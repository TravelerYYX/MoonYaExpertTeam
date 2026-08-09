# 用户管理后端系统

## 目录结构

```
admin/
├── config.php              # 系统配置文件
├── Database.php            # 数据库连接类
├── Auth.php                # 身份验证和权限控制类
├── Logger.php              # 日志记录类
├── init.php                # 系统初始化脚本
├── API_DOCUMENTATION.md    # API文档
├── README.md               # 本文件
├── api/
│   └── users.php           # 用户管理API接口
└── logs/                   # 日志文件目录
    └── .htaccess           # 安全配置
```

## 安装步骤

### 1. 配置数据库

编辑 `config.php` 文件，设置数据库连接信息：

```php
return [
    'db_host' => 'localhost',    // 数据库主机
    'db_name' => 'ai_system',    // 数据库名称
    'db_user' => 'root',         // 数据库用户名
    'db_pass' => '',              // 数据库密码
    'admin_secret' => 'your_admin_secret_key_here_change_this',
    'log_path' => __DIR__ . '/logs/',
    'jwt_secret' => 'your_jwt_secret_key_here_change_this_to_a_strong_secret'
];
```

**重要**: 请务必修改 `admin_secret` 和 `jwt_secret` 为强密码！

### 2. 创建数据库

在 MySQL 中创建数据库：

```sql
CREATE DATABASE ai_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 3. 初始化系统

运行初始化脚本：

```bash
php admin/init.php
```

这将：
- 创建必要的数据表（users、admins、admin_logs）
- 创建默认管理员账号

默认管理员账号：
- 用户名: `admin`
- 密码: `Admin@123`

**警告**: 请在首次登录后立即修改默认密码！

## API 使用

详细的API文档请参考 `API_DOCUMENTATION.md`。

### 快速开始示例

#### 1. 登录获取Token

```bash
curl -X POST http://your-domain.com/admin/api/users.php/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"Admin@123"}'
```

#### 2. 查询用户列表

```bash
curl -X GET http://your-domain.com/admin/api/users.php?page=1&limit=20 \
  -H "Authorization: Bearer YOUR_TOKEN_HERE"
```

#### 3. 修改用户密码

```bash
curl -X PUT http://your-domain.com/admin/api/users.php/1/password \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -d '{"password":"NewPass@123"}'
```

## 安全建议

1. **修改默认密码**: 首次登录后立即修改管理员密码
2. **配置HTTPS**: 在生产环境中使用HTTPS
3. **保护配置文件**: 确保 config.php 不能被直接访问
4. **定期备份**: 定期备份数据库和日志文件
5. **监控日志**: 定期检查 admin_logs 表和 logs 目录中的日志

## 功能特性

- ✅ 用户查询（支持按ID、用户名、邮箱、状态查询）
- ✅ 用户信息修改（用户名、密码、邮箱）
- ✅ 账号状态管理（封禁、解禁、删除）
- ✅ JWT身份验证
- ✅ 基于角色的权限控制
- ✅ 操作日志记录
- ✅ 密码强度验证
- ✅ RESTful API设计
- ✅ 分页支持

## 数据库表结构

### users 表
- id: 用户ID（主键）
- username: 用户名（唯一）
- email: 邮箱（唯一）
- password: 密码（加密存储）
- status: 账号状态（active/banned/deleted）
- ban_reason: 封禁原因
- ban_until: 封禁到期时间
- created_at: 创建时间
- updated_at: 更新时间

### admins 表
- id: 管理员ID（主键）
- username: 用户名（唯一）
- password: 密码（加密存储）
- email: 邮箱（唯一）
- role: 角色（super_admin/admin）
- created_at: 创建时间

### admin_logs 表
- id: 日志ID（主键）
- admin_id: 管理员ID
- action: 操作类型
- target_user_id: 目标用户ID
- details: 操作详情
- ip_address: 操作IP
- created_at: 操作时间

## 故障排除

### 数据库连接失败
- 检查 config.php 中的数据库配置
- 确认数据库服务已启动
- 确认数据库用户有足够的权限

### 初始化失败
- 确认数据库已创建
- 检查数据库用户权限
- 查看 PHP 错误日志

### 认证失败
- 确认 token 未过期
- 检查 Authorization 头格式
- 确认使用正确的 token

## 许可证

本项目仅供学习和使用。
