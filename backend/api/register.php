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
$requiredFields = ['username', 'email', 'password'];
$missing = Validator::validateRequired($requiredFields, $input);

if (!empty($missing)) {
    Response::error('Missing required fields: ' . implode(', ', $missing));
}

$username = Validator::sanitizeString($input['username']);
$email = Validator::sanitizeString($input['email']);
$password = $input['password'];
$firstName = isset($input['first_name']) ? Validator::sanitizeString($input['first_name']) : '';
$lastName = isset($input['last_name']) ? Validator::sanitizeString($input['last_name']) : '';
$bio = isset($input['bio']) ? Validator::sanitizeString($input['bio']) : '';

// Validate input
if (!Validator::validateUsername($username)) {
    Response::error('Username must be 3-30 characters long and contain only letters, numbers, and underscores');
}

if (!Validator::validateEmail($email)) {
    Response::error('Invalid email address');
}

if (!Validator::validatePassword($password)) {
    Response::error('Password must be at least 6 characters long');
}

try {
    $db = getDB();
    
    // Check if username or email already exists
    $stmt = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
    $stmt->bind_param('ss', $username, $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        Response::error('Username or email already exists');
    }
    
    // Hash password
    $hashedPassword = Auth::hashPassword($password);
    
    // Insert new user
    $stmt = $db->prepare("INSERT INTO users (username, email, password, first_name, last_name, bio) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('ssssss', $username, $email, $hashedPassword, $firstName, $lastName, $bio);
    
    if ($stmt->execute()) {
        $userId = $db->insert_id();
        
        // Generate tokens
        $tokens = Auth::generateTokens($userId);
        
        // Store session
        $deviceId = $input['device_id'] ?? '';
        $deviceType = $input['device_type'] ?? 'android';
        
        $sessionStmt = $db->prepare("INSERT INTO user_sessions (user_id, session_token, refresh_token, device_id, device_type, expires_at) VALUES (?, ?, ?, ?, ?, ?)");
        $sessionStmt->bind_param('isssss', $userId, $tokens['access_token'], $tokens['refresh_token'], $deviceId, $deviceType, $tokens['expires_at']);
        $sessionStmt->execute();
        
        // Get user data
        $userStmt = $db->prepare("SELECT id, username, email, first_name, last_name, bio, profile_picture, cover_photo, created_at FROM users WHERE id = ?");
        $userStmt->bind_param('i', $userId);
        $userStmt->execute();
        $userResult = $userStmt->get_result();
        $userData = $userResult->fetch_assoc();
        
        Response::success([
            'user' => $userData,
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'expires_at' => $tokens['expires_at']
        ], 'Registration successful');
        
    } else {
        Response::error('Registration failed');
    }
    
} catch (Exception $e) {
    error_log("Registration error: " . $e->getMessage());
    Response::error('Internal server error', 500);
}
?>