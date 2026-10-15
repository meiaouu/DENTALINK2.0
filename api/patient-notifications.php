<?php
require '../conn/conn.php';   // patient/api → patient → pages → root
date_default_timezone_set('Asia/Manila');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

// ---- Auth guard: patient must be logged in ----
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true
    || ($_SESSION['user_role'] ?? '') !== 'patient') {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$patientId    = (int)($_SESSION['user_id'] ?? 0);
$patientEmail = $_SESSION['user_email'] ?? '';
$patientPhone = $_SESSION['phone'] ?? '';

if ($patientId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid session']);
    exit;
}

$counts = [];

/* ============================================================
   1. UNREAD STAFF REPLIES (messages)
   Uses the patient_last_read_at timestamp instead of the is_read
   flag, because staff chat.php inserts staff messages with
   is_read = 1 (which makes the flag unusable for this purpose).
   ============================================================ */
$stmt = $conn->prepare("
    SELECT COUNT(*) AS c
    FROM chat_thread_messages m
    JOIN chat_conversations c ON c.id = m.conversation_id
    WHERE c.patient_id = ?
      AND m.sender_role IN ('staff','admin')
      AND (c.patient_last_read_at IS NULL OR m.created_at > c.patient_last_read_at)
");
$stmt->bind_param('i', $patientId);
$stmt->execute();
$counts['messages'] = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

/* ============================================================
   2. UPCOMING APPOINTMENTS (next 7 days, not cancelled/completed)
   ============================================================ */
if ($patientEmail !== '') {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS c
        FROM appointments
        WHERE email = ?
          AND status NOT IN ('cancelled','rejected','completed')
          AND appointment_date >= CURDATE()
          AND appointment_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    ");
    $stmt->bind_param('s', $patientEmail);
    $stmt->execute();
    $counts['upcoming_appointments'] = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
} else {
    $counts['upcoming_appointments'] = 0;
}

/* ============================================================
   3. PENDING APPOINTMENTS (awaiting staff review)
   ============================================================ */
if ($patientEmail !== '') {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS c
        FROM appointments
        WHERE email = ? AND status = 'pending'
    ");
    $stmt->bind_param('s', $patientEmail);
    $stmt->execute();
    $counts['pending_appointments'] = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
} else {
    $counts['pending_appointments'] = 0;
}

/* ============================================================
   4. UNPAID / PARTIAL BILLS
   ============================================================ */
$stmt = $conn->prepare("
    SELECT COUNT(*) AS c
    FROM billing
    WHERE (patient_email = ? OR patient_phone = ?)
      AND status IN ('unpaid','partial')
");
$stmt->bind_param('ss', $patientEmail, $patientPhone);
$stmt->execute();
$counts['unpaid_bills'] = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

/* ============================================================
   5. ACTIVE TREATMENTS (pending or in-progress)
   Resolve the patient row by email (patient_services uses the
   `patients` table id, not the `users` table id).
   ============================================================ */
$counts['active_treatments'] = 0;

if ($patientEmail !== '') {
    $stmt = $conn->prepare("SELECT id FROM patients WHERE email = ? LIMIT 1");
    $stmt->bind_param('s', $patientEmail);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        $patientRowId = (int)$row['id'];

        $stmt = $conn->prepare("
            SELECT COUNT(*) AS c
            FROM patient_services
            WHERE patient_id = ? AND status IN ('pending','in-progress')
        ");
        $stmt->bind_param('i', $patientRowId);
        $stmt->execute();
        $counts['active_treatments'] = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $stmt->close();
    }
}

/* ============================================================
   RESPONSE
   ============================================================ */
echo json_encode(['success' => true, 'counts' => $counts]);