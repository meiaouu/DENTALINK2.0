<?php
require '../../conn/conn.php';
date_default_timezone_set('Asia/Manila');

// Get patient ID from URL
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    header('Location: patients.php');
    exit;
}

// Helper function to safely convert date to timestamp
function safeStrToTime($date) {
    if (empty($date)) {
        return 0;
    }
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
// Match by phone, email, OR the appointment_id directly linked on the patient record
$appointments = [];
$stmt = $conn->prepare("
    SELECT DISTINCT a.*, 
           d.first_name as dentist_first_name, 
           d.last_name as dentist_last_name 
    FROM appointments a
    LEFT JOIN dentists d ON a.dentist_id = d.id
    WHERE a.phone = ? 
       OR a.email = ?
       OR a.id = ?
    ORDER BY a.appointment_date DESC, a.appointment_time DESC
");
$linked_appt_id = (int)($patient['appointment_id'] ?? 0);
$stmt->bind_param("ssi", $patient['phone'], $patient['email'], $linked_appt_id);
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
    
    // Cancelled appointments go to past
    if ($status === 'cancelled') {
        $past_appointments[] = $appt;
        continue;
    }
    
    if ($appt_date === $current_date) {
        $today_appointments[] = $appt;
    } elseif ($appt_date > $current_date) {
        $upcoming_appointments[] = $appt;
    } else {
        $past_appointments[] = $appt;
    }
}

usort($upcoming_appointments, function($a, $b) {
    return safeStrToTime($a['appointment_date']) - safeStrToTime($b['appointment_date']);
});

usort($today_appointments, function($a, $b) {
    return safeStrToTime($a['appointment_time'] ?? '00:00') - safeStrToTime($b['appointment_time'] ?? '00:00');
});

usort($past_appointments, function($a, $b) {
    return safeStrToTime($b['appointment_date']) - safeStrToTime($a['appointment_date']);
});

// Fetch the most recent medical/dental history record for this patient
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
    $result = $stmt->get_result();
    $medical_history = $result->fetch_assoc();
    $stmt->close();
}

// Helper function to get service names
function getServiceNames($conn, $services_json) {
    if (empty($services_json)) {
        return ['No services', 0];
    }
    
    $service_ids = json_decode($services_json, true);
    if (!is_array($service_ids) || empty($service_ids)) {
        return ['No services', 0];
    }
    
    $placeholders = implode(',', array_fill(0, count($service_ids), '?'));
    $types = str_repeat('i', count($service_ids));
    $stmt = $conn->prepare("SELECT title, price FROM services WHERE id IN ($placeholders)");
    $stmt->bind_param($types, ...$service_ids);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $names = [];
    $total = 0;
    while ($row = $result->fetch_assoc()) {
        $names[] = $row['title'];
        $total += (float)$row['price'];
    }
    $stmt->close();
    
    return [implode(', ', $names), $total];
}

// Function to get status badge class
function getStatusBadgeClass($status) {
    switch ($status) {
        case 'confirmed':
            return 'status-confirmed';
        case 'approved':
            return 'status-approved';
        case 'pending':
            return 'status-pending';
        case 'cancelled':
            return 'status-cancelled';
        default:
            return 'status-pending';
    }
}

// Function to format appointment date
function formatAppointmentDate($date) {
    if (empty($date)) return 'Not set';
    return date('F d, Y', strtotime($date));
}

// Function to format appointment time
function formatAppointmentTime($time) {
    if (empty($time)) return 'Not set';
    return date('g:i A', strtotime($time));
}

// Generate request ID
$patient_id_display = $patient['patient_id'] ?? 'PAT-' . str_pad($patient['id'], 6, '0', STR_PAD_LEFT);

include 'ssidebar.php';
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
            --navy: #0b1628;
            --teal: #0d9e8c;
            --teal-dark: #0a7d6e;
            --teal-light: #e6f7f5;
            --mint: #eafaf6;
            --slate: #64748b;
            --line: #eef1f6;
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
            position: sticky;
            top: 0;
            z-index: 30;
            background: white;
            border-bottom: 1px solid var(--line);
            padding: 16px 32px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .top-bar-title {
            font-size: 15px;
            font-weight: 700;
            color: var(--navy);
        }
        .top-bar-actions { display: flex; gap: 10px; }
        .icon-btn {
            width: 38px; height: 38px;
            border-radius: 10px;
            border: 1px solid var(--line);
            background: #f8fafc;
            color: var(--slate);
            display: flex; align-items: center; justify-content: center;
            font-size: 14px;
            cursor: pointer;
            transition: all .2s;
        }
        .icon-btn:hover { background: var(--teal-light); color: var(--teal-dark); border-color: #bfe9e2; }

        .page-body {
            padding: 28px 32px 48px;
            max-width: 1280px;
            margin: 0 auto;
        }

        .patient-profile-card {
            background: white;
            border-radius: 20px;
            box-shadow: var(--shadow-card);
            border: 1px solid #f0f3f7;
            overflow: hidden;
            margin-bottom: 28px;
        }

        .profile-header {
            background: linear-gradient(135deg, #0b1628 0%, #1a2a4a 100%);
            padding: 28px 32px;
            display: flex;
            align-items: center;
            gap: 24px;
            flex-wrap: wrap;
        }
        .profile-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: rgba(13,158,140,0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            color: var(--teal);
            font-weight: 700;
            border: 3px solid rgba(13,158,140,0.3);
            flex-shrink: 0;
        }
        .profile-info {
            flex: 1;
            min-width: 200px;
        }
        .profile-info h1 {
            font-size: 28px;
            font-weight: 700;
            color: white;
            margin: 0;
        }
        .profile-info .sub {
            color: rgba(255,255,255,0.6);
            font-size: 14px;
            margin-top: 4px;
        }
        .profile-info .sub i {
            margin-right: 6px;
        }
        .profile-badges {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 8px;
        }
        .profile-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 14px;
            border-radius: 50px;
            font-size: 11px;
            font-weight: 600;
        }
        .profile-badge.active { background: #d4edda; color: #155724; }
        .profile-badge.inactive { background: #f8d7da; color: #721c24; }
        .profile-badge.pending { background: #fef3d7; color: #b45309; }

        .profile-stats {
            display: flex;
            gap: 32px;
            margin-left: auto;
            padding: 8px 0;
        }
        .profile-stats .stat-item {
            text-align: center;
            color: rgba(255,255,255,0.8);
        }
        .profile-stats .stat-item .number {
            font-size: 24px;
            font-weight: 700;
            color: white;
            display: block;
        }
        .profile-stats .stat-item .label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            opacity: 0.6;
        }

        .profile-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            padding: 20px 32px;
            background: #fafbfc;
            border-top: 1px solid var(--line);
        }
        .detail-item {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .detail-item i {
            width: 20px;
            color: var(--teal);
            font-size: 14px;
        }
        .detail-item .label {
            font-size: 11px;
            color: var(--slate);
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .detail-item .value {
            font-weight: 600;
            color: var(--navy);
            font-size: 14px;
        }

        .appointments-section {
            background: white;
            border-radius: 20px;
            box-shadow: var(--shadow-card);
            border: 1px solid #f0f3f7;
            overflow: hidden;
            margin-bottom: 24px;
        }

        /* Scrollable container for appointments */
        .appointments-scroll {
            max-height: 400px;
            overflow-y: auto;
            overflow-x: hidden;
        }
        
        /* Custom scrollbar styling */
        .appointments-scroll::-webkit-scrollbar {
            width: 6px;
        }
        .appointments-scroll::-webkit-scrollbar-track {
            background: #f1f4f8;
        }
        .appointments-scroll::-webkit-scrollbar-thumb {
            background: #d0d7e2;
            border-radius: 10px;
        }
        .appointments-scroll::-webkit-scrollbar-thumb:hover {
            background: #b0b7c2;
        }

        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 18px 24px;
            border-bottom: 1px solid var(--line);
            background: #fafbfc;
            flex-shrink: 0;
        }
        .section-header h2 {
            font-size: 16px;
            font-weight: 700;
            color: var(--navy);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .section-header h2 i {
            color: var(--teal);
        }
        .section-header .count {
            font-size: 12px;
            color: var(--slate);
            background: #f1f4f8;
            padding: 2px 12px;
            border-radius: 12px;
        }

        .appointment-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 24px;
            border-bottom: 1px solid var(--line);
            transition: background 0.2s;
            cursor: pointer;
        }
        .appointment-item:hover {
            background: #f8fafc;
        }
        .appointment-item:last-child {
            border-bottom: none;
        }
        .appointment-item .left {
            display: flex;
            align-items: center;
            gap: 16px;
            flex: 1;
        }
        .appointment-item .left .icon-circle {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
        }
        .appointment-item .left .icon-circle.upcoming {
            background: #d1e7ff;
            color: #084298;
        }
        .appointment-item .left .icon-circle.today {
            background: #d4edda;
            color: #155724;
        }
        .appointment-item .left .icon-circle.past {
            background: #f1f4f8;
            color: var(--slate);
        }
        .appointment-item .left .icon-circle.cancelled {
            background: #fee9e7;
            color: #b91c1c;
        }
        .appointment-item .info h4 {
            font-size: 14px;
            font-weight: 600;
            color: var(--navy);
            margin: 0 0 2px 0;
        }
        .appointment-item .info .meta {
            font-size: 12px;
            color: var(--slate);
        }
        .appointment-item .info .meta i {
            margin-right: 4px;
        }
        .appointment-item .right {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .appointment-item .right .status-badge {
            padding: 4px 14px;
            border-radius: 50px;
            font-size: 11px;
            font-weight: 600;
        }
        .appointment-item .right .status-badge.status-confirmed { background: #d4edda; color: #155724; }
        .appointment-item .right .status-badge.status-approved { background: #d1e7ff; color: #084298; }
        .appointment-item .right .status-badge.status-pending { background: #fef3d7; color: #b45309; }
        .appointment-item .right .status-badge.status-cancelled { background: #fee9e7; color: #b91c1c; }

        .appointment-item .right .appt-id {
            font-size: 11px;
            color: #94a3b8;
            font-weight: 500;
        }

        .empty-state {
            padding: 40px 20px;
            text-align: center;
            color: var(--slate);
        }
        .empty-state i {
            font-size: 36px;
            color: #d0d7e2;
            display: block;
            margin-bottom: 12px;
        }
        .empty-state h3 {
            font-size: 16px;
            font-weight: 600;
            color: var(--navy);
            margin-bottom: 4px;
        }
        .empty-state p {
            font-size: 13px;
        }

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
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 10px;
            border: 1px solid var(--line);
            background: white;
            color: var(--slate);
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            transition: all .2s;
            text-decoration: none;
        }
        .back-btn:hover { background: #f8fafc; border-color: #cbd5e1; }

        /* ===== Medical & Dental History (polished) ===== */
        .history-intro {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin: 18px 24px 0;
            padding: 12px 16px;
            background: var(--teal-light);
            border: 1px solid #bfe9e2;
            border-radius: 12px;
            font-size: 13px;
            color: var(--teal-dark);
            line-height: 1.5;
        }
        .history-intro i { margin-top: 2px; }

        .history-form { padding: 4px 0 0; }

        .history-subsection {
            padding: 20px 24px 4px;
            border-top: 1px solid var(--line);
        }
        .history-subsection:first-of-type { border-top: none; }
        .history-subsection-title {
            display: flex;
            align-items: center;
            gap: 9px;
            font-size: 13px;
            font-weight: 700;
            color: var(--navy);
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin-bottom: 16px;
        }
        .history-subsection-title i {
            width: 26px;
            height: 26px;
            border-radius: 8px;
            background: var(--teal-light);
            color: var(--teal-dark);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
        }

        .history-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 16px 20px;
            padding-bottom: 20px;
        }
        .history-grid.two-col { grid-template-columns: repeat(2, 1fr); }

        .form-field {
            display: flex;
            flex-direction: column;
            gap: 7px;
        }
        .form-field.span-full { grid-column: 1 / -1; }

        .form-field label {
            font-size: 12px;
            font-weight: 600;
            color: var(--slate);
        }
        .form-field .hint {
            font-size: 11px;
            color: #a0aabb;
            font-weight: 400;
            margin-left: 4px;
        }

        .form-field input[type="text"],
        .form-field textarea {
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 13px;
            color: var(--navy);
            background: white;
            font-family: inherit;
            width: 100%;
            transition: border-color .15s, box-shadow .15s;
        }
        .form-field textarea { resize: vertical; min-height: 44px; }
        .form-field input[type="text"]:focus,
        .form-field textarea:focus {
            outline: none;
            border-color: var(--teal);
            box-shadow: 0 0 0 3px rgba(13,158,140,0.12);
        }
        .form-field input[type="text"]:disabled,
        .form-field textarea:disabled {
            background: #f4f6f9;
            color: #aab2c0;
            cursor: not-allowed;
        }

        /* Segmented Yes/No/Unanswered toggle, styled radio pills */
        .yn-toggle {
            display: inline-flex;
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 3px;
            background: #f8fafc;
            width: fit-content;
        }
        .yn-toggle input { position: absolute; opacity: 0; pointer-events: none; }
        .yn-toggle label {
            padding: 6px 14px;
            font-size: 12px;
            font-weight: 600;
            color: var(--slate);
            border-radius: 7px;
            cursor: pointer;
            transition: all .15s;
            margin: 0;
            user-select: none;
        }
        .yn-toggle input:checked + label.yn-yes { background: #fee9e7; color: #b91c1c; }
        .yn-toggle input:checked + label.yn-no { background: #d4edda; color: #155724; }
        .yn-toggle input:checked + label.yn-na { background: #e2e8f0; color: var(--navy); }
        .yn-toggle label:hover { color: var(--navy); }

        .conditional-field {
            margin-top: 10px;
            padding-left: 2px;
            display: none;
        }
        .conditional-field.is-visible { display: flex; }

        .history-form-actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            padding: 18px 24px;
            border-top: 1px solid var(--line);
            background: #fafbfc;
        }
        .save-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 11px 22px;
            border-radius: 10px;
            border: none;
            background: var(--teal);
            color: white;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            transition: background .2s, transform .1s;
            box-shadow: 0 4px 12px rgba(13,158,140,0.25);
        }
        .save-btn:hover { background: var(--teal-dark); }
        .save-btn:active { transform: scale(0.98); }

        .history-updated-note {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 11.5px;
            color: #94a3b8;
        }

        @media (max-width: 640px) {
            .history-subsection { padding: 16px 16px 4px; }
            .history-form-actions { padding: 16px; flex-direction: column-reverse; align-items: stretch; }
            .save-btn { justify-content: center; }
        }

        /* ===== Collapsible Medical & Dental History ===== */
        .collapsible-header {
            cursor: pointer;
            user-select: none;
            transition: background 0.15s;
        }
        .collapsible-header:hover {
            background: #f1f5f9;
        }
        .collapsible-header .toggle-chevron {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            border-radius: 8px;
            background: #eef2f6;
            color: var(--slate);
            font-size: 12px;
            transition: transform 0.3s ease, background 0.15s, color 0.15s;
        }
        .collapsible-header:hover .toggle-chevron {
            background: var(--teal-light);
            color: var(--teal-dark);
        }

        .history-collapsible.open .toggle-chevron {
            transform: rotate(180deg);
        }

        .history-collapsible .collapsible-body {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.4s ease;
        }
        .history-collapsible.open .collapsible-body {
            max-height: 5000px;
            transition: max-height 0.6s ease;
        }
    </style>
</head>
<body>

<div class="main-content">

    <!-- ===== TOP BAR ===== -->
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
                            <div class="icon-circle today">
                                <i class="fas fa-check-circle"></i>
                            </div>
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
                            <span class="status-badge <?= $status_class ?>">
                                <?= ucfirst($appt['status'] ?? 'Pending') ?>
                            </span>
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
                            <div class="icon-circle upcoming">
                                <i class="fas fa-calendar-check"></i>
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
                            <span class="status-badge <?= $status_class ?>">
                                <?= ucfirst($appt['status'] ?? 'Pending') ?>
                            </span>
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
                            <span class="status-badge <?= $status_class ?>">
                                <?= ucfirst($appt['status'] ?? 'Pending') ?>
                            </span>
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
            // The appointment this form will save against: keep the record's
            // own appointment if one exists, otherwise default to the
            // patient's most recent appointment.
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

                        <!-- General Health -->
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

                        <!-- Allergies -->
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

                        <!-- Hospitalization -->
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

                        <!-- Dental History -->
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

        <!-- Footer -->
        <div class="mt-8 text-center text-xs text-gray-400 border-t border-gray-200 pt-5">
            <i class="fas fa-tooth mr-1 text-teal/50"></i>
            Wansi Calacat Dental Clinic · Patient Record
        </div>
    </div>
</div>

<script>
    // Toggle conditional detail fields based on their related Yes/No selector
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

    // ===== Collapsible Medical & Dental History =====
    function toggleHistorySection(event) {
        // Don't collapse when the user clicks a link/button inside the header
        if (event && event.target.closest('a, button')) return;

        const card = document.getElementById('target-section');
        if (!card) return;

        card.classList.toggle('open');

        // If opening, scroll into view smoothly (nice UX)
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

    // Auto-open the history section if the URL has #history or ?open=history
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

        // Clean the ?saved= param out of the URL so a refresh doesn't re-fire the alert
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