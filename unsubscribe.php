<?php
/**
 * MyMailer - Unsubscribe Handler
 * 
 * Handles email unsubscribe and resubscribe requests
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Check request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Метод не разрешен']);
    exit;
}

// Get email from POST
$email = isset($_POST['email']) ? trim($_POST['email']) : '';
$action = isset($_POST['action']) ? $_POST['action'] : 'unsubscribe';

// Validate email
if (empty($email)) {
    echo json_encode(['success' => false, 'error' => 'Email не указан']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'error' => 'Некорректный email адрес']);
    exit;
}

// Handle resubscribe
if ($action === 'resubscribe') {
    removeFromGlobalUnsubscribed($email);
    echo json_encode([
        'success' => true,
        'message' => 'Вы снова подписаны на рассылку',
        'email' => $email
    ]);
    exit;
}

// Handle unsubscribe
$unsubscribed_emails = getGlobalUnsubscribed();

// Check if already unsubscribed
if (in_array($email, $unsubscribed_emails)) {
    echo json_encode(['success' => true, 'message' => 'Email уже был отписан ранее']);
    exit;
}

// Add to global unsubscribe list
addToGlobalUnsubscribed($email);

// Log to campaign-specific files
logUnsubscribeToCampaign($email);

// Return success
echo json_encode([
    'success' => true, 
    'message' => 'Вы успешно отписались от рассылки',
    'email' => $email
]);
