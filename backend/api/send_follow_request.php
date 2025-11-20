<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

$user = Auth::requireAuth();
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['user_id'])) {
    Response::error('User ID required');
}

$targetUserId = (int)$input['user_id'];

if ($targetUserId == $user['user_id']) {
    Response::error('Cannot follow yourself');
}

try {
    $db = getDB();
    
    // Check if target user exists
    $userStmt = $db->prepare("SELECT id, username, is_private FROM users WHERE id = ?");
    $userStmt->bind_param('i', $targetUserId);
    $userStmt->execute();
    $userResult = $userStmt->get_result();
    
    if ($userResult->num_rows === 0) {
        Response::error('User not found', 404);
    }
    
    $targetUser = $userResult->fetch_assoc();
    
    // Check if already following
    $followStmt = $db->prepare("SELECT id FROM followers WHERE user_id = ? AND follower_id = ?");
    $followStmt->bind_param('ii', $targetUserId, $user['user_id']);
    $followStmt->execute();
    
    if ($followStmt->get_result()->num_rows > 0) {
        Response::error('Already following this user');
    }
    
    // Check if follow request already exists
    $requestStmt = $db->prepare("SELECT id, status FROM follow_requests WHERE sender_id = ? AND receiver_id = ?");
    $requestStmt->bind_param('ii', $user['user_id'], $targetUserId);
    $requestStmt->execute();
    $requestResult = $requestStmt->get_result();
    
    if ($requestResult->num_rows > 0) {
        $existingRequest = $requestResult->fetch_assoc();
        if ($existingRequest['status'] === 'pending') {
            Response::error('Follow request already sent');
        } elseif ($existingRequest['status'] === 'rejected') {
            // Update existing rejected request to pending
            $updateStmt = $db->prepare("UPDATE follow_requests SET status = 'pending', updated_at = NOW() WHERE id = ?");
            $updateStmt->bind_param('i', $existingRequest['id']);
            $updateStmt->execute();
            $message = 'Follow request sent';
        }
    } else {
        // Create new follow request
        $insertStmt = $db->prepare("INSERT INTO follow_requests (sender_id, receiver_id, status) VALUES (?, ?, 'pending')");
        $insertStmt->bind_param('ii', $user['user_id'], $targetUserId);
        $insertStmt->execute();
        $message = 'Follow request sent';
    }
    
    // If account is public, auto-accept the request
    if (!$targetUser['is_private']) {
        $db->begin_transaction();
        
        try {
            // Accept the request
            $acceptStmt = $db->prepare("UPDATE follow_requests SET status = 'accepted', updated_at = NOW() WHERE sender_id = ? AND receiver_id = ?");
            $acceptStmt->bind_param('ii', $user['user_id'], $targetUserId);
            $acceptStmt->execute();
            
            // Add to followers table
            $addFollowStmt = $db->prepare("INSERT INTO followers (user_id, follower_id) VALUES (?, ?)");
            $addFollowStmt->bind_param('ii', $targetUserId, $user['user_id']);
            $addFollowStmt->execute();
            
            $db->commit();
            $message = 'Now following user';
        } catch (Exception $e) {
            $db->rollback();
            throw $e;
        }
    } else {
        // Send notification for follow request
        $senderStmt = $db->prepare("SELECT username FROM users WHERE id = ?");
        $senderStmt->bind_param('i', $user['user_id']);
        $senderStmt->execute();
        $senderUsername = $senderStmt->get_result()->fetch_assoc()['username'];
        
        Notification::send(
            $targetUserId,
            'follow_request',
            'New Follow Request',
            "$senderUsername wants to follow you",
            ['user_id' => $user['user_id']]
        );
    }
    
    Response::success([
        'status' => !$targetUser['is_private'] ? 'accepted' : 'pending',
        'is_following' => !$targetUser['is_private']
    ], $message);
    
} catch (Exception $e) {
    error_log("Send follow request error: " . $e->getMessage());
    Response::error('Internal server error', 500);
}
?>