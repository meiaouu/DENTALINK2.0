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

$staffName = $_SESSION['staff_name'] ?? $_SESSION['user_name'] ?? 'Staff';

$today      = date('Y-m-d');
$monthStart = date('Y-m-01');
$weekStart  = date('Y-m-d', strtotime('monday this week'));
$weekEnd    = date('Y-m-d', strtotime('sunday this week'));

/* ---------- Today's appointments ---------- */
$stmt = $conn->prepare("
    SELECT a.id, a.full_name, a.tracking_token, a.appointment_time, a.status, a.services,
           d.first_name AS dentist_first_name, d.last_name AS dentist_last_name
    FROM appointments a
    LEFT JOIN dentists d ON a.dentist_id = d.id
    WHERE a.appointment_date = ?
    ORDER BY a.appointment_time ASC
");
$stmt->bind_param('s', $today);
$stmt->execute();
$todaysAppointments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$countByStatus = ['pending' => 0, 'approved' => 0, 'confirmed' => 0, 'in_progress' => 0, 'completed' => 0, 'cancelled' => 0, 'rejected' => 0];
foreach ($todaysAppointments as $a) {
    if (isset($countByStatus[$a['status']])) $countByStatus[$a['status']]++;
}
$todayTotal    = count($todaysAppointments);
$waitingToday  = $countByStatus['approved'] + $countByStatus['confirmed'];
$inProgress    = $countByStatus['in_progress'];
$completedToday = $countByStatus['completed'];
$pendingToday  = $countByStatus['pending'];

/* ---------- Weekly / monthly appointment volume ---------- */
$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM appointments WHERE appointment_date BETWEEN ? AND ?");
$stmt->bind_param('ss', $weekStart, $weekEnd);
$stmt->execute();
$weekTotal = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM appointments WHERE appointment_date >= ?");
$stmt->bind_param('s', $monthStart);
$stmt->execute();
$monthTotal = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM appointments WHERE status = 'pending'");
$stmt->execute();
$pendingAllTime = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

/* ---------- Patients ---------- */
$totalPatients = 0; $activePatients = 0; $newThisMonth = 0;
if ($res = $conn->query("SELECT COUNT(*) AS c FROM patients")) {
    $totalPatients = (int)($res->fetch_assoc()['c'] ?? 0);
}
if ($res = $conn->query("SELECT COUNT(*) AS c FROM patients WHERE status = 'active'")) {
    $activePatients = (int)($res->fetch_assoc()['c'] ?? 0);
}
$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM patients WHERE created_at >= ?");
$stmt->bind_param('s', $monthStart);
$stmt->execute();
$newThisMonth = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

$recentPatients = [];
if ($res = $conn->query("SELECT id, patient_id, full_name, phone, email, status, created_at FROM patients ORDER BY created_at DESC LIMIT 5")) {
    $recentPatients = $res->fetch_all(MYSQLI_ASSOC);
}

/* ---------- Services / treatment plans ---------- */
$activePlans = 0; $completedPlans = 0; $totalServices = 0;
if ($res = $conn->query("SELECT COUNT(*) AS c FROM patient_services WHERE status != 'completed'")) {
    $activePlans = (int)($res->fetch_assoc()['c'] ?? 0);
}
if ($res = $conn->query("SELECT COUNT(*) AS c FROM patient_services WHERE status = 'completed'")) {
    $completedPlans = (int)($res->fetch_assoc()['c'] ?? 0);
}
if ($res = $conn->query("SELECT COUNT(*) AS c FROM services WHERE is_active = 1")) {
    $totalServices = (int)($res->fetch_assoc()['c'] ?? 0);
}

/* ---------- Dentists on roster ---------- */
$dentists = [];
if ($res = $conn->query("SELECT id, first_name, last_name, specialization FROM dentists WHERE is_active = 1 ORDER BY last_name ASC")) {
    $dentists = $res->fetch_all(MYSQLI_ASSOC);
}
$dentistApptCount = [];
foreach ($todaysAppointments as $a) {
    // not directly available (no dentist_id in select above), computed via separate query below instead
}
$stmt = $conn->prepare("
    SELECT d.id, COUNT(*) AS c
    FROM appointments a JOIN dentists d ON a.dentist_id = d.id
    WHERE a.appointment_date = ? AND a.status NOT IN ('cancelled','rejected')
    GROUP BY d.id
");
$stmt->bind_param('s', $today);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $dentistApptCount[$row['id']] = (int)$row['c'];
$stmt->close();

/* ---------- Chat activity (guest chat used on staff-chat.php) ---------- */
$openChats = 0; $unreadChats = 0;
if ($res = $conn->query("SELECT COUNT(*) AS c FROM chat_sessions WHERE status != 'closed'")) {
    $openChats = (int)($res->fetch_assoc()['c'] ?? 0);
}
if ($res = $conn->query("SELECT COUNT(*) AS c FROM chat_messages WHERE sender = 'guest' AND is_read = 0")) {
    $unreadChats = (int)($res->fetch_assoc()['c'] ?? 0);
}
$recentChats = [];
if ($res = $conn->query("
    SELECT s.guest_id, s.status, s.last_message_at,
           (SELECT message FROM chat_messages m WHERE m.guest_id = s.guest_id ORDER BY m.id DESC LIMIT 1) AS last_message,
           (SELECT COUNT(*) FROM chat_messages m WHERE m.guest_id = s.guest_id AND m.sender = 'guest' AND m.is_read = 0) AS unread
    FROM chat_sessions s
    ORDER BY s.last_message_at DESC
    LIMIT 5
")) {
    $recentChats = $res->fetch_all(MYSQLI_ASSOC);
}

function patientLabel($appt) {
    $name = trim($appt['full_name'] ?? '');
    if ($name !== '') return $name;
    $token = trim($appt['tracking_token'] ?? '');
    if ($token !== '') return 'Track #' . strtoupper(substr($token, 0, 8));
    return 'Track #' . str_pad($appt['id'], 6, '0', STR_PAD_LEFT);
}
function dentistName($appt) {
    if (!empty($appt['dentist_first_name'])) {
        return 'Dr. ' . $appt['dentist_first_name'] . ' ' . $appt['dentist_last_name'];
    }
    return 'Unassigned';
}
function statusBadge($status) {
    $map = [
        'pending'     => ['#f59e0b', '#fffaf0', 'Pending'],
        'approved'    => ['#0d9e8c', '#e6f7f5', 'Approved'],
        'confirmed'   => ['#0d9e8c', '#e6f7f5', 'Confirmed'],
        'in_progress' => ['#2563eb', '#eaf1fe', 'In Progress'],
        'completed'   => ['#16a34a', '#eafaf0', 'Completed'],
        'cancelled'   => ['#dc2626', '#fdeeee', 'Cancelled'],
        'rejected'    => ['#dc2626', '#fdeeee', 'Rejected'],
    ];
    [$fg, $bg, $label] = $map[$status] ?? ['#6b7280', '#f3f4f6', ucfirst($status)];
    return "<span class=\"badge-pill\" style=\"color:{$fg};background:{$bg};\">{$label}</span>";
}

include 'ssidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard — Wansi Calacat Dental Clinic</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap');
   @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700;800;900&family=DM+Sans:wght@400;500;600;700;800&display=swap');
        * { font-family: 'DM Sans', system-ui, sans-serif; }
        h1, h2, h3, h4, .logo-name { font-family: 'Playfair Display', Georgia, serif; }
html, body { margin: 0; background: #f7f9fb; color: #111827; }
body {
    background-color: #f8fafc;
    background-image:
        linear-gradient(rgba(11,22,40,0.04) 1px, transparent 1px),
        linear-gradient(90deg, rgba(11,22,40,0.04) 1px, transparent 1px);
    background-size: 32px 32px;
    background-position: -1px -1px;
}
.main-content { margin-left: 270px; min-height: 100vh; }
@media (max-width: 1023px) { .main-content { margin-left: 0; } }

.page { max-width: 1320px; margin: 0 auto; padding: 28px 32px 56px; }

.topbar {
    display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; flex-wrap: wrap; gap: 12px;
}
.topbar h1 { font-size: 22px; font-weight: 800; margin: 0; color: #0b1628; }
.topbar .sub { font-size: 13px; color: #6b7280; font-weight: 500; margin-top: 2px; }
.topbar .date-pill {
    background: white; border: 1px solid #e5e7eb; border-radius: 10px; padding: 9px 16px;
    font-size: 13px; font-weight: 700; color: #111827; display: flex; align-items: center; gap: 8px;
}

.stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 24px; }
.stat-card {
    background: white; border: 1px solid #eef1f6; border-left: 4px solid #0d9e8c;
    border-radius: 12px; padding: 18px 20px; display: flex; flex-direction: column; gap: 2px;
    transition: transform .15s, box-shadow .15s;
}
.stat-card:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(13,158,140,.10); }
.stat-card.alt1 { border-left-color: #2563eb; }
.stat-card.alt2 { border-left-color: #f59e0b; }
.stat-card.alt3 { border-left-color: #7c3aed; }
.stat-card .top-row { display: flex; align-items: center; justify-content: space-between; }
.stat-card .icon-box {
    width: 34px; height: 34px; border-radius: 9px; background: #e6f7f5; color: #0d9e8c;
    display: flex; align-items: center; justify-content: center; font-size: 14px;
}
.stat-card.alt1 .icon-box { background: #eaf1fe; color: #2563eb; }
.stat-card.alt2 .icon-box { background: #fff7e6; color: #f59e0b; }
.stat-card.alt3 .icon-box { background: #f3ecfe; color: #7c3aed; }
.stat-card .lbl { font-size: 11px; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: .4px; }
.stat-card .num { font-size: 26px; font-weight: 800; color: #0b1628; margin: 2px 0 1px; }
.stat-card .sub { font-size: 12px; color: #9aa4b2; }

.grid-2col { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; align-items: start; }
@media (max-width: 1100px) { .grid-2col { grid-template-columns: 1fr; } }

.panel { background: white; border: 1px solid #eef1f6; border-radius: 14px; padding: 22px 22px 18px; margin-bottom: 20px; }
.panel-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
.panel-head h2 { font-size: 15.5px; font-weight: 800; margin: 0; color: #0b1628; display: flex; align-items: center; gap: 8px; }
.panel-head h2 i { color: #0d9e8c; font-size: 14px; }
.panel-head a.view-all { font-size: 12.5px; font-weight: 700; color: #0d9e8c; text-decoration: none; }
.panel-head a.view-all:hover { text-decoration: underline; }

.appt-table { width: 100%; border-collapse: collapse; }
.appt-table th {
    text-align: left; font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .4px;
    color: #9aa4b2; padding: 0 8px 10px; border-bottom: 1px solid #f1f5f9;
}
.appt-table td { padding: 11px 8px; font-size: 13.5px; border-bottom: 1px solid #f8fafc; color: #111827; vertical-align: middle; }
.appt-table tr:last-child td { border-bottom: none; }
.appt-table .p-name { font-weight: 700; }
.appt-table .p-sub { font-size: 11.5px; color: #9aa4b2; }
.appt-table .p-time { font-variant-numeric: tabular-nums; font-weight: 700; font-size: 13px; }

.badge-pill { font-size: 11px; font-weight: 700; padding: 4px 10px; border-radius: 20px; white-space: nowrap; }

.empty-state { text-align: center; padding: 30px 10px; color: #9aa4b2; font-size: 13.5px; }
.empty-state i { font-size: 26px; margin-bottom: 8px; display: block; color: #d1d9e2; }

.side-list { display: flex; flex-direction: column; gap: 2px; }
.side-item { display: flex; align-items: center; gap: 12px; padding: 10px 4px; border-bottom: 1px solid #f8fafc; }
.side-item:last-child { border-bottom: none; }
.side-avatar {
    width: 36px; height: 36px; border-radius: 50%; background: #e6f7f5; color: #0d9e8c;
    display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 13px; flex-shrink: 0;
}
.side-name { font-size: 13px; font-weight: 700; color: #0b1628; }
.side-meta { font-size: 11.5px; color: #9aa4b2; margin-top: 1px; }
.side-right { margin-left: auto; text-align: right; }

.chat-badge { background: #e53e3e; color: white; font-size: 10px; font-weight: 800; border-radius: 20px; padding: 1px 7px; }

.quick-actions { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
@media (max-width: 700px) { .quick-actions { grid-template-columns: repeat(2, 1fr); } }
.qa-btn {
    display: flex; flex-direction: column; align-items: flex-start; gap: 10px;
    padding: 16px; border-radius: 12px; border: 1px solid #eef1f6; background: #fafbfc;
    text-decoration: none; color: #0b1628; transition: .15s;
}
.qa-btn:hover { background: white; border-color: #0d9e8c; box-shadow: 0 6px 18px rgba(13,158,140,.12); transform: translateY(-1px); }
.qa-btn .qa-icon {
    width: 36px; height: 36px; border-radius: 10px; background: #0d9e8c; color: white;
    display: flex; align-items: center; justify-content: center; font-size: 15px;
}
.qa-btn .qa-title { font-size: 13.5px; font-weight: 700; }
.qa-btn .qa-sub { font-size: 11.5px; color: #9aa4b2; }

.dentist-row { display: flex; align-items: center; justify-content: space-between; padding: 9px 4px; border-bottom: 1px solid #f8fafc; font-size: 13px; }
.dentist-row:last-child { border-bottom: none; }
.dentist-row .d-name { font-weight: 700; color: #0b1628; }
.dentist-row .d-spec { font-size: 11.5px; color: #9aa4b2; }
.dentist-row .d-count { font-size: 12px; font-weight: 800; color: #0d9e8c; background: #e6f7f5; padding: 3px 10px; border-radius: 20px; }
</style>
</head>
<body>

<div class="main-content">
<div class="page">

    <div class="topbar">
        <div>
            <h1>Welcome back, <?= h($staffName) ?> 👋</h1>
            <div class="sub">Here's what's happening at the clinic today.</div>
        </div>
        <div class="date-pill"><i class="fas fa-calendar-day" style="color:#0d9e8c;"></i> <?= h(date('l, F j, Y')) ?></div>
    </div>

    <div class="stats-row">
        <div class="stat-card">
            <div class="top-row"><div class="icon-box"><i class="fas fa-calendar-check"></i></div></div>
            <div class="lbl">Today's Appointments</div>
            <div class="num"><?= $todayTotal ?></div>
            <div class="sub"><?= $waitingToday ?> waiting &middot; <?= $inProgress ?> in progress</div>
        </div>
        <div class="stat-card alt1">
            <div class="top-row"><div class="icon-box"><i class="fas fa-users"></i></div></div>
            <div class="lbl">Total Patients</div>
            <div class="num"><?= $totalPatients ?></div>
            <div class="sub"><?= $activePatients ?> active &middot; +<?= $newThisMonth ?> this month</div>
        </div>
        <div class="stat-card alt2">
            <div class="top-row"><div class="icon-box"><i class="fas fa-hourglass-half"></i></div></div>
            <div class="lbl">Pending Requests</div>
            <div class="num"><?= $pendingAllTime ?></div>
            <div class="sub"><?= $pendingToday ?> awaiting review today</div>
        </div>
        <div class="stat-card alt3">
            <div class="top-row"><div class="icon-box"><i class="fas fa-comment-dots"></i></div></div>
            <div class="lbl">Chat Activity</div>
            <div class="num"><?= $openChats ?></div>
            <div class="sub"><?= $unreadChats ?> unread message<?= $unreadChats === 1 ? '' : 's' ?></div>
        </div>
    </div>

    <div class="grid-2col">
        <div>
            <div class="panel">
                <div class="panel-head">
                    <h2><i class="fas fa-calendar-check"></i> Today's Schedule</h2>
                    <a class="view-all" href="today.php">View all <i class="fas fa-arrow-right" style="font-size:10px;"></i></a>
                </div>
                <?php if ($todaysAppointments): ?>
                    <table class="appt-table">
                        <thead>
                            <tr><th>Time</th><th>Patient</th><th>Dentist</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach (array_slice($todaysAppointments, 0, 8) as $a): ?>
                            <tr>
                                <td class="p-time"><?= h($a['appointment_time'] ? date('g:i A', strtotime($a['appointment_time'])) : '—') ?></td>
                                <td>
                                    <div class="p-name"><?= h(patientLabel($a)) ?></div>
                                    <div class="p-sub"><?= h($a['services'] ?? '') ?></div>
                                </td>
                                <td><?= h(dentistName($a)) ?></td>
                                <td><?= statusBadge($a['status']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state"><i class="fas fa-calendar-xmark"></i>No appointments scheduled for today.</div>
                <?php endif; ?>
            </div>

            <div class="panel">
                <div class="panel-head">
                    <h2><i class="fas fa-th-large"></i> Quick Actions</h2>
                </div>
                <div class="quick-actions">
                    <a class="qa-btn" href="appointments.php">
                        <div class="qa-icon"><i class="fas fa-calendar-plus"></i></div>
                        <div class="qa-title">Appointments</div>
                        <div class="qa-sub">Manage all bookings</div>
                    </a>
                    <a class="qa-btn" href="patients.php">
                        <div class="qa-icon"><i class="fas fa-user-injured"></i></div>
                        <div class="qa-title">Patients</div>
                        <div class="qa-sub">View patient records</div>
                    </a>
                    <a class="qa-btn" href="queue.php" target="_blank">
                        <div class="qa-icon"><i class="fas fa-list-ol"></i></div>
                        <div class="qa-title">Clinic Queue</div>
                        <div class="qa-sub">Open lobby display</div>
                    </a>
                    <a class="qa-btn" href="patient_services.php">
                        <div class="qa-icon"><i class="fas fa-tooth"></i></div>
                        <div class="qa-title">Services</div>
                        <div class="qa-sub"><?= $activePlans ?> active treatment plans</div>
                    </a>
                    <a class="qa-btn" href="patient-record-history.php">
                        <div class="qa-icon"><i class="fas fa-folder-open"></i></div>
                        <div class="qa-title">Patient Archives</div>
                        <div class="qa-sub">Browse past records</div>
                    </a>
                    <a class="qa-btn" href="chat.php">
                        <div class="qa-icon"><i class="fas fa-comment-dots"></i></div>
                        <div class="qa-title">Chat</div>
                        <div class="qa-sub"><?= $unreadChats ?> unread</div>
                    </a>
                </div>
            </div>
        </div>

        <div>
            <div class="panel">
                <div class="panel-head">
                    <h2><i class="fas fa-user-plus"></i> Recent Patients</h2>
                    <a class="view-all" href="patients.php">View all</a>
                </div>
                <?php if ($recentPatients): ?>
                    <div class="side-list">
                        <?php foreach ($recentPatients as $p): ?>
                            <?php
                                $initials = '';
                                foreach (explode(' ', trim($p['full_name'] ?? '')) as $part) {
                                    if ($part !== '') $initials .= strtoupper($part[0]);
                                    if (strlen($initials) >= 2) break;
                                }
                            ?>
                            <div class="side-item">
                                <div class="side-avatar"><?= h($initials ?: '?') ?></div>
                                <div>
                                    <div class="side-name"><?= h($p['full_name'] ?: 'Unnamed') ?></div>
                                    <div class="side-meta"><?= h($p['phone'] ?: $p['email'] ?: 'No contact info') ?></div>
                                </div>
                                <div class="side-right">
                                    <?= statusBadge($p['status'] ?: 'active') ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state"><i class="fas fa-user-slash"></i>No patients yet.</div>
                <?php endif; ?>
            </div>

            <div class="panel">
                <div class="panel-head">
                    <h2><i class="fas fa-comment-dots"></i> Recent Chats</h2>
                    <a class="view-all" href="chat.php">Open chat</a>
                </div>
                <?php if ($recentChats): ?>
                    <div class="side-list">
                        <?php foreach ($recentChats as $c): ?>
                            <div class="side-item">
                                <div class="side-avatar"><i class="fas fa-user"></i></div>
                                <div style="min-width:0;">
                                    <div class="side-name"><?= h(str_replace('guest_', 'Guest ', substr($c['guest_id'], 0, 18))) ?></div>
                                    <div class="side-meta" style="max-width:170px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                        <?= h($c['last_message'] ?: 'No messages yet') ?>
                                    </div>
                                </div>
                                <div class="side-right">
                                    <?php if ((int)$c['unread'] > 0): ?>
                                        <span class="chat-badge"><?= (int)$c['unread'] ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state"><i class="fas fa-comment-slash"></i>No conversations yet.</div>
                <?php endif; ?>
            </div>

            <div class="panel">
                <div class="panel-head">
                    <h2><i class="fas fa-user-md"></i> Dentists On Roster</h2>
                </div>
                <?php if ($dentists): ?>
                    <?php foreach ($dentists as $d): ?>
                        <div class="dentist-row">
                            <div>
                                <div class="d-name">Dr. <?= h($d['first_name'] . ' ' . $d['last_name']) ?></div>
                                <div class="d-spec"><?= h($d['specialization'] ?: 'General Dentistry') ?></div>
                            </div>
                            <div class="d-count"><?= (int)($dentistApptCount[$d['id']] ?? 0) ?> today</div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state"><i class="fas fa-user-md"></i>No active dentists on file.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>
</div>

</body>
</html>