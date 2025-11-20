<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    Response::error('Method not allowed', 405);
}

$user = Auth::requireAuth();
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['message_id'])) {
    Response::error('Message ID required');
}

$messageId = (int)$input['message_id'];
$deleteForEveryone = isset($input['delete_for_everyone']) ? (bool)$input['delete_for_everyone'] : false;

try {
    $db = getDB();
    
    // Get message details
    $stmt = $db->prepare("
        SELECT sender_id, receiver_id, created_at, media_url 
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
        Response::error('You can only delete your own messages', 403);
    }
    
    // For "delete for everyone", check time limit
    if ($deleteForEveryone) {
        $messageTime = strtotime($message['created_at']);
        $currentTime = time();
        
        if (($currentTime - $messageTime) > MESSAGE_EDIT_TIME_LIMIT) {
            Response::error('Message delete time limit exceeded (5 minutes)');
        }
    }
    
    if ($deleteForEveryone) {
        // Mark message as deleted (soft delete)
        $deleteStmt = $db->prepare("
            UPDATE messages 
            SET is_deleted = 1, deleted_at = NOW(), content = '[Message deleted]'
            WHERE id = ?
        ");
        $deleteStmt->bind_param('i', $messageId);
        
        if ($deleteStmt->execute()) {
            // If message has media, you might want to delete the file too
            if (!empty($message['media_url'])) {
                $mediaPath = '../' . $message['media_url'];
                if (file_exists($mediaPath)) {
                    unlink($mediaPath);
                }
            }
            
            Response::success([], 'Message deleted for everyone');
        } else {
            Response::error('Failed to delete message');
        }
    } else {
        // Delete only for current user (implement user-specific deletion if needed)
        // For now, we'll use the same soft delete
        $deleteStmt = $db->prepare("
            UPDATE messages 
            SET is_deleted = 1, deleted_at = NOW(), content = '[Message deleted]'
            WHERE id = ?
        ");
        $deleteStmt->bind_param('i', $messageId);
        
        if ($deleteStmt->execute()) {
            Response::success([], 'Message deleted');
        } else {
            Response::error('Failed to delete message');
        }
    }
    
} catch (Exception $e) {
    error_log("Delete message error: " . $e->getMessage());
    Response::error('Internal server error', 500);
}
?>