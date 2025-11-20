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

$requiredFields = ['receiver_id'];
$missing = Validator::validateRequired($requiredFields, $input);

if (!empty($missing)) {
    Response::error('Missing required fields: ' . implode(', ', $missing));
}

$receiverId = (int)$input['receiver_id'];
$content = isset($input['content']) ? trim($input['content']) : '';
$mediaUrl = isset($input['media_url']) ? trim($input['media_url']) : '';
$mediaType = isset($input['media_type']) ? $input['media_type'] : 'text';
$messageType = isset($input['message_type']) ? $input['message_type'] : 'normal';

// Validate that we have either content or media
if (empty($content) && empty($mediaUrl)) {
    Response::error('Message must have either content or media');
}

// Validate receiver exists
if ($receiverId == $user['user_id']) {
    Response::error('Cannot send message to yourself');
}

// Validate message type
if (!in_array($messageType, ['normal', 'vanish'])) {
    $messageType = 'normal';
}

// Validate media type
if (!in_array($mediaType, ['text', 'image', 'video', 'audio', 'file'])) {
    $mediaType = 'text';
}

try {
    $db = getDB();
    
    // Check if receiver exists
    $receiverStmt = $db->prepare("SELECT id FROM users WHERE id = ?");
    $receiverStmt->bind_param('i', $receiverId);
    $receiverStmt->execute();
    
    if ($receiverStmt->get_result()->num_rows === 0) {
        Response::error('Receiver not found', 404);
    }
    
    // Calculate expiry for vanish messages (expire when read and chat closed)
    $expiresAt = null;
    if ($messageType === 'vanish') {
        // Set initial expiry to 30 days, will be updated when message is read
        $expiresAt = date('Y-m-d H:i:s', time() + (30 * 24 * 60 * 60));
    }
    
    // Insert message
    $stmt = $db->prepare("
        INSERT INTO messages (sender_id, receiver_id, content, media_url, media_type, message_type, expires_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param('iisssss', $user['user_id'], $receiverId, $content, $mediaUrl, $mediaType, $messageType, $expiresAt);
    
    if ($stmt->execute()) {
        $messageId = $db->insert_id();
        
        // Get the created message with sender details
        $messageStmt = $db->prepare("
            SELECT 
                m.id, m.content, m.media_url, m.media_type, m.message_type, 
                m.is_edited, m.is_read, m.expires_at, m.created_at,
                u.id as sender_id, u.username, u.profile_picture
            FROM messages m
            JOIN users u ON m.sender_id = u.id
            WHERE m.id = ?
        ");
        $messageStmt->bind_param('i', $messageId);
        $messageStmt->execute();
        $result = $messageStmt->get_result();
        $message = $result->fetch_assoc();
        
        $message['sender'] = [
            'id' => $message['sender_id'],
            'username' => $message['username'],
            'profile_picture' => $message['profile_picture']
        ];
        unset($message['sender_id'], $message['username'], $message['profile_picture']);
        
        // Send push notification to receiver
        $senderStmt = $db->prepare("SELECT username FROM users WHERE id = ?");
        $senderStmt->bind_param('i', $user['user_id']);
        $senderStmt->execute();
        $senderUsername = $senderStmt->get_result()->fetch_assoc()['username'];
        
        $notificationTitle = 'New Message';
        $notificationBody = $content ? $content : 'Sent you a ' . $mediaType;
        if ($messageType === 'vanish') {
            $notificationBody = '🔥 ' . $notificationBody;
        }
        
        Notification::send(
            $receiverId,
            'message',
            $notificationTitle,
            "$senderUsername: $notificationBody",
            [
                'message_id' => $messageId,
                'sender_id' => $user['user_id'],
                'message_type' => $messageType
            ]
        );
        
        Response::success(['message' => $message], 'Message sent successfully');
    } else {
        Response::error('Failed to send message');
    }
    
} catch (Exception $e) {
    error_log("Send message error: " . $e->getMessage());
    Response::error('Internal server error', 500);
}
?>