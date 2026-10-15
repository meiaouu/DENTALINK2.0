<?php
require_once __DIR__ . '/../conn/conn.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

function generateRandomPassword($length = 10) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $password;
}

function sendWelcomeEmail($email, $full_name, $password, $clinicName = ' Wansi Calacat Dental Clinic') {
    $mail = new PHPMailer(true);
    
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'santiagojhonny607@gmail.com';
        $mail->Password   = 'dtdf tayg xmsq xjoh';          // YOUR APP PASSWORD
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        
        $mail->setFrom('santiagojhonny607@gmail.com', $clinicName);
        $mail->addAddress($email, $full_name);
        
        $mail->isHTML(true);
        $mail->Subject = "Welcome to $clinicName - Your Account Details";
        
        $mail->Body = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #0d9e8c; color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { padding: 20px; background: #f9f9f9; border-radius: 0 0 10px 10px; }
                .password-box { background: white; padding: 15px; border-left: 4px solid #0d9e8c; margin: 15px 0; border-radius: 5px; }
                .footer { text-align: center; padding: 20px; font-size: 12px; color: #777; }
                .btn { display: inline-block; padding: 10px 20px; background: #0d9e8c; color: white; text-decoration: none; border-radius: 5px; }
                .highlight { color: #0d9e8c; font-weight: bold; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2 style='margin:0;'>$clinicName</h2>
                    <p style='margin:5px 0 0;opacity:0.9;'>Your Dental Health Partner</p>
                </div>
                <div class='content'>
                    <h3 style='color:#0b1628;'>Welcome to Our Dental Family! 🦷</h3>
                    <p>Dear <strong>" . htmlspecialchars($full_name) . "</strong>,</p>
                    <p>We're delighted to inform you that your appointment has been <span class='highlight'>confirmed</span>! We look forward to providing you with excellent dental care.</p>
                    
                    <div style='background: #e6f7f5; padding: 15px; border-radius: 8px; margin: 15px 0;'>
                        <p style='margin:0;'><strong>Account Created:</strong> We've created a patient account for you to manage your appointments online.</p>
                    </div>
                    
                    <div class='password-box'>
                        <h4 style='margin-top:0;color:#0b1628;'>🔐 Your Login Credentials</h4>
                        <p><strong>Email:</strong> " . htmlspecialchars($email) . "</p>
                        <p><strong>Temporary Password:</strong> <code style='background:#f1f4f8;padding:4px 8px;border-radius:4px;font-size:16px;'>" . htmlspecialchars($password) . "</code></p>
                        <p style='font-size:12px;color:#e74c3c;'><strong>⚠️ Important:</strong> Please change your password after your first login.</p>
                    </div>
                    
                    <div style='text-align:center;margin:20px 0;'>
                        <a href='http://127.0.0.1/dental/index.php' class='btn'>Login to Your Account</a>
                    </div>
                    
                    <div style='background: #f8fafc; padding: 15px; border-radius: 8px;'>
                        <h4 style='margin-top:0;color:#0b1628;'>📋 What's Next?</h4>
                        <ul style='padding-left:20px;'>
                            <li>Log in to your patient portal</li>
                            <li>View and manage your appointments</li>
                            <li>Receive updates and reminders</li>
                        </ul>
                    </div>
                    
                    <p>If you have any questions, please don't hesitate to contact us.</p>
                    <p>Best regards,<br><strong>The $clinicName Team</strong></p>
                </div>
                <div class='footer'>
                    <p>$clinicName<br>
                    <small>This is an automated message. Please do not reply to this email.</small></p>
                </div>
            </div>
        </body>
        </html>
        ";
        
        $mail->AltBody = "Welcome to $clinicName!\n\n";
        $mail->AltBody .= "Dear $full_name,\n\n";
        $mail->AltBody .= "Your appointment has been confirmed!\n\n";
        $mail->AltBody .= "Account Details:\n";
        $mail->AltBody .= "Email: $email\n";
        $mail->AltBody .= "Temporary Password: $password\n\n";
        $mail->AltBody .= "Please change your password after first login.\n\n";
        $mail->AltBody .= "Login at: http://127.0.0.1/dental/index.php\n\n";
        $mail->AltBody .= "Thank you,\n$clinicName Team";
        
        $mail->send();
        return true;
        
    } catch (Exception $e) {
        error_log("Welcome Email Error: " . $mail->ErrorInfo);
        error_log("Error details: " . $e->getMessage());
        return false;
    }
}

function sendAppointmentConfirmationEmail($email, $full_name, $clinicName = ' Wansi Calacat Dental Clinic') {
    $mail = new PHPMailer(true);
    
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
         $mail->Username   = 'santiagojhonny607@gmail.com';
        $mail->Password   = 'dtdf tayg xmsq xjoh';  
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        
        $mail->setFrom('santiagojhonny607@gmail.com', $clinicName);
        $mail->addAddress($email, $full_name);
        
        $mail->isHTML(true);
        $mail->Subject = "Appointment Confirmed - $clinicName";
        
        $mail->Body = "
        <html>
        <body style='font-family: Arial, sans-serif;'>
            <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
                <div style='background: #0d9e8c; color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0;'>
                    <h2 style='margin:0;'>$clinicName</h2>
                </div>
                <div style='background: #f9f9f9; padding: 20px; border-radius: 0 0 10px 10px;'>
                    <p>Dear <strong>" . htmlspecialchars($full_name) . "</strong>,</p>
                    <p>Your appointment has been <strong style='color:#0d9e8c;'>confirmed</strong>.</p>
                    <p>You already have an account with us. You can log in to view your appointment details.</p>
                    <div style='text-align:center;margin:20px 0;'>
                        <a href='http://127.0.0.1/dental/index.php' style='display:inline-block;padding:10px 20px;background:#0d9e8c;color:white;text-decoration:none;border-radius:5px;'>Login to View Details</a>
                    </div>
                    <p>Thank you for choosing $clinicName.</p>
                </div>
                <div style='text-align:center;padding:20px;font-size:12px;color:#777;'>
                    <p>$clinicName</p>
                </div>
            </div>
        </body>
        </html>
        ";
        
        $mail->send();
        return true;
        
    } catch (Exception $e) {
        error_log("Appointment Confirmation Email Error: " . $mail->ErrorInfo);
        return false;
    }
}
function sendAppointmentApprovalEmail($email, $full_name, $clinicName = 'Wansi Calacat Dental Clinic') {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'santiagojhonny607@gmail.com';
        $mail->Password   = 'dtdf tayg xmsq xjoh';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom('santiagojhonny607@gmail.com', $clinicName);
        $mail->addAddress($email, $full_name);

        $mail->isHTML(true);
        $mail->Subject = "Your Appointment Has Been Approved - $clinicName";

        $mail->Body = "
        <html>
        <body style='font-family: Arial, sans-serif;'>
            <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
                <div style='background: #0d9e8c; color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0;'>
                    <h2 style='margin:0;'>$clinicName</h2>
                </div>
                <div style='background: #f9f9f9; padding: 20px; border-radius: 0 0 10px 10px;'>
                    <p>Dear <strong>" . htmlspecialchars($full_name) . "</strong>,</p>
                    <p>Good news! Your appointment request has been <strong style='color:#0d9e8c;'>approved</strong>.</p>
                    <p>Please wait for our team to confirm your schedule. Once a dentist has been assigned and your slot is finalized, we will notify you again with the details.</p>
                    <p>Thank you for choosing $clinicName.</p>
                </div>
                <div style='text-align:center;padding:20px;font-size:12px;color:#777;'>
                    <p>$clinicName</p>
                </div>
            </div>
        </body>
        </html>
        ";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Approval Email Error: " . $mail->ErrorInfo);
        return false;
    }
}



?>