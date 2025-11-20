<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('Method not allowed', 405);
}

$user = Auth::getCurrentUser();
$userId = $_GET['user_id'] ?? null; // If specified, get stories for specific user

try {
    $db = getDB();
    
    if ($userId) {
        // Get stories for specific user
        $sql = "
            SELECT 
                s.id, s.media_url, s.media_type, s.caption, s.views_count, 
                s.expires_at, s.created_at,
                u.id as user_id, u.username, u.first_name, u.last_name, u.profile_picture
            FROM stories s
            JOIN users u ON s.user_id = u.id
            WHERE s.user_id = ? AND s.expires_at > NOW()
            ORDER BY s.created_at DESC
        ";
        
        $stmt = $db->prepare($sql);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $stories = [];
        while ($row = $result->fetch_assoc()) {
            // Check if current user viewed this story
            $isViewed = false;
            if ($user) {
                $viewStmt = $db->prepare("SELECT id FROM story_views WHERE story_id = ? AND viewer_id = ?");
                $viewStmt->bind_param('ii', $row['id'], $user['user_id']);
                $viewStmt->execute();
                $isViewed = $viewStmt->get_result()->num_rows > 0;
            }
            
            $row['is_viewed'] = $isViewed;
            $row['user'] = [
                'id' => $row['user_id'],
                'username' => $row['username'],
                'first_name' => $row['first_name'],
                'last_name' => $row['last_name'],
                'profile_picture' => $row['profile_picture']
            ];
            unset($row['user_id'], $row['username'], $row['first_name'], $row['last_name']);
            
            $stories[] = $row;
        }
        
        Response::success(['stories' => $stories]);
        
    } else {
        // Get stories feed grouped by user
        $whereClause = '';
        $params = [];
        $types = '';
        
        if ($user) {
            // Include own stories and stories from followed users
            $whereClause = 'WHERE (s.user_id = ? OR s.user_id IN (SELECT user_id FROM followers WHERE follower_id = ?))';
            $params = [$user['user_id'], $user['user_id']];
            $types = 'ii';
        }
        
        $sql = "
            SELECT 
                u.id as user_id, u.username, u.first_name, u.last_name, u.profile_picture,
                COUNT(s.id) as story_count,
                MAX(s.created_at) as latest_story,
                SUM(CASE WHEN sv.viewer_id IS NULL THEN 1 ELSE 0 END) as unviewed_count
            FROM users u
            JOIN stories s ON u.id = s.user_id
            LEFT JOIN story_views sv ON s.id = sv.story_id AND sv.viewer_id = ?
            $whereClause AND s.expires_at > NOW()
            GROUP BY u.id, u.username, u.first_name, u.last_name, u.profile_picture
            ORDER BY latest_story DESC
        ";
        
        // Add viewer_id parameter for LEFT JOIN
        if ($user) {
            array_unshift($params, $user['user_id']);
            $types = 'i' . $types;
        } else {
            $params = [0]; // Use 0 for anonymous user
            $types = 'i';
        }
        
        $stmt = $db->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        
        $storyUsers = [];
        while ($row = $result->fetch_assoc()) {
            $storyUsers[] = [
                'user' => [
                    'id' => $row['user_id'],
                    'username' => $row['username'],
                    'first_name' => $row['first_name'],
                    'last_name' => $row['last_name'],
                    'profile_picture' => $row['profile_picture']
                ],
                'story_count' => (int)$row['story_count'],
                'unviewed_count' => (int)$row['unviewed_count'],
                'latest_story' => $row['latest_story'],
                'has_unviewed' => (int)$row['unviewed_count'] > 0
            ];
        }
        
        Response::success(['story_users' => $storyUsers]);
    }
    
} catch (Exception $e) {
    error_log("Get stories error: " . $e->getMessage());
    Response::error('Internal server error', 500);
}
?>