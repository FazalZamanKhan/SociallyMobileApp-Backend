<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

$user = Auth::requireAuth();
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['is_online'])) {
    Response::error('Online status required');
}

$isOnline = (bool)$input['is_online'];

try {
    $db = getDB();
    
    if ($isOnline) {
        $stmt = $db->prepare("UPDATE users SET is_online = 1, last_seen = NOW() WHERE id = ?");
    } else {
        $stmt = $db->prepare("UPDATE users SET is_online = 0, last_seen = NOW() WHERE id = ?");
    }
    
    $stmt->bind_param('i', $user['user_id']);
    
    if ($stmt->execute()) {
        Response::success([
            'is_online' => $isOnline,
            'last_seen' => date('Y-m-d H:i:s')
        ], 'Status updated successfully');
    } else {
        Response::error('Failed to update status');
    }
    
} catch (Exception $e) {
    error_log("User status error: " . $e->getMessage());
    Response::error('Internal server error', 500);
}
?>