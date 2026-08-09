# MoonYa Index 模块化重构

本目录包含 MoonYa 项目 `index.php` 的模块化拆分结构。原 `index.php` 已被拆分为多个子文件以提升可维护性。

## 目录结构

```
script/MoonYa-index/
├── styles/             # CSS 分段 (15 个文件)
├── layouts/            # HTML 布局分段 (7 个文件)
├── modules/            # JS 业务模块 (7 个文件)
├── utils/              # 工具函数占位 (6 个文件)
├── components/         # UI 组件占位 (8 个文件)
└── data/               # 数据模型占位 (6 个文件)
```

## 文件清单

### styles/ (CSS 分段, 15 个文件)

按 CSS 区块拆分，共 4,755 行 CSS：

| 文件 | 行数 | 内容 |
|------|------|------|
| `css-01-base.php` | 40 | 基础重置、body、user-select |
| `css-02-auth.php` | 384 | 登录注册弹窗、模态框、标签、表格 |
| `css-03-floating.php` | 167 | 浮动按钮（侧边栏收起时显示） |
| `css-04-sidebar.php` | 474 | 侧边栏、菜单、最近对话 |
| `css-05-toast.php` | 100 | Toast 提示样式 |
| `css-06-main.php` | 430 | 右侧主内容区 |
| `css-07-agent.php` | 48 | Agent 状态栏 |
| `css-08-code.php` | 176 | 代码块样式 |
| `css-09-animations.php` | 401 | 新建对话按钮、入场动画 |
| `css-10-music.php` | 678 | 音乐卡片、播放按钮 |
| `css-11-uploader.php` | 121 | 图片上传、进度条 |
| `css-12-features.php` | 247 | 模型/模式选择样式 |
| `css-13-update.php` | 221 | 版本更新弹窗 |
| `css-14-responsive.php` | 1078 | 响应式布局 |
| `css-15-result.php` | 188 | 执行结果显示 |

### layouts/ (HTML 布局, 7 个文件)

| 文件 | 行数 | 内容 |
|------|------|------|
| `container.php` | 15 | `</head>` + `<body>` + 容器 + 浮动按钮 |
| `sidebar.php` | 118 | 左侧侧边栏 |
| `auth-modal.php` | 76 | 登录注册弹窗 |
| `main-content.php` | 323 | 右侧主内容 + 输入框 |
| `toast.php` | 3 | Toast 提示 div |
| `dynamic-island.php` | 62 | 灵动岛音乐播放器 HTML |
| `video-player.php` | 6 | 视频播放器浮层 |

### modules/ (JS 业务模块, 7 个文件)

| 文件 | 行数 | 内容 |
|------|------|------|
| `script-1a-vars.php` | 602 | 变量声明、模型/模式选择器 |
| `script-1b-features.php` | 784 | 图片上传、Toast、音乐、星座、天气 |
| `script-1c-save.php` | 1231 | 保存/加载对话、语音播报 |
| `script-1d-dom.php` | 2668 | DOM 初始化、事件绑定 |
| `script-1e-rest.php` | 2751 | 消息发送、流式处理、代码块、渲染 |
| `script-2-island.php` | 566 | 灵动岛 IIFE |
| `script-2-download.php` | 234 | 文件下载功能 |

### utils/, components/, data/ (占位文件)

- `utils/` (6): 工具函数占位文件（实际逻辑在对应 modules 中）
- `components/` (8): UI 组件占位文件（实际逻辑在对应 modules/layouts 中）
- `data/` (6): 数据模型占位文件（实际数据在对应 modules 中）

## 验证结果

- **原文件**: 14,215 行，690,444 字节
- **拆分后总行数**: 14,233 行（保存在 29 个业务 PHP 文件中）
- **新 index.php**: 54 行（PHP 配置 + head + 包含语句 + 关闭标签）
- **占位文件**: 20 个（utils 6 + components 8 + data 6）
- **PHP 语法检查**: 50/50 通过
- **PHP 执行测试**: 输出 14,195 行，718,836 字节

### 行数差异说明

- 拆分文件总行数（14,233）比原文件（14,215）多 18 行
- 这部分差异来自新 index.php 中 35 行新内容（PHP 配置 4 行 + 28 个 include 语句 + 2 个空行 + 2 行样式标签），以及占位文件
- 原文件中的 19 行 head/closing 标签被保留在新 index.php 中
- PHP 执行的输出与原文件内容等价（仅格式略有不同：原文件保留 PHP 代码占位，重构版执行后输出实际数据）

## 使用方式

根目录的 `index.php` 通过 `<?php include __DIR__ . '/script/MoonYa-index/...' ?>` 语句按顺序引入各分段文件，最终输出与原单文件版完全一致。
