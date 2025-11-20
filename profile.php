<?php
require_once 'config/database.php';

$userId = $_GET['id'] ?? null;

if (!$userId) {
    sendResponse(false, null, 'User ID is required');
}

try {
    $stmt = $pdo->prepare("
        SELECT
            u.id, u.username, u.email, u.first_name, u.last_name,
            u.bio, u.profile_picture, u.cover_photo, u.is_online,
            u.last_seen, u.is_private, u.created_at,
            (SELECT COUNT(*) FROM followers WHERE user_id = u.id) as followers_count,
            (SELECT COUNT(*) FROM followers WHERE follower_id = u.id) as following_count,
            (SELECT COUNT(*) FROM posts WHERE user_id = u.id) as posts_count
        FROM users u
        WHERE u.id = ?
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        sendResponse(false, null, 'User not found');
    }

    sendResponse(true, $user);

} catch (PDOException $e) {
    sendResponse(false, null, 'Failed to fetch profile: ' . $e->getMessage());
}
?>
