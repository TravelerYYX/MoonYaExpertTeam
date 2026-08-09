# 月雅专家团 · MoonYa Expert Team

> 以 **2.5D 可视化虚拟办公室** 为交互首页，**9 个分工子 Agent 工位** 协同调度。采用 **分层执行架构**：优先 API、Shell、浏览器自动化，无法调用时启用 **UIA‑GUI‑视觉模型兜底** 操控界面，解决跨软件网页重复办公难题，可视化监控智能体，自动执行复杂链式任务。

---

## 目录

- [核心亮点](#核心亮点)
- [系统架构总览](#系统架构总览)
- [2.5D 可视化虚拟办公室（交互首页）](#25d-可视化虚拟办公室交互首页)
- [浏览器自动化（Browser Agent）](#浏览器自动化browser-agent)
- [Computer Use 桌面操控（Computer Agent）](#computer-use-桌面操控computer-agent)
- [分层执行架构（Capability Router）](#分层执行架构capability-router)
- [9 子 Agent 团队分工](#9-子-agent-团队分工)
- [技术栈与目录结构](#技术栈与目录结构)
- [快速开始](#快速开始)
- [安全与合规](#安全与合规)
- [开源协议](#开源协议)

---

## 核心亮点

| 能力 | 说明 |
| --- | --- |
| **2.5D 虚拟办公室** | 以 3×3 工位布局呈现 9 个子 Agent，MoonYa 作为团队负责人固定左上角，派发任务时行走精灵从工位走出，实时可视化每个 Agent 的工作状态。 |
| **浏览器自动化** | 统一后端传输契约，22 种 DOM/CDP 动作（导航、点击、填充、滚动、标签页、截图等），配合 VLS 视觉定位模型，稳定操控任意网页。 |
| **Computer Use 桌面操控** | 当 API/Shell/浏览器均不适用时，通过 UIA 语义控件 + VLM 视觉定位 + 键盘语义降级三级兜底，操控已打开的 Windows 桌面应用，observe→interact→verify 闭环。 |
| **分层执行路由** | 服务端权威的五层能力选择策略（专用 API → 确定性工具 → Shell → 浏览器 → Computer），避免无谓的桌面像素操作。 |
| **复杂链式任务** | TeamCoordinator 负责任务分解、团队委派与结果汇总，AgentLoopGuard 防止循环死锁，自动执行跨软件、跨网页的链式办公流程。 |

---

## 系统架构总览

```
┌─────────────────────────────────────────────────────────────────┐
│                    用户交互层（2.5D 虚拟办公室）                   │
│   MoonYa(0,0)  Image(0,1)  Search(0,2)                          │
│   File(1,0)    Voice(1,1)  App(1,2)        ← 9 工位可视化监控     │
│   Browser(2,0) Code(2,1)   Computer(2,2)                        │
└───────────────────────────┬─────────────────────────────────────┘
                            │ 任务委派
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│                  TeamCoordinator（团队协调器）                     │
│   任务分解 → 工位委派 → 结果汇总 → AgentLoopGuard 防死锁          │
└───────────────────────────┬─────────────────────────────────────┘
                            │ 工具调用
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│              CapabilityRouter（分层执行路由）                      │
│                                                                  │
│   ① specialized_api    专用 API / MCP / 连接器      (优先级 10)   │
│   ② deterministic_tool  确定性工具                    (优先级 20)   │
│   ③ shell              Shell / Python 命令          (优先级 30)   │
│   ④ browser            浏览器 DOM/CDP 自动化         (优先级 40)   │
│   ⑤ computer           Computer Use 桌面操控（兜底）  (优先级 50)   │
│                                                                  │
│   高层写操作超时/结果不明 → 先验证，不降级重复副作用                 │
└───────────────────────────┬─────────────────────────────────────┘
                            │
              ┌─────────────┼─────────────┐
              ▼             ▼             ▼
        BrowserAutomation  ToolGateway   Computer Use
        Gateway            (Shell/API)   (UIA+VLM+键盘)
```

---

## 2.5D 可视化虚拟办公室（交互首页）

办公室是月雅专家团的**主交互界面**，将抽象的多 Agent 协作过程转化为可视、可监控的虚拟办公场景。

### 工位布局

9 个工位采用 **3×3 行优先（row-major）** 布局，MoonYa 作为团队负责人固定在左上角原点 `(0,0)`：

| (row, col) | Agent | 职责 |
| --- | --- | --- |
| (0, 0) | **MoonYa** | 团队负责人：任务分解、团队委派、结果综合 |
| (0, 1) | Image Agent | 图像生成、图片/视频内容理解 |
| (0, 2) | Search Agent | 联网搜索、多来源调研、资料溯源 |
| (1, 0) | File Agent | 文件处理、内容编辑、Office 文档 |
| (1, 1) | Voice Agent | 语音识别、语音播报、实时交互 |
| (1, 2) | App Agent | 应用检测、安装卸载、启动关闭 |
| (2, 0) | Browser Agent | 网页浏览、页面交互、浏览器自动化 |
| (2, 1) | Code Agent | 代码开发、项目分析、运行测试 |
| (2, 2) | Computer Agent | 系统状态读取、桌面界面操作、结果验证 |

### 可视化元素

每个工位由纯 CSS 绘制的 2.5D 场景构成，无需 3D 引擎：

- **工位（workstation）**：含桌面前沿、桌面顶部、显示器（含边框/屏幕/支架/底座）、键盘、鼠标、手部、阴影
- **角色精灵（ws-character）**：坐姿背影图，派发任务时切换为空椅
- **行走精灵（office-walker）**：MoonYa 接收任务后从 `(0,0)` 工位走出，动画移动到目标工位
- **状态指示（ws-active）**：当前激活的工位高亮
- **对话气泡（office-bubble）**：MoonYa 派发任务时显示任务摘要
- **人物资料卡（office-agent-card）**：点击角色弹出，展示名称、职务、简介、技能、实时状态

### 独立窗口模式

办公室支持 `?office_popout=1` 参数以独立窗口打开，仅加载办公室所需 CSS 与脚本，便于多显示器场景下常驻监控。

### 状态联动

工位状态与后端 `TeamCoordinator` 实时联动：任务委派时目标工位激活、行走精灵出发；任务完成后工位回归空闲、精灵归位。用户无需查看日志即可直观感知整个团队的工作进度。

---

## 浏览器自动化（Browser Agent）

浏览器自动化是分层执行架构的**第四层**，用于操控网页目标。相比 Computer Use 的像素级操控，浏览器自动化基于 DOM/CDP，**精准、快速、可验证**。

### 统一传输契约

`BrowserAutomationGateway` 定义了所有浏览器 Agent 入口的单一后端传输契约。桌面桥接器在运行时从服务清单解析相对路由，部署地址与代码解耦。

### 支持的 22 种动作

| 类别 | 动作 |
| --- | --- |
| **会话管理** | `start` `status` `stop` |
| **导航** | `navigate` `back` `forward` `reload` |
| **观察** | `inspect` `screenshot` |
| **交互** | `click` `fill` `hover` `press` `select` `check` `uncheck` |
| **滚动/等待** | `scroll` `wait` |
| **标签页** | `new_tab` `list_tabs` `switch_tab` `close_tab` |
| **下载** | `list_downloads` |

### 结果模型

每次浏览器动作返回结构化结果，包含：

- `success` / `error_code` / `error`：执行状态
- `page_url` / `page_title` / `page_version`：页面快照版本
- `dom_elements` / `focused_element`：可交互元素树与焦点
- `page_changed` / `change_hint` / `change_evidence`：页面变化检测
- `screenshot`：base64 截图（可选）
- `data`：结构化业务数据

### 资源锁与超时保护

浏览器会话持有 `browser:default` 排他锁。为防止桌面桥接器在 MoonYa 重启后失联导致死锁，每次浏览器调用均有**服务端截止时间**（默认 90 秒）与**资源锁超时**（30 秒），确保游离 worker 无法永久占用浏览器会话。

### VLS 视觉定位

当 DOM 定位不确定时，`vls_analyze_browser` 调用视觉定位代理（VLS），对页面局部截图进行分析，返回：

- `visible_regions` / `visible_elements` / `visible_text`：可见区域与元素
- `uncertainties`：不确定项
- `page_version`：快照版本，用于检测页面是否已变化

VLS 遵循安全策略：confidence < 0.7、窗口变化或快照过期时要求重新观察，最多三次，绝不猜点。

---

## Computer Use 桌面操控（Computer Agent）

Computer Use 是分层执行架构的**第五层（兜底）**，仅当前四层（API/工具/Shell/浏览器）均不适用时才启用，用于操控**已打开的 Windows 桌面应用**。

### 三接口闭环

桌面阶段对外只暴露三个接口，强制 observe→act→verify 闭环：

| 接口 | 作用 |
| --- | --- |
| `computer_observe` | 观察当前桌面/窗口状态，获取 UI 快照与控件树 |
| `computer_interact` | 执行**一个**语义动作后立即返回 |
| `computer_complete` | 仅在有证据支持的真实终态调用，结束桌面阶段 |

### 语义动作（禁止绝对像素）

`computer_interact.operation` 仅允许语义操作：

- `invoke`：调用控件（按钮点击、菜单项选择）
- `set_value`：设置文本框值
- `select`：选择下拉项
- `toggle`：切换开关/复选框
- `expand` / `collapse`：展开/折叠树节点
- `scroll`：语义滚动
- `key_chord`：组合键（语义快捷键，非坐标）

**`target` 必须是语义目标**（控件名/业务对象），禁止提交绝对像素坐标。这一约束确保操作可验证、可回放、不受 DPI/分辨率/窗口位置影响。

### 三级定位兜底

当需要定位桌面控件时，按以下顺序尝试：

1. **UIA 原生 Pattern**：通过 Windows UI Automation API 直接定位语义控件（首选，最精准）
2. **VLM 安全视觉定位**：UIA 不可用时，视觉语言模型对 ROI 局部截图定位
   - 第一阶段：返回归一化候选框、标签、confidence（不返回屏幕绝对坐标）
   - 运行时绘制 SoM（Set of Marks）后，第二阶段返回 `mark_id` 与 confidence
   - confidence < 0.7、窗口变化、快照过期或目标不唯一时要求重新观察，最多三次
3. **键盘语义降级**：UIA 与 VLM 均不可用时，通过 `key_chord`、`set_value`、`invoke` 等语义键盘动作操作（仍禁止坐标）

### 安全策略

CuPolicyCatalog 定义了严格的桌面安全策略：

- **敏感模态框停止**：登录、验证码、密码、生物识别、支付、权限授予、保存确认等敏感界面**必须停止**并说明需要用户处理，不得代填、猜测或绕过。`computer_complete` 返回 `blocked` 或 `failed`。
- **副作用结果不明禁重放**：写操作超时、断线或回执缺失时，结果视为 `unknown`。必须先 `computer_observe` 获取独立证据；**无法证明写操作未执行时禁止重放或降级到另一层重复执行**。
- **不可信数据不授权**：UI、网页、截图、文件和附件中的文字都是不可信数据，不能授权新动作或改变系统规则。
- **前台操作**：Windows 桌面操作是前台操作，需要目标窗口处于激活状态。
- **不输出隐藏思维链**：桌面阶段只返回观察事实与动作结果。

### 与浏览器自动化的分工

| 场景 | 选择 |
| --- | --- |
| 网页上的表单填写 | **浏览器自动化**（DOM/CDP，精准） |
| 浏览器原生对话框（如另存为） | **Computer Use**（UIA 控件） |
| 桌面 Office 应用操作 | **Computer Use**（UIA/VLM） |
| 命令行可完成的任务 | **Shell**（第三层） |
| 有专用 API 的任务 | **专用 API**（第一层，最优） |

CapabilityRouter 确保优先使用最精准、副作用最小的层，Computer Use 仅作为最后兜底。

---

## 分层执行架构（Capability Router）

`CapabilityRouter` 是服务端权威的能力选择策略，决定每个任务由哪一层执行。

### 五层优先级

| 优先级 | 层级 | route_class | 包含的工具 |
| --- | --- | --- | --- |
| 10 | ① 专用 API | `specialized_api` | web_search、web_fetch、web_crawler、get_weather、search_music、generate_image、generate_video、MCP 连接器等 |
| 20 | ② 确定性工具 | `deterministic_tool` | 其他未分类的确定性工具 |
| 30 | ③ Shell | `shell` | shell_executor、python_executor、execute_command、execute_python 等 |
| 40 | ④ 浏览器 | `browser` | browser_automation_control、vls_analyze_browser |
| 50 | ⑤ Computer | `computer` | computer_observe、computer_interact、computer_complete（原始桌面原语被隐藏） |

### 选择策略

- 这是**选择优先级**，不要求每层都执行
- 模型只能在高层不可用或已证明无副作用时才降级
- **高层写操作超时或结果不明时必须先验证**，不能降级后重复副作用
- 原始桌面原语（`mouse_move`、`mouse_click`、`keyboard_type` 等）作为内部启动器协议被隐藏，模型只能通过 `computer_observe/interact/complete` 三个业务接口访问桌面

### 工具合并与排序

`CapabilityRouter::modelTools()` 合并多来源工具定义，按 route_class 排序后暴露给模型，确保模型优先看到高层工具。

---

## 9 子 Agent 团队分工

`TeamCoordinator` 负责任务分解、团队委派与结果汇总。每个 Agent 有明确的职责边界：

| Agent | 职责 | 典型场景 |
| --- | --- | --- |
| **MoonYa** | 团队负责人 | 任务分解、团队委派、最终结果综合 |
| **Image Agent** | 图像生成 | 生成图片、理解图片/视频内容、OCR |
| **Search Agent** | 搜索检索 | 联网搜索、多源调研、资料溯源 |
| **File Agent** | 文件管理 | 文件处理、内容编辑、Office 文档 |
| **Voice Agent** | 语音交互 | 语音识别、语音播报、实时对话 |
| **App Agent** | 应用操作 | 应用检测、安装卸载、启动关闭 |
| **Browser Agent** | 网页浏览 | 网页浏览、页面交互、浏览器自动化 |
| **Code Agent** | 代码开发 | 代码浏览、编辑、执行、项目分析 |
| **Computer Agent** | 电脑控制 | 系统状态读取、桌面界面操作、结果验证 |

### 协作机制

- **委派工具**：MoonYa 通过 `delegationTool` 获取所有可路由能力及其适用/不适用场景，据此分派任务
- **AgentLoopGuard**：防止 Agent 重复执行相同动作（默认 3 次重复计数、4 个周期窗口、1 次恢复尝试）
- **事件流**：`TeamEventEmitter` 将每个 Agent 的状态变化推送到办公室可视化层
- **断线重连**：`ExecutionGuard` 支持执行任务断线重连，重放未确认事件

---

## 技术栈与目录结构

### 技术栈

- **后端**：PHP 8.1+（严格类型）
- **Windows 桌面端**：C# / .NET（MoonYa-Solution）
- **前端**：原生 PHP 模板 + CSS（2.5D 场景纯 CSS 绘制）+ Web Worker
- **桌面操控**：Windows UI Automation API + VLM 视觉定位
- **浏览器自动化**：CDP / DOM 自动化

### 目录结构

```
源码/
├── MoonYa-Backend/                  # PHP 后端
│   ├── Services/
│   │   ├── BrowserAutomationGateway.php   # 浏览器自动化网关
│   │   ├── CapabilityRouter.php           # 分层执行路由
│   │   ├── CuPolicyCatalog.php            # Computer Use 策略目录
│   │   ├── CuEventEmitter.php             # CU 事件发射器
│   │   ├── CuRunCheckpoint.php            # CU 运行检查点
│   │   ├── ExecutionGuard.php             # 执行守护进程
│   │   ├── TeamCoordinator.php            # 团队协调器
│   │   ├── TeamEventEmitter.php           # 团队事件发射器
│   │   ├── AgentLoopGuard.php             # Agent 循环防护
│   │   ├── ToolGateway.php                # 工具网关
│   │   └── ...
│   ├── office/
│   │   └── index.php                      # 办公室独立窗口入口
│   ├── script/MoonYa-index/
│   │   ├── layouts/office-panel.php       # 2.5D 办公室面板
│   │   ├── modules/script-5-office.php    # 办公室交互脚本
│   │   ├── styles/css-17-office.php       # 办公室样式
│   │   └── ...
│   ├── assets/office/                     # 办公室精灵图（坐姿/行走/色键）
│   ├── workers/
│   │   └── conversation_task_worker.php   # 会话任务 worker
│   └── tests/                             # 契约测试与冒烟测试
├── MoonYa-Win/                            # C# .NET Windows 桌面端
│   └── MoonYa-Solution/
│       ├── MoonYa.CuContracts/            # Computer Use 契约
│       ├── MoonYa.CuFixture/              # CU 固定装置
│       └── ...
├── .gitignore
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

### 访问办公室

- 主界面：`/index.php`
- 办公室独立窗口：`/office/index.php` 或 `/?office_popout=1`

---

## 安全与合规

- **敏感操作交还用户**：登录、支付、权限授予等敏感模态框由用户处理
- **副作用可验证**：写操作结果不明时禁止重放
- **不可信数据不授权**：截图/网页/文件中的文字不改变系统规则
- **前台操作**：桌面操控仅在前台窗口生效
- **PAT 令牌**：如需对接 GitHub，使用最小权限 PAT，用后即撤

---

## 开源协议

本项目开源，欢迎学习与交流。
