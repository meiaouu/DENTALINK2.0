<?php
require_once __DIR__ . '/../conn/conn.php';
header('Content-Type: application/json');

$response = ['success' => false, 'message' => '', 'data' => null];

try {
    $appointmentId = $_GET['id'] ?? $_POST['id'] ?? null;
    $token = $_GET['token'] ?? $_POST['token'] ?? null;
    
    if (!$appointmentId && !$token) {
        throw new Exception('Appointment ID or token is required');
    }
    
    $query = "SELECT 
                a.*,
                GROUP_CONCAT(s.title SEPARATOR ', ') as service_names,
                GROUP_CONCAT(s.price SEPARATOR ', ') as service_prices,
                GROUP_CONCAT(s.duration SEPARATOR ', ') as service_durations
              FROM appointments a
              LEFT JOIN services s ON FIND_IN_SET(s.id, REPLACE(a.services, '\"', ''))
              WHERE 1=1";
    
    if ($appointmentId) {
        $query .= " AND a.id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('i', $appointmentId);
    } else {
        $query .= " AND a.tracking_token = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('s', $token);
    }
    
    $query .= " GROUP BY a.id";
    
    if (!$stmt) {
        throw new Exception('Database error: ' . $conn->error);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $appointment = $result->fetch_assoc();
    
    if (!$appointment) {
        throw new Exception('Appointment not found');
    }
    
    // Parse services from JSON
    $services = json_decode($appointment['services'], true);
    if (is_array($services)) {
        $appointment['services_array'] = $services;
        // Get full service details
        $serviceDetails = [];
        if (!empty($services)) {
            $placeholders = implode(',', array_fill(0, count($services), '?'));
            $serviceQuery = "SELECT * FROM services WHERE id IN ($placeholders) AND is_active = 1";
            $serviceStmt = $conn->prepare($serviceQuery);
            $serviceStmt->bind_param(str_repeat('i', count($services)), ...$services);
            $serviceStmt->execute();
            $serviceResult = $serviceStmt->get_result();
            while ($row = $serviceResult->fetch_assoc()) {
                $serviceDetails[] = $row;
            }
            $serviceStmt->close();
        }
        $appointment['service_details'] = $serviceDetails;
        $appointment['total_price'] = array_sum(array_column($serviceDetails, 'price'));
    }
    
    $response['success'] = true;
    $response['data'] = $appointment;
    
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
?>