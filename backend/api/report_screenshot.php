<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

$user = Auth::requireAuth();
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['chat_with_user_id'])) {
    Response::error('Chat user ID required');
}

$chatWithUserId = (int)$input['chat_with_user_id'];

if ($chatWithUserId == $user['user_id']) {
    Response::error('Invalid chat user ID');
}

try {
    $db = getDB();
    
    // Check if the other user exists
    $userStmt = $db->prepare("SELECT username FROM users WHERE id = ?");
    $userStmt->bind_param('i', $chatWithUserId);
    $userStmt->execute();
    $userResult = $userStmt->get_result();
    
    if ($userResult->num_rows === 0) {
        Response::error('User not found', 404);
    }
    
    $otherUser = $userResult->fetch_assoc();
    
    // Record screenshot alert
    $stmt = $db->prepare("
        INSERT INTO screenshot_alerts (chat_user1_id, chat_user2_id, screenshot_by_user_id) 
        VALUES (?, ?, ?)
    ");
    
    // Ensure consistent ordering for chat_user1_id and chat_user2_id
    $userId1 = min($user['user_id'], $chatWithUserId);
    $userId2 = max($user['user_id'], $chatWithUserId);
    
    $stmt->bind_param('iii', $userId1, $userId2, $user['user_id']);
    
    if ($stmt->execute()) {
        // Get current user's username
        $currentUserStmt = $db->prepare("SELECT username FROM users WHERE id = ?");
        $currentUserStmt->bind_param('i', $user['user_id']);
        $currentUserStmt->execute();
        $currentUsername = $currentUserStmt->get_result()->fetch_assoc()['username'];
        
        // Send notification to the other user
        Notification::send(
            $chatWithUserId,
            'screenshot_alert',
            '📸 Screenshot Alert',
            "$currentUsername took a screenshot of your chat",
            [
                'screenshot_by_user_id' => $user['user_id'],
                'chat_user_id' => $chatWithUserId
            ]
        );
        
        Response::success([
            'screenshot_detected' => true,
            'notified_user' => $otherUser['username']
        ], 'Screenshot alert sent successfully');
    } else {
        Response::error('Failed to record screenshot alert');
    }
    
} catch (Exception $e) {
    error_log("Report screenshot error: " . $e->getMessage());
    Response::error('Internal server error', 500);
}
?>