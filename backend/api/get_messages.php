<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('Method not allowed', 405);
}

$user = Auth::requireAuth();

if (!isset($_GET['other_user_id'])) {
    Response::error('Other user ID required');
}

$otherUserId = (int)$_GET['other_user_id'];
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = min(MAX_PAGE_SIZE, max(1, (int)($_GET['limit'] ?? DEFAULT_PAGE_SIZE)));
$offset = ($page - 1) * $limit;

try {
    $db = getDB();
    
    // Check if other user exists
    $userStmt = $db->prepare("SELECT id FROM users WHERE id = ?");
    $userStmt->bind_param('i', $otherUserId);
    $userStmt->execute();
    
    if ($userStmt->get_result()->num_rows === 0) {
        Response::error('User not found', 404);
    }
    
    // Get total messages count
    $countStmt = $db->prepare("
        SELECT COUNT(*) as total 
        FROM messages 
        WHERE ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?))
          AND is_deleted = 0
          AND (expires_at IS NULL OR expires_at > NOW())
    ");
    $countStmt->bind_param('iiii', $user['user_id'], $otherUserId, $otherUserId, $user['user_id']);
    $countStmt->execute();
    $total = $countStmt->get_result()->fetch_assoc()['total'];
    
    // Get messages
    $sql = "
        SELECT 
            m.id, m.content, m.media_url, m.media_type, m.message_type,
            m.is_edited, m.edited_at, m.is_read, m.read_at, m.expires_at, m.created_at,
            m.sender_id, m.receiver_id,
            u.username, u.profile_picture
        FROM messages m
        JOIN users u ON m.sender_id = u.id
        WHERE ((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?))
          AND m.is_deleted = 0
          AND (m.expires_at IS NULL OR m.expires_at > NOW())
        ORDER BY m.created_at DESC
        LIMIT ? OFFSET ?
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param('iiiiii', $user['user_id'], $otherUserId, $otherUserId, $user['user_id'], $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $messages = [];
    $messageIds = [];
    
    while ($row = $result->fetch_assoc()) {
        $row['sender'] = [
            'id' => $row['sender_id'],
            'username' => $row['username'],
            'profile_picture' => $row['profile_picture']
        ];
        $row['is_own_message'] = $row['sender_id'] == $user['user_id'];
        
        // Track message IDs for marking as read
        if (!$row['is_own_message'] && !$row['is_read']) {
            $messageIds[] = $row['id'];
        }
        
        unset($row['username'], $row['profile_picture']);
        $messages[] = $row;
    }
    
    // Mark unread messages as read
    if (!empty($messageIds)) {
        $placeholders = str_repeat('?,', count($messageIds) - 1) . '?';
        $markReadStmt = $db->prepare("UPDATE messages SET is_read = 1, read_at = NOW() WHERE id IN ($placeholders)");
        $markReadStmt->execute($messageIds);
        
        // Update vanish messages expiry (they expire after being read and chat closed)
        $vanishStmt = $db->prepare("
            UPDATE messages 
            SET expires_at = DATE_ADD(NOW(), INTERVAL 1 HOUR)
            WHERE id IN ($placeholders) AND message_type = 'vanish'
        ");
        $vanishStmt->execute($messageIds);
    }
    
    // Reverse to show chronological order (oldest first)
    $messages = array_reverse($messages);
    
    Response::success(Response::pagination($messages, $total, $page, $limit));
    
} catch (Exception $e) {
    error_log("Get messages error: " . $e->getMessage());
    Response::error('Internal server error', 500);
}
?>