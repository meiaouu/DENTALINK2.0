<?php
require '../../conn/conn.php';
date_default_timezone_set('Asia/Manila');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Mark this admin as having "seen" the today page right now
if (isset($_SESSION['user_id'])) {
    $stmt = $conn->prepare("UPDATE users SET seen_today_at = NOW() WHERE id = ?");
    $stmt->bind_param('i', $_SESSION['user_id']);
    $stmt->execute();
    $stmt->close();
}
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

function h($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

// ========================= DATA HELPERS =========================

function getServicesMap($conn) {
    $map = [];
    $result = $conn->query("SELECT * FROM services");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $map[$row['id']] = $row;
        }
    }
    return $map;
}

function getDentistsMap($conn) {
    $map = [];
    $result = $conn->query("SELECT id, first_name, last_name, specialization FROM dentists WHERE is_active = 1 ORDER BY last_name ASC");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $map[$row['id']] = $row;
        }
    }
    return $map;
}

function parseAppointmentServices($servicesStr, $servicesMap) {
    if (empty($servicesStr)) return [];
    $decoded = json_decode($servicesStr, true);
    $serviceIds = is_array($decoded) ? $decoded : array_map('trim', explode(',', $servicesStr));
    $result = [];
    foreach ($serviceIds as $id) {
        if (isset($servicesMap[$id])) {
            $result[] = $servicesMap[$id];
        }
    }
    return $result;
}

// Status metadata: label, badge color classes, and the next allowed actions
function statusMeta($status) {
    $map = [
        'pending'     => ['label' => 'Pending',     'class' => 'st-pending'],
        'approved'    => ['label' => 'Approved',    'class' => 'st-approved'],
        'confirmed'   => ['label' => 'Confirmed',   'class' => 'st-confirmed'],
        'in_progress' => ['label' => 'In Progress', 'class' => 'st-inprogress'],
        'completed'   => ['label' => 'Completed',   'class' => 'st-completed'],
        'cancelled'   => ['label' => 'Cancelled',   'class' => 'st-cancelled'],
        'rejected'    => ['label' => 'Rejected',    'class' => 'st-cancelled'],
    ];
    if (isset($map[$status])) return $map[$status];
    return ['label' => ucfirst(str_replace('_', ' ', $status)), 'class' => 'st-default'];
}

function getAppointments($conn, $date, $status, $servicesMap, $dentistsMap) {
       $sql = "SELECT * FROM appointments WHERE appointment_date = ? AND status = 'approved' ORDER BY appointment_time ASC, id ASC";
    $params = [$date];
    $types = 's';

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($rows as &$r) {
        $r['service_details'] = parseAppointmentServices($r['services'], $servicesMap);
        $r['service_names_str'] = implode(', ', array_column($r['service_details'], 'title'));
        $r['total_price'] = array_sum(array_column($r['service_details'], 'price'));
        $r['dentist'] = isset($r['dentist_id'], $dentistsMap[$r['dentist_id']]) ? $dentistsMap[$r['dentist_id']] : null;
        $r['status_meta'] = statusMeta($r['status']);
    }
    return $rows;
}

function getStatsForDate($conn, $date) {
       $stmt = $conn->prepare("SELECT status, COUNT(*) as c FROM appointments WHERE appointment_date = ? AND status = 'approved' GROUP BY status");
    $stmt->bind_param('s', $date);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $stats = ['total' => 0, 'pending' => 0, 'approved' => 0, 'confirmed' => 0, 'in_progress' => 0, 'completed' => 0, 'cancelled' => 0];
    foreach ($rows as $r) {
        $stats['total'] += (int)$r['c'];
        if (isset($stats[$r['status']])) {
            $stats[$r['status']] = (int)$r['c'];
        } elseif ($r['status'] === 'rejected') {
            $stats['cancelled'] += (int)$r['c'];
        }
    }
    return $stats;
}

// ========================= RENDER LIST HTML =========================
function renderAppointmentsList($conn, $date, $status, $servicesMap, $dentistsMap) {
    $appointments = getAppointments($conn, $date, $status, $servicesMap, $dentistsMap);

    ob_start();
    if (empty($appointments)) {
        ?>
        <div class="empty-state">
            <div class="empty-state-icon"><i class="fas fa-calendar-day"></i></div>
            <p class="text-[#0b1628] font-semibold">No appointments found</p>
            <p class="text-[#64748b] text-sm mt-1">There are no appointments matching this date and filter.</p>
        </div>
        <?php
    } else {
        foreach ($appointments as $a):
            $sm = $a['status_meta'];
            $canApprove  = $a['status'] === 'pending';
            $canConfirm  = $a['status'] === 'approved';
            $canStart    = $a['status'] === 'confirmed';
            $canComplete = $a['status'] === 'in_progress';
            $canCancel   = in_array($a['status'], ['pending', 'approved', 'confirmed', 'in_progress']);
            ?>
<div class="appt-card" data-id="<?= (int)$a['id'] ?>" onclick="goToPatientRecord(event, <?= (int)$a['id'] ?>)" style="cursor:pointer;">                <div class="appt-time-col">
                    <div class="appt-time"><?= $a['appointment_time'] ? h(date('g:i A', strtotime($a['appointment_time']))) : '—' ?></div>
                    <span class="status-badge <?= $sm['class'] ?>"><?= h($sm['label']) ?></span>
                </div>

                <div class="appt-main">
                    <div class="flex items-start justify-between gap-2 flex-wrap">
                        <div>
                            <h4 class="appt-name"><?= h($a['full_name'] ?: 'Unnamed Patient') ?></h4>
                            <div class="appt-sub">
                                <?php if ($a['age']): ?><span><?= (int)$a['age'] ?> yrs</span><?php endif; ?>
                                <?php if ($a['occupation']): ?><span><?= h($a['occupation']) ?></span><?php endif; ?>
                            </div>
                        </div>
                        <div class="appt-dentist">
                            <i class="fas fa-user-md"></i>
                            <?= $a['dentist'] ? h('Dr. ' . $a['dentist']['first_name'] . ' ' . $a['dentist']['last_name']) : '<span class="unassigned">Unassigned</span>' ?>
                        </div>
                    </div>

                    <div class="appt-info-row">
                        <span><i class="fas fa-phone"></i> <?= h($a['phone']) ?></span>
                        <span><i class="fas fa-envelope"></i> <?= h($a['email']) ?></span>
                    </div>

                    <?php if ($a['service_names_str']): ?>
                    <div class="appt-services">
                        <i class="fas fa-tooth"></i> <?= h($a['service_names_str']) ?>
                        <span class="appt-price">₱<?= number_format($a['total_price'], 2) ?></span>
                    </div>
                    <?php endif; ?>

                <div class="appt-actions" onclick="event.stopPropagation();">
    <button class="btn-xs btn-view" onclick="viewAppointment(<?= (int)$a['id'] ?>)"><i class="fas fa-eye"></i> View</button>
    <?php if ($canApprove): ?>
        <button class="btn-xs btn-approve" onclick="updateStatus(<?= (int)$a['id'] ?>, 'approved')"><i class="fas fa-check"></i> Approve</button>
    <?php endif; ?>
    <?php if ($canConfirm): ?>
        <button class="btn-xs btn-confirm" onclick="updateStatus(<?= (int)$a['id'] ?>, 'confirmed')"><i class="fas fa-check-double"></i> Confirm</button>
    <?php endif; ?>
    <?php if ($canStart): ?>
        <button class="btn-xs btn-start" onclick="updateStatus(<?= (int)$a['id'] ?>, 'in_progress')"><i class="fas fa-play"></i> Start Treatment</button>
    <?php endif; ?>
    <?php if ($canComplete): ?>
        <button class="btn-xs btn-complete" onclick="updateStatus(<?= (int)$a['id'] ?>, 'completed')"><i class="fas fa-flag-checkered"></i> Complete</button>
    <?php endif; ?>
    <?php if ($canCancel): ?>
        <button class="btn-xs btn-cancel" onclick="updateStatus(<?= (int)$a['id'] ?>, 'cancelled')"><i class="fas fa-times"></i> Cancel</button>
    <?php endif; ?>
</div>
                </div>
            </div>
            <?php
        endforeach;
    }
    return ob_get_clean();
}

// ========================= AJAX =========================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];
    $servicesMap = getServicesMap($conn);
    $dentistsMap = getDentistsMap($conn);

    switch ($_POST['action']) {

        case 'get_list':
            $date = $_POST['date'] ?? date('Y-m-d');
            $status = $_POST['status'] ?? 'all';
            $response['success'] = true;
            $response['html'] = renderAppointmentsList($conn, $date, $status, $servicesMap, $dentistsMap);
            $response['stats'] = getStatsForDate($conn, $date);
            $response['label'] = date('l, F j, Y', strtotime($date));
            break;

        case 'update_status':
            $id = (int)($_POST['id'] ?? 0);
            $status = $_POST['status'] ?? '';
            $allowed = ['pending', 'approved', 'confirmed', 'in_progress', 'completed', 'cancelled'];
            if ($id <= 0 || !in_array($status, $allowed)) {
                $response['message'] = 'Invalid request.';
                break;
            }
            $stmt = $conn->prepare("UPDATE appointments SET status = ? WHERE id = ?");
            $stmt->bind_param('si', $status, $id);
            if ($stmt->execute()) {
                $response['success'] = true;
                $response['message'] = 'Appointment updated to ' . $status . '.';
            } else {
                $response['message'] = 'Failed to update appointment.';
            }
            $stmt->close();
            break;

        case 'assign_dentist':
            $id = (int)($_POST['id'] ?? 0);
            $dentistId = $_POST['dentist_id'] ?? '';
            $dentistId = ($dentistId === '' ) ? null : (int)$dentistId;
            if ($id <= 0) {
                $response['message'] = 'Invalid appointment.';
                break;
            }
            $stmt = $conn->prepare("UPDATE appointments SET dentist_id = ? WHERE id = ?");
            $stmt->bind_param('ii', $dentistId, $id);
            if ($stmt->execute()) {
                $response['success'] = true;
                $response['message'] = 'Dentist assignment updated.';
            } else {
                $response['message'] = 'Failed to assign dentist.';
            }
            $stmt->close();
            break;

        case 'save_internal_notes':
            $id = (int)($_POST['id'] ?? 0);
            $notes = trim($_POST['internal_notes'] ?? '');
            if ($id <= 0) {
                $response['message'] = 'Invalid appointment.';
                break;
            }
            $stmt = $conn->prepare("UPDATE appointments SET internal_notes = ? WHERE id = ?");
            $stmt->bind_param('si', $notes, $id);
            if ($stmt->execute()) {
                $response['success'] = true;
                $response['message'] = 'Internal notes saved.';
            } else {
                $response['message'] = 'Failed to save notes.';
            }
            $stmt->close();
            break;

        case 'get_details':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                $response['message'] = 'Invalid appointment.';
                break;
            }
            $stmt = $conn->prepare("SELECT * FROM appointments WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$row) {
                $response['message'] = 'Appointment not found.';
                break;
            }
            $row['service_details'] = parseAppointmentServices($row['services'], $servicesMap);
            $row['total_price'] = array_sum(array_column($row['service_details'], 'price'));
            $row['dentist'] = isset($row['dentist_id'], $dentistsMap[$row['dentist_id']]) ? $dentistsMap[$row['dentist_id']] : null;
            $row['status_meta'] = statusMeta($row['status']);
            $response['success'] = true;
            $response['appointment'] = $row;
            $response['dentists'] = array_values($dentistsMap);
            break;
    }

    echo json_encode($response);
    exit;
}

// ========================= INITIAL LOAD =========================
$servicesMap = getServicesMap($conn);
$dentistsMap = getDentistsMap($conn);

$today = date('Y-m-d');
$filterDate = $_GET['date'] ?? $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDate)) $filterDate = $today;
$filterStatus = $_GET['status'] ?? 'all';
$allowedStatus = ['all', 'pending', 'approved', 'confirmed', 'in_progress', 'completed', 'cancelled'];
if (!in_array($filterStatus, $allowedStatus)) $filterStatus = 'all';

$stats = getStatsForDate($conn, $filterDate);
$dateLabel = date('l, F j, Y', strtotime($filterDate));
$listHtml = renderAppointmentsList($conn, $filterDate, $filterStatus, $servicesMap, $dentistsMap);

include 'sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Appointments · Dental Clinic Dashboard</title>
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
    --purple: #7c3aed;
    --purple-bg: #ede9fe;
    --gray-bg: #eef1f6;
    --shadow-card: 0 2px 8px rgba(11,22,40,.06), 0 12px 32px rgba(11,22,40,.08);
}
.container-modern { max-width: 1280px; margin: 0 auto; padding: 0 1.5rem; }
@media (min-width: 1024px) { .main-content { margin-left: 260px; } }
@media (max-width: 1023px) { .main-content { margin-left: 0; } }
@media (max-width: 640px) { .container-modern { padding: 0 1rem; } }

.top-header {
      align-items: center; justify-content: space-between;
    padding: 1.25rem 1.5rem ;
}
.icon-btn {
    width: 38px; height: 38px; border-radius: 10px; border: 1px solid #e2e8f0;
    display: flex; align-items: center; justify-content: center; background: white;
    color: var(--slate); cursor: pointer;
}
.icon-btn:hover { border-color: var(--teal); color: var(--teal); }

.dashboard-card {
    background: white; border-radius: 18px; padding: 1.5rem;
    box-shadow: var(--shadow-card); border: 1px solid #f0f3f7;
}

.stat-mini {
    background: white; border-radius: 14px; padding: 14px; border: 1px solid #eef2f6;
    box-shadow: var(--shadow-card); display:flex; align-items:center; gap:12px;
}
.stat-mini .stat-icon {
    width: 40px; height: 40px; border-radius: 11px; display:flex; align-items:center; justify-content:center;
    font-size: 16px; flex-shrink:0;
}
.stat-mini p.label { font-size: 10.5px; font-weight: 600; color: #94a3b8; text-transform: uppercase; letter-spacing: .3px; }
.stat-mini p.num { font-size: 22px; font-weight: 800; color: var(--navy); margin-top: 1px; }

.date-nav { display:flex; align-items:center; gap:8px; }
.date-nav-btn {
    width: 32px; height: 32px; border-radius: 9px; border: 1px solid #e2e8f0;
    background: white; color: var(--slate); cursor: pointer; display:flex; align-items:center; justify-content:center;
}
.date-nav-btn:hover { border-color: var(--teal); color: var(--teal); }
.date-input {
    border: 1.5px solid #e2e8f0; border-radius: 10px; padding: 7px 12px; font-size: 13px;
    font-weight: 600; color: var(--navy); background: white;
}
.date-input:focus { outline:none; border-color: var(--teal); box-shadow: 0 0 0 4px rgba(13,158,140,.1); }
.btn-today {
    background: var(--teal-light); color: var(--teal-dark); border:none; padding: 8px 14px;
    border-radius: 10px; font-size: 12.5px; font-weight: 700; cursor: pointer;
}
.btn-today:hover { background: #d3f0ec; }

.status-tabs { display:flex; gap:6px; flex-wrap:wrap; }
.status-tab {
    padding: 7px 14px; border-radius: 9px; border: 1.5px solid #e2e8f0; background: white;
    font-size: 12px; font-weight: 600; color: var(--slate); cursor: pointer; transition: all .15s;
}
.status-tab:hover { border-color: var(--teal); color: var(--teal); }
.status-tab.active { background: var(--navy); border-color: var(--navy); color: white; }

.appt-list { display:flex; flex-direction:column; gap:12px; margin-top: 18px; }
.appt-card {
    display:flex; gap: 16px; background: white; border: 1px solid #eef1f6; border-radius: 16px;
    padding: 16px; box-shadow: 0 1px 3px rgba(11,22,40,.04); transition: all .2s;
}
.appt-card:hover { box-shadow: 0 8px 20px rgba(11,22,40,.08); transform: translateY(-1px); }

.appt-time-col {
    width: 96px; flex-shrink:0; display:flex; flex-direction:column; align-items:center; justify-content:center;
    gap:8px; border-right: 1px dashed #e2e8f0; padding-right: 14px;
}
.appt-time { font-size: 14px; font-weight: 800; color: var(--navy); text-align:center; }

.status-badge {
    padding: 3px 10px; border-radius: 50px; font-size: 10px; font-weight: 700; text-transform: uppercase;
    letter-spacing: .3px; white-space: nowrap;
}
.st-pending    { background: var(--amber-bg); color: var(--amber); }
.st-approved   { background: var(--teal-light); color: var(--teal-dark); }
.st-confirmed  { background: var(--blue-bg); color: var(--blue); }
.st-inprogress { background: var(--purple-bg); color: var(--purple); }
.st-completed  { background: #dcfce7; color: #166534; }
.st-cancelled  { background: var(--red-bg); color: var(--red); }
.st-default    { background: var(--gray-bg); color: var(--slate); }

.appt-main { flex:1; min-width:0; }
.appt-name { font-size: 15px; font-weight: 700; color: var(--navy); font-family:'DM Sans'; }
.appt-sub { display:flex; gap:10px; font-size:11.5px; color:var(--slate); margin-top:2px; }
.appt-dentist { font-size:12px; font-weight:600; color: var(--slate); display:flex; align-items:center; gap:6px; white-space:nowrap; }
.appt-dentist i { color: var(--teal); }
.appt-dentist .unassigned { color:#cbd5e1; font-weight:500; }

.appt-info-row { display:flex; flex-wrap:wrap; gap:14px; font-size:12px; color:var(--slate); margin-top:8px; }
.appt-info-row i { color: var(--teal); width:14px; }

.appt-services {
    font-size:12px; color:#475569; margin-top:8px; background:#f7f9fc; border-radius:9px; padding:7px 10px;
    display:flex; align-items:center; justify-content:space-between; gap:8px;
}
.appt-services i { color: var(--teal); margin-right:4px; }
.appt-price { font-weight:700; color: var(--navy); flex-shrink:0; }

.appt-actions { display:flex; gap:6px; flex-wrap:wrap; margin-top:12px; }
.btn-xs {
    padding: 6px 12px; border-radius: 8px; font-size: 11.5px; font-weight: 700; border:none; cursor:pointer;
    display:inline-flex; align-items:center; gap:5px; transition: all .15s;
}
.btn-view     { background:#f1f4f8; color: var(--navy); }
.btn-view:hover { background:#e2e8f0; }
.btn-approve  { background: var(--teal-light); color: var(--teal-dark); }
.btn-approve:hover { background: var(--teal); color:white; }
.btn-confirm  { background: var(--blue-bg); color: var(--blue); }
.btn-confirm:hover { background: var(--blue); color:white; }
.btn-start    { background: var(--purple-bg); color: var(--purple); }
.btn-start:hover { background: var(--purple); color:white; }
.btn-complete { background:#dcfce7; color:#166534; }
.btn-complete:hover { background:#22c55e; color:white; }
.btn-cancel   { background: var(--red-bg); color: var(--red); }
.btn-cancel:hover { background: var(--red); color:white; }

.empty-state {
    text-align: center; padding: 56px 24px; background: white; border-radius: 18px;
    border: 1.5px dashed #e2e8f0; margin-top: 18px;
}
.empty-state-icon {
    width: 78px; height: 78px; border-radius: 50%; background: var(--teal-light);
    display: flex; align-items: center; justify-content: center; margin: 0 auto 14px;
    font-size: 30px; color: var(--teal);
}

/* Detail modal */
.modal-overlay {
    position: fixed; inset: 0; z-index: 80; background: rgba(11,22,40,.55);
    display: none; align-items: center; justify-content: center; padding: 1rem;
}
.modal-overlay.open { display: flex; }
.modal-box {
    background: white; border-radius: 18px; width: 100%; max-width: 620px;
    box-shadow: 0 24px 60px rgba(11,22,40,.35); overflow: hidden; max-height: 90vh; overflow-y:auto;
}
.modal-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 18px 22px; border-bottom: 1px solid #f0f3f7;
}
.modal-header h3 { font-size: 16px; font-weight: 700; color: var(--navy); font-family: 'DM Sans'; }
.modal-body { padding: 20px 22px; }
.modal-footer {
    display: flex; align-items: center; justify-content: flex-end; gap: 8px;
    padding: 16px 22px; background: #fafbfc; border-top: 1px solid #f0f3f7;
}
.modal-close-btn { background: none; border: none; color: #94a3b8; font-size: 20px; cursor: pointer; }

.detail-section { margin-bottom: 16px; }
.detail-label { font-size: 10.5px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: .4px; margin-bottom: 6px; }
.detail-grid { display:grid; grid-template-columns: 1fr 1fr; gap: 10px 16px; }
.detail-item { font-size: 13px; color: var(--navy); }
.detail-item span { display:block; font-size: 10.5px; color: #94a3b8; font-weight:600; margin-bottom:2px; }

.dentist-select {
    width:100%; border:1.5px solid #e2e8f0; border-radius:10px; padding:9px 12px; font-size:13px;
    color: var(--navy); background: white;
}
.dentist-select:focus { outline:none; border-color: var(--teal); }

.notes-textarea {
    width:100%; border:1.5px solid #e2e8f0; border-radius:10px; padding:10px 12px; font-size:13px;
    min-height: 70px; resize: vertical;
}
.notes-textarea:focus { outline:none; border-color: var(--teal); box-shadow: 0 0 0 4px rgba(13,158,140,.1); }

.service-line { display:flex; justify-content:space-between; padding:6px 0; font-size:12.5px; border-bottom:1px solid #f1f5f9; }
.service-total { display:flex; justify-content:space-between; padding:8px 0 0; font-weight:700; color:var(--navy); border-top:2px solid #e2e8f0; margin-top:4px; }
</style>
</head>
<body>

<div class="main-content px-4  " style="
    min-height:100vh;
    background-color:#f7f9fc;
    background-image:
        linear-gradient(rgba(11,22,40,0.04) 1px, transparent 1px),
        linear-gradient(90deg, rgba(11,22,40,0.04) 1px, transparent 1px);
    background-size: 32px 32px;
    background-position: -1px -1px;
">

  <div class="top-header">
      <h1 class="text-2xl font-bold text-dark-blue flex items-center  ">
                     Today's Appointments
                    </h1>
                    <p class="text-sm text-gray-500 mt-1">Manage all registered patients </p>
            
  </div>

  <main class="py-6">
    <div class="container-modern">

      <!-- Stats -->
      <div class="grid grid-cols-2 md:grid-cols-6 gap-3 mb-5">
        <div class="stat-mini">
          <div class="stat-icon" style="background:#eef1f6; color:var(--navy);"><i class="fas fa-calendar-day"></i></div>
          <div><p class="label">Total</p><p class="num" id="statTotal"><?= $stats['total'] ?></p></div>
        </div>
        <div class="stat-mini">
          <div class="stat-icon" style="background:var(--amber-bg); color:var(--amber);"><i class="fas fa-hourglass-half"></i></div>
          <div><p class="label">Pending</p><p class="num" id="statPending"><?= $stats['pending'] ?></p></div>
        </div>
        <div class="stat-mini">
          <div class="stat-icon" style="background:var(--teal-light); color:var(--teal-dark);"><i class="fas fa-check"></i></div>
          <div><p class="label">Approved</p><p class="num" id="statApproved"><?= $stats['approved'] ?></p></div>
        </div>
        <div class="stat-mini">
          <div class="stat-icon" style="background:var(--blue-bg); color:var(--blue);"><i class="fas fa-check-double"></i></div>
          <div><p class="label">Confirmed</p><p class="num" id="statConfirmed"><?= $stats['confirmed'] ?></p></div>
        </div>
        <div class="stat-mini">
          <div class="stat-icon" style="background:var(--purple-bg); color:var(--purple);"><i class="fas fa-play"></i></div>
          <div><p class="label">In Progress</p><p class="num" id="statInProgress"><?= $stats['in_progress'] ?></p></div>
        </div>
        <div class="stat-mini">
          <div class="stat-icon" style="background:#dcfce7; color:#166534;"><i class="fas fa-flag-checkered"></i></div>
          <div><p class="label">Completed</p><p class="num" id="statCompleted"><?= $stats['completed'] ?></p></div>
        </div>
      </div>

      <div class="dashboard-card">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
          <div>
            <h2 class="text-lg font-bold" style="color:var(--navy);" id="dateLabel"><?= h($dateLabel) ?></h2>
            <p class="text-xs text-gray-400 mt-0.5" id="dateSubLabel"><?= $filterDate === $today ? "Today's schedule" : 'Schedule for this date' ?></p>
          </div>
          <div class="date-nav">
             <input type="date" class="date-input" id="dateInput" value="<?= h($filterDate) ?>">
             <button class="btn-today" id="todayBtn">Today</button>
          </div>
        </div>

       

        <div class="appt-list" id="apptList">
          <?= $listHtml ?>
        </div>
      </div>

    </div>
  </main>
</div>

<!-- APPOINTMENT DETAIL MODAL -->
<div class="modal-overlay" id="detailModal">
  <div class="modal-box">
    <div class="modal-header">
      <h3 id="detailModalTitle">Appointment Details</h3>
      <button class="modal-close-btn" onclick="closeDetailModal()">&times;</button>
    </div>
    <div class="modal-body" id="detailModalBody">
      <!-- populated via JS -->
    </div>
    <div class="modal-footer">
      <button type="button" class="btn-xs btn-view" onclick="closeDetailModal()">Close</button>
      <button type="button" class="btn-xs btn-approve" id="saveDetailBtn" onclick="saveDetailChanges()"><i class="fas fa-save"></i> Save Changes</button>
    </div>
  </div>
</div>
<script>
let currentDate = '<?= h($filterDate) ?>';
let currentStatus = '<?= h($filterStatus) ?>';
let currentAppointmentId = null;

function loadList() {
    const formData = new FormData();
    formData.append('action', 'get_list');
    formData.append('date', currentDate);
    formData.append('status', currentStatus);

    // Use the current page path without query parameters
    const url = window.location.pathname;
    
    fetch(url, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;
            document.getElementById('apptList').innerHTML = data.html;
            document.getElementById('dateLabel').textContent = data.label;

            const today = new Date().toISOString().slice(0, 10);
            document.getElementById('dateSubLabel').textContent = (currentDate === today) ? "Today's schedule" : 'Schedule for this date';

            document.getElementById('statTotal').textContent = data.stats.total;
            document.getElementById('statPending').textContent = data.stats.pending;
            document.getElementById('statApproved').textContent = data.stats.approved;
            document.getElementById('statConfirmed').textContent = data.stats.confirmed;
            document.getElementById('statInProgress').textContent = data.stats.in_progress;
            document.getElementById('statCompleted').textContent = data.stats.completed;

            // Update URL without reloading the page
            const urlParams = new URLSearchParams(window.location.search);
            urlParams.set('date', currentDate);
            urlParams.set('status', currentStatus);
            const newUrl = window.location.pathname + '?' + urlParams.toString();
            window.history.replaceState({}, '', newUrl);
        })
        .catch(error => {
            console.error('Error loading appointments:', error);
        });
}

// Date navigation - FIXED to ensure date input updates
document.getElementById('dateInput').addEventListener('change', function() {
    currentDate = this.value;
    loadList();
});

// document.getElementById('prevDayBtn').addEventListener('click', function() {
//     const d = new Date(currentDate + 'T00:00:00');
//     d.setDate(d.getDate() - 1);
//     currentDate = d.toISOString().slice(0, 10);
//     document.getElementById('dateInput').value = currentDate;
//     loadList();
// });

// document.getElementById('nextDayBtn').addEventListener('click', function() {
//     const d = new Date(currentDate + 'T00:00:00');
//     d.setDate(d.getDate() + 1);
//     currentDate = d.toISOString().slice(0, 10);
//     document.getElementById('dateInput').value = currentDate;
//     loadList();
// });

document.getElementById('todayBtn').addEventListener('click', function() {
    const today = new Date().toISOString().slice(0, 10);
    currentDate = today;
    document.getElementById('dateInput').value = today;
    loadList();
});

// Status tabs
document.querySelectorAll('.status-tab').forEach(tab => {
    tab.addEventListener('click', function() {
        document.querySelectorAll('.status-tab').forEach(t => t.classList.remove('active'));
        this.classList.add('active');
        currentStatus = this.dataset.status;
        loadList();
    });
});

// Update status - FIXED URL
function updateStatus(id, status) {
    const labels = { 
        approved: 'approve', 
        confirmed: 'confirm', 
        in_progress: 'start treatment for', 
        completed: 'mark complete', 
        cancelled: 'cancel' 
    };
    const verb = labels[status] || 'update';

    Swal.fire({
        title: `Are you sure you want to ${verb} this appointment?`,
        icon: status === 'cancelled' ? 'warning' : 'question',
        showCancelButton: true,
        confirmButtonColor: status === 'cancelled' ? '#dc3545' : '#0d9e8c',
        cancelButtonColor: '#64748b',
        confirmButtonText: `Yes, ${verb}`,
        cancelButtonText: 'Back',
        reverseButtons: true
    }).then(result => {
        if (!result.isConfirmed) return;

        const formData = new FormData();
        formData.append('action', 'update_status');
        formData.append('id', id);
        formData.append('status', status);

        const url = window.location.pathname;
        fetch(url, { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    loadList();
                    Swal.fire({ 
                        icon: 'success', 
                        title: 'Updated', 
                        text: data.message, 
                        timer: 1400, 
                        showConfirmButton: false 
                    });
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: data.message });
                }
            });
    });
}

// View appointment details - FIXED URL
function viewAppointment(id) {
    currentAppointmentId = id;
    const formData = new FormData();
    formData.append('action', 'get_details');
    formData.append('id', id);

    const url = window.location.pathname;
    fetch(url, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                Swal.fire({ icon: 'error', title: 'Error', text: data.message });
                return;
            }
            renderDetailModal(data.appointment, data.dentists);
            document.getElementById('detailModal').classList.add('open');
        });
}

function goToPatientRecord(event, id) {
    window.location.href = 'patient-record.php?id=' + id;
}

function renderDetailModal(a, dentists) {
    document.getElementById('detailModalTitle').textContent = a.full_name || 'Appointment Details';

    const address = [a.street_address, a.barangay, a.city, a.province, a.region].filter(Boolean).join(', ');

    let servicesHtml = '';
    if (a.service_details && a.service_details.length) {
        a.service_details.forEach(s => {
            servicesHtml += `<div class="service-line"><span>${s.title}</span><span>₱${parseFloat(s.price).toFixed(2)}</span></div>`;
        });
        servicesHtml += `<div class="service-total"><span>Total</span><span>₱${parseFloat(a.total_price).toFixed(2)}</span></div>`;
    } else {
        servicesHtml = '<p class="text-xs text-gray-400">No services specified</p>';
    }

    let dentistOptions = '<option value="">— Unassigned —</option>';
    dentists.forEach(d => {
        const selected = (a.dentist_id == d.id) ? 'selected' : '';
        dentistOptions += `<option value="${d.id}" ${selected}>Dr. ${d.first_name} ${d.last_name}${d.specialization ? ' — ' + d.specialization : ''}</option>`;
    });

    document.getElementById('detailModalBody').innerHTML = `
        <div class="detail-section">
            <div class="detail-label">Patient Info</div>
            <div class="detail-grid">
                <div class="detail-item"><span>Full Name</span>${a.full_name || '—'}</div>
                <div class="detail-item"><span>Age</span>${a.age || '—'}</div>
                <div class="detail-item"><span>Phone</span>${a.phone || '—'}</div>
                <div class="detail-item"><span>Email</span>${a.email || '—'}</div>
                <div class="detail-item"><span>Occupation</span>${a.occupation || '—'}</div>
                <div class="detail-item"><span>Status</span><span class="status-badge ${a.status_meta.class}">${a.status_meta.label}</span></div>
            </div>
        </div>

        <div class="detail-section">
            <div class="detail-label">Address</div>
            <p class="text-sm" style="color:var(--navy);">${address || '—'}</p>
        </div>

        <div class="detail-section">
            <div class="detail-label">Appointment</div>
            <div class="detail-grid">
                <div class="detail-item"><span>Date</span>${a.appointment_date || '—'}</div>
                <div class="detail-item"><span>Time</span>${a.appointment_time || '—'}</div>
            </div>
        </div>

        <div class="detail-section">
            <div class="detail-label">Services</div>
            ${servicesHtml}
        </div>

        <div class="detail-section">
            <div class="detail-label">Assigned Dentist</div>
            <select class="dentist-select" id="detailDentistSelect">${dentistOptions}</select>
        </div>

        ${a.notes ? `
        <div class="detail-section">
            <div class="detail-label">Patient Notes</div>
            <p class="text-sm" style="color:var(--navy);">${a.notes}</p>
        </div>` : ''}

        <div class="detail-section">
            <div class="detail-label">Internal Notes</div>
            <textarea class="notes-textarea" id="detailInternalNotes" placeholder="Add internal notes for staff...">${a.internal_notes || ''}</textarea>
        </div>
    `;
}

// Save detail changes - FIXED URL
function saveDetailChanges() {
    if (!currentAppointmentId) return;
    const dentistId = document.getElementById('detailDentistSelect').value;
    const notes = document.getElementById('detailInternalNotes').value;

    Swal.fire({ title: 'Saving...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

    const url = window.location.pathname;
    
    const assignForm = new FormData();
    assignForm.append('action', 'assign_dentist');
    assignForm.append('id', currentAppointmentId);
    assignForm.append('dentist_id', dentistId);

    const notesForm = new FormData();
    notesForm.append('action', 'save_internal_notes');
    notesForm.append('id', currentAppointmentId);
    notesForm.append('internal_notes', notes);

    Promise.all([
        fetch(url, { method: 'POST', body: assignForm }).then(r => r.json()),
        fetch(url, { method: 'POST', body: notesForm }).then(r => r.json())
    ]).then(([assignRes, notesRes]) => {
        Swal.close();
        if (assignRes.success && notesRes.success) {
            closeDetailModal();
            loadList();
            Swal.fire({ 
                icon: 'success', 
                title: 'Saved', 
                text: 'Appointment updated.', 
                timer: 1400, 
                showConfirmButton: false 
            });
        } else {
            Swal.fire({ 
                icon: 'error', 
                title: 'Error', 
                text: (assignRes.message || '') + ' ' + (notesRes.message || '') 
            });
        }
    }).catch(err => {
        Swal.close();
        Swal.fire({ icon: 'error', title: 'Request failed', text: err.message });
    });
}

function closeDetailModal() {
    document.getElementById('detailModal').classList.remove('open');
    currentAppointmentId = null;
}

document.getElementById('detailModal').addEventListener('click', function(e) {
    if (e.target === this) closeDetailModal();
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeDetailModal();
});

// Initialize - Load today's appointments
// Ensure the date input matches the current date
const todayStr = new Date().toISOString().slice(0, 10);
if (currentDate !== document.getElementById('dateInput').value) {
    currentDate = document.getElementById('dateInput').value || todayStr;
}

// Load the appointments for the current date
loadList();
</script>

</body>
</html>