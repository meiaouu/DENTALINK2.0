<?php
session_start();

$DB_HOST = 'localhost';
$DB_NAME = 'dental_clinic_restored_20261016';
$DB_USER = 'root';
$DB_PASS = '';

// Create MySQLi connection (for existing code)
$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);

// Check MySQLi connection
if ($conn->connect_error) {
    error_log('MySQLi Connection failed: ' . $conn->connect_error);
}

// Set charset to UTF-8
if ($conn->connect_error === null) {
    $conn->set_charset("utf8mb4");
}

// Create PDO connection (for OTP functions)
try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    error_log('PDO Connection failed: ' . $e->getMessage());
}
?>