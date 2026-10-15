<?php
/**
 * Appointment Reminder Email Sender (Settings-Aware)
 * Reads config from `email_reminder_settings` table.
 *
 * Run via cron:  0 8 * * * /usr/bin/php /path/to/cron/send_appointment_reminders.php
 * Or via URL:    https://yoursite.com/cron/send_appointment_reminders.php?key=YOUR_SECRET_KEY
 */

require_once __DIR__ . '/../conn/conn.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

date_default_timezone_set('Asia/Manila');

// ---- Security key (CHANGE THIS!) ----
$SECRET_KEY = 'CHANGE_THIS_TO_A_LONG_RANDOM_STRING_1234567890';

if (php_sapi_name() !== 'cli') {
    $key = $_GET['key'] ?? '';
    if (!hash_equals($SECRET_KEY, $key)) {
        http_response_code(403);
        exit('Forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

// ---- SMTP / Clinic config ----
$SMTP_HOST   = 'smtp.gmail.com';
$SMTP_USER   = 'santiagojhonny607@gmail.com';
$SMTP_PASS   = 'dtdf tayg xmsq xjoh';
$SMTP_PORT   = 587;
$CLINIC_NAME = 'Wansi Calacat Dental Clinic';
$LOGIN_URL   = 'http://127.0.0.1/dental/index.php';

// ---- Load reminder settings ----
$res = $conn->query("SELECT * FROM email_reminder_settings WHERE id = 1");
$settings = $res ? $res->fetch_assoc() : null;

if (!$settings) {
    echo "[!] No reminder settings found. Aborting.\n";
    exit;
}

if ((int)$settings['is_enabled'] !== 1) {
    echo "[" . date('Y-m-d H:i:s') . "] Auto reminders are OFF. Skipping.\n";
    exit;
}

$DAYS_AHEAD = max(1, (int)$settings['days_before']);
$targetDate = date('Y-m-d', strtotime("+{$DAYS_AHEAD} days"));

echo "[" . date('Y-m-d H:i:s') . "] Auto reminders ON. Checking appointments for {$targetDate} ({$DAYS_AHEAD} days ahead)\n";

// ---- Fetch eligible appointments ----
$sql = "SELECT id, full_name, email, appointment_date, appointment_time, services, phone
        FROM appointments
        WHERE appointment_date = ?
          AND status IN ('pending', 'approved', 'confirmed')
          AND email IS NOT NULL
          AND email != ''
          AND (reminder_sent IS NULL OR reminder_sent = 0)";

$stmt = $conn->prepare($sql);
$stmt->bind_param('s', $targetDate);
$stmt->execute();
$result = $stmt->get_result();

$sent = 0;
$failed = 0;

while ($appt = $result->fetch_assoc()) {

    // Resolve service names
    $serviceNames = 'your scheduled treatment';
    if (!empty($appt['services'])) {
        $decoded = json_decode($appt['services'], true);
        $ids = is_array($decoded) ? $decoded : array_filter(array_map('trim', explode(',', $appt['services'])));
        $ids = array_map('intval', $ids);

        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $types = str_repeat('i', count($ids));
            $svcStmt = $conn->prepare("SELECT title FROM services WHERE id IN ($placeholders)");
            $svcStmt->bind_param($types, ...$ids);
            $svcStmt->execute();
            $svcRes = $svcStmt->get_result();
            $titles = [];
            while ($s = $svcRes->fetch_assoc()) $titles[] = $s['title'];
            $svcStmt->close();
            if ($titles) $serviceNames = implode(', ', $titles);
        }
    }

    $ok = sendAppointmentReminderEmail(
        $appt['email'],
        $appt['full_name'],
        $appt['appointment_date'],
        $appt['appointment_time'],
        $serviceNames,
        $settings,
        $DAYS_AHEAD,
        $CLINIC_NAME,
        $SMTP_HOST,
        $SMTP_USER,
        $SMTP_PASS,
        $SMTP_PORT,
        $LOGIN_URL
    );

    if ($ok) {
        $upd = $conn->prepare("UPDATE appointments SET reminder_sent = 1, reminder_sent_at = NOW() WHERE id = ?");
        $upd->bind_param('i', $appt['id']);
        $upd->execute();
        $upd->close();
        $sent++;
        echo "  ✓ Sent to {$appt['email']} (appt #{$appt['id']})\n";
    } else {
        $failed++;
        echo "  ✗ Failed for {$appt['email']} (appt #{$appt['id']})\n";
    }
}

$stmt->close();

$summary = "[" . date('Y-m-d H:i:s') . "] Done. Sent: {$sent}, Failed: {$failed}\n";
echo $summary;
file_put_contents(__DIR__ . '/reminder_log.txt', $summary, FILE_APPEND);


// ============================================================
// EMAIL FUNCTION
// ============================================================
function sendAppointmentReminderEmail(
    $email, $full_name, $appointment_date, $appointment_time, $serviceNames,
    $settings, $daysAhead, $clinicName, $smtpHost, $smtpUser, $smtpPass, $smtpPort, $loginUrl
) {
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = $smtpHost;
        $mail->SMTPAuth   = true;
        $mail->Username   = $smtpUser;
        $mail->Password   = $smtpPass;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $smtpPort;

        $mail->setFrom($smtpUser, $clinicName);
        $mail->addAddress($email, $full_name);

        $mail->isHTML(true);
        $mail->Subject = $settings['subject'];

        $dateFormatted = date('F j, Y (l)', strtotime($appointment_date));
        $timeFormatted = date('g:i A', strtotime($appointment_time));

        // Replace placeholders in custom message
        $customMsg = $settings['message_body'];
        $customMsg = str_replace(
            ['{name}', '{date}', '{time}', '{service}', '{days}', '{clinic}'],
            [
                htmlspecialchars($full_name),
                $dateFormatted,
                $timeFormatted,
                htmlspecialchars($serviceNames),
                $daysAhead,
                $clinicName,
            ],
            $customMsg
        );
        // Convert newlines to <br>
        $customMsgHtml = nl2br($customMsg);

        $heading    = htmlspecialchars($settings['heading']);
        $buttonText = htmlspecialchars($settings['button_text']);
        $footerNote = htmlspecialchars($settings['footer_note']);
        $loginUrlEsc = htmlspecialchars($loginUrl);

        $mail->Body = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #0d9e8c; color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { padding: 20px; background: #f9f9f9; border-radius: 0 0 10px 10px; }
                .info-box { background: #e6f7f5; padding: 15px; border-left: 4px solid #0d9e8c; margin: 15px 0; border-radius: 5px; }
                .footer { text-align: center; padding: 20px; font-size: 12px; color: #777; }
                .btn { display: inline-block; padding: 10px 20px; background: #0d9e8c; color: white; text-decoration: none; border-radius: 5px; }
                .highlight { color: #0d9e8c; font-weight: bold; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2 style='margin:0;'>$clinicName</h2>
                    <p style='margin:5px 0 0;opacity:0.9;'>Appointment Reminder</p>
                </div>
                <div class='content'>
                    <h3 style='color:#0b1628;'>$heading</h3>
                    <p>Dear <strong>" . htmlspecialchars($full_name) . "</strong>,</p>

                    <div class='info-box'>
                        <p style='margin:0 0 8px;'><strong>📅 Date:</strong> $dateFormatted</p>
                        <p style='margin:0 0 8px;'><strong>⏰ Time:</strong> $timeFormatted</p>
                        <p style='margin:0;'><strong>🦷 Service:</strong> " . htmlspecialchars($serviceNames) . "</p>
                    </div>

                    <div style='margin: 15px 0;'>$customMsgHtml</div>

                    <div style='text-align:center;margin:20px 0;'>
                        <a href='$loginUrlEsc' class='btn'>$buttonText</a>
                    </div>

                    <p>Best regards,<br><strong>The $clinicName Team</strong></p>
                </div>
                <div class='footer'>
                    <p>$clinicName<br><small>$footerNote</small></p>
                </div>
            </div>
        </body>
        </html>
        ";

        // Plain text fallback
        $plain = str_replace(
            ['{name}', '{date}', '{time}', '{service}', '{days}', '{clinic}'],
            [$full_name, $dateFormatted, $timeFormatted, $serviceNames, $daysAhead, $clinicName],
            $settings['message_body']
        );

        $mail->AltBody  = "{$settings['subject']}\n\n";
        $mail->AltBody .= "Dear $full_name,\n\n";
        $mail->AltBody .= "Date: $dateFormatted\n";
        $mail->AltBody .= "Time: $timeFormatted\n";
        $mail->AltBody .= "Service: $serviceNames\n\n";
        $mail->AltBody .= $plain . "\n\n";
        $mail->AltBody .= "View details: $loginUrl\n\n";
        $mail->AltBody .= "Thank you,\n$clinicName Team";

        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log("Appointment Reminder Email Error: " . $mail->ErrorInfo);
        error_log("Error details: " . $e->getMessage());
        return false;
    }
}