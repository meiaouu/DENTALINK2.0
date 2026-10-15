<?php
require '../../conn/conn.php';
date_default_timezone_set('Asia/Manila');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

function h($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
function yn($v) {
    if ($v === 'yes') return '<span class="pill pill-yes">Yes</span>';
    if ($v === 'no') return '<span class="pill pill-no">No</span>';
    return '<span class="pill pill-unknown">—</span>';
}

// ---- Tooth naming configuration ----
function getToothName($num) {
    $names = [
        18 => '3rd Molar', 17 => '2nd Molar', 16 => '1st Molar',
        15 => '2nd Premolar', 14 => '1st Premolar',
        13 => 'Canine', 12 => 'Lateral Incisor', 11 => 'Central Incisor',
        21 => 'Central Incisor', 22 => 'Lateral Incisor', 23 => 'Canine',
        24 => '1st Premolar', 25 => '2nd Premolar',
        26 => '1st Molar', 27 => '2nd Molar', 28 => '3rd Molar',
        31 => 'Central Incisor', 32 => 'Lateral Incisor', 33 => 'Canine',
        34 => '1st Premolar', 35 => '2nd Premolar',
        36 => '1st Molar', 37 => '2nd Molar', 38 => '3rd Molar',
        48 => '3rd Molar', 47 => '2nd Molar', 46 => '1st Molar',
        45 => '2nd Premolar', 44 => '1st Premolar',
        43 => 'Canine', 42 => 'Lateral Incisor', 41 => 'Central Incisor',
        55 => '2nd Molar', 54 => '1st Molar', 53 => 'Canine',
        52 => 'Lateral Incisor', 51 => 'Central Incisor',
        61 => 'Central Incisor', 62 => 'Lateral Incisor', 63 => 'Canine',
        64 => '1st Molar', 65 => '2nd Molar',
        71 => 'Central Incisor', 72 => 'Lateral Incisor', 73 => 'Canine',
        74 => '1st Molar', 75 => '2nd Molar',
        85 => '2nd Molar', 84 => '1st Molar', 83 => 'Canine',
        82 => 'Lateral Incisor', 81 => 'Central Incisor',
    ];
    return $names[$num] ?? 'Tooth';
}

function getToothType($num) {
    if (in_array($num, [11,12,21,22,31,32,41,42, 51,52,61,62,71,72,81,82])) return 'Incisor';
    if (in_array($num, [13,23,33,43, 53,63,73,83])) return 'Canine';
    if (in_array($num, [14,15,24,25,34,35,44,45])) return 'Premolar';
    if (in_array($num, [16,17,18,26,27,28,36,37,38,46,47,48, 54,64,74,84,55,65,75,85])) return 'Molar';
    return 'Tooth';
}

$PRIMARY_TOOTH_NUMBERS = [55,54,53,52,51,61,62,63,64,65,71,72,73,74,75,81,82,83,84,85];

function isPrimaryToothNum($num) {
    global $PRIMARY_TOOTH_NUMBERS;
    return in_array((int)$num, $PRIMARY_TOOTH_NUMBERS);
}

$PRIMARY_TO_ADULT_IMAGE = [
    55 => 17, 54 => 16, 53 => 13, 52 => 12, 51 => 11,
    61 => 21, 62 => 22, 63 => 23, 64 => 26, 65 => 27,
    71 => 31, 72 => 32, 73 => 33, 74 => 36, 75 => 37,
    81 => 41, 82 => 42, 83 => 43, 84 => 46, 85 => 47,
];

function toothImage($num) {
    global $PRIMARY_TO_ADULT_IMAGE;
    $n = (int)$num;
    if (isset($PRIMARY_TO_ADULT_IMAGE[$n])) {
        $n = $PRIMARY_TO_ADULT_IMAGE[$n];
    }
    $prefix = ($n >= 11 && $n <= 28) ? 'sup' : 'inf';
    return "../../assets/img/dentadura-{$prefix}-{$n}.png";
}
$upperArch = [18, 17, 16, 15, 14, 13, 12, 11, 21, 22, 23, 24, 25, 26, 27, 28];
$lowerArch = [48, 47, 46, 45, 44, 43, 42, 41, 31, 32, 33, 34, 35, 36, 37, 38];

$primaryUpperArch = [55, 54, 53, 52, 51, 61, 62, 63, 64, 65];
$primaryLowerArch = [85, 84, 83, 82, 81, 71, 72, 73, 74, 75];

$dentalConditions = [
    'healthy'    => ['label' => 'Healthy',        'color' => '#cbd5e1'],
    'caries'     => ['label' => 'Caries / Decay', 'color' => '#dc3545'],
    'filled'     => ['label' => 'Filled',         'color' => '#1e40af'],
    'crown'      => ['label' => 'Crown',          'color' => '#b45309'],
    'root_canal' => ['label' => 'Root Canal',     'color' => '#7c3aed'],
    'missing'    => ['label' => 'Missing',        'color' => '#64748b'],
    'extraction' => ['label' => 'For Extraction', 'color' => '#7f1d1d'],
    'impacted'   => ['label' => 'Impacted',       'color' => '#ea580c'],
    'fractured'  => ['label' => 'Fractured',      'color' => '#db2777'],
    'other'      => ['label' => 'Other',          'color' => '#0891b2'],
];

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: appointments.php');
    exit;
}

// ---- Appointment ----
$stmt = $conn->prepare("SELECT * FROM appointments WHERE id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$appointment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$appointment) {
    header('Location: appointments.php');
    exit;
}

$patientKey = $appointment['phone'] ?: ($appointment['email'] ?: ('appt-' . $id));

$patientAge = is_numeric($appointment['age'] ?? null) ? (int)$appointment['age'] : null;
$defaultChartView = ($patientAge !== null && $patientAge < 13) ? 'kids' : 'adult';

// ========================= AJAX HANDLERS =========================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];

    switch ($_POST['action']) {

        case 'save_tooth':
            $tooth = preg_replace('/[^0-9]/', '', $_POST['tooth_number'] ?? '');
            $cond  = $_POST['condition_key'] ?? 'healthy';
            $notes = trim($_POST['notes'] ?? '');
            $allowedConditions = ['healthy', 'caries', 'filled', 'crown', 'root_canal', 'missing', 'extraction', 'impacted', 'fractured', 'other'];

            if ($tooth === '' || !in_array($cond, $allowedConditions)) {
                $response['message'] = 'Invalid tooth data.';
                break;
            }

            $updatedBy = $_SESSION['user_name'] ?? 'Staff';

            $stmt = $conn->prepare(
                "INSERT INTO dental_chart (patient_key, tooth_number, condition_key, notes, updated_by)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    condition_key = VALUES(condition_key),
                    notes = VALUES(notes),
                    updated_by = VALUES(updated_by),
                    updated_at = CURRENT_TIMESTAMP"
            );
            $stmt->bind_param('sssss', $patientKey, $tooth, $cond, $notes, $updatedBy);

            if ($stmt->execute()) {
                $response['success'] = true;
                $response['message'] = "Tooth {$tooth} updated.";
                $response['updated_by'] = $updatedBy;
                $response['updated_at'] = date('M j, Y g:i A');
            } else {
                $response['message'] = 'Failed to save tooth record.';
            }
            $stmt->close();
            break;

        case 'reset_tooth':
            $tooth = preg_replace('/[^0-9]/', '', $_POST['tooth_number'] ?? '');
            if ($tooth === '') {
                $response['message'] = 'Invalid tooth.';
                break;
            }
            $stmt = $conn->prepare("DELETE FROM dental_chart WHERE patient_key = ? AND tooth_number = ?");
            $stmt->bind_param('ss', $patientKey, $tooth);
            if ($stmt->execute()) {
                $response['success'] = true;
                $response['message'] = "Tooth {$tooth} reset to healthy.";
            } else {
                $response['message'] = 'Failed to reset tooth.';
            }
            $stmt->close();
            break;

        case 'update_personalInfo':
        case 'update_appointment':
            $appointmentId = (int)($_POST['appointment_id'] ?? 0);
            $data = json_decode($_POST['data'] ?? '{}', true);
            
            if (!$appointmentId || empty($data)) {
                $response['message'] = 'Invalid data.';
                break;
            }
            
            $updates = [];
            $params = [];
            $types = '';
            
            $allowedFields = ['full_name', 'phone', 'email', 'age', 'occupation', 
                             'street_address', 'barangay', 'city', 'province', 'region',
                             'appointment_date', 'appointment_time', 'status', 'notes', 'internal_notes',
                             'services'];
            
            foreach ($data as $field => $value) {
                if (in_array($field, $allowedFields)) {
                    $updates[] = "$field = ?";
                    $params[] = $value;
                    $types .= 's';
                }
            }
            
            if (array_key_exists('dentist_id', $data)) {
                $updates[] = "dentist_id = ?";
                $val = $data['dentist_id'];
                $params[] = ($val === '' || $val === null) ? null : (int)$val;
                $types .= 'i';
            }
            
            if (empty($updates)) {
                $response['message'] = 'No valid fields to update.';
                break;
            }
            
            // ✅ Bump updated_at so the receipt stale-check works
            $updates[] = "updated_at = CURRENT_TIMESTAMP";
            
            $params[] = $appointmentId;
            $types .= 'i';
            
            $sql = "UPDATE appointments SET " . implode(', ', $updates) . " WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            
            if ($stmt->execute()) {
                $response['success'] = true;
                $response['message'] = 'Appointment updated successfully.';
            } else {
                $response['message'] = 'Failed to update appointment: ' . $stmt->error;
            }
            $stmt->close();
            break;

        case 'update_medical':
            $appointmentId = (int)($_POST['appointment_id'] ?? 0);
            $data = json_decode($_POST['data'] ?? '{}', true);
            
            if (!$appointmentId || empty($data)) {
                $response['message'] = 'Invalid data.';
                break;
            }
            
            $updates = [];
            $params = [];
            $types = '';
            
            $allowedFields = ['under_physician_care', 'physician_name', 'pregnant', 'taking_medicine', 
                             'medicine_details', 'conditions', 'other_condition_details',
                             'allergic_local_anesthesia', 'allergic_antibiotics', 'allergic_pain_killer',
                             'allergic_other', 'allergic_other_details', 'hospitalized', 'hospitalized_when',
                             'hospitalized_why', 'worn_denture', 'last_dental_visit', 'last_dental_visit_reason'];
            
            foreach ($data as $field => $value) {
                if (in_array($field, $allowedFields)) {
                    $updates[] = "$field = ?";
                    $params[] = $value;
                    $types .= 's';
                }
            }
            
            if (empty($updates)) {
                $response['message'] = 'No valid fields to update.';
                break;
            }
            
            // ✅ Bump updated_at so the receipt stale-check works
            $updates[] = "updated_at = CURRENT_TIMESTAMP";
            
            $params[] = $appointmentId;
            $types .= 'i';
            
            $sql = "UPDATE medical_dental_history SET " . implode(', ', $updates) . " WHERE appointment_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            
            if ($stmt->execute()) {
                $response['success'] = true;
                $response['message'] = 'Medical history updated successfully.';
            } else {
                $response['message'] = 'Failed to update medical history: ' . $stmt->error;
            }
            $stmt->close();
            break;
    }

    echo json_encode($response);
    exit;
}

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

// ---- Active services (for the picker when editing) ----
$allServices = [];
$resAll = $conn->query("SELECT id, title, price FROM services WHERE is_active = 1 ORDER BY title");
if ($resAll) while ($row = $resAll->fetch_assoc()) $allServices[] = $row;

// ---- Currently selected service IDs (for pre-checking) ----
$selectedServiceIds = [];
foreach ($serviceDetails as $s) $selectedServiceIds[] = (int)$s['id'];

// ---- Active Dentists (for the dropdown) ----
$dentistsList = [];
$resDentists = $conn->query("SELECT id, first_name, last_name, specialization FROM dentists WHERE is_active = 1 ORDER BY first_name, last_name");
if ($resDentists) while ($row = $resDentists->fetch_assoc()) $dentistsList[] = $row;

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
$stmt->bind_param('i', $id);
$stmt->execute();
$history = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ---- Dental Chart ----
$stmt = $conn->prepare("SELECT tooth_number, condition_key, notes, updated_by, updated_at FROM dental_chart WHERE patient_key = ?");
$stmt->bind_param('s', $patientKey);
$stmt->execute();
$chartRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$chartMap = [];
foreach ($chartRows as $r) $chartMap[$r['tooth_number']] = $r;

$recordedMarks = count($chartMap);
$lastExamDate = null;
$lastUpdatedBy = null;
foreach ($chartMap as $r) {
    if (!$lastExamDate || $r['updated_at'] > $lastExamDate) {
        $lastExamDate = $r['updated_at'];
        $lastUpdatedBy = $r['updated_by'];
    }
}

// ---- Patient appointment history ----
$patientHistory = [];
if (!empty($appointment['phone']) || !empty($appointment['email'])) {
    $stmt = $conn->prepare("SELECT id, appointment_date, appointment_time, status, services FROM appointments WHERE (phone = ? OR email = ?) AND id != ? ORDER BY appointment_date DESC, appointment_time DESC");
    $stmt->bind_param('ssi', $appointment['phone'], $appointment['email'], $id);
    $stmt->execute();
    $patientHistory = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

function statusMeta($status) {
    $map = [
        'pending'   => ['label' => 'Pending',   'class' => 'st-pending'],
        'approved'  => ['label' => 'Approved',  'class' => 'st-approved'],
        'confirmed' => ['label' => 'Confirmed', 'class' => 'st-confirmed'],
        'completed' => ['label' => 'Completed', 'class' => 'st-completed'],
        'cancelled' => ['label' => 'Cancelled', 'class' => 'st-cancelled'],
        'rejected'  => ['label' => 'Rejected',  'class' => 'st-cancelled'],
    ];
    return $map[$status] ?? ['label' => ucfirst(str_replace('_', ' ', $status)), 'class' => 'st-default'];
}
$sm = statusMeta($appointment['status']);
$address = implode(', ', array_filter([$appointment['street_address'], $appointment['barangay'], $appointment['city'], $appointment['province'], $appointment['region']]));

include 'sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($appointment['full_name'] ?: 'Patient Record') ?> · Dental Clinic Dashboard</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
@import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700;800;900&family=DM+Sans:wght@400;500;600;700;800&display=swap');
* { font-family: 'DM Sans', system-ui, sans-serif; }
h1, h2, h3, h4 { font-family: 'Playfair Display', Georgia, serif; }
:root {
    --navy: #0b1628;
    --teal: #0d9e8c;
    --teal-dark: #0a7d6e;
    --teal-light: #e6f7f5;
    --slate: #64748b;
    --amber: #b45309;
    --amber-bg: #fef3d7;
    --red: #b91c1c;
    --red-bg: #fee9e7;
    --blue: #1e40af;
    --blue-bg: #dbeafe;
    --gray-bg: #eef1f6;
    --shadow-card: 0 2px 8px rgba(11,22,40,.06), 0 12px 32px rgba(11,22,40,.08);
}
.container-modern { max-width: 1200px; margin: 0 auto; padding: 0 1.5rem; }
@media (min-width: 1024px) { .main-content { margin-left: 260px; } }
@media (max-width: 1023px) { .main-content { margin-left: 0; } }
@media (max-width: 640px) { .container-modern { padding: 0 1rem; } }

.top-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 1.25rem 1.5rem; border-bottom: 1px solid #eef2f6; background: white;
}
.back-link {
    display:inline-flex; align-items:center; gap:8px; font-size:13px; font-weight:700;
    color: var(--slate); text-decoration:none;
}
.back-link:hover { color: var(--teal); }

.dashboard-card {
    background: white; border-radius: 18px; padding: 1.5rem;
    box-shadow: var(--shadow-card); border: 1px solid #f0f3f7;
    margin-bottom: 1.25rem;
}
.card-title {
    font-size: 15px; font-weight: 800; color: var(--navy); font-family:'DM Sans';
    display:flex; align-items:center; gap:8px; margin-bottom: 14px;
}
.card-title i { color: var(--teal); }

.status-badge {
    padding: 4px 12px; border-radius: 50px; font-size: 10.5px; font-weight: 700; text-transform: uppercase;
    letter-spacing: .3px; white-space: nowrap;
}
.st-pending   { background: var(--amber-bg); color: var(--amber); }
.st-approved  { background: var(--teal-light); color: var(--teal-dark); }
.st-confirmed { background: var(--blue-bg); color: var(--blue); }
.st-completed { background: #dcfce7; color: #166534; }
.st-cancelled { background: var(--red-bg); color: var(--red); }
.st-default   { background: var(--gray-bg); color: var(--slate); }

.detail-item .value select.editable-field,
.detail-item .edit-mode-dentist select {
    width: 100%;
    max-width: 100%;
    padding: 6px 10px;
    border: 1.5px solid #e2e8f0;
    border-radius: 8px;
    background: white;
    font-size: 13px;
    color: var(--navy);
    font-weight: 600;
    appearance: none;
    -webkit-appearance: none;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
    background-repeat: no-repeat;
    background-position: right 8px center;
    background-size: 14px;
    padding-right: 30px;
    transition: border-color .15s, box-shadow .15s;
}
.detail-item .value select.editable-field:focus,
.detail-item .edit-mode-dentist select:focus {
    outline: none;
    border-color: var(--teal);
    box-shadow: 0 0 0 3px rgba(13,158,140,.15);
}
.edit-mode .edit-mode-dentist select {
    border: 2px solid var(--teal);
    background-color: #f8fafc;
}

.collapsible-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    cursor: pointer;
    user-select: none;
}
.collapsible-header:hover .collapse-toggle-icon {
    color: var(--teal);
    transform: scale(1.1);
}
.collapse-toggle-icon {
    color: var(--slate);
    font-size: 14px;
    transition: transform .25s ease, color .15s ease;
    padding: 4px 8px;
    border-radius: 6px;
}
.collapsible-card.expanded .collapse-toggle-icon {
    transform: rotate(180deg);
}
.collapsible-body {
    max-height: 0;
    overflow: hidden;
    transition: max-height .35s ease;
}
.collapsible-card.expanded .collapsible-body {
    max-height: 5000px;
    transition: max-height .5s ease;
}
.collapsible-hint {
    font-size: 11.5px;
    color: #94a3b8;
    font-weight: 600;
    margin-top: 2px;
}

.service-picker { display: flex; flex-direction: column; gap: 8px; }

.service-pick-row {
    display: flex; align-items: center; gap: 10px;
    background: #f8fafc; border: 1.5px solid #e2e8f0;
    border-radius: 10px; padding: 10px 12px;
    transition: border-color .15s, background .15s;
}
.service-pick-row:hover { border-color: #cbd5e1; }

.service-pick-row .service-info { flex: 1; min-width: 0; }
.service-pick-row .service-title {
    font-size: 13px; font-weight: 700; color: var(--navy);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.service-pick-row .service-meta {
    font-size: 11px; color: #94a3b8; font-weight: 600; margin-top: 2px;
}
.service-pick-row .service-price {
    font-size: 13px; font-weight: 800; color: var(--navy);
    white-space: nowrap;
}
.service-pick-row .remove-service-btn {
    width: 30px; height: 30px; border-radius: 8px;
    background: var(--red-bg); color: var(--red);
    border: none; cursor: pointer; flex-shrink: 0;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 12px; transition: background .15s;
}
.service-pick-row .remove-service-btn:hover { background: var(--red); color: white; }

.service-empty {
    text-align: center; font-size: 12.5px; color: #94a3b8;
    padding: 18px 10px; background: #f8fafc; border-radius: 10px;
    border: 1.5px dashed #e2e8f0;
}

.service-picker-list {
    display: flex; flex-direction: column; gap: 6px;
    max-height: 320px; overflow-y: auto; padding: 2px;
}
.service-picker-item {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 12px; border-radius: 10px;
    border: 1.5px solid #e2e8f0; cursor: pointer;
    transition: all .15s; background: white;
}
.service-picker-item:hover { border-color: var(--teal); background: var(--teal-light); }
.service-picker-item.already-added { opacity: .5; cursor: not-allowed; background: #f8fafc; }
.service-picker-item .info { flex: 1; }
.service-picker-item .title { font-size: 13px; font-weight: 700; color: var(--navy); }
.service-picker-item .price { font-size: 12.5px; font-weight: 700; color: var(--teal-dark); }
.service-picker-item .badge-added {
    font-size: 10px; padding: 2px 8px; border-radius: 50px;
    background: var(--gray-bg); color: var(--slate); font-weight: 700;
    text-transform: uppercase;
}

.patient-header { display:flex; align-items:center; gap:16px; flex-wrap:wrap; }
.patient-avatar {
    width: 64px; height: 64px; border-radius: 16px; background: var(--teal-light);
    color: var(--teal-dark); display:flex; align-items:center; justify-content:center;
    font-size: 24px; font-weight: 800; flex-shrink:0;
}
.patient-name { font-size: 20px; font-weight: 800; color: var(--navy); }
.patient-sub { font-size: 12.5px; color: var(--slate); margin-top:2px; }

.detail-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px 20px; }
.detail-item span.label { display:block; font-size: 10.5px; color: #94a3b8; font-weight:700; text-transform:uppercase; letter-spacing:.4px; margin-bottom:3px; }
.detail-item .value { font-size: 13.5px; color: var(--navy); font-weight:600; }

.pill { display:inline-block; padding:2px 10px; border-radius:50px; font-size:11px; font-weight:700; }
.pill-yes { background: var(--red-bg); color: var(--red); }
.pill-no  { background: #dcfce7; color:#166534; }
.pill-unknown { background: var(--gray-bg); color: var(--slate); }

.service-line { display:flex; justify-content:space-between; padding:8px 0; font-size:13px; border-bottom:1px solid #f1f5f9; }
.service-total { display:flex; justify-content:space-between; padding:10px 0 0; font-weight:800; color:var(--navy); border-top:2px solid #e2e8f0; margin-top:6px; font-size:14px; }

.history-table { width:100%; border-collapse: collapse; font-size: 12.5px; }
.history-table th {
    text-align:left; padding: 8px 10px; background:#f7f9fc; color:#94a3b8;
    font-size: 10.5px; text-transform:uppercase; letter-spacing:.3px; font-weight:700;
}
.history-table td { padding: 10px; border-bottom: 1px solid #f1f5f9; color: var(--navy); }
.history-table tr:last-child td { border-bottom:none; }

.empty-note { color:#94a3b8; font-size: 13px; text-align:center; padding: 20px 0; }

.notes-box { background:#f7f9fc; border-radius:10px; padding:12px 14px; font-size:13px; color:var(--navy); line-height:1.6; }

.chart-topbar {
    display: flex; flex-wrap: wrap; gap: 20px; padding-bottom: 14px; margin-bottom: 16px;
    border-bottom: 1px solid #eef2f6;
}
.chart-topbar .stat { min-width: 140px; }
.chart-topbar .stat span.label { font-size: 10.5px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: .4px; display:block; margin-bottom: 3px; }
.chart-topbar .stat span.val { font-size: 13.5px; font-weight: 700; color: var(--navy); }

.legend { display: flex; flex-wrap: wrap; gap: 8px 14px; margin-bottom: 18px; }
.legend-item { display: flex; align-items: center; gap: 6px; font-size: 11px; font-weight: 600; color: var(--slate); }
.legend-dot { width: 11px; height: 11px; border-radius: 50%; flex-shrink: 0; border: 1px solid rgba(0,0,0,.08); }

.chart-view-toggle { display: flex; gap: 4px; background: #f1f4f8; padding: 4px; border-radius: 10px; }
.chart-toggle-btn {
    border: none; background: transparent; padding: 6px 14px; border-radius: 7px;
    font-size: 12px; font-weight: 700; color: var(--slate); cursor: pointer;
    display: inline-flex; align-items: center; gap: 6px; transition: all .15s;
}
.chart-toggle-btn:hover { color: var(--teal-dark); }
.chart-toggle-btn.active { background: white; color: var(--teal-dark); box-shadow: 0 1px 4px rgba(11,22,40,.1); }
.chart-age-hint {
    font-size: 11px; color: #94a3b8; font-weight: 600; margin-bottom: 14px;
    display: flex; align-items: center; gap: 6px;
}
.chart-age-hint i { color: #cbd5e1; }

.arch-label {
    text-align: center; font-size: 11px; font-weight: 700; color: #94a3b8;
    text-transform: uppercase; letter-spacing: 1px; padding: 4px 0 8px;
    border-bottom: 1px dashed #e2e8f0; margin-bottom: 8px;
}
.arch-row { display: flex; justify-content: center; gap: 4px; flex-wrap: wrap; margin-bottom: 6px; }
.arch-divider { border-top: 1px dashed #e2e8f0; margin: 10px 0 12px; }

.tooth-cell {
    display: flex; flex-direction: column; align-items: center; gap: 4px;
    cursor: pointer; padding: 6px 4px; border-radius: 10px; transition: background .15s;
    width: 65px; min-width: 55px;
}
.tooth-cell:hover { background: #f7f9fc; }
.tooth-img-wrap {
    width: 44px; height: 64px; border-radius: 8px; display: flex; align-items: center; justify-content: center;
    background: white; border: 2.5px solid var(--tooth-color, #cbd5e1);
    box-shadow: 0 1px 4px rgba(11,22,40,.06);
}
.tooth-img-wrap img { max-width: 100%; max-height: 100%; object-fit: contain; }
.tooth-cell[data-condition="missing"] .tooth-img-wrap img { opacity: .25; }
.tooth-num { font-size: 13px; font-weight: 700; color: var(--slate); line-height: 1.2; }
.tooth-name { font-size: 9.5px; font-weight: 600; color: #94a3b8; text-align: center; line-height: 1.1; max-width: 58px; }
.tooth-cell:hover .tooth-num { color: var(--teal-dark); }
.tooth-cell:hover .tooth-name { color: var(--teal-dark); }

.tooth-condition-badge {
    font-size: 8px; font-weight: 700; text-transform: uppercase; padding: 1px 6px;
    border-radius: 8px; background: rgba(0,0,0,0.05); color: #94a3b8;
    margin-top: 1px; line-height: 1.3; max-width: 56px; overflow: hidden;
    text-overflow: ellipsis; white-space: nowrap;
    text-align: center;
}

#kidsChartView .tooth-img-wrap { border-radius: 12px; }
#kidsChartView .arch-label { color: var(--teal-dark); }

@media (max-width: 768px) {
    .tooth-cell { width: 52px; min-width: 44px; padding: 4px 2px; gap: 3px; }
    .tooth-img-wrap { width: 36px; height: 52px; }
    .tooth-num { font-size: 11px; }
    .tooth-name { font-size: 7.5px; max-width: 46px; }
    .tooth-condition-badge { font-size: 6.5px; max-width: 44px; padding: 1px 4px; }
}
@media (max-width: 480px) {
    .tooth-cell { width: 42px; min-width: 36px; padding: 3px 1px; gap: 2px; }
    .tooth-img-wrap { width: 28px; height: 40px; }
    .tooth-num { font-size: 9px; }
    .tooth-name { font-size: 6px; max-width: 38px; }
    .tooth-condition-badge { font-size: 5.5px; max-width: 36px; padding: 0 3px; }
}

.modal-overlay {
    position: fixed; inset: 0; z-index: 80; background: rgba(11,22,40,.55);
    display: none; align-items: center; justify-content: center; padding: 1rem;
}
.modal-overlay.open { display: flex; }
.modal-box {
    background: white; border-radius: 18px; width: 100%; max-width: 440px;
    box-shadow: 0 24px 60px rgba(11,22,40,.35); overflow: hidden; max-height: 90vh; overflow-y: auto;
}
.modal-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 16px 20px; border-bottom: 1px solid #f0f3f7;
}
.modal-header h3 { font-size: 15px; font-weight: 700; color: var(--navy); font-family: 'DM Sans'; }
.modal-body { padding: 18px 20px; }
.modal-footer {
    display: flex; align-items: center; justify-content: space-between; gap: 8px;
    padding: 14px 20px; background: #fafbfc; border-top: 1px solid #f0f3f7;
}
.modal-close-btn { background: none; border: none; color: #94a3b8; font-size: 20px; cursor: pointer; }

.tooth-preview {
    width: 100%; height: 170px; border-radius: 14px; background: #f7f9fc;
    display: flex; flex-direction: column; align-items: center; justify-content: center; margin-bottom: 16px;
    border: 3px solid var(--tooth-color, #cbd5e1);
}
.tooth-preview img { max-height: 120px; }
.tooth-preview-info {
    text-align: center; margin-top: 6px; font-size: 12px; color: var(--slate);
}
.tooth-preview-info strong { color: var(--navy); }

.condition-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 7px; margin-bottom: 14px; }
.condition-opt {
    display: flex; align-items: center; gap: 7px; border: 1.5px solid #e2e8f0; border-radius: 9px;
    padding: 7px 9px; font-size: 11.5px; font-weight: 600; color: var(--navy); cursor: pointer;
}
.condition-opt input { accent-color: var(--teal); }
.condition-opt .dot { width: 11px; height: 11px; border-radius: 50%; flex-shrink: 0; }
.condition-opt.selected { border-color: var(--teal); background: var(--teal-light); }

.notes-textarea {
    width: 100%; border: 1.5px solid #e2e8f0; border-radius: 10px; padding: 9px 11px; font-size: 13px;
    min-height: 74px; resize: vertical;
}
.notes-textarea:focus { outline: none; border-color: var(--teal); box-shadow: 0 0 0 4px rgba(13,158,140,.1); }

.tooth-meta { font-size: 11px; color: #94a3b8; margin-top: 8px; }

.btn-xs {
    padding: 7px 13px; border-radius: 8px; font-size: 11.5px; font-weight: 700; border: none; cursor: pointer;
    display: inline-flex; align-items: center; gap: 5px;
}
.btn-view { background: #f1f4f8; color: var(--navy); }
.btn-view:hover { background: #e2e8f0; }
.btn-approve { background: var(--teal-light); color: var(--teal-dark); }
.btn-approve:hover { background: var(--teal); color: white; }

.edit-toggle {
    cursor: pointer; color: var(--slate); transition: all 0.3s ease;
    padding: 4px 8px; border-radius: 6px; background: transparent; border: none; font-size: 14px;
}
.edit-toggle:hover { color: var(--teal); background: var(--teal-light); transform: rotate(15deg); }

.edit-mode .editable-field {
    background: #f8fafc; border: 2px solid #0d9e8c; border-radius: 8px;
    padding: 4px 8px; transition: all 0.3s ease;
}
.edit-mode .editable-field:focus {
    outline: none; box-shadow: 0 0 0 3px rgba(13,158,140,0.2); background: white;
}
.edit-mode .editable-field:not(input):not(select):not(textarea) {
    display: block; min-height: 30px; cursor: text;
}
.edit-mode .editable-field input,
.edit-mode .editable-field select,
.edit-mode .editable-field textarea {
    width: 100%; padding: 6px 10px; border: 1px solid #e2e8f0;
    border-radius: 6px; background: white; font-size: 13px; color: #0b1628;
}
.edit-mode .editable-field input:focus,
.edit-mode .editable-field select:focus,
.edit-mode .editable-field textarea:focus {
    outline: none; border-color: #0d9e8c; box-shadow: 0 0 0 3px rgba(13,158,140,0.1);
}

.field-actions {
    display: none; gap: 10px; padding-top: 12px;
    border-top: 1px solid #e2e8f0; margin-top: 12px;
}
.edit-mode .field-actions { display: flex !important; }

.editable-field input, .editable-field select {
    width: 100%; padding: 6px 10px; border: 1px solid #e2e8f0;
    border-radius: 6px; background: white; font-size: 13px; color: #0b1628;
}
.editable-field input:focus, .editable-field select:focus {
    outline: none; border-color: #0d9e8c; box-shadow: 0 0 0 3px rgba(13,158,140,0.1);
}
</style>
</head>
<body>

<div class="main-content" style="
    min-height:100vh;
    background-color:#f7f9fc;
    background-image:
        linear-gradient(rgba(11,22,40,0.04) 1px, transparent 1px),
        linear-gradient(90deg, rgba(11,22,40,0.04) 1px, transparent 1px);
    background-size: 32px 32px;
    background-position: -1px -1px;
">

 <div class="top-header">
    <a href="appointments.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Appointments</a>
    <div class="flex items-center gap-2">
      <button type="button" class="btn-xs btn-approve" style="padding:9px 16px;font-size:12.5px;" onclick="confirmGenerateReceipt(<?= $id ?>)">
        <i class="fas fa-receipt"></i> Generate Patient Record
      </button>
      <button class="icon-btn" style="width:38px;height:38px;border-radius:10px;border:1px solid #e2e8f0;background:white;color:#64748b;"><i class="fas fa-bell"></i></button>
    </div>
</div>

  <main class="py-6">
    <div class="container-modern">

      <!-- Patient Header -->
      <div class="dashboard-card">
        <div class="patient-header">
          <div class="patient-avatar"><?= h(strtoupper(substr($appointment['full_name'] ?: '?', 0, 1))) ?></div>
          <div class="flex-1">
            <div class="flex items-center gap-3 flex-wrap">
              <div class="patient-name"><?= h($appointment['full_name'] ?: 'Unnamed Patient') ?></div>
              <span class="status-badge <?= $sm['class'] ?>"><?= h($sm['label']) ?></span>
            </div>
            <div class="patient-sub">
              <?php if ($appointment['age']): ?><?= (int)$appointment['age'] ?> yrs &middot; <?php endif; ?>
              <?= h($appointment['occupation'] ?: 'Occupation not specified') ?>
            </div>
          </div>
        </div>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
        <div class="lg:col-span-2 flex flex-col gap-5">

          <!-- Personal Information -->
          <div class="dashboard-card" id="personalInfoCard">
              <div class="px-6 py-4 border-b border-gray-100 bg-gradient-to-r from-blue-50/50 to-transparent">
                  <div class="flex items-center justify-between">
                      <div class="flex items-center gap-2.5">
                          <div class="w-8 h-8 rounded-lg bg-blue-100 flex items-center justify-center">
                              <i class="fas fa-id-card text-blue-600 text-sm"></i>
                          </div>
                          <h3 class="font-semibold text-gray-800">Personal Information</h3>
                      </div>
                      <button type="button" class="edit-toggle" onclick="toggleEdit('personalInfo')">
                          <i class="fas fa-pencil-alt"></i>
                      </button>
                  </div>
              </div>

              <div class="p-6" id="personalInfoDisplay">
                  <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-3">
                      <div>
                          <span class="text-sm text-gray-500">Full Name</span>
                          <div class="text-gray-900 font-medium editable-field" data-field="full_name" data-type="text"><?= h($appointment['full_name']) ?></div>
                      </div>
                      <div>
                          <span class="text-sm text-gray-500">Phone</span>
                          <div class="text-gray-900 font-medium editable-field" data-field="phone" data-type="text"><?= h($appointment['phone']) ?></div>
                      </div>
                      <div>
                          <span class="text-sm text-gray-500">Email</span>
                          <div class="text-gray-900 font-medium truncate editable-field" data-field="email" data-type="text"><?= h($appointment['email']) ?></div>
                      </div>
                      <div>
                          <span class="text-sm text-gray-500">Age</span>
                          <div class="text-gray-900 font-medium editable-field" data-field="age" data-type="number"><?= h($appointment['age'] ?: '—') ?></div>
                      </div>
                      <div>
                          <span class="text-sm text-gray-500">Occupation</span>
                          <div class="text-gray-900 font-medium editable-field" data-field="occupation" data-type="text"><?= h($appointment['occupation'] ?: '—') ?></div>
                      </div>
                  </div>
                  
                  <div class="pt-3 border-t border-gray-100 mt-3">
                      <span class="text-sm text-gray-500">Address</span>
                      <div class="space-y-2 mt-1">
                          <div class="text-gray-900 font-medium editable-field" data-field="street_address" data-type="text"><?= h($appointment['street_address'] ?: '—') ?></div>
                          <div class="grid grid-cols-2 gap-2">
                              <div class="text-gray-900 font-medium editable-field" data-field="barangay" data-type="text"><?= h($appointment['barangay'] ?: '—') ?></div>
                              <div class="text-gray-900 font-medium editable-field" data-field="city" data-type="text"><?= h($appointment['city'] ?: '—') ?></div>
                          </div>
                          <div class="grid grid-cols-2 gap-2">
                              <div class="text-gray-900 font-medium editable-field" data-field="province" data-type="text"><?= h($appointment['province'] ?: '—') ?></div>
                              <div class="text-gray-900 font-medium editable-field" data-field="region" data-type="text"><?= h($appointment['region'] ?: '—') ?></div>
                          </div>
                      </div>
                  </div>
              </div>
              
              <div class="field-actions px-6 pb-4" id="personalInfoActions" style="display:none;">
                  <button class="btn-xs btn-approve" onclick="saveEdits('personalInfo')">
                      <i class="fas fa-save"></i> Save Changes
                  </button>
                  <button class="btn-xs btn-view" onclick="cancelEdit('personalInfo')">
                      <i class="fas fa-times"></i> Cancel
                  </button>
              </div>
          </div>

          <!-- This Appointment -->
          <div class="dashboard-card" id="appointmentCard">
              <div class="card-title">
                  <i class="fas fa-calendar-check"></i> This Appointment
                  <button type="button" class="edit-toggle ml-auto" onclick="toggleEdit('appointment')">
                      <i class="fas fa-pencil-alt"></i>
                  </button>
              </div>
              <div id="appointmentDisplay">
                  <div class="detail-grid mb-4">
                      <div class="detail-item">
                          <span class="label">Date</span>
                          <div class="value editable-field" data-field="appointment_date" data-type="text">
                              <?= h($appointment['appointment_date'] ? date('M j, Y', strtotime($appointment['appointment_date'])) : '—') ?>
                          </div>
                      </div>
                      <div class="detail-item">
                          <span class="label">Time</span>
                          <div class="value editable-field" data-field="appointment_time" data-type="text">
                              <?= h($appointment['appointment_time'] ? date('g:i A', strtotime($appointment['appointment_time'])) : '—') ?>
                          </div>
                      </div>
                      <div class="detail-item" hidden>
                          <span class="label">Status</span>
                          <div class="value">
                              <select class="editable-field" data-field="status" style="padding:4px 8px;border-radius:6px;border:1px solid #e2e8f0;width:100%;">
                                  <option value="pending" <?= $appointment['status'] == 'pending' ? 'selected' : '' ?>>Pending</option>
                                  <option value="approved" <?= $appointment['status'] == 'approved' ? 'selected' : '' ?>>Approved</option>
                                  <option value="confirmed" <?= $appointment['status'] == 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
                                  <option value="completed" <?= $appointment['status'] == 'completed' ? 'selected' : '' ?>>Completed</option>
                                  <option value="cancelled" <?= $appointment['status'] == 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                  <option value="rejected" <?= $appointment['status'] == 'rejected' ? 'selected' : '' ?>>Rejected</option>
                              </select>
                          </div>
                      </div>

                      <div class="detail-item">
                          <span class="label">Dentist</span>

                          <div class="value editable-field view-mode-dentist" data-field="dentist_id" data-type="text">
                              <?= $dentist ? h('Dr. ' . $dentist['first_name'] . ' ' . $dentist['last_name']) : 'Unassigned' ?>
                          </div>

                          <div class="edit-mode-dentist" style="display:none;">
                              <select class="editable-field" data-field="dentist_id">
                                  <option value="">— Unassigned —</option>
                                  <?php foreach ($dentistsList as $d): ?>
                                      <option value="<?= (int)$d['id'] ?>" <?= (int)$appointment['dentist_id'] === (int)$d['id'] ? 'selected' : '' ?>>
                                          Dr. <?= h($d['first_name'] . ' ' . $d['last_name']) ?><?= $d['specialization'] ? ' — ' . h($d['specialization']) : '' ?>
                                      </option>
                                  <?php endforeach; ?>
                              </select>
                          </div>
                      </div>
                  </div>

                  <div class="card-title" style="font-size:12.5px; margin-bottom:6px;">
                      <i class="fas fa-tooth"></i> Services
                  </div>

                  <div id="servicesView">
                      <?php if ($serviceDetails): ?>
                          <?php foreach ($serviceDetails as $s): ?>
                              <div class="service-line">
                                  <span><?= h($s['title']) ?></span>
                                  <span>₱<?= number_format($s['price'], 2) ?></span>
                              </div>
                          <?php endforeach; ?>
                          <div class="service-total">
                              <span>Total</span>
                              <span>₱<?= number_format($totalPrice, 2) ?></span>
                          </div>
                      <?php else: ?>
                          <p class="empty-note">No services specified for this appointment.</p>
                      <?php endif; ?>
                  </div>

                  <div id="servicesEdit" style="display:none;">
                      <div class="service-picker" id="servicePicker"></div>

                      <button type="button" class="btn-xs btn-view" style="margin-top:10px;" onclick="openServicePicker()">
                          <i class="fas fa-plus"></i> Add Service
                      </button>

                      <div class="service-total" style="margin-top:14px;">
                          <span>Total</span>
                          <span id="servicesEditTotal">₱0.00</span>
                      </div>
                  </div>

                  <?php if (!empty($appointment['notes'])): ?>
                  <div class="mt-4">
                      <div class="card-title" style="font-size:12.5px; margin-bottom:6px;">
                          <i class="fas fa-comment-dots"></i> Patient Notes
                      </div>
                      <div class="notes-box editable-field" data-field="notes" data-type="textarea">
                          <?= nl2br(h($appointment['notes'])) ?>
                      </div>
                  </div>
                  <?php endif; ?>

                  <?php if (!empty($appointment['internal_notes'])): ?>
                  <div class="mt-4">
                      <div class="card-title" style="font-size:12.5px; margin-bottom:6px;">
                          <i class="fas fa-lock"></i> Internal Notes (Staff Only)
                      </div>
                      <div class="notes-box editable-field" data-field="internal_notes" data-type="textarea">
                          <?= nl2br(h($appointment['internal_notes'])) ?>
                      </div>
                  </div>
                  <?php endif; ?>
              </div>
              
              <div class="field-actions" id="appointmentActions" style="display:none;">
                  <button class="btn-xs btn-approve" onclick="saveEdits('appointment')">
                      <i class="fas fa-save"></i> Save Changes
                  </button>
                  <button class="btn-xs btn-view" onclick="cancelEdit('appointment')">
                      <i class="fas fa-times"></i> Cancel
                  </button>
              </div>
          </div>
        </div>

        <!-- Sidebar -->
        <div class="flex flex-col gap-5">
          <div class="dashboard-card">
            <div class="card-title"><i class="fas fa-history"></i> Appointment History</div>
            <?php if ($patientHistory): ?>
              <table class="history-table">
                <thead><tr><th>Date</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($patientHistory as $h): $hsm = statusMeta($h['status']); ?>
                  <tr style="cursor:pointer;" onclick="window.location.href='patient-record.php?id=<?= (int)$h['id'] ?>'">
                    <td>
                        <?= h($h['appointment_date'] ? date('M j, Y', strtotime($h['appointment_date'])) : '—') ?>
                        <div style="font-size:11px;color:#94a3b8;"><?= h($h['appointment_time'] ? date('g:i A', strtotime($h['appointment_time'])) : '') ?></div>
                    </td>
                    <td><span class="status-badge <?= $hsm['class'] ?>" style="font-size:9.5px;"><?= h($hsm['label']) ?></span></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            <?php else: ?>
              <p class="empty-note">No other appointments found for this patient.</p>
            <?php endif; ?>
          </div>

          <div class="dashboard-card">
            <div class="card-title"><i class="fas fa-shield-alt"></i> Consent</div>
            <div class="detail-grid space-y-5">
              <div class="detail-item"><span class="label">Terms Accepted</span><div class="value"><?= $appointment['terms_accepted'] ? yn('yes') : yn('no') ?></div></div>
              <div class="detail-item"><span class="label">Privacy Accepted</span><div class="value"><?= $appointment['privacy_accepted'] ? yn('yes') : yn('no') ?></div></div>
              <div class="detail-item"><span class="label">Verified</span><div class="value"><?= $appointment['is_verified'] ? yn('yes') : yn('no') ?></div></div>
            </div>
          </div>
        </div>
      </div>

      <!-- Dental Chart -->
      <div class="dashboard-card">
          <div class="card-title" style="justify-content: space-between; flex-wrap: wrap; gap: 10px;">
              <span><i class="fas fa-tooth"></i> Dental Chart / Intraoral Examination</span>
              <div class="chart-view-toggle" role="tablist" aria-label="Chart view">
                  <button type="button" id="adultToggleBtn" class="chart-toggle-btn <?= $defaultChartView === 'adult' ? 'active' : '' ?>" data-view="adult" onclick="switchChartView('adult')">
                      <i class="fas fa-user"></i> Adult
                  </button>
                  <button type="button" id="kidsToggleBtn" class="chart-toggle-btn <?= $defaultChartView === 'kids' ? 'active' : '' ?>" data-view="kids" onclick="switchChartView('kids')">
                      <i class="fas fa-child"></i> Kids
                  </button>
              </div>
          </div>

          <div class="chart-age-hint">
              <i class="fas fa-info-circle"></i>
              <?php if ($patientAge !== null): ?>
                  Patient is <?= (int)$patientAge ?> yrs old — showing the
                  <?= $defaultChartView === 'kids' ? 'primary (baby) teeth' : 'permanent (adult) teeth' ?> chart by default.
                  You can switch views above at any time (useful for mixed-dentition patients around ages 6–12).
              <?php else: ?>
                  No age on file — defaulting to the adult chart. Add the patient's age to auto-select the right chart.
              <?php endif; ?>
          </div>

          <div class="chart-topbar">
              <div class="stat"><span class="label">Latest Exam Date</span><span class="val" id="lastExamDate"><?= $lastExamDate ? h(date('M j, Y g:i A', strtotime($lastExamDate))) : '—' ?></span></div>
              <div class="stat"><span class="label">Updated By</span><span class="val" id="lastUpdatedBy"><?= h($lastUpdatedBy ?: '—') ?></span></div>
              <div class="stat"><span class="label">Recorded Marks</span><span class="val" id="recordedMarks"><?= $recordedMarks ?> mark(s)</span></div>
              <div class="stat" style="flex:1; min-width:180px;">
                  <span class="label">Notes / Findings</span>
                  <span class="val" id="latestFindings">
                      <?php
                      $latestNoteText = 'No updated notes recorded.';
                      foreach ($chartMap as $r) {
                          if (!empty($r['notes']) && $r['updated_at'] === $lastExamDate) { $latestNoteText = $r['notes']; break; }
                      }
                      echo h($latestNoteText);
                      ?>
                  </span>
              </div>
          </div>

          <div class="legend">
              <?php foreach ($dentalConditions as $key => $c): ?>
              <div class="legend-item"><span class="legend-dot" style="background:<?= $c['color'] ?>;"></span><?= h($c['label']) ?></div>
              <?php endforeach; ?>
          </div>

          <div id="adultChartView" style="display: <?= $defaultChartView === 'adult' ? 'block' : 'none' ?>;">
              <div class="arch-label">Upper Arch (Maxillary)</div>
              <div class="arch-row">
                  <?php foreach ($upperArch as $num): ?>
                      <?php
                      $entry = $chartMap[$num] ?? null;
                      $cond  = $entry['condition_key'] ?? 'healthy';
                      $color = $dentalConditions[$cond]['color'] ?? '#cbd5e1';
                      $toothName = getToothName($num);
                      $condLabel = $dentalConditions[$cond]['label'];
                      $badgeText = ($cond === 'other' && !empty($entry['notes'])) ? $entry['notes'] : $condLabel;
                      ?>
                      <div class="tooth-cell" data-tooth="<?= $num ?>" data-condition="<?= h($cond) ?>" onclick="openToothModal(<?= $num ?>)">
                          <div class="tooth-img-wrap" style="--tooth-color: <?= $color ?>;">
                              <img src="<?= h(toothImage($num)) ?>" alt="Tooth <?= $num ?>" onerror="this.style.opacity=0.15;">
                          </div>
                          <span class="tooth-num"><?= $num ?></span>
                          <span class="tooth-name"><?= h($toothName) ?></span>
                          <?php if ($cond !== 'healthy'): ?>
                              <span class="tooth-condition-badge" style="background:<?= $color ?>20; color:<?= $color ?>;" title="<?= h($badgeText) ?>"><?= h($badgeText) ?></span>
                          <?php endif; ?>
                      </div>
                  <?php endforeach; ?>
              </div>

              <div class="arch-divider"></div>

              <div class="arch-label">Lower Arch (Mandibular)</div>
              <div class="arch-row">
                  <?php foreach ($lowerArch as $num): ?>
                      <?php
                      $entry = $chartMap[$num] ?? null;
                      $cond  = $entry['condition_key'] ?? 'healthy';
                      $color = $dentalConditions[$cond]['color'] ?? '#cbd5e1';
                      $toothName = getToothName($num);
                      $condLabel = $dentalConditions[$cond]['label'];
                      $badgeText = ($cond === 'other' && !empty($entry['notes'])) ? $entry['notes'] : $condLabel;
                      ?>
                      <div class="tooth-cell" data-tooth="<?= $num ?>" data-condition="<?= h($cond) ?>" onclick="openToothModal(<?= $num ?>)">
                          <div class="tooth-img-wrap" style="--tooth-color: <?= $color ?>;">
                              <img src="<?= h(toothImage($num)) ?>" alt="Tooth <?= $num ?>" onerror="this.style.opacity=0.15;">
                          </div>
                          <span class="tooth-num"><?= $num ?></span>
                          <span class="tooth-name"><?= h($toothName) ?></span>
                          <?php if ($cond !== 'healthy'): ?>
                              <span class="tooth-condition-badge" style="background:<?= $color ?>20; color:<?= $color ?>;" title="<?= h($badgeText) ?>"><?= h($badgeText) ?></span>
                          <?php endif; ?>
                      </div>
                  <?php endforeach; ?>
              </div>
          </div>

          <div id="kidsChartView" style="display: <?= $defaultChartView === 'kids' ? 'block' : 'none' ?>;">
              <div class="arch-label">Upper Arch (Maxillary) — Primary Teeth</div>
              <div class="arch-row">
                  <?php foreach ($primaryUpperArch as $num): ?>
                      <?php
                      $entry = $chartMap[$num] ?? null;
                      $cond  = $entry['condition_key'] ?? 'healthy';
                      $color = $dentalConditions[$cond]['color'] ?? '#cbd5e1';
                      $toothName = getToothName($num);
                      $condLabel = $dentalConditions[$cond]['label'];
                      $badgeText = ($cond === 'other' && !empty($entry['notes'])) ? $entry['notes'] : $condLabel;
                      ?>
                      <div class="tooth-cell" data-tooth="<?= $num ?>" data-condition="<?= h($cond) ?>" onclick="openToothModal(<?= $num ?>)">
                          <div class="tooth-img-wrap" style="--tooth-color: <?= $color ?>;">
                              <img src="<?= h(toothImage($num)) ?>" alt="Tooth <?= $num ?>" onerror="this.style.opacity=0.15;">
                          </div>
                          <span class="tooth-num"><?= $num ?></span>
                          <span class="tooth-name"><?= h($toothName) ?></span>
                          <?php if ($cond !== 'healthy'): ?>
                              <span class="tooth-condition-badge" style="background:<?= $color ?>20; color:<?= $color ?>;" title="<?= h($badgeText) ?>"><?= h($badgeText) ?></span>
                          <?php endif; ?>
                      </div>
                  <?php endforeach; ?>
              </div>

              <div class="arch-divider"></div>

              <div class="arch-label">Lower Arch (Mandibular) — Primary Teeth</div>
              <div class="arch-row">
                  <?php foreach ($primaryLowerArch as $num): ?>
                      <?php
                      $entry = $chartMap[$num] ?? null;
                      $cond  = $entry['condition_key'] ?? 'healthy';
                      $color = $dentalConditions[$cond]['color'] ?? '#cbd5e1';
                      $toothName = getToothName($num);
                      $condLabel = $dentalConditions[$cond]['label'];
                      $badgeText = ($cond === 'other' && !empty($entry['notes'])) ? $entry['notes'] : $condLabel;
                      ?>
                      <div class="tooth-cell" data-tooth="<?= $num ?>" data-condition="<?= h($cond) ?>" onclick="openToothModal(<?= $num ?>)">
                          <div class="tooth-img-wrap" style="--tooth-color: <?= $color ?>;">
                              <img src="<?= h(toothImage($num)) ?>" alt="Tooth <?= $num ?>" onerror="this.style.opacity=0.15;">
                          </div>
                          <span class="tooth-num"><?= $num ?></span>
                          <span class="tooth-name"><?= h($toothName) ?></span>
                          <?php if ($cond !== 'healthy'): ?>
                              <span class="tooth-condition-badge" style="background:<?= $color ?>20; color:<?= $color ?>;" title="<?= h($badgeText) ?>"><?= h($badgeText) ?></span>
                          <?php endif; ?>
                      </div>
                  <?php endforeach; ?>
              </div>
          </div>
      </div>

      <!-- Medical & Dental History (collapsible) -->
      <div class="dashboard-card collapsible-card" id="medicalCard">
          <div class="collapsible-header" onclick="toggleMedicalHistory()">
              <div>
                  <div class="card-title" style="margin-bottom:2px;">
                      <i class="fas fa-file-medical"></i> Medical &amp; Dental History                  </div>
                  <div class="collapsible-hint" id="medicalHint">
                      <?= $history ? 'Click to view or edit the submitted medical/dental history form.' : 'No medical/dental history form submitted yet.' ?>
                  </div>
              </div>
              <div class="flex items-center gap-3">
                  <?php if ($history): ?>
                      <button type="button" class="edit-toggle" onclick="event.stopPropagation(); toggleEdit('medical')" title="Edit history">
                          <i class="fas fa-pencil-alt"></i>
                      </button>
                  <?php endif; ?>
                  <i class="fas fa-chevron-down collapse-toggle-icon"></i>
              </div>
          </div>

          <div class="collapsible-body">
              <div class="pt-4" id="medicalDisplay">
                  <?php if ($history): ?>
                      <div class="detail-grid mb-4">
                          <div class="detail-item">
                              <span class="label">Under physician's care</span>
                              <div class="value">
                                  <select class="editable-field" data-field="under_physician_care" style="padding:4px 8px;border-radius:6px;border:1px solid #e2e8f0;width:100%;">
                                      <option value="yes" <?= $history['under_physician_care'] == 'yes' ? 'selected' : '' ?>>Yes</option>
                                      <option value="no" <?= $history['under_physician_care'] == 'no' ? 'selected' : '' ?>>No</option>
                                  </select>
                              </div>
                          </div>
                          <div class="detail-item">
                              <span class="label">Physician name</span>
                              <div class="value editable-field" data-field="physician_name" data-type="text"><?= h($history['physician_name'] ?: '—') ?></div>
                          </div>
                          <div class="detail-item">
                              <span class="label">Pregnant</span>
                              <div class="value">
                                  <select class="editable-field" data-field="pregnant" style="padding:4px 8px;border-radius:6px;border:1px solid #e2e8f0;width:100%;">
                                      <option value="yes" <?= $history['pregnant'] == 'yes' ? 'selected' : '' ?>>Yes</option>
                                      <option value="no" <?= $history['pregnant'] == 'no' ? 'selected' : '' ?>>No</option>
                                  </select>
                              </div>
                          </div>
                          <div class="detail-item">
                              <span class="label">Taking medicine</span>
                              <div class="value">
                                  <select class="editable-field" data-field="taking_medicine" style="padding:4px 8px;border-radius:6px;border:1px solid #e2e8f0;width:100%;">
                                      <option value="yes" <?= $history['taking_medicine'] == 'yes' ? 'selected' : '' ?>>Yes</option>
                                      <option value="no" <?= $history['taking_medicine'] == 'no' ? 'selected' : '' ?>>No</option>
                                  </select>
                              </div>
                          </div>
                      </div>
                      
                      <div class="mb-4">
                          <span class="label" style="font-size:10.5px;color:#94a3b8;font-weight:700;text-transform:uppercase;">Medicine Details</span>
                          <div class="notes-box mt-1 editable-field" data-field="medicine_details" data-type="textarea">
                              <?= nl2br(h($history['medicine_details'] ?: '—')) ?>
                          </div>
                      </div>

                      <div class="mb-4">
                          <span class="label" style="font-size:10.5px;color:#94a3b8;font-weight:700;text-transform:uppercase;">Medical Conditions</span>
                          <div class="notes-box mt-1 editable-field" data-field="conditions" data-type="textarea">
                              <?= nl2br(h($history['conditions'] ?: '—')) ?>
                          </div>
                      </div>

                      <div class="mb-4">
                          <span class="label" style="font-size:10.5px;color:#94a3b8;font-weight:700;text-transform:uppercase;">Other Condition Details</span>
                          <div class="notes-box mt-1 editable-field" data-field="other_condition_details" data-type="textarea">
                              <?= nl2br(h($history['other_condition_details'] ?: '—')) ?>
                          </div>
                      </div>

                      <div class="mb-4">
                          <span class="label" style="font-size:10.5px;color:#94a3b8;font-weight:700;text-transform:uppercase;">Allergies</span>
                          <div class="grid grid-cols-1 md:grid-cols-2 gap-2 mt-2">
                              <div class="detail-item">
                                  <span class="label">Local Anesthesia</span>
                                  <div class="value">
                                      <select class="editable-field" data-field="allergic_local_anesthesia" style="padding:4px 8px;border-radius:6px;border:1px solid #e2e8f0;width:100%;">
                                          <option value="yes" <?= $history['allergic_local_anesthesia'] == 'yes' ? 'selected' : '' ?>>Yes</option>
                                          <option value="no" <?= $history['allergic_local_anesthesia'] == 'no' ? 'selected' : '' ?>>No</option>
                                      </select>
                                  </div>
                              </div>
                              <div class="detail-item">
                                  <span class="label">Antibiotics</span>
                                  <div class="value">
                                      <select class="editable-field" data-field="allergic_antibiotics" style="padding:4px 8px;border-radius:6px;border:1px solid #e2e8f0;width:100%;">
                                          <option value="yes" <?= $history['allergic_antibiotics'] == 'yes' ? 'selected' : '' ?>>Yes</option>
                                          <option value="no" <?= $history['allergic_antibiotics'] == 'no' ? 'selected' : '' ?>>No</option>
                                      </select>
                                  </div>
                              </div>
                              <div class="detail-item">
                                  <span class="label">Pain Killers</span>
                                  <div class="value">
                                      <select class="editable-field" data-field="allergic_pain_killer" style="padding:4px 8px;border-radius:6px;border:1px solid #e2e8f0;width:100%;">
                                          <option value="yes" <?= $history['allergic_pain_killer'] == 'yes' ? 'selected' : '' ?>>Yes</option>
                                          <option value="no" <?= $history['allergic_pain_killer'] == 'no' ? 'selected' : '' ?>>No</option>
                                      </select>
                                  </div>
                              </div>
                              <div class="detail-item">
                                  <span class="label">Other Allergies</span>
                                  <div class="value">
                                      <select class="editable-field" data-field="allergic_other" style="padding:4px 8px;border-radius:6px;border:1px solid #e2e8f0;width:100%;">
                                          <option value="yes" <?= $history['allergic_other'] == 'yes' ? 'selected' : '' ?>>Yes</option>
                                          <option value="no" <?= $history['allergic_other'] == 'no' ? 'selected' : '' ?>>No</option>
                                      </select>
                                  </div>
                              </div>
                          </div>
                          <div class="mt-2">
                              <span class="label" style="font-size:10.5px;color:#94a3b8;font-weight:700;text-transform:uppercase;">Other Allergy Details</span>
                              <div class="notes-box mt-1 editable-field" data-field="allergic_other_details" data-type="textarea">
                                  <?= nl2br(h($history['allergic_other_details'] ?: '—')) ?>
                              </div>
                          </div>
                      </div>

                      <div class="mb-4">
                          <span class="label" style="font-size:10.5px;color:#94a3b8;font-weight:700;text-transform:uppercase;">Hospitalization History</span>
                          <div class="grid grid-cols-1 md:grid-cols-2 gap-2 mt-2">
                              <div class="detail-item">
                                  <span class="label">Ever Hospitalized</span>
                                  <div class="value">
                                      <select class="editable-field" data-field="hospitalized" style="padding:4px 8px;border-radius:6px;border:1px solid #e2e8f0;width:100%;">
                                          <option value="yes" <?= $history['hospitalized'] == 'yes' ? 'selected' : '' ?>>Yes</option>
                                          <option value="no" <?= $history['hospitalized'] == 'no' ? 'selected' : '' ?>>No</option>
                                      </select>
                                  </div>
                              </div>
                              <div class="detail-item">
                                  <span class="label">When</span>
                                  <div class="value editable-field" data-field="hospitalized_when" data-type="text"><?= h($history['hospitalized_when'] ?: '—') ?></div>
                              </div>
                          </div>
                          <div class="mt-2">
                              <span class="label" style="font-size:10.5px;color:#94a3b8;font-weight:700;text-transform:uppercase;">Reason for Hospitalization</span>
                              <div class="notes-box mt-1 editable-field" data-field="hospitalized_why" data-type="textarea">
                                  <?= nl2br(h($history['hospitalized_why'] ?: '—')) ?>
                              </div>
                          </div>
                      </div>

                      <div class="mb-4">
                          <span class="label" style="font-size:10.5px;color:#94a3b8;font-weight:700;text-transform:uppercase;">Dental History</span>
                          <div class="grid grid-cols-1 md:grid-cols-2 gap-2 mt-2">
                              <div class="detail-item">
                                  <span class="label">Wears Denture</span>
                                  <div class="value">
                                      <select class="editable-field" data-field="worn_denture" style="padding:4px 8px;border-radius:6px;border:1px solid #e2e8f0;width:100%;">
                                          <option value="yes" <?= $history['worn_denture'] == 'yes' ? 'selected' : '' ?>>Yes</option>
                                          <option value="no" <?= $history['worn_denture'] == 'no' ? 'selected' : '' ?>>No</option>
                                      </select>
                                  </div>
                              </div>
                              <div class="detail-item">
                                  <span class="label">Last Dental Visit</span>
                                  <div class="value editable-field" data-field="last_dental_visit" data-type="text"><?= h($history['last_dental_visit'] ?: '—') ?></div>
                              </div>
                          </div>
                          <div class="mt-2">
                              <span class="label" style="font-size:10.5px;color:#94a3b8;font-weight:700;text-transform:uppercase;">Reason for Last Visit</span>
                              <div class="notes-box mt-1 editable-field" data-field="last_dental_visit_reason" data-type="textarea">
                                  <?= nl2br(h($history['last_dental_visit_reason'] ?: '—')) ?>
                              </div>
                          </div>
                      </div>
                  <?php else: ?>
                      <p class="empty-note">No medical/dental history form has been submitted for this appointment.</p>
                  <?php endif; ?>
              </div>

              <?php if ($history): ?>
              <div class="field-actions" id="medicalActions" style="display:none;">
                  <button class="btn-xs btn-approve" onclick="saveEdits('medical')">
                      <i class="fas fa-save"></i> Save Changes
                  </button>
                  <button class="btn-xs btn-view" onclick="cancelEdit('medical')">
                      <i class="fas fa-times"></i> Cancel
                  </button>
              </div>
              <?php endif; ?>
          </div>
      </div>
    </div>
  </main>
</div>

<!-- TOOTH MODAL -->
<div class="modal-overlay" id="toothModal">
  <div class="modal-box">
    <div class="modal-header">
      <h3 id="toothModalTitle">Tooth #</h3>
      <button class="modal-close-btn" onclick="closeToothModal()">&times;</button>
    </div>
    <div class="modal-body">
      <div class="tooth-preview" id="toothPreview">
        <img id="toothPreviewImg" src="" alt="">
        <div class="tooth-preview-info" id="toothPreviewInfo"></div>
      </div>

      <div class="detail-item" style="margin-bottom:10px;"><span class="label">Condition</span></div>
      <div class="condition-grid" id="conditionGrid"></div>

      <div id="otherConditionWrap" style="display:none; margin-bottom:14px;">
        <div class="detail-item" style="margin-bottom:6px;">
          <span class="label">Specify Condition <span style="color:#b91c1c;">*</span></span>
        </div>
        <input type="text" id="otherConditionInput" class="notes-textarea"
               style="min-height:auto;padding:9px 12px;"
               placeholder="e.g. Discoloration, Wear, Sensitivity, etc.">
      </div>

      <div class="detail-item" style="margin-bottom:6px;"><span class="label">Notes</span></div>
      <textarea class="notes-textarea" id="toothNotes" placeholder="Add findings for this tooth..."></textarea>

      <p class="tooth-meta" id="toothMeta"></p>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn-xs btn-view" onclick="resetTooth()"><i class="fas fa-rotate-left"></i> Reset</button>
      <div class="flex gap-2">
        <button type="button" class="btn-xs btn-view" onclick="closeToothModal()">Cancel</button>
        <button type="button" class="btn-xs btn-approve" onclick="saveTooth()"><i class="fas fa-save"></i> Save</button>
      </div>
    </div>
  </div>
</div>

<!-- SERVICE PICKER MODAL -->
<div class="modal-overlay" id="servicePickerModal">
  <div class="modal-box" style="max-width:520px;">
    <div class="modal-header">
      <h3>Add Service</h3>
      <button class="modal-close-btn" onclick="closeServicePicker()">&times;</button>
    </div>
    <div class="modal-body">
      <input type="text" id="serviceSearch" class="notes-textarea"
             style="min-height:auto;padding:9px 12px;margin-bottom:12px;"
             placeholder="Search services..."
             oninput="filterServicePicker(this.value)">
      <div class="service-picker-list" id="servicePickerList"></div>
    </div>
    <div class="modal-footer" style="justify-content:flex-end;">
      <button type="button" class="btn-xs btn-view" onclick="closeServicePicker()">Close</button>
    </div>
  </div>
</div>

<script>
const CONDITIONS = <?= json_encode($dentalConditions) ?>;

<?php
$allToothNumbers = array_merge($upperArch, $lowerArch, $primaryUpperArch, $primaryLowerArch);
$toothNamesJs = [];
foreach ($allToothNumbers as $tn) { $toothNamesJs[$tn] = getToothName($tn); }
?>
const TOOTH_NAMES = <?= json_encode($toothNamesJs) ?>;
const PRIMARY_TOOTH_NUMBERS = <?= json_encode(array_merge($primaryUpperArch, $primaryLowerArch)) ?>;
const PRIMARY_TO_ADULT_IMAGE = <?= json_encode($PRIMARY_TO_ADULT_IMAGE) ?>;

// ===== Services editing =====
const ALL_SERVICES = <?= json_encode($allServices) ?>;
let selectedServices = <?= json_encode(array_values($selectedServiceIds)) ?>;

let chartData = <?= json_encode($chartMap) ?>;
let currentTooth = null;

function toothImagePath(num) {
    num = parseInt(num, 10);
    const imgNum = PRIMARY_TO_ADULT_IMAGE[num] || num;
    const prefix = (imgNum >= 11 && imgNum <= 28) ? 'sup' : 'inf';
    return `../../assets/img/dentadura-${prefix}-${imgNum}.png`;
}

function buildConditionGrid(selectedKey) {
    const grid = document.getElementById('conditionGrid');
    grid.innerHTML = '';
    Object.entries(CONDITIONS).forEach(([key, c]) => {
        const label = document.createElement('label');
        label.className = 'condition-opt' + (key === selectedKey ? ' selected' : '');
        label.innerHTML = `
            <input type="radio" name="condition" value="${key}" ${key === selectedKey ? 'checked' : ''}>
            <span class="dot" style="background:${c.color};"></span>
            ${c.label}
        `;
        label.querySelector('input').addEventListener('change', () => {
            document.querySelectorAll('.condition-opt').forEach(el => el.classList.remove('selected'));
            label.classList.add('selected');
            toggleOtherConditionField(key);
        });
        grid.appendChild(label);
    });

    toggleOtherConditionField(selectedKey);
}

function toggleOtherConditionField(conditionKey) {
    const wrap = document.getElementById('otherConditionWrap');
    if (!wrap) return;
    wrap.style.display = (conditionKey === 'other') ? 'block' : 'none';
}

function openToothModal(num) {
    currentTooth = num;
    const entry = chartData[num] || null;
    const cond = entry ? entry.condition_key : 'healthy';
    const toothName = TOOTH_NAMES[num] || 'Tooth';
    const isPrimary = PRIMARY_TOOTH_NUMBERS.includes(parseInt(num, 10));

    document.getElementById('toothModalTitle').textContent = `Tooth #${num} — ${toothName}${isPrimary ? ' (Primary)' : ''}`;
    document.getElementById('toothPreviewImg').src = toothImagePath(num);
    document.getElementById('toothPreview').style.setProperty('--tooth-color', CONDITIONS[cond].color);
    document.getElementById('toothPreviewInfo').textContent = `${toothName} (${num})${isPrimary ? ' · Baby tooth' : ''}`;
    document.getElementById('toothNotes').value = entry ? (entry.notes || '') : '';
    document.getElementById('otherConditionInput').value =
        (entry && entry.condition_key === 'other' && entry.notes) ? entry.notes : '';

    const meta = document.getElementById('toothMeta');
    meta.textContent = entry && entry.updated_by
        ? `Last updated by ${entry.updated_by} on ${entry.updated_at}`
        : 'No record yet for this tooth.';

    buildConditionGrid(cond);

    document.getElementById('toothModal').classList.add('open');
}

function toggleMedicalHistory() {
    const card = document.getElementById('medicalCard');
    card.classList.toggle('expanded');
}

function renderServicePicker() {
    const container = document.getElementById('servicePicker');
    container.innerHTML = '';

    if (selectedServices.length === 0) {
        container.innerHTML = '<div class="service-empty">No services selected. Click "Add Service" to add one.</div>';
        document.getElementById('servicesEditTotal').textContent = '₱0.00';
        return;
    }

    let total = 0;
    selectedServices.forEach(id => {
        const svc = ALL_SERVICES.find(s => parseInt(s.id) === parseInt(id));
        if (!svc) return;
        total += parseFloat(svc.price);

        const row = document.createElement('div');
        row.className = 'service-pick-row';
        row.innerHTML = `
            <div class="service-info">
                <div class="service-title">${svc.title}</div>
            </div>
            <div class="service-price">₱${parseFloat(svc.price).toLocaleString(undefined,{minimumFractionDigits:2, maximumFractionDigits:2})}</div>
            <button type="button" class="remove-service-btn" title="Remove" onclick="removeService(${svc.id})">
                <i class="fas fa-times"></i>
            </button>
        `;
        container.appendChild(row);
    });

    document.getElementById('servicesEditTotal').textContent =
        '₱' + total.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function removeService(id) {
    selectedServices = selectedServices.filter(x => parseInt(x) !== parseInt(id));
    renderServicePicker();
}

function openServicePicker() {
    document.getElementById('serviceSearch').value = '';
    renderServiceList(ALL_SERVICES);
    document.getElementById('servicePickerModal').classList.add('open');
}

function closeServicePicker() {
    document.getElementById('servicePickerModal').classList.remove('open');
}

function renderServiceList(list) {
    const container = document.getElementById('servicePickerList');
    container.innerHTML = '';

    if (list.length === 0) {
        container.innerHTML = '<p class="empty-note">No services match your search.</p>';
        return;
    }

    list.forEach(svc => {
        const already = selectedServices.some(id => parseInt(id) === parseInt(svc.id));
        const item = document.createElement('div');
        item.className = 'service-picker-item' + (already ? ' already-added' : '');
        item.innerHTML = `
            <div class="info">
                <div class="title">${svc.title}</div>
                <div class="price">₱${parseFloat(svc.price).toLocaleString(undefined,{minimumFractionDigits:2, maximumFractionDigits:2})}</div>
            </div>
            ${already
                ? '<span class="badge-added">Added</span>'
                : '<i class="fas fa-plus" style="color:var(--teal);"></i>'}
        `;
        if (!already) {
            item.addEventListener('click', () => {
                selectedServices.push(svc.id);
                renderServicePicker();
                closeServicePicker();
            });
        }
        container.appendChild(item);
    });
}

function filterServicePicker(query) {
    const q = query.trim().toLowerCase();
    const filtered = q === ''
        ? ALL_SERVICES
        : ALL_SERVICES.filter(s => s.title.toLowerCase().includes(q));
    renderServiceList(filtered);
}

document.getElementById('servicePickerModal').addEventListener('click', function(e) {
    if (e.target === this) closeServicePicker();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeServicePicker();
});

// ==========================================
// GENERATE RECEIPT — with stale check
// ==========================================
function confirmGenerateReceipt(appointmentId) {
    Swal.fire({
        title: 'Generate Patient Record?',
        text: 'This will create or update the patient record snapshot with the current data.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#0d9e8c',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Yes, generate',
        reverseButtons: true
    }).then(result => {
        if (result.isConfirmed) {
            Swal.fire({
                title: 'Generating...',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });
            window.location.href = '../../api/generate_receipt.php?appointment_id=' + appointmentId;
        }
    });
}

function closeToothModal() {
    document.getElementById('toothModal').classList.remove('open');
    currentTooth = null;
}

function switchChartView(view) {
    document.getElementById('adultChartView').style.display = view === 'adult' ? 'block' : 'none';
    document.getElementById('kidsChartView').style.display = view === 'kids' ? 'block' : 'none';
    document.getElementById('adultToggleBtn').classList.toggle('active', view === 'adult');
    document.getElementById('kidsToggleBtn').classList.toggle('active', view === 'kids');
}

function updateToothCellUI(num, cond, notes) {
    const cell = document.querySelector(`.tooth-cell[data-tooth="${num}"]`);
    if (!cell) return;
    cell.dataset.condition = cond;
    const imgWrap = cell.querySelector('.tooth-img-wrap');
    if (imgWrap) imgWrap.style.setProperty('--tooth-color', CONDITIONS[cond].color);
    
    const existingBadge = cell.querySelector('.tooth-condition-badge');
    if (existingBadge) existingBadge.remove();
    
    if (cond !== 'healthy') {
        const badge = document.createElement('span');
        badge.className = 'tooth-condition-badge';
        badge.style.background = CONDITIONS[cond].color + '20';
        badge.style.color = CONDITIONS[cond].color;
        badge.textContent = (cond === 'other' && notes && notes.trim())
            ? notes.trim()
            : CONDITIONS[cond].label;
        badge.title = badge.textContent;
        cell.appendChild(badge);
    }
}

function refreshTopbar() {
    const entries = Object.values(chartData);
    document.getElementById('recordedMarks').textContent = entries.length + ' mark(s)';
    if (entries.length) {
        const latest = entries.reduce((a, b) => (a.updated_at > b.updated_at ? a : b));
        document.getElementById('lastExamDate').textContent = latest.updated_at;
        document.getElementById('lastUpdatedBy').textContent = latest.updated_by;
        document.getElementById('latestFindings').textContent = latest.notes || 'No updated notes recorded.';
    }
}

function saveTooth() {
    if (!currentTooth) return;
    const cond = document.querySelector('input[name="condition"]:checked').value;
    const otherText = document.getElementById('otherConditionInput').value.trim();
    const notes = document.getElementById('toothNotes').value;

    if (cond === 'other' && !otherText) {
        Swal.fire({
            icon: 'warning',
            title: 'Condition Required',
            text: 'Please specify the condition when selecting "Other".'
        });
        return;
    }

    // For "Other", use the typed text as the saved notes (so it shows on the badge/tooltip)
    const finalNotes = cond === 'other' ? otherText : notes;

    const formData = new FormData();
    formData.append('action', 'save_tooth');
    formData.append('tooth_number', currentTooth);
    formData.append('condition_key', cond);
    formData.append('notes', finalNotes);

    fetch(window.location.href, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                chartData[currentTooth] = {
                    tooth_number: String(currentTooth),
                    condition_key: cond,
                    notes: finalNotes,
                    updated_by: data.updated_by,
                    updated_at: data.updated_at
                };
                updateToothCellUI(currentTooth, cond, finalNotes);
                refreshTopbar();
                closeToothModal();
                Swal.fire({ icon: 'success', title: 'Saved', text: data.message, timer: 1200, showConfirmButton: false });
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: data.message });
            }
        });
}

function resetTooth() {
    if (!currentTooth) return;
    Swal.fire({
        title: `Reset tooth #${currentTooth} to healthy?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#0d9e8c',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Yes, reset',
        reverseButtons: true
    }).then(result => {
        if (!result.isConfirmed) return;
        const formData = new FormData();
        formData.append('action', 'reset_tooth');
        formData.append('tooth_number', currentTooth);

        fetch(window.location.href, { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    delete chartData[currentTooth];
                    updateToothCellUI(currentTooth, 'healthy');
                    refreshTopbar();
                    closeToothModal();
                    Swal.fire({ icon: 'success', title: 'Reset', text: data.message, timer: 1200, showConfirmButton: false });
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: data.message });
                }
            });
    });
}

document.getElementById('toothModal').addEventListener('click', function(e) {
    if (e.target === this) closeToothModal();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeToothModal();
});

// ==========================================
// EDIT MODE MANAGEMENT
// ==========================================
let editMode = {};

function toggleEdit(section) {
    const card = document.getElementById(section + 'Card');
    const display = document.getElementById(section + 'Display');
    const actions = document.getElementById(section + 'Actions');
    
    if (!editMode[section]) {
        editMode[section] = true;
        card.classList.add('edit-mode');

        if (card.classList.contains('collapsible-card')) {
            card.classList.add('expanded');
        }
        
        if (actions) actions.style.display = 'flex';

        if (section === 'appointment') {
            const viewEl = display.querySelector('.view-mode-dentist');
            const editEl = display.querySelector('.edit-mode-dentist');
            if (viewEl && editEl) {
                viewEl.style.display = 'none';
                editEl.style.display = 'block';
            }

            const servicesView = document.getElementById('servicesView');
            const servicesEdit = document.getElementById('servicesEdit');
            if (servicesView && servicesEdit) {
                servicesView.style.display = 'none';
                servicesEdit.style.display = 'block';
                renderServicePicker();
            }
        }
        
        const fields = display.querySelectorAll('.editable-field');
        fields.forEach(field => {
            if (field.tagName === 'INPUT' || field.tagName === 'SELECT' || field.tagName === 'TEXTAREA') return;
            if (field.dataset.field === 'dentist_id') return;
            if (field.closest('#servicesView') || field.closest('#servicesEdit')) return;

            const currentText = field.textContent.trim();
            const fieldName = field.dataset.field;
            const fieldType = field.dataset.type || 'text';
            
            let input;
            if (fieldType === 'textarea') {
                input = document.createElement('textarea');
                input.rows = 3;
            } else {
                input = document.createElement('input');
                input.type = fieldType === 'number' ? 'number' : 'text';
            }
            
            input.className = 'editable-field w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-teal focus:border-transparent';
            input.dataset.field = fieldName;
            input.dataset.type = fieldType;
            input.value = currentText !== '—' ? currentText : '';
            input.placeholder = 'Enter ' + fieldName.replace(/_/g, ' ');
            
            field.parentNode.replaceChild(input, field);
        });
        
        display.querySelectorAll('select.editable-field').forEach(select => {
            select.disabled = false;
            select.style.border = '2px solid #0d9e8c';
            select.style.background = 'white';
            select.style.padding = '6px 10px';
            select.style.borderRadius = '6px';
        });
    }
}

function cancelEdit(section) {
    editMode[section] = false;
    location.reload();
}

function saveEdits(section) {
    const display = document.getElementById(section + 'Display');
    const data = {};
    
    display.querySelectorAll('input.editable-field, textarea.editable-field, select.editable-field').forEach(el => {
        if (el.offsetParent === null) return;
        data[el.dataset.field] = el.value;
    });
    
    display.querySelectorAll('.editable-field:not(input):not(textarea):not(select)').forEach(el => {
        if (el.offsetParent === null) return;
        if (el.dataset && el.dataset.field && !(el.dataset.field in data)) {
            data[el.dataset.field] = el.textContent.trim();
        }
    });

    if (section === 'appointment') {
        data['services'] = JSON.stringify(selectedServices);
    }
    
    if (Object.keys(data).length === 0) {
        Swal.fire({
            icon: 'warning',
            title: 'No Changes',
            text: 'No fields were found to save.'
        });
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'update_' + section);
    formData.append('appointment_id', <?= $id ?>);
    formData.append('data', JSON.stringify(data));
    
    Swal.fire({
        title: 'Saving...',
        allowOutsideClick: false,
        didOpen: () => { Swal.showLoading(); }
    });
    
    fetch(window.location.href, { method: 'POST', body: formData })
    .then(r => r.json())
    .then(response => {
        Swal.close();
        if (response.success) {
            Swal.fire({
                icon: 'success',
                title: 'Saved!',
                text: response.message || 'Changes saved successfully.',
                timer: 1500,
                showConfirmButton: false
            });
            editMode[section] = false;
            setTimeout(() => location.reload(), 1500);
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: response.message || 'Failed to save changes.'
            });
        }
    })
    .catch(error => {
        Swal.close();
        console.error('Error saving:', error);
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'An error occurred while saving: ' + error.message
        });
    });
}
</script>

</body>
</html>