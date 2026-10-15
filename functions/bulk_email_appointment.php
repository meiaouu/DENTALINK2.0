<?php
// functions/bulk_email_appointment.php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php'; // adjust path if your PHPMailer autoload is elsewhere

function sendAppointmentReminderEmail($email, $full_name, $appointment_date, $appointment_time, $services_text = '', $custom_message = '') {
            $mail = new PHPMailer(true);

    try {
        // --- Use the SAME Gmail SMTP config as your existing mailer functions ---
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
          $mail->Username   = 'santiagojhonny607@gmail.com';
        $mail->Password   = 'dtdf tayg xmsq xjoh';          // YOUR APP PASSWORD
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom('your-clinic-gmail@gmail.com', 'Wansi Calacat Dental Clinic');
        $mail->addAddress($email, $full_name);

        $mail->isHTML(true);
        $mail->Subject = 'Appointment Reminder - ' . date('F d, Y', strtotime($appointment_date));

        $niceDate = date('l, F d, Y', strtotime($appointment_date));
        $niceTime = date('g:i A', strtotime($appointment_time));

                  $custom_block = '';
        if ($custom_message !== '') {
            $safe_msg = nl2br(htmlspecialchars($custom_message, ENT_QUOTES, 'UTF-8'));
            $custom_block = "
                <div style='background:#e6f7f5; border-left:3px solid #0d9e8c; padding:12px 16px; border-radius:8px; margin:16px 0; font-size:14px; color:#0b1628;'>
                    {$safe_msg}
                </div>
            ";
        }

        $mail->Body = "
            <div style='font-family: DM Sans, Arial, sans-serif; max-width:600px; margin:auto;'>
                <h2 style='color:#0d9e8c;'>Appointment Reminder</h2>
                <p>Hi {$full_name},</p>
                <p>This is a reminder that you have a dental appointment scheduled:</p>
                <p><strong>Date:</strong> {$niceDate}<br>
                   <strong>Time:</strong> {$niceTime}<br>
                   " . ($services_text ? "<strong>Service(s):</strong> {$services_text}" : "") . "
                </p>
                {$custom_block}
                <p>Please arrive 10-15 minutes early. If you need to reschedule, contact us as soon as possible.</p>
                <p>Thank you,<br>Wansi Calacat Dental Clinic</p>
            </div>
        ";

        return $mail->send();
    } catch (Exception $e) {
        error_log('Reminder email failed for ' . $email . ': ' . $mail->ErrorInfo);
        return false;
    }
}