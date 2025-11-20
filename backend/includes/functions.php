<?php
require_once 'config.php';

class JWT {
    
    public static function encode($payload) {
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payload = json_encode($payload);
        
        $headerEncoded = self::base64UrlEncode($header);
        $payloadEncoded = self::base64UrlEncode($payload);
        
        $signature = hash_hmac('sha256', $headerEncoded . '.' . $payloadEncoded, JWT_SECRET, true);
        $signatureEncoded = self::base64UrlEncode($signature);
        
        return $headerEncoded . '.' . $payloadEncoded . '.' . $signatureEncoded;
    }
    
    public static function decode($jwt) {
        $parts = explode('.', $jwt);
        
        if (count($parts) !== 3) {
            return false;
        }
        
        $header = json_decode(self::base64UrlDecode($parts[0]), true);
        $payload = json_decode(self::base64UrlDecode($parts[1]), true);
        $signature = self::base64UrlDecode($parts[2]);
        
        if (!$header || !$payload) {
            return false;
        }
        
        $expectedSignature = hash_hmac('sha256', $parts[0] . '.' . $parts[1], JWT_SECRET, true);
        
        if (!hash_equals($signature, $expectedSignature)) {
            return false;
        }
        
        // Check if token is expired
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            return false;
        }
        
        return $payload;
    }
    
    private static function base64UrlEncode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
    
    private static function base64UrlDecode($data) {
        return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT));
    }
}

class Auth {
    
    public static function generateTokens($userId) {
        $issuedAt = time();
        $expiry = $issuedAt + JWT_EXPIRY;
        $refreshExpiry = $issuedAt + REFRESH_TOKEN_EXPIRY;
        
        $accessTokenPayload = [
            'user_id' => $userId,
            'iat' => $issuedAt,
            'exp' => $expiry
        ];
        
        $refreshTokenPayload = [
            'user_id' => $userId,
            'type' => 'refresh',
            'iat' => $issuedAt,
            'exp' => $refreshExpiry
        ];
        
        $accessToken = JWT::encode($accessTokenPayload);
        $refreshToken = JWT::encode($refreshTokenPayload);
        
        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_at' => date('Y-m-d H:i:s', $expiry)
        ];
    }
    
    public static function verifyToken($token) {
        return JWT::decode($token);
    }
    
    public static function getCurrentUser() {
        $headers = getallheaders();
        $authHeader = isset($headers['Authorization']) ? $headers['Authorization'] : 
                     (isset($headers['authorization']) ? $headers['authorization'] : null);
        
        if (!$authHeader) {
            return null;
        }
        
        if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            return null;
        }
        
        $token = $matches[1];
        $payload = self::verifyToken($token);
        
        if (!$payload || !isset($payload['user_id'])) {
            return null;
        }
        
        return $payload;
    }
    
    public static function requireAuth() {
        $user = self::getCurrentUser();
        
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Authentication required']);
            exit;
        }
        
        return $user;
    }
    
    public static function hashPassword($password) {
        return password_hash($password, PASSWORD_DEFAULT);
    }
    
    public static function verifyPassword($password, $hash) {
        return password_verify($password, $hash);
    }
    
    public static function generateRandomString($length = 32) {
        return bin2hex(random_bytes($length));
    }
}

class Validator {
    
    public static function validateEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
    
    public static function validateUsername($username) {
        return preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username);
    }
    
    public static function validatePassword($password) {
        // At least 6 characters, can add more complexity as needed
        return strlen($password) >= 6;
    }
    
    public static function sanitizeString($string) {
        return htmlspecialchars(trim($string), ENT_QUOTES, 'UTF-8');
    }
    
    public static function validateRequired($fields, $data) {
        $missing = [];
        foreach ($fields as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                $missing[] = $field;
            }
        }
        return $missing;
    }
}

class Response {
    
    public static function success($data = [], $message = 'Success') {
        echo json_encode([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        exit;
    }
    
    public static function error($message, $code = 400, $data = []) {
        http_response_code($code);
        echo json_encode([
            'success' => false,
            'error' => $message,
            'data' => $data,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        exit;
    }
    
    public static function pagination($data, $total, $page, $limit) {
        return [
            'items' => $data,
            'pagination' => [
                'current_page' => (int)$page,
                'per_page' => (int)$limit,
                'total_items' => (int)$total,
                'total_pages' => ceil($total / $limit),
                'has_next' => ($page * $limit) < $total,
                'has_prev' => $page > 1
            ]
        ];
    }
}

class FileUpload {
    
    public static function validateFile($file, $allowedTypes, $maxSize = MAX_FILE_SIZE) {
        if (!isset($file['error']) || is_array($file['error'])) {
            return ['success' => false, 'error' => 'Invalid file upload'];
        }
        
        switch ($file['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_NO_FILE:
                return ['success' => false, 'error' => 'No file sent'];
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return ['success' => false, 'error' => 'File too large'];
            default:
                return ['success' => false, 'error' => 'Unknown upload error'];
        }
        
        if ($file['size'] > $maxSize) {
            return ['success' => false, 'error' => 'File exceeds maximum size'];
        }
        
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);
        
        if (!in_array($mimeType, $allowedTypes)) {
            return ['success' => false, 'error' => 'Invalid file type'];
        }
        
        return ['success' => true, 'mime_type' => $mimeType];
    }
    
    public static function generateUniqueFileName($originalName) {
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        return uniqid() . '_' . time() . '.' . $extension;
    }
    
    public static function getFileType($mimeType) {
        if (in_array($mimeType, ALLOWED_IMAGE_TYPES)) {
            return 'image';
        } elseif (in_array($mimeType, ALLOWED_VIDEO_TYPES)) {
            return 'video';
        } elseif (in_array($mimeType, ALLOWED_AUDIO_TYPES)) {
            return 'audio';
        } else {
            return 'document';
        }
    }
}

class Notification {
    
    public static function send($userId, $type, $title, $message, $data = []) {
        $db = getDB();
        
        // Save notification to database
        $stmt = $db->prepare("INSERT INTO notifications (user_id, type, title, message, data) VALUES (?, ?, ?, ?, ?)");
        $jsonData = json_encode($data);
        $stmt->bind_param('issss', $userId, $type, $title, $message, $jsonData);
        $stmt->execute();
        
        // Get user's FCM token
        $stmt = $db->prepare("SELECT token FROM notification_tokens WHERE user_id = ? AND is_active = 1");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            self::sendFCM($row['token'], $title, $message, $data);
        }
    }
    
    private static function sendFCM($token, $title, $message, $data = []) {
        if (!defined('FCM_SERVER_KEY') || empty(FCM_SERVER_KEY)) {
            return false;
        }
        
        $payload = [
            'to' => $token,
            'notification' => [
                'title' => $title,
                'body' => $message,
                'sound' => 'default'
            ],
            'data' => $data
        ];
        
        $headers = [
            'Authorization: key=' . FCM_SERVER_KEY,
            'Content-Type: application/json'
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://fcm.googleapis.com/fcm/send');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        
        $result = curl_exec($ch);
        curl_close($ch);
        
        return $result;
    }
}

// Function to clean up expired data (call this periodically)
function cleanupExpiredData() {
    $db = getDB();
    $db->cleanupExpiredStories();
    $db->cleanupExpiredVanishMessages();
    $db->cleanupExpiredSessions();
}
?>