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

$requiredFields = ['receiver_id', 'call_type'];
$missing = Validator::validateRequired($requiredFields, $input);

if (!empty($missing)) {
    Response::error('Missing required fields: ' . implode(', ', $missing));
}

$receiverId = (int)$input['receiver_id'];
$callType = $input['call_type']; // 'audio' or 'video'

if (!in_array($callType, ['audio', 'video'])) {
    Response::error('Invalid call type. Use "audio" or "video"');
}

if ($receiverId == $user['user_id']) {
    Response::error('Cannot call yourself');
}

try {
    $db = getDB();
    
    // Check if receiver exists
    $receiverStmt = $db->prepare("SELECT username FROM users WHERE id = ?");
    $receiverStmt->bind_param('i', $receiverId);
    $receiverStmt->execute();
    $receiverResult = $receiverStmt->get_result();
    
    if ($receiverResult->num_rows === 0) {
        Response::error('Receiver not found', 404);
    }
    
    $receiver = $receiverResult->fetch_assoc();
    
    // Generate unique channel name
    $channelName = 'call_' . min($user['user_id'], $receiverId) . '_' . max($user['user_id'], $receiverId) . '_' . time();
    
    // Create call session
    $stmt = $db->prepare("
        INSERT INTO call_sessions (channel_name, caller_id, receiver_id, call_type, status) 
        VALUES (?, ?, ?, ?, 'initiated')
    ");
    $stmt->bind_param('siis', $channelName, $user['user_id'], $receiverId, $callType);
    
    if ($stmt->execute()) {
        $callId = $db->insert_id();
        
        // Get caller info
        $callerStmt = $db->prepare("SELECT username FROM users WHERE id = ?");
        $callerStmt->bind_param('i', $user['user_id']);
        $callerStmt->execute();
        $callerUsername = $callerStmt->get_result()->fetch_assoc()['username'];
        
        // Send notification to receiver
        $notificationTitle = ucfirst($callType) . ' Call';
        $notificationBody = "$callerUsername is calling you";
        
        Notification::send(
            $receiverId,
            'call',
            $notificationTitle,
            $notificationBody,
            [
                'call_id' => $callId,
                'channel_name' => $channelName,
                'caller_id' => $user['user_id'],
                'call_type' => $callType
            ]
        );
        
        Response::success([
            'call_id' => $callId,
            'channel_name' => $channelName,
            'call_type' => $callType,
            'receiver' => $receiver,
            'status' => 'initiated'
        ], 'Call initiated successfully');
    } else {
        Response::error('Failed to initiate call');
    }
    
} catch (Exception $e) {
    error_log("Initiate call error: " . $e->getMessage());
    Response::error('Internal server error', 500);
}
?>