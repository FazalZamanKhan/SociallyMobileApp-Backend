<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('Method not allowed', 405);
}

$user = Auth::getCurrentUser();

if (!isset($_GET['post_id'])) {
    Response::error('Post ID required');
}

$postId = (int)$_GET['post_id'];
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = min(MAX_PAGE_SIZE, max(1, (int)($_GET['limit'] ?? DEFAULT_PAGE_SIZE)));
$offset = ($page - 1) * $limit;

try {
    $db = getDB();
    
    // Check if post exists
    $postStmt = $db->prepare("SELECT id FROM posts WHERE id = ?");
    $postStmt->bind_param('i', $postId);
    $postStmt->execute();
    
    if ($postStmt->get_result()->num_rows === 0) {
        Response::error('Post not found', 404);
    }
    
    // Get total comments count
    $countStmt = $db->prepare("SELECT COUNT(*) as total FROM post_comments WHERE post_id = ?");
    $countStmt->bind_param('i', $postId);
    $countStmt->execute();
    $total = $countStmt->get_result()->fetch_assoc()['total'];
    
    // Get comments with user details
    $sql = "
        SELECT 
            c.id, c.content, c.parent_id, c.likes_count, c.created_at,
            u.id as user_id, u.username, u.first_name, u.last_name, u.profile_picture
        FROM post_comments c
        JOIN users u ON c.user_id = u.id
        WHERE c.post_id = ?
        ORDER BY c.created_at ASC
        LIMIT ? OFFSET ?
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param('iii', $postId, $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $comments = [];
    while ($row = $result->fetch_assoc()) {
        // Check if current user liked this comment
        $isLiked = false;
        if ($user) {
            $likeStmt = $db->prepare("SELECT id FROM comment_likes WHERE comment_id = ? AND user_id = ?");
            $likeStmt->bind_param('ii', $row['id'], $user['user_id']);
            $likeStmt->execute();
            $isLiked = $likeStmt->get_result()->num_rows > 0;
        }
        
        $row['is_liked'] = $isLiked;
        $row['user'] = [
            'id' => $row['user_id'],
            'username' => $row['username'],
            'first_name' => $row['first_name'],
            'last_name' => $row['last_name'],
            'profile_picture' => $row['profile_picture']
        ];
        unset($row['user_id'], $row['username'], $row['first_name'], $row['last_name']);
        
        $comments[] = $row;
    }
    
    Response::success(Response::pagination($comments, $total, $page, $limit));
    
} catch (Exception $e) {
    error_log("Get comments error: " . $e->getMessage());
    Response::error('Internal server error', 500);
}
?>