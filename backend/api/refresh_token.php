<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['refresh_token'])) {
    Response::error('Refresh token required');
}

$refreshToken = $input['refresh_token'];

try {
    $db = getDB();
    
    // Verify refresh token
    $payload = JWT::decode($refreshToken);
    
    if (!$payload || !isset($payload['user_id']) || $payload['type'] !== 'refresh') {
        Response::error('Invalid refresh token', 401);
    }
    
    // Check if refresh token exists in database
    $stmt = $db->prepare("SELECT user_id FROM user_sessions WHERE refresh_token = ? AND expires_at > NOW()");
    $stmt->bind_param('s', $refreshToken);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        Response::error('Invalid or expired refresh token', 401);
    }
    
    $userId = $payload['user_id'];
    
    // Generate new tokens
    $newTokens = Auth::generateTokens($userId);
    
    // Update session with new tokens
    $updateStmt = $db->prepare("UPDATE user_sessions SET session_token = ?, refresh_token = ?, expires_at = ? WHERE refresh_token = ?");
    $updateStmt->bind_param('ssss', $newTokens['access_token'], $newTokens['refresh_token'], $newTokens['expires_at'], $refreshToken);
    $updateStmt->execute();
    
    Response::success([
        'access_token' => $newTokens['access_token'],
        'refresh_token' => $newTokens['refresh_token'],
        'expires_at' => $newTokens['expires_at']
    ], 'Token refreshed successfully');
    
} catch (Exception $e) {
    error_log("Refresh token error: " . $e->getMessage());
    Response::error('Invalid refresh token', 401);
}
?>