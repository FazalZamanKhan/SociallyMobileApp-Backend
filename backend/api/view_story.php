<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

$user = Auth::requireAuth();
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['story_id'])) {
    Response::error('Story ID required');
}

$storyId = (int)$input['story_id'];

try {
    $db = getDB();
    
    // Check if story exists and is still active
    $storyStmt = $db->prepare("
        SELECT user_id, expires_at 
        FROM stories 
        WHERE id = ? AND expires_at > NOW()
    ");
    $storyStmt->bind_param('i', $storyId);
    $storyStmt->execute();
    $storyResult = $storyStmt->get_result();
    
    if ($storyResult->num_rows === 0) {
        Response::error('Story not found or expired', 404);
    }
    
    $story = $storyResult->fetch_assoc();
    $storyOwnerId = $story['user_id'];
    
    // Check if user already viewed this story
    $viewStmt = $db->prepare("SELECT id FROM story_views WHERE story_id = ? AND viewer_id = ?");
    $viewStmt->bind_param('ii', $storyId, $user['user_id']);
    $viewStmt->execute();
    $viewResult = $viewStmt->get_result();
    
    if ($viewResult->num_rows === 0) {
        // Add new view record (this will trigger the view count update via trigger)
        $insertViewStmt = $db->prepare("INSERT INTO story_views (story_id, viewer_id) VALUES (?, ?)");
        $insertViewStmt->bind_param('ii', $storyId, $user['user_id']);
        $insertViewStmt->execute();
        
        // Send notification to story owner (if not own story)
        if ($storyOwnerId != $user['user_id']) {
            $userStmt = $db->prepare("SELECT username FROM users WHERE id = ?");
            $userStmt->bind_param('i', $user['user_id']);
            $userStmt->execute();
            $viewerUsername = $userStmt->get_result()->fetch_assoc()['username'];
            
            Notification::send(
                $storyOwnerId,
                'story_view',
                'Story Viewed',
                "$viewerUsername viewed your story",
                ['story_id' => $storyId, 'user_id' => $user['user_id']]
            );
        }
        
        $message = 'Story view recorded';
    } else {
        $message = 'Story already viewed';
    }
    
    // Get updated view count
    $countStmt = $db->prepare("SELECT views_count FROM stories WHERE id = ?");
    $countStmt->bind_param('i', $storyId);
    $countStmt->execute();
    $viewsCount = $countStmt->get_result()->fetch_assoc()['views_count'];
    
    Response::success([
        'story_id' => $storyId,
        'views_count' => (int)$viewsCount,
        'is_viewed' => true
    ], $message);
    
} catch (Exception $e) {
    error_log("View story error: " . $e->getMessage());
    Response::error('Internal server error', 500);
}
?>