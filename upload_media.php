<?php
require_once 'config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, null, 'Only POST method allowed');
}

if (!isset($_FILES['file'])) {
    sendResponse(false, null, 'No file uploaded');
}

$file = $_FILES['file'];
$uploadDir = 'uploads/';

// Create upload directory if it doesn't exist
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

// Generate unique filename
$fileExtension = pathinfo($file['name'], PATHINFO_EXTENSION);
$fileName = uniqid() . '.' . $fileExtension;
$targetPath = $uploadDir . $fileName;

// Validate file type
$allowedTypes = ['jpg', 'jpeg', 'png', 'gif', 'mp4', 'mov'];
if (!in_array(strtolower($fileExtension), $allowedTypes)) {
    sendResponse(false, null, 'Invalid file type');
}

// Validate file size (10MB max)
if ($file['size'] > 10 * 1024 * 1024) {
    sendResponse(false, null, 'File too large. Maximum size is 10MB');
}

if (move_uploaded_file($file['tmp_name'], $targetPath)) {
    $fileUrl = 'http://' . $_SERVER['HTTP_HOST'] . '/' . $targetPath;

    sendResponse(true, [
        'url' => $fileUrl,
        'type' => in_array(strtolower($fileExtension), ['mp4', 'mov']) ? 'video' : 'image'
    ]);
} else {
    sendResponse(false, null, 'File upload failed');
}
?>
