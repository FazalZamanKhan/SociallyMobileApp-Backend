<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('Method not allowed', 405);
}

$currentUser = Auth::getCurrentUser();
$query = $_GET['q'] ?? '';
$filter = $_GET['filter'] ?? 'all'; // 'all', 'followers', 'following'
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = min(MAX_PAGE_SIZE, max(1, (int)($_GET['limit'] ?? DEFAULT_PAGE_SIZE)));
$offset = ($page - 1) * $limit;

if (strlen($query) < 2) {
    Response::error('Search query must be at least 2 characters');
}

$searchTerm = '%' . $query . '%';

try {
    $db = getDB();
    
    $whereConditions = [];
    $joinClauses = '';
    $params = [$searchTerm, $searchTerm];
    $types = 'ss';
    
    // Base search condition
    $whereConditions[] = "(u.username LIKE ? OR CONCAT(u.first_name, ' ', u.last_name) LIKE ?)";
    
    // Apply filters
    if ($currentUser && in_array($filter, ['followers', 'following'])) {
        if ($filter === 'followers') {
            // Search within followers
            $joinClauses .= " JOIN followers f ON u.id = f.follower_id";
            $whereConditions[] = "f.user_id = ?";
            $params[] = $currentUser['user_id'];
            $types .= 'i';
        } elseif ($filter === 'following') {
            // Search within following
            $joinClauses .= " JOIN followers f ON u.id = f.user_id";
            $whereConditions[] = "f.follower_id = ?";
            $params[] = $currentUser['user_id'];
            $types .= 'i';
        }
    }
    
    // Exclude current user from results
    if ($currentUser) {
        $whereConditions[] = "u.id != ?";
        $params[] = $currentUser['user_id'];
        $types .= 'i';
    }
    
    $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);
    
    // Get total count
    $countSql = "SELECT COUNT(*) as total FROM users u $joinClauses $whereClause";
    $countStmt = $db->prepare($countSql);
    $countStmt->bind_param($types, ...$params);
    $countStmt->execute();
    $total = $countStmt->get_result()->fetch_assoc()['total'];
    
    // Get search results
    $sql = "
        SELECT 
            u.id, u.username, u.first_name, u.last_name, u.profile_picture, 
            u.bio, u.is_private, u.is_online, u.last_seen
        FROM users u 
        $joinClauses
        $whereClause
        ORDER BY 
            CASE 
                WHEN u.username LIKE ? THEN 1
                WHEN CONCAT(u.first_name, ' ', u.last_name) LIKE ? THEN 2
                ELSE 3
            END,
            u.username ASC
        LIMIT ? OFFSET ?
    ";
    
    // Add ordering parameters
    $params[] = $query . '%'; // Exact username match priority
    $params[] = $query . '%'; // Exact name match priority
    $params[] = $limit;
    $params[] = $offset;
    $types .= 'ssii';
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $users = [];
    while ($row = $result->fetch_assoc()) {
        // Check relationship with current user
        $isFollowing = false;
        $followRequestStatus = null;
        $followersCount = 0;
        $followingCount = 0;
        
        if ($currentUser) {
            // Check if following
            $followStmt = $db->prepare("SELECT id FROM followers WHERE user_id = ? AND follower_id = ?");
            $followStmt->bind_param('ii', $row['id'], $currentUser['user_id']);
            $followStmt->execute();
            $isFollowing = $followStmt->get_result()->num_rows > 0;
            
            // Check follow request status
            $requestStmt = $db->prepare("SELECT status FROM follow_requests WHERE sender_id = ? AND receiver_id = ?");
            $requestStmt->bind_param('ii', $currentUser['user_id'], $row['id']);
            $requestStmt->execute();
            $requestResult = $requestStmt->get_result();
            if ($requestResult->num_rows > 0) {
                $followRequestStatus = $requestResult->fetch_assoc()['status'];
            }
        }
        
        // Get follower/following counts
        $statsStmt = $db->prepare("
            SELECT 
                (SELECT COUNT(*) FROM followers WHERE user_id = ?) as followers_count,
                (SELECT COUNT(*) FROM followers WHERE follower_id = ?) as following_count
        ");
        $statsStmt->bind_param('ii', $row['id'], $row['id']);
        $statsStmt->execute();
        $stats = $statsStmt->get_result()->fetch_assoc();
        
        $users[] = [
            'id' => $row['id'],
            'username' => $row['username'],
            'first_name' => $row['first_name'],
            'last_name' => $row['last_name'],
            'profile_picture' => $row['profile_picture'],
            'bio' => $row['bio'],
            'is_private' => (bool)$row['is_private'],
            'is_online' => (bool)$row['is_online'],
            'last_seen' => $row['last_seen'],
            'followers_count' => (int)$stats['followers_count'],
            'following_count' => (int)$stats['following_count'],
            'is_following' => $isFollowing,
            'follow_request_status' => $followRequestStatus
        ];
    }
    
    Response::success([
        'query' => $query,
        'filter' => $filter,
        'results' => Response::pagination($users, $total, $page, $limit)
    ]);
    
} catch (Exception $e) {
    error_log("Search users error: " . $e->getMessage());
    Response::error('Internal server error', 500);
}
?>