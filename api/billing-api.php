<?php
// Turn off error display to prevent HTML output
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

// Log errors instead of displaying them (kept outside the webroot if possible)
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/billing-api-error.log');

// Correct path from api/ to conn/
require_once '../conn/conn.php';
date_default_timezone_set('Asia/Manila');

// Set JSON header FIRST before any output
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Toggle verbose debug logging explicitly instead of always-on POST dumps
$DEBUG_LOG = false;
if ($DEBUG_LOG) {
    error_log('=== BILLING API CALLED ===');
    error_log('action=' . ($_POST['action'] ?? '') . ' id=' . ($_POST['id'] ?? ''));
}

// ---- Auth: mirror the same source of truth billing-history.php uses ----
// (session_id -> look up the patient row) rather than trusting extra
// session flags that may not be set by every login flow.
$patientId = (int)($_SESSION['user_id'] ?? 0);

if ($patientId <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized - Not logged in'
    ]);
    exit;
}

$stmt = $conn->prepare("SELECT * FROM users WHERE id = ? AND role = 'patient'");
if (!$stmt) {
    error_log('Prepare failed (users lookup): ' . $conn->error);
    echo json_encode(['success' => false, 'message' => 'Server error']);
    exit;
}
$stmt->bind_param("i", $patientId);
$stmt->execute();
$patient = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$patient) {
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized - Not a patient'
    ]);
    exit;
}

$patientEmail = $_SESSION['email'] ?? ($patient['email'] ?? '');
$patientPhone = $_SESSION['phone'] ?? ($patient['phone'] ?? '');
$patientName  = $patient['full_name'] ?? '';

// Only handle POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$action = $_POST['action'] ?? '';

// ===== GET BILLING DETAILS =====
if ($action === 'get_billing') {
    $billingId = (int)($_POST['id'] ?? 0);

    if ($billingId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid billing ID']);
        exit;
    }

    // Get billing details
    $stmt = $conn->prepare("
        SELECT b.*, a.appointment_date, a.appointment_time, a.email AS appt_email, a.phone AS appt_phone
        FROM billing b
        LEFT JOIN appointments a ON b.appointment_id = a.id
        WHERE b.id = ?
    ");
    if (!$stmt) {
        error_log('Prepare failed (billing lookup): ' . $conn->error);
        echo json_encode(['success' => false, 'message' => 'Server error']);
        exit;
    }
    $stmt->bind_param("i", $billingId);
    $stmt->execute();
    $billing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$billing) {
        echo json_encode(['success' => false, 'message' => 'Billing record not found']);
        exit;
    }

    // Ownership check — same matching logic as the patient's billing list:
    // match on the billing row's own patient fields OR the linked
    // appointment's email/phone. This mirrors the WHERE clause in
    // billing-history.php so anything visible in the list can also be
    // opened/printed here.
    $ownsRecord =
        (!empty($patientEmail) && $billing['patient_email'] === $patientEmail) ||
        (!empty($patientPhone) && $billing['patient_phone'] === $patientPhone) ||
        (!empty($patientName)  && $billing['patient_name']  === $patientName)  ||
        (!empty($patientEmail) && ($billing['appt_email'] ?? '') === $patientEmail) ||
        (!empty($patientPhone) && ($billing['appt_phone'] ?? '') === $patientPhone);

    if (!$ownsRecord) {
        echo json_encode([
            'success' => false,
            'message' => 'Unauthorized - This billing does not belong to you'
        ]);
        exit;
    }

    $services = json_decode($billing['services'] ?? '[]', true);

    echo json_encode([
        'success' => true,
        'billing' => $billing,
        'services' => $services
    ]);
    exit;
}

// ===== INVALID ACTION =====
echo json_encode(['success' => false, 'message' => 'Invalid action']);
exit;