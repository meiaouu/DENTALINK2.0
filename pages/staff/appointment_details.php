<?php
require '../../conn/conn.php';
require_once '../../functions/appointment_patient_search.php';
try { $authorizedStaff = aps_staff_authorized($conn); }
catch (Throwable $e) { error_log('Staff details authorization failed: ' . $e->getMessage()); $authorizedStaff = false; }
if (!$authorizedStaff) { http_response_code(403); exit('Authorized staff access required.'); }
require '../../functions/appointment_email.php';
// Appointment actions are handled by appointments.php, which validates this session token.
if (empty($_SESSION['staff_appointments_csrf'])) {
    $_SESSION['staff_appointments_csrf'] = bin2hex(random_bytes(32));
}
$appointmentActionsCsrf = $_SESSION['staff_appointments_csrf'];
date_default_timezone_set('Asia/Manila');

// Get appointment ID from URL
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    header('Location: appointments.php');
    exit;
}

// Fetch appointment details
$stmt = $conn->prepare("SELECT * FROM appointments WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$appointment = $result->fetch_assoc();
$stmt->close();

if (!$appointment) {
    header('Location: appointments.php');
    exit;
}

// ============================================================
// Fetch active dentists (for the assign dropdown)
// ============================================================
$dentists = [];
$result = $conn->query("SELECT id, first_name, last_name, specialization FROM dentists WHERE is_active = 1 ORDER BY last_name ASC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $dentists[] = $row;
    }
}

// ============================================================
// Resolve the assigned dentist's display name
// ============================================================
$dentist_name = 'Not assigned';
if (!empty($appointment['dentist_id'])) {
    $dentist_stmt = $conn->prepare("SELECT first_name, last_name FROM dentists WHERE id = ?");
    $dentist_stmt->bind_param("i", $appointment['dentist_id']);
    $dentist_stmt->execute();
    $dentist_result = $dentist_stmt->get_result();
    if ($dentist_row = $dentist_result->fetch_assoc()) {
        $dentist_name = 'Dr. ' . $dentist_row['first_name'] . ' ' . $dentist_row['last_name'];
    }
    $dentist_stmt->close();
}

// ============================================================
// Resolve the linked patient record.
// We match on appointment_id first, then fall back to email or phone
// so returning patients (with multiple appointments) are recognised.
// ============================================================
$patient_id = null;
// New appointments carry an explicit patients.id. Never replace it by a loose
// email/phone match (family members can share contact details).
if (!empty($appointment['patient_id'])) {
    $linked_id = (int)$appointment['patient_id'];
    $stmt = $conn->prepare('SELECT id FROM patients WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $linked_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row) $patient_id = (int)$row['id'];
    $stmt->close();
} else {
    // Historical appointments predate patient_id; retain the original lookup
    // while excluding blank contacts to reduce incorrect associations.
    $stmt = $conn->prepare("
        SELECT id FROM patients
        WHERE appointment_id = ?
           OR (email <> '' AND email = ?)
           OR (phone <> '' AND phone = ?)
        ORDER BY (appointment_id = ?) DESC, id ASC
        LIMIT 1
    ");
    $stmt->bind_param('issi', $appointment['id'], $appointment['email'], $appointment['phone'], $appointment['id']);
    $stmt->execute();
    if ($row = $stmt->get_result()->fetch_assoc()) $patient_id = (int)$row['id'];
    $stmt->close();
}

// ============================================================
// Fetch services for this appointment
// ============================================================
$services_list = [];
$total_price = 0;
if ($appointment['services']) {
    $service_ids = json_decode($appointment['services'], true);
    if (is_array($service_ids) && !empty($service_ids)) {
        $placeholders = implode(',', array_fill(0, count($service_ids), '?'));
        $types = str_repeat('i', count($service_ids));
        $stmt = $conn->prepare("SELECT id, title, price FROM services WHERE id IN ($placeholders)");
        $stmt->bind_param($types, ...$service_ids);
        $stmt->execute();
        $services_result = $stmt->get_result();
        while ($row = $services_result->fetch_assoc()) {
            $services_list[] = $row;
            $total_price += (float)$row['price'];
        }
        $stmt->close();
    }
}

// ============================================================
// Fetch medical & dental history
// ============================================================
$medicalHistory = null;
$stmt = $conn->prepare("SELECT * FROM medical_dental_history WHERE appointment_id = ? ORDER BY id DESC LIMIT 1");
$stmt->bind_param("i", $id);
$stmt->execute();
$medicalHistory = $stmt->get_result()->fetch_assoc();
$stmt->close();

$mh_conditions = [];
if ($medicalHistory && !empty($medicalHistory['conditions'])) {
    $mh_conditions = array_filter(array_map('trim', explode(',', $medicalHistory['conditions'])));
}

$mh_allergies = [];
if ($medicalHistory) {
    if (($medicalHistory['allergic_local_anesthesia'] ?? '') === 'yes') $mh_allergies[] = 'Local Anesthesia';
    if (($medicalHistory['allergic_antibiotics'] ?? '') === 'yes') $mh_allergies[] = 'Antibiotics';
    if (($medicalHistory['allergic_pain_killer'] ?? '') === 'yes') $mh_allergies[] = 'Pain Killer';
    if (($medicalHistory['allergic_other'] ?? '') === 'yes') {
        $mh_allergies[] = !empty($medicalHistory['allergic_other_details']) ? $medicalHistory['allergic_other_details'] : 'Other';
    }
}

// Yes / No badge helper
function yn($val) {
    if ($val === 'yes') return '<span class="yn-badge yes"><i class="fas fa-check"></i> Yes</span>';
    if ($val === 'no')  return '<span class="yn-badge no">No</span>';
    return '<span class="item-value empty">Not answered</span>';
}

// Generate request ID
$request_id = 'REQ-' . date('YmdHis') . '-' . strtoupper(substr(md5($id), 0, 6));

include __DIR__ . '/ssidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Appointment #<?= $id ?> · Dental Clinic</title>
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
            --btn-dark: #0c2b25;
            --btn-dark-hover: #0a231e;
            --shadow-card: 0 2px 8px rgba(11,22,40,.06), 0 12px 32px rgba(11,22,40,.08);
        }

        html, body { background: #ffffff; }

        body {
            background-color: #f8fafc;
            background-image:
                linear-gradient(rgba(11,22,40,0.04) 1px, transparent 1px),
                linear-gradient(90deg, rgba(11,22,40,0.04) 1px, transparent 1px);
            background-size: 32px 32px;
            background-position: -1px -1px;
        }
        body::before {
            content: '';
            position: fixed;
            left: -140px;
            bottom: -160px;
            width: 480px;
            height: 480px;
            background: radial-gradient(circle, rgba(13,158,140,.16), rgba(13,158,140,0) 70%);
            filter: blur(10px);
            pointer-events: none;
            z-index: 0;
        }

        .bg-teal { background-color: var(--teal); }
        .text-teal { color: var(--teal); }
        .bg-dark-blue { background-color: var(--navy); }
        .text-dark-blue { color: var(--navy); }

        @media (min-width: 1024px) { .main-content { margin-left: 260px; } }
        @media (max-width: 1023px) { .main-content { margin-left: 0; } }

        /* ===== TOP BAR ===== */
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
        .top-bar-title { font-size: 15px; font-weight: 700; color: var(--navy); }
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

        .page-body { position: relative; z-index: 1; max-width: 1180px; margin: 0 auto; }

        .detail-grid {
            display: grid;
            grid-template-columns: minmax(0, 2fr) minmax(280px, 1fr);
            gap: 22px;
            align-items: start;
        }
        @media (max-width: 900px) { .detail-grid { grid-template-columns: 1fr; } }

        /* ===== MAIN CARD ===== */
        .request-card {
            background: white;
            border-radius: 20px;
            box-shadow: var(--shadow-card);
            border: 1px solid #f0f3f7;
            overflow: hidden;
        }

        .rc-header {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            padding: 26px 28px 20px;
        }
        .back-circle {
            width: 34px; height: 34px;
            border-radius: 50%;
            border: 1px solid var(--line);
            background: white;
            color: var(--slate);
            display: flex; align-items: center; justify-content: center;
            font-size: 13px;
            cursor: pointer;
            flex-shrink: 0;
            margin-top: 4px;
            transition: all .2s;
        }
        .back-circle:hover { background: #f8fafc; border-color: #cbd5e1; color: var(--navy); }
        .rc-header-text { flex: 1; min-width: 0; }
        .patient-name {
            font-size: 24px; font-weight: 700; color: var(--navy);
            font-family: 'Playfair Display', serif; line-height: 1.2;
        }
        .request-id {
            font-size: 12.5px; color: #94a3b8; font-weight: 500;
            letter-spacing: .3px; margin-top: 3px;
        }
        .rc-header-badges { display: flex; gap: 8px; flex-shrink: 0; margin-top: 4px; flex-wrap: wrap; justify-content: flex-end; }

        .pill {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 5px 14px; border-radius: 50px;
            font-size: 11.5px; font-weight: 700; white-space: nowrap;
        }
        .pill i { font-size: 7px; }
        .pill.status-confirmed { background: #d4edda; color: #155724; }
        .pill.status-approved  { background: #d1e7ff; color: #084298; }
        .pill.status-pending   { background: #fef3d7; color: #b45309; }
        .pill.status-cancelled { background: #fee9e7; color: #b91c1c; }
        .pill.guest { background: #f1f4f8; color: var(--slate); }
        .pill.alert-allergy { background: #fee9e7; color: #b91c1c; }
        .pill.alert-allergy i { font-size: 11px; }

        .rc-divider { height: 1px; background: var(--line); margin: 0 28px; }

        .schedule-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            padding: 20px 28px;
            gap: 10px;
        }
        @media (max-width: 640px) { .schedule-row { grid-template-columns: 1fr; gap: 16px; } }
        .schedule-col { padding: 0 18px; }
        .schedule-col:first-child { padding-left: 0; }
        .schedule-col:not(:first-child) { border-left: 1px solid var(--line); }
        @media (max-width: 640px) { .schedule-col { border-left: none !important; padding-left: 0 !important; } }

        .item-label {
            display: flex; align-items: center; gap: 6px;
            font-size: 10.5px; font-weight: 700; color: #94a3b8;
            text-transform: uppercase; letter-spacing: .5px;
            margin-bottom: 6px;
        }
        .item-label i { color: var(--teal); font-size: 11px; width: 12px; }
        .item-value { font-size: 15px; font-weight: 700; color: var(--navy); }
        .item-value.empty { color: #cbd5e1; font-weight: 400; font-style: italic; }
        .item-value.danger { color: #c0392b; }
        .item-sub { font-size: 12px; font-weight: 400; color: #94a3b8; margin-top: 2px; }

        .service-pill {
            display: inline-flex; align-items: center; gap: 5px;
            background: #f1f4f8; color: var(--navy);
            padding: 5px 13px; border-radius: 50px;
            font-size: 12.5px; font-weight: 600;
            margin: 2px 6px 2px 0;
        }
        .service-pill i { color: var(--teal); font-size: 10px; }

        .info-grid-2 { display: grid; grid-template-columns: 1fr 1fr; }
        @media (max-width: 640px) { .info-grid-2 { grid-template-columns: 1fr; } }
        .info-cell { padding: 14px 28px; border-top: 1px solid var(--line); }
        .info-cell:nth-child(odd) { border-right: 1px solid var(--line); }
        @media (max-width: 640px) { .info-cell:nth-child(odd) { border-right: none; } }
        .info-cell.full { grid-column: 1 / -1; border-right: none !important; }

        /* ===== MEDICAL & DENTAL HISTORY ===== */
        .rc-section-title {
            display: flex; align-items: center; gap: 8px;
            font-size: 13px; font-weight: 800; color: var(--navy);
            padding: 18px 28px 2px;
        }
        .rc-section-title i { color: var(--teal); font-size: 12px; }

        .yn-badge {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 3px 11px; border-radius: 50px;
            font-size: 12px; font-weight: 700;
        }
        .yn-badge i { font-size: 9px; }
        .yn-badge.yes { background: #fef3d7; color: #b45309; }
        .yn-badge.no { background: #f1f4f8; color: var(--slate); }

        .condition-pill {
            display: inline-block; background: #f1f4f8; color: var(--navy);
            padding: 4px 12px; border-radius: 50px;
            font-size: 12px; font-weight: 600; margin: 2px 6px 2px 0;
        }
        .allergy-pill {
            display: inline-flex; align-items: center; gap: 5px;
            background: #fee9e7; color: #b91c1c;
            padding: 4px 12px; border-radius: 50px;
            font-size: 12px; font-weight: 700; margin: 2px 6px 2px 0;
        }
        .allergy-pill i { font-size: 10px; }

        .mh-empty-note {
            padding: 14px 28px 20px;
            font-size: 13px; color: #94a3b8; font-style: italic;
        }

        /* ===== ADVANCED ACTIONS ===== */
        .adv-toggle {
            display: flex; align-items: center; gap: 10px;
            padding: 16px 28px; border-top: 1px solid var(--line);
            cursor: pointer; user-select: none; transition: background .15s;
        }
        .adv-toggle:hover { background: #fafbfc; }
        .adv-toggle .adv-icon {
            width: 26px; height: 26px; border-radius: 8px;
            background: #f1f4f8; color: var(--slate);
            display: flex; align-items: center; justify-content: center;
            font-size: 11px;
        }
        .adv-toggle span { font-size: 13.5px; font-weight: 700; color: var(--navy); flex: 1; }
        .adv-toggle .chevron { color: #94a3b8; transition: transform .25s; font-size: 12px; }
        .adv-toggle.open .chevron { transform: rotate(180deg); }

        .adv-panel { display: none; padding: 4px 28px 22px; border-top: 1px dashed var(--line); }
        .adv-panel.open { display: block; }

        .adv-meta {
            font-size: 12px; color: #94a3b8; margin-bottom: 14px;
            display: flex; flex-wrap: wrap; gap: 6px 16px;
        }
        .adv-badges { display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; }
        .verified-pill { display: inline-flex; align-items: center; gap: 6px; padding: 5px 14px; border-radius: 50px; font-size: 11.5px; font-weight: 700; }
        .verified-pill.yes { background: #d4edda; color: #155724; }
        .verified-pill.no { background: #f8d7da; color: #721c24; }

        .adv-actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .btn-sm {
            padding: 8px 16px; border-radius: 9px;
            font-weight: 600; font-size: 12.5px; border: none;
            cursor: pointer; display: inline-flex; align-items: center; gap: 7px;
            text-decoration: none; transition: all .2s;
        }
        .btn-sm.edit { background: var(--teal-light); color: var(--teal-dark); }
        .btn-sm.edit:hover { background: #d3f0eb; }
        .btn-sm.cancel { background: #fef3d7; color: #b45309; }
        .btn-sm.cancel:hover { background: #fbe6b8; }
        .btn-sm.delete { background: #fee9e7; color: #b91c1c; }
        .btn-sm.delete:hover { background: #fbd6d2; }

        /* ===== CONFIRM PANEL ===== */
        .confirm-panel {
            background: var(--mint);
            border-radius: 20px;
            border: 1px solid #d6f2ec;
            padding: 22px 24px;
            position: sticky;
            top: 90px;
        }
        .confirm-panel-title {
            display: flex; align-items: center; gap: 9px;
            font-size: 15px; font-weight: 700; color: var(--navy);
            font-family: 'Playfair Display', serif; margin-bottom: 18px;
        }
        .confirm-panel-title i { color: var(--teal); font-size: 15px; }

        .cf-group { margin-bottom: 16px; }
        .cf-group label {
            display: block; font-size: 11.5px; font-weight: 700;
            color: var(--slate); margin-bottom: 6px;
        }
        .cf-group label .req { color: #dc2626; }
        .cf-group select, .cf-group textarea {
            width: 100%; border: 1.5px solid #d6f2ec; border-radius: 12px;
            padding: 11px 14px; font-size: 13.5px; outline: none;
            transition: border .2s, box-shadow .2s; background: white;
            font-family: 'DM Sans', system-ui, sans-serif; color: var(--navy);
        }
        .cf-group select:focus, .cf-group textarea:focus {
            border-color: var(--teal);
            box-shadow: 0 0 0 4px rgba(13,158,140,.12);
        }
        .cf-group textarea { resize: vertical; min-height: 64px; }

        .btn-confirm {
            width: 100%; background: linear-gradient(135deg, var(--btn-dark), #123f36);
            color: white; padding: 13px; border-radius: 12px;
            font-weight: 700; font-size: 13.5px; border: none; cursor: pointer;
            display: flex; align-items: center; justify-content: center; gap: 8px;
            transition: all .2s;
        }
        .btn-confirm:hover { background: linear-gradient(135deg, var(--btn-dark-hover), #0d3a31); transform: translateY(-1px); }

        .confirm-note { font-size: 11.5px; color: #6b8f87; margin-top: 10px; text-align: center; }

        @media (max-width: 640px) {
            .top-bar { padding: 14px 18px; }
            .page-body { padding: 18px 16px 40px; }
            .rc-header { padding: 20px 18px 16px; flex-wrap: wrap; }
            .rc-header-badges { width: 100%; margin-top: 10px; }
            .rc-divider { margin: 0 18px; }
            .schedule-row { padding: 16px 18px; }
            .info-cell { padding: 12px 18px; }
            .rc-section-title { padding-left: 18px; padding-right: 18px; }
            .mh-empty-note { padding-left: 18px; padding-right: 18px; }
            .adv-toggle, .adv-panel { padding-left: 18px; padding-right: 18px; }
            .confirm-panel { position: static; }
        }
    </style>
</head>
<body>

<div class="main-content">

    <!-- ===== TOP BAR ===== -->
    <div class="top-bar">
        <div class="top-bar-title">Appointment Request — <?= htmlspecialchars($appointment['full_name'] ?? 'Unnamed Patient') ?></div>
        <div class="top-bar-actions">
       </div>
    </div>

    <div class="page-body">

        <!-- ===== LINKED PATIENT BANNER ===== -->
        <?php if ($patient_id && $patient_id > 0): ?>
        <div style="background: #d4edda; border: 1px solid #c3e6cb; border-radius: 12px; padding: 14px 20px; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-check-circle" style="color: #155724; font-size: 18px;"></i>
                <span style="color: #155724; font-weight: 600;">
                    <i class="fas fa-user" style="margin-right: 6px;"></i>
                    This appointment is linked to a registered patient record
                </span>
            </div>
            <a href="patient_details.php?id=<?= (int)$patient_id ?>" class="btn-sm edit" style="background: #155724; color: white; text-decoration: none; display: inline-flex; align-items: center; gap: 7px; padding: 8px 18px; border-radius: 9px; font-weight: 600; font-size: 13px; border: none; cursor: pointer; transition: all .2s;">
                <i class="fas fa-user"></i> View Patient Record
            </a>
        </div>
        <?php else: ?>
        <div style="background: #fef3d7; border: 1px solid #f5c6cb; border-radius: 12px; padding: 14px 20px; margin-bottom: 20px; display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
            <i class="fas fa-info-circle" style="color: #b45309; font-size: 18px;"></i>
            <span style="color: #b45309; font-weight: 500;">
                <i class="fas fa-user-slash" style="margin-right: 6px;"></i>
                This appointment is not from a registered patient (Guest Appointment)
            </span>
            <span style="background: #b45309; color: white; padding: 3px 12px; border-radius: 50px; font-size: 11px; font-weight: 600; text-transform: uppercase;">
                Guest
            </span>
        </div>
        <?php endif; ?>

        <div class="detail-grid">

            <!-- ===== LEFT: MAIN REQUEST CARD ===== -->
            <div class="request-card">

                <!-- Header -->
                <div class="rc-header">
                    <button type="button" class="back-circle" onclick="window.location.href='appointments.php'" title="Back to list">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <div class="rc-header-text">
                        <div class="patient-name"><?= htmlspecialchars($appointment['full_name'] ?? 'Unnamed Patient') ?></div>
                        <div class="request-id"># <?= $request_id ?></div>
                    </div>
                    <div class="rc-header-badges">
                        <?php
                        $status = $appointment['status'] ?? 'pending';
                        $status_label = ucfirst($status);
                        if ($status === 'confirmed') $status_label = 'Confirmed';
                        ?>
                        <?php if (!empty($mh_allergies)): ?>
                        <span class="pill alert-allergy" title="<?= htmlspecialchars(implode(', ', $mh_allergies)) ?>"><i class="fas fa-triangle-exclamation"></i> Allergy Alert</span>
                        <?php endif; ?>
                        <span class="pill status-<?= $status ?>"><i class="fas fa-circle"></i> <?= $status_label ?></span>
                        <?php if (!$patient_id): ?>
                        <span class="pill guest"><i class="fas fa-user"></i> Guest</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="rc-divider"></div>

                <!-- Date / Time / Arrived / Services / Dentist -->
                <div class="schedule-row">
                    <div class="schedule-col">
                        <div class="item-label"><i class="fas fa-calendar-day"></i> Date</div>
                        <?php if ($appointment['appointment_date']): ?>
                            <div class="item-value"><?= date('F d, Y', strtotime($appointment['appointment_date'])) ?></div>
                            <div class="item-sub"><?= date('l', strtotime($appointment['appointment_date'])) ?></div>
                        <?php else: ?>
                            <div class="item-value empty">Not set</div>
                        <?php endif; ?>
                    </div>
                    <div class="schedule-col">
                        <div class="item-label"><i class="fas fa-clock"></i> Time</div>
                        <?php if ($appointment['appointment_time']): ?>
                            <div class="item-value"><?= date('g:i A', strtotime($appointment['appointment_time'])) ?></div>
                            <div class="item-sub">Preferred time</div>
                        <?php else: ?>
                            <div class="item-value empty">Not set</div>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($appointment['arrived_at'])): ?>
                    <div class="schedule-col">
                        <div class="item-label"><i class="fas fa-user-check"></i> Arrived At</div>
                        <div class="item-value" style="color:#0d9e8c;">
                            <?= date('g:i A', strtotime($appointment['arrived_at'])) ?>
                        </div>
                        <div class="item-sub">Confirmed at clinic</div>
                    </div>
                    <?php endif; ?>

                    <div class="schedule-col">
                        <div class="item-label"><i class="fas fa-circle-check"></i> Service(s)</div>
                        <?php if (!empty($services_list)): ?>
                            <div>
                                <?php foreach ($services_list as $service): ?>
                                <span class="service-pill"><i class="fas fa-tooth"></i> <?= htmlspecialchars($service['title']) ?></span>
                                <?php endforeach; ?>
                            </div>
                            <?php if ($total_price > 0): ?>
                            <div class="item-sub" style="margin-top:6px; font-weight:700; color:var(--navy);">Total: ₱<?= number_format($total_price, 2) ?></div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="item-value empty">No services selected</div>
                        <?php endif; ?>
                    </div>

                    <div class="schedule-col">
                        <div class="item-label"><i class="fas fa-user-md"></i> Assigned Dentist</div>
                        <div class="item-value" style="font-size:14px;">
                            <?= htmlspecialchars($dentist_name) ?>
                        </div>
                    </div>
                </div>

                <!-- Info grid -->
                <div class="info-grid-2">
                    <div class="info-cell">
                        <div class="item-label"><i class="fas fa-lock"></i> Privacy Consent</div>
                        <?php if ($appointment['privacy_accepted']): ?>
                            <div class="item-value" style="color:#28a745;"><i class="fas fa-check-circle" style="font-size:12px;"></i> Accepted</div>
                        <?php else: ?>
                            <div class="item-value danger">Missing / Not logged</div>
                        <?php endif; ?>
                    </div>
                    <div class="info-cell">
                        <div class="item-label"><i class="fas fa-id-badge"></i> Patient Portal Account</div>
                        <?php if ($patient_id): ?>
                            <div class="item-value" style="color:#28a745; font-size:14px;"><i class="fas fa-check-circle" style="font-size:12px;"></i> Linked</div>
                        <?php else: ?>
                            <div class="item-value empty">Not requested</div>
                        <?php endif; ?>
                    </div>

                    <div class="info-cell">
                        <div class="item-label"><i class="fas fa-phone"></i> Contact</div>
                        <div class="item-value" style="font-size:14px;"><?= htmlspecialchars($appointment['phone'] ?? 'Not provided') ?></div>
                    </div>
                    <div class="info-cell">
                        <div class="item-label"><i class="fas fa-envelope"></i> Email</div>
                        <div class="item-value" style="font-size:14px;"><?= htmlspecialchars($appointment['email'] ?? 'Not provided') ?></div>
                    </div>

                    <div class="info-cell">
                        <div class="item-label"><i class="fas fa-cake-candles"></i> Birth Date</div>
                        <?php if ($appointment['age']): ?>
                            <?php $birth_year = date('Y') - (int)$appointment['age']; ?>
                            <div class="item-value" style="font-size:14px;" title="Estimated from age">~ <?= date('F d, Y', strtotime($birth_year . '-01-01')) ?></div>
                        <?php else: ?>
                            <div class="item-value empty">Not provided</div>
                        <?php endif; ?>
                    </div>
                    <div class="info-cell">
                        <div class="item-label"><i class="fas fa-ring"></i> Civil Status</div>
                        <div class="item-value empty">Not provided</div>
                    </div>

                    <div class="info-cell">
                        <div class="item-label"><i class="fas fa-briefcase"></i> Occupation</div>
                        <div class="item-value" style="font-size:14px;"><?= $appointment['occupation'] ? htmlspecialchars($appointment['occupation']) : '<span class="empty">Not provided</span>' ?></div>
                    </div>
                    <div class="info-cell">
                        <div class="item-label"><i class="fas fa-hourglass-half"></i> Age</div>
                        <div class="item-value" style="font-size:14px;"><?= $appointment['age'] ? htmlspecialchars($appointment['age']) . ' years old' : '<span class="empty">Not provided</span>' ?></div>
                    </div>

                    <div class="info-cell full">
                        <div class="item-label"><i class="fas fa-location-dot"></i> Address</div>
                        <div class="item-value" style="font-weight:400; font-size:14px;">
                            <?php
                            $address_parts = [];
                            if (!empty($appointment['street_address'])) $address_parts[] = $appointment['street_address'];
                            if (!empty($appointment['barangay'])) $address_parts[] = $appointment['barangay'];
                            if (!empty($appointment['city'])) $address_parts[] = $appointment['city'];
                            if (!empty($appointment['province'])) $address_parts[] = $appointment['province'];
                            if (!empty($appointment['region'])) $address_parts[] = $appointment['region'];
                            echo !empty($address_parts) ? htmlspecialchars(implode(', ', $address_parts)) : '<span class="empty">Not provided</span>';
                            ?>
                        </div>
                    </div>

                    <div class="info-cell full">
                        <div class="item-label"><i class="fas fa-note-sticky"></i> Patient Notes</div>
                        <div class="item-value <?= empty($appointment['notes']) ? 'empty' : '' ?>" style="font-weight:400; font-size:14px;">
                            <?= !empty($appointment['notes']) ? nl2br(htmlspecialchars($appointment['notes'])) : 'No notes provided.' ?>
                        </div>
                    </div>
                </div>

                <!-- Medical & Dental History -->
                <div class="rc-divider"></div>
                <div class="rc-section-title"><i class="fas fa-notes-medical"></i> Medical & Dental History</div>

                <?php if ($medicalHistory): ?>
                <div class="info-grid-2">
                    <div class="info-cell">
                        <div class="item-label"><i class="fas fa-user-doctor"></i> Under Physician's Care</div>
                        <div class="item-value" style="font-size:14px; font-weight:400;">
                            <?= yn($medicalHistory['under_physician_care']) ?>
                            <?php if (!empty($medicalHistory['physician_name'])): ?>
                                <div class="item-sub">Physician: <?= htmlspecialchars($medicalHistory['physician_name']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="info-cell">
                        <div class="item-label"><i class="fas fa-baby"></i> Pregnant</div>
                        <div class="item-value" style="font-size:14px; font-weight:400;"><?= yn($medicalHistory['pregnant']) ?></div>
                    </div>

                    <div class="info-cell">
                        <div class="item-label"><i class="fas fa-pills"></i> Taking Medicine</div>
                        <div class="item-value" style="font-size:14px; font-weight:400;">
                            <?= yn($medicalHistory['taking_medicine']) ?>
                            <?php if (!empty($medicalHistory['medicine_details'])): ?>
                                <div class="item-sub"><?= htmlspecialchars($medicalHistory['medicine_details']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="info-cell">
                        <div class="item-label"><i class="fas fa-hospital"></i> Hospitalized</div>
                        <div class="item-value" style="font-size:14px; font-weight:400;">
                            <?= yn($medicalHistory['hospitalized']) ?>
                            <?php if (!empty($medicalHistory['hospitalized_when']) || !empty($medicalHistory['hospitalized_why'])): ?>
                                <div class="item-sub">
                                    <?= !empty($medicalHistory['hospitalized_when']) ? htmlspecialchars($medicalHistory['hospitalized_when']) : '' ?>
                                    <?= !empty($medicalHistory['hospitalized_why']) ? ' — ' . htmlspecialchars($medicalHistory['hospitalized_why']) : '' ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="info-cell">
                        <div class="item-label"><i class="fas fa-tooth"></i> Worn Denture</div>
                        <div class="item-value" style="font-size:14px; font-weight:400;"><?= yn($medicalHistory['worn_denture']) ?></div>
                    </div>
                    <div class="info-cell">
                        <div class="item-label"><i class="fas fa-calendar-check"></i> Last Dental Visit</div>
                        <div class="item-value" style="font-weight:400; font-size:14px;">
                            <?php if (!empty($medicalHistory['last_dental_visit'])): ?>
                                <?= htmlspecialchars($medicalHistory['last_dental_visit']) ?>
                                <?php if (!empty($medicalHistory['last_dental_visit_reason'])): ?>
                                    <div class="item-sub"><?= htmlspecialchars($medicalHistory['last_dental_visit_reason']) ?></div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="item-value empty">Not provided</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="info-cell full">
                        <div class="item-label"><i class="fas fa-heart-pulse"></i> Medical Conditions</div>
                        <div class="item-value" style="font-weight:400; font-size:14px;">
                            <?php if (!empty($mh_conditions) || !empty($medicalHistory['other_condition_details'])): ?>
                                <?php foreach ($mh_conditions as $condition): ?>
                                    <span class="condition-pill"><?= htmlspecialchars($condition) ?></span>
                                <?php endforeach; ?>
                                <?php if (!empty($medicalHistory['other_condition_details'])): ?>
                                    <span class="condition-pill"><?= htmlspecialchars($medicalHistory['other_condition_details']) ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="item-value empty">None reported</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="info-cell full">
                        <div class="item-label"><i class="fas fa-triangle-exclamation"></i> Allergic Reactions</div>
                        <div class="item-value" style="font-weight:400; font-size:14px;">
                            <?php if (!empty($mh_allergies)): ?>
                                <?php foreach ($mh_allergies as $allergy): ?>
                                    <span class="allergy-pill"><i class="fas fa-triangle-exclamation"></i> <?= htmlspecialchars($allergy) ?></span>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="item-value empty">None reported</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="mh-empty-note">No medical & dental history was submitted for this appointment.</div>
                <?php endif; ?>

                <!-- Advanced Actions -->
                <div class="adv-toggle" id="advToggle" onclick="toggleAdvanced()">
                    <span class="adv-icon"><i class="fas fa-sliders"></i></span>
                    <span>Advanced Actions</span>
                    <i class="fas fa-chevron-down chevron"></i>
                </div>
                <div class="adv-panel" id="advPanel">
                    <div class="adv-meta">
                        <span><i class="far fa-calendar-plus mr-1"></i> Created: <?= date('M d, Y g:i A', strtotime($appointment['created_at'])) ?></span>
                        <?php if (!empty($appointment['updated_at']) && $appointment['updated_at'] != $appointment['created_at']): ?>
                        <span><i class="fas fa-edit mr-1"></i> Updated: <?= date('M d, Y g:i A', strtotime($appointment['updated_at'])) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="adv-badges">
                        <span class="verified-pill <?= $appointment['is_verified'] ? 'yes' : 'no' ?>">
                            <i class="fas <?= $appointment['is_verified'] ? 'fa-check-circle' : 'fa-times-circle' ?>"></i>
                            <?= $appointment['is_verified'] ? 'Verified' : 'Not Verified' ?>
                        </span>
                    </div>
                    <div class="adv-actions">
                        <button type="button" class="btn-sm edit" onclick="window.location.href='appointments.php?action=edit&id=<?= $id ?>'">
                            <i class="fas fa-pen"></i> Edit Appointment
                        </button>
                        <?php if ($patient_id && $patient_id > 0): ?>
                        <a href="patient_details.php?id=<?= (int)$patient_id ?>" class="btn-sm edit" style="background: #d1e7ff; color: #084298;">
                            <i class="fas fa-user"></i> View Patient Record
                        </a>
                        <?php endif; ?>
                        <?php if ($appointment['status'] !== 'cancelled'): ?>
                        <button type="button" class="btn-sm cancel" onclick="updateStatus(<?= $id ?>, 'cancelled')">
                            <i class="fas fa-times"></i> Cancel Appointment
                        </button>
                        <?php endif; ?>
                        <button type="button" class="btn-sm delete" onclick="deleteAppointment(<?= $id ?>)">
                            <i class="fas fa-trash-alt"></i> Delete Appointment
                        </button>
                    </div>
                </div>
            </div>

            <!-- ===== RIGHT: CONFIRM PANEL / CONFIRMED CARD ===== -->
            <?php if (!in_array($appointment['status'], ['confirmed','in_progress','completed','cancelled'], true)): ?>

                <!-- Actionable confirm form -->
                <div class="confirm-panel">
                    <div class="confirm-panel-title"><i class="fas fa-circle-check"></i> Confirm Appointment</div>

                    <form id="confirmForm" onsubmit="return false;">
                        <div class="cf-group">
                            <label for="assignDentist"><i class="fas fa-user-doctor mr-1"></i> Assign Dentist <span class="req">*</span></label>
                            <select id="assignDentist" name="dentist">
                                <option value="">Choose a dentist</option>
                                <?php foreach ($dentists as $dentist): ?>
                                    <option value="<?= $dentist['id'] ?>" <?= ($appointment['dentist_id'] ?? 0) == $dentist['id'] ? 'selected' : '' ?>>
                                        Dr. <?= htmlspecialchars($dentist['first_name'] . ' ' . $dentist['last_name']) ?>
                                        <?php if ($dentist['specialization']): ?>
                                            (<?= htmlspecialchars($dentist['specialization']) ?>)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="cf-group">
                            <label for="internalNotes"><i class="fas fa-note-sticky mr-1"></i> Internal Notes</label>
                            <textarea id="internalNotes" name="notes" placeholder="Optional notes visible only to staff..."></textarea>
                        </div>
                        <button type="button" class="btn-confirm" onclick="confirmAppointment()">
                            <i class="fas fa-check-circle"></i> Confirm Appointment
                        </button>
                        <div class="confirm-note">Select a dentist and click Confirm.</div>
                    </form>
                </div>

            <?php else: ?>

                <!-- Read-only "already confirmed / cancelled" card -->
                <div class="confirm-panel" style="background:<?= $appointment['status'] === 'cancelled' ? '#fef3d7' : '#f8fafc' ?>; border-color:<?= $appointment['status'] === 'cancelled' ? '#f5c6cb' : '#e2e8f0' ?>;">
                    <div class="confirm-panel-title" style="color:<?= $appointment['status'] === 'cancelled' ? '#b45309' : '#0b1628' ?>;">
                        <?php if ($appointment['status'] === 'cancelled'): ?>
                            <i class="fas fa-times-circle" style="color:#b45309;"></i>
                            Appointment Cancelled
                        <?php else: ?>
                            <i class="fas fa-check-circle" style="color:#0d9e8c;"></i>
                            <?= $appointment['status'] === 'in_progress' ? 'Treatment In Progress' : ($appointment['status'] === 'completed' ? 'Treatment Completed' : 'Appointment Confirmed') ?>
                        <?php endif; ?>
                    </div>

                    <p style="font-size:13px; color:#64748b; line-height:1.6; margin-bottom:14px;">
                        <?php if ($appointment['status'] === 'cancelled'): ?>
                            This appointment was cancelled. No further action is needed.
                        <?php else: ?>
                            This appointment was confirmed
                            <?php if (!empty($appointment['arrived_at'])): ?>
                                on <strong style="color:#0b1628;"><?= date('M d, Y g:i A', strtotime($appointment['arrived_at'])) ?></strong>.
                            <?php else: ?>
                                and locked from further changes.
                            <?php endif; ?>
                        <?php endif; ?>
                    </p>

                    <?php if ($appointment['status'] !== 'cancelled'): ?>
                        <div style="background:#fff;border:1px solid #d6f2ec;border-radius:12px;padding:13px 16px;margin-bottom:12px;font-size:13px;color:#0b1628;line-height:1.9;">
                            <div><strong>Scheduled:</strong> <?= !empty($appointment['appointment_date']) ? date('M d, Y', strtotime($appointment['appointment_date'])) : 'Not scheduled' ?> <?= !empty($appointment['appointment_time']) ? date('g:i A', strtotime($appointment['appointment_time'])) : '' ?></div>
                            <div><strong>Actual confirmation:</strong> <?= !empty($appointment['arrived_at']) ? date('M d, Y g:i A', strtotime($appointment['arrived_at'])) : 'Not confirmed' ?></div>
                            <div><strong>Actual treatment start:</strong> <?= !empty($appointment['treatment_started_at']) ? date('M d, Y g:i A', strtotime($appointment['treatment_started_at'])) : 'Not started' ?></div>
                            <?php if (!empty($appointment['treatment_completed_at'])): ?>
                                <div><strong>Actual completion:</strong> <?= date('M d, Y g:i A', strtotime($appointment['treatment_completed_at'])) ?></div>
                            <?php endif; ?>
                        </div>
                        <div style="background:white; border-radius:12px; padding:14px 16px; border:1px solid #e2e8f0; margin-bottom:14px;">
                            <div class="item-label" style="margin-bottom:8px;"><i class="fas fa-user-md"></i> Assigned Dentist</div>
                            <div style="font-size:14px; font-weight:700; color:#0b1628;"><?= htmlspecialchars($dentist_name) ?></div>
                        </div>
                    <?php endif; ?>

                    <?php if ($appointment['status'] === 'confirmed'): ?>
                        <button type="button" class="btn-confirm" style="margin-bottom:12px;background:#0d9e8c;" onclick="changeTreatmentStatus('in_progress')">
                            <i class="fas fa-play"></i> Start Treatment
                        </button>
                        <div class="confirm-note" style="margin:0 0 14px;">The patient stays on Today's Appointments until treatment is completed.</div>
                    <?php elseif ($appointment['status'] === 'in_progress'): ?>
                        <div class="pill" style="background:#dbeafe;color:#1d4ed8;margin-bottom:12px;">Treatment In Progress</div>
                        <button type="button" class="btn-confirm" style="margin-bottom:12px;" onclick="changeTreatmentStatus('completed')">
                            <i class="fas fa-check-double"></i> Complete Treatment
                        </button>
                    <?php endif; ?>

                    <?php if ($patient_id): ?>
                        <a href="patient_details.php?id=<?= (int)$patient_id ?>" class="btn-confirm" style="text-decoration:none; display:flex; justify-content:center;">
                            <i class="fas fa-user"></i> View Patient Record
                        </a>
                    <?php else: ?>
                        <div style="background:#fef3d7; border-radius:12px; padding:12px 14px; font-size:12.5px; color:#b45309; line-height:1.5;">
                            <i class="fas fa-info-circle" style="margin-right:4px;"></i>
                            No patient record is linked to this appointment yet.
                        </div>
                    <?php endif; ?>
                </div>

            <?php endif; ?>

        </div>

        <!-- Footer -->
        <div class="mt-8 text-center text-xs text-gray-400 border-t border-gray-200 pt-5">
            <i class="fas fa-tooth mr-1 text-teal/50"></i>
            Wansi Calacat Dental Clinic · Appointment #<?= $id ?>
        </div>
    </div>
</div>

<script>
const APPOINTMENT_ACTIONS_CSRF = <?= json_encode($appointmentActionsCsrf) ?>;
async function changeTreatmentStatus(nextStatus) {
    const start = nextStatus === 'in_progress';
    const result = await Swal.fire({
        title: start ? 'Start Treatment?' : 'Complete Treatment?',
        text: start ? 'Start treatment now? This keeps the original appointment date and moves the patient to In Progress in Today and Queue.' : 'Only mark complete after the treatment is finished.',
        icon: 'question', showCancelButton: true, confirmButtonColor: '#0d9e8c',
        confirmButtonText: start ? 'Start Treatment' : 'Complete Treatment'
    });
    if (!result.isConfirmed) return;
    const form = new FormData();
    form.append('action', 'update_status');
    form.append('id', <?= (int)$id ?>);
    form.append('status', nextStatus);
    form.append('csrf_token', APPOINTMENT_ACTIONS_CSRF);
    try {
        const response = await fetch('appointments.php?ajax=1', { method: 'POST', body: form });
        const data = await response.json();
        if (!response.ok || !data.success) throw new Error(data.message || 'Unable to update treatment.');
        await Swal.fire({icon:'success',title:start?'Treatment Started':'Treatment Completed',text:data.message,confirmButtonColor:'#0d9e8c'});
        window.location.reload();
    } catch (error) {
        Swal.fire({icon:'error',title:'Unable to update treatment',text:error.message || 'Please try again.'});
    }
}

function toggleAdvanced() {
    document.getElementById('advToggle').classList.toggle('open');
    document.getElementById('advPanel').classList.toggle('open');
}

function deleteAppointment(id) {
    Swal.fire({
        title: 'Delete Appointment',
        text: 'Are you sure you want to delete this appointment? This action cannot be undone!',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Yes, delete it!',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire({
                title: 'Deleting...',
                text: 'Please wait',
                icon: 'info',
                allowOutsideClick: false,
                showConfirmButton: false,
                didOpen: () => Swal.showLoading()
            });

            fetch('appointments.php?ajax=1', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=delete&id=' + id + '&csrf_token=' + encodeURIComponent(APPOINTMENT_ACTIONS_CSRF)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({
                        title: 'Deleted!',
                        text: data.message,
                        icon: 'success',
                        timer: 1500,
                        showConfirmButton: false
                    }).then(() => {
                        window.location.href = 'appointments.php';
                    });
                } else {
                    Swal.fire({ title: 'Error!', text: data.message, icon: 'error' });
                }
            })
            .catch(() => Swal.fire({ title: 'Error!', text: 'An unexpected error occurred.', icon: 'error' }));
        }
    });
}

function confirmAppointment() {
    const dentist = document.getElementById('assignDentist').value;
    const notes = document.getElementById('internalNotes').value;

    if (!dentist) {
        Swal.fire({
            title: 'Select Dentist',
            text: 'Please select a dentist before confirming.',
            icon: 'warning',
            confirmButtonText: 'OK'
        });
        return;
    }

    Swal.fire({
        title: 'Confirm Appointment',
        text: 'Are you sure you want to confirm this appointment? This will create a patient record and send a welcome email.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#0d9e8c',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Yes, Confirm!',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            updateStatusWithDetails(<?= $id ?>, 'confirmed', dentist, notes);
        }
    });
}

function updateStatusWithDetails(id, status, dentistId, notes) {
    Swal.fire({
        title: 'Confirming Appointment...',
        html: 'Please wait while we create the patient record and send the welcome email.',
        icon: 'info',
        allowOutsideClick: false,
        showConfirmButton: false,
        didOpen: () => Swal.showLoading()
    });

    const formData = new FormData();
    formData.append('action', 'update_status');
    formData.append('csrf_token', APPOINTMENT_ACTIONS_CSRF);
    formData.append('id', id);
    formData.append('status', status);
    formData.append('dentist_id', dentistId);
    formData.append('notes', notes);

    fetch('appointments.php?ajax=1', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                let icon = data.warning ? 'warning' : 'success';
                let title = data.warning ? 'Confirmed with Warning' : 'Appointment Confirmed!';
                let message = data.message || 'Appointment confirmed.';
                if (data.dentist) {
                    message = `Appointment confirmed and assigned to ${data.dentist}.<br><br>${data.message}`;
                }
                Swal.fire({
                    title: title,
                    html: message,
                    icon: icon,
                    showConfirmButton: true,
                    confirmButtonText: 'OK'
                }).then(() => {
                    window.location.reload();
                });
            } else {
                Swal.fire({ title: 'Error!', text: data.message, icon: 'error' });
            }
        })
        .catch(() => Swal.fire({ title: 'Error!', text: 'An unexpected error occurred.', icon: 'error' }));
}

function updateStatus(id, status) {
    Swal.fire({
        title: 'Updating Status...',
        text: 'Please wait',
        icon: 'info',
        allowOutsideClick: false,
        showConfirmButton: false,
        didOpen: () => Swal.showLoading()
    });

    const formData = new FormData();
    formData.append('action', 'update_status');
    formData.append('csrf_token', APPOINTMENT_ACTIONS_CSRF);
    formData.append('id', id);
    formData.append('status', status);

    fetch('appointments.php?ajax=1', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                let icon = data.warning ? 'warning' : 'success';
                let title = data.warning ? 'Updated with Warning' : 'Status Updated!';
                Swal.fire({
                    title: title,
                    text: data.message,
                    icon: icon,
                    timer: 2500,
                    showConfirmButton: true
                }).then(() => {
                    window.location.reload();
                });
            } else {
                Swal.fire({ title: 'Error!', text: data.message, icon: 'error' });
            }
        })
        .catch(() => Swal.fire({ title: 'Error!', text: 'An unexpected error occurred.', icon: 'error' }));
}
</script>

<?php $conn->close(); ?>
</body>
</html>