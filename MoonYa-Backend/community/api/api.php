<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('max_execution_time', 300);

header('Content-Type: application/json; charset=utf-8');
header('Vary: Origin');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_end_clean();
    http_response_code(200);
    exit;
}

ini_set('session.gc_maxlifetime', 1296000);
ini_set('session.cookie_lifetime', 1296000);
session_start();

try {
    $config = require_once __DIR__ . '/../../config.php';

    $pdo = new PDO(
        "mysql:host=" . $config['db_host'] . ";dbname=" . $config['db_name'] . ";charset=utf8mb4",
        $config['db_user'],
        $config['db_pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );

    $communityConfig = $config['community'] ?? [];
    $module = isset($_GET['module']) ? $_GET['module'] : '';
    $action = isset($_GET['action']) ? $_GET['action'] : '';
    $method = $_SERVER['REQUEST_METHOD'];
    $currentUserId = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;

    if ($method === 'POST') {
        if ($currentUserId <= 0) {
            sendError(401, '请先登录');
        }
        $expectedCsrf = isset($_SESSION['community_csrf']) ? (string)$_SESSION['community_csrf'] : '';
        $providedCsrf = isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? (string)$_SERVER['HTTP_X_CSRF_TOKEN'] : '';
        if ($expectedCsrf === '' || $providedCsrf === '' || !hash_equals($expectedCsrf, $providedCsrf)) {
            sendError(403, 'CSRF 校验失败，请刷新页面后重试');
        }
    }

    switch ($module) {
        case 'posts':
            handlePosts($pdo, $communityConfig, $action, $method, $currentUserId);
            break;
        case 'comments':
            handleComments($pdo, $communityConfig, $action, $method, $currentUserId);
            break;
        case 'likes':
            handleLikes($pdo, $action, $method, $currentUserId);
            break;
        case 'favorites':
            handleFavorites($pdo, $communityConfig, $action, $method, $currentUserId);
            break;
        case 'follows':
            handleFollows($pdo, $communityConfig, $action, $method, $currentUserId);
            break;
        case 'reports':
            handleReports($pdo, $action, $method, $currentUserId);
            break;
        case 'upload':
            handleUpload($config, $action, $method, $currentUserId);
            break;
        case 'notifications':
            handleNotifications($pdo, $action, $method, $currentUserId);
            break;
        case 'video_process':
            handleVideoProcess($config, $action, $method, $currentUserId);
            break;
        default:
            sendError(400, '无效的模块');
    }
} catch (Throwable $e) {
    sendError(500, '服务器错误: ' . $e->getMessage() . ' [' . get_class($e) . ' 在 ' . basename($e->getFile()) . ':' . $e->getLine() . ']');
}

function handlePosts($pdo, $communityConfig, $action, $method, $currentUserId) {
    $postsPerPage = $communityConfig['posts_per_page'] ?? 20;
    $maxTitleLength = $communityConfig['max_title_length'] ?? 100;
    $maxContentLength = $communityConfig['max_content_length'] ?? 100000;
    $maxImagesPerPost = $communityConfig['max_images_per_post'] ?? 3;
    $maxExternalVideos = $communityConfig['max_external_videos_per_post'] ?? 4;

    switch ($action) {
        case 'list':
            listPosts($pdo, $postsPerPage, $currentUserId);
            break;
        case 'detail':
            detailPost($pdo, $currentUserId);
            break;
        case 'create':
            if ($method !== 'POST') sendError(405, '请求方法不允许');
            if (!$currentUserId) sendError(401, '请先登录');
            createPost($pdo, $currentUserId, $maxTitleLength, $maxContentLength, $maxImagesPerPost, $maxExternalVideos);
            break;
        case 'delete':
            if ($method !== 'POST') sendError(405, '请求方法不允许');
            if (!$currentUserId) sendError(401, '请先登录');
            deletePost($pdo, $currentUserId);
            break;
        case 'search':
            searchPosts($pdo, $postsPerPage, $currentUserId);
            break;
        default:
            sendError(400, '无效的操作');
    }
}

function handleComments($pdo, $communityConfig, $action, $method, $currentUserId) {
    switch ($action) {
        case 'list':
            listComments($pdo, $communityConfig);
            break;
        case 'replies':
            listReplies($pdo, $communityConfig);
            break;
        case 'create':
            if ($method !== 'POST') sendError(405, '请求方法不允许');
            if (!$currentUserId) sendError(401, '请先登录');
            createComment($pdo);
            break;
        case 'delete':
            if ($method !== 'POST') sendError(405, '请求方法不允许');
            if (!$currentUserId) sendError(401, '请先登录');
            deleteComment($pdo);
            break;
        case 'reply_count':
            getReplyCount($pdo);
            break;
        default:
            sendError(400, '无效的操作');
    }
}

function handleLikes($pdo, $action, $method, $currentUserId) {
    if (!$currentUserId) sendError(401, '未登录');

    switch ($action) {
        case 'toggle':
            if ($method !== 'POST') sendError(405, '请求方法不允许');
            toggleLike($pdo, $currentUserId);
            break;
        case 'check':
            checkLike($pdo, $currentUserId);
            break;
        default:
            sendError(400, '无效的操作');
    }
}

function handleFavorites($pdo, $communityConfig, $action, $method, $currentUserId) {
    switch ($action) {
        case 'toggle':
            if ($method !== 'POST') sendError(405, '请求方法不允许');
            if (!$currentUserId) sendError(401, '请先登录');
            toggleFavorite($pdo, $currentUserId);
            break;
        case 'list':
            if (!$currentUserId) sendError(401, '请先登录');
            listFavorites($pdo, $communityConfig, $currentUserId);
            break;
        default:
            sendError(400, '无效的操作');
    }
}

function handleFollows($pdo, $communityConfig, $action, $method, $currentUserId) {
    switch ($action) {
        case 'toggle':
            if ($method !== 'POST') sendError(405, '请求方法不允许');
            if (!$currentUserId) sendError(401, '请先登录');
            toggleFollow($pdo, $currentUserId);
            break;
        case 'following':
            if (!$currentUserId) sendError(401, '请先登录');
            listFollowing($pdo, $communityConfig, $currentUserId);
            break;
        case 'followers':
            if (!$currentUserId) sendError(401, '请先登录');
            listFollowers($pdo, $communityConfig, $currentUserId);
            break;
        case 'stats':
            getFollowStats($pdo);
            break;
        case 'default_follows':
            getDefaultFollows($pdo, $communityConfig);
            break;
        case 'check':
            if (!$currentUserId) sendError(401, '请先登录');
            checkFollow($pdo, $currentUserId);
            break;
        default:
            sendError(400, '无效的操作');
    }
}

function handleReports($pdo, $action, $method, $currentUserId) {
    switch ($action) {
        case 'create':
            if ($method !== 'POST') sendError(405, '请求方法不允许');
            if (!$currentUserId) sendError(401, '请先登录');
            createReport($pdo, $currentUserId);
            break;
        default:
            sendError(400, '无效的操作');
    }
}

function handleUpload($config, $action, $method, $currentUserId) {
    switch ($action) {
        case 'image':
            if ($method !== 'POST') sendError(405, '请求方法不允许');
            if (!$currentUserId) sendError(401, '请先登录');
            uploadImage($config);
            break;
        case 'video':
            if ($method !== 'POST') sendError(405, '请求方法不允许');
            if (!$currentUserId) sendError(401, '请先登录');
            uploadVideo($config);
            break;
        case 'video_cover':
            if ($method !== 'POST') sendError(405, '请求方法不允许');
            if (!$currentUserId) sendError(401, '请先登录');
            uploadVideoCover($config);
            break;
        case 'check_config':
            checkUploadConfig($config);
            break;
        case 'test_upload':
            if ($method !== 'POST') sendError(405, '请求方法不允许');
            if (!$currentUserId) sendError(401, '请先登录');
            testUpload($config);
            break;
        default:
            sendError(400, '无效的操作');
    }
}

// ==================== Posts ====================

function listPosts($pdo, $postsPerPage, $currentUserId) {
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $perPage = isset($_GET['per_page']) ? max(1, intval($_GET['per_page'])) : $postsPerPage;
    $filterUserId = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
    $offset = ($page - 1) * $perPage;

    $where = [];
    $params = [];

    if ($filterUserId > 0) {
        $where[] = "p.user_id = ?";
        $params[] = $filterUserId;
    }

    $whereClause = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);

    $countSql = "SELECT COUNT(*) as total FROM community_posts p $whereClause";
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = $stmt->fetch()['total'];

    $sql = "SELECT p.id, p.user_id, p.title, p.content, p.images, p.video_url, p.video_cover, p.external_videos, p.likes_count, p.comments_count, p.favorites_count, p.created_at,
                   u.username, u.real_name, u.gender, u.avatar
            FROM community_posts p
            INNER JOIN users u ON p.user_id = u.id
            $whereClause
            ORDER BY p.created_at DESC
            LIMIT ? OFFSET ?";

    $params[] = $perPage;
    $params[] = $offset;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $posts = $stmt->fetchAll();

    $posts = enrichPostsWithUserStatus($pdo, $posts, $currentUserId);

    sendSuccess([
        'posts' => $posts,
        'total' => intval($total),
        'page' => $page,
        'per_page' => $perPage
    ]);
}

function detailPost($pdo, $currentUserId) {
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    if ($id <= 0) sendError(400, '帖子ID不能为空');

    $sql = "SELECT p.id, p.user_id, p.title, p.content, p.images, p.video_url, p.video_cover, p.external_videos, p.likes_count, p.comments_count, p.favorites_count, p.created_at, p.updated_at,
                   u.username, u.real_name, u.gender, u.avatar, u.bio
            FROM community_posts p
            INNER JOIN users u ON p.user_id = u.id
            WHERE p.id = ?";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    $post = $stmt->fetch();

    if (!$post) sendError(404, '帖子不存在');

    $isLiked = false;
    $isFavorited = false;
    $isFollowing = false;

    if ($currentUserId > 0) {
        $stmt = $pdo->prepare("SELECT id FROM community_likes WHERE user_id = ? AND target_id = ? AND target_type = 'post'");
        $stmt->execute([$currentUserId, $id]);
        $isLiked = $stmt->fetch() ? true : false;

        $stmt = $pdo->prepare("SELECT id FROM community_favorites WHERE user_id = ? AND post_id = ?");
        $stmt->execute([$currentUserId, $id]);
        $isFavorited = $stmt->fetch() ? true : false;

        if ($post['user_id'] != $currentUserId) {
            $stmt = $pdo->prepare("SELECT id FROM community_follows WHERE follower_id = ? AND following_id = ?");
            $stmt->execute([$currentUserId, $post['user_id']]);
            $isFollowing = $stmt->fetch() ? true : false;
        }
    }

    sendSuccess([
        'post' => $post,
        'is_liked' => $isLiked,
        'is_favorited' => $isFavorited,
        'is_following' => $isFollowing
    ]);
}

function createPost($pdo, $currentUserId, $maxTitleLength, $maxContentLength, $maxImagesPerPost, $maxExternalVideos) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) sendError(400, '请求数据格式错误');

    $title = isset($input['title']) ? trim($input['title']) : '';
    $content = isset($input['content']) ? trim($input['content']) : '';
    $images = isset($input['images']) ? $input['images'] : [];
    $videoUrl = isset($input['video_url']) ? trim($input['video_url']) : null;
    $videoCover = isset($input['video_cover']) ? trim($input['video_cover']) : null;
    $externalVideos = isset($input['external_videos']) ? $input['external_videos'] : [];

    if (empty($content)) sendError(400, '帖子内容不能为空');
    if (mb_strlen($title) > $maxTitleLength) sendError(400, '标题长度不能超过' . $maxTitleLength . '个字符');
    if (mb_strlen($content) > $maxContentLength) sendError(400, '内容长度不能超过' . $maxContentLength . '个字符');

    if (!is_array($images)) $images = [];
    if (count($images) > $maxImagesPerPost) sendError(400, '图片数量不能超过' . $maxImagesPerPost . '张');

    if (!is_array($externalVideos)) $externalVideos = [];
    $externalVideos = array_filter($externalVideos, function($v) { return is_string($v) && filter_var($v, FILTER_VALIDATE_URL); });
    if (count($externalVideos) > $maxExternalVideos) sendError(400, '外部视频数量不能超过' . $maxExternalVideos . '个');

    $imagesJson = empty($images) ? null : json_encode($images, JSON_UNESCAPED_UNICODE);
    $externalVideosJson = empty($externalVideos) ? null : json_encode(array_values($externalVideos), JSON_UNESCAPED_UNICODE);

    $stmt = $pdo->prepare("INSERT INTO community_posts (user_id, title, content, images, video_url, video_cover, external_videos, likes_count, comments_count, favorites_count, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, 0, NOW(), NOW())");
    $stmt->execute([$currentUserId, $title, $content, $imagesJson, $videoUrl, $videoCover, $externalVideosJson]);
    $postId = $pdo->lastInsertId();

    sendSuccess(['post_id' => intval($postId)]);
}

function deletePost($pdo, $currentUserId) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) sendError(400, '请求数据格式错误');

    $id = isset($input['id']) ? intval($input['id']) : 0;
    if ($id <= 0) sendError(400, '帖子ID不能为空');

    $stmt = $pdo->prepare("SELECT user_id FROM community_posts WHERE id = ?");
    $stmt->execute([$id]);
    $post = $stmt->fetch();

    if (!$post) sendError(404, '帖子不存在');
    if ($post['user_id'] != $currentUserId) sendError(403, '无权删除此帖子');

    $stmt = $pdo->prepare("DELETE FROM community_posts WHERE id = ?");
    $stmt->execute([$id]);

    sendSuccess(['message' => '删除成功']);
}

function searchPosts($pdo, $postsPerPage, $currentUserId) {
    $keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';
    if (empty($keyword)) sendError(400, '搜索关键词不能为空');

    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $perPage = isset($_GET['per_page']) ? max(1, intval($_GET['per_page'])) : $postsPerPage;
    $offset = ($page - 1) * $perPage;

    $searchPattern = '%' . $keyword . '%';

    $countSql = "SELECT COUNT(*) as total FROM community_posts p WHERE p.title LIKE ? OR p.content LIKE ?";
    $stmt = $pdo->prepare($countSql);
    $stmt->execute([$searchPattern, $searchPattern]);
    $total = $stmt->fetch()['total'];

    $sql = "SELECT p.id, p.user_id, p.title, p.content, p.images, p.video_url, p.video_cover, p.external_videos, p.likes_count, p.comments_count, p.favorites_count, p.created_at,
                   u.username, u.real_name, u.gender, u.avatar
            FROM community_posts p
            INNER JOIN users u ON p.user_id = u.id
            WHERE p.title LIKE ? OR p.content LIKE ?
            ORDER BY p.created_at DESC
            LIMIT ? OFFSET ?";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$searchPattern, $searchPattern, $perPage, $offset]);
    $posts = $stmt->fetchAll();

    $posts = enrichPostsWithUserStatus($pdo, $posts, $currentUserId);

    sendSuccess([
        'posts' => $posts,
        'total' => intval($total),
        'page' => $page,
        'per_page' => $perPage
    ]);
}

function enrichPostsWithUserStatus($pdo, $posts, $currentUserId) {
    if (empty($posts) || $currentUserId <= 0) {
        foreach ($posts as &$post) {
            $post['is_liked'] = false;
            $post['is_favorited'] = false;
        }
        return $posts;
    }

    $postIds = array_column($posts, 'id');
    $placeholders = implode(',', array_fill(0, count($postIds), '?'));

    $params = array_merge([$currentUserId], $postIds);
    $stmt = $pdo->prepare("SELECT target_id FROM community_likes WHERE user_id = ? AND target_id IN ($placeholders) AND target_type = 'post'");
    $stmt->execute($params);
    $likedIds = array_column($stmt->fetchAll(), 'target_id');

    $params = array_merge([$currentUserId], $postIds);
    $stmt = $pdo->prepare("SELECT post_id FROM community_favorites WHERE user_id = ? AND post_id IN ($placeholders)");
    $stmt->execute($params);
    $favoritedIds = array_column($stmt->fetchAll(), 'post_id');

    foreach ($posts as &$post) {
        $post['is_liked'] = in_array($post['id'], $likedIds);
        $post['is_favorited'] = in_array($post['id'], $favoritedIds);
    }

    return $posts;
}

// ==================== Comments ====================

function listComments($pdo, $communityConfig) {
    $postId = $_GET['post_id'] ?? null;
    if (!$postId) sendError(400, '缺少post_id参数');

    $page = max(1, intval($_GET['page'] ?? 1));
    $perPage = max(1, intval($communityConfig['comments_per_page'] ?? 20));
    $offset = ($page - 1) * $perPage;

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM community_comments WHERE post_id = :post_id AND parent_id IS NULL');
    $countStmt->execute([':post_id' => $postId]);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare(
        'SELECT c.id, c.post_id, c.user_id, c.content, c.likes_count, c.created_at,
                u.username, u.real_name, u.gender, u.avatar
         FROM community_comments c
         LEFT JOIN users u ON c.user_id = u.id
         WHERE c.post_id = :post_id AND c.parent_id IS NULL
         ORDER BY c.created_at ASC
         LIMIT :limit OFFSET :offset'
    );
    $stmt->bindValue(':post_id', $postId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $userId = $_SESSION['user_id'] ?? null;
    $commentIds = array_column($comments, 'id');

    $replyCounts = [];
    if (!empty($commentIds)) {
        $placeholders = implode(',', array_fill(0, count($commentIds), '?'));
        $rcStmt = $pdo->prepare("SELECT parent_id, COUNT(*) as cnt FROM community_comments WHERE parent_id IN ($placeholders) GROUP BY parent_id");
        $rcStmt->execute($commentIds);
        while ($row = $rcStmt->fetch(PDO::FETCH_ASSOC)) {
            $replyCounts[$row['parent_id']] = (int)$row['cnt'];
        }
    }

    $likedIds = [];
    if ($userId && !empty($commentIds)) {
        $placeholders = implode(',', array_fill(0, count($commentIds), '?'));
        $likeStmt = $pdo->prepare("SELECT target_id FROM community_likes WHERE user_id = ? AND target_type = 'comment' AND target_id IN ($placeholders)");
        $likeStmt->execute(array_merge([$userId], $commentIds));
        while ($row = $likeStmt->fetch(PDO::FETCH_ASSOC)) {
            $likedIds[] = $row['target_id'];
        }
    }

    foreach ($comments as &$comment) {
        $comment['id'] = (int)$comment['id'];
        $comment['post_id'] = (int)$comment['post_id'];
        $comment['user_id'] = (int)$comment['user_id'];
        $comment['likes_count'] = (int)$comment['likes_count'];
        $comment['reply_count'] = $replyCounts[$comment['id']] ?? 0;
        $comment['is_liked'] = in_array($comment['id'], $likedIds);
        $comment['is_owner'] = ($userId && $comment['user_id'] == $userId);
    }
    unset($comment);

    sendSuccess([
        'comments' => $comments,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage
    ]);
}

function listReplies($pdo, $communityConfig) {
    $commentId = $_GET['comment_id'] ?? null;
    if (!$commentId) sendError(400, '缺少comment_id参数');

    $page = max(1, intval($_GET['page'] ?? 1));
    $perPage = max(1, intval($communityConfig['comments_per_page'] ?? 20));
    $offset = ($page - 1) * $perPage;

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM community_comments WHERE parent_id = :parent_id');
    $countStmt->execute([':parent_id' => $commentId]);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare(
        'SELECT c.id, c.post_id, c.user_id, c.content, c.likes_count, c.created_at,
                u.username, u.real_name, u.gender, u.avatar
         FROM community_comments c
         LEFT JOIN users u ON c.user_id = u.id
         WHERE c.parent_id = :parent_id
         ORDER BY c.created_at ASC
         LIMIT :limit OFFSET :offset'
    );
    $stmt->bindValue(':parent_id', $commentId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $userId = $_SESSION['user_id'] ?? null;
    $commentIds = array_column($comments, 'id');

    $likedIds = [];
    if ($userId && !empty($commentIds)) {
        $placeholders = implode(',', array_fill(0, count($commentIds), '?'));
        $likeStmt = $pdo->prepare("SELECT target_id FROM community_likes WHERE user_id = ? AND target_type = 'comment' AND target_id IN ($placeholders)");
        $likeStmt->execute(array_merge([$userId], $commentIds));
        while ($row = $likeStmt->fetch(PDO::FETCH_ASSOC)) {
            $likedIds[] = $row['target_id'];
        }
    }

    foreach ($comments as &$comment) {
        $comment['id'] = (int)$comment['id'];
        $comment['post_id'] = (int)$comment['post_id'];
        $comment['user_id'] = (int)$comment['user_id'];
        $comment['likes_count'] = (int)$comment['likes_count'];
        $comment['reply_count'] = 0;
        $comment['is_liked'] = in_array($comment['id'], $likedIds);
        $comment['is_owner'] = ($userId && $comment['user_id'] == $userId);
    }
    unset($comment);

    sendSuccess([
        'comments' => $comments,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage
    ]);
}

function createComment($pdo) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) sendError(400, '无效的请求数据');

    $postId = $input['post_id'] ?? null;
    $content = trim($input['content'] ?? '');
    $parentId = $input['parent_id'] ?? null;

    if (!$postId) sendError(400, '缺少post_id参数');
    if ($content === '') sendError(400, '评论内容不能为空');

    $postStmt = $pdo->prepare('SELECT id FROM community_posts WHERE id = :id');
    $postStmt->execute([':id' => $postId]);
    if (!$postStmt->fetch()) sendError(404, '帖子不存在');

    if ($parentId !== null) {
        $parentStmt = $pdo->prepare('SELECT id, post_id FROM community_comments WHERE id = :id');
        $parentStmt->execute([':id' => $parentId]);
        $parent = $parentStmt->fetch(PDO::FETCH_ASSOC);
        if (!$parent) sendError(404, '父评论不存在');
        if ((int)$parent['post_id'] !== (int)$postId) sendError(400, '父评论不属于该帖子');
    }

    $userId = $_SESSION['user_id'];

    $stmt = $pdo->prepare(
        'INSERT INTO community_comments (post_id, user_id, parent_id, content, likes_count, created_at)
         VALUES (:post_id, :user_id, :parent_id, :content, 0, NOW())'
    );
    $stmt->execute([
        ':post_id' => $postId,
        ':user_id' => $userId,
        ':parent_id' => $parentId ?: null,
        ':content' => $content
    ]);

    $commentId = (int)$pdo->lastInsertId();

    $updateStmt = $pdo->prepare('UPDATE community_posts SET comments_count = comments_count + 1 WHERE id = :id');
    $updateStmt->execute([':id' => $postId]);

    $stmt = $pdo->prepare("SELECT user_id FROM community_posts WHERE id = ?");
    $stmt->execute([$postId]);
    $postOwner = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($postOwner && $postOwner['user_id'] != $userId) {
        createNotification($pdo, $postOwner['user_id'], $userId, 'comment', $postId, 'post');
    }

    sendSuccess(['comment_id' => $commentId]);
}

function deleteComment($pdo) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) sendError(400, '无效的请求数据');

    $id = $input['id'] ?? null;
    if (!$id) sendError(400, '缺少id参数');

    $stmt = $pdo->prepare('SELECT id, user_id, post_id FROM community_comments WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $comment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$comment) sendError(404, '评论不存在');
    if ((int)$comment['user_id'] !== (int)$_SESSION['user_id']) sendError(403, '无权删除该评论');

    $postId = $comment['post_id'];

    $deleteStmt = $pdo->prepare('DELETE FROM community_comments WHERE id = :id');
    $deleteStmt->execute([':id' => $id]);

    $updateStmt = $pdo->prepare('UPDATE community_posts SET comments_count = GREATEST(comments_count - 1, 0) WHERE id = :id');
    $updateStmt->execute([':id' => $postId]);

    sendSuccess(['message' => '删除成功']);
}

function getReplyCount($pdo) {
    $commentId = $_GET['comment_id'] ?? null;
    if (!$commentId) sendError(400, '缺少comment_id参数');

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM community_comments WHERE parent_id = :parent_id');
    $stmt->execute([':parent_id' => $commentId]);
    $count = (int)$stmt->fetchColumn();

    sendSuccess(['count' => $count]);
}

// ==================== Likes ====================

function toggleLike($pdo, $userId) {
    $input = json_decode(file_get_contents('php://input'), true);

    $targetId = $input['target_id'] ?? null;
    $targetType = $input['target_type'] ?? null;

    if (empty($targetId) || !is_numeric($targetId)) sendError(400, 'target_id 必填');
    $targetId = (int)$targetId;

    if (!in_array($targetType, ['post', 'comment'], true)) sendError(400, 'target_type 必须为 post 或 comment');

    $targetTable = $targetType === 'post' ? 'community_posts' : 'community_comments';

    $stmt = $pdo->prepare("SELECT id FROM {$targetTable} WHERE id = ?");
    $stmt->execute([$targetId]);
    if (!$stmt->fetch()) sendError(404, '目标不存在');

    $stmt = $pdo->prepare("SELECT id FROM community_likes WHERE user_id = ? AND target_id = ? AND target_type = ?");
    $stmt->execute([$userId, $targetId, $targetType]);
    $existing = $stmt->fetch();

    $pdo->beginTransaction();

    try {
        if ($existing) {
            $stmt = $pdo->prepare("DELETE FROM community_likes WHERE user_id = ? AND target_id = ? AND target_type = ?");
            $stmt->execute([$userId, $targetId, $targetType]);

            $stmt = $pdo->prepare("UPDATE {$targetTable} SET likes_count = GREATEST(likes_count - 1, 0) WHERE id = ?");
            $stmt->execute([$targetId]);

            if ($targetType === 'post') {
                $stmt = $pdo->prepare("SELECT user_id FROM community_posts WHERE id = ?");
                $stmt->execute([$targetId]);
                $owner = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($owner) {
                    $stmt = $pdo->prepare("UPDATE users SET likes_count = GREATEST(likes_count - 1, 0) WHERE id = ?");
                    $stmt->execute([$owner['user_id']]);
                }
            } else {
                $stmt = $pdo->prepare("SELECT post_id FROM community_comments WHERE id = ?");
                $stmt->execute([$targetId]);
                $comment = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($comment) {
                    $stmt = $pdo->prepare("SELECT user_id FROM community_posts WHERE id = ?");
                    $stmt->execute([$comment['post_id']]);
                    $owner = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($owner) {
                        $stmt = $pdo->prepare("UPDATE users SET likes_count = GREATEST(likes_count - 1, 0) WHERE id = ?");
                        $stmt->execute([$owner['user_id']]);
                    }
                }
            }

            $isLiked = false;
        } else {
            $stmt = $pdo->prepare("INSERT INTO community_likes (user_id, target_id, target_type, created_at) VALUES (?, ?, ?, NOW())");
            $stmt->execute([$userId, $targetId, $targetType]);

            $stmt = $pdo->prepare("UPDATE {$targetTable} SET likes_count = likes_count + 1 WHERE id = ?");
            $stmt->execute([$targetId]);

            if ($targetType === 'post') {
                $stmt = $pdo->prepare("SELECT user_id FROM community_posts WHERE id = ?");
                $stmt->execute([$targetId]);
                $owner = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($owner) {
                    $stmt = $pdo->prepare("UPDATE users SET likes_count = likes_count + 1 WHERE id = ?");
                    $stmt->execute([$owner['user_id']]);
                }
            } else {
                $stmt = $pdo->prepare("SELECT post_id FROM community_comments WHERE id = ?");
                $stmt->execute([$targetId]);
                $comment = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($comment) {
                    $stmt = $pdo->prepare("SELECT user_id FROM community_posts WHERE id = ?");
                    $stmt->execute([$comment['post_id']]);
                    $owner = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($owner) {
                        $stmt = $pdo->prepare("UPDATE users SET likes_count = likes_count + 1 WHERE id = ?");
                        $stmt->execute([$owner['user_id']]);
                    }
                }
            }

            $isLiked = true;

            if ($targetType === 'post') {
                $stmt = $pdo->prepare("SELECT user_id FROM community_posts WHERE id = ?");
                $stmt->execute([$targetId]);
                $owner = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($owner && $owner['user_id'] != $userId) {
                    createNotification($pdo, $owner['user_id'], $userId, 'like', $targetId, 'post');
                }
            }
        }

        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        throw $e;
    }

    $stmt = $pdo->prepare("SELECT likes_count FROM {$targetTable} WHERE id = ?");
    $stmt->execute([$targetId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $likesCount = $row ? (int)$row['likes_count'] : 0;

    sendSuccess([
        'is_liked' => $isLiked,
        'likes_count' => $likesCount
    ]);
}

function checkLike($pdo, $userId) {
    $targetId = $_GET['target_id'] ?? null;
    $targetType = $_GET['target_type'] ?? null;

    if (empty($targetId) || !is_numeric($targetId)) sendError(400, 'target_id 必填');
    $targetId = (int)$targetId;

    if (!in_array($targetType, ['post', 'comment'], true)) sendError(400, 'target_type 必须为 post 或 comment');

    $stmt = $pdo->prepare("SELECT id FROM community_likes WHERE user_id = ? AND target_id = ? AND target_type = ?");
    $stmt->execute([$userId, $targetId, $targetType]);
    $isLiked = (bool)$stmt->fetch();

    sendSuccess(['is_liked' => $isLiked]);
}

// ==================== Favorites ====================

function toggleFavorite($pdo, $userId) {
    $input = json_decode(file_get_contents('php://input'), true);
    $postId = intval($input['post_id'] ?? 0);

    if ($postId <= 0) sendError(400, '无效的帖子ID');

    $stmt = $pdo->prepare("SELECT id FROM community_posts WHERE id = ?");
    $stmt->execute([$postId]);
    if (!$stmt->fetch()) sendError(404, '帖子不存在');

    $stmt = $pdo->prepare("SELECT id FROM community_favorites WHERE user_id = ? AND post_id = ?");
    $stmt->execute([$userId, $postId]);
    $existing = $stmt->fetch();

    $pdo->beginTransaction();
    try {
        if ($existing) {
            $stmt = $pdo->prepare("DELETE FROM community_favorites WHERE user_id = ? AND post_id = ?");
            $stmt->execute([$userId, $postId]);

            $stmt = $pdo->prepare("UPDATE community_posts SET favorites_count = GREATEST(0, favorites_count - 1) WHERE id = ?");
            $stmt->execute([$postId]);

            $isFavorited = false;
        } else {
            $stmt = $pdo->prepare("INSERT INTO community_favorites (user_id, post_id) VALUES (?, ?)");
            $stmt->execute([$userId, $postId]);

            $stmt = $pdo->prepare("UPDATE community_posts SET favorites_count = favorites_count + 1 WHERE id = ?");
            $stmt->execute([$postId]);

            $isFavorited = true;

            $stmt = $pdo->prepare("SELECT user_id FROM community_posts WHERE id = ?");
            $stmt->execute([$postId]);
            $owner = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($owner && $owner['user_id'] != $userId) {
                createNotification($pdo, $owner['user_id'], $userId, 'favorite', $postId, 'post');
            }
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        sendError(500, '操作失败: ' . $e->getMessage());
    }

    $stmt = $pdo->prepare("SELECT favorites_count FROM community_posts WHERE id = ?");
    $stmt->execute([$postId]);
    $post = $stmt->fetch();

    sendSuccess([
        'is_favorited' => $isFavorited,
        'favorites_count' => intval($post['favorites_count'])
    ]);
}

function listFavorites($pdo, $communityConfig, $userId) {
    $page = max(1, intval($_GET['page'] ?? 1));
    $perPage = min(100, max(1, intval($communityConfig['posts_per_page'] ?? 20)));
    if (isset($_GET['per_page'])) {
        $perPage = min(100, max(1, intval($_GET['per_page'])));
    }
    $offset = ($page - 1) * $perPage;

    $countStmt = $pdo->prepare("SELECT COUNT(*) as total FROM community_favorites WHERE user_id = ?");
    $countStmt->execute([$userId]);
    $total = intval($countStmt->fetch()['total']);

    $stmt = $pdo->prepare("
        SELECT p.*, u.username, u.real_name, u.gender, u.avatar
        FROM community_favorites f
        INNER JOIN community_posts p ON f.post_id = p.id
        INNER JOIN users u ON p.user_id = u.id
        WHERE f.user_id = ?
        ORDER BY f.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$userId, $perPage, $offset]);
    $posts = $stmt->fetchAll();

    sendSuccess([
        'posts' => $posts,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage
    ]);
}

// ==================== Follows ====================

function toggleFollow($pdo, $userId) {
    $input = json_decode(file_get_contents('php://input'), true);
    $followingId = intval($input['following_id'] ?? 0);

    if ($followingId <= 0) sendError(400, '无效的用户ID');
    if ($userId === $followingId) sendError(400, '不能关注自己');

    $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND status != 'deleted'");
    $stmt->execute([$followingId]);
    if (!$stmt->fetch()) sendError(404, '用户不存在');

    $stmt = $pdo->prepare("SELECT id FROM community_follows WHERE follower_id = ? AND following_id = ?");
    $stmt->execute([$userId, $followingId]);
    $existing = $stmt->fetch();

    if ($existing) {
        $stmt = $pdo->prepare("DELETE FROM community_follows WHERE follower_id = ? AND following_id = ?");
        $stmt->execute([$userId, $followingId]);
        $isFollowing = false;
    } else {
        $stmt = $pdo->prepare("INSERT INTO community_follows (follower_id, following_id) VALUES (?, ?)");
        $stmt->execute([$userId, $followingId]);
        $isFollowing = true;
        createNotification($pdo, $followingId, $userId, 'follow');
    }

    sendSuccess(['is_following' => $isFollowing]);
}

function listFollowing($pdo, $communityConfig, $currentUserId) {
    $targetUserId = isset($_GET['user_id']) ? intval($_GET['user_id']) : $currentUserId;
    $page = max(1, intval($_GET['page'] ?? 1));
    $perPage = min(100, max(1, intval($communityConfig['posts_per_page'] ?? 20)));
    if (isset($_GET['per_page'])) {
        $perPage = min(100, max(1, intval($_GET['per_page'])));
    }
    $offset = ($page - 1) * $perPage;

    $countStmt = $pdo->prepare("SELECT COUNT(*) as total FROM community_follows WHERE follower_id = ?");
    $countStmt->execute([$targetUserId]);
    $total = intval($countStmt->fetch()['total']);

    $stmt = $pdo->prepare("
        SELECT u.id, u.username, u.real_name, u.gender, u.avatar, u.bio,
               EXISTS(SELECT 1 FROM community_follows WHERE follower_id = ? AND following_id = u.id) as is_following
        FROM community_follows f
        INNER JOIN users u ON f.following_id = u.id
        WHERE f.follower_id = ? AND u.status != 'deleted'
        ORDER BY f.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$currentUserId, $targetUserId, $perPage, $offset]);
    $users = $stmt->fetchAll();

    foreach ($users as &$user) {
        $user['is_following'] = boolval($user['is_following']);
    }
    unset($user);

    sendSuccess([
        'users' => $users,
        'total' => $total
    ]);
}

function listFollowers($pdo, $communityConfig, $currentUserId) {
    $targetUserId = intval($_GET['user_id'] ?? 0);
    if ($targetUserId <= 0) sendError(400, '请指定用户ID');

    $page = max(1, intval($_GET['page'] ?? 1));
    $perPage = min(100, max(1, intval($communityConfig['posts_per_page'] ?? 20)));
    if (isset($_GET['per_page'])) {
        $perPage = min(100, max(1, intval($_GET['per_page'])));
    }
    $offset = ($page - 1) * $perPage;

    $countStmt = $pdo->prepare("SELECT COUNT(*) as total FROM community_follows WHERE following_id = ?");
    $countStmt->execute([$targetUserId]);
    $total = intval($countStmt->fetch()['total']);

    $stmt = $pdo->prepare("
        SELECT u.id, u.username, u.real_name, u.gender, u.avatar, u.bio,
               EXISTS(SELECT 1 FROM community_follows WHERE follower_id = ? AND following_id = u.id) as is_following
        FROM community_follows f
        INNER JOIN users u ON f.follower_id = u.id
        WHERE f.following_id = ? AND u.status != 'deleted'
        ORDER BY f.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$currentUserId, $targetUserId, $perPage, $offset]);
    $users = $stmt->fetchAll();

    foreach ($users as &$user) {
        $user['is_following'] = boolval($user['is_following']);
    }
    unset($user);

    sendSuccess([
        'users' => $users,
        'total' => $total
    ]);
}

function getFollowStats($pdo) {
    $userId = intval($_GET['user_id'] ?? 0);
    if ($userId <= 0) sendError(400, '请指定用户ID');

    $stmt = $pdo->prepare("SELECT COUNT(*) as following_count FROM community_follows WHERE follower_id = ?");
    $stmt->execute([$userId]);
    $followingCount = intval($stmt->fetch()['following_count']);

    $stmt = $pdo->prepare("SELECT COUNT(*) as followers_count FROM community_follows WHERE following_id = ?");
    $stmt->execute([$userId]);
    $followersCount = intval($stmt->fetch()['followers_count']);

    sendSuccess([
        'following_count' => $followingCount,
        'followers_count' => $followersCount
    ]);
}

function getDefaultFollows($pdo, $communityConfig) {
    $defaultAccounts = $communityConfig['default_follow_accounts'] ?? [];

    if (empty($defaultAccounts)) {
        sendSuccess(['users' => []]);
    }

    $placeholders = implode(',', array_fill(0, count($defaultAccounts), '?'));
    $stmt = $pdo->prepare("SELECT id, username, real_name, avatar, bio FROM users WHERE username IN ($placeholders) AND status != 'deleted'");
    $stmt->execute($defaultAccounts);
    $users = $stmt->fetchAll();

    sendSuccess(['users' => $users]);
}

function checkFollow($pdo, $userId) {
    $followingId = intval($_GET['following_id'] ?? 0);
    if ($followingId <= 0) sendError(400, '请指定用户ID');

    $stmt = $pdo->prepare("SELECT id FROM community_follows WHERE follower_id = ? AND following_id = ?");
    $stmt->execute([$userId, $followingId]);

    sendSuccess(['is_following' => boolval($stmt->fetch())]);
}

// ==================== Reports ====================

function createReport($pdo, $userId) {
    $input = json_decode(file_get_contents('php://input'), true);
    $targetId = intval($input['target_id'] ?? 0);
    $targetType = trim($input['target_type'] ?? '');
    $reason = trim($input['reason'] ?? '');

    if ($targetId <= 0) sendError(400, '无效的目标ID');
    if (!in_array($targetType, ['post', 'comment', 'user'])) sendError(400, '无效的目标类型');
    if (empty($reason)) sendError(400, '请填写举报原因');

    $stmt = $pdo->prepare("INSERT INTO community_reports (reporter_id, target_id, target_type, reason, status) VALUES (?, ?, ?, ?, 'pending')");
    $stmt->execute([$userId, $targetId, $targetType, $reason]);

    sendSuccess(['message' => '举报已提交']);
}

// ==================== Upload ====================

function checkUploadConfig($config) {
    $videoConfig = $config['community']['video'] ?? [];
    $storagePath = $videoConfig['storage_path'] ?? 'uploads/community/videos/';
    $coverStoragePath = $videoConfig['cover_storage_path'] ?? 'uploads/community/covers/';
    $uploadDir = __DIR__ . '/../../' . $storagePath;
    $coverUploadDir = __DIR__ . '/../../' . $coverStoragePath;

    $info = [
        'php_version' => PHP_VERSION,
        'upload_max_filesize' => ini_get('upload_max_filesize'),
        'post_max_size' => ini_get('post_max_size'),
        'memory_limit' => ini_get('memory_limit'),
        'max_execution_time' => ini_get('max_execution_time'),
        'max_input_time' => ini_get('max_input_time'),
        'file_uploads' => ini_get('file_uploads'),
        'upload_tmp_dir' => ini_get('upload_tmp_dir'),
        'display_errors' => ini_get('display_errors'),
        'fileinfo_loaded' => extension_loaded('fileinfo'),
        'user_ini_filename' => ini_get('user_ini.filename'),
        'user_ini_cache_ttl' => ini_get('user_ini.cache_ttl'),
        'sapi_name' => PHP_SAPI,
        'video_storage_path' => $storagePath,
        'video_upload_dir_exists' => is_dir($uploadDir),
        'video_upload_dir_writable' => is_dir($uploadDir) ? is_writable($uploadDir) : false,
        'cover_upload_dir_exists' => is_dir($coverUploadDir),
        'cover_upload_dir_writable' => is_dir($coverUploadDir) ? is_writable($coverUploadDir) : false,
        'config_max_video_size' => ($videoConfig['max_size'] ?? 100 * 1024 * 1024) / 1024 / 1024 . 'MB',
        'current_user' => function_exists('get_current_user') ? get_current_user() : 'N/A',
        'php_ini_loaded' => php_ini_loaded_file(),
        'php_ini_scanned' => php_ini_scanned_files(),
        'script_dir' => __DIR__,
        'user_ini_file' => __DIR__ . '/.user.ini',
        'user_ini_exists' => file_exists(__DIR__ . '/.user.ini'),
        'content_length' => isset($_SERVER['CONTENT_LENGTH']) ? $_SERVER['CONTENT_LENGTH'] : 'N/A',
    ];

    $problems = [];
    $uploadMax = returnBytes(ini_get('upload_max_filesize'));
    $postMax = returnBytes(ini_get('post_max_size'));
    $memLimit = returnBytes(ini_get('memory_limit'));
    $configMaxSize = $videoConfig['max_size'] ?? 100 * 1024 * 1024;

    if ($uploadMax < $configMaxSize) {
        $problems[] = 'upload_max_filesize(' . ini_get('upload_max_filesize') . ') 小于配置的最大视频大小(' . ($configMaxSize / 1024 / 1024) . 'MB)，需要在php.ini或.user.ini中增大';
    }
    if ($postMax < $uploadMax) {
        $problems[] = 'post_max_size(' . ini_get('post_max_size') . ') 小于 upload_max_filesize(' . ini_get('upload_max_filesize') . ')，post_max_size必须大于upload_max_filesize';
    }
    if ($postMax < $configMaxSize) {
        $problems[] = 'post_max_size(' . ini_get('post_max_size') . ') 小于配置的最大视频大小(' . ($configMaxSize / 1024 / 1024) . 'MB)';
    }
    if ($memLimit < $postMax && $memLimit > 0) {
        $problems[] = 'memory_limit(' . ini_get('memory_limit') . ') 小于 post_max_size(' . ini_get('post_max_size') . ')';
    }
    if (!is_dir($uploadDir)) {
        $problems[] = '视频上传目录不存在: ' . $storagePath;
    } elseif (!is_writable($uploadDir)) {
        $problems[] = '视频上传目录不可写: ' . $storagePath;
    }

    $info['problems'] = $problems;

    sendSuccess($info);
}

function testUpload($config) {
    $debug = [];
    $debug['step'] = 'start';
    $debug['files_empty'] = empty($_FILES);
    $debug['post_empty'] = empty($_POST);
    $debug['content_length'] = isset($_SERVER['CONTENT_LENGTH']) ? $_SERVER['CONTENT_LENGTH'] : 'N/A';
    $debug['request_method'] = $_SERVER['REQUEST_METHOD'];
    $debug['raw_files'] = [];

    if (!empty($_FILES)) {
        foreach ($_FILES as $key => $f) {
            $debug['raw_files'][$key] = [
                'name' => $f['name'],
                'size' => $f['size'],
                'type' => $f['type'],
                'error' => $f['error'],
                'tmp_name_exists' => !empty($f['tmp_name']),
                'is_uploaded_file' => !empty($f['tmp_name']) && @is_uploaded_file($f['tmp_name']),
            ];
        }
    }

    if (empty($_FILES) && empty($_POST)) {
        $postMax = ini_get('post_max_size');
        $cl = isset($_SERVER['CONTENT_LENGTH']) ? intval($_SERVER['CONTENT_LENGTH']) : 0;
        if ($cl > 0) {
            $debug['likely_cause'] = 'post_max_size exceeded';
            $debug['post_max_size'] = $postMax;
            $debug['content_length_bytes'] = $cl;
            sendError(400, '请求数据超出post_max_size限制: ' . json_encode($debug));
        }
    }

    if (!isset($_FILES['video'])) {
        $debug['likely_cause'] = 'no video field in $_FILES';
        sendError(400, '未找到video字段: ' . json_encode($debug));
    }

    $file = $_FILES['video'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $debug['likely_cause'] = 'upload error code: ' . $file['error'];
        sendError(400, '上传错误码' . $file['error'] . ': ' . json_encode($debug));
    }

    $videoConfig = $config['community']['video'] ?? [];
    $storagePath = $videoConfig['storage_path'] ?? 'uploads/community/videos/';
    $uploadDir = __DIR__ . '/../../' . $storagePath;

    $debug['upload_dir'] = $uploadDir;
    $debug['upload_dir_exists'] = is_dir($uploadDir);
    $debug['upload_dir_writable'] = is_dir($uploadDir) && is_writable($uploadDir);

    if (!is_dir($uploadDir)) {
        $mkResult = @mkdir($uploadDir, 0755, true);
        $debug['mkdir_result'] = $mkResult;
        if (!$mkResult) {
            $debug['mkdir_error'] = error_get_last();
            sendError(500, '创建目录失败: ' . json_encode($debug));
        }
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $filename = 'test_' . time() . '.' . $ext;
    $filepath = $uploadDir . $filename;

    $debug['target_filepath'] = $filepath;
    $debug['is_uploaded_file'] = @is_uploaded_file($file['tmp_name']);

    $moveResult = @move_uploaded_file($file['tmp_name'], $filepath);
    $debug['move_result'] = $moveResult;

    if (!$moveResult) {
        $debug['move_error'] = error_get_last();
        @unlink($filepath);
        sendError(500, '移动文件失败: ' . json_encode($debug));
    }

    sendSuccess(['message' => '测试上传成功', 'url' => '/' . $storagePath . $filename, 'debug' => $debug]);
}

function returnBytes($val) {
    if (empty($val) || $val === '-1') return -1;
    $val = trim($val);
    $last = strtolower($val[strlen($val) - 1]);
    $num = (int)$val;
    switch ($last) {
        case 'g': $num *= 1024;
        case 'm': $num *= 1024;
        case 'k': $num *= 1024;
    }
    return $num;
}

function uploadImage($config) {
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE => '文件超出服务器限制',
            UPLOAD_ERR_FORM_SIZE => '文件超出表单限制',
            UPLOAD_ERR_PARTIAL => '文件上传不完整',
            UPLOAD_ERR_NO_FILE => '未选择文件',
        ];
        $errorCode = $_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE;
        $message = $errorMessages[$errorCode] ?? '上传失败';
        sendError(400, $message);
    }

    $file = $_FILES['image'];
    $allowedTypes = $config['community']['allowed_image_types'] ?? ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $maxSize = $config['community']['max_image_size'] ?? 3 * 1024 * 1024;

    if ($file['size'] > $maxSize) sendError(400, '文件大小超出限制');

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedTypes)) sendError(400, '不支持的文件类型');

    $uploadDir = __DIR__ . '/../../uploads/community/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $filename = uniqid() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $filepath = $uploadDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $filepath)) sendError(500, '文件保存失败');

    sendSuccess(['url' => '/uploads/community/' . $filename]);
}

function uploadVideo($config) {
    $videoConfig = $config['community']['video'] ?? [];
    $maxSize = $videoConfig['max_size'] ?? 100 * 1024 * 1024;
    $allowedTypes = $videoConfig['allowed_types'] ?? ['mp4', 'webm', 'mov', 'avi', 'mkv'];
    $allowedMimeTypes = $videoConfig['allowed_mime_types'] ?? ['video/mp4', 'video/webm', 'video/quicktime', 'video/x-msvideo', 'video/x-matroska'];
    $storagePath = $videoConfig['storage_path'] ?? 'uploads/community/videos/';

    $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? intval($_SERVER['CONTENT_LENGTH']) : 0;
    $postMax = returnBytes(ini_get('post_max_size'));
    $uploadMax = returnBytes(ini_get('upload_max_filesize'));

    if (empty($_FILES) && empty($_POST) && $contentLength > 0) {
        sendError(400, '请求数据(' . round($contentLength / 1024 / 1024, 1) . 'MB)超出服务器限制(post_max_size=' . ini_get('post_max_size') . ')，请在服务器 PHP 配置中增大 post_max_size 和 upload_max_filesize');
    }

    if (!isset($_FILES['video']) || $_FILES['video']['error'] !== UPLOAD_ERR_OK) {
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE => '视频文件超出 PHP 上传限制(upload_max_filesize=' . ini_get('upload_max_filesize') . ')，请在服务器 PHP 配置中增大 upload_max_filesize',
            UPLOAD_ERR_FORM_SIZE => '文件超出表单限制',
            UPLOAD_ERR_PARTIAL => '文件上传不完整，请重试',
            UPLOAD_ERR_NO_FILE => '未选择文件',
            UPLOAD_ERR_NO_TMP_DIR => '服务器缺少临时目录',
            UPLOAD_ERR_CANT_WRITE => '服务器写入失败',
            UPLOAD_ERR_EXTENSION => '文件上传被服务器扩展阻止',
        ];
        $errorCode = isset($_FILES['video']) ? $_FILES['video']['error'] : UPLOAD_ERR_NO_FILE;
        $message = $errorMessages[$errorCode] ?? '上传失败(错误码:' . $errorCode . ')';
        sendError(400, $message);
    }

    $file = $_FILES['video'];

    if ($file['size'] > $maxSize) sendError(400, '视频文件大小不能超过' . ($maxSize / 1024 / 1024) . 'MB');

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedTypes)) sendError(400, '不支持的视频格式，允许的格式：' . implode(', ', $allowedTypes));

    if (function_exists('finfo_open')) {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mimeType = @finfo_file($finfo, $file['tmp_name']);
            @finfo_close($finfo);
            if ($mimeType && !in_array($mimeType, $allowedMimeTypes) && !in_array($ext, $allowedTypes)) {
                sendError(400, '不支持的视频MIME类型: ' . $mimeType);
            }
        }
    }

    $uploadDir = __DIR__ . '/../../' . $storagePath;
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            sendError(500, '无法创建上传目录: ' . $storagePath . '，请检查目录权限');
        }
    }

    if (!is_writable($uploadDir)) {
        sendError(500, '上传目录不可写: ' . $storagePath . '，请检查目录权限(当前用户: ' . get_current_user() . ')');
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        sendError(400, '非法的文件上传');
    }

    $filename = uniqid() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $filepath = $uploadDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        $lastError = error_get_last();
        $errorMsg = '视频保存失败';
        if ($lastError) {
            $errorMsg .= ': ' . $lastError['message'];
        }
        sendError(500, $errorMsg);
    }

    $originalUrl = '/' . $storagePath . $filename;
    $result = ['url' => $originalUrl];

    $vpConfig = $config['community']['video_processing'] ?? [];
    if (!empty($vpConfig) && ($vpConfig['enabled'] ?? false)) {
        $vpConfig['root_path'] = __DIR__ . '/../../';
        require_once __DIR__ . '/../VideoProcessor.php';
        $processor = new VideoProcessor($vpConfig);

        if ($processor->isAvailable()) {
            $processResult = $processor->process($filepath);
            if ($processResult['success']) {
                $result['url'] = $processResult['m3u8_url'];
                $result['original_url'] = $originalUrl;
                $result['m3u8_url'] = $processResult['m3u8_url'];
                $result['original_resolution'] = $processResult['original_resolution'];
                $result['was_scaled'] = $processResult['was_scaled'];
                if (isset($processResult['output_resolution'])) {
                    $result['output_resolution'] = $processResult['output_resolution'];
                }
            } else {
                $result['process_error'] = $processResult['error'];
                if (isset($processResult['detail'])) {
                    $result['process_detail'] = $processResult['detail'];
                }
                $result['debug_input_path'] = $filepath;
                $result['debug_input_exists'] = file_exists($filepath);
            }
        } else {
            $result['process_error'] = 'ffmpeg 不可用: ' . ($processor->getLastError() ?? '未知原因');
            $result['ffmpeg_path'] = $processor->getFfmpegPath();
            $result['ffprobe_path'] = $processor->getFfprobePath();
            $result['ffmpeg_exists'] = file_exists($processor->getFfmpegPath());
            $result['ffprobe_exists'] = file_exists($processor->getFfprobePath());
            $result['exec_available'] = function_exists('exec');
            $result['shell_exec_available'] = function_exists('shell_exec');
            $result['platform'] = PHP_OS_FAMILY;
        }
    }

    sendSuccess($result);
}

function uploadVideoCover($config) {
    if (empty($_FILES) && empty($_POST)) {
        sendError(400, '请求数据过大，超出服务器限制');
    }

    if (!isset($_FILES['cover']) || $_FILES['cover']['error'] !== UPLOAD_ERR_OK) {
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE => '封面图片超出服务器上传限制',
            UPLOAD_ERR_PARTIAL => '封面上传不完整',
            UPLOAD_ERR_NO_FILE => '未选择封面文件',
        ];
        $errorCode = isset($_FILES['cover']) ? $_FILES['cover']['error'] : UPLOAD_ERR_NO_FILE;
        $message = $errorMessages[$errorCode] ?? '封面上传失败';
        sendError(400, $message);
    }

    $file = $_FILES['cover'];
    $videoConfig = $config['community']['video'] ?? [];
    $coverStoragePath = $videoConfig['cover_storage_path'] ?? 'uploads/community/covers/';

    $ext = 'jpg';
    $uploadDir = __DIR__ . '/../../' . $coverStoragePath;
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            sendError(500, '无法创建封面上传目录: ' . $coverStoragePath);
        }
    }

    if (!is_writable($uploadDir)) {
        sendError(500, '封面上传目录不可写: ' . $coverStoragePath);
    }

    $filename = uniqid() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $filepath = $uploadDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        sendError(500, '封面保存失败');
    }

    sendSuccess(['url' => '/' . $coverStoragePath . $filename]);
}

// ==================== Common ====================

function handleNotifications($pdo, $action, $method, $currentUserId) {
    if (!$currentUserId) sendError(401, '请先登录');

    switch ($action) {
        case 'list':
            listNotifications($pdo, $currentUserId);
            break;
        case 'unread_count':
            getUnreadCount($pdo, $currentUserId);
            break;
        case 'mark_read':
            if ($method !== 'POST') sendError(405, '请求方法不允许');
            markNotificationsRead($pdo, $currentUserId);
            break;
        case 'mark_all_read':
            if ($method !== 'POST') sendError(405, '请求方法不允许');
            markAllNotificationsRead($pdo, $currentUserId);
            break;
        default:
            sendError(400, '无效的操作');
    }
}

function listNotifications($pdo, $currentUserId) {
    $type = isset($_GET['type']) ? $_GET['type'] : 'all';
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $perPage = 20;
    $offset = ($page - 1) * $perPage;

    $where = ["n.user_id = ?"];
    $params = [$currentUserId];

    if ($type === 'system') {
        $where[] = "n.type = 'system'";
    } elseif ($type === 'user') {
        $where[] = "n.type IN ('like', 'comment', 'follow', 'favorite')";
    }

    $whereClause = 'WHERE ' . implode(' AND ', $where);

    $countSql = "SELECT COUNT(*) as total FROM community_notifications n $whereClause";
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = intval($stmt->fetch()['total']);

    $sql = "SELECT n.id, n.actor_id, n.type, n.target_id, n.target_type, n.content, n.image, n.is_read, n.created_at,
                   u.username as actor_username, u.real_name as actor_real_name, u.gender as actor_gender, u.avatar as actor_avatar
            FROM community_notifications n
            LEFT JOIN users u ON n.actor_id = u.id
            $whereClause
            ORDER BY n.created_at DESC
            LIMIT ? OFFSET ?";

    $params[] = $perPage;
    $params[] = $offset;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($notifications as &$n) {
        $n['id'] = intval($n['id']);
        $n['actor_id'] = $n['actor_id'] ? intval($n['actor_id']) : null;
        $n['is_read'] = intval($n['is_read']);
        if ($n['actor_avatar']) {
            $n['actor_avatar'] = '/' . ltrim($n['actor_avatar'], '/');
        }
    }
    unset($n);

    sendSuccess([
        'notifications' => $notifications,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage
    ]);
}

function getUnreadCount($pdo, $currentUserId) {
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM community_notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$currentUserId]);
    $allUnread = intval($stmt->fetch()['count']);

    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM community_notifications WHERE user_id = ? AND is_read = 0 AND type = 'system'");
    $stmt->execute([$currentUserId]);
    $systemUnread = intval($stmt->fetch()['count']);

    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM community_notifications WHERE user_id = ? AND is_read = 0 AND type IN ('like', 'comment', 'follow', 'favorite')");
    $stmt->execute([$currentUserId]);
    $userUnread = intval($stmt->fetch()['count']);

    sendSuccess([
        'all_unread' => $allUnread,
        'system_unread' => $systemUnread,
        'user_unread' => $userUnread
    ]);
}

function markNotificationsRead($pdo, $currentUserId) {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = intval($input['id'] ?? 0);
    if ($id <= 0) sendError(400, '无效的通知ID');

    $stmt = $pdo->prepare("UPDATE community_notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $currentUserId]);

    sendSuccess(['message' => '已标记为已读']);
}

function markAllNotificationsRead($pdo, $currentUserId) {
    $type = isset($_GET['type']) ? $_GET['type'] : 'all';

    if ($type === 'system') {
        $stmt = $pdo->prepare("UPDATE community_notifications SET is_read = 1 WHERE user_id = ? AND type = 'system' AND is_read = 0");
    } elseif ($type === 'user') {
        $stmt = $pdo->prepare("UPDATE community_notifications SET is_read = 1 WHERE user_id = ? AND type IN ('like', 'comment', 'follow', 'favorite') AND is_read = 0");
    } else {
        $stmt = $pdo->prepare("UPDATE community_notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
    }
    $stmt->execute([$currentUserId]);

    sendSuccess(['message' => '已全部标记为已读']);
}

function createNotification($pdo, $userId, $actorId, $type, $targetId = null, $targetType = null, $content = null, $image = null) {
    if ($userId == $actorId) return;
    $stmt = $pdo->prepare("INSERT INTO community_notifications (user_id, actor_id, type, target_id, target_type, content, image) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$userId, $actorId, $type, $targetId, $targetType, $content, $image]);
}

function handleVideoProcess($config, $action, $method, $currentUserId)
{
    $vpConfig = $config['community']['video_processing'] ?? [];
    if (empty($vpConfig) || !($vpConfig['enabled'] ?? false)) {
        sendError(403, '视频处理功能未启用');
    }

    $vpConfig['root_path'] = __DIR__ . '/../../';
    require_once __DIR__ . '/../VideoProcessor.php';

    switch ($action) {
        case 'process':
            if ($method !== 'POST') sendError(405, '请求方法不允许');
            if (!$currentUserId) sendError(401, '请先登录');
            processExistingVideo($vpConfig);
            break;
        case 'download':
            if ($method !== 'POST') sendError(405, '请求方法不允许');
            downloadM3u8($vpConfig);
            break;
        case 'download_image':
            if ($method !== 'POST') sendError(405, '请求方法不允许');
            downloadImageWithWatermark($vpConfig);
            break;
        case 'info':
            if ($method !== 'GET') sendError(405, '请求方法不允许');
            getVideoInfo($vpConfig);
            break;
        case 'check_ffmpeg':
            checkFfmpeg($vpConfig);
            break;
        default:
            sendError(400, '无效的操作，可用: process, info, check_ffmpeg');
    }
}

function downloadImageWithWatermark($vpConfig)
{
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) sendError(400, '请求数据格式错误');

    $imageUrl = trim($input['image_url'] ?? '');
    $postId = intval($input['post_id'] ?? 0);
    if (empty($imageUrl)) sendError(400, '缺少 image_url 参数');

    $rootPath = $vpConfig['root_path'];
    $filePath = $rootPath . ltrim($imageUrl, '/');

    if (!file_exists($filePath)) sendError(404, '文件不存在');

    $realPath = realpath($filePath);
    $realRoot = realpath($rootPath . 'uploads/');
    if ($realRoot === false || strpos($realPath, $realRoot) !== 0) {
        sendError(403, '无权访问');
    }

    $authorName = '';
    if ($postId > 0) {
        global $pdo;
        $stmt = $pdo->prepare("SELECT u.real_name, u.username, u.gender FROM community_posts p JOIN users u ON p.user_id = u.id WHERE p.id = ?");
        $stmt->execute([$postId]);
        $row = $stmt->fetch();
        if ($row) {
            $authorName = $row['real_name'] ?: $row['username'];
        }
    }

    $imageInfo = @getimagesize($filePath);
    if (!$imageInfo) sendError(500, '无法读取图片');

    $mime = $imageInfo['mime'];
    $isPng = ($mime === 'image/png');
    switch ($mime) {
        case 'image/jpeg': $img = @imagecreatefromjpeg($filePath); break;
        case 'image/png':  $img = @imagecreatefrompng($filePath); break;
        case 'image/gif':  $img = @imagecreatefromgif($filePath); break;
        case 'image/webp': $img = @imagecreatefromwebp($filePath); break;
        default: sendError(500, '不支持的图片格式');
    }
    if (!$img) sendError(500, '图片加载失败');

    $fontPath = $config['community']['share_card_font_path'] ?? '';
    if (!is_string($fontPath) || $fontPath === '' || !is_file($fontPath)) {
        sendError(500, 'Missing or invalid configuration: community.share_card_font_path');
    }

    $imgW = imagesx($img);
    $imgH = imagesy($img);
    $fontSize = max(16, intval($imgW / 30));
    $authorFontSize = max(12, intval($imgW / 40));

    $white = imagecolorallocate($img, 255, 255, 255);

    $pad = intval($imgW / 60);
    if ($pad < 8) $pad = 8;

    $moonYaBox = @imagettfbbox($fontSize, 0, $fontPath, 'MoonYa');
    $moonYaH = $moonYaBox ? ($moonYaBox[1] - $moonYaBox[7]) : $fontSize;

    @imagettftext($img, $fontSize, 0, $pad, $pad + $moonYaH, $white, $fontPath, 'MoonYa');

    if (!empty($authorName)) {
        $authorBox = @imagettfbbox($authorFontSize, 0, $fontPath, $authorName);
        $authorW = $authorBox ? ($authorBox[2] - $authorBox[0]) : $authorFontSize * mb_strlen($authorName);

        @imagettftext($img, $authorFontSize, 0, $imgW - $authorW - $pad, $imgH - $pad, $white, $fontPath, $authorName);
    }

    $ext = $isPng ? 'png' : 'jpg';
    $tmpFile = tempnam(sys_get_temp_dir(), 'img_wm_') . '.' . $ext;

    if ($isPng) {
        imagepng($img, $tmpFile, 9);
    } else {
        imagejpeg($img, $tmpFile, 90);
    }
    imagedestroy($img);

    $fileSize = filesize($tmpFile);
    $filename = 'image_' . time() . '.' . $ext;
    $contentType = $isPng ? 'image/png' : 'image/jpeg';

    if (ob_get_level()) ob_end_clean();
    header('Content-Type: ' . $contentType);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . $fileSize);
    header('Cache-Control: no-cache');
    readfile($tmpFile);
    @unlink($tmpFile);
    exit;
}

function downloadM3u8($vpConfig)
{
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) sendError(400, '请求数据格式错误');

    $videoUrl = trim($input['video_url'] ?? '');
    $postId = intval($input['post_id'] ?? 0);
    if (empty($videoUrl)) sendError(400, '缺少 video_url 参数');

    $rootPath = $vpConfig['root_path'];
    $filePath = $rootPath . ltrim($videoUrl, '/');

    if (!file_exists($filePath)) sendError(404, '文件不存在');

    $realPath = realpath($filePath);
    $realRoot = realpath($rootPath . 'uploads/');
    if ($realRoot === false || strpos($realPath, $realRoot) !== 0) {
        sendError(403, '无权访问');
    }

    $authorName = '';
    if ($postId > 0) {
        global $pdo;
        $stmt = $pdo->prepare("SELECT u.real_name, u.username, u.gender FROM community_posts p JOIN users u ON p.user_id = u.id WHERE p.id = ?");
        $stmt->execute([$postId]);
        $row = $stmt->fetch();
        if ($row) {
            $authorName = $row['real_name'] ?: $row['username'];
        }
    }

    $m3u8Content = file_get_contents($filePath);
    if ($m3u8Content === false) sendError(500, '无法读取 m3u8 文件');

    $m3u8Dir = dirname($filePath);
    $tsFiles = [];
    $lines = explode("\n", $m3u8Content);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || $line[0] === '#') continue;
        $tsPath = $m3u8Dir . '/' . $line;
        if (file_exists($tsPath)) {
            $tsFiles[] = $tsPath;
        }
    }

    if (empty($tsFiles)) sendError(500, '未找到视频分片');

    $tmpDir = sys_get_temp_dir();
    $uid = uniqid();
    $concatFile = $tmpDir . '/hls_concat_' . $uid . '.txt';
    $mergedFile = $tmpDir . '/hls_merged_' . $uid . '.mp4';
    $outputFile = $tmpDir . '/hls_output_' . $uid . '.mp4';

    $cf = fopen($concatFile, 'w');
    foreach ($tsFiles as $tsFile) {
        fwrite($cf, "file '" . str_replace("'", "'\\''", $tsFile) . "'\n");
    }
    fclose($cf);

    $processor = new VideoProcessor($vpConfig);
    $ffmpeg = $processor->getFfmpegPath();
    $ffprobe = $processor->getFfprobePath();
    $isWindows = DIRECTORY_SEPARATOR === '\\';

    $concatFileEsc = $isWindows
        ? '"' . str_replace('"', '""', $concatFile) . '"'
        : escapeshellarg($concatFile);
    $mergedFileEsc = $isWindows
        ? '"' . str_replace('"', '""', $mergedFile) . '"'
        : escapeshellarg($mergedFile);
    $outputFileEsc = $isWindows
        ? '"' . str_replace('"', '""', $outputFile) . '"'
        : escapeshellarg($outputFile);

    $mergeCmd = escapeshellarg($ffmpeg)
         . ' -y'
         . ' -f concat -safe 0'
         . ' -i ' . $concatFileEsc
         . ' -c copy'
         . ' ' . $mergedFileEsc
         . ' 2>&1';

    exec($mergeCmd, $mergeOut, $mergeRet);
    @unlink($concatFile);

    if ($mergeRet !== 0 || !file_exists($mergedFile)) {
        $fallbackFile = $tmpDir . '/hls_fallback_' . $uid . '.mp4';
        $out = fopen($fallbackFile, 'wb');
        foreach ($tsFiles as $tsFile) {
            $data = file_get_contents($tsFile);
            if ($data !== false) fwrite($out, $data);
        }
        fclose($out);
        downloadFile($fallbackFile, 'video_' . time() . '.mp4');
    }

    $nullDev = $isWindows ? 'NUL' : '/dev/null';
    $probeCmd = escapeshellarg($ffprobe)
         . ' -v error -show_entries format=duration -of csv=p=0'
         . ' ' . escapeshellarg($mergedFile)
         . ' 2>' . $nullDev;

    $duration = 0;
    exec($probeCmd, $probeOut, $probeRet);
    if ($probeRet === 0 && !empty($probeOut)) {
        $duration = floatval(trim($probeOut[0]));
    }

    $halfTime = $duration > 0 ? $duration / 2 : 0;

    $fontFile = $rootPath . 'font/qaddin_medium.otf';
    if (!file_exists($fontFile)) {
        $fontFile = $isWindows ? 'C\\:/Windows/Fonts/msyh.ttc' : '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
    }
    $fontFile = str_replace('\\', '/', $fontFile);
    $fontFile = str_replace(':', '\\:', $fontFile);

    $drawtext = "drawtext=fontfile='{$fontFile}':text='MoonYa':x=10:y=10:fontsize=28:fontcolor=white:enable='lt(t,{$halfTime})'";
    $drawtext .= ",drawtext=fontfile='{$fontFile}':text='MoonYa':x=10:y=H-th-10:fontsize=28:fontcolor=white:enable='gte(t,{$halfTime})'";
    if (!empty($authorName)) {
        $escapedAuthor = str_replace("'", "'", $authorName);
        $escapedAuthor = str_replace(":", "\\:", $escapedAuthor);
        $escapedAuthor = str_replace("\\", "\\\\", $escapedAuthor);
        $drawtext .= ",drawtext=fontfile='{$fontFile}':text='{$escapedAuthor}':x=W-tw-10:y=H-th-10:fontsize=22:fontcolor=white:enable='lt(t,{$halfTime})'";
        $drawtext .= ",drawtext=fontfile='{$fontFile}':text='{$escapedAuthor}':x=W-tw-10:y=10:fontsize=22:fontcolor=white:enable='gte(t,{$halfTime})'";
    }

    $watermarkCmd = escapeshellarg($ffmpeg)
         . ' -y'
         . ' -i ' . escapeshellarg($mergedFile)
         . ' -vf ' . escapeshellarg($drawtext)
         . ' -c:v libx264 -preset fast -crf 23'
         . ' -c:a aac -b:a 128k'
         . ' -movflags +faststart'
         . ' ' . $outputFileEsc
         . ' 2>&1';

    exec($watermarkCmd, $wmOut, $wmRet);
    @unlink($mergedFile);

    if ($wmRet !== 0 || !file_exists($outputFile)) {
        $fallbackFile = $tmpDir . '/hls_fallback_' . $uid . '.mp4';
        $out = fopen($fallbackFile, 'wb');
        foreach ($tsFiles as $tsFile) {
            $data = file_get_contents($tsFile);
            if ($data !== false) fwrite($out, $data);
        }
        fclose($out);
        @unlink($outputFile);
        downloadFile($fallbackFile, 'video_' . time() . '.mp4');
    }

    downloadFile($outputFile, 'video_' . time() . '.mp4');
}

function downloadFile($filePath, $filename)
{
    $fileSize = filesize($filePath);
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: video/mp4');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . $fileSize);
    header('Content-Transfer-Encoding: binary');
    header('Cache-Control: no-cache');
    readfile($filePath);
    @unlink($filePath);
    exit;
}

function processExistingVideo($vpConfig)
{
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) sendError(400, '请求数据格式错误');

    $videoUrl = trim($input['video_url'] ?? '');
    if (empty($videoUrl)) sendError(400, '缺少 video_url 参数');

    $rootPath = $vpConfig['root_path'];
    $filePath = $rootPath . ltrim($videoUrl, '/');

    if (!file_exists($filePath)) sendError(404, '视频文件不存在');

    $realPath = realpath($filePath);
    $realRoot = realpath($rootPath . 'uploads/');
    if ($realRoot === false || strpos($realPath, $realRoot) !== 0) {
        sendError(403, '无权访问该文件');
    }

    $processor = new VideoProcessor($vpConfig);
    if (!$processor->isAvailable()) {
        sendError(500, 'ffmpeg 不可用，请联系管理员');
    }

    $result = $processor->process($filePath);

    if ($result['success']) {
        sendSuccess($result);
    } else {
        sendError(500, $result['error']);
    }
}

function getVideoInfo($vpConfig)
{
    $videoUrl = trim($_GET['video_url'] ?? '');
    if (empty($videoUrl)) sendError(400, '缺少 video_url 参数');

    $rootPath = $vpConfig['root_path'];
    $filePath = $rootPath . ltrim($videoUrl, '/');

    if (!file_exists($filePath)) sendError(404, '视频文件不存在');

    $realPath = realpath($filePath);
    $realRoot = realpath($rootPath . 'uploads/');
    if ($realRoot === false || strpos($realPath, $realRoot) !== 0) {
        sendError(403, '无权访问该文件');
    }

    $processor = new VideoProcessor($vpConfig);
    $result = $processor->getVideoInfo($filePath);

    if ($result['success']) {
        sendSuccess($result);
    } else {
        sendError(500, $result['error']);
    }
}

function checkFfmpeg($vpConfig)
{
    $processor = new VideoProcessor($vpConfig);
    $ffmpegPath = $processor->getFfmpegPath();
    $ffprobePath = $processor->getFfprobePath();

    $diag = [
        'ffmpeg_available' => $processor->isAvailable(),
        'ffmpeg_path' => $ffmpegPath,
        'ffprobe_path' => $ffprobePath,
        'ffmpeg_exists' => file_exists($ffmpegPath),
        'ffprobe_exists' => file_exists($ffprobePath),
        'ffmpeg_executable' => is_executable($ffmpegPath),
        'ffprobe_executable' => is_executable($ffprobePath),
        'exec_available' => function_exists('exec'),
        'shell_exec_available' => function_exists('shell_exec'),
        'proc_open_available' => function_exists('proc_open'),
        'platform' => PHP_OS_FAMILY,
        'php_uname' => php_uname('s') . ' ' . php_uname('r') . ' ' . php_uname('m'),
        'last_error' => $processor->getLastError(),
        'disabled_functions' => array_intersect(
            ['exec', 'shell_exec', 'proc_open', 'popen', 'system', 'passthru'],
            explode(',', ini_get('disable_functions'))
        ),
    ];

    if ($diag['ffmpeg_exists'] && $diag['exec_available']) {
        $version = '';
        @exec(escapeshellarg($ffmpegPath) . ' -version 2>&1', $versionOut, $versionRet);
        if ($versionRet === 0 && !empty($versionOut)) {
            $diag['ffmpeg_version'] = $versionOut[0];
        }
    }

    sendSuccess($diag);
}

function sendSuccess($data) {
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function sendError($code, $message) {
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}
