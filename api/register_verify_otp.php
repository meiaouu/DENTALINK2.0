<?php
/**
 * API: Verify OTP and Complete Registration
 * File: api/register_verify_otp.php
 */

ob_start();
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json');

require_once __DIR__ . '/../conn/conn.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Invalid request');
    }
    
    $email = trim($input['email'] ?? '');
    $otp = $input['otp'] ?? '';
    
    // ===== VALIDATION =====
    if (empty($email)) {
        throw new Exception('Email is required');
    }
    
    if (empty($otp) || strlen($otp) !== 6) {
        throw new Exception('Please enter a valid 6-digit verification code');
    }
    
    // ===== CHECK SESSION =====
    if (!isset($_SESSION['pending_registration'])) {
        throw new Exception('No pending registration found. Please start over.');
    }
    
    $pendingData = $_SESSION['pending_registration'];
    
    // Verify email matches
    if ($pendingData['email'] !== $email) {
        throw new Exception('Email mismatch. Please try again.');
    }
    
    // ===== VERIFY OTP FROM DATABASE =====
    error_log("VERIFY ATTEMPT — phone: [" . $pendingData['phone'] . "] email: [" . $email . "] otp: [" . $otp . "]");

    $checkAll = $pdo->prepare("SELECT * FROM otp_verifications WHERE email = ? ORDER BY id DESC LIMIT 5");
    $checkAll->execute([$email]);
    error_log("EXISTING OTP ROWS: " . print_r($checkAll->fetchAll(PDO::FETCH_ASSOC), true));

    $stmt = $pdo->prepare("
        SELECT id, expires_at 
        FROM otp_verifications 
        WHERE phone = ? AND email = ? AND otp_code = ? AND is_verified = 0
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$pendingData['phone'], $email, $otp]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$result) {
        throw new Exception('Invalid verification code. Please try again.');
    }
    
    if (strtotime($result['expires_at']) < time()) {
        throw new Exception('Verification code has expired. Please request a new one.');
    }
    
    // Mark OTP as verified
    $stmt = $pdo->prepare("UPDATE otp_verifications SET is_verified = 1 WHERE id = ?");
    $stmt->execute([$result['id']]);
    
    // ===== CREATE USER =====
    $fullName = $pendingData['fullName'];
    $hashedPassword = $pendingData['password'];
    $phone = $pendingData['phone'];
    $role = 'patient';
    $isActive = 1;
    
    $stmt = $pdo->prepare("
        INSERT INTO users (email, password, full_name, phone, role, is_active) 
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([$email, $hashedPassword, $fullName, $phone, $role, $isActive]);
    $userId = $pdo->lastInsertId();
    
    // ===== CLEAR SESSION =====
    unset($_SESSION['pending_registration']);
    
    // ===== AUTO-LOGIN =====
    $_SESSION['user_id'] = $userId;
    $_SESSION['user_email'] = $email;
    $_SESSION['user_name'] = $fullName;
    $_SESSION['user_role'] = $role;
    $_SESSION['logged_in'] = true;
    
    // Staff session for chat
    if (in_array($role, ['staff', 'admin'])) {
        $_SESSION['staff_logged_in'] = true;
        $_SESSION['staff_id'] = $userId;
        $_SESSION['staff_name'] = $fullName;
        $_SESSION['staff_role'] = $role;
        $_SESSION['is_staff'] = true;
    }
    
    ob_clean();
    echo json_encode([
        'success' => true,
        'message' => 'Account created successfully!',
        'user' => [
            'id' => $userId,
            'full_name' => $fullName,
            'email' => $email,
            'role' => $role
        ],
        'redirect' => 'pages/patient/patient-dashboard.php'
    ]);
    exit;
    
} catch (Exception $e) {
    error_log("Register Verify OTP Error: " . $e->getMessage());
    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
    exit;
}