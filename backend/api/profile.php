<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('Method not allowed', 405);
}

$currentUser = Auth::getCurrentUser();
$userId = $_GET['user_id'] ?? ($currentUser ? $currentUser['user_id'] : null);

if (!$userId) {
    Response::error('User ID required');
}

try {
    $db = getDB();
    
    // Get user profile
    $stmt = $db->prepare("
        SELECT 
            u.id, u.username, u.email, u.first_name, u.last_name, u.bio, 
            u.profile_picture, u.cover_photo, u.is_online, u.last_seen, 
            u.is_private, u.created_at,
            (SELECT COUNT(*) FROM followers WHERE user_id = u.id) as followers_count,
            (SELECT COUNT(*) FROM followers WHERE follower_id = u.id) as following_count,
            (SELECT COUNT(*) FROM posts WHERE user_id = u.id) as posts_count
        FROM users u 
        WHERE u.id = ?
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        Response::error('User not found', 404);
    }
    
    $user = $result->fetch_assoc();
    
    // Check if current user is following this user
    $isFollowing = false;
    $followRequestStatus = null;
    
    if ($currentUser && $currentUser['user_id'] != $userId) {
        // Check if following
        $followStmt = $db->prepare("SELECT id FROM followers WHERE user_id = ? AND follower_id = ?");
        $followStmt->bind_param('ii', $userId, $currentUser['user_id']);
        $followStmt->execute();
        $isFollowing = $followStmt->get_result()->num_rows > 0;
        
        // Check follow request status
        $requestStmt = $db->prepare("SELECT status FROM follow_requests WHERE sender_id = ? AND receiver_id = ?");
        $requestStmt->bind_param('ii', $currentUser['user_id'], $userId);
        $requestStmt->execute();
        $requestResult = $requestStmt->get_result();
        if ($requestResult->num_rows > 0) {
            $followRequestStatus = $requestResult->fetch_assoc()['status'];
        }
    }
    
    // Add follow status to user data
    $user['is_following'] = $isFollowing;
    $user['follow_request_status'] = $followRequestStatus;
    $user['is_own_profile'] = $currentUser && $currentUser['user_id'] == $userId;
    
    // If it's a private account and not following, hide some data
    if ($user['is_private'] && !$user['is_following'] && !$user['is_own_profile']) {
        $user['posts_count'] = 0;
        $user['bio'] = '';
    }
    
    Response::success(['user' => $user]);
    
} catch (Exception $e) {
    error_log("Profile error: " . $e->getMessage());
    Response::error('Internal server error', 500);
}
?>