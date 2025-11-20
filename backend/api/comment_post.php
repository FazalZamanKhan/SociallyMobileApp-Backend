<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

$user = Auth::requireAuth();
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['post_id']) || !isset($input['content'])) {
    Response::error('Post ID and content required');
}

$postId = (int)$input['post_id'];
$content = trim($input['content']);
$parentId = isset($input['parent_id']) ? (int)$input['parent_id'] : null;

if (empty($content)) {
    Response::error('Comment content cannot be empty');
}

if (strlen($content) > 1000) {
    Response::error('Comment is too long (max 1000 characters)');
}

try {
    $db = getDB();
    
    // Check if post exists and get post owner
    $postStmt = $db->prepare("SELECT user_id FROM posts WHERE id = ?");
    $postStmt->bind_param('i', $postId);
    $postStmt->execute();
    $postResult = $postStmt->get_result();
    
    if ($postResult->num_rows === 0) {
        Response::error('Post not found', 404);
    }
    
    $postOwner = $postResult->fetch_assoc()['user_id'];
    
    // If it's a reply, check if parent comment exists
    if ($parentId) {
        $parentStmt = $db->prepare("SELECT user_id FROM post_comments WHERE id = ? AND post_id = ?");
        $parentStmt->bind_param('ii', $parentId, $postId);
        $parentStmt->execute();
        $parentResult = $parentStmt->get_result();
        
        if ($parentResult->num_rows === 0) {
            Response::error('Parent comment not found', 404);
        }
    }
    
    $db->begin_transaction();
    
    try {
        // Insert comment
        $stmt = $db->prepare("INSERT INTO post_comments (post_id, user_id, content, parent_id) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('iisi', $postId, $user['user_id'], $content, $parentId);
        $stmt->execute();
        
        $commentId = $db->insert_id();
        
        // Get the created comment with user details
        $commentStmt = $db->prepare("
            SELECT 
                c.id, c.content, c.parent_id, c.likes_count, c.created_at,
                u.id as user_id, u.username, u.first_name, u.last_name, u.profile_picture
            FROM post_comments c
            JOIN users u ON c.user_id = u.id
            WHERE c.id = ?
        ");
        $commentStmt->bind_param('i', $commentId);
        $commentStmt->execute();
        $commentResult = $commentStmt->get_result();
        $comment = $commentResult->fetch_assoc();
        
        $comment['is_liked'] = false; // New comment, not liked yet
        $comment['user'] = [
            'id' => $comment['user_id'],
            'username' => $comment['username'],
            'first_name' => $comment['first_name'],
            'last_name' => $comment['last_name'],
            'profile_picture' => $comment['profile_picture']
        ];
        unset($comment['user_id'], $comment['username'], $comment['first_name'], $comment['last_name']);
        
        // Send notification to post owner (if not own post)
        if ($postOwner != $user['user_id']) {
            $userStmt = $db->prepare("SELECT username FROM users WHERE id = ?");
            $userStmt->bind_param('i', $user['user_id']);
            $userStmt->execute();
            $commenterUsername = $userStmt->get_result()->fetch_assoc()['username'];
            
            Notification::send(
                $postOwner,
                'comment',
                'New Comment',
                "$commenterUsername commented on your post",
                ['post_id' => $postId, 'comment_id' => $commentId, 'user_id' => $user['user_id']]
            );
        }
        
        $db->commit();
        
        Response::success(['comment' => $comment], 'Comment added successfully');
        
    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }
    
} catch (Exception $e) {
    error_log("Comment post error: " . $e->getMessage());
    Response::error('Internal server error', 500);
}
?>