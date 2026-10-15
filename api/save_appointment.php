<?php
require_once __DIR__ . '/../conn/conn.php';
require_once __DIR__ . '/../functions/otp_functions.php';

header('Content-Type: application/json');

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    $data = [
        'phone'           => $input['phone'] ?? '',
        'email'           => $input['email'] ?? '',
        'services'        => $input['services'] ?? [],
        'date'            => $input['date'] ?? '',
        'time'            => $input['time'] ?? '',
        'full_name'       => $input['full_name'] ?? '',
        'age'             => $input['age'] ?? '',
        'occupation'      => $input['occupation'] ?? '',
        'region'          => $input['region'] ?? '',
        'province'        => $input['province'] ?? '',
        'city'            => $input['city'] ?? '',
        'barangay'        => $input['barangay'] ?? '',
        'street_address'  => $input['street_address'] ?? '',
        'notes'           => $input['notes'] ?? '',
        'termsAccepted'   => $input['termsAccepted'] ?? false,
        'privacyAccepted' => $input['privacyAccepted'] ?? false,
    ];

    if (empty($data['phone']) || empty($data['email']) || empty($data['services'])
        || empty($data['date']) || empty($data['time']) || empty($data['full_name'])) {
        $response['message'] = 'Missing required appointment details';
        echo json_encode($response);
        exit;
    }

    $result = saveAppointment($pdo, $data);

    if ($result['success']) {
        $response['success']        = true;
        $response['message']        = 'Appointment saved successfully';
        $response['appointment_id'] = $result['id'];
        $response['tracking_token'] = $result['token'] ?? null;
    } else {
        $response['message'] = $result['message'];
    }
}

echo json_encode($response);