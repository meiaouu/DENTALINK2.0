<?php
require '../conn/conn.php';
header('Content-Type: application/json');

$date = $_GET['date'] ?? '';
$response = ['booked_slots' => []];

if (!empty($date)) {
    $stmt = $conn->prepare("
        SELECT appointment_time 
        FROM appointments 
        WHERE appointment_date = ? 
        AND status IN ('approved', 'confirmed')
    ");
    $stmt->bind_param('s', $date);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $response['booked_slots'][] = $row['appointment_time'];
    }
    $stmt->close();
}

echo json_encode($response);
?>