<?php
session_start();
require_once __DIR__ . '/../config.php';

$config = getConfig();

// Auth check
if (!isset($_SESSION['mymailer_authenticated']) || $_SESSION['mymailer_authenticated'] !== true) {
    header('Location: index.php');
    exit;
}

// Get campaign ID
$uuid = $_GET['id'] ?? '';
if (empty($uuid)) {
    header('Location: index.php');
    exit;
}

// AJAX handlers
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['ajax_action'];
    
    $campaign = getCampaign($uuid);
    if (!$campaign) {
        echo json_encode(['success' => false, 'error' => 'Campaign not found']);
        exit;
    }
    
    switch ($action) {
        case 'save_settings':
            $data = [
                'name' => trim($_POST['campaign_name'] ?? ''),
                'subject' => trim($_POST['campaign_subject'] ?? ''),
                'status' => $campaign['status'],
                'counter' => $campaign['counter'],
                'created_at' => $campaign['created_at']
            ];
            saveCampaign($uuid, $data);
            echo json_encode(['success' => true, 'message' => 'Настройки сохранены']);
            exit;
            
        case 'save_recipients':
            $data = [
                'name' => $campaign['name'],
                'subject' => $campaign['subject'],
                'status' => $campaign['status'],
                'counter' => $campaign['counter'],
                'created_at' => $campaign['created_at'],
                'recipients_text' => $_POST['recipients'] ?? ''
            ];
            saveCampaign($uuid, $data);
            $new_campaign = getCampaign($uuid);
            echo json_encode([
                'success' => true, 
                'message' => 'Список получателей сохранён',
                'count' => count($new_campaign['recipients'])
            ]);
            exit;
            
        case 'save_template':
            $data = [
                'name' => $campaign['name'],
                'subject' => $campaign['subject'],
                'status' => $campaign['status'],
                'counter' => $campaign['counter'],
                'created_at' => $campaign['created_at'],
                'template' => $_POST['template'] ?? ''
            ];
            saveCampaign($uuid, $data);
            echo json_encode(['success' => true, 'message' => 'Шаблон сохранён']);
            exit;
            
        case 'upload_attachment':
            if (!isset($_FILES['attachment']) || $_FILES['attachment']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['success' => false, 'error' => 'Ошибка загрузки файла']);
                exit;
            }
            
            $file = $_FILES['attachment'];
            $attachments_dir = CAMPAIGNS_DIR . '/' . $uuid . '/attachments';
            if (!is_dir($attachments_dir)) {
                mkdir($attachments_dir, 0755, true);
            }
            
            // Get original name and extension
            $original_name = $file['name'];
            $extension = pathinfo($original_name, PATHINFO_EXTENSION);
            $stored_name = generateSafeFilename($extension);
            $target_path = $attachments_dir . '/' . $stored_name;
            
            if (move_uploaded_file($file['tmp_name'], $target_path)) {
                // Update options.json with attachment info
                $options_file = CAMPAIGNS_DIR . '/' . $uuid . '/options.json';
                $options = json_decode(file_get_contents($options_file), true);
                $options['attachments'] = $options['attachments'] ?? [];
                $options['attachments'][] = [
                    'original_name' => $original_name,
                    'stored_name' => $stored_name
                ];
                file_put_contents($options_file, json_encode($options, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
                
                echo json_encode([
                    'success' => true, 
                    'message' => 'Файл загружен',
                    'file' => [
                        'name' => $original_name,
                        'stored_name' => $stored_name,
                        'size' => formatFileSize(filesize($target_path))
                    ]
                ]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Ошибка при сохранении файла']);
            }
            exit;
            
        case 'delete_attachment':
            $stored_name = basename($_POST['stored_name'] ?? '');
            $filepath = CAMPAIGNS_DIR . '/' . $uuid . '/attachments/' . $stored_name;
            
            if (file_exists($filepath)) {
                unlink($filepath);
                
                // Update options.json
                $options_file = CAMPAIGNS_DIR . '/' . $uuid . '/options.json';
                $options = json_decode(file_get_contents($options_file), true);
                $options['attachments'] = array_filter($options['attachments'] ?? [], function($att) use ($stored_name) {
                    return $att['stored_name'] !== $stored_name;
                });
                $options['attachments'] = array_values($options['attachments']);
                file_put_contents($options_file, json_encode($options, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
                
                echo json_encode(['success' => true, 'message' => 'Файл удалён']);
            } else {
                echo json_encode(['success' => false, 'error' => 'Файл не найден']);
            }
            exit;
            
        case 'reset_counter':
            $options_file = CAMPAIGNS_DIR . '/' . $uuid . '/options.json';
            $options = json_decode(file_get_contents($options_file), true);
            $options['counter'] = 0;
            $options['status'] = 'draft';
            file_put_contents($options_file, json_encode($options, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
            echo json_encode(['success' => true, 'message' => 'Счётчик сброшен']);
            exit;
    }
    
    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    exit;
}

// Load campaign
$campaign = getCampaign($uuid);
if (!$campaign) {
    header('Location: index.php');
    exit;
}

// Read logs first (needed for stats)
$send_log = file_exists(CAMPAIGNS_DIR . '/' . $uuid . '/log/send.txt') 
    ? file_get_contents(CAMPAIGNS_DIR . '/' . $uuid . '/log/send.txt') : '';
$error_log = file_exists(CAMPAIGNS_DIR . '/' . $uuid . '/log/error.txt') 
    ? file_get_contents(CAMPAIGNS_DIR . '/' . $uuid . '/log/error.txt') : '';
$unsubscribe_log = file_exists(CAMPAIGNS_DIR . '/' . $uuid . '/log/unsubscribe.txt') 
    ? file_get_contents(CAMPAIGNS_DIR . '/' . $uuid . '/log/unsubscribe.txt') : '';

$send_log_lines = array_filter(explode("\n", trim($send_log)));
$error_log_lines = array_filter(explode("\n", trim($error_log)));
$unsubscribe_log_lines = array_filter(explode("\n", trim($unsubscribe_log)));

// Get statistics
$recipients_count = count($campaign['recipients'] ?? []);
$unsubscribed = getGlobalUnsubscribed();
$active_recipients = array_diff($campaign['recipients'] ?? [], $unsubscribed);
$active_count = count($active_recipients);
$counter = $campaign['counter'] ?? 0;
// Считаем отправленных из лога (не вычитаем отписавшихся!)
$sent_count = count($send_log_lines);

// Get tracking stats
$tracking_stats = getCampaignStats($uuid);

// Read opens and clicks logs
$opens_log = file_exists(CAMPAIGNS_DIR . '/' . $uuid . '/log/opens.txt') 
    ? file_get_contents(CAMPAIGNS_DIR . '/' . $uuid . '/log/opens.txt') : '';
$clicks_log = file_exists(CAMPAIGNS_DIR . '/' . $uuid . '/log/clicks.txt') 
    ? file_get_contents(CAMPAIGNS_DIR . '/' . $uuid . '/log/clicks.txt') : '';

$opens_log_lines = array_filter(explode("\n", trim($opens_log)));
$clicks_log_lines = array_filter(explode("\n", trim($clicks_log)));

// Unsubscribe URL
$unsubscribe_url = getUnsubscribeUrl();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($campaign['name']) ?> - MyMailer</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- CodeMirror -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/theme/material-darker.min.css">
    <style>
        :root {
            --bg-primary: #0a0a0f;
            --bg-secondary: #12121a;
            --bg-tertiary: #1a1a26;
            --bg-card: #16161f;
            --accent-primary: #6366f1;
            --accent-secondary: #8b5cf6;
            --accent-gradient: linear-gradient(135deg, #6366f1 0%, #8b5cf6 50%, #a855f7 100%);
            --accent-glow: rgba(99, 102, 241, 0.3);
            --text-primary: #f1f5f9;
            --text-secondary: #94a3b8;
            --text-muted: #64748b;
            --border-color: rgba(255, 255, 255, 0.06);
            --success: #22c55e;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 16px;
            --radius-xl: 24px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Outfit', sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
            line-height: 1.6;
        }

        body::before {
            content: '';
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: 
                radial-gradient(ellipse 80% 50% at 50% -20%, rgba(99, 102, 241, 0.15), transparent),
                radial-gradient(ellipse 60% 40% at 100% 100%, rgba(139, 92, 246, 0.1), transparent);
            pointer-events: none;
            z-index: 0;
        }

        .app { position: relative; z-index: 1; min-height: 100vh; }

        /* Header */
        .header {
            padding: 20px 40px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid var(--border-color);
            background: rgba(10, 10, 15, 0.8);
            backdrop-filter: blur(20px);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .header-logo {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .header-logo-icon {
            width: 36px;
            height: 36px;
            background: var(--accent-gradient);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .header-logo h1 {
            font-size: 24px;
            font-weight: 700;
            background: var(--accent-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .header-logo .version {
            font-size: 11px;
            color: var(--text-muted);
            background: var(--bg-tertiary);
            padding: 4px 8px;
            border-radius: 6px;
            font-family: 'JetBrains Mono', monospace;
        }

        .header-nav {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .header-nav a {
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            padding: 10px 16px;
            border-radius: var(--radius-sm);
            transition: all 0.2s ease;
        }

        .header-nav a:hover { color: var(--text-primary); background: var(--bg-tertiary); }

        /* Main Content */
        .main {
            padding: 40px;
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Breadcrumb */
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 24px;
            font-size: 14px;
        }

        .breadcrumb a { color: var(--text-secondary); text-decoration: none; }
        .breadcrumb a:hover { color: var(--accent-primary); }
        .breadcrumb span { color: var(--text-muted); }

        /* Page Header */
        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 32px;
            flex-wrap: wrap;
            gap: 20px;
        }

        .page-title h1 { font-size: 28px; font-weight: 700; margin-bottom: 8px; }
        .page-title p { color: var(--text-secondary); font-size: 14px; }
        .page-actions { display: flex; gap: 12px; }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 14px 28px;
            border: none;
            border-radius: var(--radius-md);
            font-family: inherit;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--accent-gradient);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 0 40px var(--accent-glow);
        }

        .btn-secondary {
            background: var(--bg-tertiary);
            color: var(--text-primary);
            border: 1px solid var(--border-color);
        }

        .btn-secondary:hover { border-color: var(--accent-primary); }
        .btn-danger { background: var(--danger); color: white; }
        .btn-danger:hover { background: #dc2626; }
        .btn-success { background: var(--success); color: white; }
        .btn-success:hover { background: #16a34a; }
        .btn-sm { padding: 10px 18px; font-size: 13px; }
        .btn-icon { padding: 10px; min-width: 40px; }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
            margin-bottom: 32px;
        }

        .stat-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            background: var(--bg-tertiary);
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }

        .stat-info { flex: 1; }
        .stat-value {
            font-size: 24px;
            font-weight: 700;
            font-family: 'JetBrains Mono', monospace;
        }

        .stat-label {
            font-size: 12px;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Tabs */
        .tabs {
            display: flex;
            gap: 4px;
            margin-bottom: 24px;
            background: var(--bg-card);
            padding: 4px;
            border-radius: var(--radius-md);
            border: 1px solid var(--border-color);
            overflow-x: auto;
        }

        .tab {
            padding: 12px 24px;
            font-size: 14px;
            font-weight: 500;
            color: var(--text-secondary);
            background: none;
            border: none;
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: all 0.2s ease;
            white-space: nowrap;
        }

        .tab:hover { color: var(--text-primary); }
        .tab.active { background: var(--accent-primary); color: white; }

        .tab-content { display: none; }
        .tab-content.active { display: block; }

        /* Cards */
        .card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 24px;
            margin-bottom: 24px;
        }

        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }

        .card-title { font-size: 18px; font-weight: 600; }

        /* Form Elements */
        .form-group { margin-bottom: 20px; }

        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            color: var(--text-secondary);
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-control {
            width: 100%;
            padding: 14px 18px;
            background: var(--bg-tertiary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            color: var(--text-primary);
            font-family: inherit;
            font-size: 15px;
            transition: all 0.2s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--accent-primary);
            box-shadow: 0 0 0 3px var(--accent-glow);
        }

        textarea.form-control {
            min-height: 200px;
            resize: vertical;
            font-family: 'JetBrains Mono', monospace;
            font-size: 13px;
        }

        .form-hint {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 8px;
        }

        /* File Upload */
        .upload-zone {
            border: 2px dashed var(--border-color);
            border-radius: var(--radius-lg);
            padding: 40px;
            text-align: center;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .upload-zone:hover,
        .upload-zone.dragover {
            border-color: var(--accent-primary);
            background: rgba(99, 102, 241, 0.05);
        }

        .upload-zone-icon { font-size: 48px; margin-bottom: 16px; opacity: 0.5; }
        .upload-zone-text { color: var(--text-secondary); margin-bottom: 8px; }
        .upload-zone-hint { font-size: 12px; color: var(--text-muted); }
        .upload-zone input[type="file"] { display: none; }

        /* File List */
        .file-list { display: flex; flex-direction: column; gap: 8px; margin-top: 20px; }

        .file-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 16px;
            background: var(--bg-tertiary);
            border-radius: var(--radius-sm);
        }

        .file-info { display: flex; align-items: center; gap: 12px; }
        .file-icon { font-size: 20px; }
        .file-name { font-size: 14px; word-break: break-all; }
        .file-size { font-size: 12px; color: var(--text-muted); font-family: 'JetBrains Mono', monospace; }

        /* Preview */
        .preview-frame {
            width: 100%;
            min-height: 500px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            background: white;
        }

        /* CodeMirror */
        .CodeMirror {
            height: 400px;
            border-radius: var(--radius-md);
            font-family: 'JetBrains Mono', monospace;
            font-size: 13px;
        }

        /* Log */
        .log-content {
            background: var(--bg-tertiary);
            border-radius: var(--radius-md);
            padding: 16px;
            max-height: 300px;
            overflow-y: auto;
            font-family: 'JetBrains Mono', monospace;
            font-size: 12px;
        }

        .log-line { padding: 4px 0; border-bottom: 1px solid var(--border-color); }
        .log-line:last-child { border-bottom: none; }
        .log-empty { color: var(--text-muted); text-align: center; padding: 20px; }

        /* Status Badge */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-draft { background: rgba(100, 116, 139, 0.2); color: #94a3b8; }
        .status-sending { background: rgba(59, 130, 246, 0.2); color: #60a5fa; }
        .status-completed { background: rgba(34, 197, 94, 0.2); color: #86efac; }

        /* Info Box */
        .info-box {
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid rgba(59, 130, 246, 0.3);
            border-radius: var(--radius-md);
            padding: 16px;
            margin-bottom: 20px;
        }

        .info-box code {
            background: var(--bg-tertiary);
            padding: 2px 8px;
            border-radius: 4px;
            font-family: 'JetBrains Mono', monospace;
            font-size: 13px;
        }

        /* Toast */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 10000;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .toast {
            padding: 16px 24px;
            border-radius: var(--radius-md);
            color: white;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideIn 0.3s ease;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
        }

        .toast-success { background: var(--success); }
        .toast-error { background: var(--danger); }
        .toast-info { background: var(--info); }

        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        @keyframes slideOut {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(100%); opacity: 0; }
        }

        /* Loading */
        .loading-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s;
        }

        .loading-overlay.active { opacity: 1; visibility: visible; }

        .spinner {
            width: 40px;
            height: 40px;
            border: 3px solid var(--bg-tertiary);
            border-top-color: var(--accent-primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin { to { transform: rotate(360deg); } }

        /* Responsive */
        @media (max-width: 768px) {
            .header { padding: 16px 20px; flex-direction: column; gap: 16px; }
            .main { padding: 20px; }
            .page-header { flex-direction: column; align-items: flex-start; }
            .tabs { flex-wrap: nowrap; }
        }
    </style>
</head>
<body>
    <div class="app">
        <header class="header">
            <div class="header-logo">
                <div class="header-logo-icon">📧</div>
                <h1>MyMailer</h1>
                <span class="version">v2.1</span>
            </div>
            <nav class="header-nav">
                <a href="index.php">Кампании</a>
                <a href="settings.php">Настройки</a>
                <a href="index.php?logout=1">Выйти</a>
            </nav>
        </header>

        <main class="main">
            <div class="breadcrumb">
                <a href="index.php">Кампании</a>
                <span>→</span>
                <span><?= htmlspecialchars($campaign['name']) ?></span>
            </div>

            <div class="page-header">
                <div class="page-title">
                    <h1><?= htmlspecialchars($campaign['name']) ?></h1>
                    <p>
                        <span class="status-badge status-<?= $campaign['status'] ?>">
                            <?php
                            $statuses = [
                                'draft' => '📝 Черновик',
                                'sending' => '🚀 Отправка',
                                'completed' => '✅ Завершена'
                            ];
                            echo $statuses[$campaign['status']] ?? '📝 Черновик';
                            ?>
                        </span>
                    </p>
                </div>
                <div class="page-actions">
                    <?php if ($campaign['status'] === 'draft' || $campaign['status'] === 'paused'): ?>
                        <a href="send.php?id=<?= $uuid ?>" class="btn btn-success" onclick="return saveAndGo(event, this.href)">🚀 Запустить рассылку</a>
                    <?php elseif ($campaign['status'] === 'sending'): ?>
                        <a href="send.php?id=<?= $uuid ?>" class="btn btn-primary" onclick="return saveAndGo(event, this.href)">📊 Продолжить</a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">📋</div>
                    <div class="stat-info">
                        <div class="stat-value" id="stat-total"><?= $recipients_count ?></div>
                        <div class="stat-label">Всего адресов</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">✅</div>
                    <div class="stat-info">
                        <div class="stat-value"><?= $active_count ?></div>
                        <div class="stat-label">Активных</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">📤</div>
                    <div class="stat-info">
                        <div class="stat-value"><?= $sent_count ?></div>
                        <div class="stat-label">Отправлено</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">📎</div>
                    <div class="stat-info">
                        <div class="stat-value" id="stat-attachments"><?= count($campaign['attachments']) ?></div>
                        <div class="stat-label">Вложений</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">👁️</div>
                    <div class="stat-info">
                        <div class="stat-value"><?= $tracking_stats['opens']['unique'] ?></div>
                        <div class="stat-label">Открытий</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">🔗</div>
                    <div class="stat-info">
                        <div class="stat-value"><?= $tracking_stats['clicks']['unique'] ?></div>
                        <div class="stat-label">Кликов</div>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <button class="tab active" data-tab="settings">⚙️ Настройки</button>
                <button class="tab" data-tab="recipients">📋 Получатели</button>
                <button class="tab" data-tab="template">✉️ Шаблон</button>
                <button class="tab" data-tab="attachments">📎 Вложения</button>
                <button class="tab" data-tab="stats">📈 Статистика</button>
                <button class="tab" data-tab="logs">📊 Логи</button>
            </div>

            <!-- Settings Tab -->
            <div class="tab-content active" id="settings">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Настройки кампании</h3>
                    </div>
                    <form id="settingsForm">
                        <div class="form-group">
                            <label>Название кампании</label>
                            <input type="text" name="campaign_name" class="form-control" value="<?= htmlspecialchars($campaign['name']) ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Тема письма</label>
                            <input type="text" name="campaign_subject" class="form-control" value="<?= htmlspecialchars($campaign['subject']) ?>">
                        </div>
                        <button type="submit" class="btn btn-primary">💾 Сохранить</button>
                    </form>
                </div>

                <?php if ($campaign['status'] === 'completed' || $counter > 0): ?>
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Сброс рассылки</h3>
                    </div>
                    <p style="color: var(--text-secondary); margin-bottom: 16px;">
                        Сбросьте счётчик, чтобы начать рассылку заново.
                    </p>
                    <button class="btn btn-danger" onclick="resetCounter()">🔄 Сбросить</button>
                </div>
                <?php endif; ?>
            </div>

            <!-- Recipients Tab -->
            <div class="tab-content" id="recipients">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Список получателей</h3>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('csv-import-input').click()">
                                📥 Импорт CSV
                            </button>
                            <input type="file" id="csv-import-input" style="display: none;" accept=".csv,.txt" onchange="importCSV(this)">
                            
                            <label style="font-size: 13px; color: var(--text-secondary); cursor: pointer; display: flex; align-items: center; gap: 6px; text-transform: none;">
                                <input type="checkbox" id="advanced-toggle" <?= ($campaign['is_advanced'] ?? false) ? 'checked' : '' ?> onchange="toggleAdvancedMode()">
                                Расширенный режим (CSV)
                            </label>
                        </div>
                    </div>
                    
                    <div id="simple-hint" class="info-box" style="display: <?= ($campaign['is_advanced'] ?? false) ? 'none' : 'block' ?>;">
                        Простой список email, каждый с новой строки.
                    </div>
                    
                    <div id="advanced-hint" class="info-box" style="display: <?= ($campaign['is_advanced'] ?? false) ? 'block' : 'none' ?>;">
                        <strong>CSV формат:</strong> первая строка — заголовки через запятую, включая <code>email</code>.<br>
                        Пример: <code>email,name,price</code><br>
                        В письме используйте: <code>{name}</code>, <code>{price}</code>.
                    </div>

                    <form id="recipientsForm">
                        <div class="form-group">
                            <textarea name="recipients" id="recipients-input" class="form-control" style="min-height: 300px;"><?= htmlspecialchars($campaign['recipients_raw'] ?? implode("\n", $campaign['recipients'])) ?></textarea>
                            <p class="form-hint">
                                Всего: <span id="recipients-count"><?= count($campaign['recipients']) ?></span> строк
                            </p>
                        </div>
                        <button type="submit" class="btn btn-primary">💾 Сохранить</button>
                    </form>
                </div>
            </div>

            <!-- Template Tab -->
            <div class="tab-content" id="template">
                <div class="info-box">
                    <strong>💡 Подсказка:</strong> Используйте <code>[LINK_UNSUBSCRIBE]</code> для ссылки отписки.<br>
                    <small style="color: var(--text-muted);">📊 Отслеживание открытий и кликов добавляется автоматически при отправке.</small>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">HTML шаблон письма</h3>
                        <button type="button" class="btn btn-primary btn-sm" onclick="saveTemplate()">💾 Сохранить</button>
                    </div>
                    <textarea id="templateEditor"><?= htmlspecialchars($campaign['template']) ?></textarea>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Предпросмотр</h3>
                    </div>
                    <iframe class="preview-frame" id="previewFrame"></iframe>
                </div>
            </div>

            <!-- Attachments Tab -->
            <div class="tab-content" id="attachments">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Загрузить вложение</h3>
                    </div>
                    <div class="upload-zone" id="uploadZone">
                        <div class="upload-zone-icon">📁</div>
                        <div class="upload-zone-text">Перетащите файл сюда или нажмите для выбора</div>
                        <div class="upload-zone-hint">Любые типы файлов</div>
                        <input type="file" id="fileInput">
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Прикреплённые файлы</h3>
                    </div>
                    <div class="file-list" id="fileList">
                        <?php if (empty($campaign['attachments'])): ?>
                            <p class="log-empty">Нет прикреплённых файлов</p>
                        <?php else: ?>
                            <?php foreach ($campaign['attachments'] as $attachment): ?>
                                <div class="file-item" data-stored="<?= htmlspecialchars($attachment['stored_name']) ?>">
                                    <div class="file-info">
                                        <span class="file-icon">📄</span>
                                        <div>
                                            <div class="file-name"><?= htmlspecialchars($attachment['name']) ?></div>
                                            <div class="file-size"><?= formatFileSize($attachment['size']) ?></div>
                                        </div>
                                    </div>
                                    <button class="btn btn-danger btn-sm btn-icon" onclick="deleteAttachment('<?= htmlspecialchars($attachment['stored_name']) ?>', this)">🗑️</button>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Stats Tab -->
            <div class="tab-content" id="stats">
                <?php 
                $open_rate = $sent_count > 0 ? round(($tracking_stats['opens']['unique'] / $sent_count) * 100, 1) : 0;
                $click_rate = $sent_count > 0 ? round(($tracking_stats['clicks']['unique'] / $sent_count) * 100, 1) : 0;
                ?>
                
                <!-- Summary Stats -->
                <div class="stats-grid" style="margin-bottom: 24px;">
                    <div class="stat-card">
                        <div class="stat-icon">👁️</div>
                        <div class="stat-info">
                            <div class="stat-value"><?= $open_rate ?>%</div>
                            <div class="stat-label">Open Rate</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">🔗</div>
                        <div class="stat-info">
                            <div class="stat-value"><?= $click_rate ?>%</div>
                            <div class="stat-label">Click Rate</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">📬</div>
                        <div class="stat-info">
                            <div class="stat-value"><?= $tracking_stats['opens']['total'] ?></div>
                            <div class="stat-label">Всего открытий</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">👆</div>
                        <div class="stat-info">
                            <div class="stat-value"><?= $tracking_stats['clicks']['total'] ?></div>
                            <div class="stat-label">Всего кликов</div>
                        </div>
                    </div>
                </div>

                <!-- Top Links -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">🔗 Популярные ссылки</h3>
                    </div>
                    <?php if (empty($tracking_stats['clicks']['urls'])): ?>
                        <p class="log-empty">Кликов по ссылкам пока нет</p>
                    <?php else: ?>
                        <div class="file-list">
                            <?php foreach (array_slice($tracking_stats['clicks']['urls'], 0, 10, true) as $url => $clicks): ?>
                                <div class="file-item">
                                    <div class="file-info" style="flex: 1; min-width: 0;">
                                        <span class="file-icon">🔗</span>
                                        <div style="min-width: 0; flex: 1;">
                                            <div class="file-name" style="word-break: break-all;"><?= htmlspecialchars($url) ?></div>
                                        </div>
                                    </div>
                                    <div style="background: var(--accent-primary); color: white; padding: 6px 14px; border-radius: 20px; font-weight: 600; font-size: 13px;">
                                        <?= $clicks ?> клик<?= $clicks > 1 ? ($clicks < 5 ? 'а' : 'ов') : '' ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Opens Log -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">👁️ Лог открытий (<?= count($opens_log_lines) ?>)</h3>
                    </div>
                    <div class="log-content">
                        <?php if (empty($opens_log_lines)): ?>
                            <div class="log-empty">Открытий пока нет</div>
                        <?php else: ?>
                            <?php foreach (array_reverse(array_slice($opens_log_lines, -100)) as $line): ?>
                                <div class="log-line"><?= htmlspecialchars($line) ?></div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Clicks Log -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">🔗 Лог переходов (<?= count($clicks_log_lines) ?>)</h3>
                    </div>
                    <div class="log-content">
                        <?php if (empty($clicks_log_lines)): ?>
                            <div class="log-empty">Переходов пока нет</div>
                        <?php else: ?>
                            <?php foreach (array_reverse(array_slice($clicks_log_lines, -100)) as $line): ?>
                                <div class="log-line"><?= htmlspecialchars($line) ?></div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Logs Tab -->
            <div class="tab-content" id="logs">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">📤 Лог отправки (<?= count($send_log_lines) ?>)</h3>
                    </div>
                    <div class="log-content">
                        <?php if (empty($send_log_lines)): ?>
                            <div class="log-empty">Лог пуст</div>
                        <?php else: ?>
                            <?php foreach (array_reverse($send_log_lines) as $line): ?>
                                <div class="log-line"><?= htmlspecialchars($line) ?></div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">⚠️ Лог ошибок (<?= count($error_log_lines) ?>)</h3>
                    </div>
                    <div class="log-content">
                        <?php if (empty($error_log_lines)): ?>
                            <div class="log-empty">Ошибок нет</div>
                        <?php else: ?>
                            <?php foreach (array_reverse($error_log_lines) as $line): ?>
                                <div class="log-line" style="color: #fca5a5;"><?= htmlspecialchars($line) ?></div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">🚫 Отписавшиеся (<?= count($unsubscribe_log_lines) ?>)</h3>
                    </div>
                    <div class="log-content">
                        <?php if (empty($unsubscribe_log_lines)): ?>
                            <div class="log-empty">Отписок нет</div>
                        <?php else: ?>
                            <?php foreach (array_reverse($unsubscribe_log_lines) as $line): ?>
                                <div class="log-line" style="color: #fcd34d;"><?= htmlspecialchars($line) ?></div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>

        <footer style="text-align: center; padding: 24px 40px; border-top: 1px solid var(--border-color); color: var(--text-muted); font-size: 13px;">
            Powered by <a href="https://github.com/lowdigital/mymailer" target="_blank" style="color: var(--accent-primary); text-decoration: none;">MyMailer</a>
        </footer>
    </div>

    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>

    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner"></div>
    </div>

    <!-- CodeMirror -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/xml/xml.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/htmlmixed/htmlmixed.min.js"></script>

    <script>
        const uuid = '<?= $uuid ?>';
        const unsubscribeUrl = '<?= $unsubscribe_url ?>';
        
        // Toast notifications
        function showToast(message, type = 'success') {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;
            toast.innerHTML = `<span>${type === 'success' ? '✅' : type === 'error' ? '❌' : 'ℹ️'}</span> ${message}`;
            container.appendChild(toast);
            
            setTimeout(() => {
                toast.style.animation = 'slideOut 0.3s ease forwards';
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }

        // Loading
        function showLoading() { document.getElementById('loadingOverlay').classList.add('active'); }
        function hideLoading() { document.getElementById('loadingOverlay').classList.remove('active'); }

        // Change tracking
        let changes = { settings: false, recipients: false, template: false };

        // Tab switching
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', () => {
                const activeTab = document.querySelector('.tab.active');
                if (activeTab) {
                    const prevTabName = activeTab.dataset.tab;
                    autoSave(prevTabName);
                }

                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                tab.classList.add('active');
                document.getElementById(tab.dataset.tab).classList.add('active');
                if (tab.dataset.tab === 'template') {
                    editor.refresh();
                }
            });
        });

        function autoSave(tabName) {
            if (tabName === 'settings' && changes.settings) return saveSettings(true);
            else if (tabName === 'recipients' && changes.recipients) return saveRecipients(true);
            else if (tabName === 'template' && changes.template) return saveTemplate(true);
            return Promise.resolve();
        }

        async function saveAndGo(e, url) {
            e.preventDefault();
            const activeTab = document.querySelector('.tab.active').dataset.tab;
            
            if (changes[activeTab]) {
                showLoading();
                await autoSave(activeTab);
            }
            
            window.location.href = url;
            return false;
        }

        // Recipients counter
        function updateRecipientsCount() {
            const textarea = document.getElementById('recipients-input');
            const isAdvanced = document.getElementById('advanced-toggle').checked;
            const lines = textarea.value.split('\n').filter(line => line.trim() !== '');
            
            let count = lines.length;
            if (isAdvanced && count > 0 && lines[0].includes('email')) {
                count = Math.max(0, count - 1);
            }
            
            document.getElementById('recipients-count').textContent = count;
        }

        // Form change listeners
        document.getElementById('settingsForm').addEventListener('input', () => changes.settings = true);
        document.getElementById('recipientsForm').addEventListener('input', () => {
            changes.recipients = true;
            updateRecipientsCount();
        });

        // Save functions
        function saveSettings(silent = false) {
            const form = document.getElementById('settingsForm');
            const formData = new FormData(form);
            formData.append('ajax_action', 'save_settings');
            
            return fetch('campaign.php?id=' + uuid, { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        changes.settings = false;
                        if (!silent) showToast(data.message);
                    }
                });
        }

        function saveRecipients(silent = false) {
            const form = document.getElementById('recipientsForm');
            const formData = new FormData(form);
            formData.append('ajax_action', 'save_recipients');
            
            return fetch('campaign.php?id=' + uuid, { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        changes.recipients = false;
                        document.getElementById('stat-total').textContent = data.count;
                        document.getElementById('recipients-count').textContent = data.count;
                        if (!silent) showToast(data.message);
                    }
                });
        }

        function saveTemplate(silent = false) {
            const formData = new FormData();
            formData.append('ajax_action', 'save_template');
            formData.append('template', editor.getValue());
            
            return fetch('campaign.php?id=' + uuid, { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        changes.template = false;
                        if (!silent) showToast(data.message);
                    }
                });
        }

        // Toggle advanced mode hints
        function toggleAdvancedMode() {
            const isAdvanced = document.getElementById('advanced-toggle').checked;
            document.getElementById('simple-hint').style.display = isAdvanced ? 'none' : 'block';
            document.getElementById('advanced-hint').style.display = isAdvanced ? 'block' : 'none';
            changes.recipients = true;
            
            const textarea = document.getElementById('recipients-input');
            if (isAdvanced && !textarea.value.startsWith('email')) {
                textarea.value = 'email,name,price\nmatrix@test.com,Matrix,1000\n' + textarea.value;
            }
            updateRecipientsCount();
        }

        // Import CSV file
        function importCSV(input) {
            if (!input.files || !input.files[0]) return;
            
            const file = input.files[0];
            const reader = new FileReader();
            
            reader.onload = function(e) {
                const content = e.target.result;
                const textarea = document.getElementById('recipients-input');
                textarea.value = content;
                changes.recipients = true;
                
                // Автоматически включаем расширенный режим, если есть запятые
                if (content.includes(',') && content.includes('email')) {
                    document.getElementById('advanced-toggle').checked = true;
                    toggleAdvancedMode();
                } else {
                    updateRecipientsCount();
                }
                
                showToast('Файл импортирован');
                input.value = ''; // Сброс инпута
            };
            
            reader.readAsText(file);
        }

        // CodeMirror editor
        const editor = CodeMirror.fromTextArea(document.getElementById('templateEditor'), {
            mode: 'htmlmixed',
            theme: 'material-darker',
            lineNumbers: true,
            lineWrapping: true,
            autoCloseTags: true,
            matchBrackets: true
        });

        // Live preview
        function updatePreview() {
            const content = editor.getValue();
            const preview = document.getElementById('previewFrame');
            const processedContent = content.replace(/\[LINK_UNSUBSCRIBE\]/g, unsubscribeUrl + '?email=example@email.com');
            preview.srcdoc = processedContent;
        }

        editor.on('change', () => {
            changes.template = true;
            updatePreview();
        });
        
        updatePreview(); // Initial preview

        // Forms event listeners for explicit save
        document.getElementById('settingsForm').addEventListener('submit', function(e) {
            e.preventDefault();
            saveSettings();
        });

        document.getElementById('recipientsForm').addEventListener('submit', function(e) {
            e.preventDefault();
            saveRecipients();
        });

        // Reset counter
        function resetCounter() {
            if (!confirm('Вы уверены? Рассылка будет начата заново.')) return;
            
            const formData = new FormData();
            formData.append('ajax_action', 'reset_counter');
            
            fetch('campaign.php?id=' + uuid, { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        showToast(data.message);
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showToast(data.error, 'error');
                    }
                })
                .catch(() => showToast('Ошибка сети', 'error'));
        }

        // File upload drag & drop
        const uploadZone = document.getElementById('uploadZone');
        const fileInput = document.getElementById('fileInput');

        uploadZone.addEventListener('click', () => fileInput.click());

        uploadZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            uploadZone.classList.add('dragover');
        });

        uploadZone.addEventListener('dragleave', () => {
            uploadZone.classList.remove('dragover');
        });

        uploadZone.addEventListener('drop', (e) => {
            e.preventDefault();
            uploadZone.classList.remove('dragover');
            if (e.dataTransfer.files.length) {
                uploadFile(e.dataTransfer.files[0]);
            }
        });

        fileInput.addEventListener('change', () => {
            if (fileInput.files.length) {
                uploadFile(fileInput.files[0]);
            }
        });

        function uploadFile(file) {
            showLoading();
            const formData = new FormData();
            formData.append('ajax_action', 'upload_attachment');
            formData.append('attachment', file);
            
            fetch('campaign.php?id=' + uuid, { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    hideLoading();
                    if (data.success) {
                        showToast(data.message);
                        addFileToList(data.file);
                        updateAttachmentCount(1);
                    } else {
                        showToast(data.error, 'error');
                    }
                })
                .catch(() => {
                    hideLoading();
                    showToast('Ошибка загрузки', 'error');
                });
        }

        function addFileToList(file) {
            const fileList = document.getElementById('fileList');
            const empty = fileList.querySelector('.log-empty');
            if (empty) empty.remove();
            
            const item = document.createElement('div');
            item.className = 'file-item';
            item.dataset.stored = file.stored_name;
            item.innerHTML = `
                <div class="file-info">
                    <span class="file-icon">📄</span>
                    <div>
                        <div class="file-name">${file.name}</div>
                        <div class="file-size">${file.size}</div>
                    </div>
                </div>
                <button class="btn btn-danger btn-sm btn-icon" onclick="deleteAttachment('${file.stored_name}', this)">🗑️</button>
            `;
            fileList.appendChild(item);
        }

        function deleteAttachment(storedName, btn) {
            if (!confirm('Удалить файл?')) return;
            
            const formData = new FormData();
            formData.append('ajax_action', 'delete_attachment');
            formData.append('stored_name', storedName);
            
            fetch('campaign.php?id=' + uuid, { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        showToast(data.message);
                        btn.closest('.file-item').remove();
                        updateAttachmentCount(-1);
                    } else {
                        showToast(data.error, 'error');
                    }
                })
                .catch(() => showToast('Ошибка сети', 'error'));
        }

        function updateAttachmentCount(delta) {
            const el = document.getElementById('stat-attachments');
            el.textContent = parseInt(el.textContent) + delta;
        }

        // Keyboard shortcut for save
        document.addEventListener('keydown', (e) => {
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                const activeTab = document.querySelector('.tab.active').dataset.tab;
                if (activeTab === 'template') saveTemplate();
                else if (activeTab === 'settings') document.getElementById('settingsForm').requestSubmit();
                else if (activeTab === 'recipients') document.getElementById('recipientsForm').requestSubmit();
            }
        });
    </script>
</body>
</html>
