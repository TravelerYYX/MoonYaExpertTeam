import re

# 读取原始文件
with open('admin/dashboard.php', 'r', encoding='utf-8') as f:
    original = f.read()

# 提取所有 <script>...</script> 块
script_blocks = re.findall(r'<script>(.*?)</script>', original, re.DOTALL)

if len(script_blocks) < 2:
    print(f"Warning: found {len(script_blocks)} script blocks, expected at least 2")

# 合并所有 script 内容到 dashboard.js
js_content = '\n'.join(script_blocks)
with open('admin/dashboard.js', 'w', encoding='utf-8') as f:
    f.write(js_content)

print(f"Extracted {len(script_blocks)} script blocks to admin/dashboard.js")

# 找到 <body> 到第一个 <script> 之间的 HTML
# 以及最后一个 </script> 到 </body> 之间的内容
body_match = re.search(r'(<body>)(.*?)(<script>)', original, re.DOTALL)
end_match = re.search(r'(</script>)(.*?)(</body>)', original, re.DOTALL)

if not body_match or not end_match:
    print("Could not parse HTML structure")
    exit(1)

# 构建新的 dashboard.php
new_content = '''<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理后台</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background-color: #0B0E14;
            color: #E5E7EB;
            min-height: 100vh;
        }
        
        /* 顶部导航栏 */
        .top-nav {
            position: fixed;
            top: 0; left: 0; right: 0;
            height: 64px;
            background: rgba(15, 23, 42, 0.95);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(255,255,255,0.08);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 32px;
            z-index: 100;
        }
        
        .nav-brand {
            font-size: 20px;
            font-weight: 700;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-right: 24px;
        }
        
        .nav-center {
            display: flex;
            gap: 8px;
            align-items: center;
            flex: 1;
            overflow-x: auto;
        }
        
        .nav-center::-webkit-scrollbar { display: none; }
        
        .nav-item {
            padding: 8px 16px;
            border-radius: 20px;
            cursor: pointer;
            font-size: 14px;
            color: #9CA3AF;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
            border: none;
            background: transparent;
            font-family: inherit;
        }
        
        .nav-item:hover {
            color: #E5E7EB;
            background: rgba(255,255,255,0.05);
        }
        
        .nav-item.active {
            background: #fff;
            color: #0B0E14;
            font-weight: 500;
        }
        
        .nav-right {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-left: 16px;
        }
        
        .admin-info {
            font-size: 13px;
            color: #9CA3AF;
        }
        
        .logout-btn {
            padding: 6px 14px;
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.12);
            border-radius: 8px;
            color: #E5E7EB;
            cursor: pointer;
            font-size: 13px;
            transition: all 0.3s;
        }
        
        .logout-btn:hover {
            background: rgba(255,255,255,0.15);
        }
        
        /* 主内容区 */
        .main-content {
            padding: 88px 32px 32px;
            max-width: 1600px;
            margin: 0 auto;
        }
        
        .page-title {
            font-size: 28px;
            font-weight: 600;
            color: #F9FAFB;
            margin-bottom: 24px;
        }
        
        /* 统计卡片 */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 24px;
        }
        
        .stat-card {
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.15) 0%, rgba(118, 75, 162, 0.15) 100%);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 20px;
            padding: 24px;
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0; right: 0;
            width: 120px; height: 120px;
            background: radial-gradient(circle, rgba(255,255,255,0.06) 0%, transparent 70%);
            border-radius: 50%;
            transform: translate(30%, -30%);
        }
        
        .stat-card h3 {
            font-size: 13px;
            color: #9CA3AF;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .stat-card .number {
            font-size: 32px;
            font-weight: 700;
            color: #F9FAFB;
        }
        
        .stat-card .number.active { color: #4ADE80; }
        .stat-card .number.banned { color: #F87171; }
        .stat-card .number.pending { color: #FBBF24; }
        
        /* 内容卡片 */
        .content-card {
            background: rgba(255,255,255,0.03);
            border: 1px solid rgba(255,255,255,0.06);
            border-radius: 16px;
            overflow: hidden;
            margin-bottom: 20px;
        }
        
        .card-header {
            padding: 20px 24px;
            border-bottom: 1px solid rgba(255,255,255,0.06);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }
        
        .card-header h2 {
            font-size: 16px;
            font-weight: 600;
            color: #F9FAFB;
        }
        
        /* 表格 */
        .table-container { overflow-x: auto; }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th, td {
            padding: 14px 24px;
            text-align: left;
            border-bottom: 1px solid rgba(255,255,255,0.05);
            font-size: 14px;
        }
        
        th {
            background: rgba(255,255,255,0.03);
            font-weight: 600;
            color: #9CA3AF;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        td { color: #D1D5DB; }
        tr:hover td { background: rgba(255,255,255,0.02); }
        
        /* 搜索 */
        .search-form {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .search-input {
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 8px;
            color: #E5E7EB;
            padding: 10px 14px;
            font-size: 14px;
            outline: none;
            transition: all 0.3s;
        }
        
        .search-input:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 2px rgba(102, 126, 234, 0.2);
        }
        
        .search-input::placeholder { color: #6B7280; }
        
        /* 按钮 */
        .search-btn {
            padding: 8px 16px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 8px;
            color: white;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .search-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }
        
        .action-btn {
            padding: 4px 12px;
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 4px;
            background: rgba(255,255,255,0.05);
            color: #D1D5DB;
            font-size: 12px;
            cursor: pointer;
            margin-right: 8px;
            transition: all 0.3s;
        }
        
        .action-btn:hover {
            border-color: #667eea;
            color: #667eea;
            background: rgba(102, 126, 234, 0.1);
        }
        
        .action-btn.danger {
            border-color: rgba(248, 113, 113, 0.3);
            color: #F87171;
        }
        
        .action-btn.danger:hover {
            background: rgba(248, 113, 113, 0.1);
        }
        
        .action-btn.primary {
            border-color: rgba(74, 222, 128, 0.3);
            color: #4ADE80;
        }
        
        .action-btn.primary:hover {
            background: rgba(74, 222, 128, 0.1);
        }
        
        /* 分页 */
        .pagination {
            padding: 16px 24px;
            display: flex;
            justify-content: center;
            gap: 8px;
        }
        
        .page-btn {
            padding: 6px 12px;
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 6px;
            color: #9CA3AF;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .page-btn:hover:not(:disabled) {
            background: rgba(255,255,255,0.1);
            color: #E5E7EB;
        }
        
        .page-btn.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-color: transparent;
        }
        
        .page-btn:disabled {
            opacity: 0.3;
            cursor: not-allowed;
        }
        
        /* 状态徽章 */
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .status-badge.active {
            background: rgba(74, 222, 128, 0.1);
            color: #4ADE80;
            border: 1px solid rgba(74, 222, 128, 0.2);
        }
        
        .status-badge.banned {
            background: rgba(248, 113, 113, 0.1);
            color: #F87171;
            border: 1px solid rgba(248, 113, 113, 0.2);
        }
        
        .status-badge.deleted {
            background: rgba(156, 163, 175, 0.1);
            color: #9CA3AF;
            border: 1px solid rgba(156, 163, 175, 0.2);
        }
        
        /* Alert */
        .alert {
            position: fixed;
            top: 80px;
            right: 20px;
            padding: 16px 24px;
            border-radius: 12px;
            font-size: 14px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
            z-index: 1000;
            animation: slideIn 0.3s ease;
            display: none;
            backdrop-filter: blur(10px);
        }
        
        .alert.show { display: block; }
        
        .alert.success {
            background: rgba(74, 222, 128, 0.15);
            border: 1px solid rgba(74, 222, 128, 0.25);
            color: #4ADE80;
        }
        
        .alert.error {
            background: rgba(248, 113, 113, 0.15);
            border: 1px solid rgba(248, 113, 113, 0.25);
            color: #F87171;
        }
        
        @keyframes slideIn {
            from { opacity: 0; transform: translateX(100px); }
            to { opacity: 1; transform: translateX(0); }
        }
        
        /* 模态框 */
        .modal {
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(0, 0, 0, 0.7);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2000;
        }
        
        .modal.show { display: flex; }
        
        .modal-content {
            background: #1F2937;
            padding: 32px;
            border-radius: 16px;
            width: 100%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            border: 1px solid rgba(255,255,255,0.08);
            color: #E5E7EB;
        }
        
        .modal-header {
            margin-bottom: 24px;
        }
        
        .modal-header h3 {
            font-size: 20px;
            font-weight: 600;
            color: #F9FAFB;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            font-weight: 500;
            color: #D1D5DB;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px 14px;
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 8px;
            color: #E5E7EB;
            font-size: 14px;
            outline: none;
            transition: all 0.3s;
            font-family: inherit;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 2px rgba(102, 126, 234, 0.2);
        }
        
        .modal-footer {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 24px;
        }
        
        .modal-btn {
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s;
            border: none;
            font-family: inherit;
        }
        
        .modal-btn.cancel {
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.1);
            color: #9CA3AF;
        }
        
        .modal-btn.cancel:hover {
            background: rgba(255,255,255,0.1);
            color: #E5E7EB;
        }
        
        .modal-btn.confirm {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .modal-btn.confirm:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }
        
        .modal-btn.danger {
            background: #EF4444;
            color: white;
        }
        
        .modal-btn.danger:hover {
            background: #F87171;
        }
        
        /* Switch开关样式 */
        .switch-container {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
            flex-shrink: 0;
        }
        
        .switch-input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .switch-label {
            position: absolute;
            cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background-color: #374151;
            transition: .4s;
            border-radius: 24px;
        }
        
        .switch-label:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        
        .switch-input:checked + .switch-label {
            background-color: #667eea;
        }
        
        .switch-input:checked + .switch-label:before {
            transform: translateX(26px);
        }
        
        .switch-tip {
            position: relative;
            display: inline-block;
        }
        
        .tip-text {
            visibility: hidden;
            width: 200px;
            background-color: #1F2937;
            color: #fff;
            text-align: center;
            border-radius: 8px;
            padding: 8px;
            position: absolute;
            z-index: 1;
            bottom: 125%;
            left: 50%;
            margin-left: -100px;
            opacity: 0;
            transition: opacity 0.3s;
            font-size: 12px;
            border: 1px solid rgba(255,255,255,0.1);
        }
        
        .switch-tip:hover .tip-text {
            visibility: visible;
            opacity: 1;
        }
        
        /* API调试面板 */
        .api-debug-panel {
            margin-top: 16px;
            border: 1px solid rgba(255,255,255,0.06);
            border-radius: 12px;
            overflow: hidden;
        }
        
        .api-debug-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 16px;
            background: rgba(255,255,255,0.03);
            border-bottom: 1px solid rgba(255,255,255,0.06);
            cursor: pointer;
        }
        
        .api-debug-header:hover {
            background: rgba(255,255,255,0.05);
        }
        
        .api-debug-header h3 {
            font-size: 14px;
            color: #D1D5DB;
            margin: 0;
        }
        
        .api-debug-toggle {
            font-size: 12px;
            color: #6B7280;
            transition: transform 0.3s;
        }
        
        .api-debug-body {
            display: none;
            padding: 16px;
        }
        
        .api-debug-body.open { display: block; }
        
        .api-endpoint-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 12px;
            margin-bottom: 8px;
            background: rgba(255,255,255,0.03);
            border: 1px solid rgba(255,255,255,0.06);
            border-radius: 8px;
            font-size: 13px;
        }
        
        .api-method {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: bold;
            color: white;
            min-width: 42px;
            text-align: center;
        }
        
        .api-method.get { background: #61affe; }
        .api-method.post { background: #49cc90; }
        
        .api-path {
            flex: 1;
            font-family: 'Courier New', monospace;
            color: #D1D5DB;
            word-break: break-all;
        }
        
        .api-desc {
            color: #6B7280;
            font-size: 12px;
            min-width: 100px;
        }
        
        .api-debug-btn {
            padding: 4px 12px;
            font-size: 12px;
            border: 1px solid #667eea;
            background: transparent;
            color: #667eea;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.2s;
            white-space: nowrap;
        }
        
        .api-debug-btn:hover {
            background: #667eea;
            color: white;
        }
        
        .api-debug-result {
            margin-top: 12px;
            border: 1px solid rgba(255,255,255,0.06);
            border-radius: 8px;
            overflow: hidden;
            display: none;
        }
        
        .api-debug-result.show { display: block; }
        
        .api-debug-result-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 12px;
            background: rgba(255,255,255,0.03);
            border-bottom: 1px solid rgba(255,255,255,0.06);
        }
        
        .api-debug-result-header span {
            font-size: 12px;
            color: #9CA3AF;
        }
        
        .api-debug-result-close {
            cursor: pointer;
            color: #6B7280;
            font-size: 14px;
        }
        
        .api-debug-result-body {
            padding: 12px;
            max-height: 300px;
            overflow-y: auto;
        }
        
        .api-debug-result-body pre {
            margin: 0;
            font-size: 12px;
            font-family: 'Courier New', monospace;
            white-space: pre-wrap;
            word-break: break-all;
            color: #D1D5DB;
        }
        
        .api-debug-status {
            font-size: 12px;
            padding: 2px 8px;
            border-radius: 4px;
        }
        
        .api-debug-status.success { background: rgba(74, 222, 128, 0.15); color: #4ADE80; }
        .api-debug-status.error { background: rgba(248, 113, 113, 0.15); color: #F87171; }
        
        /* 添加用户模态框 */
        .add-user-overlay {
            display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); z-index:10000;
            align-items:center; justify-content:center;
        }
        .add-user-overlay.show { display:flex; }
        .add-user-modal {
            background:#1F2937; border-radius:16px; padding:32px; width:420px; max-width:90vw;
            box-shadow:0 20px 60px rgba(0,0,0,0.5);
            border: 1px solid rgba(255,255,255,0.08);
        }
        .add-user-modal h3 { font-size:20px; margin-bottom:20px; color:#F9FAFB; }
        .add-user-modal .form-field { margin-bottom:16px; }
        .add-user-modal .form-field label { display:block; font-size:13px; color:#9CA3AF; margin-bottom:6px; font-weight:500; }
        .add-user-modal .form-field input {
            width:100%; padding:10px 12px; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); border-radius:8px; font-size:14px;
            outline:none; transition:border-color 0.3s; box-sizing:border-box; color:#E5E7EB;
        }
        .add-user-modal .form-field input:focus { border-color:#667eea; box-shadow:0 0 0 3px rgba(102,126,234,0.2); }
        .add-user-modal .qq-wrapper { position:relative; }
        .add-user-modal .qq-wrapper input { padding-right:80px; }
        .add-user-modal .qq-suffix { position:absolute; right:12px; top:50%; transform:translateY(-50%); color:#6B7280; font-size:13px; pointer-events:none; }
        .add-user-modal .btn-row { display:flex; gap:10px; margin-top:24px; }
        .add-user-modal .btn-row button {
            flex:1; padding:10px; border:none; border-radius:8px; font-size:14px; font-weight:500; cursor:pointer;
        }
        .add-user-modal .btn-cancel { background:rgba(255,255,255,0.06); color:#9CA3AF; }
        .add-user-modal .btn-cancel:hover { background:rgba(255,255,255,0.1); }
        .add-user-modal .btn-submit { background:linear-gradient(135deg,#667eea,#764ba2); color:#fff; }
        .add-user-modal .btn-submit:hover { opacity:0.9; }
        .add-user-modal .btn-submit:disabled { opacity:0.5; cursor:not-allowed; }
        
        /* 首页仪表盘布局 */
        .dashboard-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
        }
        
        .dashboard-left {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        
        .dashboard-right {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        
        .chart-card {
            background: rgba(255,255,255,0.03);
            border: 1px solid rgba(255,255,255,0.06);
            border-radius: 16px;
            padding: 24px;
        }
        
        .chart-card h3 {
            font-size: 16px;
            font-weight: 600;
            color: #F9FAFB;
            margin-bottom: 16px;
        }
        
        .chart-container {
            position: relative;
            height: 200px;
        }
        
        .side-card {
            background: rgba(255,255,255,0.03);
            border: 1px solid rgba(255,255,255,0.06);
            border-radius: 16px;
            padding: 24px;
        }
        
        .side-card h3 {
            font-size: 16px;
            font-weight: 600;
            color: #F9FAFB;
            margin-bottom: 16px;
        }
        
        .side-stat {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }
        
        .side-stat:last-child { border-bottom: none; }
        .side-stat-label { color: #9CA3AF; font-size: 13px; }
        .side-stat-value { color: #F9FAFB; font-weight: 600; font-size: 14px; }
        
        /* 响应式 */
        @media (max-width: 1024px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .dashboard-grid { grid-template-columns: 1fr; }
        }
        
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: 1fr; }
            .main-content { padding: 80px 16px 16px; }
            .top-nav { padding: 0 16px; }
            .nav-center { display: none; }
        }
        
        /* 内容区显示隐藏 */
        .content-section { display: none; }
        .content-section.active { display: block; }
    </style>
</head>
<body>
    <div id="alert" class="alert"></div>
    
    <!-- 顶部导航栏 -->
    <div class="top-nav">
        <div style="display:flex;align-items:center;">
            <div class="nav-brand">MoonYa Admin</div>
            <div class="nav-center">
                <button class="nav-item menu-item active" data-section="home" onclick="switchSection('home')">🏠 首页</button>
                <button class="nav-item menu-item" data-section="splashPages" onclick="switchSection('splashPages')">📱 启动页</button>
                <button class="nav-item menu-item" data-section="users" onclick="switchSection('users')">👥 用户</button>
                <button class="nav-item menu-item" data-section="personality" onclick="switchSection('personality')">🎭 人格</button>
                <button class="nav-item menu-item" data-section="toolSettings" onclick="switchSection('toolSettings')">🔧 工具</button>
                <button class="nav-item menu-item" data-section="updates" onclick="switchSection('updates')">📢 版本</button>
                <button class="nav-item menu-item" data-section="mobileUpdates" onclick="switchSection('mobileUpdates')">📲 移动端</button>
                <button class="nav-item menu-item" data-section="hotTopics" onclick="switchSection('hotTopics')">🔥 热点</button>
                <button class="nav-item menu-item" data-section="webpages" onclick="switchSection('webpages')">🌐 网页</button>
                <button class="nav-item menu-item" data-section="community" onclick="switchSection('community')">💬 社区</button>
                <button class="nav-item menu-item" data-section="email" onclick="switchSection('email')">📧 邮件</button>
            </div>
        </div>
        <div class="nav-right">
            <div class="admin-info" id="adminInfo"></div>
            <button class="logout-btn" onclick="logout()">退出</button>
        </div>
    </div>
    
    <!-- 主内容区 -->
    <div class="main-content">
        <!-- 首页内容 -->
        <div id="homeSection" class="content-section active">
            <h1 class="page-title">Dashboard</h1>
            <div class="dashboard-grid">
                <div class="dashboard-left">
                    <div class="stats-grid" style="grid-template-columns: 1fr 1fr; margin-bottom: 0;">
                        <div class="stat-card" style="background: linear-gradient(135deg, #10B981 0%, #059669 100%); border: none;">
                            <h3 style="color: rgba(255,255,255,0.8);">总用户数</h3>
                            <div class="number" id="totalUsers" style="color: white; font-size: 40px;">0</div>
                            <div style="margin-top: 8px; font-size: 13px; color: rgba(255,255,255,0.7);">系统注册用户总数</div>
                        </div>
                        <div>
                            <div class="stat-card" style="margin-bottom: 20px; background: linear-gradient(135deg, rgba(102,126,234,0.25), rgba(118,75,162,0.25));">
                                <h3>活跃用户</h3>
                                <div class="number active" id="activeUsers">0</div>
                            </div>
                            <div class="stat-card" style="background: linear-gradient(135deg, rgba(239,68,68,0.15), rgba(220,38,38,0.15));">
                                <h3>封禁用户</h3>
                                <div class="number banned" id="bannedUsers">0</div>
                            </div>
                        </div>
                    </div>
                    <div class="chart-card">
                        <h3>用户状态分布</h3>
                        <div class="chart-container">
                            <canvas id="userStatusChart" width="400" height="200"></canvas>
                        </div>
                    </div>
                </div>
                <div class="dashboard-right">
                    <div class="side-card">
                        <h3>系统概览</h3>
                        <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px;">
                            <div style="width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, #667eea, #764ba2); display: flex; align-items: center; justify-content: center; font-size: 18px;">👤</div>
                            <div>
                                <div style="font-weight: 500; color: #F9FAFB;" id="adminInfoDisplay">Admin</div>
                                <div style="font-size: 12px; color: #9CA3AF;">管理员</div>
                            </div>
                        </div>
                        <div class="side-stat">
                            <span class="side-stat-label">总网页数</span>
                            <span class="side-stat-value" id="totalWebpagesSide">0</span>
                        </div>
                        <div class="side-stat">
                            <span class="side-stat-label">总用户数</span>
                            <span class="side-stat-value" id="totalUsersSide">0</span>
                        </div>
                    </div>
                    <div class="side-card">
                        <h3>快捷导航</h3>
                        <div style="display: flex; flex-direction: column; gap: 8px;">
                            <button class="search-btn" style="width: 100%;" onclick="switchSection('users')">用户管理</button>
                            <button class="search-btn" style="width: 100%; background: linear-gradient(135deg, #10B981, #059669);" onclick="switchSection('community')">社区管理</button>
                            <button class="search-btn" style="width: 100%; background: linear-gradient(135deg, #F59E0B, #D97706);" onclick="switchSection('email')">邮件发送</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
'''

# 找到原始文件中从 <!-- 启动页管理内容 --> 到 </body> 之前的所有内容
# 我们需要保留所有原始 section 的内容和模态框、script 等

# 提取从第一个 content-section 之后的内容
sections_start = original.find('<!-- 启动页管理内容 -->')
if sections_start == -1:
    print("Could not find sections start")
    exit(1)

# 保留从启动页管理到文件末尾的所有内容
rest_content = original[sections_start:]

# 但我们需要在第一个 <script> 之前插入新的 homeSection 结束标签 </div>
# 实际上原始文件中 homeSection 在启动页管理之前已经结束了
# 所以我们只需要把 rest_content 拼接在后面

new_content += rest_content

# 写回文件
with open('admin/dashboard.php', 'w', encoding='utf-8') as f:
    f.write(new_content)

print("Dashboard redesigned successfully!")
