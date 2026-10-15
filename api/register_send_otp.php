<?php
/**
 * API: Send OTP for Registration
 * File: api/register_send_otp.php
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../conn/conn.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// ============================================================
// CONFIGURATION
// ============================================================
$RECAPTCHA_SECRET_KEY = '6Ld6fmQtAAAAAIe6gqVd8N4xlQcp8CcHFc1zK-5B';

// ============================================================
// FUNCTIONS
// ============================================================
function verifyRecaptcha($token) {
    global $RECAPTCHA_SECRET_KEY;
    
    if (empty($token)) {
        return false;
    }
    
    $url = 'https://www.google.com/recaptcha/api/siteverify';
    $data = [
        'secret' => $RECAPTCHA_SECRET_KEY,
        'response' => $token
    ];
    
    $options = [
        'http' => [
            'header' => "Content-type: application/x-www-form-urlencoded\r\n",
            'method' => 'POST',
            'content' => http_build_query($data)
        ]
    ];
    
    $context = stream_context_create($options);
    $result = file_get_contents($url, false, $context);
    $response = json_decode($result, true);
    
    return $response['success'] ?? false;
}

function generateOTP() {
    return str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
}

function saveOTP($pdo, $phone, $email, $otp) {
    try {
        $expiresAt = date('Y-m-d H:i:s', strtotime('+5 minutes'));
        
        // Delete existing OTPs
        $stmt = $pdo->prepare("DELETE FROM otp_verifications WHERE phone = ? AND email = ?");
        $stmt->execute([$phone, $email]);
        
        // Insert new OTP
        $stmt = $pdo->prepare("INSERT INTO otp_verifications (phone, email, otp_code, expires_at) VALUES (?, ?, ?, ?)");
        $result = $stmt->execute([$phone, $email, $otp, $expiresAt]);
        
        if (!$result) {
            error_log("Failed to save OTP: " . print_r($stmt->errorInfo(), true));
            return false;
        }
        
        return true;
    } catch (PDOException $e) {
        error_log("Save OTP Database Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Send OTP Email using PHPMailer - SAME AS YOUR BOOKING SYSTEM
 */
function sendRegistrationOTPEmail($email, $otp, $fullName, $clinicName) {
    $mail = new PHPMailer(true);
    
    try {
        // SMTP Configuration - USING YOUR EXISTING SETTINGS
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'santiagojhonny607@gmail.com';  // YOUR EMAIL
        $mail->Password   = 'dtdf tayg xmsq xjoh';          // YOUR APP PASSWORD
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        
        $mail->setFrom('santiagojhonny607@gmail.com', $clinicName);
        $mail->addAddress($email);
        
        $mail->isHTML(true);
        $mail->Subject = "🔐 Verify Your Account - $clinicName";
        
        $mail->Body = "
            <html>
            <body style='font-family: Arial, sans-serif;'>
                <div style='max-width: 500px; margin: 0 auto; padding: 20px; background: #f8fafc; border-radius: 10px;'>
                    <div style='text-align: center; margin-bottom: 20px;'>
                        <h2 style='color: #0b1628; margin: 0;'>$clinicName</h2>
                        <p style='color: #64748b; font-size: 13px; margin: 4px 0 0;'>Your Dental Health Partner</p>
                    </div>
                    
                    <p style='color: #475569;'>Hello <strong>$fullName</strong>,</p>
                    <p style='color: #475569;'>Thank you for registering! Please use the verification code below to complete your account creation:</p>
                    
                    <div style='background: white; padding: 20px; text-align: center; border-radius: 8px; border: 2px dashed #0d9e8c; margin: 20px 0;'>
                        <h1 style='font-size: 40px; letter-spacing: 8px; color: #0b1628; margin: 0;'>$otp</h1>
                    </div>
                    
                    <p style='text-align: center; color: #94a3b8; font-size: 14px;'>⏰ This code expires in 5 minutes.</p>
                    
                    <div style='background: #fff8e6; border-left: 4px solid #f59e0b; padding: 12px 16px; margin: 15px 0; border-radius: 4px;'>
                        <p style='margin: 0; font-size: 13px; color: #92400e;'>
                            ⚠️ <strong>Security Tip:</strong> Never share this code with anyone. Our staff will never ask for your OTP.
                        </p>
                    </div>
                    
                    <hr style='border: 1px solid #e2e8f0; margin: 20px 0;'>
                    <p style='text-align: center; color: #94a3b8; font-size: 12px;'>
                        This is an automated message. Please do not reply.<br>
                        <span style='font-size: 11px;'>If you didn't request this, please ignore this email.</span>
                    </p>
                </div>
            </body>
            </html>
        ";
        
        $mail->AltBody = "Your verification code is: $otp\n\nValid for 5 minutes.\n\nThis is an automated message. Please do not reply.";
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Registration OTP Email Error: " . $mail->ErrorInfo);
        return false;
    }
}

// ============================================================
// MAIN LOGIC
// ============================================================
try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Invalid request');
    }
    
    $fullName = trim($input['fullName'] ?? '');
    $email = trim($input['email'] ?? '');
    $phone = trim($input['phone'] ?? '');
    $password = $input['password'] ?? '';
    $confirmPassword = $input['confirmPassword'] ?? '';
    $recaptchaToken = $input['recaptchaToken'] ?? '';
    $termsAccepted = $input['termsAccepted'] ?? false;
    $privacyAccepted = $input['privacyAccepted'] ?? false;
    
    // ===== VALIDATION =====
    if (empty($fullName)) {
        throw new Exception('Full name is required');
    }
    
    if (empty($email)) {
        throw new Exception('Email is required');
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Invalid email format');
    }
    
    if (empty($phone)) {
        throw new Exception('Phone number is required');
    }
    
    // Format phone
    $phone = preg_replace('/\s+/', '', $phone);
    if (!preg_match('/^\+63\d{10}$/', $phone)) {
        if (preg_match('/^0\d{10}$/', $phone)) {
            $phone = '+63' . substr($phone, 1);
        } elseif (preg_match('/^\d{10}$/', $phone)) {
            $phone = '+63' . $phone;
        } else {
            throw new Exception('Invalid phone number format. Please use Philippine format (e.g., 9123456789)');
        }
    }
    
    if (empty($password)) {
        throw new Exception('Password is required');
    }
    
    if (strlen($password) < 8) {
        throw new Exception('Password must be at least 8 characters');
    }
    
    if ($password !== $confirmPassword) {
        throw new Exception('Passwords do not match');
    }
    
    if (!$termsAccepted) {
        throw new Exception('You must agree to Terms & Conditions');
    }
    
    if (!$privacyAccepted) {
        throw new Exception('You must agree to Privacy Policy');
    }
    
    // ===== reCAPTCHA VERIFICATION =====
    if (empty($recaptchaToken)) {
        throw new Exception('Please complete the reCAPTCHA verification');
    }
    
    if (!verifyRecaptcha($recaptchaToken)) {
        throw new Exception('reCAPTCHA verification failed. Please try again.');
    }
    
    // ===== CHECK IF EMAIL EXISTS =====
    $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $checkStmt->execute([$email]);
    
    if ($checkStmt->rowCount() > 0) {
        throw new Exception('Email already registered. Please login instead.');
    }
    
    // ===== GET CLINIC NAME =====
    $settingsStmt = $pdo->query("SELECT clinic_name FROM clinic_settings WHERE id = 1");
    $settings = $settingsStmt->fetch(PDO::FETCH_ASSOC);
    $clinicName = $settings['clinic_name'] ?? 'Dental Clinic';
    
    // ===== GENERATE AND SEND OTP =====
    $otp = generateOTP();
    
    // Save OTP to database
    $saved = saveOTP($pdo, $phone, $email, $otp);
    
    if (!$saved) {
        throw new Exception('Failed to save OTP. Please try again.');
    }
    
    // Send OTP email using PHPMailer
    $emailSent = sendRegistrationOTPEmail($email, $otp, $fullName, $clinicName);
    
    // Log result
    error_log("Registration OTP for $email: " . ($emailSent ? "SENT" : "FAILED"));
    
    if (!$emailSent) {
        // Continue anyway - user can request resend
        error_log("Registration OTP email failed for: $email");
    }
    
    // ===== STORE IN SESSION =====
    $_SESSION['pending_registration'] = [
        'fullName' => $fullName,
        'email' => $email,
        'phone' => $phone,
        'password' => password_hash($password, PASSWORD_DEFAULT),
        'termsAccepted' => $termsAccepted,
        'privacyAccepted' => $privacyAccepted,
        'otp' => $otp,
        'timestamp' => time()
    ];
    
  ob_clean();
echo json_encode([
    'success' => true,
    'message' => 'Verification code sent to your email'
]);
exit;
    
} catch (Exception $e) {
    error_log("Register Send OTP Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>