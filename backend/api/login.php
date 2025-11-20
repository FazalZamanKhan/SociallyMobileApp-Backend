<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    Response::error('Invalid JSON input');
}

// Validate required fields
$requiredFields = ['email', 'password'];
$missing = Validator::validateRequired($requiredFields, $input);

if (!empty($missing)) {
    Response::error('Missing required fields: ' . implode(', ', $missing));
}

$email = Validator::sanitizeString($input['email']);
$password = $input['password'];
$deviceId = $input['device_id'] ?? '';
$deviceType = $input['device_type'] ?? 'android';

if (!Validator::validateEmail($email)) {
    Response::error('Invalid email address');
}

try {
    $db = getDB();
    
    // Get user by email
    $stmt = $db->prepare("SELECT id, username, email, password, first_name, last_name, bio, profile_picture, cover_photo, is_private, created_at FROM users WHERE email = ?");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        Response::error('Invalid credentials', 401);
    }
    
    $user = $result->fetch_assoc();
    
    // Verify password
    if (!Auth::verifyPassword($password, $user['password'])) {
        Response::error('Invalid credentials', 401);
    }
    
    // Update user online status
    $updateStatusStmt = $db->prepare("UPDATE users SET is_online = 1, last_seen = NOW() WHERE id = ?");
    $updateStatusStmt->bind_param('i', $user['id']);
    $updateStatusStmt->execute();
    
    // Generate tokens
    $tokens = Auth::generateTokens($user['id']);
    
    // Store session (remove old sessions for this device if any)
    $db->prepare("DELETE FROM user_sessions WHERE user_id = ? AND device_id = ?")->execute([$user['id'], $deviceId]);
    
    $sessionStmt = $db->prepare("INSERT INTO user_sessions (user_id, session_token, refresh_token, device_id, device_type, expires_at) VALUES (?, ?, ?, ?, ?, ?)");
    $sessionStmt->bind_param('isssss', $user['id'], $tokens['access_token'], $tokens['refresh_token'], $deviceId, $deviceType, $tokens['expires_at']);
    $sessionStmt->execute();
    
    // Remove password from response
    unset($user['password']);
    
    // Get additional user stats
    $statsStmt = $db->prepare("
        SELECT 
            (SELECT COUNT(*) FROM followers WHERE user_id = ?) as followers_count,
            (SELECT COUNT(*) FROM followers WHERE follower_id = ?) as following_count,
            (SELECT COUNT(*) FROM posts WHERE user_id = ?) as posts_count
    ");
    $statsStmt->bind_param('iii', $user['id'], $user['id'], $user['id']);
    $statsStmt->execute();
    $statsResult = $statsStmt->get_result();
    $stats = $statsResult->fetch_assoc();
    
    $user = array_merge($user, $stats);
    
    Response::success([
        'user' => $user,
        'access_token' => $tokens['access_token'],
        'refresh_token' => $tokens['refresh_token'],
        'expires_at' => $tokens['expires_at']
    ], 'Login successful');
    
} catch (Exception $e) {
    error_log("Login error: " . $e->getMessage());
    Response::error('Internal server error', 500);
}
?>