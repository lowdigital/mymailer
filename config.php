<?php
/**
 * MyMailer - Configuration File
 * 
 * This file contains all system settings and helper functions.
 * Settings are stored in JSON file for admin panel editing.
 */

// Path definitions
define('CONFIG_FILE', __DIR__ . '/config.json');
define('CAMPAIGNS_DIR', __DIR__ . '/campaigns');
define('UNSUBSCRIBED_FILE', __DIR__ . '/unsubscribed.txt');

// Create default config if not exists
if (!file_exists(CONFIG_FILE)) {
    $default_config = [
        'admin_password' => 'admin123',
        'smtp' => [
            'host' => 'smtp.example.com',
            'port' => 465,
            'username' => 'no-reply@example.com',
            'password' => '',
            'from_name' => 'MyMailer'
        ],
        'sending' => [
            'users_per_step' => 10,
            'timeout_seconds' => 5
        ]
    ];
    file_put_contents(CONFIG_FILE, json_encode($default_config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// Load config
function getConfig() {
    if (file_exists(CONFIG_FILE)) {
        $content = file_get_contents(CONFIG_FILE);
        return json_decode($content, true) ?: [];
    }
    return [];
}

// Save config
function saveConfig($config) {
    return file_put_contents(CONFIG_FILE, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

// Get base URL
function getBaseUrl() {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script_path = dirname($_SERVER['SCRIPT_NAME']);
    
    // Remove /admin from path if we're in admin
    $base_path = preg_replace('#/admin$#', '', $script_path);
    $base_path = rtrim($base_path, '/');
    
    return $protocol . '://' . $host . $base_path;
}

// Get unsubscribe URL
function getUnsubscribeUrl() {
    return getBaseUrl() . '/unsubscribe.html';
}

// Create campaigns directory if not exists
if (!is_dir(CAMPAIGNS_DIR)) {
    mkdir(CAMPAIGNS_DIR, 0755, true);
}

// Create unsubscribed file if not exists
if (!file_exists(UNSUBSCRIBED_FILE)) {
    file_put_contents(UNSUBSCRIBED_FILE, '');
}

// Generate UUID
function generateUUID() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

// Get all campaigns
function getCampaigns() {
    $campaigns = [];
    if (is_dir(CAMPAIGNS_DIR)) {
        $dirs = array_filter(glob(CAMPAIGNS_DIR . '/*'), 'is_dir');
        foreach ($dirs as $dir) {
            $options_file = $dir . '/options.json';
            if (file_exists($options_file)) {
                $options = json_decode(file_get_contents($options_file), true);
                if ($options) {
                    $options['uuid'] = basename($dir);
                    $options['path'] = $dir;
                    $campaigns[] = $options;
                }
            }
        }
    }
    // Sort by creation date (newest first)
    usort($campaigns, function($a, $b) {
        return strtotime($b['created_at'] ?? '0') - strtotime($a['created_at'] ?? '0');
    });
    return $campaigns;
}

// Get campaign data
function getCampaign($uuid) {
    $dir = CAMPAIGNS_DIR . '/' . $uuid;
    $options_file = $dir . '/options.json';
    
    if (!file_exists($options_file)) {
        return null;
    }
    
    $options = json_decode(file_get_contents($options_file), true);
    if (!$options) {
        return null;
    }
    
    $options['uuid'] = $uuid;
    $options['path'] = $dir;
    
    // Load recipients list
    $list_file = $dir . '/list.txt';
    $options['recipients'] = file_exists($list_file) 
        ? array_filter(array_map('trim', explode("\n", file_get_contents($list_file))))
        : [];
    
    // Load template
    $template_file = $dir . '/template.html';
    $options['template'] = file_exists($template_file) 
        ? file_get_contents($template_file) 
        : '';
    
    // Load attachments from options.json
    $attachments_data = $options['attachments'] ?? [];
    $attachments_dir = $dir . '/attachments';
    $options['attachments'] = [];
    
    if (is_dir($attachments_dir)) {
        foreach ($attachments_data as $att) {
            $filepath = $attachments_dir . '/' . $att['stored_name'];
            if (file_exists($filepath)) {
                $options['attachments'][] = [
                    'name' => $att['original_name'],
                    'stored_name' => $att['stored_name'],
                    'path' => $filepath,
                    'size' => filesize($filepath)
                ];
            }
        }
    }
    
    return $options;
}

// Save campaign data
function saveCampaign($uuid, $data) {
    $dir = CAMPAIGNS_DIR . '/' . $uuid;
    $options_file = $dir . '/options.json';
    
    // Load existing options to preserve attachments
    $existing = [];
    if (file_exists($options_file)) {
        $existing = json_decode(file_get_contents($options_file), true) ?: [];
    }
    
    // Build options
    $options = [
        'name' => $data['name'] ?? $existing['name'] ?? 'Untitled',
        'subject' => $data['subject'] ?? $existing['subject'] ?? '',
        'status' => $data['status'] ?? $existing['status'] ?? 'draft',
        'counter' => $data['counter'] ?? $existing['counter'] ?? 0,
        'created_at' => $data['created_at'] ?? $existing['created_at'] ?? date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
        'attachments' => $data['attachments'] ?? $existing['attachments'] ?? []
    ];
    
    file_put_contents($options_file, json_encode($options, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    
    // Save recipients list
    if (isset($data['recipients_text'])) {
        file_put_contents($dir . '/list.txt', $data['recipients_text'], LOCK_EX);
    }
    
    // Save template
    if (isset($data['template'])) {
        file_put_contents($dir . '/template.html', $data['template'], LOCK_EX);
    }
    
    return true;
}

// Create new campaign
function createCampaign($name, $subject = '') {
    $uuid = generateUUID();
    $dir = CAMPAIGNS_DIR . '/' . $uuid;
    
    // Create directory structure
    mkdir($dir, 0755, true);
    mkdir($dir . '/log', 0755, true);
    mkdir($dir . '/attachments', 0755, true);
    
    // Create files
    $options = [
        'name' => $name,
        'subject' => $subject,
        'status' => 'draft',
        'counter' => 0,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
        'attachments' => []
    ];
    
    file_put_contents($dir . '/options.json', json_encode($options, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    file_put_contents($dir . '/list.txt', '');
    file_put_contents($dir . '/template.html', getDefaultTemplate());
    file_put_contents($dir . '/log/send.txt', '');
    file_put_contents($dir . '/log/error.txt', '');
    file_put_contents($dir . '/log/unsubscribe.txt', '');
    file_put_contents($dir . '/log/opens.txt', '');
    file_put_contents($dir . '/log/clicks.txt', '');
    
    return $uuid;
}

// Delete campaign
function deleteCampaign($uuid) {
    $dir = CAMPAIGNS_DIR . '/' . $uuid;
    if (is_dir($dir)) {
        // Recursive directory removal
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
        rmdir($dir);
        return true;
    }
    return false;
}

// Get global unsubscribed list
function getGlobalUnsubscribed() {
    if (file_exists(UNSUBSCRIBED_FILE)) {
        return array_filter(array_map('trim', explode("\n", file_get_contents(UNSUBSCRIBED_FILE))));
    }
    return [];
}

// Add email to global unsubscribed list
function addToGlobalUnsubscribed($email) {
    $unsubscribed = getGlobalUnsubscribed();
    if (!in_array($email, $unsubscribed)) {
        $unsubscribed[] = $email;
        file_put_contents(UNSUBSCRIBED_FILE, implode("\n", $unsubscribed) . "\n", LOCK_EX);
    }
    return true;
}

// Remove email from global unsubscribed list
function removeFromGlobalUnsubscribed($email) {
    $unsubscribed = getGlobalUnsubscribed();
    $unsubscribed = array_filter($unsubscribed, function($e) use ($email) {
        return strtolower(trim($e)) !== strtolower(trim($email));
    });
    file_put_contents(UNSUBSCRIBED_FILE, implode("\n", $unsubscribed) . "\n", LOCK_EX);
    return true;
}

// Log unsubscribe to campaign
function logUnsubscribeToCampaign($email) {
    // Find campaigns that contain this email and log to them
    $campaigns = getCampaigns();
    foreach ($campaigns as $campaign) {
        $list_file = CAMPAIGNS_DIR . '/' . $campaign['uuid'] . '/list.txt';
        if (file_exists($list_file)) {
            $recipients = array_filter(array_map('trim', explode("\n", file_get_contents($list_file))));
            if (in_array($email, $recipients)) {
                $log_file = CAMPAIGNS_DIR . '/' . $campaign['uuid'] . '/log/unsubscribe.txt';
                $log_entry = date('Y-m-d H:i:s') . " - " . $email . "\n";
                file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
            }
        }
    }
}

// Default email template
function getDefaultTemplate() {
    return '<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Arial, sans-serif; background-color: #f5f5f5;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f5f5f5; padding: 40px 20px;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                    <!-- Header -->
                    <tr>
                        <td style="background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%); padding: 30px; text-align: center;">
                            <h1 style="color: #ffffff; margin: 0; font-size: 28px; font-weight: 600;">MyMailer</h1>
                        </td>
                    </tr>
                    
                    <!-- Content -->
                    <tr>
                        <td style="padding: 40px 30px;">
                            <h2 style="color: #1a1a2e; margin: 0 0 20px; font-size: 24px;">Hello!</h2>
                            <p style="color: #4a4a4a; line-height: 1.6; margin: 0 0 20px;">
                                This is a sample email. Replace this text with your message.
                            </p>
                            <p style="color: #4a4a4a; line-height: 1.6; margin: 0 0 30px;">
                                You can add any HTML content to this template.
                            </p>
                            
                            <!-- Button -->
                            <table cellpadding="0" cellspacing="0" style="margin: 0 auto;">
                                <tr>
                                    <td style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 8px;">
                                        <a href="#" style="display: inline-block; padding: 15px 35px; color: #ffffff; text-decoration: none; font-weight: 600; font-size: 16px;">
                                            Learn More
                                        </a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #f8f9fa; padding: 25px 30px; text-align: center; border-top: 1px solid #e9ecef;">
                            <p style="color: #6c757d; font-size: 12px; margin: 0 0 10px;">
                                You received this email because you subscribed to our newsletter.
                            </p>
                            <p style="margin: 0;">
                                <a href="[LINK_UNSUBSCRIBE]" style="color: #6c757d; font-size: 12px;">Unsubscribe</a>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
}

// Format file size
function formatFileSize($bytes) {
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    }
    return $bytes . ' bytes';
}

// Generate safe filename
function generateSafeFilename($extension) {
    return uniqid() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
}

// Get tracking base URL
function getTrackingUrl() {
    return getBaseUrl() . '/link.php';
}

// Process template for tracking - add pixel and replace links
function processTemplateForTracking($template, $campaignId, $email) {
    $trackingUrl = getTrackingUrl();
    $encodedEmail = base64_encode($email);
    
    // 1. Add tracking pixel before </body>
    $trackingPixel = '<img src="' . $trackingUrl . '?c=' . urlencode($campaignId) . '&e=' . urlencode($encodedEmail) . '&t=open" width="1" height="1" style="display:none;width:1px;height:1px;" alt="" />';
    
    if (stripos($template, '</body>') !== false) {
        $template = str_ireplace('</body>', $trackingPixel . '</body>', $template);
    } else {
        $template .= $trackingPixel;
    }
    
    // 2. Replace links with tracking links (except unsubscribe link)
    $template = preg_replace_callback(
        '/href=["\']([^"\']+)["\']/i',
        function($matches) use ($trackingUrl, $campaignId, $encodedEmail) {
            $originalUrl = $matches[1];
            
            // Don't track unsubscribe links, mailto, tel, or anchors
            if (strpos($originalUrl, 'LINK_UNSUBSCRIBE') !== false ||
                strpos($originalUrl, 'mailto:') === 0 ||
                strpos($originalUrl, 'tel:') === 0 ||
                strpos($originalUrl, '#') === 0 ||
                strpos($originalUrl, 'javascript:') === 0) {
                return $matches[0];
            }
            
            // Create tracking URL
            $trackedUrl = $trackingUrl . '?c=' . urlencode($campaignId) . '&e=' . urlencode($encodedEmail) . '&t=click&url=' . urlencode(base64_encode($originalUrl));
            
            return 'href="' . $trackedUrl . '"';
        },
        $template
    );
    
    return $template;
}

// Get campaign statistics
function getCampaignStats($uuid) {
    $dir = CAMPAIGNS_DIR . '/' . $uuid;
    
    $stats = [
        'opens' => ['total' => 0, 'unique' => 0],
        'clicks' => ['total' => 0, 'unique' => 0, 'urls' => []]
    ];
    
    // Parse opens log
    $opens_file = $dir . '/log/opens.txt';
    if (file_exists($opens_file)) {
        $opens_lines = array_filter(explode("\n", trim(file_get_contents($opens_file))));
        $stats['opens']['total'] = count($opens_lines);
        
        $unique_emails = [];
        foreach ($opens_lines as $line) {
            $parts = explode(' | ', $line);
            if (isset($parts[1])) {
                $unique_emails[$parts[1]] = true;
            }
        }
        $stats['opens']['unique'] = count($unique_emails);
    }
    
    // Parse clicks log
    $clicks_file = $dir . '/log/clicks.txt';
    if (file_exists($clicks_file)) {
        $clicks_lines = array_filter(explode("\n", trim(file_get_contents($clicks_file))));
        $stats['clicks']['total'] = count($clicks_lines);
        
        $unique_clickers = [];
        $url_clicks = [];
        
        foreach ($clicks_lines as $line) {
            $parts = explode(' | ', $line);
            if (isset($parts[1])) {
                $unique_clickers[$parts[1]] = true;
            }
            if (isset($parts[2])) {
                $url = $parts[2];
                if (!isset($url_clicks[$url])) {
                    $url_clicks[$url] = 0;
                }
                $url_clicks[$url]++;
            }
        }
        
        $stats['clicks']['unique'] = count($unique_clickers);
        arsort($url_clicks);
        $stats['clicks']['urls'] = $url_clicks;
    }
    
    return $stats;
}
