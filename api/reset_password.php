<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../conn/conn.php';

$data     = json_decode(file_get_contents('php://input'), true);
$token    = trim($data['token'] ?? '');
$password = $data['password'] ?? '';
$confirm  = $data['confirm_password'] ?? '';

if (!$token || !$password || !$confirm) {
    echo json_encode(['success' => false, 'message' => 'All fields are required.']);
    exit;
}

if ($password !== $confirm) {
    echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
    exit;
}

if (strlen($password) < 8) {
    echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters.']);
    exit;
}

$stmt = $conn->prepare("SELECT id, user_id, expires_at, used FROM password_resets WHERE token = ? LIMIT 1");
$stmt->bind_param('s', $token);
$stmt->execute();
$reset = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$reset) {
    echo json_encode(['success' => false, 'message' => 'Invalid or unknown reset link.']);
    exit;
}
if ((int)$reset['used'] === 1) {
    echo json_encode(['success' => false, 'message' => 'This reset link has already been used.']);
    exit;
}
if (strtotime($reset['expires_at']) < time()) {
    echo json_encode(['success' => false, 'message' => 'This reset link has expired. Please request a new one.']);
    exit;
}

// Hash — matches your registration (assumes password_hash / bcrypt)
$hash = password_hash($password, PASSWORD_DEFAULT);

$update = $conn->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
$update->bind_param('si', $hash, $reset['user_id']);
if (!$update->execute()) {
    error_log('Password update failed: ' . $update->error);
    echo json_encode(['success' => false, 'message' => 'Server error. Please try again.']);
    exit;
}
$update->close();

$mark = $conn->prepare("UPDATE password_resets SET used = 1 WHERE id = ?");
$mark->bind_param('i', $reset['id']);
$mark->execute();
$mark->close();

echo json_encode([
    'success' => true,
    'message' => 'Password reset successfully. You can now log in with your new password.'
]);