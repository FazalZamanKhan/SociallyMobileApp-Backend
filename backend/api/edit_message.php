<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    Response::error('Method not allowed', 405);
}

$user = Auth::requireAuth();
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    Response::error('Invalid JSON input');
}

$requiredFields = ['message_id', 'content'];
$missing = Validator::validateRequired($requiredFields, $input);

if (!empty($missing)) {
    Response::error('Missing required fields: ' . implode(', ', $missing));
}

$messageId = (int)$input['message_id'];
$newContent = trim($input['content']);

if (empty($newContent)) {
    Response::error('Message content cannot be empty');
}

try {
    $db = getDB();
    
    // Get message details
    $stmt = $db->prepare("
        SELECT sender_id, content, media_url, created_at 
        FROM messages 
        WHERE id = ? AND is_deleted = 0
    ");
    $stmt->bind_param('i', $messageId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        Response::error('Message not found', 404);
    }
    
    $message = $result->fetch_assoc();
    
    // Check if user owns the message
    if ($message['sender_id'] != $user['user_id']) {
        Response::error('You can only edit your own messages', 403);
    }
    
    // Check if message has media (can't edit media messages)
    if (!empty($message['media_url'])) {
        Response::error('Cannot edit messages with media');
    }
    
    // Check if message is within edit time limit (5 minutes)
    $messageTime = strtotime($message['created_at']);
    $currentTime = time();
    
    if (($currentTime - $messageTime) > MESSAGE_EDIT_TIME_LIMIT) {
        Response::error('Message edit time limit exceeded (5 minutes)');
    }
    
    // Update message
    $updateStmt = $db->prepare("
        UPDATE messages 
        SET content = ?, is_edited = 1, edited_at = NOW() 
        WHERE id = ?
    ");
    $updateStmt->bind_param('si', $newContent, $messageId);
    
    if ($updateStmt->execute()) {
        // Get updated message
        $updatedStmt = $db->prepare("
            SELECT 
                m.id, m.content, m.media_url, m.media_type, m.message_type,
                m.is_edited, m.edited_at, m.is_read, m.created_at,
                m.sender_id, m.receiver_id
            FROM messages m
            WHERE m.id = ?
        ");
        $updatedStmt->bind_param('i', $messageId);
        $updatedStmt->execute();
        $updatedMessage = $updatedStmt->get_result()->fetch_assoc();
        
        Response::success(['message' => $updatedMessage], 'Message edited successfully');
    } else {
        Response::error('Failed to edit message');
    }
    
} catch (Exception $e) {
    error_log("Edit message error: " . $e->getMessage());
    Response::error('Internal server error', 500);
}
?>