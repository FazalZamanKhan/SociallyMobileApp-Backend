<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

$user = Auth::requireAuth();
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['call_id'])) {
    Response::error('Call ID required');
}

$callId = (int)$input['call_id'];
$status = $input['status'] ?? 'ended'; // 'ended', 'missed', 'declined'

if (!in_array($status, ['ended', 'missed', 'declined'])) {
    $status = 'ended';
}

try {
    $db = getDB();
    
    // Get call session
    $callStmt = $db->prepare("
        SELECT caller_id, receiver_id, started_at, status as current_status 
        FROM call_sessions 
        WHERE id = ?
    ");
    $callStmt->bind_param('i', $callId);
    $callStmt->execute();
    $callResult = $callStmt->get_result();
    
    if ($callResult->num_rows === 0) {
        Response::error('Call session not found', 404);
    }
    
    $call = $callResult->fetch_assoc();
    
    // Check if user is part of this call
    if ($call['caller_id'] != $user['user_id'] && $call['receiver_id'] != $user['user_id']) {
        Response::error('You are not part of this call', 403);
    }
    
    // Calculate duration
    $duration = 0;
    if ($call['current_status'] === 'answered' && $status === 'ended') {
        $startTime = strtotime($call['started_at']);
        $duration = time() - $startTime;
    }
    
    // Update call session
    $updateStmt = $db->prepare("
        UPDATE call_sessions 
        SET status = ?, ended_at = NOW(), duration = ? 
        WHERE id = ?
    ");
    $updateStmt->bind_param('sii', $status, $duration, $callId);
    
    if ($updateStmt->execute()) {
        // Get the other participant for notification
        $otherUserId = ($call['caller_id'] == $user['user_id']) ? $call['receiver_id'] : $call['caller_id'];
        
        $userStmt = $db->prepare("SELECT username FROM users WHERE id = ?");
        $userStmt->bind_param('i', $user['user_id']);
        $userStmt->execute();
        $username = $userStmt->get_result()->fetch_assoc()['username'];
        
        // Send notification based on status
        if ($status === 'declined') {
            Notification::send(
                $otherUserId,
                'call',
                'Call Declined',
                "$username declined your call",
                ['call_id' => $callId, 'status' => $status]
            );
        } elseif ($status === 'ended') {
            Notification::send(
                $otherUserId,
                'call',
                'Call Ended',
                "Call ended",
                ['call_id' => $callId, 'status' => $status, 'duration' => $duration]
            );
        }
        
        Response::success([
            'call_id' => $callId,
            'status' => $status,
            'duration' => $duration
        ], 'Call ended successfully');
    } else {
        Response::error('Failed to end call');
    }
    
} catch (Exception $e) {
    error_log("End call error: " . $e->getMessage());
    Response::error('Internal server error', 500);
}
?>