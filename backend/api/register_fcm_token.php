<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

$user = Auth::requireAuth();
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['token'])) {
    Response::error('FCM token required');
}

$token = trim($input['token']);
$deviceType = isset($input['device_type']) ? $input['device_type'] : 'android';

if (empty($token)) {
    Response::error('FCM token cannot be empty');
}

if (!in_array($deviceType, ['android', 'ios'])) {
    $deviceType = 'android';
}

try {
    $db = getDB();
    
    // Check if token already exists for this user
    $checkStmt = $db->prepare("SELECT id FROM notification_tokens WHERE user_id = ? AND token = ?");
    $checkStmt->bind_param('is', $user['user_id'], $token);
    $checkStmt->execute();
    
    if ($checkStmt->get_result()->num_rows > 0) {
        // Update existing token
        $updateStmt = $db->prepare("
            UPDATE notification_tokens 
            SET device_type = ?, is_active = 1, updated_at = NOW() 
            WHERE user_id = ? AND token = ?
        ");
        $updateStmt->bind_param('sis', $deviceType, $user['user_id'], $token);
        $updateStmt->execute();
        
        Response::success([], 'FCM token updated successfully');
    } else {
        // Insert new token
        $insertStmt = $db->prepare("
            INSERT INTO notification_tokens (user_id, token, device_type) 
            VALUES (?, ?, ?)
        ");
        $insertStmt->bind_param('iss', $user['user_id'], $token, $deviceType);
        
        if ($insertStmt->execute()) {
            Response::success([], 'FCM token registered successfully');
        } else {
            Response::error('Failed to register FCM token');
        }
    }
    
} catch (Exception $e) {
    error_log("Register FCM token error: " . $e->getMessage());
    Response::error('Internal server error', 500);
}
?>