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

// Can mark single notification, multiple notifications, or all notifications
$notificationIds = [];
$markAll = false;

if (isset($input['notification_id'])) {
    $notificationIds = [(int)$input['notification_id']];
} elseif (isset($input['notification_ids']) && is_array($input['notification_ids'])) {
    $notificationIds = array_map('intval', $input['notification_ids']);
} elseif (isset($input['mark_all']) && $input['mark_all']) {
    $markAll = true;
} else {
    Response::error('Notification ID(s) or mark_all flag required');
}

try {
    $db = getDB();
    
    if ($markAll) {
        // Mark all notifications as read for current user
        $stmt = $db->prepare("
            UPDATE notifications 
            SET is_read = 1 
            WHERE user_id = ? AND is_read = 0
        ");
        $stmt->bind_param('i', $user['user_id']);
        $stmt->execute();
        
        $affectedRows = $stmt->affected_rows;
        
        Response::success([
            'marked_count' => $affectedRows
        ], "$affectedRows notification(s) marked as read");
        
    } else {
        if (empty($notificationIds)) {
            Response::error('No valid notification IDs provided');
        }
        
        // Validate that all notifications belong to current user
        $placeholders = str_repeat('?,', count($notificationIds) - 1) . '?';
        $validateStmt = $db->prepare("
            SELECT id FROM notifications 
            WHERE id IN ($placeholders) AND user_id = ?
        ");
        $params = array_merge($notificationIds, [$user['user_id']]);
        $types = str_repeat('i', count($notificationIds)) . 'i';
        $validateStmt->bind_param($types, ...$params);
        $validateStmt->execute();
        $validNotifications = $validateStmt->get_result();
        
        $validIds = [];
        while ($row = $validNotifications->fetch_assoc()) {
            $validIds[] = $row['id'];
        }
        
        if (empty($validIds)) {
            Response::error('No valid notifications found');
        }
        
        // Mark valid notifications as read
        $placeholders = str_repeat('?,', count($validIds) - 1) . '?';
        $updateStmt = $db->prepare("
            UPDATE notifications 
            SET is_read = 1 
            WHERE id IN ($placeholders) AND is_read = 0
        ");
        $updateStmt->bind_param(str_repeat('i', count($validIds)), ...$validIds);
        $updateStmt->execute();
        
        $affectedRows = $updateStmt->affected_rows;
        
        Response::success([
            'marked_count' => $affectedRows,
            'notification_ids' => $validIds
        ], "$affectedRows notification(s) marked as read");
    }
    
} catch (Exception $e) {
    error_log("Mark notification read error: " . $e->getMessage());
    Response::error('Internal server error', 500);
}
?>