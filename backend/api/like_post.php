<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

$user = Auth::requireAuth();
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['post_id'])) {
    Response::error('Post ID required');
}

$postId = (int)$input['post_id'];

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
    
    // Check if user already liked this post
    $likeStmt = $db->prepare("SELECT id FROM post_likes WHERE post_id = ? AND user_id = ?");
    $likeStmt->bind_param('ii', $postId, $user['user_id']);
    $likeStmt->execute();
    $likeResult = $likeStmt->get_result();
    
    $isLiked = $likeResult->num_rows > 0;
    
    $db->begin_transaction();
    
    try {
        if ($isLiked) {
            // Unlike the post
            $deleteLikeStmt = $db->prepare("DELETE FROM post_likes WHERE post_id = ? AND user_id = ?");
            $deleteLikeStmt->bind_param('ii', $postId, $user['user_id']);
            $deleteLikeStmt->execute();
            $action = 'unliked';
        } else {
            // Like the post
            $insertLikeStmt = $db->prepare("INSERT INTO post_likes (post_id, user_id) VALUES (?, ?)");
            $insertLikeStmt->bind_param('ii', $postId, $user['user_id']);
            $insertLikeStmt->execute();
            $action = 'liked';
            
            // Send notification to post owner (if not own post)
            if ($postOwner != $user['user_id']) {
                $userStmt = $db->prepare("SELECT username FROM users WHERE id = ?");
                $userStmt->bind_param('i', $user['user_id']);
                $userStmt->execute();
                $likerUsername = $userStmt->get_result()->fetch_assoc()['username'];
                
                Notification::send(
                    $postOwner,
                    'like',
                    'New Like',
                    "$likerUsername liked your post",
                    ['post_id' => $postId, 'user_id' => $user['user_id']]
                );
            }
        }
        
        // Get updated likes count
        $countStmt = $db->prepare("SELECT likes_count FROM posts WHERE id = ?");
        $countStmt->bind_param('i', $postId);
        $countStmt->execute();
        $likesCount = $countStmt->get_result()->fetch_assoc()['likes_count'];
        
        $db->commit();
        
        Response::success([
            'action' => $action,
            'is_liked' => !$isLiked,
            'likes_count' => (int)$likesCount
        ], "Post $action successfully");
        
    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }
    
} catch (Exception $e) {
    error_log("Like post error: " . $e->getMessage());
    Response::error('Internal server error', 500);
}
?>