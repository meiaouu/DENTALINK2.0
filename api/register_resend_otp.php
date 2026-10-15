<?php
/**
 * API: Resend OTP for Registration
 * File: api/register_resend_otp.php
 */

header('Content-Type: application/json');
 

require_once __DIR__ . '/../conn/conn.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

function generateOTP() {
    return str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
}

function saveOTP($pdo, $phone, $email, $otp) {
    try {
        $expiresAt = date('Y-m-d H:i:s', strtotime('+5 minutes'));
        
        $stmt = $pdo->prepare("DELETE FROM otp_verifications WHERE phone = ? AND email = ?");
        $stmt->execute([$phone, $email]);
        
        $stmt = $pdo->prepare("INSERT INTO otp_verifications (phone, email, otp_code, expires_at) VALUES (?, ?, ?, ?)");
        $result = $stmt->execute([$phone, $email, $otp, $expiresAt]);
        
        return $result;
    } catch (PDOException $e) {
        error_log("Save OTP Error: " . $e->getMessage());
        return false;
    }
}

function sendRegistrationOTPEmail($email, $otp, $fullName, $clinicName) {
    $mail = new PHPMailer(true);
    
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'santiagojhonny607@gmail.com';
        $mail->Password   = 'isyl kxdb oexp zkep';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        
        $mail->setFrom('santiagojhonny607@gmail.com', $clinicName);
        $mail->addAddress($email);
        
        $mail->isHTML(true);
        $mail->Subject = "🔄 New Verification Code - $clinicName";
        
        $mail->Body = "
            <html>
            <body style='font-family: Arial, sans-serif;'>
                <div style='max-width: 500px; margin: 0 auto; padding: 20px; background: #f8fafc; border-radius: 10px;'>
                    <div style='text-align: center; margin-bottom: 20px;'>
                        <h2 style='color: #0b1628; margin: 0;'>$clinicName</h2>
                        <p style='color: #64748b; font-size: 13px; margin: 4px 0 0;'>Your Dental Health Partner</p>
                    </div>
                    
                    <p style='color: #475569;'>Hello <strong>$fullName</strong>,</p>
                    <p style='color: #475569;'>You requested a new verification code:</p>
                    
                    <div style='background: white; padding: 20px; text-align: center; border-radius: 8px; border: 2px dashed #0d9e8c; margin: 20px 0;'>
                        <h1 style='font-size: 40px; letter-spacing: 8px; color: #0b1628; margin: 0;'>$otp</h1>
                    </div>
                    
                    <p style='text-align: center; color: #94a3b8; font-size: 14px;'>⏰ This code expires in 5 minutes.</p>
                    
                    <hr style='border: 1px solid #e2e8f0; margin: 20px 0;'>
                    <p style='text-align: center; color: #94a3b8; font-size: 12px;'>
                        This is an automated message. Please do not reply.
                    </p>
                </div>
            </body>
            </html>
        ";
        
        $mail->AltBody = "Your new verification code is: $otp\n\nValid for 5 minutes.";
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Resend OTP Email Error: " . $mail->ErrorInfo);
        return false;
    }
}

try {
    if (!isset($_SESSION['pending_registration'])) {
        throw new Exception('No pending registration found. Please start over.');
    }
    
    $pendingData = $_SESSION['pending_registration'];
    
    // Get clinic name
    $settingsStmt = $pdo->query("SELECT clinic_name FROM clinic_settings WHERE id = 1");
    $settings = $settingsStmt->fetch(PDO::FETCH_ASSOC);
    $clinicName = $settings['clinic_name'] ?? 'Dental Clinic';
    
    // Generate new OTP
    $otp = generateOTP();
    
    // Save OTP
    $saved = saveOTP($pdo, $pendingData['phone'], $pendingData['email'], $otp);
    
    if (!$saved) {
        throw new Exception('Failed to save OTP. Please try again.');
    }
    
    // Update session
    $_SESSION['pending_registration']['otp'] = $otp;
    
    // Send OTP email
    $emailSent = sendRegistrationOTPEmail($pendingData['email'], $otp, $pendingData['fullName'], $clinicName);
    
    if (!$emailSent) {
        throw new Exception('Failed to send OTP. Please try again.');
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'New verification code sent successfully'
    ]);
    
} catch (Exception $e) {
    error_log("Resend OTP Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>