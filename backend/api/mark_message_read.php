<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

$user = Auth::requireAuth();
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    Response::error('Invalid JSON input');
}

// Can mark single message or multiple messages
$messageIds = [];
if (isset($input['message_id'])) {
    $messageIds = [(int)$input['message_id']];
} elseif (isset($input['message_ids']) && is_array($input['message_ids'])) {
    $messageIds = array_map('intval', $input['message_ids']);
} else {
    Response::error('Message ID(s) required');
}

if (empty($messageIds)) {
    Response::error('No valid message IDs provided');
}

try {
    $db = getDB();
    
    // Validate that all messages exist and belong to current user as receiver
    $placeholders = str_repeat('?,', count($messageIds) - 1) . '?';
    $validateStmt = $db->prepare("
        SELECT id FROM messages 
        WHERE id IN ($placeholders) 
          AND receiver_id = ? 
          AND is_deleted = 0
    ");
    $params = array_merge($messageIds, [$user['user_id']]);
    $types = str_repeat('i', count($messageIds)) . 'i';
    $validateStmt->bind_param($types, ...$params);
    $validateStmt->execute();
    $validMessages = $validateStmt->get_result();
    
    $validIds = [];
    while ($row = $validMessages->fetch_assoc()) {
        $validIds[] = $row['id'];
    }
    
    if (empty($validIds)) {
        Response::error('No valid messages found to mark as read');
    }
    
    $db->begin_transaction();
    
    try {
        // Mark messages as read
        $placeholders = str_repeat('?,', count($validIds) - 1) . '?';
        $updateStmt = $db->prepare("
            UPDATE messages 
            SET is_read = 1, read_at = NOW() 
            WHERE id IN ($placeholders) AND is_read = 0
        ");
        $updateStmt->bind_param(str_repeat('i', count($validIds)), ...$validIds);
        $updateStmt->execute();
        
        $affectedRows = $updateStmt->affected_rows;
        
        // Handle vanish messages - set expiry after being read
        $vanishStmt = $db->prepare("
            UPDATE messages 
            SET expires_at = DATE_ADD(NOW(), INTERVAL 1 HOUR)
            WHERE id IN ($placeholders) 
              AND message_type = 'vanish' 
              AND expires_at > DATE_ADD(NOW(), INTERVAL 1 HOUR)
        ");
        $vanishStmt->bind_param(str_repeat('i', count($validIds)), ...$validIds);
        $vanishStmt->execute();
        
        $db->commit();
        
        Response::success([
            'marked_count' => $affectedRows,
            'message_ids' => $validIds
        ], "$affectedRows message(s) marked as read");
        
    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }
    
} catch (Exception $e) {
    error_log("Mark message read error: " . $e->getMessage());
    Response::error('Internal server error', 500);
}
?>