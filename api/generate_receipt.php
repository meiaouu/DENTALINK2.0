<?php
require '../conn/conn.php';
date_default_timezone_set('Asia/Manila');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

$appointmentId = isset($_GET['appointment_id']) ? (int)$_GET['appointment_id'] : 0;
if ($appointmentId <= 0) {
    header('Location: appointments.php');
    exit;
}

// ---- Load appointment ----
$stmt = $conn->prepare("SELECT * FROM appointments WHERE id = ?");
$stmt->bind_param('i', $appointmentId);
$stmt->execute();
$appointment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$appointment) {
    header('Location: appointments.php');
    exit;
}

$patientKey = $appointment['phone'] ?: ($appointment['email'] ?: ('appt-' . $appointmentId));

// ---- Services ----
$servicesMap = [];
$res = $conn->query("SELECT * FROM services");
if ($res) while ($row = $res->fetch_assoc()) $servicesMap[$row['id']] = $row;

$serviceDetails = [];
if (!empty($appointment['services'])) {
    $decoded = json_decode($appointment['services'], true);
    $serviceIds = is_array($decoded) ? $decoded : array_map('trim', explode(',', $appointment['services']));
    foreach ($serviceIds as $sid) {
        if (isset($servicesMap[$sid])) $serviceDetails[] = $servicesMap[$sid];
    }
}
$totalPrice = array_sum(array_column($serviceDetails, 'price'));

// ---- Dentist ----
$dentist = null;
if (!empty($appointment['dentist_id'])) {
    $stmt = $conn->prepare("SELECT * FROM dentists WHERE id = ?");
    $stmt->bind_param('i', $appointment['dentist_id']);
    $stmt->execute();
    $dentist = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// ---- Medical / Dental History ----
$stmt = $conn->prepare("SELECT * FROM medical_dental_history WHERE appointment_id = ? ORDER BY id DESC LIMIT 1");
$stmt->bind_param('i', $appointmentId);
$stmt->execute();
$history = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ---- Dental Chart ----
$stmt = $conn->prepare("SELECT tooth_number, condition_key, notes, updated_by, updated_at FROM dental_chart WHERE patient_key = ?");
$stmt->bind_param('s', $patientKey);
$stmt->execute();
$chartRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ---- Other appointments for the same patient ----
$patientHistory = [];
if (!empty($appointment['phone']) || !empty($appointment['email'])) {
    $stmt = $conn->prepare("SELECT id, appointment_date, appointment_time, status, services FROM appointments WHERE (phone = ? OR email = ?) AND id != ? ORDER BY appointment_date DESC, appointment_time DESC");
    $stmt->bind_param('ssi', $appointment['phone'], $appointment['email'], $appointmentId);
    $stmt->execute();
    $patientHistory = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// ---- Build snapshot ----
$snapshot = [
    'appointment'        => $appointment,
    'dentist'            => $dentist,
    'services'           => $serviceDetails,
    'total_price'        => $totalPrice,
    'medical_history'    => $history,
    'dental_chart'       => $chartRows,
    'other_appointments' => $patientHistory,
    'generated_at'       => date('Y-m-d H:i:s'),
];

$snapshotJson = json_encode($snapshot, JSON_UNESCAPED_UNICODE);

$generatedBy = $_SESSION['user_name'] ?? 'Staff';
$fullName    = $appointment['full_name'] ?? null;

// ---- Check for existing record ----
$stmt = $conn->prepare("SELECT id, receipt_code FROM patient_record_archive WHERE appointment_id = ? ORDER BY id DESC LIMIT 1");
$stmt->bind_param('i', $appointmentId);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($existing) {
    // ==== UPDATE existing record (overwrite snapshot) ====
    $stmt = $conn->prepare("
        UPDATE patient_record_archive
        SET snapshot_json = ?,
            generated_by  = ?,
            full_name     = ?,
            patient_key   = ?,
            created_at    = CURRENT_TIMESTAMP
        WHERE id = ?
    ");
    $stmt->bind_param('ssssi', $snapshotJson, $generatedBy, $fullName, $patientKey, $existing['id']);
    $stmt->execute();
    $stmt->close();

    header('Location: ../pages/admin/receipt.php?code=' . urlencode($existing['receipt_code']) . '&updated=1');
    exit;
} else {
    // ==== INSERT brand new record ====
    $receiptCode = 'RCPT-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));

    $stmt = $conn->prepare("
        INSERT INTO patient_record_archive
            (receipt_code, appointment_id, patient_key, full_name, snapshot_json, generated_by)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param('sissss', $receiptCode, $appointmentId, $patientKey, $fullName, $snapshotJson, $generatedBy);

    if ($stmt->execute()) {
        $stmt->close();
        header('Location: ../pages/admin/receipt.php?code=' . urlencode($receiptCode) . '&new=1');
        exit;
    }

    $stmt->close();
    header('Location: patient-record.php?id=' . $appointmentId . '&archive_error=1');
    exit;
}