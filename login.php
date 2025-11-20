<?php
require_once 'config/database.php';

$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    sendResponse(false, null, 'Invalid JSON data');
}

$email = $data['email'] ?? '';
$password = $data['password'] ?? '';

if (empty($email) || empty($password)) {
    sendResponse(false, null, 'Email and password are required');
}

try {
    // Get user by email
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        sendResponse(false, null, 'Invalid email or password');
    }

    // Update last seen
    $stmt = $pdo->prepare("UPDATE users SET is_online = TRUE, last_seen = NOW() WHERE id = ?");
    $stmt->execute([$user['id']]);

    // Remove password from response
    unset($user['password']);

    // Create access token
    $accessToken = base64_encode($user['id'] . ':' . time() . ':' . uniqid());

    sendResponse(true, [
        'user' => $user,
        'access_token' => $accessToken,
        'expires_at' => date('Y-m-d H:i:s', time() + 3600) // 1 hour
    ]);

} catch (PDOException $e) {
    sendResponse(false, null, 'Login failed: ' . $e->getMessage());
}
?>
