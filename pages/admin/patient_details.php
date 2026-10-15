<?php
require '../../conn/conn.php';
date_default_timezone_set('Asia/Manila');

// Get patient ID from URL
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    header('Location: patients.php');
    exit;
}

function safeStrToTime($date) {
    if (empty($date)) return 0;
    $timestamp = strtotime($date);
    return $timestamp !== false ? $timestamp : 0;
}

// Fetch patient details
$stmt = $conn->prepare("SELECT * FROM patients WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$patient = $result->fetch_assoc();
$stmt->close();

if (!$patient) {
    header('Location: patients.php');
    exit;
}

$current_date = date('Y-m-d');

// Fetch all appointments for this patient
$appointments = [];
$stmt = $conn->prepare("
    SELECT DISTINCT a.*, 
           d.first_name as dentist_first_name, 
           d.last_name as dentist_last_name 
    FROM appointments a
    LEFT JOIN dentists d ON a.dentist_id = d.id
    WHERE a.patient_id = ?
       OR (a.patient_id IS NULL AND ((a.phone <> '' AND a.phone = ?) OR (a.email <> '' AND a.email = ?) OR a.id = ?))
    ORDER BY a.appointment_date DESC, a.appointment_time DESC
");
$linked_appt_id = (int)($patient['appointment_id'] ?? 0);
$stmt->bind_param("issi", $id, $patient['phone'], $patient['email'], $linked_appt_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $appointments[] = $row;
}
$stmt->close();

// Categorize appointments
$upcoming_appointments = [];
$past_appointments = [];
$today_appointments = [];

foreach ($appointments as $appt) {
    $appt_date = $appt['appointment_date'];
    $status = $appt['status'] ?? 'pending';
    if ($status === 'cancelled') { $past_appointments[] = $appt; continue; }
    if ($appt_date === $current_date) $today_appointments[] = $appt;
    elseif ($appt_date > $current_date) $upcoming_appointments[] = $appt;
    else $past_appointments[] = $appt;
}

usort($upcoming_appointments, fn($a, $b) => safeStrToTime($a['appointment_date']) - safeStrToTime($b['appointment_date']));
usort($today_appointments,   fn($a, $b) => safeStrToTime($a['appointment_time'] ?? '00:00') - safeStrToTime($b['appointment_time'] ?? '00:00'));
usort($past_appointments,    fn($a, $b) => safeStrToTime($b['appointment_date']) - safeStrToTime($a['appointment_date']));

// Fetch the most recent medical/dental history record
$medical_history = null;
if (!empty($appointments)) {
    $appt_ids = array_column($appointments, 'id');
    $placeholders = implode(',', array_fill(0, count($appt_ids), '?'));
    $types = str_repeat('i', count($appt_ids));
    $stmt = $conn->prepare("
        SELECT * FROM medical_dental_history
        WHERE appointment_id IN ($placeholders)
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->bind_param($types, ...$appt_ids);
    $stmt->execute();
    $medical_history = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

function getServiceNames($conn, $services_json) {
    if (empty($services_json)) return ['No services', 0];
    $service_ids = json_decode($services_json, true);
    if (!is_array($service_ids) || empty($service_ids)) return ['No services', 0];
    $placeholders = implode(',', array_fill(0, count($service_ids), '?'));
    $types = str_repeat('i', count($service_ids));
    $stmt = $conn->prepare("SELECT title, price FROM services WHERE id IN ($placeholders)");
    $stmt->bind_param($types, ...$service_ids);
    $stmt->execute();
    $result = $stmt->get_result();
    $names = []; $total = 0;
    while ($row = $result->fetch_assoc()) {
        $names[] = $row['title'];
        $total += (float)$row['price'];
    }
    $stmt->close();
    return [implode(', ', $names), $total];
}

function getStatusBadgeClass($status) {
    switch ($status) {
        case 'confirmed': return 'status-confirmed';
        case 'approved':  return 'status-approved';
        case 'pending':   return 'status-pending';
        case 'cancelled': return 'status-cancelled';
        default:          return 'status-pending';
    }
}

function formatAppointmentDate($date) {
    if (empty($date)) return 'Not set';
    return date('F d, Y', strtotime($date));
}
function formatAppointmentTime($time) {
    if (empty($time)) return 'Not set';
    return date('g:i A', strtotime($time));
}

$patient_id_display = $patient['patient_id'] ?? 'PAT-' . str_pad($patient['id'], 6, '0', STR_PAD_LEFT);

// ==================== DENTAL EXAMINATION ====================
$patientKey = $patient['phone'] ?: ($patient['email'] ?: ('patient-' . $id));

$examConditions = [
    'healthy'  => ['label' => 'Healthy / Sound',    'color' => '#cbd5e1'],
    'caries'   => ['label' => 'Caries',             'color' => '#dc3545'],
    'filled'   => ['label' => 'Existing Filling',   'color' => '#1e40af'],
    'crown'    => ['label' => 'Existing Crown',     'color' => '#b45309'],
    'rct'      => ['label' => 'Root Canal Treated', 'color' => '#7c3aed'],
    'fractured'=> ['label' => 'Fractured',          'color' => '#db2777'],
    'mobile'   => ['label' => 'Mobile',             'color' => '#ea580c'],
    'worn'     => ['label' => 'Worn / Abraded',     'color' => '#0891b2'],
    'missing'  => ['label' => 'Missing',            'color' => '#64748b'],
    'other'    => ['label' => 'Other',              'color' => '#0f766e'],
];

$examSurfaces = ['M', 'D', 'B/F', 'L/P', 'O'];

$upperArch = [18,17,16,15,14,13,12,11,21,22,23,24,25,26,27,28];
$lowerArch = [48,47,46,45,44,43,42,41,31,32,33,34,35,36,37,38];
$primaryUpperArch = [55,54,53,52,51,61,62,63,64,65];
$primaryLowerArch = [85,84,83,82,81,71,72,73,74,75];

$PRIMARY_TO_ADULT_IMAGE = [
    55=>17,54=>16,53=>13,52=>12,51=>11,61=>21,62=>22,63=>23,64=>26,65=>27,
    71=>31,72=>32,73=>33,74=>36,75=>37,81=>41,82=>42,83=>43,84=>46,85=>47,
];
function toothImage($num) {
    global $PRIMARY_TO_ADULT_IMAGE;
    $n = (int)$num;
    if (isset($PRIMARY_TO_ADULT_IMAGE[$n])) $n = $PRIMARY_TO_ADULT_IMAGE[$n];
    $prefix = ($n >= 11 && $n <= 28) ? 'sup' : 'inf';
    return "../../assets/img/dentadura-{$prefix}-{$n}.png";
}
function getToothName($num) {
    $names = [
        18=>'3rd Molar',17=>'2nd Molar',16=>'1st Molar',15=>'2nd Premolar',14=>'1st Premolar',
        13=>'Canine',12=>'Lateral Incisor',11=>'Central Incisor',
        21=>'Central Incisor',22=>'Lateral Incisor',23=>'Canine',24=>'1st Premolar',25=>'2nd Premolar',
        26=>'1st Molar',27=>'2nd Molar',28=>'3rd Molar',
        31=>'Central Incisor',32=>'Lateral Incisor',33=>'Canine',34=>'1st Premolar',35=>'2nd Premolar',
        36=>'1st Molar',37=>'2nd Molar',38=>'3rd Molar',
        41=>'Central Incisor',42=>'Lateral Incisor',43=>'Canine',44=>'1st Premolar',45=>'2nd Premolar',
        46=>'1st Molar',47=>'2nd Molar',48=>'3rd Molar',
        55=>'2nd Molar',54=>'1st Molar',53=>'Canine',52=>'Lateral Incisor',51=>'Central Incisor',
        61=>'Central Incisor',62=>'Lateral Incisor',63=>'Canine',64=>'1st Molar',65=>'2nd Molar',
        71=>'Central Incisor',72=>'Lateral Incisor',73=>'Canine',74=>'1st Molar',75=>'2nd Molar',
        81=>'Central Incisor',82=>'Lateral Incisor',83=>'Canine',84=>'1st Molar',85=>'2nd Molar',
    ];
    return $names[(int)$num] ?? 'Tooth';
}

// Load the examination rows for this patient
$examMap = [];
$stmt = $conn->prepare("SELECT tooth_number, conditions, surfaces, notes, other_details, examined_by, examined_at FROM dental_examination WHERE patient_key = ?");
$stmt->bind_param('s', $patientKey);
$stmt->execute();
$examRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

foreach ($examRows as $r) {
    $conds = json_decode($r['conditions'], true) ?: [];
    $surfs = json_decode($r['surfaces'], true) ?: [];
    $examMap[$r['tooth_number']] = [
        'tooth_number' => $r['tooth_number'],
        'conditions'   => $conds,
        'surfaces'     => $surfs,
        'notes'        => $r['notes'],
        'other_details'=> $r['other_details'],
        'examined_by'  => $r['examined_by'],
        'examined_at'  => $r['examined_at'],
    ];
}

$examRecordedCount = count($examMap);
$examLatestDate = null; $examLatestBy = null;
foreach ($examMap as $r) {
    if (!$examLatestDate || $r['examined_at'] > $examLatestDate) {
        $examLatestDate = $r['examined_at'];
        $examLatestBy   = $r['examined_by'];
    }
}

// ---------- AJAX: save / reset examination ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];

    switch ($_POST['action']) {

        case 'save_examination':
            $tooth = preg_replace('/[^0-9]/', '', $_POST['tooth_number'] ?? '');
            $conds = json_decode($_POST['conditions'] ?? '[]', true) ?: [];
            $surfs = json_decode($_POST['surfaces']   ?? '[]', true) ?: [];
            $notes = trim($_POST['notes'] ?? '');
            $otherDetails = trim($_POST['other_details'] ?? '');

            $validConds = array_keys($examConditions);
            $validSurfs = $examSurfaces;

            $conds = array_values(array_intersect($conds, $validConds));
            $surfs = array_values(array_intersect($surfs, $validSurfs));

            if ($tooth === '' || empty($conds)) {
                $response['message'] = 'Please pick at least one condition.';
                break;
            }

            // If "Other" is selected, the custom text is required
            if (in_array('other', $conds) && $otherDetails === '') {
                $response['message'] = 'Please specify the custom condition for "Other".';
                break;
            }

            // Clear other_details if "Other" isn't picked
            if (!in_array('other', $conds)) {
                $otherDetails = '';
            }

            // If "healthy" is picked with anything else, drop the others (healthy is exclusive)
            if (in_array('healthy', $conds) && count($conds) > 1) {
                $conds = ['healthy'];
                $surfs = [];
                $otherDetails = '';
            }

            $condsJson = json_encode(array_values($conds));
            $surfsJson = json_encode(array_values($surfs));
            $examinedBy = $_SESSION['user_name'] ?? 'Staff';

            $stmt = $conn->prepare("
                INSERT INTO dental_examination
                    (patient_key, tooth_number, conditions, surfaces, notes, other_details, examined_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    conditions    = VALUES(conditions),
                    surfaces      = VALUES(surfaces),
                    notes         = VALUES(notes),
                    other_details = VALUES(other_details),
                    examined_by   = VALUES(examined_by),
                    examined_at   = CURRENT_TIMESTAMP
            ");
            $stmt->bind_param('sssssss', $patientKey, $tooth, $condsJson, $surfsJson, $notes, $otherDetails, $examinedBy);

            if ($stmt->execute()) {
                $response['success'] = true;
                $response['message'] = "Tooth {$tooth} examination saved.";
                $response['examined_by'] = $examinedBy;
                $response['examined_at'] = date('M j, Y g:i A');
            } else {
                $response['message'] = 'Failed to save examination.';
            }
            $stmt->close();
            break;

        case 'reset_examination':
            $tooth = preg_replace('/[^0-9]/', '', $_POST['tooth_number'] ?? '');
            if ($tooth === '') { $response['message'] = 'Invalid tooth.'; break; }

            $stmt = $conn->prepare("DELETE FROM dental_examination WHERE patient_key = ? AND tooth_number = ?");
            $stmt->bind_param('ss', $patientKey, $tooth);
            if ($stmt->execute()) {
                $response['success'] = true;
                $response['message'] = "Tooth {$tooth} cleared.";
            } else {
                $response['message'] = 'Failed to clear.';
            }
            $stmt->close();
            break;
    }

    echo json_encode($response);
    exit;
}

include 'sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Details · <?= htmlspecialchars($patient['full_name']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700;800;900&family=DM+Sans:wght@400;500;600;700;800&display=swap');
        * { font-family: 'DM Sans', system-ui, sans-serif; box-sizing: border-box; }
        h1, h2, h3, h4, .logo-name { font-family: 'Playfair Display', Georgia, serif; }

        :root {
            --navy: #0b1628; --teal: #0d9e8c; --teal-dark: #0a7d6e; --teal-light: #e6f7f5;
            --mint: #eafaf6; --slate: #64748b; --line: #eef1f6;
            --shadow-card: 0 2px 8px rgba(11,22,40,.06), 0 12px 32px rgba(11,22,40,.08);
        }

        body {
            background-color: #f8fafc;
            background-image:
                linear-gradient(rgba(11,22,40,0.04) 1px, transparent 1px),
                linear-gradient(90deg, rgba(11,22,40,0.04) 1px, transparent 1px);
            background-size: 32px 32px;
            background-position: -1px -1px;
        }

        @media (min-width: 1024px) { .main-content { margin-left: 260px; } }
        @media (max-width: 1023px) { .main-content { margin-left: 0; } }

        .top-bar {
            position: sticky; top: 0; z-index: 30; background: white;
            border-bottom: 1px solid var(--line); padding: 16px 32px;
            display: flex; align-items: center; justify-content: space-between;
        }
        .top-bar-title { font-size: 15px; font-weight: 700; color: var(--navy); }
        .top-bar-actions { display: flex; gap: 10px; }
        .icon-btn {
            width: 38px; height: 38px; border-radius: 10px; border: 1px solid var(--line);
            background: #f8fafc; color: var(--slate);
            display: flex; align-items: center; justify-content: center;
            font-size: 14px; cursor: pointer; transition: all .2s;
        }
        .icon-btn:hover { background: var(--teal-light); color: var(--teal-dark); border-color: #bfe9e2; }

        .page-body { padding: 28px 32px 48px; max-width: 1280px; margin: 0 auto; }

        .patient-profile-card {
            background: white; border-radius: 20px; box-shadow: var(--shadow-card);
            border: 1px solid #f0f3f7; overflow: hidden; margin-bottom: 28px;
        }
        .profile-header {
            background: linear-gradient(135deg, #0b1628 0%, #1a2a4a 100%);
            padding: 28px 32px; display: flex; align-items: center;
            gap: 24px; flex-wrap: wrap;
        }
        .profile-avatar {
            width: 80px; height: 80px; border-radius: 50%;
            background: rgba(13,158,140,0.2); display: flex; align-items: center; justify-content: center;
            font-size: 32px; color: var(--teal); font-weight: 700;
            border: 3px solid rgba(13,158,140,0.3); flex-shrink: 0;
        }
        .profile-info { flex: 1; min-width: 200px; }
        .profile-info h1 { font-size: 28px; font-weight: 700; color: white; margin: 0; }
        .profile-info .sub { color: rgba(255,255,255,0.6); font-size: 14px; margin-top: 4px; }
        .profile-info .sub i { margin-right: 6px; }
        .profile-badges { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 8px; }
        .profile-badge {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 4px 14px; border-radius: 50px; font-size: 11px; font-weight: 600;
        }
        .profile-badge.active { background: #d4edda; color: #155724; }
        .profile-badge.inactive { background: #f8d7da; color: #721c24; }
        .profile-badge.pending { background: #fef3d7; color: #b45309; }

        .profile-stats { display: flex; gap: 32px; margin-left: auto; padding: 8px 0; }
        .profile-stats .stat-item { text-align: center; color: rgba(255,255,255,0.8); }
        .profile-stats .stat-item .number { font-size: 24px; font-weight: 700; color: white; display: block; }
        .profile-stats .stat-item .label {
            font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; opacity: 0.6;
        }

        .profile-details {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px; padding: 20px 32px; background: #fafbfc; border-top: 1px solid var(--line);
        }
        .detail-item { display: flex; align-items: center; gap: 10px; }
        .detail-item i { width: 20px; color: var(--teal); font-size: 14px; }
        .detail-item .label { font-size: 11px; color: var(--slate); text-transform: uppercase; letter-spacing: 0.3px; }
        .detail-item .value { font-weight: 600; color: var(--navy); font-size: 14px; }

        .appointments-section {
            background: white; border-radius: 20px; box-shadow: var(--shadow-card);
            border: 1px solid #f0f3f7; overflow: hidden; margin-bottom: 24px;
        }

        .appointments-scroll {
            max-height: 400px; overflow-y: auto; overflow-x: hidden;
        }
        .appointments-scroll::-webkit-scrollbar { width: 6px; }
        .appointments-scroll::-webkit-scrollbar-track { background: #f1f4f8; }
        .appointments-scroll::-webkit-scrollbar-thumb { background: #d0d7e2; border-radius: 10px; }
        .appointments-scroll::-webkit-scrollbar-thumb:hover { background: #b0b7c2; }

        .section-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 18px 24px; border-bottom: 1px solid var(--line);
            background: #fafbfc; flex-shrink: 0;
        }
        .section-header h2 {
            font-size: 16px; font-weight: 700; color: var(--navy);
            display: flex; align-items: center; gap: 10px;
        }
        .section-header h2 i { color: var(--teal); }
        .section-header .count {
            font-size: 12px; color: var(--slate); background: #f1f4f8;
            padding: 2px 12px; border-radius: 12px;
        }

        .appointment-item {
            display: flex; align-items: center; justify-content: space-between;
            padding: 16px 24px; border-bottom: 1px solid var(--line);
            transition: background 0.2s; cursor: pointer;
        }
        .appointment-item:hover { background: #f8fafc; }
        .appointment-item:last-child { border-bottom: none; }
        .appointment-item .left { display: flex; align-items: center; gap: 16px; flex: 1; }
        .appointment-item .left .icon-circle {
            width: 40px; height: 40px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 16px; flex-shrink: 0;
        }
        .appointment-item .left .icon-circle.upcoming { background: #d1e7ff; color: #084298; }
        .appointment-item .left .icon-circle.today { background: #d4edda; color: #155724; }
        .appointment-item .left .icon-circle.past { background: #f1f4f8; color: var(--slate); }
        .appointment-item .left .icon-circle.cancelled { background: #fee9e7; color: #b91c1c; }
        .appointment-item .info h4 { font-size: 14px; font-weight: 600; color: var(--navy); margin: 0 0 2px 0; }
        .appointment-item .info .meta { font-size: 12px; color: var(--slate); }
        .appointment-item .info .meta i { margin-right: 4px; }
        .appointment-item .right { display: flex; align-items: center; gap: 12px; }
        .appointment-item .right .status-badge {
            padding: 4px 14px; border-radius: 50px; font-size: 11px; font-weight: 600;
        }
        .appointment-item .right .status-badge.status-confirmed { background: #d4edda; color: #155724; }
        .appointment-item .right .status-badge.status-approved { background: #d1e7ff; color: #084298; }
        .appointment-item .right .status-badge.status-pending { background: #fef3d7; color: #b45309; }
        .appointment-item .right .status-badge.status-cancelled { background: #fee9e7; color: #b91c1c; }
        .appointment-item .right .appt-id { font-size: 11px; color: #94a3b8; font-weight: 500; }

        .empty-state { padding: 40px 20px; text-align: center; color: var(--slate); }
        .empty-state i { font-size: 36px; color: #d0d7e2; display: block; margin-bottom: 12px; }
        .empty-state h3 { font-size: 16px; font-weight: 600; color: var(--navy); margin-bottom: 4px; }
        .empty-state p { font-size: 13px; }

        @media (max-width: 640px) {
            .top-bar { padding: 14px 18px; }
            .page-body { padding: 18px 16px 40px; }
            .profile-header { padding: 20px; flex-direction: column; text-align: center; }
            .profile-stats { margin-left: 0; gap: 16px; }
            .profile-details { grid-template-columns: 1fr; padding: 16px; }
            .appointment-item { flex-direction: column; align-items: flex-start; gap: 10px; padding: 16px; }
            .appointment-item .right { width: 100%; justify-content: flex-start; }
            .appointments-scroll { max-height: 300px; }
        }

        .back-btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 8px 16px; border-radius: 10px; border: 1px solid var(--line);
            background: white; color: var(--slate); font-weight: 600;
            font-size: 13px; cursor: pointer; transition: all .2s; text-decoration: none;
        }
        .back-btn:hover { background: #f8fafc; border-color: #cbd5e1; }

        /* Medical history section */
        .history-intro {
            display: flex; align-items: flex-start; gap: 12px;
            margin: 18px 24px 0; padding: 12px 16px;
            background: var(--teal-light); border: 1px solid #bfe9e2;
            border-radius: 12px; font-size: 13px; color: var(--teal-dark); line-height: 1.5;
        }
        .history-intro i { margin-top: 2px; }
        .history-form { padding: 4px 0 0; }
        .history-subsection { padding: 20px 24px 4px; border-top: 1px solid var(--line); }
        .history-subsection:first-of-type { border-top: none; }
        .history-subsection-title {
            display: flex; align-items: center; gap: 9px;
            font-size: 13px; font-weight: 700; color: var(--navy);
            text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 16px;
        }
        .history-subsection-title i {
            width: 26px; height: 26px; border-radius: 8px;
            background: var(--teal-light); color: var(--teal-dark);
            display: flex; align-items: center; justify-content: center; font-size: 12px;
        }
        .history-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 16px 20px; padding-bottom: 20px;
        }
        .form-field { display: flex; flex-direction: column; gap: 7px; }
        .form-field.span-full { grid-column: 1 / -1; }
        .form-field label { font-size: 12px; font-weight: 600; color: var(--slate); }
        .form-field .hint { font-size: 11px; color: #a0aabb; font-weight: 400; margin-left: 4px; }
        .form-field input[type="text"], .form-field textarea {
            border: 1px solid var(--line); border-radius: 10px; padding: 10px 12px;
            font-size: 13px; color: var(--navy); background: white;
            font-family: inherit; width: 100%; transition: border-color .15s, box-shadow .15s;
        }
        .form-field textarea { resize: vertical; min-height: 44px; }
        .form-field input[type="text"]:focus, .form-field textarea:focus {
            outline: none; border-color: var(--teal); box-shadow: 0 0 0 3px rgba(13,158,140,0.12);
        }
        .yn-toggle {
            display: inline-flex; border: 1px solid var(--line); border-radius: 10px;
            padding: 3px; background: #f8fafc; width: fit-content;
        }
        .yn-toggle input { position: absolute; opacity: 0; pointer-events: none; }
        .yn-toggle label {
            padding: 6px 14px; font-size: 12px; font-weight: 600; color: var(--slate);
            border-radius: 7px; cursor: pointer; transition: all .15s; margin: 0; user-select: none;
        }
        .yn-toggle input:checked + label.yn-yes { background: #fee9e7; color: #b91c1c; }
        .yn-toggle input:checked + label.yn-no { background: #d4edda; color: #155724; }
        .yn-toggle input:checked + label.yn-na { background: #e2e8f0; color: var(--navy); }
        .yn-toggle label:hover { color: var(--navy); }

        .conditional-field { margin-top: 10px; padding-left: 2px; display: none; }
        .conditional-field.is-visible { display: flex; }

        .history-form-actions {
            display: flex; align-items: center; justify-content: space-between;
            gap: 12px; flex-wrap: wrap; padding: 18px 24px;
            border-top: 1px solid var(--line); background: #fafbfc;
        }
        .save-btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 11px 22px; border-radius: 10px; border: none;
            background: var(--teal); color: white; font-weight: 600; font-size: 13px;
            cursor: pointer; transition: background .2s, transform .1s;
            box-shadow: 0 4px 12px rgba(13,158,140,0.25);
        }
        .save-btn:hover { background: var(--teal-dark); }
        .save-btn:active { transform: scale(0.98); }

        .collapsible-header { cursor: pointer; user-select: none; transition: background 0.15s; }
        .collapsible-header:hover { background: #f1f5f9; }
        .collapsible-header .toggle-chevron {
            display: inline-flex; align-items: center; justify-content: center;
            width: 28px; height: 28px; border-radius: 8px; background: #eef2f6;
            color: var(--slate); font-size: 12px;
            transition: transform 0.3s ease, background 0.15s, color 0.15s;
        }
        .collapsible-header:hover .toggle-chevron { background: var(--teal-light); color: var(--teal-dark); }
        .history-collapsible.open .toggle-chevron { transform: rotate(180deg); }
        .history-collapsible .collapsible-body {
            max-height: 0; overflow: hidden; transition: max-height 0.4s ease;
        }
        .history-collapsible.open .collapsible-body {
            max-height: 5000px; transition: max-height 0.6s ease;
        }

        /* ============ DENTAL EXAMINATION CHART ============ */
        .exam-chart-wrap { padding: 20px 24px 24px; }
        .exam-summary {
            display: flex; flex-wrap: wrap; gap: 20px;
            padding-bottom: 14px; margin-bottom: 16px;
            border-bottom: 1px solid var(--line);
        }
        .exam-summary .stat span.label {
            font-size: 10.5px; font-weight: 700; color: #94a3b8;
            text-transform: uppercase; letter-spacing: .4px;
            display: block; margin-bottom: 3px;
        }
        .exam-summary .stat span.val { font-size: 13.5px; font-weight: 700; color: var(--navy); }

        .exam-legend { display: flex; flex-wrap: wrap; gap: 8px 14px; margin-bottom: 18px; }
        .exam-legend .legend-item {
            display: flex; align-items: center; gap: 6px;
            font-size: 11px; font-weight: 600; color: var(--slate);
        }
        .exam-legend .legend-dot {
            width: 11px; height: 11px; border-radius: 50%;
            flex-shrink: 0; border: 1px solid rgba(0,0,0,.08);
        }

        .exam-arch-label {
            text-align: center; font-size: 11px; font-weight: 700; color: #94a3b8;
            text-transform: uppercase; letter-spacing: 1px; padding: 4px 0 8px;
            border-bottom: 1px dashed #e2e8f0; margin-bottom: 8px;
        }
        .exam-arch-row { display: flex; justify-content: center; gap: 4px; flex-wrap: wrap; margin-bottom: 6px; }
        .exam-arch-divider { border-top: 1px dashed #e2e8f0; margin: 10px 0 12px; }

        .exam-tooth-cell {
            display: flex; flex-direction: column; align-items: center; gap: 4px;
            cursor: pointer; padding: 6px 4px; border-radius: 10px;
            transition: background .15s; width: 65px; min-width: 55px;
            position: relative;
        }
        .exam-tooth-cell:hover { background: #f7f9fc; }
        .exam-tooth-img-wrap {
            width: 44px; height: 64px; border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            background: white; border: 2.5px solid var(--tooth-color, #cbd5e1);
            box-shadow: 0 1px 4px rgba(11,22,40,.06);
            position: relative;
        }
        .exam-tooth-img-wrap img { max-width: 100%; max-height: 100%; object-fit: contain; }
        .exam-tooth-cell[data-has-exam="1"] .exam-tooth-img-wrap::after {
            content: ""; position: absolute; top: -3px; right: -3px;
            width: 10px; height: 10px; border-radius: 50%;
            background: var(--teal); border: 2px solid white;
        }
        .exam-tooth-num { font-size: 13px; font-weight: 700; color: var(--slate); line-height: 1.2; }
        .exam-tooth-name {
            font-size: 9.5px; font-weight: 600; color: #94a3b8;
            text-align: center; line-height: 1.1; max-width: 58px;
        }
        .exam-tooth-badge {
            font-size: 7.5px; font-weight: 700; text-transform: uppercase;
            padding: 1px 6px; border-radius: 8px; line-height: 1.3;
            max-width: 58px; overflow: hidden; text-overflow: ellipsis;
            white-space: nowrap; text-align: center;
        }
        .exam-tooth-surfaces {
            font-size: 7px; font-weight: 700; color: #94a3b8;
            letter-spacing: .3px; margin-top: 1px;
        }

        @media (max-width: 768px) {
            .exam-tooth-cell { width: 52px; min-width: 44px; padding: 4px 2px; gap: 3px; }
            .exam-tooth-img-wrap { width: 36px; height: 52px; }
            .exam-tooth-num { font-size: 11px; }
            .exam-tooth-name { font-size: 7.5px; max-width: 46px; }
            .exam-tooth-badge { font-size: 6.5px; max-width: 46px; padding: 1px 4px; }
        }

        /* Modal */
        .exam-modal-overlay {
            position: fixed; inset: 0; z-index: 80; background: rgba(11,22,40,.55);
            display: none; align-items: center; justify-content: center; padding: 1rem;
        }
        .exam-modal-overlay.open { display: flex; }
        .exam-modal-box {
            background: white; border-radius: 18px; width: 100%; max-width: 520px;
            box-shadow: 0 24px 60px rgba(11,22,40,.35); overflow: hidden;
            max-height: 92vh; overflow-y: auto;
        }
        .exam-modal-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 16px 20px; border-bottom: 1px solid #f0f3f7;
            position: sticky; top: 0; background: white; z-index: 5;
        }
        .exam-modal-header h3 { font-size: 15px; font-weight: 700; color: var(--navy); font-family: 'DM Sans'; margin: 0; }
        .exam-modal-close { background: none; border: none; color: #94a3b8; font-size: 22px; cursor: pointer; line-height: 1; }
        .exam-modal-body { padding: 18px 20px; }
        .exam-modal-footer {
            display: flex; align-items: center; justify-content: space-between; gap: 8px;
            padding: 14px 20px; background: #fafbfc; border-top: 1px solid #f0f3f7;
            position: sticky; bottom: 0;
        }

        .exam-tooth-preview {
            width: 100%; height: 150px; border-radius: 14px; background: #f7f9fc;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            margin-bottom: 16px; border: 3px solid var(--tooth-color, #cbd5e1);
        }
        .exam-tooth-preview img { max-height: 100px; }
        .exam-tooth-preview-info { text-align: center; margin-top: 6px; font-size: 12px; color: var(--slate); }

        .exam-condition-grid {
            display: grid; grid-template-columns: 1fr 1fr; gap: 7px; margin-bottom: 14px;
        }
        .exam-condition-opt {
            display: flex; align-items: center; gap: 7px;
            border: 1.5px solid #e2e8f0; border-radius: 9px;
            padding: 8px 10px; font-size: 11.5px; font-weight: 600;
            color: var(--navy); cursor: pointer; user-select: none;
            transition: all .15s;
        }
        .exam-condition-opt:hover { border-color: #cbd5e1; }
        .exam-condition-opt input { accent-color: var(--teal); }
        .exam-condition-opt .dot { width: 11px; height: 11px; border-radius: 50%; flex-shrink: 0; }
        .exam-condition-opt.selected { border-color: var(--teal); background: var(--teal-light); }

        .exam-surface-row { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 14px; }
        .exam-surface-chip {
            padding: 6px 12px; border-radius: 8px; border: 1.5px solid #e2e8f0;
            font-size: 11.5px; font-weight: 700; color: var(--navy);
            cursor: pointer; user-select: none; background: white;
            transition: all .15s;
        }
        .exam-surface-chip:hover { border-color: #cbd5e1; }
        .exam-surface-chip.selected {
            background: var(--navy); color: white; border-color: var(--navy);
        }
        .exam-surface-chip.disabled {
            opacity: .4; cursor: not-allowed;
        }

        .exam-notes-textarea {
            width: 100%; border: 1.5px solid #e2e8f0; border-radius: 10px;
            padding: 9px 11px; font-size: 13px; min-height: 68px; resize: vertical;
            font-family: inherit;
        }
        .exam-notes-textarea:focus { outline: none; border-color: var(--teal); box-shadow: 0 0 0 4px rgba(13,158,140,.1); }

        .exam-meta { font-size: 11px; color: #94a3b8; margin-top: 8px; }

        .exam-field-label {
            display: block; font-size: 10.5px; color: #94a3b8;
            font-weight: 700; text-transform: uppercase; letter-spacing: .4px;
            margin-bottom: 6px;
        }

        .exam-btn {
            padding: 7px 13px; border-radius: 8px; font-size: 11.5px; font-weight: 700;
            border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 5px;
        }
        .exam-btn-view { background: #f1f4f8; color: var(--navy); }
        .exam-btn-view:hover { background: #e2e8f0; }
        .exam-btn-save { background: var(--teal-light); color: var(--teal-dark); }
        .exam-btn-save:hover { background: var(--teal); color: white; }

        .exam-empty-note {
            text-align: center; padding: 32px 12px; color: #94a3b8;
            font-size: 13px; background: #f8fafc; border-radius: 12px;
            border: 1.5px dashed #e2e8f0;
        }
    </style>
</head>
<body>

<div class="main-content">

    <!-- TOP BAR -->
    <div class="top-bar">
        <div class="top-bar-title">
            <i class="fas fa-user mr-2 text-teal"></i>
            Patient Details — <?= htmlspecialchars($patient['full_name']) ?>
        </div>
        <div class="top-bar-actions">
            <a href="patients.php" class="back-btn">
                <i class="fas fa-arrow-left"></i> Back to Patients
            </a>
        </div>
    </div>

    <div class="page-body">

        <!-- Patient Profile Card -->
        <div class="patient-profile-card">
            <div class="profile-header">
                <div class="profile-avatar">
                    <?= strtoupper(substr($patient['full_name'] ?? 'P', 0, 1)) ?>
                </div>
                <div class="profile-info">
                    <h1><?= htmlspecialchars($patient['full_name']) ?></h1>
                    <div class="sub">
                        <i class="fas fa-id-card"></i> <?= htmlspecialchars($patient_id_display) ?>
                        <span style="margin: 0 10px;">|</span>
                        <i class="fas fa-calendar-plus"></i> Patient since <?= date('F d, Y', strtotime($patient['created_at'])) ?>
                    </div>
                    <div class="profile-badges">
                        <span class="profile-badge <?= $patient['status'] ?? 'pending' ?>">
                            <i class="fas fa-circle" style="font-size: 6px;"></i>
                            <?= ucfirst($patient['status'] ?? 'Pending') ?>
                        </span>
                        <span class="profile-badge" style="background: #e6f7f5; color: #0a7d6e;">
                            <i class="fas fa-calendar-check"></i>
                            <?= count($appointments) ?> Total Appointments
                        </span>
                    </div>
                </div>
                <div class="profile-stats">
                    <div class="stat-item">
                        <span class="number"><?= count($upcoming_appointments) ?></span>
                        <span class="label">Upcoming</span>
                    </div>
                    <div class="stat-item">
                        <span class="number"><?= count($today_appointments) ?></span>
                        <span class="label">Today</span>
                    </div>
                    <div class="stat-item">
                        <span class="number"><?= count($past_appointments) ?></span>
                        <span class="label">Past</span>
                    </div>
                </div>
            </div>
            <div class="profile-details">
                <div class="detail-item">
                    <i class="fas fa-phone"></i>
                    <div>
                        <div class="label">Phone</div>
                        <div class="value"><?= htmlspecialchars($patient['phone'] ?? 'Not provided') ?></div>
                    </div>
                </div>
                <div class="detail-item">
                    <i class="fas fa-envelope"></i>
                    <div>
                        <div class="label">Email</div>
                        <div class="value"><?= htmlspecialchars($patient['email'] ?? 'Not provided') ?></div>
                    </div>
                </div>
                <?php if (!empty($patient['age'])): ?>
                <div class="detail-item">
                    <i class="fas fa-cake-candles"></i>
                    <div>
                        <div class="label">Age</div>
                        <div class="value"><?= htmlspecialchars($patient['age']) ?> years old</div>
                    </div>
                </div>
                <?php endif; ?>
                <?php if (!empty($patient['gender'])): ?>
                <div class="detail-item">
                    <i class="fas fa-venus-mars"></i>
                    <div>
                        <div class="label">Gender</div>
                        <div class="value"><?= htmlspecialchars($patient['gender']) ?></div>
                    </div>
                </div>
                <?php endif; ?>
                <?php if (!empty($patient['address'])): ?>
                <div class="detail-item">
                    <i class="fas fa-location-dot"></i>
                    <div>
                        <div class="label">Address</div>
                        <div class="value"><?= htmlspecialchars($patient['address']) ?></div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ==================== DENTAL EXAMINATION CHART ==================== -->
        <div class="appointments-section">
            <div class="section-header">
                <h2><i class="fas fa-tooth"></i> Dental Examination Chart</h2>
                <span class="count">Whole-mouth record · <?= $examRecordedCount ?> tooth<?= $examRecordedCount === 1 ? '' : 'es' ?> recorded</span>
            </div>

            <div class="exam-chart-wrap">
                <div class="exam-summary">
                    <div class="stat">
                        <span class="label">Latest Exam</span>
                        <span class="val" id="examLatestDate"><?= $examLatestDate ? date('M j, Y g:i A', strtotime($examLatestDate)) : '—' ?></span>
                    </div>
                    <div class="stat">
                        <span class="label">Examined By</span>
                        <span class="val" id="examLatestBy"><?= htmlspecialchars($examLatestBy ?: '—') ?></span>
                    </div>
                    <div class="stat">
                        <span class="label">Recorded Teeth</span>
                        <span class="val" id="examRecordedCount"><?= $examRecordedCount ?> tooth<?= $examRecordedCount === 1 ? '' : 'es' ?></span>
                    </div>
                </div>

                <div class="exam-legend">
                    <?php foreach ($examConditions as $k => $c): ?>
                        <div class="legend-item">
                            <span class="legend-dot" style="background:<?= $c['color'] ?>;"></span>
                            <?= htmlspecialchars($c['label']) ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($examRecordedCount === 0): ?>
                    <div class="exam-empty-note">
                        <i class="fas fa-info-circle"></i>
                        No examination records yet. Click any tooth below to begin charting.
                    </div>
                <?php endif; ?>

                <div class="exam-arch-label">Upper Arch (Maxillary)</div>
                <div class="exam-arch-row">
                    <?php foreach ($upperArch as $num):
                        $entry = $examMap[$num] ?? null;
                        $conds = $entry['conditions'] ?? [];
                        $surfs = $entry['surfaces']   ?? [];
                        $firstCond = $conds[0] ?? null;
                        $color = $firstCond ? $examConditions[$firstCond]['color'] : '#cbd5e1';
                        if (in_array('healthy', $conds) && count($conds) === 1) $color = $examConditions['healthy']['color'];
                        $badgeLabel = '';
                        if (!empty($conds)) {
                            if (count($conds) === 1 && $conds[0] === 'other' && !empty($entry['other_details'])) {
                                $badgeLabel = $entry['other_details'];
                            } elseif (count($conds) === 1) {
                                $badgeLabel = $examConditions[$conds[0]]['label'];
                            } else {
                                $badgeLabel = count($conds) . ' conditions';
                            }
                        }
                        $condsTooltip = implode(', ', array_map(function($k) use ($examConditions, $entry) {
                            if ($k === 'other' && !empty($entry['other_details'])) return 'Other: ' . $entry['other_details'];
                            return $examConditions[$k]['label'] ?? $k;
                        }, $conds));
                    ?>
                    <div class="exam-tooth-cell"
                         data-tooth="<?= $num ?>"
                         data-has-exam="<?= $entry ? '1' : '0' ?>"
                         onclick="openExamModal(<?= $num ?>)">
                        <div class="exam-tooth-img-wrap" style="--tooth-color: <?= $color ?>;">
                            <img src="<?= htmlspecialchars(toothImage($num)) ?>" alt="Tooth <?= $num ?>" onerror="this.style.opacity=0.15;">
                        </div>
                        <span class="exam-tooth-num"><?= $num ?></span>
                        <span class="exam-tooth-name"><?= htmlspecialchars(getToothName($num)) ?></span>
                        <?php if ($badgeLabel): ?>
                            <span class="exam-tooth-badge" style="background: <?= $color ?>20; color: <?= $color ?>;"
                                  title="<?= htmlspecialchars($condsTooltip) ?>">
                                <?= htmlspecialchars($badgeLabel) ?>
                            </span>
                        <?php endif; ?>
                        <?php if (!empty($surfs)): ?>
                            <span class="exam-tooth-surfaces" title="Surfaces"><?= htmlspecialchars(implode('·', $surfs)) ?></span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="exam-arch-divider"></div>

                <div class="exam-arch-label">Lower Arch (Mandibular)</div>
                <div class="exam-arch-row">
                    <?php foreach ($lowerArch as $num):
                        $entry = $examMap[$num] ?? null;
                        $conds = $entry['conditions'] ?? [];
                        $surfs = $entry['surfaces']   ?? [];
                        $firstCond = $conds[0] ?? null;
                        $color = $firstCond ? $examConditions[$firstCond]['color'] : '#cbd5e1';
                        if (in_array('healthy', $conds) && count($conds) === 1) $color = $examConditions['healthy']['color'];
                        $badgeLabel = '';
                        if (!empty($conds)) {
                            if (count($conds) === 1 && $conds[0] === 'other' && !empty($entry['other_details'])) {
                                $badgeLabel = $entry['other_details'];
                            } elseif (count($conds) === 1) {
                                $badgeLabel = $examConditions[$conds[0]]['label'];
                            } else {
                                $badgeLabel = count($conds) . ' conditions';
                            }
                        }
                        $condsTooltip = implode(', ', array_map(function($k) use ($examConditions, $entry) {
                            if ($k === 'other' && !empty($entry['other_details'])) return 'Other: ' . $entry['other_details'];
                            return $examConditions[$k]['label'] ?? $k;
                        }, $conds));
                    ?>
                    <div class="exam-tooth-cell"
                         data-tooth="<?= $num ?>"
                         data-has-exam="<?= $entry ? '1' : '0' ?>"
                         onclick="openExamModal(<?= $num ?>)">
                        <div class="exam-tooth-img-wrap" style="--tooth-color: <?= $color ?>;">
                            <img src="<?= htmlspecialchars(toothImage($num)) ?>" alt="Tooth <?= $num ?>" onerror="this.style.opacity=0.15;">
                        </div>
                        <span class="exam-tooth-num"><?= $num ?></span>
                        <span class="exam-tooth-name"><?= htmlspecialchars(getToothName($num)) ?></span>
                        <?php if ($badgeLabel): ?>
                            <span class="exam-tooth-badge" style="background: <?= $color ?>20; color: <?= $color ?>;"
                                  title="<?= htmlspecialchars($condsTooltip) ?>">
                                <?= htmlspecialchars($badgeLabel) ?>
                            </span>
                        <?php endif; ?>
                        <?php if (!empty($surfs)): ?>
                            <span class="exam-tooth-surfaces" title="Surfaces"><?= htmlspecialchars(implode('·', $surfs)) ?></span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <!-- ==================== /DENTAL EXAMINATION CHART ==================== -->

        <!-- Today's Appointments -->
        <?php if (!empty($today_appointments)): ?>
        <div class="appointments-section">
            <?php if (!$medical_history): ?>
                <div class="history-intro cursor-pointer" onclick="openHistorySectionAndScroll()">
                    <i class="fas fa-circle-info"></i>
                    <div>No medical/dental history has been recorded for this patient yet. Click here to expand and fill out the form.</div>
                </div>
            <?php endif; ?>

            <div class="section-header">
                <h2><i class="fas fa-calendar-day"></i> Today's Appointments</h2>
                <span class="count"><?= count($today_appointments) ?> today</span>
            </div>
            <div class="appointments-scroll">
                <?php foreach ($today_appointments as $appt): ?>
                    <?php
                        list($service_names, $total_price) = getServiceNames($conn, $appt['services']);
                        $status_class = getStatusBadgeClass($appt['status'] ?? 'pending');
                        $dentist_name = 'Not assigned';
                        if (!empty($appt['dentist_id']) && !empty($appt['dentist_first_name'])) {
                            $dentist_name = 'Dr. ' . $appt['dentist_first_name'] . ' ' . $appt['dentist_last_name'];
                        }
                    ?>
                    <div class="appointment-item" onclick="window.location.href='appointment_details.php?id=<?= $appt['id'] ?>'">
                        <div class="left">
                            <div class="icon-circle today"><i class="fas fa-check-circle"></i></div>
                            <div class="info">
                                <h4>Appointment #<?= $appt['id'] ?></h4>
                                <div class="meta">
                                    <i class="fas fa-clock"></i> <?= formatAppointmentTime($appt['appointment_time']) ?>
                                    <span style="margin: 0 6px;">·</span>
                                    <i class="fas fa-tooth"></i> <?= htmlspecialchars($service_names) ?>
                                    <?php if ($dentist_name !== 'Not assigned'): ?>
                                        <span style="margin: 0 6px;">·</span>
                                        <i class="fas fa-user-md"></i> <?= htmlspecialchars($dentist_name) ?>
                                    <?php endif; ?>
                                    <?php if ($total_price > 0): ?>
                                        <span style="margin: 0 6px;">·</span>
                                        <i class="fas fa-tag"></i> ₱<?= number_format($total_price, 2) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="right">
                            <span class="status-badge <?= $status_class ?>"><?= ucfirst($appt['status'] ?? 'Pending') ?></span>
                            <span class="appt-id">#<?= $appt['id'] ?></span>
                            <i class="fas fa-chevron-right" style="color: #cbd5e1; font-size: 12px;"></i>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Upcoming Appointments -->
        <?php if (!empty($upcoming_appointments)): ?>
        <div class="appointments-section">
            <div class="section-header">
                <h2><i class="fas fa-calendar-plus"></i> Upcoming Appointments</h2>
                <span class="count"><?= count($upcoming_appointments) ?> upcoming</span>
            </div>
            <div class="appointments-scroll">
                <?php foreach ($upcoming_appointments as $appt): ?>
                    <?php
                        list($service_names, $total_price) = getServiceNames($conn, $appt['services']);
                        $status_class = getStatusBadgeClass($appt['status'] ?? 'pending');
                        $dentist_name = 'Not assigned';
                        if (!empty($appt['dentist_id']) && !empty($appt['dentist_first_name'])) {
                            $dentist_name = 'Dr. ' . $appt['dentist_first_name'] . ' ' . $appt['dentist_last_name'];
                        }
                    ?>
                    <div class="appointment-item" onclick="window.location.href='appointment_details.php?id=<?= $appt['id'] ?>'">
                        <div class="left">
                            <div class="icon-circle upcoming"><i class="fas fa-calendar-check"></i></div>
                            <div class="info">
                                <h4>Appointment #<?= $appt['id'] ?></h4>
                                <div class="meta">
                                    <i class="fas fa-calendar-day"></i> <?= formatAppointmentDate($appt['appointment_date']) ?>
                                    <span style="margin: 0 6px;">·</span>
                                    <i class="fas fa-clock"></i> <?= formatAppointmentTime($appt['appointment_time']) ?>
                                    <span style="margin: 0 6px;">·</span>
                                    <i class="fas fa-tooth"></i> <?= htmlspecialchars($service_names) ?>
                                    <?php if ($dentist_name !== 'Not assigned'): ?>
                                        <span style="margin: 0 6px;">·</span>
                                        <i class="fas fa-user-md"></i> <?= htmlspecialchars($dentist_name) ?>
                                    <?php endif; ?>
                                    <?php if ($total_price > 0): ?>
                                        <span style="margin: 0 6px;">·</span>
                                        <i class="fas fa-tag"></i> ₱<?= number_format($total_price, 2) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="right">
                            <span class="status-badge <?= $status_class ?>"><?= ucfirst($appt['status'] ?? 'Pending') ?></span>
                            <span class="appt-id">#<?= $appt['id'] ?></span>
                            <i class="fas fa-chevron-right" style="color: #cbd5e1; font-size: 12px;"></i>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Past Appointments -->
        <?php if (!empty($past_appointments)): ?>
        <div class="appointments-section">
            <div class="section-header">
                <h2><i class="fas fa-history"></i> Past Appointments</h2>
                <span class="count"><?= count($past_appointments) ?> past</span>
            </div>
            <div class="appointments-scroll">
                <?php foreach ($past_appointments as $appt): ?>
                    <?php
                        list($service_names, $total_price) = getServiceNames($conn, $appt['services']);
                        $status_class = getStatusBadgeClass($appt['status'] ?? 'pending');
                        $dentist_name = 'Not assigned';
                        if (!empty($appt['dentist_id']) && !empty($appt['dentist_first_name'])) {
                            $dentist_name = 'Dr. ' . $appt['dentist_first_name'] . ' ' . $appt['dentist_last_name'];
                        }
                    ?>
                    <div class="appointment-item" onclick="window.location.href='appointment_details.php?id=<?= $appt['id'] ?>'">
                        <div class="left">
                            <div class="icon-circle <?= $appt['status'] === 'cancelled' ? 'cancelled' : 'past' ?>">
                                <i class="fas fa-<?= $appt['status'] === 'cancelled' ? 'times' : 'clock-rotate-left' ?>"></i>
                            </div>
                            <div class="info">
                                <h4>Appointment #<?= $appt['id'] ?></h4>
                                <div class="meta">
                                    <i class="fas fa-calendar-day"></i> <?= formatAppointmentDate($appt['appointment_date']) ?>
                                    <span style="margin: 0 6px;">·</span>
                                    <i class="fas fa-clock"></i> <?= formatAppointmentTime($appt['appointment_time']) ?>
                                    <span style="margin: 0 6px;">·</span>
                                    <i class="fas fa-tooth"></i> <?= htmlspecialchars($service_names) ?>
                                    <?php if ($dentist_name !== 'Not assigned'): ?>
                                        <span style="margin: 0 6px;">·</span>
                                        <i class="fas fa-user-md"></i> <?= htmlspecialchars($dentist_name) ?>
                                    <?php endif; ?>
                                    <?php if ($total_price > 0): ?>
                                        <span style="margin: 0 6px;">·</span>
                                        <i class="fas fa-tag"></i> ₱<?= number_format($total_price, 2) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="right">
                            <span class="status-badge <?= $status_class ?>"><?= ucfirst($appt['status'] ?? 'Pending') ?></span>
                            <span class="appt-id">#<?= $appt['id'] ?></span>
                            <i class="fas fa-chevron-right" style="color: #cbd5e1; font-size: 12px;"></i>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- No Appointments -->
        <?php if (empty($upcoming_appointments) && empty($today_appointments) && empty($past_appointments)): ?>
        <div class="appointments-section">
            <div class="section-header">
                <h2><i class="fas fa-calendar"></i> Appointments</h2>
            </div>
            <div class="empty-state">
                <i class="fas fa-calendar-times"></i>
                <h3>No Appointments Found</h3>
                <p>This patient doesn't have any appointments yet.</p>
            </div>
        </div>
        <?php endif; ?>

        <!-- Medical & Dental History (collapsible) -->
        <?php
            $history_target_appointment_id = $medical_history['appointment_id']
                ?? ($appointments[0]['id'] ?? null);

            $mh = $medical_history ?: [];
            function mhVal($mh, $key) {
                return htmlspecialchars($mh[$key] ?? '');
            }
            function ynToggle($name, $mh, $key) {
                $val = $mh[$key] ?? '';
                ob_start();
                ?>
                <div class="yn-toggle" data-yn-for="<?= htmlspecialchars($key) ?>">
                    <input type="radio" name="<?= $name ?>" id="<?= $name ?>_yes" value="yes" <?= $val === 'yes' ? 'checked' : '' ?>>
                    <label class="yn-yes" for="<?= $name ?>_yes">Yes</label>
                    <input type="radio" name="<?= $name ?>" id="<?= $name ?>_no" value="no" <?= $val === 'no' ? 'checked' : '' ?>>
                    <label class="yn-no" for="<?= $name ?>_no">No</label>
                    <input type="radio" name="<?= $name ?>" id="<?= $name ?>_na" value="" <?= $val !== 'yes' && $val !== 'no' ? 'checked' : '' ?>>
                    <label class="yn-na" for="<?= $name ?>_na">Unanswered</label>
                </div>
                <?php
                return ob_get_clean();
            }
        ?>
        <div class="appointments-section history-collapsible" id="target-section">
            <div class="section-header collapsible-header" onclick="toggleHistorySection(event)">
                <h2><i class="fas fa-file-medical"></i> Medical &amp; Dental History</h2>
                <div style="display: flex; align-items: center; gap: 10px;">
                    <span class="count"><?= $medical_history ? 'On file — editable' : 'Not yet on file' ?></span>
                    <span class="toggle-chevron"><i class="fas fa-chevron-down"></i></span>
                </div>
            </div>

            <div class="collapsible-body">
                <?php if (!$history_target_appointment_id): ?>
                    <div class="empty-state">
                        <i class="fas fa-file-circle-exclamation"></i>
                        <h3>No history on file</h3>
                        <p>This patient has no appointment on record yet, so a medical/dental history form can't be attached. Once they have an appointment, the form will appear here.</p>
                    </div>
                <?php else: ?>
                    <form id="historyForm" class="history-form" method="POST" action="../../api/medical_history_save.php">
                        <input type="hidden" name="history_id" value="<?= mhVal($mh, 'id') ?>">
                        <input type="hidden" name="appointment_id" value="<?= (int)$history_target_appointment_id ?>">
                        <input type="hidden" name="patient_page_id" value="<?= (int)$id ?>">

                        <div class="history-subsection">
                            <div class="history-subsection-title"><i class="fas fa-heart-pulse"></i> General Health</div>
                            <div class="history-grid">
                                <div class="form-field">
                                    <label>Under a physician's care?</label>
                                    <?= ynToggle('under_physician_care', $mh, 'under_physician_care') ?>
                                    <div class="conditional-field" data-show-when="under_physician_care=yes">
                                        <input type="text" name="physician_name" value="<?= mhVal($mh, 'physician_name') ?>" placeholder="Physician's name">
                                    </div>
                                </div>
                                <div class="form-field">
                                    <label>Pregnant</label>
                                    <?= ynToggle('pregnant', $mh, 'pregnant') ?>
                                </div>
                                <div class="form-field">
                                    <label>Currently taking medicine?</label>
                                    <?= ynToggle('taking_medicine', $mh, 'taking_medicine') ?>
                                    <div class="conditional-field" data-show-when="taking_medicine=yes">
                                        <input type="text" name="medicine_details" value="<?= mhVal($mh, 'medicine_details') ?>" placeholder="Which medicine(s)?">
                                    </div>
                                </div>
                                <div class="form-field span-full">
                                    <label>Medical conditions <span class="hint">comma-separated</span></label>
                                    <input type="text" name="conditions" value="<?= mhVal($mh, 'conditions') ?>" placeholder="e.g. Diabetes, Hypertension, Heart Disease">
                                </div>
                                <div class="form-field span-full">
                                    <label>Other condition details</label>
                                    <input type="text" name="other_condition_details" value="<?= mhVal($mh, 'other_condition_details') ?>">
                                </div>
                            </div>
                        </div>

                        <div class="history-subsection">
                            <div class="history-subsection-title"><i class="fas fa-triangle-exclamation"></i> Allergies</div>
                            <div class="history-grid">
                                <div class="form-field">
                                    <label>Local anesthesia</label>
                                    <?= ynToggle('allergic_local_anesthesia', $mh, 'allergic_local_anesthesia') ?>
                                </div>
                                <div class="form-field">
                                    <label>Antibiotics</label>
                                    <?= ynToggle('allergic_antibiotics', $mh, 'allergic_antibiotics') ?>
                                </div>
                                <div class="form-field">
                                    <label>Pain killers</label>
                                    <?= ynToggle('allergic_pain_killer', $mh, 'allergic_pain_killer') ?>
                                </div>
                                <div class="form-field">
                                    <label>Other allergies</label>
                                    <?= ynToggle('allergic_other', $mh, 'allergic_other') ?>
                                    <div class="conditional-field" data-show-when="allergic_other=yes">
                                        <input type="text" name="allergic_other_details" value="<?= mhVal($mh, 'allergic_other_details') ?>" placeholder="Details">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="history-subsection">
                            <div class="history-subsection-title"><i class="fas fa-hospital"></i> Hospitalization</div>
                            <div class="history-grid">
                                <div class="form-field">
                                    <label>Ever hospitalized?</label>
                                    <?= ynToggle('hospitalized', $mh, 'hospitalized') ?>
                                </div>
                                <div class="form-field conditional-field <?= ($mh['hospitalized'] ?? '') === 'yes' ? 'is-visible' : '' ?>" data-show-when="hospitalized=yes" style="display:<?= ($mh['hospitalized'] ?? '') === 'yes' ? 'flex' : 'none' ?>;">
                                    <label>When</label>
                                    <input type="text" name="hospitalized_when" value="<?= mhVal($mh, 'hospitalized_when') ?>" placeholder="e.g. 2022">
                                </div>
                                <div class="form-field span-full conditional-field <?= ($mh['hospitalized'] ?? '') === 'yes' ? 'is-visible' : '' ?>" data-show-when="hospitalized=yes" style="display:<?= ($mh['hospitalized'] ?? '') === 'yes' ? 'flex' : 'none' ?>;">
                                    <label>Why</label>
                                    <input type="text" name="hospitalized_why" value="<?= mhVal($mh, 'hospitalized_why') ?>">
                                </div>
                            </div>
                        </div>

                        <div class="history-subsection">
                            <div class="history-subsection-title"><i class="fas fa-tooth"></i> Dental History</div>
                            <div class="history-grid">
                                <div class="form-field">
                                    <label>Wears a denture?</label>
                                    <?= ynToggle('worn_denture', $mh, 'worn_denture') ?>
                                </div>
                                <div class="form-field">
                                    <label>Last dental visit</label>
                                    <input type="text" name="last_dental_visit" value="<?= mhVal($mh, 'last_dental_visit') ?>" placeholder="e.g. 6 months ago">
                                </div>
                                <div class="form-field span-full">
                                    <label>Reason for last visit</label>
                                    <input type="text" name="last_dental_visit_reason" value="<?= mhVal($mh, 'last_dental_visit_reason') ?>">
                                </div>
                            </div>
                        </div>

                        <div class="history-form-actions">
                            <?php if ($medical_history): ?>
                                <span class="history-updated-note">
                                    <i class="fas fa-clock"></i> Last recorded <?= date('F d, Y g:i A', strtotime($medical_history['created_at'])) ?>
                                </span>
                            <?php else: ?>
                                <span></span>
                            <?php endif; ?>
                            <button type="submit" class="save-btn">
                                <i class="fas fa-floppy-disk"></i> <?= $medical_history ? 'Save Changes' : 'Save History' ?>
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="mt-8 text-center text-xs text-gray-400 border-t border-gray-200 pt-5">
            <i class="fas fa-tooth mr-1 text-teal/50"></i>
            Wansi Calacat Dental Clinic · Patient Record
        </div>
    </div>
</div>

<!-- ==================== EXAMINATION MODAL ==================== -->
<div class="exam-modal-overlay" id="examModal">
  <div class="exam-modal-box">
    <div class="exam-modal-header">
      <h3 id="examModalTitle">Tooth #</h3>
      <button type="button" class="exam-modal-close" onclick="closeExamModal()">&times;</button>
    </div>
    <div class="exam-modal-body">
      <div class="exam-tooth-preview" id="examToothPreview">
        <img id="examToothPreviewImg" src="" alt="">
        <div class="exam-tooth-preview-info" id="examToothPreviewInfo"></div>
      </div>

      <span class="exam-field-label">Existing tooth conditions</span>
      <div class="exam-condition-grid" id="examConditionGrid"></div>

      <div id="examOtherWrap" style="display:none; margin-bottom:14px;">
        <span class="exam-field-label">Specify other condition <span style="color:#b91c1c;">*</span></span>
        <input type="text" id="examOtherInput" class="exam-notes-textarea"
               style="min-height:auto;padding:9px 12px;"
               placeholder="e.g. Discoloration, Attrition, Hypoplasia...">
      </div>

      <span class="exam-field-label">Affected surface(s)</span>
      <div class="exam-surface-row" id="examSurfaceRow"></div>

      <span class="exam-field-label">Examination notes</span>
      <textarea class="exam-notes-textarea" id="examNotes" placeholder="Enter clinical findings..."></textarea>

      <p class="exam-meta" id="examMeta"></p>
    </div>
    <div class="exam-modal-footer">
      <button type="button" class="exam-btn exam-btn-view" onclick="resetExamTooth()">
        <i class="fas fa-rotate-left"></i> Clear
      </button>
      <div style="display:flex; gap:8px;">
        <button type="button" class="exam-btn exam-btn-view" onclick="closeExamModal()">Cancel</button>
        <button type="button" class="exam-btn exam-btn-save" onclick="saveExamTooth()">
          <i class="fas fa-save"></i> Save Examination
        </button>
      </div>
    </div>
  </div>
</div>

<script>
// ==================== EXAMINATION CHART JS ====================
const EXAM_CONDITIONS = <?= json_encode($examConditions) ?>;
const EXAM_SURFACES = <?= json_encode($examSurfaces) ?>;
const EXAM_PRIMARY_TO_ADULT = <?= json_encode($PRIMARY_TO_ADULT_IMAGE) ?>;
const TOOTH_NAMES = <?php
    $names = [];
    foreach (array_merge($upperArch, $lowerArch, $primaryUpperArch, $primaryLowerArch) as $tn) {
        $names[$tn] = getToothName($tn);
    }
    echo json_encode($names);
?>;
let examChartData = <?= json_encode($examMap) ?>;
let currentExamTooth = null;

function examToothImagePath(num) {
    num = parseInt(num, 10);
    const imgNum = EXAM_PRIMARY_TO_ADULT[num] || num;
    const prefix = (imgNum >= 11 && imgNum <= 28) ? 'sup' : 'inf';
    return `../../assets/img/dentadura-${prefix}-${imgNum}.png`;
}

function openExamModal(num) {
    currentExamTooth = num;
    const entry = examChartData[num] || null;
    const currentConds = entry ? (entry.conditions || []) : [];
    const currentSurfs = entry ? (entry.surfaces   || []) : [];
    const toothName = TOOTH_NAMES[num] || 'Tooth';

    document.getElementById('examModalTitle').textContent = `Tooth #${num} — ${toothName}`;
    document.getElementById('examToothPreviewImg').src = examToothImagePath(num);
    document.getElementById('examToothPreviewInfo').textContent = `${toothName} (${num})`;

    const firstColor = currentConds.length ? EXAM_CONDITIONS[currentConds[0]].color : '#cbd5e1';
    document.getElementById('examToothPreview').style.setProperty('--tooth-color', firstColor);

    document.getElementById('examNotes').value = entry ? (entry.notes || '') : '';
    document.getElementById('examOtherInput').value = entry ? (entry.other_details || '') : '';

    const meta = document.getElementById('examMeta');
    meta.textContent = entry && entry.examined_by
        ? `Last examined by ${entry.examined_by} on ${entry.examined_at}`
        : 'No examination record yet for this tooth.';

    buildExamConditionGrid(currentConds);
    buildExamSurfaceRow(currentSurfs, currentConds);

    document.getElementById('examModal').classList.add('open');
}

function closeExamModal() {
    document.getElementById('examModal').classList.remove('open');
    currentExamTooth = null;
}

function buildExamConditionGrid(selectedConds) {
    const grid = document.getElementById('examConditionGrid');
    grid.innerHTML = '';

    Object.entries(EXAM_CONDITIONS).forEach(([key, c]) => {
        const isSel = selectedConds.includes(key);
        const label = document.createElement('label');
        label.className = 'exam-condition-opt' + (isSel ? ' selected' : '');
        label.innerHTML = `
            <input type="checkbox" name="exam_condition" value="${key}" ${isSel ? 'checked' : ''}>
            <span class="dot" style="background:${c.color};"></span>
            ${c.label}
        `;

        const cb = label.querySelector('input');
        cb.addEventListener('change', () => {
            label.classList.toggle('selected', cb.checked);

            // Healthy is exclusive
            if (key === 'healthy' && cb.checked) {
                grid.querySelectorAll('input[type="checkbox"]').forEach(other => {
                    if (other.value !== 'healthy') {
                        other.checked = false;
                        other.closest('.exam-condition-opt').classList.remove('selected');
                    }
                });
            } else if (key !== 'healthy' && cb.checked) {
                const healthyCb = grid.querySelector('input[value="healthy"]');
                if (healthyCb) {
                    healthyCb.checked = false;
                    healthyCb.closest('.exam-condition-opt').classList.remove('selected');
                }
            }

            // Show/hide the "Other" details input
            toggleExamOtherField();

            // Refresh surfaces state
            refreshExamSurfacesState();
        });

        grid.appendChild(label);
    });

    toggleExamOtherField();
}

function toggleExamOtherField() {
    const wrap = document.getElementById('examOtherWrap');
    if (!wrap) return;
    const otherCb = document.querySelector('input[name="exam_condition"][value="other"]');
    wrap.style.display = (otherCb && otherCb.checked) ? 'block' : 'none';
}

function buildExamSurfaceRow(selectedSurfs, selectedConds) {
    const row = document.getElementById('examSurfaceRow');
    row.innerHTML = '';

    EXAM_SURFACES.forEach(s => {
        const chip = document.createElement('div');
        chip.className = 'exam-surface-chip' + (selectedSurfs.includes(s) ? ' selected' : '');
        chip.textContent = s;
        chip.dataset.surface = s;
        chip.addEventListener('click', () => {
            if (chip.classList.contains('disabled')) return;
            chip.classList.toggle('selected');
        });
        row.appendChild(chip);
    });

    refreshExamSurfacesState();
}

function refreshExamSurfacesState() {
    const checkedConds = Array.from(document.querySelectorAll('input[name="exam_condition"]:checked'))
        .map(cb => cb.value);

    const surfacesDisabled =
        checkedConds.length === 0 ||
        (checkedConds.length === 1 && (checkedConds[0] === 'healthy' || checkedConds[0] === 'missing'));

    document.querySelectorAll('.exam-surface-chip').forEach(chip => {
        if (surfacesDisabled) {
            chip.classList.add('disabled');
            chip.classList.remove('selected');
        } else {
            chip.classList.remove('disabled');
        }
    });
}

function saveExamTooth() {
    if (!currentExamTooth) return;

    const conds = Array.from(document.querySelectorAll('input[name="exam_condition"]:checked'))
        .map(cb => cb.value);
    const surfs = Array.from(document.querySelectorAll('.exam-surface-chip.selected'))
        .map(c => c.dataset.surface);
    const notes = document.getElementById('examNotes').value.trim();
    const otherDetails = document.getElementById('examOtherInput').value.trim();

    if (conds.length === 0) {
        Swal.fire({ icon: 'warning', title: 'Missing condition', text: 'Please pick at least one condition.' });
        return;
    }

    if (conds.includes('other') && !otherDetails) {
        Swal.fire({ icon: 'warning', title: 'Specify condition', text: 'Please describe the custom condition for "Other".' });
        return;
    }

    const fd = new FormData();
    fd.append('action', 'save_examination');
    fd.append('tooth_number', currentExamTooth);
    fd.append('conditions', JSON.stringify(conds));
    fd.append('surfaces', JSON.stringify(surfs));
    fd.append('notes', notes);
    fd.append('other_details', conds.includes('other') ? otherDetails : '');

    fetch(window.location.href, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                examChartData[currentExamTooth] = {
                    tooth_number: String(currentExamTooth),
                    conditions: conds,
                    surfaces: surfs,
                    notes: notes,
                    other_details: conds.includes('other') ? otherDetails : '',
                    examined_by: data.examined_by,
                    examined_at: data.examined_at
                };
                updateExamToothCell(currentExamTooth);
                updateExamSummary();
                closeExamModal();
                Swal.fire({ icon: 'success', title: 'Saved', text: data.message, timer: 1200, showConfirmButton: false });
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: data.message });
            }
        });
}

function resetExamTooth() {
    if (!currentExamTooth) return;
    Swal.fire({
        title: `Clear examination for tooth #${currentExamTooth}?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#0d9e8c',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Yes, clear',
        reverseButtons: true
    }).then(result => {
        if (!result.isConfirmed) return;
        const fd = new FormData();
        fd.append('action', 'reset_examination');
        fd.append('tooth_number', currentExamTooth);
        fetch(window.location.href, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    delete examChartData[currentExamTooth];
                    updateExamToothCell(currentExamTooth);
                    updateExamSummary();
                    closeExamModal();
                    Swal.fire({ icon: 'success', title: 'Cleared', text: data.message, timer: 1200, showConfirmButton: false });
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: data.message });
                }
            });
    });
}

function updateExamToothCell(num) {
    const cell = document.querySelector(`.exam-tooth-cell[data-tooth="${num}"]`);
    if (!cell) return;

    const entry = examChartData[num];
    const conds = entry ? (entry.conditions || []) : [];
    const surfs = entry ? (entry.surfaces   || []) : [];

    cell.querySelectorAll('.exam-tooth-badge, .exam-tooth-surfaces').forEach(el => el.remove());

    if (!entry || conds.length === 0) {
        cell.dataset.hasExam = '0';
        cell.querySelector('.exam-tooth-img-wrap').style.setProperty('--tooth-color', '#cbd5e1');
        return;
    }

    cell.dataset.hasExam = '1';

    const firstColor = EXAM_CONDITIONS[conds[0]]?.color || '#cbd5e1';
    const color = (conds.length === 1 && conds[0] === 'healthy')
        ? EXAM_CONDITIONS.healthy.color
        : firstColor;
    cell.querySelector('.exam-tooth-img-wrap').style.setProperty('--tooth-color', color);

    // Badge — if "Other" is the sole condition, show custom text
    let badgeLabel;
    if (conds.length === 1 && conds[0] === 'other' && entry.other_details) {
        badgeLabel = entry.other_details;
    } else if (conds.length === 1) {
        badgeLabel = EXAM_CONDITIONS[conds[0]].label;
    } else {
        badgeLabel = conds.length + ' conditions';
    }

    const condsTooltip = conds.map(k => {
        if (k === 'other' && entry.other_details) return `Other: ${entry.other_details}`;
        return EXAM_CONDITIONS[k]?.label || k;
    }).join(', ');

    const badge = document.createElement('span');
    badge.className = 'exam-tooth-badge';
    badge.style.background = color + '20';
    badge.style.color = color;
    badge.title = condsTooltip;
    badge.textContent = badgeLabel;
    cell.appendChild(badge);

    if (surfs.length) {
        const s = document.createElement('span');
        s.className = 'exam-tooth-surfaces';
        s.title = 'Surfaces';
        s.textContent = surfs.join('·');
        cell.appendChild(s);
    }
}

function updateExamSummary() {
    const entries = Object.values(examChartData);
    const count = entries.length;
    document.getElementById('examRecordedCount').textContent = count + ' tooth' + (count === 1 ? '' : 'es');

    if (entries.length) {
        const latest = entries.reduce((a, b) => (a.examined_at > b.examined_at ? a : b));
        document.getElementById('examLatestDate').textContent = latest.examined_at || '—';
        document.getElementById('examLatestBy').textContent = latest.examined_by || '—';
    }
}

// Close on outside click / Esc
document.getElementById('examModal').addEventListener('click', function(e) {
    if (e.target === this) closeExamModal();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeExamModal();
});

// ==================== existing scripts ====================
(function () {
    function refreshConditional(triggerName, expectedValue) {
        var checked = document.querySelector('input[name="' + triggerName + '"]:checked');
        var isMatch = checked && checked.value === expectedValue;
        document.querySelectorAll('[data-show-when="' + triggerName + '=' + expectedValue + '"]').forEach(function (el) {
            el.style.display = isMatch ? 'flex' : 'none';
            if (el.classList.contains('conditional-field')) {
                el.classList.toggle('is-visible', isMatch);
            }
        });
    }

    var triggers = ['under_physician_care', 'taking_medicine', 'allergic_other', 'hospitalized'];
    triggers.forEach(function (name) {
        refreshConditional(name, 'yes');
        document.querySelectorAll('input[name="' + name + '"]').forEach(function (input) {
            input.addEventListener('change', function () { refreshConditional(name, 'yes'); });
        });
    });
})();

function toggleHistorySection(event) {
    if (event && event.target.closest('a, button')) return;
    const card = document.getElementById('target-section');
    if (!card) return;
    card.classList.toggle('open');
    if (card.classList.contains('open')) {
        setTimeout(() => {
            card.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }, 100);
    }
}

function openHistorySectionAndScroll() {
    const card = document.getElementById('target-section');
    if (!card) return;
    card.classList.add('open');
    setTimeout(() => {
        card.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, 100);
}

(function () {
    const hash = window.location.hash;
    const params = new URLSearchParams(window.location.search);
    if (hash === '#history' || params.get('open') === 'history') {
        const card = document.getElementById('target-section');
        if (card) {
            card.classList.add('open');
            setTimeout(() => card.scrollIntoView({ behavior: 'smooth', block: 'start' }), 150);
        }
    }
})();
</script>

<?php if (isset($_GET['saved'])): ?>
<script>
    window.addEventListener('DOMContentLoaded', function () {
        <?php if ($_GET['saved'] === '1'): ?>
            Swal.fire({
                icon: 'success',
                title: 'Saved',
                text: 'Medical & dental history has been saved.',
                timer: 2200,
                showConfirmButton: false
            });
        <?php else: ?>
            Swal.fire({
                icon: 'error',
                title: 'Save failed',
                text: 'Something went wrong while saving. Please try again.'
            });
        <?php endif; ?>

        if (window.history.replaceState) {
            const url = new URL(window.location.href);
            url.searchParams.delete('saved');
            window.history.replaceState({}, document.title, url.toString());
        }
    });
</script>
<?php endif; ?>

</body>
</html>