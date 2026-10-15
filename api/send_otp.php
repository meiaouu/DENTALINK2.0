<?php
require_once __DIR__ . '/../conn/conn.php';
require_once __DIR__ . '/../functions/otp_functions.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$response = ['success' => false, 'message' => ''];

try {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        throw new Exception('No data received');
    }

    $phone    = $input['phone'] ?? '';
    $email    = $input['email'] ?? '';
    $services = $input['services'] ?? [];
    $termsAccepted   = $input['termsAccepted'] ?? false;
    $privacyAccepted = $input['privacyAccepted'] ?? false;
    $appointmentId   = $input['appointment_id'] ?? null; // only set on resend-with-tracking

    if (empty($phone) || empty($email)) {
        throw new Exception('Phone and email are required');
    }

    // Stash the pre-OTP booking intent in session so verify_otp.php
    // can hand it straight to schedule-appointment.php on success.
    // Nothing is written to the DB yet — no appointment exists at this point.
    $_SESSION['pending_booking'] = [
        'phone'           => $phone,
        'email'           => $email,
        'services'        => $services,
        'termsAccepted'   => (bool) $termsAccepted,
        'privacyAccepted' => (bool) $privacyAccepted,
    ];

    $clinicName = 'Dr. Brendalyn Wansi Calacat'; // or pull from clinic_settings
    $result = sendOTP($pdo, $phone, $email, $clinicName, $appointmentId);

    $response['success'] = $result['success'];
    $response['message'] = $result['message'];

} catch (Exception $e) {
    $response['message'] = $e->getMessage();
    error_log('send_otp.php error: ' . $e->getMessage());
}

echo json_encode($response);