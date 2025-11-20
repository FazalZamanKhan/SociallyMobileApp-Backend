<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

$user = Auth::requireAuth();
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    Response::error('Invalid JSON input');
}

$requiredFields = ['request_id', 'action'];
$missing = Validator::validateRequired($requiredFields, $input);

if (!empty($missing)) {
    Response::error('Missing required fields: ' . implode(', ', $missing));
}

$requestId = (int)$input['request_id'];
$action = $input['action']; // 'accept' or 'reject'

if (!in_array($action, ['accept', 'reject'])) {
    Response::error('Invalid action. Use "accept" or "reject"');
}

try {
    $db = getDB();
    
    // Get follow request details
    $requestStmt = $db->prepare("
        SELECT fr.id, fr.sender_id, fr.receiver_id, fr.status,
               u.username as sender_username
        FROM follow_requests fr
        JOIN users u ON fr.sender_id = u.id
        WHERE fr.id = ? AND fr.receiver_id = ? AND fr.status = 'pending'
    ");
    $requestStmt->bind_param('ii', $requestId, $user['user_id']);
    $requestStmt->execute();
    $requestResult = $requestStmt->get_result();
    
    if ($requestResult->num_rows === 0) {
        Response::error('Follow request not found or already processed', 404);
    }
    
    $request = $requestResult->fetch_assoc();
    $senderId = $request['sender_id'];
    $senderUsername = $request['sender_username'];
    
    $db->begin_transaction();
    
    try {
        if ($action === 'accept') {
            // Update request status
            $updateStmt = $db->prepare("UPDATE follow_requests SET status = 'accepted', updated_at = NOW() WHERE id = ?");
            $updateStmt->bind_param('i', $requestId);
            $updateStmt->execute();
            
            // Add to followers table (check if not already exists)
            $checkFollowStmt = $db->prepare("SELECT id FROM followers WHERE user_id = ? AND follower_id = ?");
            $checkFollowStmt->bind_param('ii', $user['user_id'], $senderId);
            $checkFollowStmt->execute();
            
            if ($checkFollowStmt->get_result()->num_rows === 0) {
                $addFollowStmt = $db->prepare("INSERT INTO followers (user_id, follower_id) VALUES (?, ?)");
                $addFollowStmt->bind_param('ii', $user['user_id'], $senderId);
                $addFollowStmt->execute();
            }
            
            // Send notification to requester
            $currentUserStmt = $db->prepare("SELECT username FROM users WHERE id = ?");
            $currentUserStmt->bind_param('i', $user['user_id']);
            $currentUserStmt->execute();
            $currentUsername = $currentUserStmt->get_result()->fetch_assoc()['username'];
            
            Notification::send(
                $senderId,
                'follow_accepted',
                'Follow Request Accepted',
                "$currentUsername accepted your follow request",
                ['user_id' => $user['user_id']]
            );
            
            $message = 'Follow request accepted';
            
        } else { // reject
            // Update request status
            $updateStmt = $db->prepare("UPDATE follow_requests SET status = 'rejected', updated_at = NOW() WHERE id = ?");
            $updateStmt->bind_param('i', $requestId);
            $updateStmt->execute();
            
            $message = 'Follow request rejected';
        }
        
        $db->commit();
        
        Response::success([
            'request_id' => $requestId,
            'action' => $action,
            'sender_id' => $senderId,
            'sender_username' => $senderUsername
        ], $message);
        
    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }
    
} catch (Exception $e) {
    error_log("Respond follow request error: " . $e->getMessage());
    Response::error('Internal server error', 500);
}
?>