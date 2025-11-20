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

$requiredFields = ['media_url', 'media_type'];
$missing = Validator::validateRequired($requiredFields, $input);

if (!empty($missing)) {
    Response::error('Missing required fields: ' . implode(', ', $missing));
}

$mediaUrl = trim($input['media_url']);
$mediaType = $input['media_type'];
$caption = isset($input['caption']) ? trim($input['caption']) : '';

// Validate media type
if (!in_array($mediaType, ['image', 'video'])) {
    Response::error('Invalid media type. Only image and video are allowed for stories');
}

// Validate caption length
if (strlen($caption) > 200) {
    Response::error('Caption is too long (max 200 characters)');
}

try {
    $db = getDB();
    
    // Calculate expiry time (24 hours from now)
    $expiresAt = date('Y-m-d H:i:s', time() + STORY_EXPIRY_TIME);
    
    // Create story
    $stmt = $db->prepare("
        INSERT INTO stories (user_id, media_url, media_type, caption, expires_at) 
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->bind_param('issss', $user['user_id'], $mediaUrl, $mediaType, $caption, $expiresAt);
    
    if ($stmt->execute()) {
        $storyId = $db->insert_id();
        
        // Get the created story with user details
        $storyStmt = $db->prepare("
            SELECT 
                s.id, s.media_url, s.media_type, s.caption, s.views_count, 
                s.expires_at, s.created_at,
                u.id as user_id, u.username, u.first_name, u.last_name, u.profile_picture
            FROM stories s
            JOIN users u ON s.user_id = u.id
            WHERE s.id = ?
        ");
        $storyStmt->bind_param('i', $storyId);
        $storyStmt->execute();
        $result = $storyStmt->get_result();
        $story = $result->fetch_assoc();
        
        $story['is_viewed'] = false; // New story, not viewed yet
        $story['user'] = [
            'id' => $story['user_id'],
            'username' => $story['username'],
            'first_name' => $story['first_name'],
            'last_name' => $story['last_name'],
            'profile_picture' => $story['profile_picture']
        ];
        unset($story['user_id'], $story['username'], $story['first_name'], $story['last_name']);
        
        Response::success(['story' => $story], 'Story created successfully');
    } else {
        Response::error('Failed to create story');
    }
    
} catch (Exception $e) {
    error_log("Create story error: " . $e->getMessage());
    Response::error('Internal server error', 500);
}
?>