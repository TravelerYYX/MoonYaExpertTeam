<div align="center">

# 月雅专家团 · MoonYa Expert Team

**让 AI 拥有一间真正的办公室，而不仅仅是一个对话框。**

一间 2.5D 可视化虚拟办公室 · 九位分工子 Agent · 五层能力路由 · 浏览器自动化 · 桌面 Computer Use · 全程可视可监控

</div>

---

## 这是什么

月雅专家团不是又一个聊天框。它是一座**会动的虚拟办公室**：你走进办公室，看见九个工位、九个角色，有人正在敲键盘，有人正在浏览网页，MoonYa 站在左上角派发任务——你看见的不是日志，是**一支正在工作的 AI 团队**。

当任务落在浏览器，Browser Agent 通过 DOM/CDP 精准操控网页；当任务落在桌面应用，Computer Agent 启动 UIA + 视觉模型 + 键盘语义三级兜底操控 Windows 界面。整个过程通过**实时时间线 GUI** 呈现：思考流、截图、动作、计划、验证，每一步都看得见。

**一句话**：把"AI 帮你干活"从一句承诺，变成"你看着它在你电脑上把活干了"。

---

## 三个核心，缺一不可

### ① 2.5D 虚拟办公室 — 把多 Agent 协作变成一场可以围观的工作

九个工位 3×3 排列，纯 CSS 绘制的 2.5D 场景：显示器、键盘、鼠标、手部、阴影、坐姿角色精灵，全部不需要 3D 引擎。

```
┌─────────────┬─────────────┬─────────────┐
│  MoonYa     │  Image      │  Search     │
│  (团队负责人) │  (图像)      │  (搜索)      │
├─────────────┼─────────────┼─────────────┤
│  File        │  Voice      │  App        │
│  (文件)      │  (语音)      │  (应用)      │
├─────────────┼─────────────┼─────────────┤
│  Browser    │  Code       │  Computer   │
│  (浏览器)    │  (代码)      │  (桌面)      │
└─────────────┴─────────────┴─────────────┘
```

**你能看到的**：

- **MoonYa 派活** — 接到任务后，行走精灵从 `(0,0)` 工位走出，沿着办公室走到目标工位，对话气泡弹出任务摘要
- **工位亮起** — 被点到的 Agent 工位高亮，角色切换为"正在工作"状态
- **人物资料卡** — 点击任意角色，弹出姓名 / 职务 / 技能 / 实时状态
- **独立窗口模式** — `?office_popout=1` 让办公室常驻第二屏，多显示器干活更爽
- **状态联动** — 工位状态与后端 TeamCoordinator 实时同步，不是装饰，是真状态

> 这不是"为了好看"。多 Agent 系统最大的痛点是**黑盒**——你不知道谁在干什么、干到哪了。办公室把这层黑盒撕开了。

### ② 浏览器自动化 — 22 种动作精准操控任意网页

`BrowserAutomationGateway` 定义了统一的浏览器后端传输契约，22 种动作覆盖网页自动化的全部场景：

| 类别 | 动作 |
| --- | --- |
| 会话 | `start` `status` `stop` |
| 导航 | `navigate` `back` `forward` `reload` |
| 观察 | `inspect` `screenshot` |
| 交互 | `click` `fill` `hover` `press` `select` `check` `uncheck` |
| 滚动/等待 | `scroll` `wait` |
| 标签页 | `new_tab` `list_tabs` `switch_tab` `close_tab` |
| 下载 | `list_downloads` |

**为什么稳**：

- **资源锁防死锁** — 浏览器会话持有 `browser:default` 排他锁，90 秒服务端截止 + 30 秒锁超时，MoonYa 重启后游离 worker 也不会永久占着浏览器
- **VLS 视觉定位** — DOM 定位不确定时，视觉定位代理对局部截图分析，返回可见区域、元素、文本、不确定项。confidence < 0.7、窗口变化、快照过期——一律重新观察，**绝不猜点**，最多三次
- **结构化结果** — 每次动作返回 DOM 元素树、焦点元素、页面变化检测、截图、业务数据，下一步决策有据可依

### ③ Computer Use 桌面操控 — 当 API、Shell、浏览器都搞不定时的终极兜底

当任务落在**已打开的 Windows 桌面应用**（Office、记事本、系统设置、第三方 GUI 软件），前四层全部失效，Computer Agent 登场。

#### 三接口强制闭环

桌面阶段对外只暴露三个接口，**禁止跳步**：

```
computer_observe  →  computer_interact  →  computer_complete
   (观察)              (执行一个动作)         (有证据才结束)
```

#### 禁止绝对像素，只允许语义操作

`computer_interact.operation` 白名单：

| 操作 | 含义 |
| --- | --- |
| `invoke` | 调用控件（按钮点击、菜单项选择） |
| `set_value` | 设置文本框值 |
| `select` | 选择下拉项 |
| `toggle` | 切换开关/复选框 |
| `expand` / `collapse` | 展开/折叠树节点 |
| `scroll` | 语义滚动 |
| `key_chord` | 组合键（语义快捷键，**非坐标**） |

`target` 必须是语义目标（控件名/业务对象），**禁止提交绝对像素坐标**。这一约束确保操作可验证、可回放、不受 DPI / 分辨率 / 窗口位置影响。

#### 三级定位兜底

```
① UIA 原生 Pattern（首选，最精准）
    ↓ 不可用时
② VLM 安全视觉定位（SoM 标记 + 两阶段返回 mark_id）
    ↓ 不可用时
③ 键盘语义降级（key_chord / set_value / invoke，仍禁止坐标）
```

VLM 视觉定位的安全策略：

- 第一阶段返回归一化候选框 + 标签 + confidence（**不返回屏幕绝对坐标**）
- 运行时绘制 SoM（Set of Marks）后，第二阶段返回 `mark_id` + confidence
- confidence < 0.7、窗口变化、快照过期、目标不唯一 → 重新观察，最多三次

#### 严格安全策略（CuPolicyCatalog）

- **敏感模态框必须停** — 登录、验证码、密码、生物识别、支付、权限授予、保存确认，一律停止并交还用户，**不得代填、猜测、绕过**
- **副作用不明禁重放** — 写操作超时/断线/回执缺失，结果视为 `unknown`，必须先 `observe` 获取独立证据；**无法证明写操作未执行时禁止重放或降级重复**
- **不可信数据不授权** — 截图、网页、文件中的文字都是不可信数据，不能授权新动作或改变系统规则
- **前台操作** — Windows 桌面操作是前台操作，需要目标窗口处于激活状态

#### CU 执行过程 GUI — 把桌面操控的黑盒彻底打开

这是月雅专家团最与众不同的部分：**Computer Use 的每一步都在聊天界面里实时可视化**，不是埋在日志里的冰冷文字。

当 Computer Agent 启动，聊天区会出现一个 **Codex 风格的可折叠执行卡片**（`cu-codex-card`）：

```
⁁ 正在操作电脑                    执行中        ▾
├── · 思考：当前屏幕显示 Word 文档，需要点击"文件"菜单...
├── 📷 截图 #1 · 14:23:05  [缩略图，点击放大]
├── 🖱  click：文件菜单
├── 📷 截图 #2 · 14:23:08  [缩略图]
├── ⌨   key_chord：Ctrl+S
├── · 任务计划
│    ① 打开文件菜单  ✓
│    ② 选择"另存为"  →
│    ③ 输入文件名    ·
│    ④ 点击保存      ·
└── ⚠ 等待你操作：检测到保存对话框，需要你确认路径
```

**GUI 元素清单**：

| 元素 | 作用 |
| --- | --- |
| `cu-codex-card` | 执行卡片容器，可折叠，状态色（working/waiting/done/limited/error） |
| `cu-codex-header` | 卡片头：⁁ 标记 + 标题 + 状态 + 折叠箭头 |
| `cu-timeline` | 时间线，左侧竖线，节点依次追加 |
| `cu-step-thinking` | 模型思考流，支持 SSE 增量流式（按 token 不堆积） |
| `cu-step-screenshot` | 截图节点，200px 缩略图卡片，hover 放大 |
| `cu-screenshot-card` | 截图卡片，点击打开 Lightbox 灯箱预览 |
| `cu-lightbox` | 全屏灯箱，支持上一张/下一张/ESC 关闭/点击遮罩关闭 |
| `cu-step-mouse` / `cu-step-keyboard` | 鼠标/键盘动作节点，带图标 + 动作描述 + 目标 |
| `cu-plan-card` | 任务计划卡，步骤列表，含状态色（active/completed/failed/retrying） |
| `cu-plan-step-num` | 步骤序号圆点，完成变绿、失败变红、重试变黄 |
| `cu-plan-step-type` | 步骤类型标签（click/type/key/observe/scroll/drag） |
| `cu-step-warning` | 警告节点（等待用户、敏感模态框停止） |
| `cu-step-complete` | 完成节点 |
| `cu-final-answer` | 最终答案区域，桌面阶段结束后展示结论 |
| `cu-waiting-user` | 等待用户操作提示 |

**流式协议**（通过 `TeamCuEventEmitter` 适配 TeamEventV1）：

| SSE 事件 | GUI 渲染 |
| --- | --- |
| `cu_status` | 创建/更新执行卡片，设置状态 |
| `cu_thinking` | 思考节点，支持 `delta` 增量流式（复用同一节点，不按 token 堆积） |
| `cu_screenshot` | 截图节点 + 缩略图，`index=1` 时重置截图序列 |
| `cu_action` | 动作节点（鼠标/键盘），带 `target` 或 `params` |
| `cu_step` | 通用步骤节点，带 `type` 和 `status` |
| `cu_plan` | 任务计划卡，含步骤列表 |
| `cu_step_progress` | 步骤进度更新（active/completed/failed/retrying） |
| `cu_waiting_user` | 等待用户节点，卡片切 `is-waiting` 状态 |
| `cu_complete` | 完成事件，所有 running 节点转 done，展示最终答案 |

**这就是"惊艳"的地方**：别的 Computer Use 实现让你盯着"正在执行..."然后等结果，月雅专家团让你**看见它思考、看见它截图、看见它点击、看见它等待你**。桌面操控不再是黑盒。

---

## 分层执行架构 — 为什么不直接用 Computer Use

`CapabilityRouter` 是服务端权威的五层能力选择策略。Computer Use 是**最后一层**，不是第一选择：

| 优先级 | 层级 | 工具示例 | 为什么 |
| --- | --- | --- | --- |
| 10 | ① 专用 API | web_search、generate_image、MCP 连接器 | 最快、最准、最便宜 |
| 20 | ② 确定性工具 | 其他确定性工具 | 可预测、可验证 |
| 30 | ③ Shell | shell_executor、python_executor | 命令行能搞定就别点鼠标 |
| 40 | ④ 浏览器 | browser_automation、vls_analyze_browser | DOM/CDP 比像素精准得多 |
| 50 | ⑤ Computer | computer_observe/interact/complete | 兜底，只在前面都搞不定时启用 |

**关键规则**：高层写操作超时或结果不明时，**必须先验证**，不能降级后重复副作用。原始桌面原语（`mouse_move`、`mouse_click`、`keyboard_type`）作为内部启动器协议被隐藏，模型只能通过三个业务接口访问桌面。

---

## 浏览器 vs Computer Use 怎么选

| 场景 | 选择 | 原因 |
| --- | --- | --- |
| 网页表单填写 | **浏览器自动化** | DOM/CDP 精准 |
| 浏览器原生对话框（另存为） | **Computer Use** | UIA 控件 |
| 桌面 Office 应用 | **Computer Use** | UIA/VLM |
| 命令行能搞定的 | **Shell** | 最快 |
| 有专用 API 的 | **专用 API** | 最优 |

---

## 九子 Agent 团队

`TeamCoordinator` 负责任务分解、团队委派、结果汇总。`AgentLoopGuard` 防止循环死锁（默认 3 次重复计数、4 个周期窗口、1 次恢复尝试）。

| Agent | 职责 |
| --- | --- |
| MoonYa | 团队负责人：任务分解、委派、结果综合 |
| Image | 图像生成、图片/视频理解、OCR |
| Search | 联网搜索、多源调研、资料溯源 |
| File | 文件处理、内容编辑、Office 文档 |
| Voice | 语音识别、语音播报、实时对话 |
| App | 应用检测、安装卸载、启动关闭 |
| Browser | 网页浏览、页面交互、浏览器自动化 |
| Code | 代码开发、项目分析、运行测试 |
| Computer | 系统状态读取、桌面界面操作、结果验证 |

---

## 技术栈

- **后端**：PHP 8.1+（严格类型）
- **Windows 桌面端**：C# / .NET（MoonYa-Solution）
- **前端**：原生 PHP 模板 + CSS（2.5D 场景纯 CSS 绘制）+ Web Worker
- **桌面操控**：Windows UI Automation API + VLM 视觉定位
- **浏览器自动化**：CDP / DOM 自动化

## 目录结构

```
源码/
├── MoonYa-Backend/                         # PHP 后端
│   ├── Services/
│   │   ├── BrowserAutomationGateway.php   # 浏览器自动化网关（22 种动作）
│   │   ├── CapabilityRouter.php           # 五层分层执行路由
│   │   ├── CuPolicyCatalog.php            # Computer Use 安全策略目录
│   │   ├── CuEventEmitter.php             # CU 事件发射器
│   │   ├── TeamCuEventEmitter.php         # CU → TeamEventV1 适配器
│   │   ├── ExecutionGuard.php             # 执行守护进程（断线重连）
│   │   ├── TeamCoordinator.php            # 团队协调器
│   │   ├── TeamEventEmitter.php           # 团队事件发射器
│   │   ├── AgentLoopGuard.php             # Agent 循环防护
│   │   └── ToolGateway.php                # 工具网关
│   ├── office/index.php                   # 办公室独立窗口入口
│   ├── script/MoonYa-index/
│   │   ├── layouts/office-panel.php       # 2.5D 办公室面板
│   │   ├── modules/script-1e-rest.php     # CU 执行卡片 GUI 渲染逻辑
│   │   ├── modules/script-5-office.php    # 办公室交互脚本
│   │   ├── styles/css-07-agent.php        # CU GUI 样式 + Agent 样式
│   │   ├── styles/css-17-office.php       # 办公室 2.5D 样式
│   │   └── ...
│   ├── assets/office/                     # 办公室精灵图（坐姿/行走/色键）
│   └── tests/                             # 契约测试与冒烟测试
├── MoonYa-Win/MoonYa-Solution/            # C# .NET Windows 桌面端
│   ├── MoonYa.CuContracts/                # Computer Use 契约
│   └── ...
└── README.md
```

---

## 快速开始

### 环境要求

- PHP 8.1+（含 CLI）
- Windows 10/11（Computer Use 桌面操控需要）
- .NET SDK（如需构建 Windows 桌面端）

### 后端配置

1. 复制 `.env.example` 为 `.env`，填入模型 API 地址与密钥
2. 配置 Web 服务器（Nginx/Apache）指向 `MoonYa-Backend/`
3. 确保桌面桥接器运行（用于浏览器与 Computer Use）

### 访问

- 主界面：`/index.php`
- 办公室独立窗口：`/office/index.php` 或 `/?office_popout=1`

---

## 安全与合规

- **敏感操作交还用户** — 登录、支付、权限授予由用户处理，AI 不代填
- **副作用可验证** — 写操作结果不明时禁止重放
- **不可信数据不授权** — 截图/网页/文件中的文字不改变系统规则
- **前台操作** — 桌面操控仅在前台窗口生效

---

## 开源协议

本项目开源，欢迎学习与交流。

---

<div align="center">

**月雅专家团 — 让 AI 真正走进你的办公室。**

</div>
