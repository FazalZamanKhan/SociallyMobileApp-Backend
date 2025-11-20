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
    Response::error('Cannot unfollow yourself');
}

try {
    $db = getDB();
    
    // Check if currently following
    $followStmt = $db->prepare("SELECT id FROM followers WHERE user_id = ? AND follower_id = ?");
    $followStmt->bind_param('ii', $targetUserId, $user['user_id']);
    $followStmt->execute();
    
    if ($followStmt->get_result()->num_rows === 0) {
        Response::error('Not currently following this user');
    }
    
    $db->begin_transaction();
    
    try {
        // Remove from followers table
        $deleteFollowStmt = $db->prepare("DELETE FROM followers WHERE user_id = ? AND follower_id = ?");
        $deleteFollowStmt->bind_param('ii', $targetUserId, $user['user_id']);
        $deleteFollowStmt->execute();
        
        // Update follow request to rejected (so user can't immediately follow again without sending new request)
        $updateRequestStmt = $db->prepare("
            UPDATE follow_requests 
            SET status = 'rejected', updated_at = NOW() 
            WHERE sender_id = ? AND receiver_id = ? AND status = 'accepted'
        ");
        $updateRequestStmt->bind_param('ii', $user['user_id'], $targetUserId);
        $updateRequestStmt->execute();
        
        $db->commit();
        
        Response::success(['is_following' => false], 'Successfully unfollowed user');
        
    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }
    
} catch (Exception $e) {
    error_log("Unfollow error: " . $e->getMessage());
    Response::error('Internal server error', 500);
}
?>