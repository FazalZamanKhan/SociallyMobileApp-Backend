<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('Method not allowed', 405);
}

$user = Auth::getCurrentUser();
$userId = $_GET['user_id'] ?? null; // If specified, get posts for specific user
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = min(MAX_PAGE_SIZE, max(1, (int)($_GET['limit'] ?? DEFAULT_PAGE_SIZE)));
$offset = ($page - 1) * $limit;

try {
    $db = getDB();
    
    $whereConditions = [];
    $params = [];
    $types = '';
    
    // If specific user requested
    if ($userId) {
        $whereConditions[] = "p.user_id = ?";
        $params[] = $userId;
        $types .= 'i';
        
        // Check if user profile is private and if current user is following
        if ($user && $user['user_id'] != $userId) {
            $privacyStmt = $db->prepare("
                SELECT u.is_private, 
                       EXISTS(SELECT 1 FROM followers WHERE user_id = ? AND follower_id = ?) as is_following
                FROM users u WHERE u.id = ?
            ");
            $privacyStmt->bind_param('iii', $userId, $user['user_id'], $userId);
            $privacyStmt->execute();
            $privacyResult = $privacyStmt->get_result();
            
            if ($privacyResult->num_rows > 0) {
                $privacyData = $privacyResult->fetch_assoc();
                if ($privacyData['is_private'] && !$privacyData['is_following']) {
                    Response::success(Response::pagination([], 0, $page, $limit));
                }
            }
        }
    } else {
        // For feed, only show posts from followed users and own posts
        if ($user) {
            $whereConditions[] = "(
                p.user_id = ? OR 
                p.user_id IN (SELECT user_id FROM followers WHERE follower_id = ?)
            )";
            $params[] = $user['user_id'];
            $params[] = $user['user_id'];
            $types .= 'ii';
        }
    }
    
    // Privacy filter (if user is logged in)
    if ($user) {
        $whereConditions[] = "(
            p.privacy = 'public' OR 
            (p.privacy = 'followers' AND (
                p.user_id = ? OR 
                EXISTS(SELECT 1 FROM followers WHERE user_id = p.user_id AND follower_id = ?)
            )) OR
            (p.privacy = 'private' AND p.user_id = ?)
        )";
        $params[] = $user['user_id'];
        $params[] = $user['user_id'];
        $params[] = $user['user_id'];
        $types .= 'iii';
    } else {
        $whereConditions[] = "p.privacy = 'public'";
    }
    
    $whereClause = empty($whereConditions) ? '' : 'WHERE ' . implode(' AND ', $whereConditions);
    
    // Get total count
    $countSql = "SELECT COUNT(*) as total FROM posts p $whereClause";
    if (!empty($params)) {
        $countStmt = $db->prepare($countSql);
        $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $totalResult = $countStmt->get_result();
    } else {
        $totalResult = $db->query($countSql);
    }
    $total = $totalResult->fetch_assoc()['total'];
    
    // Get posts
    $sql = "
        SELECT 
            p.id, p.content, p.media_url, p.media_type, p.location, p.privacy,
            p.likes_count, p.comments_count, p.created_at,
            u.id as user_id, u.username, u.first_name, u.last_name, u.profile_picture
        FROM posts p
        JOIN users u ON p.user_id = u.id
        $whereClause
        ORDER BY p.created_at DESC
        LIMIT ? OFFSET ?
    ";
    
    // Add limit and offset parameters
    $params[] = $limit;
    $params[] = $offset;
    $types .= 'ii';
    
    $stmt = $db->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    $posts = [];
    while ($row = $result->fetch_assoc()) {
        // Check if current user liked this post
        $isLiked = false;
        if ($user) {
            $likeStmt = $db->prepare("SELECT id FROM post_likes WHERE post_id = ? AND user_id = ?");
            $likeStmt->bind_param('ii', $row['id'], $user['user_id']);
            $likeStmt->execute();
            $isLiked = $likeStmt->get_result()->num_rows > 0;
        }
        
        $row['is_liked'] = $isLiked;
        $row['user'] = [
            'id' => $row['user_id'],
            'username' => $row['username'],
            'first_name' => $row['first_name'],
            'last_name' => $row['last_name'],
            'profile_picture' => $row['profile_picture']
        ];
        unset($row['user_id'], $row['username'], $row['first_name'], $row['last_name']);
        
        $posts[] = $row;
    }
    
    Response::success(Response::pagination($posts, $total, $page, $limit));
    
} catch (Exception $e) {
    error_log("Get posts error: " . $e->getMessage());
    Response::error('Internal server error', 500);
}
?>