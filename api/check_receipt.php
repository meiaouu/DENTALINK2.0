<?php
require '../conn/conn.php';
date_default_timezone_set('Asia/Manila');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$appointmentId = isset($_GET['appointment_id']) ? (int)$_GET['appointment_id'] : 0;
$response = ['exists' => false];

if ($appointmentId > 0) {
    $stmt = $conn->prepare("
        SELECT receipt_code 
        FROM patient_record_archive 
        WHERE appointment_id = ? 
        ORDER BY id DESC 
        LIMIT 1
    ");
    $stmt->bind_param('i', $appointmentId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($result) {
        $response['exists'] = true;
        $response['receipt_code'] = $result['receipt_code'];
    }
}

echo json_encode($response);
exit;