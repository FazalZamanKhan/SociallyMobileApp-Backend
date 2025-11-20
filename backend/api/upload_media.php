<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed', 405);
}

$user = Auth::requireAuth();

if (!isset($_FILES['file']) || !isset($_POST['upload_type'])) {
    Response::error('File and upload_type required');
}

$uploadType = $_POST['upload_type'];
$allowedTypes = ['post', 'story', 'message', 'profile', 'cover'];

if (!in_array($uploadType, $allowedTypes)) {
    Response::error('Invalid upload type');
}

try {
    $file = $_FILES['file'];
    
    // Determine allowed file types based on upload type
    $allowedMimeTypes = [];
    switch ($uploadType) {
        case 'profile':
        case 'cover':
            $allowedMimeTypes = ALLOWED_IMAGE_TYPES;
            break;
        case 'post':
        case 'story':
            $allowedMimeTypes = array_merge(ALLOWED_IMAGE_TYPES, ALLOWED_VIDEO_TYPES);
            break;
        case 'message':
            $allowedMimeTypes = array_merge(
                ALLOWED_IMAGE_TYPES, 
                ALLOWED_VIDEO_TYPES, 
                ALLOWED_AUDIO_TYPES, 
                ALLOWED_FILE_TYPES
            );
            break;
    }
    
    // Validate file
    $validation = FileUpload::validateFile($file, $allowedMimeTypes);
    if (!$validation['success']) {
        Response::error($validation['error']);
    }
    
    $mimeType = $validation['mime_type'];
    $fileType = FileUpload::getFileType($mimeType);
    
    // Generate unique filename
    $originalName = $file['name'];
    $fileName = FileUpload::generateUniqueFileName($originalName);
    
    // Determine upload directory
    $uploadDir = '../media/uploads/';
    switch ($uploadType) {
        case 'profile':
        case 'cover':
            $uploadDir .= 'profiles/';
            break;
        case 'post':
            $uploadDir .= 'posts/';
            break;
        case 'story':
            $uploadDir .= 'stories/';
            break;
        case 'message':
            $uploadDir .= 'messages/';
            break;
    }
    
    $filePath = $uploadDir . $fileName;
    $webPath = str_replace('../', '', $filePath);
    
    // Create directory if it doesn't exist
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $filePath)) {
        Response::error('Failed to upload file');
    }
    
    $db = getDB();
    
    // Save file info to database
    $stmt = $db->prepare("
        INSERT INTO media_files 
        (user_id, original_name, file_name, file_path, file_size, mime_type, file_type, upload_type) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param(
        'isssisss',
        $user['user_id'],
        $originalName,
        $fileName,
        $webPath,
        $file['size'],
        $mimeType,
        $fileType,
        $uploadType
    );
    
    if (!$stmt->execute()) {
        // Delete uploaded file if database insert fails
        unlink($filePath);
        Response::error('Failed to save file info');
    }
    
    $mediaId = $db->insert_id();
    
    // If it's a profile or cover photo, update user record
    if ($uploadType === 'profile' || $uploadType === 'cover') {
        $field = $uploadType === 'profile' ? 'profile_picture' : 'cover_photo';
        $updateStmt = $db->prepare("UPDATE users SET $field = ? WHERE id = ?");
        $updateStmt->bind_param('si', $webPath, $user['user_id']);
        $updateStmt->execute();
    }
    
    $mediaUrl = MEDIA_URL . '/' . str_replace('media/', '', $webPath);
    
    Response::success([
        'media_id' => $mediaId,
        'file_name' => $fileName,
        'original_name' => $originalName,
        'file_path' => $webPath,
        'media_url' => $mediaUrl,
        'file_type' => $fileType,
        'mime_type' => $mimeType,
        'file_size' => $file['size']
    ], 'File uploaded successfully');
    
} catch (Exception $e) {
    error_log("Media upload error: " . $e->getMessage());
    Response::error('Internal server error', 500);
}
?>