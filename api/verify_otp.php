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

    $phone = $input['phone'] ?? '';
    $email = $input['email'] ?? '';
    $otp   = $input['otp'] ?? '';

    if (empty($phone) || empty($email) || empty($otp)) {
        throw new Exception('Phone, email and OTP are required');
    }

    $result = verifyOTP($pdo, $phone, $email, $otp);

    if ($result['success']) {
        // OTP confirmed — carry the pending booking into the session
        // key that schedule-appointment.php reads. Still nothing saved to DB.
        if (isset($_SESSION['pending_booking'])) {
            $_SESSION['booking_data'] = $_SESSION['pending_booking'];
            unset($_SESSION['pending_booking']);
        } else {
            // fallback, in case send_otp.php wasn't hit in this session
            $_SESSION['booking_data'] = [
                'phone'           => $phone,
                'email'           => $email,
                'services'        => $input['services'] ?? [],
                'termsAccepted'   => true,
                'privacyAccepted' => true,
            ];
        }

        $response['success']  = true;
        $response['message']  = 'OTP verified successfully';
        $response['redirect'] = 'schedule-appointment.php';
    } else {
        $response['message'] = $result['message'];
    }

} catch (Exception $e) {
    $response['message'] = $e->getMessage();
    error_log('verify_otp.php error: ' . $e->getMessage());
}

echo json_encode($response);