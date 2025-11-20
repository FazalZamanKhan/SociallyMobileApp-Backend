<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

$user = Auth::requireAuth();
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    Response::error('Invalid JSON input');
}

$content = isset($input['content']) ? trim($input['content']) : '';
$mediaUrl = isset($input['media_url']) ? trim($input['media_url']) : '';
$mediaType = isset($input['media_type']) ? $input['media_type'] : null;
$location = isset($input['location']) ? Validator::sanitizeString($input['location']) : '';
$privacy = isset($input['privacy']) ? $input['privacy'] : 'public';

// Validate that we have either content or media
if (empty($content) && empty($mediaUrl)) {
    Response::error('Post must have either content or media');
}

// Validate privacy setting
if (!in_array($privacy, ['public', 'private', 'followers'])) {
    $privacy = 'public';
}

// Validate media type if provided
if (!empty($mediaType) && !in_array($mediaType, ['image', 'video', 'audio'])) {
    Response::error('Invalid media type');
}

try {
    $db = getDB();
    
    // Create post
    $stmt = $db->prepare("
        INSERT INTO posts (user_id, content, media_url, media_type, location, privacy) 
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param('isssss', $user['user_id'], $content, $mediaUrl, $mediaType, $location, $privacy);
    
    if ($stmt->execute()) {
        $postId = $db->insert_id();
        
        // Get the created post with user details
        $postStmt = $db->prepare("
            SELECT 
                p.id, p.content, p.media_url, p.media_type, p.location, p.privacy,
                p.likes_count, p.comments_count, p.created_at,
                u.id as user_id, u.username, u.first_name, u.last_name, u.profile_picture
            FROM posts p
            JOIN users u ON p.user_id = u.id
            WHERE p.id = ?
        ");
        $postStmt->bind_param('i', $postId);
        $postStmt->execute();
        $result = $postStmt->get_result();
        $post = $result->fetch_assoc();
        
        $post['is_liked'] = false; // New post, not liked yet
        $post['user'] = [
            'id' => $post['user_id'],
            'username' => $post['username'],
            'first_name' => $post['first_name'],
            'last_name' => $post['last_name'],
            'profile_picture' => $post['profile_picture']
        ];
        unset($post['user_id'], $post['username'], $post['first_name'], $post['last_name']);
        
        Response::success(['post' => $post], 'Post created successfully');
    } else {
        Response::error('Failed to create post');
    }
    
} catch (Exception $e) {
    error_log("Create post error: " . $e->getMessage());
    Response::error('Internal server error', 500);
}
?>