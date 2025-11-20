<?php
require_once 'config/database.php';

$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    sendResponse(false, null, 'Invalid JSON data');
}

$username = $data['username'] ?? '';
$email = $data['email'] ?? '';
$password = $data['password'] ?? '';
$firstName = $data['first_name'] ?? '';
$lastName = $data['last_name'] ?? '';

if (empty($username) || empty($email) || empty($password)) {
    sendResponse(false, null, 'Username, email, and password are required');
}

// Check if email already exists
try {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? OR username = ?");
    $stmt->execute([$email, $username]);

    if ($stmt->fetch()) {
        sendResponse(false, null, 'Email or username already exists');
    }

    // Hash password
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    // Insert new user
    $stmt = $pdo->prepare("INSERT INTO users (username, email, password, first_name, last_name) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$username, $email, $hashedPassword, $firstName, $lastName]);

    $userId = $pdo->lastInsertId();

    // Get user data
    $stmt = $pdo->prepare("SELECT id, username, email, first_name, last_name, created_at FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    // Create access token (simple token for demo)
    $accessToken = base64_encode($user['id'] . ':' . time() . ':' . uniqid());

    sendResponse(true, [
        'user' => $user,
        'access_token' => $accessToken,
        'expires_at' => date('Y-m-d H:i:s', time() + 3600) // 1 hour
    ]);

} catch (PDOException $e) {
    sendResponse(false, null, 'Registration failed: ' . $e->getMessage());
}
?>
