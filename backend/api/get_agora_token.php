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

$requiredFields = ['channel_name', 'call_type'];
$missing = Validator::validateRequired($requiredFields, $input);

if (!empty($missing)) {
    Response::error('Missing required fields: ' . implode(', ', $missing));
}

$channelName = $input['channel_name'];
$callType = $input['call_type']; // 'audio' or 'video'
$uid = $input['uid'] ?? $user['user_id'];

if (!in_array($callType, ['audio', 'video'])) {
    Response::error('Invalid call type. Use "audio" or "video"');
}

// Note: This is a basic implementation. In production, you should use Agora's official token generation
// You'll need to implement the actual Agora token generation using their SDK or REST API

try {
    // For now, we'll return a mock token. In production, integrate with Agora's token generation
    $token = generateAgoraToken($channelName, $uid);
    
    Response::success([
        'token' => $token,
        'channel_name' => $channelName,
        'uid' => $uid,
        'app_id' => AGORA_APP_ID,
        'call_type' => $callType,
        'expires_at' => time() + 3600 // Token valid for 1 hour
    ], 'Agora token generated successfully');
    
} catch (Exception $e) {
    error_log("Get Agora token error: " . $e->getMessage());
    Response::error('Failed to generate token', 500);
}

// Mock token generation function - replace with actual Agora implementation
function generateAgoraToken($channelName, $uid) {
    // This is a placeholder. In production, use Agora's token generation
    return base64_encode($channelName . ':' . $uid . ':' . time());
}
?>