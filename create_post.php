<?php
require_once 'config/database.php';

$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    sendResponse(false, null, 'Invalid JSON data');
}

$content = $data['content'] ?? '';
$mediaUrl = $data['media_url'] ?? '';
$mediaType = $data['media_type'] ?? '';
$userId = 1; // In real app, get from token

if (empty($content) && empty($mediaUrl)) {
    sendResponse(false, null, 'Content or media is required');
}

try {
    $stmt = $pdo->prepare("INSERT INTO posts (user_id, content, media_url, media_type) VALUES (?, ?, ?, ?)");
    $stmt->execute([$userId, $content, $mediaUrl, $mediaType]);

    $postId = $pdo->lastInsertId();

    // Get the created post with user data
    $stmt = $pdo->prepare("
        SELECT p.*, u.username, u.first_name, u.last_name, u.profile_picture
        FROM posts p
        JOIN users u ON p.user_id = u.id
        WHERE p.id = ?
    ");
    $stmt->execute([$postId]);
    $post = $stmt->fetch();

    sendResponse(true, $post);

} catch (PDOException $e) {
    sendResponse(false, null, 'Failed to create post: ' . $e->getMessage());
}
?>
