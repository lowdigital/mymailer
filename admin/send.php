<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../PHPMailer/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer/SMTP.php';
require_once __DIR__ . '/../PHPMailer/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

$config = getConfig();

// Проверка авторизации
if (!isset($_SESSION['mymailer_authenticated']) || $_SESSION['mymailer_authenticated'] !== true) {
    header('Location: index.php');
    exit;
}

// Получаем ID кампании
$uuid = $_GET['id'] ?? '';
if (empty($uuid)) {
    header('Location: index.php');
    exit;
}

// Загружаем кампанию
$campaign = getCampaign($uuid);
if (!$campaign) {
    header('Location: index.php');
    exit;
}

// Настройки отправки
$users_per_step = $config['sending']['users_per_step'] ?? 10;
$timeout_seconds = $config['sending']['timeout_seconds'] ?? 5;

// Функция отправки email
function sendEmail($to, $subject, $body, $attachments = []) {
    global $config;
    
    $smtp = $config['smtp'];
    
    // Валидируем email адрес
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'Invalid email address'];
    }
    
    $to = filter_var(trim($to), FILTER_SANITIZE_EMAIL);
    
    $mail = new PHPMailer(true);
    
    try {
        // Настройки SMTP
        $mail->isSMTP();
        $mail->Host = $smtp['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $smtp['username'];
        $mail->Password = $smtp['password'];
        $mail->Port = $smtp['port'];
        
        // Настройки шифрования
        if ($smtp['port'] == 465) {
            $mail->SMTPSecure = 'ssl';
        } elseif ($smtp['port'] == 587) {
            $mail->SMTPSecure = 'tls';
        }
        
        // Отладка отключена
        $mail->SMTPDebug = 0;
        
        // Отправитель
        $mail->setFrom($smtp['username'], $smtp['from_name']);
        
        // Получатель
        $mail->addAddress($to);
        
        // Настройки письма
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = $subject;
        $mail->Body = $body;
        
        // Добавляем вложения
        foreach ($attachments as $attachment) {
            if (file_exists($attachment['path'])) {
                $mail->addAttachment($attachment['path'], $attachment['name']);
            }
        }
        
        // Ссылка отписки
        $unsubscribe_url = getUnsubscribeUrl();
        $unsubscribe_link = $unsubscribe_url . '?email=' . urlencode($to);
        
        // RFC 2369 - стандартный заголовок отписки
        $mail->addCustomHeader('List-Unsubscribe', '<' . $unsubscribe_link . '>');
        $mail->addCustomHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        $mail->addCustomHeader('Precedence', 'bulk');
        $mail->addCustomHeader('X-Auto-Response-Suppress', 'All');
        
        // Отправляем
        if ($mail->send()) {
            return ['success' => true];
        } else {
            return ['success' => false, 'error' => $mail->ErrorInfo];
        }
        
    } catch (PHPMailerException $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Получаем список получателей и исключаем отписавшихся
$recipients = $campaign['recipients'] ?? [];
$unsubscribed = getGlobalUnsubscribed();
$active_recipients = array_values(array_diff($recipients, $unsubscribed));
$total_active = count($active_recipients);

// Получаем текущий счётчик
$options_file = CAMPAIGNS_DIR . '/' . $uuid . '/options.json';
$options = json_decode(file_get_contents($options_file), true);
$counter = $options['counter'] ?? 0;

// Проверяем статус рассылки
$is_completed = ($counter == -1);
$is_running = !$is_completed && $total_active > 0;

// Переменные для отслеживания отправки
$sent_in_this_batch = 0;
$current_email = '';
$send_status = '';
$errors = [];

// Логика рассылки
if ($is_running) {
    // Обновляем статус на "sending"
    $options['status'] = 'sending';
    file_put_contents($options_file, json_encode($options, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    
    $start_index = max(0, $counter);
    $end_index = min($start_index + $users_per_step, $total_active);
    
    $template = $campaign['template'] ?? '';
    $subject = $campaign['subject'] ?? 'Без темы';
    $attachments = $campaign['attachments'] ?? [];
    $unsubscribe_url = getUnsubscribeUrl();
    
    for ($i = $start_index; $i < $end_index; $i++) {
        if (isset($active_recipients[$i])) {
            $recipient_data = $active_recipients[$i];
            
            // Определяем email и данные для замены
            if (is_array($recipient_data)) {
                $email = $recipient_data['email'] ?? '';
                $vars = $recipient_data;
            } else {
                $email = $recipient_data;
                $vars = ['email' => $email];
            }
            
            if (empty($email)) continue;
            
            $current_email = $email;
            
            // Подготавливаем персонализированное письмо
            $personalized_body = $template;
            
            // Заменяем [LINK_UNSUBSCRIBE]
            $personalized_body = str_replace(
                '[LINK_UNSUBSCRIBE]',
                $unsubscribe_url . '?email=' . urlencode($email),
                $personalized_body
            );
            
            // Заменяем динамические переменные {key}
            foreach ($vars as $key => $value) {
                $personalized_body = str_replace('{' . $key . '}', $value, $personalized_body);
            }
            
            // Добавляем отслеживание открытий и кликов
            $personalized_body = processTemplateForTracking($personalized_body, $uuid, $email);
            
            // Отправляем письмо
            $result = sendEmail($email, $subject, $personalized_body, $attachments);
            
            if ($result['success']) {
                $send_status = 'Отправлено успешно';
                
                // Логируем успешную отправку
                $log_entry = date('Y-m-d H:i:s') . " - Отправлено: " . $email . "\n";
                file_put_contents(CAMPAIGNS_DIR . '/' . $uuid . '/log/send.txt', $log_entry, FILE_APPEND | LOCK_EX);
            } else {
                $send_status = 'Ошибка отправки';
                $errors[] = "Ошибка отправки на $email: " . ($result['error'] ?? 'Unknown error');
                
                // Логируем ошибку
                $log_entry = date('Y-m-d H:i:s') . " - Ошибка (" . $email . "): " . ($result['error'] ?? 'Unknown') . "\n";
                file_put_contents(CAMPAIGNS_DIR . '/' . $uuid . '/log/error.txt', $log_entry, FILE_APPEND | LOCK_EX);
            }
            
            $sent_in_this_batch++;
            
            // Задержка между отправками
            usleep(300000); // 0.3 секунды
        }
    }
    
    // Обновляем счётчик
    $new_counter = $start_index + $sent_in_this_batch;
    if ($new_counter >= $total_active) {
        // Рассылка завершена
        $options['counter'] = -1;
        $options['status'] = 'completed';
        $is_completed = true;
    } else {
        $options['counter'] = $new_counter;
    }
    
    file_put_contents($options_file, json_encode($options, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    $counter = $options['counter'];
}

// Рассчитываем прогресс
$progress = 0;
if ($total_active > 0) {
    if ($is_completed) {
        $progress = 100;
    } else {
        $progress = ($counter / $total_active) * 100;
    }
}

$sent_count = $counter == -1 ? $total_active : max(0, $counter);
$remaining_count = $total_active - $sent_count;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Рассылка - <?= htmlspecialchars($campaign['name']) ?> - MyMailer</title>
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
            --radius-md: 12px;
            --radius-lg: 16px;
            --radius-xl: 24px;
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
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
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

        .container {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 600px;
        }

        .card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-xl);
            padding: 40px;
            text-align: center;
            box-shadow: var(--shadow-lg);
        }

        .icon {
            font-size: 72px;
            margin-bottom: 24px;
            line-height: 1;
        }

        h1 {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 12px;
        }

        .subtitle {
            color: var(--text-secondary);
            font-size: 16px;
            margin-bottom: 32px;
        }

        /* Progress Bar */
        .progress-container {
            margin: 32px 0;
        }

        .progress {
            background: var(--bg-tertiary);
            border-radius: 20px;
            height: 24px;
            overflow: hidden;
            position: relative;
            border: 1px solid var(--border-color);
        }

        .progress-bar {
            height: 100%;
            background: var(--accent-gradient);
            border-radius: 20px;
            transition: width 0.5s ease;
            position: relative;
            min-width: 40px;
        }

        .progress-text {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: white;
            font-weight: 700;
            font-size: 13px;
            text-shadow: 0 1px 3px rgba(0, 0, 0, 0.5);
            font-family: 'JetBrains Mono', monospace;
        }

        /* Stats Grid */
        .stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin: 32px 0;
        }

        .stat {
            background: var(--bg-tertiary);
            border-radius: var(--radius-md);
            padding: 20px 16px;
            border: 1px solid var(--border-color);
        }

        .stat-value {
            font-size: 28px;
            font-weight: 700;
            font-family: 'JetBrains Mono', monospace;
            margin-bottom: 4px;
            background: var(--accent-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .stat-label {
            font-size: 11px;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Current Email */
        .current-email {
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid rgba(59, 130, 246, 0.3);
            border-radius: var(--radius-md);
            padding: 16px;
            margin: 24px 0;
            font-family: 'JetBrains Mono', monospace;
            font-size: 14px;
            word-break: break-all;
        }

        /* Status */
        .status {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 20px;
            border-radius: 30px;
            font-size: 14px;
            font-weight: 600;
            margin: 16px 0;
        }

        .status-success {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.3);
            color: #86efac;
        }

        .status-error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #fca5a5;
        }

        .status-info {
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid rgba(59, 130, 246, 0.3);
            color: #93c5fd;
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
            margin: 8px;
        }

        .btn-primary {
            background: var(--accent-gradient);
            color: white;
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
            border-color: var(--accent-primary);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        /* Spinner */
        .spinner {
            width: 40px;
            height: 40px;
            border: 3px solid var(--bg-tertiary);
            border-top-color: var(--accent-primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 24px auto;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Error Log */
        .error-log {
            background: var(--bg-tertiary);
            border-radius: var(--radius-md);
            padding: 16px;
            margin-top: 24px;
            text-align: left;
            max-height: 200px;
            overflow-y: auto;
            font-family: 'JetBrains Mono', monospace;
            font-size: 12px;
        }

        .error-log-title {
            color: var(--danger);
            margin-bottom: 12px;
            font-weight: 600;
        }

        .error-line {
            color: #fca5a5;
            padding: 4px 0;
            border-bottom: 1px solid var(--border-color);
        }

        .error-line:last-child { border-bottom: none; }

        /* Reload Timer */
        .reload-bar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--bg-tertiary);
            z-index: 1000;
        }

        .reload-bar-progress {
            height: 100%;
            background: var(--accent-gradient);
            width: 0%;
            transition: width 0.1s linear;
        }

        .reload-timer {
            position: fixed;
            top: 16px;
            right: 16px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 30px;
            padding: 10px 20px;
            font-size: 13px;
            font-weight: 600;
            z-index: 1001;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .pulse {
            width: 8px;
            height: 8px;
            background: var(--success);
            border-radius: 50%;
            animation: pulse 1.5s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.5; transform: scale(1.2); }
        }

        /* Responsive */
        @media (max-width: 600px) {
            .card { padding: 24px; }
            .stats { grid-template-columns: 1fr; }
            h1 { font-size: 24px; }
        }
    </style>
</head>
<body>
    <?php if ($is_running && !$is_completed): ?>
    <!-- Reload Progress -->
    <div class="reload-bar">
        <div class="reload-bar-progress" id="reloadProgress"></div>
    </div>
    <div class="reload-timer">
        <div class="pulse"></div>
        <span>Обновление через <span id="countdown"><?= $timeout_seconds ?></span>с</span>
    </div>
    <?php endif; ?>

    <div class="container">
        <div class="card">
            <?php if ($is_completed): ?>
                <!-- Completed State -->
                <div class="icon">✅</div>
                <h1>Рассылка завершена!</h1>
                <p class="subtitle"><?= htmlspecialchars($campaign['name']) ?></p>
                
            <?php elseif ($is_running): ?>
                <!-- Running State -->
                <div class="icon">📧</div>
                <h1>Рассылка выполняется</h1>
                <p class="subtitle">Не закрывайте эту страницу</p>
                
                <div class="spinner"></div>
                
                <?php if ($current_email): ?>
                    <div class="current-email">
                        📤 <?= htmlspecialchars($current_email) ?>
                    </div>
                    <div class="status <?= strpos($send_status, 'успешно') !== false ? 'status-success' : 'status-error' ?>">
                        <?= strpos($send_status, 'успешно') !== false ? '✅' : '⚠️' ?>
                        <?= htmlspecialchars($send_status) ?>
                    </div>
                <?php endif; ?>
                
            <?php else: ?>
                <!-- No Recipients State -->
                <div class="icon">📭</div>
                <h1>Нет получателей</h1>
                <p class="subtitle">Добавьте email адреса для отправки</p>
            <?php endif; ?>

            <!-- Progress Bar -->
            <div class="progress-container">
                <div class="progress">
                    <div class="progress-bar" style="width: <?= max(1, $progress) ?>%">
                        <span class="progress-text"><?= number_format($progress, 1) ?>%</span>
                    </div>
                </div>
            </div>

            <!-- Stats -->
            <div class="stats">
                <div class="stat">
                    <div class="stat-value"><?= $total_active ?></div>
                    <div class="stat-label">Всего</div>
                </div>
                <div class="stat">
                    <div class="stat-value"><?= $sent_count ?></div>
                    <div class="stat-label">Отправлено</div>
                </div>
                <div class="stat">
                    <div class="stat-value"><?= max(0, $remaining_count) ?></div>
                    <div class="stat-label">Осталось</div>
                </div>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="error-log">
                    <div class="error-log-title">⚠️ Ошибки в этой порции:</div>
                    <?php foreach ($errors as $error): ?>
                        <div class="error-line"><?= htmlspecialchars($error) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Actions -->
            <div style="margin-top: 32px;">
                <a href="campaign.php?id=<?= $uuid ?>" class="btn btn-secondary">
                    ← Назад к кампании
                </a>
                <?php if ($is_completed): ?>
                    <a href="index.php" class="btn btn-success">
                        🏠 На главную
                    </a>
                <?php endif; ?>
            </div>

            <?php if (!$is_completed && $total_active > 0): ?>
                <div class="status status-info" style="margin-top: 24px;">
                    ⚡ Отправка по <?= $users_per_step ?> писем каждые <?= $timeout_seconds ?> сек
                </div>
            <?php endif; ?>

            <div style="margin-top: 32px; padding-top: 24px; border-top: 1px solid var(--border-color); color: var(--text-muted); font-size: 13px;">
                Powered by <a href="https://github.com/lowdigital/mymailer" target="_blank" style="color: var(--accent-primary); text-decoration: none;">MyMailer</a>
            </div>
        </div>
    </div>

    <?php if (!$is_completed && $total_active > 0): ?>
    <script>
        const timeout = <?= $timeout_seconds ?>;
        let remaining = timeout;
        
        const progressBar = document.getElementById('reloadProgress');
        const countdownEl = document.getElementById('countdown');
        
        const interval = setInterval(() => {
            remaining -= 0.1;
            
            if (remaining <= 0) {
                clearInterval(interval);
                window.location.reload();
                return;
            }
            
            const percent = ((timeout - remaining) / timeout) * 100;
            if (progressBar) progressBar.style.width = percent + '%';
            if (countdownEl) countdownEl.textContent = Math.ceil(remaining);
        }, 100);
    </script>
    <?php endif; ?>
</body>
</html>

