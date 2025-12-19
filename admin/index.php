<?php
session_start();
require_once __DIR__ . '/../config.php';

$config = getConfig();

// Проверка авторизации
function isAuthenticated() {
    return isset($_SESSION['mymailer_authenticated']) && $_SESSION['mymailer_authenticated'] === true;
}

// Обработка входа
if (isset($_POST['login'])) {
    $password = $_POST['password'] ?? '';
    if ($password === $config['admin_password']) {
        $_SESSION['mymailer_authenticated'] = true;
        header('Location: index.php');
        exit;
    } else {
        $login_error = 'Неверный пароль';
    }
}

// Обработка выхода
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

// Создание новой кампании
if (isset($_POST['create_campaign']) && isAuthenticated()) {
    $name = trim($_POST['campaign_name'] ?? '');
    $subject = trim($_POST['campaign_subject'] ?? '');
    
    if (!empty($name)) {
        $uuid = createCampaign($name, $subject);
        header('Location: campaign.php?id=' . $uuid);
        exit;
    }
}

// Удаление кампании
if (isset($_POST['delete_campaign']) && isAuthenticated()) {
    $uuid = $_POST['campaign_uuid'] ?? '';
    if (!empty($uuid)) {
        deleteCampaign($uuid);
        header('Location: index.php?deleted=1');
        exit;
    }
}

// Архивация/Разархивация кампании
if (isset($_POST['toggle_archive']) && isAuthenticated()) {
    $uuid = $_POST['campaign_uuid'] ?? '';
    $status = $_POST['new_status'] ?? 'archived';
    if (!empty($uuid)) {
        $campaign_data = getCampaign($uuid);
        if ($campaign_data) {
            $campaign_data['status'] = $status;
            saveCampaign($uuid, $campaign_data);
            header('Location: index.php?archived=1');
            exit;
        }
    }
}

$all_campaigns = getCampaigns();
$campaigns = array_filter($all_campaigns, fn($c) => ($c['status'] ?? '') !== 'archived');
$archived_campaigns = array_filter($all_campaigns, fn($c) => ($c['status'] ?? '') === 'archived');
$unsubscribed_count = count(getGlobalUnsubscribed());
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MyMailer - Управление рассылками</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.3);
            --shadow-md: 0 4px 20px rgba(0, 0, 0, 0.4);
            --shadow-lg: 0 8px 40px rgba(0, 0, 0, 0.5);
            --shadow-glow: 0 0 40px var(--accent-glow);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Outfit', sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
            line-height: 1.6;
        }

        /* Background Pattern */
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: 
                radial-gradient(ellipse 80% 50% at 50% -20%, rgba(99, 102, 241, 0.15), transparent),
                radial-gradient(ellipse 60% 40% at 100% 100%, rgba(139, 92, 246, 0.1), transparent);
            pointer-events: none;
            z-index: 0;
        }

        .app {
            position: relative;
            z-index: 1;
            min-height: 100vh;
        }

        /* Login Page */
        .login-wrapper {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-xl);
            padding: 48px;
            width: 100%;
            max-width: 420px;
            box-shadow: var(--shadow-lg);
        }

        .login-logo {
            text-align: center;
            margin-bottom: 40px;
        }

        .login-logo-icon {
            width: 64px;
            height: 64px;
            background: var(--accent-gradient);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            margin: 0 auto 16px;
        }

        .login-logo h1 {
            font-size: 32px;
            font-weight: 700;
            background: var(--accent-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 8px;
        }

        .login-logo p {
            color: var(--text-secondary);
            font-size: 14px;
        }

        /* Form Elements */
        .form-group {
            margin-bottom: 24px;
        }

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

        .form-control::placeholder {
            color: var(--text-muted);
        }

        textarea.form-control {
            min-height: 120px;
            resize: vertical;
            font-family: 'JetBrains Mono', monospace;
            font-size: 13px;
        }

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
            box-shadow: var(--shadow-sm);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-glow), var(--shadow-md);
        }

        .btn-secondary {
            background: var(--bg-tertiary);
            color: var(--text-primary);
            border: 1px solid var(--border-color);
        }

        .btn-secondary:hover {
            background: var(--bg-card);
            border-color: var(--accent-primary);
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background: #dc2626;
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-success:hover {
            background: #16a34a;
        }

        .btn-sm {
            padding: 10px 18px;
            font-size: 13px;
        }

        .btn-icon {
            padding: 10px;
            min-width: 40px;
        }

        .btn-full {
            width: 100%;
        }

        /* Alert Messages */
        .alert {
            padding: 16px 20px;
            border-radius: var(--radius-md);
            margin-bottom: 24px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #fca5a5;
        }

        .alert-success {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.3);
            color: #86efac;
        }

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

        .header-nav a:hover,
        .header-nav a.active {
            color: var(--text-primary);
            background: var(--bg-tertiary);
        }

        .header-nav a.active {
            background: var(--accent-primary);
            color: white;
        }

        /* Tabs */
        .index-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 32px;
            background: var(--bg-card);
            padding: 6px;
            border-radius: var(--radius-md);
            border: 1px solid var(--border-color);
            width: fit-content;
        }

        .index-tab {
            padding: 10px 20px;
            font-size: 14px;
            font-weight: 600;
            color: var(--text-secondary);
            background: none;
            border: none;
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .index-tab.active {
            background: var(--bg-tertiary);
            color: var(--text-primary);
            box-shadow: var(--shadow-sm);
        }

        .tab-pane {
            display: none;
        }

        .tab-pane.active {
            display: block;
        }

        /* Main Content */
        .main {
            padding: 40px;
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }

        .stat-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 24px;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--accent-gradient);
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .stat-card:hover::before {
            opacity: 1;
        }

        .stat-icon {
            font-size: 24px;
            margin-bottom: 16px;
        }

        .stat-value {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 4px;
            font-family: 'JetBrains Mono', monospace;
        }

        .stat-label {
            font-size: 13px;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Section */
        .section {
            margin-bottom: 40px;
        }

        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }

        .section-title {
            font-size: 20px;
            font-weight: 600;
        }

        /* Campaign Cards */
        .campaigns-grid {
            display: grid;
            gap: 16px;
        }

        .campaign-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 24px;
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 20px;
            align-items: center;
            transition: all 0.2s ease;
        }

        .campaign-card:hover {
            border-color: var(--accent-primary);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .campaign-info h3 {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .campaign-meta {
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }

        .campaign-meta span {
            font-size: 13px;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .campaign-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-draft {
            background: rgba(100, 116, 139, 0.2);
            color: #94a3b8;
        }

        .status-sending {
            background: rgba(59, 130, 246, 0.2);
            color: #60a5fa;
        }

        .status-completed {
            background: rgba(34, 197, 94, 0.2);
            color: #86efac;
        }

        .status-paused {
            background: rgba(245, 158, 11, 0.2);
            color: #fcd34d;
        }

        .campaign-actions {
            display: flex;
            gap: 8px;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: var(--bg-card);
            border: 2px dashed var(--border-color);
            border-radius: var(--radius-lg);
        }

        .empty-state-icon {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .empty-state h3 {
            font-size: 20px;
            margin-bottom: 8px;
            color: var(--text-secondary);
        }

        .empty-state p {
            color: var(--text-muted);
            margin-bottom: 24px;
        }

        /* Modal */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(4px);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }

        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .modal {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-xl);
            padding: 32px;
            width: 100%;
            max-width: 480px;
            transform: translateY(20px);
            transition: transform 0.3s ease;
        }

        .modal-overlay.active .modal {
            transform: translateY(0);
        }

        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }

        .modal-header h2 {
            font-size: 20px;
            font-weight: 600;
        }

        .modal-close {
            background: none;
            border: none;
            color: var(--text-secondary);
            font-size: 24px;
            cursor: pointer;
            padding: 4px;
            line-height: 1;
        }

        .modal-close:hover {
            color: var(--text-primary);
        }

        .modal-footer {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 24px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .header {
                padding: 16px 20px;
                flex-direction: column;
                gap: 16px;
            }

            .main {
                padding: 20px;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .campaign-card {
                grid-template-columns: 1fr;
            }

            .campaign-actions {
                width: 100%;
            }

            .campaign-actions .btn {
                flex: 1;
            }
        }
    </style>
</head>
<body>
    <div class="app">
        <?php if (!isAuthenticated()): ?>
        <!-- Login Page -->
        <div class="login-wrapper">
            <div class="login-card">
                <div class="login-logo">
                    <div class="login-logo-icon">📧</div>
                    <h1>MyMailer</h1>
                    <p>Система управления рассылками</p>
                </div>
                
                <?php if (isset($login_error)): ?>
                    <div class="alert alert-error">
                        <span>⚠️</span>
                        <?= htmlspecialchars($login_error) ?>
                    </div>
                <?php endif; ?>
                
                <form method="post">
                    <div class="form-group">
                        <label for="password">Пароль</label>
                        <input type="password" id="password" name="password" class="form-control" placeholder="Введите пароль" required autofocus>
                    </div>
                    <button type="submit" name="login" class="btn btn-primary btn-full">
                        Войти
                    </button>
                </form>
            </div>
        </div>
        
        <?php else: ?>
        <!-- Dashboard -->
        <header class="header">
            <div class="header-logo">
                <div class="header-logo-icon">📧</div>
                <h1>MyMailer</h1>
                <span class="version">v2.1</span>
            </div>
            <nav class="header-nav">
                <a href="index.php" class="active">Кампании</a>
                <a href="settings.php">Настройки</a>
                <a href="?logout=1">Выйти</a>
            </nav>
        </header>
        
        <main class="main">
            <?php if (isset($_GET['deleted'])): ?>
                <div class="alert alert-success">
                    <span>✅</span>
                    Кампания успешно удалена
                </div>
            <?php endif; ?>
            <?php if (isset($_GET['archived'])): ?>
                <div class="alert alert-info">
                    <span>📦</span>
                    Статус кампании обновлен
                </div>
            <?php endif; ?>
            
            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">📊</div>
                    <div class="stat-value"><?= count($all_campaigns) ?></div>
                    <div class="stat-label">Всего кампаний</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">✅</div>
                    <div class="stat-value"><?= count(array_filter($all_campaigns, fn($c) => ($c['status'] ?? '') === 'completed')) ?></div>
                    <div class="stat-label">Завершено</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">🚫</div>
                    <div class="stat-value"><?= $unsubscribed_count ?></div>
                    <div class="stat-label">Отписавшихся</div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="index-tabs">
                <button class="index-tab active" onclick="switchTab('active-pane', this)">Активные (<?= count($campaigns) ?>)</button>
                <button class="index-tab" onclick="switchTab('archived-pane', this)">Архив (<?= count($archived_campaigns) ?>)</button>
            </div>
            
            <!-- Active Campaigns -->
            <div id="active-pane" class="tab-pane active">
                <section class="section">
                    <div class="section-header">
                        <h2 class="section-title">Активные рассылки</h2>
                        <button class="btn btn-primary" onclick="openModal('createModal')">
                            <span>+</span> Новая рассылка
                        </button>
                    </div>
                    
                    <?php if (empty($campaigns)): ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">📭</div>
                            <h3>Нет активных рассылок</h3>
                            <p>Создайте новую кампанию или проверьте архив</p>
                        </div>
                    <?php else: ?>
                        <div class="campaigns-grid">
                            <?php foreach ($campaigns as $campaign): ?>
                                <div class="campaign-card">
                                    <div class="campaign-info">
                                        <h3><?= htmlspecialchars($campaign['name'] ?? 'Без названия') ?></h3>
                                        <div class="campaign-meta">
                                            <span class="campaign-status status-<?= $campaign['status'] ?? 'draft' ?>">
                                                <?php
                                                $statuses = [
                                                    'draft' => '📝 Черновик',
                                                    'sending' => '🚀 Отправка',
                                                    'completed' => '✅ Завершена',
                                                    'paused' => '⏸️ Пауза'
                                                ];
                                                echo $statuses[$campaign['status'] ?? 'draft'] ?? '📝 Черновик';
                                                ?>
                                            </span>
                                            <span>📧 <?= htmlspecialchars($campaign['subject'] ?? 'Без темы') ?></span>
                                            <span>📅 <?= date('d.m.Y H:i', strtotime($campaign['created_at'] ?? 'now')) ?></span>
                                        </div>
                                    </div>
                                    <div class="campaign-actions">
                                        <a href="campaign.php?id=<?= $campaign['uuid'] ?>" class="btn btn-secondary btn-sm">
                                            Открыть
                                        </a>
                                        
                                        <!-- Archive Toggle -->
                                        <form method="post" style="display: inline;">
                                            <input type="hidden" name="campaign_uuid" value="<?= $campaign['uuid'] ?>">
                                            <input type="hidden" name="new_status" value="archived">
                                            <button type="submit" name="toggle_archive" class="btn btn-secondary btn-sm" title="В архив">
                                                📦 Архивировать
                                            </button>
                                        </form>

                                        <?php if (($campaign['status'] ?? 'draft') === 'draft'): ?>
                                            <form method="post" style="display: inline;" onsubmit="return confirm('Удалить кампанию?');">
                                                <input type="hidden" name="campaign_uuid" value="<?= $campaign['uuid'] ?>">
                                                <button type="submit" name="delete_campaign" class="btn btn-danger btn-sm btn-icon" title="Удалить">
                                                    🗑️
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            </div>

            <!-- Archived Campaigns -->
            <div id="archived-pane" class="tab-pane">
                <section class="section">
                    <div class="section-header">
                        <h2 class="section-title">Архивные рассылки</h2>
                    </div>
                    
                    <?php if (empty($archived_campaigns)): ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">📦</div>
                            <h3>Архив пуст</h3>
                            <p>Здесь будут храниться завершенные или ненужные рассылки</p>
                        </div>
                    <?php else: ?>
                        <div class="campaigns-grid">
                            <?php foreach ($archived_campaigns as $campaign): ?>
                                <div class="campaign-card" style="opacity: 0.7;">
                                    <div class="campaign-info">
                                        <h3 style="text-decoration: line-through; color: var(--text-muted);"><?= htmlspecialchars($campaign['name'] ?? 'Без названия') ?></h3>
                                        <div class="campaign-meta">
                                            <span class="campaign-status" style="background: var(--bg-tertiary); color: var(--text-muted);">📦 В архиве</span>
                                            <span>📅 <?= date('d.m.Y H:i', strtotime($campaign['created_at'] ?? 'now')) ?></span>
                                        </div>
                                    </div>
                                    <div class="campaign-actions">
                                        <form method="post" style="display: inline;">
                                            <input type="hidden" name="campaign_uuid" value="<?= $campaign['uuid'] ?>">
                                            <input type="hidden" name="new_status" value="draft">
                                            <button type="submit" name="toggle_archive" class="btn btn-secondary btn-sm" title="Вернуть из архива">
                                                🔄 Восстановить
                                            </button>
                                        </form>
                                        <form method="post" style="display: inline;" onsubmit="return confirm('Удалить кампанию навсегда?');">
                                            <input type="hidden" name="campaign_uuid" value="<?= $campaign['uuid'] ?>">
                                            <button type="submit" name="delete_campaign" class="btn btn-danger btn-sm btn-icon">
                                                🗑️
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
        </main>

        <footer style="text-align: center; padding: 24px 40px; border-top: 1px solid var(--border-color); color: var(--text-muted); font-size: 13px;">
            Powered by <a href="https://github.com/lowdigital/mymailer" target="_blank" style="color: var(--accent-primary); text-decoration: none;">MyMailer</a>
        </footer>
        
        <!-- Create Campaign Modal -->
        <div class="modal-overlay" id="createModal">
            <div class="modal">
                <div class="modal-header">
                    <h2>Новая рассылка</h2>
                    <button class="modal-close" onclick="closeModal('createModal')">&times;</button>
                </div>
                <form method="post">
                    <div class="form-group">
                        <label for="campaign_name">Название кампании</label>
                        <input type="text" id="campaign_name" name="campaign_name" class="form-control" placeholder="Например: Новогодняя акция" required>
                    </div>
                    <div class="form-group">
                        <label for="campaign_subject">Тема письма</label>
                        <input type="text" id="campaign_subject" name="campaign_subject" class="form-control" placeholder="Тема, которую увидят получатели">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeModal('createModal')">Отмена</button>
                        <button type="submit" name="create_campaign" class="btn btn-primary">Создать</button>
                    </div>
                </form>
            </div>
        </div>
        
        <script>
            function switchTab(paneId, tabEl) {
                // Hide all panes
                document.querySelectorAll('.tab-pane').forEach(pane => pane.classList.remove('active'));
                // Deactivate all tabs
                document.querySelectorAll('.index-tab').forEach(tab => tab.classList.remove('active'));
                
                // Show selected pane
                document.getElementById(paneId).classList.add('active');
                // Activate selected tab
                tabEl.classList.add('active');
            }

            function openModal(id) {
                document.getElementById(id).classList.add('active');
                document.body.style.overflow = 'hidden';
            }
            
            function closeModal(id) {
                document.getElementById(id).classList.remove('active');
                document.body.style.overflow = '';
            }
            
            // Close modal on overlay click
            document.querySelectorAll('.modal-overlay').forEach(overlay => {
                overlay.addEventListener('click', (e) => {
                    if (e.target === overlay) {
                        overlay.classList.remove('active');
                        document.body.style.overflow = '';
                    }
                });
            });
            
            // Close modal on Escape key
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    document.querySelectorAll('.modal-overlay.active').forEach(modal => {
                        modal.classList.remove('active');
                    });
                    document.body.style.overflow = '';
                }
            });
        </script>
        <?php endif; ?>
    </div>
</body>
</html>

