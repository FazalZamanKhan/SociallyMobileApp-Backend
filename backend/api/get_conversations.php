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

try {
    $db = getDB();
    
    // Get conversations with latest message info
    $sql = "
        SELECT 
            other_user.id as user_id,
            other_user.username,
            other_user.first_name,
            other_user.last_name,
            other_user.profile_picture,
            other_user.is_online,
            other_user.last_seen,
            latest.content as last_message,
            latest.media_type as last_media_type,
            latest.message_type as last_message_type,
            latest.created_at as last_message_time,
            latest.sender_id = ? as is_last_message_mine,
            COALESCE(unread_count.count, 0) as unread_count
        FROM (
            SELECT 
                CASE 
                    WHEN sender_id = ? THEN receiver_id 
                    ELSE sender_id 
                END as other_user_id,
                MAX(created_at) as last_message_time
            FROM messages 
            WHERE (sender_id = ? OR receiver_id = ?)
              AND is_deleted = 0
              AND (expires_at IS NULL OR expires_at > NOW())
            GROUP BY other_user_id
        ) conv
        JOIN users other_user ON conv.other_user_id = other_user.id
        JOIN messages latest ON (
            ((latest.sender_id = ? AND latest.receiver_id = conv.other_user_id) OR
             (latest.sender_id = conv.other_user_id AND latest.receiver_id = ?))
            AND latest.created_at = conv.last_message_time
            AND latest.is_deleted = 0
            AND (latest.expires_at IS NULL OR latest.expires_at > NOW())
        )
        LEFT JOIN (
            SELECT 
                sender_id,
                COUNT(*) as count
            FROM messages 
            WHERE receiver_id = ? AND is_read = 0 AND is_deleted = 0
              AND (expires_at IS NULL OR expires_at > NOW())
            GROUP BY sender_id
        ) unread_count ON unread_count.sender_id = conv.other_user_id
        ORDER BY latest.created_at DESC
        LIMIT ? OFFSET ?
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param(
        'iiiiiiiiii', 
        $user['user_id'], // is_last_message_mine check
        $user['user_id'], // CASE condition 1
        $user['user_id'], // WHERE condition 1
        $user['user_id'], // WHERE condition 2
        $user['user_id'], // JOIN condition 1
        $user['user_id'], // JOIN condition 2
        $user['user_id'], // unread count WHERE
        $limit,
        $offset
    );
    $stmt->execute();
    $result = $stmt->get_result();
    
    $conversations = [];
    while ($row = $result->fetch_assoc()) {
        $lastMessage = $row['last_message'];
        if (empty($lastMessage) && $row['last_media_type'] !== 'text') {
            $lastMessage = '📎 ' . ucfirst($row['last_media_type']);
        }
        
        if ($row['last_message_type'] === 'vanish') {
            $lastMessage = '🔥 ' . $lastMessage;
        }
        
        $conversations[] = [
            'user' => [
                'id' => $row['user_id'],
                'username' => $row['username'],
                'first_name' => $row['first_name'],
                'last_name' => $row['last_name'],
                'profile_picture' => $row['profile_picture'],
                'is_online' => (bool)$row['is_online'],
                'last_seen' => $row['last_seen']
            ],
            'last_message' => $lastMessage,
            'last_message_time' => $row['last_message_time'],
            'is_last_message_mine' => (bool)$row['is_last_message_mine'],
            'unread_count' => (int)$row['unread_count']
        ];
    }
    
    // Get total conversations count
    $countSql = "
        SELECT COUNT(DISTINCT 
            CASE 
                WHEN sender_id = ? THEN receiver_id 
                ELSE sender_id 
            END
        ) as total
        FROM messages 
        WHERE (sender_id = ? OR receiver_id = ?)
          AND is_deleted = 0
          AND (expires_at IS NULL OR expires_at > NOW())
    ";
    
    $countStmt = $db->prepare($countSql);
    $countStmt->bind_param('iii', $user['user_id'], $user['user_id'], $user['user_id']);
    $countStmt->execute();
    $total = $countStmt->get_result()->fetch_assoc()['total'];
    
    Response::success(Response::pagination($conversations, $total, $page, $limit));
    
} catch (Exception $e) {
    error_log("Get conversations error: " . $e->getMessage());
    Response::error('Internal server error', 500);
}
?>