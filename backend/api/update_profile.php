<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    Response::error('Method not allowed', 405);
}

$user = Auth::requireAuth();
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    Response::error('Invalid JSON input');
}

try {
    $db = getDB();
    
    $updateFields = [];
    $updateValues = [];
    $types = '';
    
    // Handle updatable fields
    $allowedFields = ['first_name', 'last_name', 'bio', 'is_private'];
    
    foreach ($allowedFields as $field) {
        if (isset($input[$field])) {
            $updateFields[] = "$field = ?";
            if ($field === 'is_private') {
                $updateValues[] = (bool)$input[$field] ? 1 : 0;
                $types .= 'i';
            } else {
                $updateValues[] = Validator::sanitizeString($input[$field]);
                $types .= 's';
            }
        }
    }
    
    // Handle username separately with validation
    if (isset($input['username'])) {
        $newUsername = Validator::sanitizeString($input['username']);
        
        if (!Validator::validateUsername($newUsername)) {
            Response::error('Invalid username format');
        }
        
        // Check if username is already taken
        $checkStmt = $db->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $checkStmt->bind_param('si', $newUsername, $user['user_id']);
        $checkStmt->execute();
        
        if ($checkStmt->get_result()->num_rows > 0) {
            Response::error('Username already taken');
        }
        
        $updateFields[] = "username = ?";
        $updateValues[] = $newUsername;
        $types .= 's';
    }
    
    if (empty($updateFields)) {
        Response::error('No fields to update');
    }
    
    // Add user_id for WHERE clause
    $updateValues[] = $user['user_id'];
    $types .= 'i';
    
    $sql = "UPDATE users SET " . implode(', ', $updateFields) . ", updated_at = NOW() WHERE id = ?";
    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$updateValues);
    
    if ($stmt->execute()) {
        // Get updated user data
        $userStmt = $db->prepare("
            SELECT 
                id, username, email, first_name, last_name, bio, 
                profile_picture, cover_photo, is_online, last_seen, 
                is_private, created_at, updated_at
            FROM users 
            WHERE id = ?
        ");
        $userStmt->bind_param('i', $user['user_id']);
        $userStmt->execute();
        $result = $userStmt->get_result();
        $updatedUser = $result->fetch_assoc();
        
        Response::success(['user' => $updatedUser], 'Profile updated successfully');
    } else {
        Response::error('Failed to update profile');
    }
    
} catch (Exception $e) {
    error_log("Update profile error: " . $e->getMessage());
    Response::error('Internal server error', 500);
}
?>