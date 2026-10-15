<?php
require_once __DIR__ . '/../conn/conn.php';

header('Content-Type: application/json');

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    $appointmentId = isset($input['appointment_id']) ? (int)$input['appointment_id'] : 0;

    if ($appointmentId <= 0) {
        $response['message'] = 'Missing or invalid appointment_id';
        echo json_encode($response);
        exit;
    }

    $conditions = (isset($input['conditions']) && is_array($input['conditions']))
        ? implode(', ', array_map('trim', $input['conditions']))
        : '';

    // Normalize yes/no fields so we only ever store 'yes', 'no', or null
    $yesNo = function ($val) {
        $val = strtolower(trim((string)($val ?? '')));
        return in_array($val, ['yes', 'no'], true) ? $val : null;
    };

    $data = [
        'appointment_id'             => $appointmentId,
        'under_physician_care'       => $yesNo($input['under_physician_care'] ?? null),
        'physician_name'             => trim($input['physician_name'] ?? ''),
        'pregnant'                   => $yesNo($input['pregnant'] ?? null),
        'taking_medicine'            => $yesNo($input['taking_medicine'] ?? null),
        'medicine_details'           => trim($input['medicine_details'] ?? ''),
        'conditions'                 => $conditions,
        'other_condition_details'    => trim($input['other_condition_details'] ?? ''),
        'allergic_local_anesthesia'  => $yesNo($input['allergic_local_anesthesia'] ?? null),
        'allergic_antibiotics'       => $yesNo($input['allergic_antibiotics'] ?? null),
        'allergic_pain_killer'       => $yesNo($input['allergic_pain_killer'] ?? null),
        'allergic_other'             => $yesNo($input['allergic_other'] ?? null),
        'allergic_other_details'     => trim($input['allergic_other_details'] ?? ''),
        'hospitalized'               => $yesNo($input['hospitalized'] ?? null),
        'hospitalized_when'          => trim($input['hospitalized_when'] ?? ''),
        'hospitalized_why'           => trim($input['hospitalized_why'] ?? ''),
        'worn_denture'               => $yesNo($input['worn_denture'] ?? null),
        'last_dental_visit'          => trim($input['last_dental_visit'] ?? ''),
        'last_dental_visit_reason'   => trim($input['last_dental_visit_reason'] ?? ''),
    ];

    try {
        $stmt = $pdo->prepare("
            INSERT INTO medical_dental_history (
                appointment_id, under_physician_care, physician_name, pregnant,
                taking_medicine, medicine_details, conditions, other_condition_details,
                allergic_local_anesthesia, allergic_antibiotics, allergic_pain_killer,
                allergic_other, allergic_other_details,
                hospitalized, hospitalized_when, hospitalized_why,
                worn_denture, last_dental_visit, last_dental_visit_reason
            ) VALUES (
                :appointment_id, :under_physician_care, :physician_name, :pregnant,
                :taking_medicine, :medicine_details, :conditions, :other_condition_details,
                :allergic_local_anesthesia, :allergic_antibiotics, :allergic_pain_killer,
                :allergic_other, :allergic_other_details,
                :hospitalized, :hospitalized_when, :hospitalized_why,
                :worn_denture, :last_dental_visit, :last_dental_visit_reason
            )
        ");
        $stmt->execute($data);

        $response['success'] = true;
        $response['message'] = 'Medical & dental history saved successfully';
        $response['record_id'] = $pdo->lastInsertId();
    } catch (PDOException $e) {
        $response['message'] = 'Database error while saving medical history';
        // Log full detail server-side instead of exposing it to the client
        error_log('save_medical_history.php error: ' . $e->getMessage());
    }
} else {
    $response['message'] = 'Invalid request method';
}

echo json_encode($response);