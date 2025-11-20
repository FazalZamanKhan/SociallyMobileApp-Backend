<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

$user = Auth::requireAuth();
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['actions']) || !is_array($input['actions'])) {
    Response::error('Actions array required');
}

$actions = $input['actions'];
$results = [];

try {
    $db = getDB();
    
    foreach ($actions as $action) {
        $actionType = $action['action_type'] ?? '';
        $actionData = $action['data'] ?? [];
        $clientId = $action['client_id'] ?? null; // For client-side tracking
        
        if (empty($actionType) || empty($actionData)) {
            $results[] = [
                'client_id' => $clientId,
                'success' => false,
                'error' => 'Invalid action format'
            ];
            continue;
        }
        
        try {
            $db->begin_transaction();
            
            $success = false;
            $error = null;
            $resultData = [];
            
            switch ($actionType) {
                case 'send_message':
                    $success = processSendMessage($db, $user['user_id'], $actionData, $resultData, $error);
                    break;
                    
                case 'create_post':
                    $success = processCreatePost($db, $user['user_id'], $actionData, $resultData, $error);
                    break;
                    
                case 'like_post':
                    $success = processLikePost($db, $user['user_id'], $actionData, $resultData, $error);
                    break;
                    
                case 'comment_post':
                    $success = processCommentPost($db, $user['user_id'], $actionData, $resultData, $error);
                    break;
                    
                case 'follow_user':
                    $success = processFollowUser($db, $user['user_id'], $actionData, $resultData, $error);
                    break;
                    
                case 'upload_story':
                    $success = processUploadStory($db, $user['user_id'], $actionData, $resultData, $error);
                    break;
                    
                default:
                    $error = 'Unsupported action type';
                    break;
            }
            
            if ($success) {
                $db->commit();
                $results[] = [
                    'client_id' => $clientId,
                    'success' => true,
                    'data' => $resultData
                ];
            } else {
                $db->rollback();
                $results[] = [
                    'client_id' => $clientId,
                    'success' => false,
                    'error' => $error ?? 'Action failed'
                ];
            }
            
        } catch (Exception $e) {
            $db->rollback();
            $results[] = [
                'client_id' => $clientId,
                'success' => false,
                'error' => 'Internal error: ' . $e->getMessage()
            ];
        }
    }
    
    $successCount = count(array_filter($results, function($r) { return $r['success']; }));
    $totalCount = count($results);
    
    Response::success([
        'results' => $results,
        'summary' => [
            'total_actions' => $totalCount,
            'successful' => $successCount,
            'failed' => $totalCount - $successCount
        ]
    ], "Processed $successCount out of $totalCount actions");
    
} catch (Exception $e) {
    error_log("Sync offline actions error: " . $e->getMessage());
    Response::error('Internal server error', 500);
}

// Helper functions for processing different action types
function processSendMessage($db, $userId, $data, &$resultData, &$error) {
    $requiredFields = ['receiver_id'];
    foreach ($requiredFields as $field) {
        if (!isset($data[$field])) {
            $error = "Missing field: $field";
            return false;
        }
    }
    
    $content = $data['content'] ?? '';
    $mediaUrl = $data['media_url'] ?? '';
    $messageType = $data['message_type'] ?? 'normal';
    
    if (empty($content) && empty($mediaUrl)) {
        $error = 'Message must have content or media';
        return false;
    }
    
    $stmt = $db->prepare("
        INSERT INTO messages (sender_id, receiver_id, content, media_url, message_type) 
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->bind_param('iisss', $userId, $data['receiver_id'], $content, $mediaUrl, $messageType);
    
    if ($stmt->execute()) {
        $resultData['message_id'] = $db->insert_id();
        return true;
    }
    
    $error = 'Failed to send message';
    return false;
}

function processCreatePost($db, $userId, $data, &$resultData, &$error) {
    $content = $data['content'] ?? '';
    $mediaUrl = $data['media_url'] ?? '';
    
    if (empty($content) && empty($mediaUrl)) {
        $error = 'Post must have content or media';
        return false;
    }
    
    $stmt = $db->prepare("INSERT INTO posts (user_id, content, media_url) VALUES (?, ?, ?)");
    $stmt->bind_param('iss', $userId, $content, $mediaUrl);
    
    if ($stmt->execute()) {
        $resultData['post_id'] = $db->insert_id();
        return true;
    }
    
    $error = 'Failed to create post';
    return false;
}

function processLikePost($db, $userId, $data, &$resultData, &$error) {
    if (!isset($data['post_id'])) {
        $error = 'Post ID required';
        return false;
    }
    
    // Check if already liked
    $checkStmt = $db->prepare("SELECT id FROM post_likes WHERE post_id = ? AND user_id = ?");
    $checkStmt->bind_param('ii', $data['post_id'], $userId);
    $checkStmt->execute();
    
    if ($checkStmt->get_result()->num_rows > 0) {
        $error = 'Post already liked';
        return false;
    }
    
    $stmt = $db->prepare("INSERT INTO post_likes (post_id, user_id) VALUES (?, ?)");
    $stmt->bind_param('ii', $data['post_id'], $userId);
    
    return $stmt->execute();
}

function processCommentPost($db, $userId, $data, &$resultData, &$error) {
    $requiredFields = ['post_id', 'content'];
    foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            $error = "Missing field: $field";
            return false;
        }
    }
    
    $stmt = $db->prepare("INSERT INTO post_comments (post_id, user_id, content) VALUES (?, ?, ?)");
    $stmt->bind_param('iis', $data['post_id'], $userId, $data['content']);
    
    if ($stmt->execute()) {
        $resultData['comment_id'] = $db->insert_id();
        return true;
    }
    
    $error = 'Failed to add comment';
    return false;
}

function processFollowUser($db, $userId, $data, &$resultData, &$error) {
    if (!isset($data['user_id'])) {
        $error = 'User ID required';
        return false;
    }
    
    // Check if already following or request exists
    $checkStmt = $db->prepare("
        SELECT 'following' as type FROM followers WHERE user_id = ? AND follower_id = ?
        UNION
        SELECT 'request' as type FROM follow_requests WHERE sender_id = ? AND receiver_id = ? AND status = 'pending'
    ");
    $checkStmt->bind_param('iiii', $data['user_id'], $userId, $userId, $data['user_id']);
    $checkStmt->execute();
    
    if ($checkStmt->get_result()->num_rows > 0) {
        $error = 'Already following or request exists';
        return false;
    }
    
    // Create follow request
    $stmt = $db->prepare("INSERT INTO follow_requests (sender_id, receiver_id) VALUES (?, ?)");
    $stmt->bind_param('ii', $userId, $data['user_id']);
    
    return $stmt->execute();
}

function processUploadStory($db, $userId, $data, &$resultData, &$error) {
    $requiredFields = ['media_url', 'media_type'];
    foreach ($requiredFields as $field) {
        if (!isset($data[$field])) {
            $error = "Missing field: $field";
            return false;
        }
    }
    
    $expiresAt = date('Y-m-d H:i:s', time() + STORY_EXPIRY_TIME);
    
    $stmt = $db->prepare("
        INSERT INTO stories (user_id, media_url, media_type, caption, expires_at) 
        VALUES (?, ?, ?, ?, ?)
    ");
    $caption = $data['caption'] ?? '';
    $stmt->bind_param('issss', $userId, $data['media_url'], $data['media_type'], $caption, $expiresAt);
    
    if ($stmt->execute()) {
        $resultData['story_id'] = $db->insert_id();
        return true;
    }
    
    $error = 'Failed to upload story';
    return false;
}
?>