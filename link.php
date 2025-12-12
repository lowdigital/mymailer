<?php
/**
 * MyMailer - Email Tracking Handler
 * 
 * Tracks email opens (via tracking pixel) and link clicks
 */

require_once __DIR__ . '/config.php';

$campaign_id = $_GET['c'] ?? '';
$email = $_GET['e'] ?? '';
$type = $_GET['t'] ?? '';
$url = $_GET['url'] ?? '';

// Validate campaign
if (empty($campaign_id) || empty($email) || empty($type)) {
    // Return 1x1 transparent pixel for invalid requests
    outputPixel();
    exit;
}

// Decode email (it's base64 encoded for URL safety)
$email = base64_decode($email);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    outputPixel();
    exit;
}

// Check if campaign exists
$campaign_dir = CAMPAIGNS_DIR . '/' . $campaign_id;
if (!is_dir($campaign_dir)) {
    outputPixel();
    exit;
}

// Get client info
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
$timestamp = date('Y-m-d H:i:s');

// Handle tracking based on type
switch ($type) {
    case 'open':
        // Log email open
        $log_file = $campaign_dir . '/log/opens.txt';
        
        // Check if this email already opened (to count unique opens)
        $existing_opens = file_exists($log_file) ? file_get_contents($log_file) : '';
        $is_unique = strpos($existing_opens, $email) === false;
        
        $log_entry = $timestamp . " | " . $email . " | " . ($is_unique ? "unique" : "repeat") . " | " . $ip . "\n";
        file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
        
        // Return tracking pixel
        outputPixel();
        break;
        
    case 'click':
        // Decode URL
        $decoded_url = base64_decode($url);
        if (empty($decoded_url) || !filter_var($decoded_url, FILTER_VALIDATE_URL)) {
            // Invalid URL - redirect to homepage or show error
            header('HTTP/1.1 400 Bad Request');
            exit;
        }
        
        // Log click
        $log_file = $campaign_dir . '/log/clicks.txt';
        $log_entry = $timestamp . " | " . $email . " | " . $decoded_url . " | " . $ip . "\n";
        file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
        
        // Redirect to original URL
        header('Location: ' . $decoded_url, true, 302);
        break;
        
    default:
        outputPixel();
}

/**
 * Output 1x1 transparent GIF pixel
 */
function outputPixel() {
    header('Content-Type: image/gif');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // 1x1 transparent GIF
    echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    exit;
}

