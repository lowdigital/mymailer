<?php
session_start();
require_once __DIR__ . '/../config.php';

$config = getConfig();

// Проверка авторизации
if (!isset($_SESSION['mymailer_authenticated']) || $_SESSION['mymailer_authenticated'] !== true) {
    header('Location: index.php');
    exit;
}

$success_message = '';
$error_message = '';

// Обработка сохранения пароля
if (isset($_POST['save_password'])) {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if ($current_password !== $config['admin_password']) {
        $error_message = 'Неверный текущий пароль';
    } elseif (strlen($new_password) < 4) {
        $error_message = 'Новый пароль должен содержать минимум 4 символа';
    } elseif ($new_password !== $confirm_password) {
        $error_message = 'Пароли не совпадают';
    } else {
        $config['admin_password'] = $new_password;
        saveConfig($config);
        $success_message = 'Пароль успешно изменён';
    }
}

// Обработка сохранения SMTP настроек
if (isset($_POST['save_smtp'])) {
    $config['smtp'] = [
        'host' => trim($_POST['smtp_host'] ?? ''),
        'port' => (int)($_POST['smtp_port'] ?? 465),
        'username' => trim($_POST['smtp_username'] ?? ''),
        'password' => $_POST['smtp_password'] ?? '',
        'from_name' => trim($_POST['smtp_from_name'] ?? 'MyMailer')
    ];
    saveConfig($config);
    $success_message = 'Настройки SMTP сохранены';
}

// Обработка сохранения настроек отправки
if (isset($_POST['save_sending'])) {
    $config['sending'] = [
        'users_per_step' => max(1, (int)($_POST['users_per_step'] ?? 10)),
        'timeout_seconds' => max(1, (int)($_POST['timeout_seconds'] ?? 5))
    ];
    saveConfig($config);
    $success_message = 'Настройки отправки сохранены';
}

// Обработка сохранения глобального списка отписавшихся
if (isset($_POST['save_unsubscribed'])) {
    $emails = $_POST['unsubscribed_emails'] ?? '';
    file_put_contents(UNSUBSCRIBED_FILE, $emails, LOCK_EX);
    $success_message = 'Список отписавшихся сохранён';
}

// Получаем данные
$unsubscribed = getGlobalUnsubscribed();
$unsubscribe_url = getUnsubscribeUrl();
$base_url = getBaseUrl();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Настройки - MyMailer</title>
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
        .header-nav a.active { background: var(--accent-primary); color: white; }

        /* Main Content */
        .main {
            padding: 40px;
            max-width: 900px;
            margin: 0 auto;
        }

        .page-header {
            margin-bottom: 32px;
        }

        .page-header h1 {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .page-header p {
            color: var(--text-secondary);
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
            gap: 12px;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--border-color);
        }

        .card-icon {
            font-size: 24px;
        }

        .card-title {
            font-size: 18px;
            font-weight: 600;
        }

        /* Form Elements */
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }

        .form-group {
            margin-bottom: 20px;
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

        .form-control::placeholder { color: var(--text-muted); }

        .form-control:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }

        textarea.form-control {
            min-height: 150px;
            resize: vertical;
            font-family: 'JetBrains Mono', monospace;
            font-size: 13px;
        }

        .form-hint {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 8px;
        }

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
            padding: 4px 10px;
            border-radius: 4px;
            font-family: 'JetBrains Mono', monospace;
            font-size: 13px;
            display: inline-block;
            margin: 4px 0;
            word-break: break-all;
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

        /* Responsive */
        @media (max-width: 768px) {
            .header { padding: 16px 20px; flex-direction: column; gap: 16px; }
            .main { padding: 20px; }
            .form-row { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="app">
        <header class="header">
            <div class="header-logo">
                <div class="header-logo-icon">📧</div>
                <h1>MyMailer</h1>
                <span class="version">v2.0</span>
            </div>
            <nav class="header-nav">
                <a href="index.php">Кампании</a>
                <a href="settings.php" class="active">Настройки</a>
                <a href="index.php?logout=1">Выйти</a>
            </nav>
        </header>

        <main class="main">
            <div class="page-header">
                <h1>⚙️ Настройки</h1>
                <p>Конфигурация системы рассылки</p>
            </div>

            <?php if ($success_message): ?>
                <div class="alert alert-success">
                    <span>✅</span> <?= htmlspecialchars($success_message) ?>
                </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="alert alert-error">
                    <span>⚠️</span> <?= htmlspecialchars($error_message) ?>
                </div>
            <?php endif; ?>

            <!-- URL Info -->
            <div class="info-box">
                <strong>🔗 Автоматически определённые URL:</strong><br>
                <div style="margin-top: 8px;">
                    <small style="color: var(--text-secondary);">Base URL:</small><br>
                    <code><?= htmlspecialchars($base_url) ?></code>
                </div>
                <div style="margin-top: 8px;">
                    <small style="color: var(--text-secondary);">LINK_UNSUBSCRIBE:</small><br>
                    <code><?= htmlspecialchars($unsubscribe_url) ?></code>
                </div>
            </div>

            <!-- SMTP Settings -->
            <div class="card">
                <div class="card-header">
                    <span class="card-icon">📬</span>
                    <h3 class="card-title">Настройки SMTP</h3>
                </div>
                <form method="post">
                    <div class="form-row">
                        <div class="form-group">
                            <label>SMTP Хост</label>
                            <input type="text" name="smtp_host" class="form-control" value="<?= htmlspecialchars($config['smtp']['host'] ?? '') ?>" placeholder="smtp.example.com">
                        </div>
                        <div class="form-group">
                            <label>Порт</label>
                            <input type="number" name="smtp_port" class="form-control" value="<?= $config['smtp']['port'] ?? 465 ?>" placeholder="465">
                            <p class="form-hint">465 для SSL, 587 для TLS</p>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Email отправителя</label>
                            <input type="email" name="smtp_username" class="form-control" value="<?= htmlspecialchars($config['smtp']['username'] ?? '') ?>" placeholder="no-reply@example.com">
                        </div>
                        <div class="form-group">
                            <label>Пароль</label>
                            <input type="password" name="smtp_password" class="form-control" value="<?= htmlspecialchars($config['smtp']['password'] ?? '') ?>" placeholder="••••••••">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Имя отправителя</label>
                        <input type="text" name="smtp_from_name" class="form-control" value="<?= htmlspecialchars($config['smtp']['from_name'] ?? 'MyMailer') ?>" placeholder="MyMailer">
                    </div>
                    <button type="submit" name="save_smtp" class="btn btn-primary">
                        💾 Сохранить SMTP
                    </button>
                </form>
            </div>

            <!-- Sending Settings -->
            <div class="card">
                <div class="card-header">
                    <span class="card-icon">⚡</span>
                    <h3 class="card-title">Настройки отправки</h3>
                </div>
                <form method="post">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Писем за шаг</label>
                            <input type="number" name="users_per_step" class="form-control" value="<?= $config['sending']['users_per_step'] ?? 10 ?>" min="1" max="100">
                            <p class="form-hint">Количество писем отправляемых за одну итерацию</p>
                        </div>
                        <div class="form-group">
                            <label>Таймаут (секунды)</label>
                            <input type="number" name="timeout_seconds" class="form-control" value="<?= $config['sending']['timeout_seconds'] ?? 5 ?>" min="1" max="60">
                            <p class="form-hint">Время между итерациями отправки</p>
                        </div>
                    </div>
                    <button type="submit" name="save_sending" class="btn btn-primary">
                        💾 Сохранить настройки отправки
                    </button>
                </form>
            </div>

            <!-- Password Change -->
            <div class="card">
                <div class="card-header">
                    <span class="card-icon">🔐</span>
                    <h3 class="card-title">Изменить пароль</h3>
                </div>
                <form method="post">
                    <div class="form-group">
                        <label>Текущий пароль</label>
                        <input type="password" name="current_password" class="form-control" placeholder="••••••••" required>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Новый пароль</label>
                            <input type="password" name="new_password" class="form-control" placeholder="••••••••" required>
                        </div>
                        <div class="form-group">
                            <label>Подтвердите пароль</label>
                            <input type="password" name="confirm_password" class="form-control" placeholder="••••••••" required>
                        </div>
                    </div>
                    <button type="submit" name="save_password" class="btn btn-primary">
                        🔑 Изменить пароль
                    </button>
                </form>
            </div>

            <!-- Global Unsubscribed List -->
            <div class="card">
                <div class="card-header">
                    <span class="card-icon">🚫</span>
                    <h3 class="card-title">Глобальный список отписавшихся</h3>
                </div>
                <p style="color: var(--text-secondary); margin-bottom: 16px;">
                    Эти email адреса исключены из всех рассылок. Новые отписки автоматически добавляются в этот список.
                </p>
                <form method="post">
                    <div class="form-group">
                        <label>Email адреса (каждый с новой строки)</label>
                        <textarea name="unsubscribed_emails" class="form-control" placeholder="user1@example.com&#10;user2@example.com"><?= htmlspecialchars(implode("\n", $unsubscribed)) ?></textarea>
                        <p class="form-hint">Всего отписавшихся: <?= count($unsubscribed) ?></p>
                    </div>
                    <button type="submit" name="save_unsubscribed" class="btn btn-primary">
                        💾 Сохранить список
                    </button>
                </form>
            </div>
        </main>
    </div>
</body>
</html>

