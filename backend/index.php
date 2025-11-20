<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';

setCORSHeaders();

// Clean up expired data periodically (you may want to run this as a cron job instead)
if (rand(1, 100) == 1) {
    cleanupExpiredData();
}

$method = $_SERVER['REQUEST_METHOD'];
$path = $_SERVER['PATH_INFO'] ?? '/';

// Remove trailing slash
$path = rtrim($path, '/');
if (empty($path)) {
    $path = '/';
}

// Route handling
switch ($path) {
    // Authentication routes
    case '/register':
        require 'api/register.php';
        break;
    case '/login':
        require 'api/login.php';
        break;
    case '/logout':
        require 'api/logout.php';
        break;
    case '/refresh-token':
        require 'api/refresh_token.php';
        break;
    
    // User management
    case '/profile':
        require 'api/profile.php';
        break;
    case '/update-profile':
        require 'api/update_profile.php';
        break;
    case '/search-users':
        require 'api/search_users.php';
        break;
    case '/user-status':
        require 'api/user_status.php';
        break;
    
    // Posts
    case '/create-post':
        require 'api/create_post.php';
        break;
    case '/get-posts':
        require 'api/get_posts.php';
        break;
    case '/like-post':
        require 'api/like_post.php';
        break;
    case '/comment-post':
        require 'api/comment_post.php';
        break;
    case '/get-comments':
        require 'api/get_comments.php';
        break;
    
    // Stories
    case '/create-story':
        require 'api/create_story.php';
        break;
    case '/get-stories':
        require 'api/get_stories.php';
        break;
    case '/view-story':
        require 'api/view_story.php';
        break;
    
    // Follow system
    case '/send-follow-request':
        require 'api/send_follow_request.php';
        break;
    case '/respond-follow-request':
        require 'api/respond_follow_request.php';
        break;
    case '/get-follow-requests':
        require 'api/get_follow_requests.php';
        break;
    case '/unfollow':
        require 'api/unfollow.php';
        break;
    case '/get-followers':
        require 'api/get_followers.php';
        break;
    case '/get-following':
        require 'api/get_following.php';
        break;
    
    // Messaging
    case '/send-message':
        require 'api/send_message.php';
        break;
    case '/get-messages':
        require 'api/get_messages.php';
        break;
    case '/get-conversations':
        require 'api/get_conversations.php';
        break;
    case '/edit-message':
        require 'api/edit_message.php';
        break;
    case '/delete-message':
        require 'api/delete_message.php';
        break;
    case '/mark-message-read':
        require 'api/mark_message_read.php';
        break;
    
    // Media upload
    case '/upload-media':
        require 'api/upload_media.php';
        break;
    
    // Notifications
    case '/register-fcm-token':
        require 'api/register_fcm_token.php';
        break;
    case '/get-notifications':
        require 'api/get_notifications.php';
        break;
    case '/mark-notification-read':
        require 'api/mark_notification_read.php';
        break;
    
    // Agora calls
    case '/initiate-call':
        require 'api/initiate_call.php';
        break;
    case '/end-call':
        require 'api/end_call.php';
        break;
    case '/get-agora-token':
        require 'api/get_agora_token.php';
        break;
    
    // Screenshot alerts
    case '/report-screenshot':
        require 'api/report_screenshot.php';
        break;
    
    // Sync for offline support
    case '/sync-offline-actions':
        require 'api/sync_offline_actions.php';
        break;
    
    // Health check
    case '/':
    case '/health':
        Response::success(['status' => 'API is running', 'version' => '1.0.0']);
        break;
    
    default:
        Response::error('Endpoint not found', 404);
}
?>