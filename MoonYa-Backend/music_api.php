<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/Services/CorsPolicy.php';
applyCorsPolicy();
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $config = require_once __DIR__ . '/config.php';

    $pdo = new PDO(
        "mysql:host=" . $config['db_host'] . ";dbname=" . $config['db_name'] . ";charset=utf8mb4",
        $config['db_user'],
        $config['db_pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );

    $method = $_SERVER['REQUEST_METHOD'];
    $action = isset($_GET['action']) ? $_GET['action'] : '';

    if ($method === 'GET') {
        if ($action === 'random') {
            getRandomMusic($pdo, $config);
        } elseif ($action === 'list') {
            getMusicList($pdo);
        } elseif ($action === 'domain') {
            getMusicDomain($pdo);
        } elseif ($action === 'search') {
            searchOnlineMusic($config);
        } elseif ($action === 'detail') {
            getOnlineMusicDetail($config);
        }
    } elseif ($method === 'POST') {
        if ($action === 'check_music_request') {
            checkMusicRequest($pdo, $config);
        }
    }

    sendError(400, '无效的请求');
} catch (Exception $e) {
    sendError(500, '服务器错误: ' . $e->getMessage());
}

/* ===================== 本地音乐库函数（保留，暂不删除） ===================== */

function getRandomMusic($pdo, $config) {
    $stmt = $pdo->query("SELECT * FROM music WHERE status = 'approved' ORDER BY RAND() LIMIT 4");
    $music = $stmt->fetchAll();

    $domainStmt = $pdo->query("SELECT setting_value FROM music_settings WHERE setting_key = 'music_domain'");
    $domainResult = $domainStmt->fetch();
    $domain = $domainResult ? $domainResult['setting_value'] : $config['music']['default_domain'];

    foreach ($music as &$item) {
        if (!empty($item['file_url']) && !preg_match('/^https?:\/\//', $item['file_url'])) {
            $item['file_url'] = $domain . $item['file_url'];
        }
        if (!empty($item['logo_url']) && !preg_match('/^https?:\/\//', $item['logo_url'])) {
            $item['logo_url'] = $domain . $item['logo_url'];
        }
        $item['file_url'] = preg_replace('/^http:\/\//i', 'https://', $item['file_url']);
        $item['logo_url'] = preg_replace('/^http:\/\//i', 'https://', $item['logo_url']);
    }

    sendSuccess(['music' => $music]);
}

function getMusicList($pdo) {
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? min(100, max(1, intval($_GET['limit']))) : 10;
    $offset = ($page - 1) * $limit;

    $countStmt = $pdo->query("SELECT COUNT(*) as total FROM music WHERE status = 'approved'");
    $total = $countStmt->fetch()['total'];

    $stmt = $pdo->prepare("SELECT * FROM music WHERE status = 'approved' ORDER BY created_at DESC LIMIT ? OFFSET ?");
    $stmt->execute([$limit, $offset]);
    $music = $stmt->fetchAll();

    sendSuccess([
        'music' => $music,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => ceil($total / $limit)
        ]
    ]);
}

function getMusicDomain($pdo) {
    $stmt = $pdo->query("SELECT setting_value FROM music_settings WHERE setting_key = 'music_domain'");
    $result = $stmt->fetch();

    sendSuccess([
        'domain' => $result ? $result['setting_value'] : ''
    ]);
}

function checkMusicRequest($pdo, $config) {
    $input = json_decode(file_get_contents('php://input'), true);
    $message = isset($input['message']) ? strtolower(trim($input['message'])) : '';

    $musicKeywords = [
        '随便来点音乐吧～', '来点音乐', '推荐音乐', '播放音乐', '音乐推荐',
        '想听音乐', '给我推荐几首歌', '推荐几首歌', '有什么好听的音乐', '好听的音乐'
    ];

    $isMusicRequest = false;
    foreach ($musicKeywords as $keyword) {
        if (strpos($message, strtolower($keyword)) !== false) {
            $isMusicRequest = true;
            break;
        }
    }

    if ($isMusicRequest) {
        $stmt = $pdo->query("SELECT * FROM music WHERE status = 'approved' ORDER BY RAND() LIMIT 4");
        $music = $stmt->fetchAll();

        $domainStmt = $pdo->query("SELECT setting_value FROM music_settings WHERE setting_key = 'music_domain'");
        $domainResult = $domainStmt->fetch();
        $domain = $domainResult ? $domainResult['setting_value'] : $config['music']['default_domain'];

        foreach ($music as &$item) {
            if (!empty($item['file_url']) && !preg_match('/^https?:\/\//', $item['file_url'])) {
                $item['file_url'] = $domain . $item['file_url'];
            }
            if (!empty($item['logo_url']) && !preg_match('/^https?:\/\//', $item['logo_url'])) {
                $item['logo_url'] = $domain . $item['logo_url'];
            }
            $item['file_url'] = preg_replace('/^http:\/\//i', 'https://', $item['file_url']);
            $item['logo_url'] = preg_replace('/^http:\/\//i', 'https://', $item['logo_url']);
        }

        sendSuccess(['is_music_request' => true, 'music' => $music]);
    } else {
        sendSuccess(['is_music_request' => false]);
    }
}

/* ===================== 在线多源搜索（重写） ===================== */

/**
 * 多源故障转移搜索：按 config 中 online_sources 优先级逐源尝试，
 * 每源搜索 → 预取最多 prefetch_count 首详情 → 过滤无有效 URL 的 → 返回最多 return_count 首。
 * 某源返回 >= 1 首可播放即采用该源并停止；全部源无可用结果则报错。
 */
function searchOnlineMusic($config) {
    $name = isset($_GET['name']) ? trim($_GET['name']) : '';
    if (empty($name)) {
        sendError(400, '请提供搜索关键词');
    }

    $musicCfg = $config['music'];
    $sources = $musicCfg['online_sources'];
    $prefetchCount = intval($musicCfg['prefetch_count']);
    $returnCount = intval($musicCfg['return_count']);
    $timeout = intval($musicCfg['timeout']);
    $detailLinkFields = $musicCfg['detail_link_fields'];

    $errors = [];
    foreach ($sources as $sourceKey => $source) {
        try {
            $songs = searchSingleSource($source, $sourceKey, $name, $prefetchCount, $timeout, $detailLinkFields);
            if (!empty($songs)) {
                $result = array_slice($songs, 0, $returnCount);
                sendSuccess([
                    'music' => $result,
                    'source' => $sourceKey,
                    'source_name' => $source['name'],
                ]);
            }
            $errors[] = $source['name'] . '：无可用结果';
        } catch (Exception $e) {
            $errors[] = $source['name'] . '：' . $e->getMessage();
        }
    }

    sendError(502, '所有音乐源均无可用结果（' . implode('；', $errors) . '）');
}

/**
 * 单源搜索：搜索 → 提取列表 → 归一化 → 按 link_mode 取链 → 返回可播放列表。
 */
function searchSingleSource($source, $sourceKey, $keyword, $prefetchCount, $timeout, $detailLinkFields) {
    // 1. 搜索请求
    $searchUrl = $source['url'] . '?' . $source['search_param'] . '=' . urlencode($keyword);
    $raw = httpGet($searchUrl, $timeout);
    if ($raw === false || $raw === '') {
        throw new Exception('搜索请求失败');
    }
    $data = json_decode($raw, true);
    if ($data === null) {
        throw new Exception('响应非 JSON');
    }

    // 2. 提取歌曲列表（先按配置 list_path，再尝试常见路径兜底）
    $list = extractList($data, $source['list_path']);
    if (empty($list)) {
        foreach (['data', 'result', 'songs', 'list', ''] as $fallbackPath) {
            if ($fallbackPath === $source['list_path']) continue;
            $list = extractList($data, $fallbackPath);
            if (!empty($list)) break;
        }
    }
    if (empty($list)) {
        throw new Exception('无搜索结果');
    }

    // 3. 限制预取数量
    $list = array_slice($list, 0, $prefetchCount);

    // 4. 归一化每首歌
    $songs = [];
    foreach ($list as $idx => $item) {
        $songs[] = normalizeSong($item, $source, $sourceKey, $keyword, $idx);
    }

    // 5. 按 link_mode 处理
    if ($source['link_mode'] === 'direct') {
        // link 字段直接是可播放 URL，过滤无有效 URL 的
        $songs = array_values(array_filter($songs, function($s) {
            return !empty($s['file_url']);
        }));
    } else {
        // detail: 搜索已返回有效 URL 的直接用，空的对详情取链
        $playable = [];
        foreach ($songs as $song) {
            if (!empty($song['file_url'])) {
                $playable[] = $song;
            } else {
                $url = fetchDetailUrl($source, $keyword, $song['_searchIndex'] + 1, $timeout, $detailLinkFields);
                if ($url) {
                    $song['file_url'] = $url;
                    $playable[] = $song;
                }
            }
        }
        $songs = $playable;
    }

    return $songs;
}

/**
 * 将单首原始数据归一化为统一结构。
 */
function normalizeSong($item, $source, $sourceKey, $keyword, $idx) {
    $fields = $source['fields'];
    $name = extractField($item, $fields['name']);
    $artist = extractField($item, $fields['artist']);
    $pic = extractField($item, $fields['pic']);
    $link = extractField($item, $fields['link']);

    $fileUrl = '';
    if ($link && preg_match('/^https?:\/\//i', $link)) {
        $fileUrl = upgradeUrl($link);
    }
    $logoUrl = '';
    if ($pic && preg_match('/^https?:\/\//i', $pic)) {
        $logoUrl = upgradeUrl($pic);
    }

    return [
        'id' => $sourceKey . '_' . $idx,
        'name' => $name !== '' ? $name : '未知歌曲',
        'artist' => $artist !== '' ? $artist : '未知歌手',
        'logo_url' => $logoUrl,
        'file_url' => $fileUrl,
        '_source' => $sourceKey,
        '_keyword' => $keyword,
        '_searchIndex' => $idx,
    ];
}

/**
 * 调详情接口取真实播放 URL。尝试 detail_link_fields 中所有字段路径。
 * 同时搜索根级和 data 子级（多数 API 将数据放在 data 字段下）。
 */
function fetchDetailUrl($source, $keyword, $n, $timeout, $detailLinkFields) {
    $detailUrl = $source['url'] . '?' . $source['search_param'] . '=' . urlencode($keyword)
               . '&' . $source['detail_param'] . '=' . intval($n);
    $raw = httpGet($detailUrl, $timeout);
    if ($raw === false || $raw === '') return '';
    $data = json_decode($raw, true);
    if ($data === null) return '';

    // 搜索范围：根级 + data 子级（若有）
    $scopes = [$data];
    if (isset($data['data']) && is_array($data['data'])) {
        $scopes[] = $data['data'];
    }

    foreach ($detailLinkFields as $field) {
        foreach ($scopes as $scope) {
            $url = extractNestedField($scope, $field);
            if ($url && preg_match('/^https?:\/\//i', $url)) {
                return upgradeUrl($url);
            }
        }
    }
    return '';
}

/**
 * 在线详情接口（前端播放兜底用）：根据 source + name + n 取真实播放 URL。
 */
function getOnlineMusicDetail($config) {
    $name = isset($_GET['name']) ? trim($_GET['name']) : '';
    $n = isset($_GET['n']) ? max(1, intval($_GET['n'])) : 1;
    $sourceKey = isset($_GET['source']) ? trim($_GET['source']) : '';
    if (empty($name)) {
        sendError(400, '请提供搜索关键词');
    }

    $sources = $config['music']['online_sources'];
    if (empty($sourceKey) || !isset($sources[$sourceKey])) {
        $sourceKey = 'netease';
    }
    $source = $sources[$sourceKey];
    $timeout = intval($config['music']['timeout']);
    $detailLinkFields = $config['music']['detail_link_fields'];

    $url = fetchDetailUrl($source, $name, $n, $timeout, $detailLinkFields);
    if (empty($url)) {
        sendError(404, '无法获取播放链接');
    }
    sendSuccess(['url' => $url, 'name' => $name, 'source' => $sourceKey]);
}

/* ===================== 工具函数 ===================== */

function httpGet($url, $timeout) {
    $context = stream_context_create([
        'http' => [
            'timeout' => $timeout,
            'method' => 'GET',
            'header' => "User-Agent: Mozilla/5.0\r\n",
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ]);
    return @file_get_contents($url, false, $context);
}

/**
 * 按字段路径提取值。支持 '#' 表示嵌套数组取子字段（如 singers#name）。
 */
function extractField($item, $fieldPath) {
    if (!is_array($item)) return '';
    if (strpos($fieldPath, '#') !== false) {
        list($arrayKey, $subKey) = explode('#', $fieldPath, 2);
        if (!isset($item[$arrayKey]) || !is_array($item[$arrayKey])) return '';
        $values = [];
        foreach ($item[$arrayKey] as $sub) {
            if (is_array($sub) && isset($sub[$subKey])) {
                $values[] = $sub[$subKey];
            }
        }
        return implode('、', $values);
    }
    return isset($item[$fieldPath]) ? (string)$item[$fieldPath] : '';
}

/**
 * 按点分路径提取嵌套字段（如 raw.audioHttpsUrl）。
 */
function extractNestedField($data, $fieldPath) {
    if (!is_array($data)) return '';
    $parts = explode('.', $fieldPath);
    $current = $data;
    foreach ($parts as $part) {
        if (!is_array($current) || !isset($current[$part])) return '';
        $current = $current[$part];
    }
    return is_string($current) ? $current : '';
}

/**
 * 从响应数据中按路径提取歌曲列表。
 * - 路径为空：根为数组则直接返回，根为对象则包成 1 元素列表
 * - 路径非空：按点分路径定位，结果为列表则返回，为对象则包成 1 元素列表
 */
function extractList($data, $path) {
    if (!is_array($data)) return [];
    $current = $data;
    if (!empty($path)) {
        foreach (explode('.', $path) as $part) {
            if (!is_array($current) || !isset($current[$part])) return [];
            $current = $current[$part];
        }
    }
    if (!is_array($current)) return [];
    $keys = array_keys($current);
    if ($keys === range(0, count($current) - 1)) {
        return $current; // 顺序数组（列表）
    }
    return [$current]; // 关联数组（对象）包成 1 元素列表
}

function upgradeUrl($url) {
    return preg_replace('/^http:\/\//i', 'https://', $url);
}

function upgradeHttpUrls($jsonResponse) {
    $data = json_decode($jsonResponse, true);
    if ($data === null) {
        return $jsonResponse;
    }
    $data = upgradeUrlsInData($data);
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function upgradeUrlsInData($data) {
    if (is_array($data)) {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = upgradeUrlsInData($value);
            } elseif (is_string($value) && preg_match('/^http:\/\//i', $value)) {
                $isAudioOrImage = preg_match('/\.(mp3|wav|flac|m4a|ogg|aac|jpg|jpeg|png|gif|webp)(\?|$)/i', $value) ||
                                  preg_match('/music\.126\.net/i', $value) ||
                                  preg_match('/\/song\/|\/music\/|\/audio\/|\/media\//i', $value) ||
                                  in_array($key, ['url', 'mp3Url', 'songUrl', 'picurl', 'picUrl', 'cover', 'logo', 'src', 'source', 'playUrl', 'downloadUrl'], true);
                if ($isAudioOrImage) {
                    $data[$key] = preg_replace('/^http:\/\//i', 'https://', $value);
                }
            }
        }
    }
    return $data;
}

function sendSuccess($data) {
    echo json_encode(['success' => true, 'data' => $data]);
    exit;
}

function sendError($code, $message) {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}
