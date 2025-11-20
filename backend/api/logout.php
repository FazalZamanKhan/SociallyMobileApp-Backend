<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

$user = Auth::requireAuth();

try {
    $db = getDB();
    
    // Update user offline status
    $stmt = $db->prepare("UPDATE users SET is_online = 0, last_seen = NOW() WHERE id = ?");
    $stmt->bind_param('i', $user['user_id']);
    $stmt->execute();
    
    // Get the current token from header
    $headers = getallheaders();
    $authHeader = isset($headers['Authorization']) ? $headers['Authorization'] : 
                 (isset($headers['authorization']) ? $headers['authorization'] : null);
    
    if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        $currentToken = $matches[1];
        
        // Remove the current session
        $deleteStmt = $db->prepare("DELETE FROM user_sessions WHERE session_token = ?");
        $deleteStmt->bind_param('s', $currentToken);
        $deleteStmt->execute();
    }
    
    Response::success([], 'Logout successful');
    
} catch (Exception $e) {
    error_log("Logout error: " . $e->getMessage());
    Response::error('Internal server error', 500);
}
?>