<?php
require '../conn/conn.php';
date_default_timezone_set('Asia/Manila');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../pages/admin/patients.php');
    exit;
}

$patient_page_id = isset($_POST['patient_page_id']) ? (int)$_POST['patient_page_id'] : 0;
$history_id       = isset($_POST['history_id']) && $_POST['history_id'] !== '' ? (int)$_POST['history_id'] : null;
$appointment_id   = isset($_POST['appointment_id']) ? (int)$_POST['appointment_id'] : 0;

// Absolute path from the api folder to the admin folder
$redirect_base = '../pages/admin/patient_details.php?id=' . $patient_page_id;

if ($patient_page_id <= 0 || $appointment_id <= 0) {
    header('Location: ' . $redirect_base . '&saved=0');
    exit;
}

// Normalize yes/no fields so we only ever store 'yes', 'no', or null
$yesNo = function ($val) {
    $val = strtolower(trim((string)($val ?? '')));
    return in_array($val, ['yes', 'no'], true) ? $val : null;
};

// Build $data from the posted form fields
// (this block was missing in the original file, which is why nothing
// was actually being saved even though the redirect said saved=1)
$data = [
    'under_physician_care'      => $yesNo($_POST['under_physician_care'] ?? null),
    'physician_name'            => trim($_POST['physician_name'] ?? ''),
    'pregnant'                  => $yesNo($_POST['pregnant'] ?? null),
    'taking_medicine'           => $yesNo($_POST['taking_medicine'] ?? null),
    'medicine_details'          => trim($_POST['medicine_details'] ?? ''),
    'conditions'                => trim($_POST['conditions'] ?? ''),
    'other_condition_details'   => trim($_POST['other_condition_details'] ?? ''),
    'allergic_local_anesthesia' => $yesNo($_POST['allergic_local_anesthesia'] ?? null),
    'allergic_antibiotics'      => $yesNo($_POST['allergic_antibiotics'] ?? null),
    'allergic_pain_killer'      => $yesNo($_POST['allergic_pain_killer'] ?? null),
    'allergic_other'            => $yesNo($_POST['allergic_other'] ?? null),
    'allergic_other_details'    => trim($_POST['allergic_other_details'] ?? ''),
    'hospitalized'              => $yesNo($_POST['hospitalized'] ?? null),
    'hospitalized_when'         => trim($_POST['hospitalized_when'] ?? ''),
    'hospitalized_why'          => trim($_POST['hospitalized_why'] ?? ''),
    'worn_denture'              => $yesNo($_POST['worn_denture'] ?? null),
    'last_dental_visit'         => trim($_POST['last_dental_visit'] ?? ''),
    'last_dental_visit_reason'  => trim($_POST['last_dental_visit_reason'] ?? ''),
];

$ok = false;

if ($history_id) {
    // UPDATE query
    $sql = "
        UPDATE medical_dental_history SET
            appointment_id = ?,
            under_physician_care = ?,
            physician_name = ?,
            pregnant = ?,
            taking_medicine = ?,
            medicine_details = ?,
            conditions = ?,
            other_condition_details = ?,
            allergic_local_anesthesia = ?,
            allergic_antibiotics = ?,
            allergic_pain_killer = ?,
            allergic_other = ?,
            allergic_other_details = ?,
            hospitalized = ?,
            hospitalized_when = ?,
            hospitalized_why = ?,
            worn_denture = ?,
            last_dental_visit = ?,
            last_dental_visit_reason = ?
        WHERE id = ?
    ";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $types = "issssssssssssssssssi";
        $stmt->bind_param(
            $types,
            $appointment_id,
            $data['under_physician_care'],
            $data['physician_name'],
            $data['pregnant'],
            $data['taking_medicine'],
            $data['medicine_details'],
            $data['conditions'],
            $data['other_condition_details'],
            $data['allergic_local_anesthesia'],
            $data['allergic_antibiotics'],
            $data['allergic_pain_killer'],
            $data['allergic_other'],
            $data['allergic_other_details'],
            $data['hospitalized'],
            $data['hospitalized_when'],
            $data['hospitalized_why'],
            $data['worn_denture'],
            $data['last_dental_visit'],
            $data['last_dental_visit_reason'],
            $history_id
        );
        $ok = $stmt->execute();
        $stmt->close();
    }
} else {
    // INSERT query
    $sql = "
        INSERT INTO medical_dental_history (
            appointment_id, under_physician_care, physician_name, pregnant,
            taking_medicine, medicine_details, conditions, other_condition_details,
            allergic_local_anesthesia, allergic_antibiotics, allergic_pain_killer,
            allergic_other, allergic_other_details, hospitalized, hospitalized_when,
            hospitalized_why, worn_denture, last_dental_visit, last_dental_visit_reason
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $types = "issssssssssssssssss";
        $stmt->bind_param(
            $types,
            $appointment_id,
            $data['under_physician_care'],
            $data['physician_name'],
            $data['pregnant'],
            $data['taking_medicine'],
            $data['medicine_details'],
            $data['conditions'],
            $data['other_condition_details'],
            $data['allergic_local_anesthesia'],
            $data['allergic_antibiotics'],
            $data['allergic_pain_killer'],
            $data['allergic_other'],
            $data['allergic_other_details'],
            $data['hospitalized'],
            $data['hospitalized_when'],
            $data['hospitalized_why'],
            $data['worn_denture'],
            $data['last_dental_visit'],
            $data['last_dental_visit_reason']
        );
        $ok = $stmt->execute();
        $stmt->close();
    }
}

header('Location: ' . $redirect_base . '&saved=' . ($ok ? '1' : '0'));
exit;