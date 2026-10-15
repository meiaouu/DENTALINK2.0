<?php
// submit-feedback.php - Save patient feedback for a completed treatment
require_once '../../conn/conn.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

// Auth check
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// Look up patient by email
$stmt = $conn->prepare("SELECT email FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    echo json_encode(['success' => false, 'message' => 'User not found']);
    exit;
}

$stmt = $conn->prepare("SELECT id FROM patients WHERE email = ?");
$stmt->bind_param("s", $user['email']);
$stmt->execute();
$patient = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$patient) {
    echo json_encode(['success' => false, 'message' => 'No patient record']);
    exit;
}

$patient_id = (int)$patient['id'];

// Input
$patient_service_id = (int)($_POST['patient_service_id'] ?? 0);
$rating             = (int)($_POST['rating'] ?? 0);
$comment            = trim($_POST['comment'] ?? '');

if ($patient_service_id <= 0 || $rating < 1 || $rating > 5) {
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit;
}

if (mb_strlen($comment) > 500) {
    $comment = mb_substr($comment, 0, 500);
}

// Verify treatment belongs to this patient
$stmt = $conn->prepare("
    SELECT ps.id, ps.dentist_id
    FROM patient_services ps
    WHERE ps.id = ? AND ps.patient_id = ?
");
$stmt->bind_param("ii", $patient_service_id, $patient_id);
$stmt->execute();
$ps = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$ps) {
    echo json_encode(['success' => false, 'message' => 'Treatment not found']);
    exit;
}

// Verify treatment is fully completed
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) AS total,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS done
    FROM service_sessions
    WHERE patient_service_id = ?
");
$stmt->bind_param("i", $patient_service_id);
$stmt->execute();
$s = $stmt->get_result()->fetch_assoc();
$stmt->close();

$total_sessions = (int)($s['total'] ?? 0);
$done_sessions  = (int)($s['done'] ?? 0);

if ($total_sessions > 0 && $done_sessions < $total_sessions) {
    echo json_encode(['success' => false, 'message' => 'Treatment is not yet complete']);
    exit;
}

// Upsert feedback (one per treatment per patient)
$stmt = $conn->prepare("
    INSERT INTO feedback (patient_id, patient_service_id, dentist_id, rating, comment)
    VALUES (?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        rating = VALUES(rating),
        comment = VALUES(comment),
        updated_at = CURRENT_TIMESTAMP
");
$stmt->bind_param("iiiis", $patient_id, $patient_service_id, $ps['dentist_id'], $rating, $comment);
$ok = $stmt->execute();
$err = $stmt->error;
$stmt->close();

if ($ok) {
    echo json_encode(['success' => true, 'message' => 'Saved']);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $err]);
}