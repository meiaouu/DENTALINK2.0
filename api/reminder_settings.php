<?php
require_once __DIR__ . '/../conn/conn.php';
date_default_timezone_set('Asia/Manila');

if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

// GET — fetch current settings
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $res = $conn->query("SELECT * FROM email_reminder_settings WHERE id = 1");
    $settings = $res ? $res->fetch_assoc() : null;
    echo json_encode(['success' => true, 'settings' => $settings]);
    exit;
}

// POST — update settings
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    $is_enabled   = isset($input['is_enabled']) ? (int)$input['is_enabled'] : 0;
    $days_before  = max(1, min(30, (int)($input['days_before'] ?? 3)));
    $subject      = trim($input['subject'] ?? '');
    $heading      = trim($input['heading'] ?? '');
    $message_body = trim($input['message_body'] ?? '');
    $button_text  = trim($input['button_text'] ?? '');
    $footer_note  = trim($input['footer_note'] ?? '');

    if ($subject === '' || $heading === '' || $message_body === '') {
        echo json_encode(['success' => false, 'message' => 'Subject, heading, and message body are required.']);
        exit;
    }

    $stmt = $conn->prepare("UPDATE email_reminder_settings
        SET is_enabled = ?, days_before = ?, subject = ?, heading = ?, message_body = ?, button_text = ?, footer_note = ?
        WHERE id = 1");
    $stmt->bind_param('iisssss',
        $is_enabled, $days_before, $subject, $heading, $message_body, $button_text, $footer_note
    );

    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Reminder settings saved.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to save: ' . $stmt->error]);
    }
    $stmt->close();
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed.']);