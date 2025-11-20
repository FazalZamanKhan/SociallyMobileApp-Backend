<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('Method not allowed', 405);
}

$user = Auth::requireAuth();
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = min(MAX_PAGE_SIZE, max(1, (int)($_GET['limit'] ?? DEFAULT_PAGE_SIZE)));
$offset = ($page - 1) * $limit;
$unreadOnly = isset($_GET['unread_only']) ? (bool)$_GET['unread_only'] : false;

try {
    $db = getDB();
    
    $whereClause = 'WHERE user_id = ?';
    $params = [$user['user_id']];
    $types = 'i';
    
    if ($unreadOnly) {
        $whereClause .= ' AND is_read = 0';
    }
    
    // Get total count
    $countSql = "SELECT COUNT(*) as total FROM notifications $whereClause";
    $countStmt = $db->prepare($countSql);
    $countStmt->bind_param($types, ...$params);
    $countStmt->execute();
    $total = $countStmt->get_result()->fetch_assoc()['total'];
    
    // Get notifications
    $sql = "
        SELECT id, type, title, message, data, is_read, created_at
        FROM notifications 
        $whereClause
        ORDER BY created_at DESC
        LIMIT ? OFFSET ?
    ";
    
    $params[] = $limit;
    $params[] = $offset;
    $types .= 'ii';
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $notifications = [];
    while ($row = $result->fetch_assoc()) {
        $row['data'] = json_decode($row['data'], true);
        $row['is_read'] = (bool)$row['is_read'];
        $notifications[] = $row;
    }
    
    // Get unread count
    $unreadStmt = $db->prepare("SELECT COUNT(*) as unread_count FROM notifications WHERE user_id = ? AND is_read = 0");
    $unreadStmt->bind_param('i', $user['user_id']);
    $unreadStmt->execute();
    $unreadCount = $unreadStmt->get_result()->fetch_assoc()['unread_count'];
    
    $response = Response::pagination($notifications, $total, $page, $limit);
    $response['unread_count'] = (int)$unreadCount;
    
    Response::success($response);
    
} catch (Exception $e) {
    error_log("Get notifications error: " . $e->getMessage());
    Response::error('Internal server error', 500);
}
?>