<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('Method not allowed', 405);
}

$user = Auth::requireAuth();
$type = $_GET['type'] ?? 'received'; // 'received' or 'sent'
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = min(MAX_PAGE_SIZE, max(1, (int)($_GET['limit'] ?? DEFAULT_PAGE_SIZE)));
$offset = ($page - 1) * $limit;

if (!in_array($type, ['received', 'sent'])) {
    Response::error('Invalid type. Use "received" or "sent"');
}

try {
    $db = getDB();
    
    if ($type === 'received') {
        // Get follow requests received by current user
        $sql = "
            SELECT 
                fr.id, fr.status, fr.created_at,
                u.id as user_id, u.username, u.first_name, u.last_name, u.profile_picture
            FROM follow_requests fr
            JOIN users u ON fr.sender_id = u.id
            WHERE fr.receiver_id = ? AND fr.status = 'pending'
            ORDER BY fr.created_at DESC
            LIMIT ? OFFSET ?
        ";
        
        $countSql = "SELECT COUNT(*) as total FROM follow_requests WHERE receiver_id = ? AND status = 'pending'";
        
    } else { // sent
        // Get follow requests sent by current user
        $sql = "
            SELECT 
                fr.id, fr.status, fr.created_at,
                u.id as user_id, u.username, u.first_name, u.last_name, u.profile_picture
            FROM follow_requests fr
            JOIN users u ON fr.receiver_id = u.id
            WHERE fr.sender_id = ?
            ORDER BY fr.created_at DESC
            LIMIT ? OFFSET ?
        ";
        
        $countSql = "SELECT COUNT(*) as total FROM follow_requests WHERE sender_id = ?";
    }
    
    // Get total count
    $countStmt = $db->prepare($countSql);
    $countStmt->bind_param('i', $user['user_id']);
    $countStmt->execute();
    $total = $countStmt->get_result()->fetch_assoc()['total'];
    
    // Get requests
    $stmt = $db->prepare($sql);
    if ($type === 'received') {
        $stmt->bind_param('iii', $user['user_id'], $limit, $offset);
    } else {
        $stmt->bind_param('iii', $user['user_id'], $limit, $offset);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    $requests = [];
    while ($row = $result->fetch_assoc()) {
        $requests[] = [
            'request_id' => $row['id'],
            'status' => $row['status'],
            'created_at' => $row['created_at'],
            'user' => [
                'id' => $row['user_id'],
                'username' => $row['username'],
                'first_name' => $row['first_name'],
                'last_name' => $row['last_name'],
                'profile_picture' => $row['profile_picture']
            ]
        ];
    }
    
    Response::success(Response::pagination($requests, $total, $page, $limit));
    
} catch (Exception $e) {
    error_log("Get follow requests error: " . $e->getMessage());
    Response::error('Internal server error', 500);
}
?>