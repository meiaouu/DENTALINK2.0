<?php
require '../conn/conn.php';

$file = $_GET['file'] ?? '';
$backupDir = __DIR__ . '/backups/';
$filepath = $backupDir . basename($file);

// Security: Only allow .sql files
if (empty($file) || !preg_match('/^backup_.*\.sql$/', $file)) {
    die('Invalid file.');
}

// Check if file exists
if (!file_exists($filepath)) {
    die('File not found.');
}

// Force download
header('Content-Type: application/sql');
header('Content-Disposition: attachment; filename="' . basename($filepath) . '"');
header('Content-Length: ' . filesize($filepath));
readfile($filepath);
exit;
?>