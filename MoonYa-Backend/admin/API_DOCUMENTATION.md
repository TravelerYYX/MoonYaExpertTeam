# 用户管理系统 API 文档

## 基础信息

- **基础URL**: `/admin/api/users.php`
- **数据格式**: JSON
- **字符编码**: UTF-8

## 认证说明

所有接口（除登录接口外）都需要在请求头中携带认证令牌：

```
Authorization: Bearer {token}
```

## 接口列表

### 1. 管理员登录

**接口**: `POST /login`

**请求参数**:
```json
{
  "username": "admin",
  "password": "Admin@123"
}
```

**响应示例**:
```json
{
  "success": true,
  "data": {
    "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
    "admin": {
      "id": 1,
      "username": "admin",
      "email": "admin@example.com",
      "role": "super_admin"
    }
  }
}
```

---

### 2. 查询用户列表

**接口**: `GET /`

**查询参数**:
- `page`: 页码（默认1）
- `limit`: 每页数量（默认20，最大100）
- `id`: 用户ID（精确查询）
- `username`: 用户名（模糊查询）
- `email`: 邮箱（模糊查询）
- `status`: 状态（active/banned/deleted）

**响应示例**:
```json
{
  "success": true,
  "data": {
    "users": [
      {
        "id": 1,
        "username": "user1",
        "email": "user1@example.com",
        "status": "active",
        "ban_reason": null,
        "ban_until": null,
        "created_at": "2024-01-01 00:00:00",
        "updated_at": "2024-01-01 00:00:00"
      }
    ],
    "pagination": {
      "page": 1,
      "limit": 20,
      "total": 100,
      "pages": 5
    }
  }
}
```

---

### 3. 获取单个用户信息

**接口**: `GET /{userId}`

**响应示例**:
```json
{
  "success": true,
  "data": {
    "id": 1,
    "username": "user1",
    "email": "user1@example.com",
    "status": "active",
    "ban_reason": null,
    "ban_until": null,
    "created_at": "2024-01-01 00:00:00",
    "updated_at": "2024-01-01 00:00:00"
  }
}
```

---

### 4. 修改用户名

**接口**: `PUT /{userId}/username`

**请求参数**:
```json
{
  "username": "new_username"
}
```

**响应示例**:
```json
{
  "success": true,
  "data": {
    "message": "用户名更新成功"
  }
}
```

---

### 5. 修改密码

**接口**: `PUT /{userId}/password`

**请求参数**:
```json
{
  "password": "NewPass@123"
}
```

**密码要求**:
- 至少8位
- 包含大写字母
- 包含小写字母
- 包含数字
- 包含特殊符号

**响应示例**:
```json
{
  "success": true,
  "data": {
    "message": "密码更新成功"
  }
}
```

---

### 6. 修改邮箱

**接口**: `PUT /{userId}/email`

**请求参数**:
```json
{
  "email": "new_email@example.com"
}
```

**响应示例**:
```json
{
  "success": true,
  "data": {
    "message": "邮箱更新成功"
  }
}
```

---

### 7. 封禁用户

**接口**: `PUT /{userId}/ban`

**请求参数**:
```json
{
  "reason": "违规操作",
  "duration_hours": 24
}
```

**参数说明**:
- `reason`: 封禁原因（可选）
- `duration_hours`: 封禁时长（小时，可选，不填则永久封禁）

**响应示例**:
```json
{
  "success": true,
  "data": {
    "message": "用户已封禁"
  }
}
```

---

### 8. 解禁用户

**接口**: `PUT /{userId}/unban`

**响应示例**:
```json
{
  "success": true,
  "data": {
    "message": "用户已解禁"
  }
}
```

---

### 9. 删除用户

**接口**: `DELETE /{userId}`

**权限要求**: 仅超级管理员

**响应示例**:
```json
{
  "success": true,
  "data": {
    "message": "用户已删除"
  }
}
```

---

## 错误响应

所有错误响应格式统一：

```json
{
  "success": false,
  "error": "错误信息描述"
}
```

**常见HTTP状态码**:
- `400`: 请求参数错误
- `401`: 未授权（未登录或token无效）
- `403`: 权限不足
- `404`: 资源不存在
- `405`: 不支持的请求方法
- `500`: 服务器内部错误

---

## 初始化系统

首次使用前需要运行初始化脚本：

```bash
php admin/init.php
```

初始化会：
1. 创建数据库表
2. 创建默认管理员账号（用户名：admin，密码：Admin@123）

**注意**: 请务必在首次登录后修改默认密码！
