<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../conn/conn.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Enable this ONLY during testing — set to false before going live
define('DEBUG_MODE', false);

$data  = json_decode(file_get_contents('php://input'), true);
$email = trim($data['email'] ?? '');

$debug = [];

// ---------- Validate email format ----------
if ($email === '') {
    echo json_encode(['success' => false, 'message' => 'Please enter your email address.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
    exit;
}

// ---------- Look up user ----------
$stmt = $conn->prepare("SELECT id, full_name, email, is_active FROM users WHERE email = ? LIMIT 1");
if (!$stmt) {
    error_log('[FORGOT PW] DB prepare failed: ' . $conn->error);
    echo json_encode(['success' => false, 'message' => 'Server error. Please try again.']);
    exit;
}
$stmt->bind_param('s', $email);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ---------- User not found ----------
if (!$user) {
    error_log("[FORGOT PW] No user found for: $email");
    echo json_encode([
        'success' => false,
        'message' => 'No account found with that email address.'
    ]);
    exit;
}

// ---------- User inactive ----------
if ((int)$user['is_active'] !== 1) {
    error_log("[FORGOT PW] Inactive account: $email");
    echo json_encode([
        'success' => false,
        'message' => 'This account is inactive. Please contact the clinic directly.'
    ]);
    exit;
}

// ---------- Invalidate old tokens ----------
$conn->query("UPDATE password_resets SET used = 1 WHERE user_id = " . (int)$user['id'] . " AND used = 0");

// ---------- Create new token ----------
$token     = bin2hex(random_bytes(32));
$expiresAt = date('Y-m-d H:i:s', strtotime('+30 minutes'));

$insert = $conn->prepare("INSERT INTO password_resets (user_id, email, token, expires_at) VALUES (?, ?, ?, ?)");
if (!$insert) {
    error_log('[FORGOT PW] Insert prepare failed: ' . $conn->error);
    echo json_encode(['success' => false, 'message' => 'Server error. Please try again.']);
    exit;
}
$insert->bind_param('isss', $user['id'], $user['email'], $token, $expiresAt);
if (!$insert->execute()) {
    error_log('[FORGOT PW] Insert failed: ' . $insert->error);
    $insert->close();
    echo json_encode(['success' => false, 'message' => 'Server error while saving reset request. Please try again.']);
    exit;
}
$insert->close();

// ---------- Build reset link ----------
$protocol  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host      = $_SERVER['HTTP_HOST'];
$basePath  = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
$resetLink = "{$protocol}://{$host}{$basePath}/reset-password.php?token={$token}";

// ---------- Send email ----------
$clinicName = 'Wansi Calacat Dental Clinic';
$mail = new PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'santiagojhonny607@gmail.com';
    $mail->Password   = 'dtdf tayg xmsq xjoh';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;

    // $mail->SMTPDebug = 2; // uncomment for deep debugging

    $mail->setFrom('santiagojhonny607@gmail.com', $clinicName);
    $mail->addAddress($email, $user['full_name']);

    $mail->isHTML(true);
    $mail->Subject = "Password Reset Request - $clinicName";

    $safeName = htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8');

    $mail->Body = "
    <html>
    <body style='font-family:Arial,sans-serif;background:#f7f9fc;padding:30px;margin:0;'>
      <div style='max-width:520px;margin:0 auto;background:#fff;border-radius:14px;padding:32px;box-shadow:0 6px 24px rgba(11,22,40,0.08);'>
        <div style='text-align:center;margin-bottom:22px;'>
          <div style='display:inline-block;width:64px;height:64px;border-radius:50%;background:#e6f7f5;line-height:64px;color:#0d9e8c;font-size:26px;'>&#128274;</div>
        </div>
        <h2 style='color:#0b1628;margin:0 0 12px;font-size:20px;'>Password Reset Request</h2>
        <p style='color:#475569;font-size:14px;line-height:1.6;'>Hi $safeName,</p>
        <p style='color:#475569;font-size:14px;line-height:1.6;'>
          We received a request to reset your password. Click the button below to choose a new one.
        </p>
        <div style='text-align:center;margin:26px 0;'>
          <a href='$resetLink' style='display:inline-block;background:#0d9e8c;color:#ffffff;text-decoration:none;padding:14px 34px;border-radius:9px;font-weight:700;font-size:14px;'>
            Reset My Password
          </a>
        </div>
        <p style='color:#64748b;font-size:13px;line-height:1.6;'>This link expires in <strong>30 minutes</strong>.</p>
        <p style='color:#64748b;font-size:13px;line-height:1.6;'>If you didn't request this, you can safely ignore this email.</p>
        <p style='color:#94a3b8;font-size:11px;word-break:break-all;border-top:1px solid #e2e8f0;padding-top:16px;'>
          Or copy this URL:<br>$resetLink
        </p>
      </div>
    </body>
    </html>";

    $mail->AltBody = "Password Reset Request\n\nReset link (expires in 30 min):\n$resetLink\n";

    $mail->send();
    error_log("[FORGOT PW] Email sent to $email");

} catch (Exception $e) {
    error_log("[FORGOT PW] PHPMailer Error: " . $mail->ErrorInfo);
    error_log("[FORGOT PW] Reset link (backup): " . $resetLink);

    echo json_encode([
        'success' => false,
        'message' => 'Could not send the reset email. Please check your internet or contact the clinic directly.'
    ]);
    exit;
}

// ---------- Success ----------
echo json_encode([
    'success' => true,
    'message' => 'A password reset link has been sent to your email.'
]);

?>