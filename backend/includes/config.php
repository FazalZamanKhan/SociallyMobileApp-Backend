<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'socially_user');
define('DB_PASS', 'socially_pass123');
define('DB_NAME', 'socially_app');

// JWT Secret Key (Change this to a random strong key in production)
define('JWT_SECRET', 'your_super_secret_jwt_key_change_this_in_production');
define('JWT_EXPIRY', 3600 * 24 * 7); // 7 days
define('REFRESH_TOKEN_EXPIRY', 3600 * 24 * 30); // 30 days

// File upload settings
define('MAX_FILE_SIZE', 50 * 1024 * 1024); // 50MB
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
define('ALLOWED_VIDEO_TYPES', ['video/mp4', 'video/avi', 'video/quicktime', 'video/webm']);
define('ALLOWED_AUDIO_TYPES', ['audio/mpeg', 'audio/wav', 'audio/ogg', 'audio/mp4']);
define('ALLOWED_FILE_TYPES', ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']);

// Base URLs
define('BASE_URL', 'http://localhost/SociallyMobileApp-Backend/backend');
define('MEDIA_URL', BASE_URL . '/media');

// Agora configuration
define('AGORA_APP_ID', 'YOUR_AGORA_APP_ID');
define('AGORA_APP_CERTIFICATE', 'YOUR_AGORA_APP_CERTIFICATE');

// Firebase FCM configuration
define('FCM_SERVER_KEY', 'YOUR_FCM_SERVER_KEY');
define('FCM_SENDER_ID', 'YOUR_FCM_SENDER_ID');

// Story expiration time (24 hours)
define('STORY_EXPIRY_TIME', 24 * 60 * 60);

// Message edit/delete time limit (5 minutes)
define('MESSAGE_EDIT_TIME_LIMIT', 5 * 60);

// Pagination settings
define('DEFAULT_PAGE_SIZE', 20);
define('MAX_PAGE_SIZE', 100);

// Error reporting for development (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// CORS settings for mobile app
function setCORSHeaders() {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
    
    // Handle preflight OPTIONS request
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
}

// Set content type for JSON responses
header('Content-Type: application/json; charset=utf-8');

// Timezone setting
date_default_timezone_set('UTC');
?>