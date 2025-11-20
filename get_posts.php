<?php
require_once 'config/database.php';

$page = $_GET['page'] ?? 1;
$limit = $_GET['limit'] ?? 20;
$offset = ($page - 1) * $limit;

try {
    $stmt = $pdo->prepare("
        SELECT
            p.*,
            u.username, u.first_name, u.last_name, u.profile_picture,
            (SELECT COUNT(*) FROM post_likes WHERE post_id = p.id) as likes_count,
            0 as comments_count,
            FALSE as is_liked
        FROM posts p
        JOIN users u ON p.user_id = u.id
        ORDER BY p.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$limit, $offset]);
    $posts = $stmt->fetchAll();

    // Format posts for response
    $formattedPosts = [];
    foreach ($posts as $post) {
        $formattedPosts[] = [
            'id' => (int)$post['id'],
            'user_id' => (int)$post['user_id'],
            'content' => $post['content'],
            'media_url' => $post['media_url'],
            'media_type' => $post['media_type'],
            'likes_count' => (int)$post['likes_count'],
            'comments_count' => (int)$post['comments_count'],
            'created_at' => $post['created_at'],
            'is_liked' => (bool)$post['is_liked'],
            'user' => [
                'id' => (int)$post['user_id'],
                'username' => $post['username'],
                'first_name' => $post['first_name'],
                'last_name' => $post['last_name'],
                'profile_picture' => $post['profile_picture']
            ]
        ];
    }

    sendResponse(true, $formattedPosts);

} catch (PDOException $e) {
    sendResponse(false, null, 'Failed to fetch posts: ' . $e->getMessage());
}
?>
