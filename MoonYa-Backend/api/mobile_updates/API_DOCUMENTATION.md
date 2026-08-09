# 移动端更新管理 API 接口文档

## 基础信息

- **基础URL**: `http://ai.yueyaxuan.cn/api/mobile_updates/mobile_updates.php`
- **数据格式**: JSON
- **字符编码**: UTF-8

## 认证说明

| 接口 | 是否需要认证 | 说明 |
|------|-------------|------|
| `GET ?action=latest` | ❌ 免认证 | 供客户端App匿名调用检查更新 |
| `GET ?action=list` | ✅ 需要认证 | 管理后台使用 |
| `GET ?action=get` | ✅ 需要认证 | 管理后台使用 |
| `POST ?action=create` | ✅ 需要认证 | 管理后台使用 |
| `POST ?action=update` | ✅ 需要认证 | 管理后台使用 |
| `POST ?action=delete` | ✅ 需要认证 | 管理后台使用 |
| `POST ?action=toggle` | ✅ 需要认证 | 管理后台使用 |

需要认证的接口请在请求头中携带管理员令牌：

```
Authorization: Bearer {token}
```

---

## 数据库表结构设计

### mobile_updates 表

| 字段名 | 类型 | 默认值 | 说明 |
|--------|------|--------|------|
| id | INT AUTO_INCREMENT | - | 主键，自增ID |
| version | VARCHAR(50) | - | 版本号，如 1.2.3（唯一） |
| title | VARCHAR(200) | - | 更新标题 |
| content | TEXT | - | 更新内容（支持HTML） |
| download_url | VARCHAR(500) | '' | 下载链接 |
| is_force | TINYINT(1) | 0 | 是否强制更新：1=是，0=否 |
| is_active | TINYINT(1) | 1 | 是否启用：1=启用，0=禁用 |
| created_at | TIMESTAMP | CURRENT_TIMESTAMP | 创建时间 |
| updated_at | TIMESTAMP | 自动更新 | 更新时间 |

**建表SQL**:
```sql
CREATE TABLE IF NOT EXISTS mobile_updates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    version VARCHAR(50) NOT NULL COMMENT '版本号',
    title VARCHAR(200) NOT NULL COMMENT '更新标题',
    content TEXT NOT NULL COMMENT '更新内容',
    download_url VARCHAR(500) NOT NULL DEFAULT '' COMMENT '下载链接',
    is_force TINYINT(1) DEFAULT 0 COMMENT '是否强制更新',
    is_active TINYINT(1) DEFAULT 1 COMMENT '是否启用',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    UNIQUE KEY unique_version (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='移动端更新表';
```

---

## 接口列表

### 1. 获取最新移动端更新 🔓 免认证

获取当前启用的最新一条移动端更新记录，供客户端App检查更新。此接口**无需认证**，可直接调用。

**接口**: `GET ?action=latest`

**认证**: 不需要

**请求参数**: 无

**完整请求地址**:
```
http://ai.yueyaxuan.cn/api/mobile_updates/mobile_updates.php?action=latest
```

**响应示例**（有更新时）:
```json
{
    "success": true,
    "data": {
        "update": {
            "id": 1,
            "version": "1.2.0",
            "title": "重大功能更新",
            "content": "1. 新增社区功能<br>2. 优化性能<br>3. 修复已知问题",
            "download_url": "https://example.com/app-1.2.0.apk",
            "is_force": 0,
            "is_active": 1,
            "created_at": "2026-05-01 10:00:00",
            "updated_at": "2026-05-01 10:00:00"
        }
    }
}
```

**响应示例**（无启用更新时）:
```json
{
    "success": true,
    "data": {
        "update": null
    }
}
```

---

### 2. 获取移动端更新列表

获取所有移动端更新记录，按创建时间降序排列。

**接口**: `GET ?action=list`

**认证**: 需要管理员令牌

**请求参数**: 无

**完整请求地址**:
```
http://ai.yueyaxuan.cn/api/mobile_updates/mobile_updates.php?action=list
```

**响应示例**:
```json
{
    "success": true,
    "data": {
        "updates": [
            {
                "id": 1,
                "version": "1.2.0",
                "title": "重大功能更新",
                "content": "1. 新增社区功能<br>2. 优化性能<br>3. 修复已知问题",
                "download_url": "https://example.com/app-1.2.0.apk",
                "is_force": 0,
                "is_active": 1,
                "created_at": "2026-05-01 10:00:00",
                "updated_at": "2026-05-01 10:00:00"
            }
        ]
    }
}
```

---

### 3. 获取单个移动端更新详情

根据ID获取单条移动端更新数据。

**接口**: `GET ?action=get&id={id}`

**认证**: 需要管理员令牌

**请求参数**:

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| id | int | 是 | 更新记录ID |

**完整请求地址**:
```
http://ai.yueyaxuan.cn/api/mobile_updates/mobile_updates.php?action=get&id=1
```

**响应示例**:
```json
{
    "success": true,
    "data": {
        "update": {
            "id": 1,
            "version": "1.2.0",
            "title": "重大功能更新",
            "content": "1. 新增社区功能<br>2. 优化性能",
            "download_url": "https://example.com/app-1.2.0.apk",
            "is_force": 0,
            "is_active": 1,
            "created_at": "2026-05-01 10:00:00",
            "updated_at": "2026-05-01 10:00:00"
        }
    }
}
```

---

### 4. 创建移动端更新

新增一条移动端更新记录。

**接口**: `POST ?action=create`

**认证**: 需要管理员令牌

**请求头**:
```
Content-Type: application/json
Authorization: Bearer {token}
```

**完整请求地址**:
```
http://ai.yueyaxuan.cn/api/mobile_updates/mobile_updates.php?action=create
```

**请求参数**:

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| version | string | 是 | 版本号，格式如 1.2.3 |
| title | string | 是 | 更新标题 |
| content | string | 是 | 更新内容（支持HTML） |
| download_url | string | 否 | 下载链接 |
| is_force | int | 否 | 是否强制更新，默认0 |
| is_active | int | 否 | 是否启用，默认1 |

**请求示例**:
```json
{
    "version": "1.2.0",
    "title": "重大功能更新",
    "content": "1. 新增社区功能<br>2. 优化性能<br>3. 修复已知问题",
    "download_url": "https://example.com/app-1.2.0.apk",
    "is_force": 0,
    "is_active": 1
}
```

**响应示例**:
```json
{
    "success": true,
    "data": {
        "message": "移动端更新创建成功",
        "id": 1
    }
}
```

**错误响应**:
```json
{
    "success": false,
    "error": "版本号已存在"
}
```

---

### 5. 修改移动端更新

根据ID更新移动端更新记录。

**接口**: `POST ?action=update`

**认证**: 需要管理员令牌

**请求头**:
```
Content-Type: application/json
Authorization: Bearer {token}
```

**完整请求地址**:
```
http://ai.yueyaxuan.cn/api/mobile_updates/mobile_updates.php?action=update
```

**请求参数**:

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| id | int | 是 | 更新记录ID |
| version | string | 是 | 版本号 |
| title | string | 是 | 更新标题 |
| content | string | 是 | 更新内容 |
| download_url | string | 否 | 下载链接 |
| is_force | int | 否 | 是否强制更新 |
| is_active | int | 否 | 是否启用 |

**请求示例**:
```json
{
    "id": 1,
    "version": "1.2.0",
    "title": "重大功能更新（已修正）",
    "content": "1. 新增社区功能<br>2. 优化性能",
    "download_url": "https://example.com/app-1.2.0-fix.apk",
    "is_force": 1,
    "is_active": 1
}
```

**响应示例**:
```json
{
    "success": true,
    "data": {
        "message": "移动端更新修改成功"
    }
}
```

---

### 6. 删除移动端更新

根据ID删除一条移动端更新记录。

**接口**: `POST ?action=delete`

**认证**: 需要管理员令牌

**请求头**:
```
Content-Type: application/json
Authorization: Bearer {token}
```

**完整请求地址**:
```
http://ai.yueyaxuan.cn/api/mobile_updates/mobile_updates.php?action=delete
```

**请求参数**:

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| id | int | 是 | 更新记录ID |

**请求示例**:
```json
{
    "id": 1
}
```

**响应示例**:
```json
{
    "success": true,
    "data": {
        "message": "移动端更新删除成功"
    }
}
```

---

### 7. 切换启用/禁用状态

快速切换移动端更新的启用/禁用状态。

**接口**: `POST ?action=toggle`

**认证**: 需要管理员令牌

**请求头**:
```
Content-Type: application/json
Authorization: Bearer {token}
```

**完整请求地址**:
```
http://ai.yueyaxuan.cn/api/mobile_updates/mobile_updates.php?action=toggle
```

**请求参数**:

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| id | int | 是 | 更新记录ID |
| is_active | int | 是 | 目标状态：1=启用，0=禁用 |

**请求示例**:
```json
{
    "id": 1,
    "is_active": 0
}
```

**响应示例**:
```json
{
    "success": true,
    "data": {
        "message": "移动端更新状态更新成功"
    }
}
```

---

## 通用错误码

| HTTP状态码 | 说明 |
|-----------|------|
| 200 | 请求成功 |
| 400 | 请求参数错误 |
| 401 | 未授权访问，请先登录 |
| 404 | 资源不存在 |
| 405 | 不支持的请求方法 |
| 500 | 服务器内部错误 |

## 通用响应格式

**成功响应**:
```json
{
    "success": true,
    "data": { ... }
}
```

**失败响应**:
```json
{
    "success": false,
    "error": "错误描述信息"
}
```

---

## 客户端对接指南（检查更新）

客户端App启动时，调用 `GET ?action=latest` 接口获取最新版本信息，与本地版本号对比判断是否需要更新。此接口**无需认证**，可直接调用。

### 请求

```
GET http://ai.yueyaxuan.cn/api/mobile_updates/mobile_updates.php?action=latest
```

### 响应处理逻辑

1. 检查 `success` 是否为 `true`
2. 检查 `data.update` 是否为 `null`：
   - 为 `null` → 当前无启用版本，无需更新
   - 不为 `null` → 进入版本对比
3. 将服务端返回的 `version` 与本地版本号对比：
   - 服务端版本 > 本地版本 → 需要更新
   - 服务端版本 ≤ 本地版本 → 已是最新版本
4. 需要更新时，根据 `is_force` 判断：
   - `is_force = 1` → 强制更新，用户必须下载安装后才能继续使用
   - `is_force = 0` → 非强制更新，用户可选择跳过
5. 使用 `download_url` 提供下载链接，使用 `title` 和 `content` 展示更新说明

### 版本号对比规则

版本号格式为 `X.Y.Z`（如 `1.2.3`），按段逐个比较数字大小：

- `1.2.0` > `1.1.9`（第二段 2 > 1）
- `2.0.0` > `1.9.9`（第一段 2 > 1）
- `1.2.3` = `1.2.3`（相等则无需更新）

### 示例代码（Android / Kotlin）

```kotlin
// 检查更新
val response = httpClient.get("http://ai.yueyaxuan.cn/api/mobile_updates/mobile_updates.php?action=latest")
val json = response.json()

if (json.getBoolean("success")) {
    val update = json.optJSONObject("data")?.optJSONObject("update")
    if (update != null) {
        val serverVersion = update.getString("version")       // 如 "1.2.0"
        val title = update.getString("title")                  // 更新标题
        val content = update.getString("content")              // 更新内容（HTML）
        val downloadUrl = update.getString("download_url")     // 下载链接
        val isForce = update.getInt("is_force") == 1           // 是否强制更新

        val currentVersion = BuildConfig.VERSION_NAME          // 本地版本号

        if (compareVersions(serverVersion, currentVersion) > 0) {
            showUpdateDialog(title, content, downloadUrl, isForce)
        }
    }
}

fun compareVersions(v1: String, v2: String): Int {
    val parts1 = v1.split(".").map { it.toInt() }
    val parts2 = v2.split(".").map { it.toInt() }
    val maxLen = maxOf(parts1.size, parts2.size)
    for (i in 0 until maxLen) {
        val p1 = parts1.getOrElse(i) { 0 }
        val p2 = parts2.getOrElse(i) { 0 }
        if (p1 != p2) return p1 - p2
    }
    return 0
}
```

### 示例代码（iOS / Swift）

```swift
// 检查更新
let url = URL(string: "http://ai.yueyaxuan.cn/api/mobile_updates/mobile_updates.php?action=latest")!
let (data, _) = try await URLSession.shared.data(from: url)
let json = try JSONSerialization.jsonObject(with: data) as! [String: Any]

if json["success"] as! Bool,
   let dataObj = json["data"] as? [String: Any],
   let update = dataObj["update"] as? [String: Any] {
    let serverVersion = update["version"] as! String
    let title = update["title"] as! String
    let content = update["content"] as! String
    let downloadUrl = update["download_url"] as! String
    let isForce = update["is_force"] as! Int == 1

    let currentVersion = Bundle.main.infoDictionary?["CFBundleShortVersionString"] as! String

    if compareVersions(serverVersion, currentVersion) > 0 {
        showUpdateAlert(title: title, message: content, downloadUrl: downloadUrl, isForce: isForce)
    }
}

func compareVersions(_ v1: String, _ v2: String) -> Int {
    let parts1 = v1.split(separator: ".").map { Int($0) ?? 0 }
    let parts2 = v2.split(separator: ".").map { Int($0) ?? 0 }
    let maxLen = max(parts1.count, parts2.count)
    for i in 0..<maxLen {
        let p1 = parts1.count > i ? parts1[i] : 0
        let p2 = parts2.count > i ? parts2[i] : 0
        if p1 != p2 { return p1 - p2 }
    }
    return 0
}
```

### 示例代码（Flutter / Dart）

```dart
// 检查更新
final response = await http.get(Uri.parse('http://ai.yueyaxuan.cn/api/mobile_updates/mobile_updates.php?action=latest'));
final json = jsonDecode(response.body);

if (json['success'] == true) {
  final update = json['data']?['update'];
  if (update != null) {
    final serverVersion = update['version'] as String;
    final title = update['title'] as String;
    final content = update['content'] as String;
    final downloadUrl = update['download_url'] as String;
    final isForce = update['is_force'] == 1;

    final currentVersion = '1.0.0'; // 从 package_info_plus 获取

    if (_compareVersions(serverVersion, currentVersion) > 0) {
      _showUpdateDialog(title, content, downloadUrl, isForce);
    }
  }
}

int _compareVersions(String v1, String v2) {
  final parts1 = v1.split('.').map(int.parse).toList();
  final parts2 = v2.split('.').map(int.parse).toList();
  final maxLen = parts1.length > parts2.length ? parts1.length : parts2.length;
  for (var i = 0; i < maxLen; i++) {
    final p1 = i < parts1.length ? parts1[i] : 0;
    final p2 = i < parts2.length ? parts2[i] : 0;
    if (p1 != p2) return p1 - p2;
  }
  return 0;
}
```
