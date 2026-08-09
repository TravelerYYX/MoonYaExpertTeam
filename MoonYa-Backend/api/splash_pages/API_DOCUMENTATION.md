# 启动页管理 API 接口文档

## 基础信息

- **基础URL**: `http://ai.yueyaxuan.cn/api/splash_pages/splash_pages.php`
- **数据格式**: JSON
- **字符编码**: UTF-8

## 认证说明

| 接口 | 是否需要认证 | 说明 |
|------|-------------|------|
| `GET ?action=active` | ❌ 免认证 | 供客户端App匿名调用 |
| `GET ?action=list` | ✅ 需要认证 | 管理后台使用 |
| `GET ?action=get` | ✅ 需要认证 | 管理后台使用 |
| `POST ?action=add` | ✅ 需要认证 | 管理后台使用 |
| `POST ?action=update` | ✅ 需要认证 | 管理后台使用 |
| `POST ?action=delete` | ✅ 需要认证 | 管理后台使用 |
| `POST ?action=toggle` | ✅ 需要认证 | 管理后台使用 |

需要认证的接口请在请求头中携带管理员令牌：

```
Authorization: Bearer {token}
```

---

## 数据库表结构设计

### splash_pages 表

| 字段名 | 类型 | 默认值 | 说明 |
|--------|------|--------|------|
| id | INT AUTO_INCREMENT | - | 主键，自增ID |
| image_url | VARCHAR(500) | - | 启动页图片链接（必填） |
| jump_url | VARCHAR(500) | '' | 点击跳转链接（可为空） |
| sort_order | INT | 0 | 排序权重，数字越小越靠前 |
| is_active | TINYINT(1) | 1 | 是否启用：1=启用，0=禁用 |
| created_at | TIMESTAMP | CURRENT_TIMESTAMP | 创建时间 |
| updated_at | TIMESTAMP | CURRENT_TIMESTAMP ON UPDATE | 更新时间（自动更新） |

**建表SQL**:
```sql
CREATE TABLE IF NOT EXISTS splash_pages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    image_url VARCHAR(500) NOT NULL COMMENT '启动页图片链接',
    jump_url VARCHAR(500) DEFAULT '' COMMENT '点击跳转链接（可为空）',
    sort_order INT DEFAULT 0 COMMENT '排序（数字越小越靠前）',
    is_active TINYINT(1) DEFAULT 1 COMMENT '是否启用：1启用 0禁用',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='启动页管理表';
```

---

## 接口列表

### 1. 获取已启用的启动页（随机一个） 🔓 免认证

从所有已启用的启动页中**随机返回一条**，供客户端App启动时展示。

**接口**: `GET ?action=active`

**认证**: 不需要

**请求参数**: 无

**完整请求地址**:
```
http://ai.yueyaxuan.cn/api/splash_pages/splash_pages.php?action=active
```

**响应示例**（有数据时）:
```json
{
    "success": true,
    "data": {
        "splash_page": {
            "id": 1,
            "image_url": "https://example.com/splash1.png",
            "jump_url": "https://example.com/promo",
            "sort_order": 0,
            "is_active": 1,
            "created_at": "2026-05-01 10:00:00",
            "updated_at": "2026-05-01 10:00:00"
        }
    }
}
```

**响应示例**（无启用启动页时）:
```json
{
    "success": true,
    "data": {
        "splash_page": null
    }
}
```

---

### 2. 获取启动页列表

获取所有启动页数据，按排序权重升序排列。

**接口**: `GET ?action=list`

**认证**: 需要管理员令牌

**请求参数**: 无

**完整请求地址**:
```
http://ai.yueyaxuan.cn/api/splash_pages/splash_pages.php?action=list
```

**响应示例**:
```json
{
    "success": true,
    "data": {
        "splash_pages": [
            {
                "id": 1,
                "image_url": "https://example.com/splash1.png",
                "jump_url": "https://example.com/promo",
                "sort_order": 0,
                "is_active": 1,
                "created_at": "2026-05-01 10:00:00",
                "updated_at": "2026-05-01 10:00:00"
            },
            {
                "id": 2,
                "image_url": "https://example.com/splash2.png",
                "jump_url": "",
                "sort_order": 1,
                "is_active": 0,
                "created_at": "2026-05-01 11:00:00",
                "updated_at": "2026-05-01 11:00:00"
            }
        ]
    }
}
```

---

### 3. 获取单个启动页详情

根据ID获取单条启动页数据。

**接口**: `GET ?action=get&id={id}`

**认证**: 需要管理员令牌

**请求参数**:

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| id | int | 是 | 启动页ID |

**完整请求地址**:
```
http://ai.yueyaxuan.cn/api/splash_pages/splash_pages.php?action=get&id=1
```

**响应示例**:
```json
{
    "success": true,
    "data": {
        "splash_page": {
            "id": 1,
            "image_url": "https://example.com/splash1.png",
            "jump_url": "https://example.com/promo",
            "sort_order": 0,
            "is_active": 1,
            "created_at": "2026-05-01 10:00:00",
            "updated_at": "2026-05-01 10:00:00"
        }
    }
}
```

**错误响应**:
```json
{
    "success": false,
    "error": "启动页不存在"
}
```

---

### 4. 添加启动页

新增一条启动页数据。

**接口**: `POST ?action=add`

**认证**: 需要管理员令牌

**请求头**:
```
Content-Type: application/json
Authorization: Bearer {token}
```

**完整请求地址**:
```
http://ai.yueyaxuan.cn/api/splash_pages/splash_pages.php?action=add
```

**请求参数**:

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| image_url | string | 是 | 启动页图片链接 |
| jump_url | string | 否 | 点击跳转链接（可为空字符串） |
| sort_order | int | 否 | 排序权重，默认自动递增 |
| is_active | int | 否 | 是否启用，默认1（启用） |

**请求示例**:
```json
{
    "image_url": "https://example.com/splash_new.png",
    "jump_url": "https://example.com/new_promo",
    "sort_order": 0,
    "is_active": 1
}
```

**响应示例**:
```json
{
    "success": true,
    "data": {
        "message": "启动页添加成功",
        "id": 3
    }
}
```

**错误响应**:
```json
{
    "success": false,
    "error": "启动页图片链接不能为空"
}
```

---

### 5. 修改启动页

根据ID更新启动页数据。

**接口**: `POST ?action=update`

**认证**: 需要管理员令牌

**请求头**:
```
Content-Type: application/json
Authorization: Bearer {token}
```

**完整请求地址**:
```
http://ai.yueyaxuan.cn/api/splash_pages/splash_pages.php?action=update
```

**请求参数**:

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| id | int | 是 | 启动页ID |
| image_url | string | 是 | 启动页图片链接 |
| jump_url | string | 否 | 点击跳转链接（可为空字符串） |
| sort_order | int | 否 | 排序权重，默认0 |
| is_active | int | 否 | 是否启用，默认1 |

**请求示例**:
```json
{
    "id": 1,
    "image_url": "https://example.com/splash_updated.png",
    "jump_url": "https://example.com/updated_promo",
    "sort_order": 2,
    "is_active": 1
}
```

**响应示例**:
```json
{
    "success": true,
    "data": {
        "message": "启动页更新成功"
    }
}
```

**错误响应**:
```json
{
    "success": false,
    "error": "启动页ID不能为空"
}
```

---

### 6. 删除启动页

根据ID删除一条启动页数据。

**接口**: `POST ?action=delete`

**认证**: 需要管理员令牌

**请求头**:
```
Content-Type: application/json
Authorization: Bearer {token}
```

**完整请求地址**:
```
http://ai.yueyaxuan.cn/api/splash_pages/splash_pages.php?action=delete
```

**请求参数**:

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| id | int | 是 | 启动页ID |

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
        "message": "启动页删除成功"
    }
}
```

**错误响应**:
```json
{
    "success": false,
    "error": "启动页ID不能为空"
}
```

---

### 7. 切换启动页启用/禁用状态

快速切换启动页的启用/禁用状态。

**接口**: `POST ?action=toggle`

**认证**: 需要管理员令牌

**请求头**:
```
Content-Type: application/json
Authorization: Bearer {token}
```

**完整请求地址**:
```
http://ai.yueyaxuan.cn/api/splash_pages/splash_pages.php?action=toggle
```

**请求参数**:

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| id | int | 是 | 启动页ID |
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
        "message": "启动页状态更新成功"
    }
}
```

**错误响应**:
```json
{
    "success": false,
    "error": "启动页ID不能为空"
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

所有接口返回标准JSON格式：

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

## 客户端对接指南（获取启动页）

客户端App启动时，调用 `GET ?action=active` 接口获取一个随机启用的启动页进行展示。此接口**无需认证**，可直接调用。

### 请求

```
GET http://ai.yueyaxuan.cn/api/splash_pages/splash_pages.php?action=active
```

### 响应处理逻辑

1. 检查 `success` 是否为 `true`
2. 检查 `data.splash_page` 是否为 `null`：
   - 为 `null` → 当前无启用启动页，跳过启动页展示
   - 不为 `null` → 展示启动页图片
3. 若 `jump_url` 不为空字符串，用户点击启动页图片时跳转到对应链接；若为空则点击无跳转

### 示例代码（Android / Kotlin）

```kotlin
// 请求启动页
val response = httpClient.get("http://ai.yueyaxuan.cn/api/splash_pages/splash_pages.php?action=active")
val json = response.json()

if (json.getBoolean("success")) {
    val splashPage = json.optJSONObject("data")?.optJSONObject("splash_page")
    if (splashPage != null) {
        val imageUrl = splashPage.getString("image_url")
        val jumpUrl = splashPage.optString("jump_url", "")
        // 加载并展示启动页图片
        Glide.with(this).load(imageUrl).into(splashImageView)
        // 设置点击跳转
        if (jumpUrl.isNotEmpty()) {
            splashImageView.setOnClickListener {
                startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(jumpUrl)))
            }
        }
    } else {
        // 无启用启动页，直接进入主页
    }
}
```

### 示例代码（iOS / Swift）

```swift
// 请求启动页
let url = URL(string: "http://ai.yueyaxuan.cn/api/splash_pages/splash_pages.php?action=active")!
let (data, _) = try await URLSession.shared.data(from: url)
let json = try JSONSerialization.jsonObject(with: data) as! [String: Any]

if json["success"] as! Bool,
   let dataObj = json["data"] as? [String: Any],
   let splashPage = dataObj["splash_page"] as? [String: Any] {
    let imageUrl = splashPage["image_url"] as! String
    let jumpUrl = splashPage["jump_url"] as? String ?? ""
    // 加载并展示启动页图片
    if let url = URL(string: imageUrl) {
        imageView.kf.setImage(with: url)
    }
    // 设置点击跳转
    if !jumpUrl.isEmpty {
        let tapGesture = UITapGestureRecognizer {
            UIApplication.shared.open(URL(string: jumpUrl)!)
        }
        imageView.addGestureRecognizer(tapGesture)
    }
}
```

### 示例代码（Flutter / Dart）

```dart
// 请求启动页
final response = await http.get(Uri.parse('http://ai.yueyaxuan.cn/api/splash_pages/splash_pages.php?action=active'));
final json = jsonDecode(response.body);

if (json['success'] == true) {
  final splashPage = json['data']?['splash_page'];
  if (splashPage != null) {
    final imageUrl = splashPage['image_url'] as String;
    final jumpUrl = splashPage['jump_url'] as String? ?? '';
    // 展示启动页
    showDialog(
      context: context,
      builder: (_) => GestureDetector(
        onTap: jumpUrl.isNotEmpty ? () => launchUrl(Uri.parse(jumpUrl)) : null,
        child: Image.network(imageUrl, fit: BoxFit.cover),
      ),
    );
  }
}
```
