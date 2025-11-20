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

$userId = (int)$userId;
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = min(MAX_PAGE_SIZE, max(1, (int)($_GET['limit'] ?? DEFAULT_PAGE_SIZE)));
$offset = ($page - 1) * $limit;

try {
    $db = getDB();
    
    // Check if target user exists and if profile is private
    $userStmt = $db->prepare("SELECT id, username, is_private FROM users WHERE id = ?");
    $userStmt->bind_param('i', $userId);
    $userStmt->execute();
    $userResult = $userStmt->get_result();
    
    if ($userResult->num_rows === 0) {
        Response::error('User not found', 404);
    }
    
    $targetUser = $userResult->fetch_assoc();
    
    // Check if current user can view followers list
    $canView = true;
    if ($targetUser['is_private'] && $currentUser && $currentUser['user_id'] != $userId) {
        // Check if current user is following the target user
        $followingStmt = $db->prepare("SELECT id FROM followers WHERE user_id = ? AND follower_id = ?");
        $followingStmt->bind_param('ii', $userId, $currentUser['user_id']);
        $followingStmt->execute();
        $canView = $followingStmt->get_result()->num_rows > 0;
    } elseif ($targetUser['is_private'] && !$currentUser) {
        $canView = false;
    }
    
    if (!$canView) {
        Response::error('Cannot view followers list for private account', 403);
    }
    
    // Get total followers count
    $countStmt = $db->prepare("SELECT COUNT(*) as total FROM followers WHERE user_id = ?");
    $countStmt->bind_param('i', $userId);
    $countStmt->execute();
    $total = $countStmt->get_result()->fetch_assoc()['total'];
    
    // Get followers with their details
    $sql = "
        SELECT 
            f.created_at as followed_at,
            u.id, u.username, u.first_name, u.last_name, u.profile_picture, u.bio
        FROM followers f
        JOIN users u ON f.follower_id = u.id
        WHERE f.user_id = ?
        ORDER BY f.created_at DESC
        LIMIT ? OFFSET ?
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param('iii', $userId, $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $followers = [];
    while ($row = $result->fetch_assoc()) {
        // Check if current user is following this follower
        $isFollowing = false;
        $followRequestStatus = null;
        
        if ($currentUser && $currentUser['user_id'] != $row['id']) {
            $followingCheckStmt = $db->prepare("SELECT id FROM followers WHERE user_id = ? AND follower_id = ?");
            $followingCheckStmt->bind_param('ii', $row['id'], $currentUser['user_id']);
            $followingCheckStmt->execute();
            $isFollowing = $followingCheckStmt->get_result()->num_rows > 0;
            
            // Check follow request status
            $requestStmt = $db->prepare("SELECT status FROM follow_requests WHERE sender_id = ? AND receiver_id = ?");
            $requestStmt->bind_param('ii', $currentUser['user_id'], $row['id']);
            $requestStmt->execute();
            $requestResult = $requestStmt->get_result();
            if ($requestResult->num_rows > 0) {
                $followRequestStatus = $requestResult->fetch_assoc()['status'];
            }
        }
        
        $followers[] = [
            'user' => [
                'id' => $row['id'],
                'username' => $row['username'],
                'first_name' => $row['first_name'],
                'last_name' => $row['last_name'],
                'profile_picture' => $row['profile_picture'],
                'bio' => $row['bio']
            ],
            'followed_at' => $row['followed_at'],
            'is_following' => $isFollowing,
            'follow_request_status' => $followRequestStatus,
            'is_own_profile' => $currentUser && $currentUser['user_id'] == $row['id']
        ];
    }
    
    Response::success(Response::pagination($followers, $total, $page, $limit));
    
} catch (Exception $e) {
    error_log("Get followers error: " . $e->getMessage());
    Response::error('Internal server error', 500);
}
?>