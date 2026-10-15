<?php
require '../conn/conn.php';
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$what = $_POST['what'] ?? '';

$column = null;
if ($what === 'appointments') $column = 'seen_appointments_at';
if ($what === 'today')        $column = 'seen_today_at';

if (!$column) {
    echo json_encode(['success' => false, 'message' => 'Unknown target']);
    exit;
}

$stmt = $conn->prepare("UPDATE users SET $column = NOW() WHERE id = ?");
$stmt->bind_param('i', $userId);
$ok = $stmt->execute();
$stmt->close();

echo json_encode(['success' => (bool)$ok]);