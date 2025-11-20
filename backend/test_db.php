<?php
require_once 'includes/config.php';
require_once 'includes/db.php';

try {
    $db = getDB();
    echo json_encode([
        'success' => true,
        'message' => 'Database connection successful',
        'database' => DB_NAME,
        'user' => DB_USER
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Database connection failed: ' . $e->getMessage()
    ]);
}
?>