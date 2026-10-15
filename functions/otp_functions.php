<?php
require_once __DIR__ . '/../conn/conn.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

function generateOTP() {
    return str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
}

// ====== UPDATED saveOTP FUNCTION ======
function saveOTP($pdo, $phone, $email, $otp) {
    try {
        $expiresAt = date('Y-m-d H:i:s', strtotime('+5 minutes'));
        
        // Delete existing OTPs for this phone/email
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

function sendOTPEmail($email, $otp, $clinicName, $appointment_id = null) {
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
        $mail->Subject = "Your OTP Code - $clinicName";
        
        // Generate tracking token if appointment_id is provided
        $tracking_link = '';
        if ($appointment_id) {
            $tracking_token = generateTrackingToken($appointment_id);
            $tracking_link = "https://your-clinic-domain.com/track-appointment.php?token=" . $tracking_token;
        }
        
        $mail->Body = "
            <html>
            <body style='font-family: Arial, sans-serif;'>
                <div style='max-width: 500px; margin: 0 auto; padding: 20px; background: #f8fafc; border-radius: 10px;'>
                    <div style='text-align: center; margin-bottom: 20px;'>
                        <h2 style='color: #0b1628; margin: 0;'>$clinicName</h2>
                        <p style='color: #64748b; font-size: 13px; margin: 4px 0 0;'>Your Dental Health Partner</p>
                    </div>
                    
                    <p style='color: #475569; text-align: center;'>Your verification code is:</p>
                    <div style='background: white; padding: 20px; text-align: center; border-radius: 8px; border: 2px dashed #0d9e8c;'>
                        <h1 style='font-size: 40px; letter-spacing: 8px; color: #0b1628; margin: 0;'>$otp</h1>
                    </div>
                    <p style='text-align: center; color: #94a3b8; font-size: 14px;'>This code expires in 5 minutes.</p>
                    
                    " . ($tracking_link ? "
                    <div style='background: #e6f7f5; padding: 15px; border-radius: 8px; margin: 15px 0; text-align: center;'>
                        <p style='margin: 0 0 8px; font-weight: 600; color: #0d9e8c;'>
                            <i class='fas fa-link' style='color: #0d9e8c;'></i> Track Your Appointment
                        </p>
                        <p style='margin: 0 0 10px; font-size: 13px; color: #475569;'>
                            You can track your appointment status anytime using the link below:
                        </p>
                        <a href='$tracking_link' style='display: inline-block; padding: 10px 20px; background: #0d9e8c; color: white; text-decoration: none; border-radius: 5px; font-weight: 600;'>
                            Track Appointment
                        </a>
                        <p style='margin-top: 8px; font-size: 11px; color: #94a3b8;'>
                            Or copy this link: $tracking_link
                        </p>
                    </div>
                    " : "") . "
                    
                    <hr style='border: 1px solid #e2e8f0; margin: 20px 0;'>
                    <p style='text-align: center; color: #94a3b8; font-size: 12px;'>
                        This is an automated message. Please do not reply.<br>
                        <span style='font-size: 11px;'>If you didn't request this, please ignore this email.</span>
                    </p>
                </div>
            </body>
            </html>
        ";
        $mail->AltBody = "Your OTP code is: $otp. Valid for 5 minutes.\n\n";
        if ($tracking_link) {
            $mail->AltBody .= "Track your appointment: $tracking_link\n\n";
        }
        $mail->AltBody .= "This is an automated message. Please do not reply.";
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Email Error: " . $mail->ErrorInfo);
        return false;
    }
}

function generateTrackingToken($appointment_id) {
    $secret = 'your-secret-key-here';
    $data = $appointment_id . '|' . time() . '|' . random_int(1000, 9999);
    return base64_encode($data);
}

function sendOTP($pdo, $phone, $email, $clinicName, $appointment_id = null) {
    $otp = generateOTP();
    
    if (!saveOTP($pdo, $phone, $email, $otp)) {
        return ['success' => false, 'message' => 'Failed to save OTP'];
    }
    
    $emailSent = sendOTPEmail($email, $otp, $clinicName, $appointment_id);
    
    if ($emailSent) {
        return ['success' => true, 'message' => 'OTP sent successfully'];
    } else {
        return ['success' => false, 'message' => 'Failed to send OTP via email'];
    }
}

function verifyOTP($pdo, $phone, $email, $otp) {
    $stmt = $pdo->prepare("
        SELECT id, expires_at 
        FROM otp_verifications 
        WHERE phone = ? AND email = ? AND otp_code = ? AND is_verified = 0
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$phone, $email, $otp]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$result) {
        return ['success' => false, 'message' => 'Invalid OTP'];
    }
    
    if (strtotime($result['expires_at']) < time()) {
        return ['success' => false, 'message' => 'OTP has expired'];
    }
    
    $stmt = $pdo->prepare("UPDATE otp_verifications SET is_verified = 1 WHERE id = ?");
    $stmt->execute([$result['id']]);
    
    return ['success' => true, 'message' => 'OTP verified successfully'];
}

function saveAppointment($pdo, $data) {
    try {
        $email = $data['email'];
        // Prefer the name the patient actually typed in Step 2;
        // only fall back to guessing from the email if it's blank.
        $full_name = trim($data['full_name'] ?? '') ?: (extractNameFromEmail($email) ?: 'Patient');

        // ================================================================
        // NOTE: User account creation has been MOVED to appointments.php.
        // Accounts are now created ONLY when an admin marks the appointment
        // as "Arrived at Clinic" (status = 'confirmed') via the
        // update_status AJAX handler. This keeps the booking flow from
        // silently creating orphaned user rows with unknown passwords.
        // ================================================================

        $stmt = $pdo->prepare("
            INSERT INTO appointments (
                phone, email, services, appointment_date, appointment_time,
                full_name, age, occupation,
                region, province, city, barangay, street_address, notes,
                terms_accepted, privacy_accepted, is_verified, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
 
        $result = $stmt->execute([
            $data['phone'],
            $data['email'],
            json_encode($data['services']),
            $data['date']            ?? null,
            $data['time']            ?? null,
            $full_name,
            !empty($data['age']) ? (int)$data['age'] : null,
            $data['occupation']      ?? null,
            $data['region']          ?? null,
            $data['province']        ?? null,
            $data['city']            ?? null,
            $data['barangay']        ?? null,
            $data['street_address']  ?? null,
            $data['notes']           ?? null,
            $data['termsAccepted'] ? 1 : 0,
            $data['privacyAccepted'] ? 1 : 0,
            1,
            'pending'
        ]);
 
        if ($result) {
            $appointment_id = $pdo->lastInsertId();
 
            $tracking_token = generateTrackingToken($appointment_id);
            $update_stmt = $pdo->prepare("UPDATE appointments SET tracking_token = ? WHERE id = ?");
            $update_stmt->execute([$tracking_token, $appointment_id]);
 
            return [
                'success' => true,
                'id' => $appointment_id,
                'token' => $tracking_token,
                'name' => $full_name,
                'message' => 'Appointment saved successfully'
            ];
        } else {
            return ['success' => false, 'message' => 'Failed to save appointment'];
        }
    } catch (PDOException $e) {
        error_log("Save Appointment Error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    }
}

// Helper function to extract name from email
function extractNameFromEmail($email) {
    // Remove everything after @
    $name = explode('@', $email)[0];
    
    // Replace dots, underscores, hyphens with spaces
    $name = str_replace(['.', '_', '-'], ' ', $name);
    
    // Remove numbers (optional - uncomment if you want to keep numbers)
    // $name = preg_replace('/[0-9]+/', '', $name);
    
    // Capitalize first letter of each word
    $name = ucwords(strtolower($name));
    
    // If name is empty, return null
    return !empty($name) ? $name : null;
}

 
?>