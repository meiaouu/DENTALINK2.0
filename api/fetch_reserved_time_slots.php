<?php
header('Content-Type: application/json');
require '../conn/conn.php';
date_default_timezone_set('Asia/Manila');

$date = $_GET['date'] ?? '';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode(['success' => false, 'booked_slots' => []]);
    exit;
}

$stmt = $conn->prepare("
    SELECT appointment_time 
    FROM appointments 
    WHERE appointment_date = ? 
      AND status IN ('pending', 'approved', 'confirmed')
");
$stmt->bind_param('s', $date);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// FIX: Ensure the time format always matches the front-end format (HH:MM:SS)
$slots = array_map(function($r) {
    // If it's already HH:MM:SS, this returns it as is.
    // If it's HH:MM, it adds :00.
    return date('H:i:s', strtotime($r['appointment_time']));
}, $rows);

echo json_encode([
    'success'      => true,
    'date'         => $date,
    'booked_slots' => $slots
]);