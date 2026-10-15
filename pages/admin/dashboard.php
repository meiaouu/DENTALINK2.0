<?php
require '../../conn/conn.php';
date_default_timezone_set('Asia/Manila');

function h($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
function money($v) { return '₱' . number_format((float)$v, 2); }

/* ---------------------------------------------------------------------
   Date ranges used throughout the stat cards
--------------------------------------------------------------------- */
$today          = date('Y-m-d');
$monthStart     = date('Y-m-01');
$monthEnd       = date('Y-m-t');
$prevMonthStart = date('Y-m-01', strtotime('-1 month'));
$prevMonthEnd   = date('Y-m-t', strtotime('-1 month'));

/* ---------------------------------------------------------------------
   Stat: appointments booked this month vs last month
--------------------------------------------------------------------- */
$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM appointments WHERE appointment_date BETWEEN ? AND ?");
$stmt->bind_param('ss', $monthStart, $monthEnd);
$stmt->execute();
$totalThisMonth = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM appointments WHERE appointment_date BETWEEN ? AND ?");
$stmt->bind_param('ss', $prevMonthStart, $prevMonthEnd);
$stmt->execute();
$totalLastMonth = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

$apptTrend = $totalLastMonth > 0
    ? round((($totalThisMonth - $totalLastMonth) / $totalLastMonth) * 100)
    : ($totalThisMonth > 0 ? 100 : 0);

/* ---------------------------------------------------------------------
   Stat: status breakdown this month (confirmed / pending)
--------------------------------------------------------------------- */
$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM appointments WHERE appointment_date BETWEEN ? AND ? AND status IN ('confirmed','approved','in_progress','completed')");
$stmt->bind_param('ss', $monthStart, $monthEnd);
$stmt->execute();
$confirmedThisMonth = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM appointments WHERE status = 'pending'");
$stmt->execute();
$pendingTotal = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

$confirmedPct = $totalThisMonth > 0 ? round(($confirmedThisMonth / $totalThisMonth) * 100, 1) : 0;

/* ---------------------------------------------------------------------
   Stat: revenue this month vs last month, from finalized patient records
--------------------------------------------------------------------- */
$revenueThisMonth = 0.0;
$revenueLastMonth = 0.0;
$revenueColumn    = null;

// The exact money column on patient_record_archive isn't guaranteed (it may
// not be a plain numeric column at all - e.g. it could live inside a JSON
// snapshot). Detect it from INFORMATION_SCHEMA instead of assuming a name,
// and skip the revenue stat entirely rather than crashing if none match.
if ($conn->query("SHOW TABLES LIKE 'patient_record_archive'")->num_rows > 0) {
    $candidates = ['total_price', 'grand_total', 'total_amount', 'amount_paid', 'amount', 'price_total', 'total'];
    $colCheck = $conn->prepare("
        SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'patient_record_archive'
          AND COLUMN_NAME IN (" . implode(',', array_fill(0, count($candidates), '?')) . ")
    ");
    $colCheck->bind_param(str_repeat('s', count($candidates)), ...$candidates);
    $colCheck->execute();
    $foundCols = array_column($colCheck->get_result()->fetch_all(MYSQLI_ASSOC), 'COLUMN_NAME');
    $colCheck->close();
    foreach ($candidates as $cand) {
        if (in_array($cand, $foundCols, true)) { $revenueColumn = $cand; break; }
    }
}

if ($revenueColumn) {
    $stmt = $conn->prepare("SELECT COALESCE(SUM(`$revenueColumn`),0) AS r FROM patient_record_archive WHERE DATE(created_at) BETWEEN ? AND ?");
    $stmt->bind_param('ss', $monthStart, $monthEnd);
    $stmt->execute();
    $revenueThisMonth = (float)($stmt->get_result()->fetch_assoc()['r'] ?? 0);
    $stmt->close();

    $stmt = $conn->prepare("SELECT COALESCE(SUM(`$revenueColumn`),0) AS r FROM patient_record_archive WHERE DATE(created_at) BETWEEN ? AND ?");
    $stmt->bind_param('ss', $prevMonthStart, $prevMonthEnd);
    $stmt->execute();
    $revenueLastMonth = (float)($stmt->get_result()->fetch_assoc()['r'] ?? 0);
    $stmt->close();
}
$revenueTrend = $revenueLastMonth > 0
    ? round((($revenueThisMonth - $revenueLastMonth) / $revenueLastMonth) * 100)
    : ($revenueThisMonth > 0 ? 100 : 0);

/* ---------------------------------------------------------------------
   Stat: active patients, plus how many were added in the last 7 days
--------------------------------------------------------------------- */
$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM patients WHERE status = 'active'");
$stmt->execute();
$activePatients = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM patients WHERE created_at >= (NOW() - INTERVAL 7 DAY)");
$stmt->execute();
$newPatientsWeek = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

/* ---------------------------------------------------------------------
   Today's schedule (mirrors the logic used on the Queue board)
--------------------------------------------------------------------- */
$stmt = $conn->prepare("
    SELECT a.id, a.full_name, a.appointment_time, a.status, a.services,
           d.first_name AS dentist_first_name, d.last_name AS dentist_last_name
    FROM appointments a
    LEFT JOIN dentists d ON a.dentist_id = d.id
    WHERE a.appointment_date = ?
    ORDER BY a.appointment_time ASC
    LIMIT 6
");
$stmt->bind_param('s', $today);
$stmt->execute();
$todaysAppointments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM appointments WHERE appointment_date = ?");
$stmt->bind_param('s', $today);
$stmt->execute();
$todayCount = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

/* Next upcoming appointment today, used for the clinic-status line */
$nowTime = date('H:i:s');
$stmt = $conn->prepare("
    SELECT a.full_name, a.appointment_time
    FROM appointments a
    WHERE a.appointment_date = ? AND a.appointment_time >= ?
      AND a.status IN ('approved','confirmed')
    ORDER BY a.appointment_time ASC LIMIT 1
");
$stmt->bind_param('ss', $today, $nowTime);
$stmt->execute();
$nextAppt = $stmt->get_result()->fetch_assoc();
$stmt->close();

/* ---------------------------------------------------------------------
   Active services (top 5 by sort order)
--------------------------------------------------------------------- */
$services = [];
if ($conn->query("SHOW TABLES LIKE 'services'")->num_rows > 0) {
    $res = $conn->query("SELECT title, price, duration, icon FROM services WHERE is_active = 1 ORDER BY sort_order ASC, title ASC LIMIT 5");
    $services = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

/* ---------------------------------------------------------------------
   Gallery preview (top 4)
--------------------------------------------------------------------- */
$gallery = [];
if ($conn->query("SHOW TABLES LIKE 'gallery_items'")->num_rows > 0) {
    $res = $conn->query("SELECT image, tag FROM gallery_items WHERE is_active = 1 ORDER BY sort_order ASC LIMIT 4");
    $gallery = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}
$galleryCount = 0;
if ($conn->query("SHOW TABLES LIKE 'gallery_items'")->num_rows > 0) {
    $galleryCount = (int)$conn->query("SELECT COUNT(*) AS c FROM gallery_items WHERE is_active = 1")->fetch_assoc()['c'];
}

/* ---------------------------------------------------------------------
   Clinic settings (hours, name) for the status widget
--------------------------------------------------------------------- */
$clinicName  = 'Wansi Calacat Dental Clinic';
$clinicHours = '9:00 AM – 6:00 PM';
if ($conn->query("SHOW TABLES LIKE 'clinic_settings'")->num_rows > 0) {
    $settings = $conn->query("SELECT clinic_name, clinic_hours FROM clinic_settings WHERE id = 1")->fetch_assoc();
    if ($settings) {
        $clinicName  = $settings['clinic_name']  ?: $clinicName;
        $clinicHours = $settings['clinic_hours'] ?: $clinicHours;
    }
}

function statusClass($status) {
    switch ($status) {
        case 'confirmed':   return 'status-confirmed';
        case 'approved':    return 'status-approved';
        case 'pending':     return 'status-pending';
        case 'cancelled':   return 'status-cancelled';
        case 'in_progress': return 'status-inprogress';
        case 'completed':   return 'status-completed';
        default:            return 'status-pending';
    }
}
function statusLabel($status) {
    return ucwords(str_replace('_', ' ', $status ?? 'pending'));
}
function serviceNames($raw) {
    $ids = json_decode($raw ?? '', true);
    if (!is_array($ids)) return trim((string)$raw) !== '' ? (string)$raw : '—';
    return implode(', ', array_map(fn($v) => is_string($v) ? $v : (string)$v, $ids));
}

include 'sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard · <?= h($clinicName) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700;800;900&family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
* { font-family: 'DM Sans', system-ui, sans-serif; box-sizing: border-box; }
body {
  margin: 0;
  background: #f8fafc;
  background-image: linear-gradient(rgba(11,22,40,0.04) 1px, transparent 1px),
                    linear-gradient(90deg, rgba(11,22,40,0.04) 1px, transparent 1px);
  background-size: 32px 32px;
  background-position: -1px -1px;
  color: #111827;
}
h1, h2, h3, h4, .logo-name { font-family: 'Playfair Display', Georgia, serif; }
:root {
  --navy: #0b1628;
  --teal: #0d9e8c;
  --teal-dark: #0a7d6e;
  --teal-light: #e6f7f5;
  --slate: #64748b;
  --shadow-card: 0 2px 8px rgba(11,22,40,.06), 0 12px 32px rgba(11,22,40,.08);
  --line: #eef1f6;
}
a { text-decoration: none; }
.text-teal { color: var(--teal); }

@media (min-width: 1024px) { .main-content { margin-left: 270px; } }
@media (max-width: 1023px) { .main-content { margin-left: 0; } }

.dashboard-wrapper { max-width: 1360px; margin: 0 auto; }

/* top bar */
.top-bar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 28px; }
.top-bar h1 { font-size: 26px; font-weight: 700; color: var(--navy); margin: 0; }
.top-bar .sub { font-size: 14px; color: var(--slate); margin-top: 2px; }
.top-bar-actions { display: flex; gap: 10px; flex-wrap: wrap; }
.btn-primary {
  background: var(--teal); color: #fff; padding: 10px 22px; border-radius: 12px;
  font-weight: 600; font-size: 13px; border: none; cursor: pointer;
  display: inline-flex; align-items: center; gap: 8px;
  box-shadow: 0 4px 12px rgba(13,158,140,0.25); transition: all .2s;
}
.btn-primary:hover { background: var(--teal-dark); transform: translateY(-2px); box-shadow: 0 8px 22px rgba(13,158,140,0.35); }
.btn-outline {
  background: #fff; color: var(--slate); padding: 8px 18px; border-radius: 10px;
  border: 1.5px solid #e2e8f0; font-weight: 600; font-size: 13px; cursor: pointer;
  transition: all .2s; display: inline-flex; align-items: center; gap: 6px;
}
.btn-outline:hover { background: #f8fafc; border-color: #cbd5e1; }

/* stat cards */
.stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 18px; margin-bottom: 28px; }
.stat-card { background: #fff; border-radius: 18px; padding: 20px 18px; box-shadow: var(--shadow-card); border: 1px solid var(--line); transition: all .2s; }
.stat-card:hover { border-color: var(--teal); box-shadow: 0 8px 24px rgba(11,22,40,.08); }
.stat-card .label { font-size: 11px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: .4px; }
.stat-card .number { font-size: 28px; font-weight: 800; color: var(--navy); margin: 4px 0 2px; }
.stat-card .trend { font-size: 12px; font-weight: 600; color: var(--teal); }
.stat-card .trend.down { color: #e74c3c; }
.stat-card .trend.flat { color: var(--slate); }

/* main panels */
.panel-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 24px; margin-bottom: 24px; }
@media (max-width: 900px) { .panel-grid { grid-template-columns: 1fr; } }
.row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px; }
@media (max-width: 900px) { .row-2 { grid-template-columns: 1fr; } }

.card { background: #fff; border-radius: 20px; box-shadow: var(--shadow-card); border: 1px solid var(--line); overflow: hidden; }
.card-header { padding: 18px 24px; border-bottom: 1px solid var(--line); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; background: #fafbfc; }
.card-header h2 { font-size: 15px; font-weight: 700; color: var(--navy); display: flex; align-items: center; gap: 10px; margin: 0; }
.card-header h2 i { color: var(--teal); }
.card-header .badge-count { font-size: 11px; background: #f1f4f8; color: var(--slate); padding: 2px 12px; border-radius: 20px; font-weight: 600; }
.card-body { padding: 6px 0; }
.card-header a.manage-link { font-size: 12px; color: var(--teal); font-weight: 700; }
.card-header a.manage-link:hover { text-decoration: underline; }

/* appointment rows */
.appt-row { display: grid; grid-template-columns: 1.2fr 1.6fr 0.9fr 0.9fr; padding: 12px 24px; border-bottom: 1px solid #f1f4f8; font-size: 13px; align-items: center; }
.appt-row:hover { background: #fafbfc; }
.appt-row.head { font-weight: 600; color: var(--slate); font-size: 10.5px; text-transform: uppercase; letter-spacing: .4px; border-bottom: 2px solid #e9edf2; padding-bottom: 10px; }
.appt-row:last-child { border-bottom: none; }
.appt-name { font-weight: 600; color: var(--navy); }
.appt-meta { color: #9aa4b2; font-size: 12px; margin-top: 1px; }

.status-badge { display: inline-block; padding: 3px 12px; border-radius: 50px; font-size: 10.5px; font-weight: 700; text-align: center; min-width: 74px; }
.status-confirmed  { background: #d4edda; color: #155724; }
.status-approved   { background: #d1e7ff; color: #084298; }
.status-pending    { background: #fef3d7; color: #b45309; }
.status-cancelled  { background: #fee9e7; color: #b91c1c; }
.status-inprogress { background: #e6f7f5; color: #0a7d6e; }
.status-completed  { background: #e2e8f0; color: #334155; }

.empty-row { padding: 32px 24px; text-align: center; color: #9aa4b2; font-size: 13.5px; }
.empty-row i { display: block; font-size: 22px; margin-bottom: 8px; color: #d1d9e3; }

/* quick actions */
.quick-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; padding: 16px 20px 20px; }
.quick-item {
  display: flex; align-items: center; gap: 10px; padding: 12px 14px; border-radius: 12px;
  background: #fafbfc; border: 1px solid var(--line); color: var(--navy); font-size: 12.5px; font-weight: 600;
  transition: all .15s;
}
.quick-item i { color: var(--teal); width: 16px; text-align: center; }
.quick-item:hover { background: var(--teal-light); border-color: var(--teal); transform: translateY(-1px); }

.clinic-status { padding: 16px 20px 20px; border-top: 1px solid var(--line); }
.clinic-status .row { display: flex; align-items: center; gap: 8px; margin-top: 8px; font-size: 13.5px; color: var(--navy); }
.clinic-status .dot { width: 8px; height: 8px; border-radius: 50%; background: var(--teal); box-shadow: 0 0 0 4px var(--teal-light); }

/* services */
.service-item { display: flex; align-items: center; justify-content: space-between; padding: 12px 24px; border-bottom: 1px solid #f1f4f8; font-size: 13.5px; }
.service-item:last-child { border-bottom: none; }
.service-item .left { display: flex; align-items: center; gap: 10px; }
.service-item .left i { color: var(--teal); width: 16px; text-align: center; }
.service-item .price { font-weight: 700; color: var(--navy); }

/* gallery */
.gallery-preview { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; padding: 16px 20px 0; }
.thumb { aspect-ratio: 1; border-radius: 10px; overflow: hidden; background: var(--teal-light); display: flex; align-items: center; justify-content: center; color: var(--teal); font-size: 18px; }
.thumb img { width: 100%; height: 100%; object-fit: cover; }

.footer-note { text-align: center; font-size: 12px; color: #9aa4b2; margin-top: 8px; }

@media (max-width: 640px) {
  .appt-row { grid-template-columns: 1.2fr 1fr 0.8fr; }
  .appt-row span:nth-child(2) { display: none; }
  .appt-row.head span:nth-child(2) { display: none; }
  .stat-card .number { font-size: 22px; }
  .quick-grid { grid-template-columns: 1fr; }
  .top-bar h1 { font-size: 22px; }
}
</style>
</head>
<body>

<div class="main-content " style="padding: 28px 28px 48px;">
<div class="dashboard-wrapper ">

  <!-- TOP BAR -->
  <div class="top-bar">
    <div>
      <h1>Dashboard</h1>
      <div class="sub"><?= h($clinicName) ?> · <span id="greeting">Good morning</span></div>
    </div>
    <div class="top-bar-actions">
      <a href="appointments.php" class="btn-primary"><i class="fas fa-plus-circle"></i> New Appointment</a>
     </div>
  </div>

  <!-- STATS -->
  <div class="stat-grid">
    <div class="stat-card">
      <div class="label">Today's Appointments</div>
      <div class="number"><?= (int)$todayCount ?></div>
      <div class="trend flat"><i class="fas fa-calendar-day"></i> scheduled today</div>
    </div>
    <div class="stat-card">
      <div class="label">Appointments (this month)</div>
      <div class="number"><?= (int)$totalThisMonth ?></div>
      <div class="trend <?= $apptTrend < 0 ? 'down' : '' ?>">
        <i class="fas fa-arrow-<?= $apptTrend >= 0 ? 'up' : 'down' ?>"></i> <?= abs($apptTrend) ?>% vs last month
      </div>
    </div>
    <div class="stat-card">
      <div class="label">Confirmed</div>
      <div class="number"><?= (int)$confirmedThisMonth ?></div>
      <div class="trend flat"><?= h($confirmedPct) ?>% of this month</div>
    </div>
    <div class="stat-card">
      <div class="label">Pending Review</div>
      <div class="number"><?= (int)$pendingTotal ?></div>
      <div class="trend" style="color:#b45309;"><i class="fas fa-clock"></i> needs review</div>
    </div>
    
    <div class="stat-card">
      <div class="label">Active Patients</div>
      <div class="number"><?= (int)$activePatients ?></div>
      <div class="trend flat">+<?= (int)$newPatientsWeek ?> this week</div>
    </div>
  </div>

  <!-- MAIN PANELS: today's schedule + quick actions -->
  <div class="panel-grid">
    <div class="card">
      <div class="card-header">
        <h2><i class="fas fa-calendar-check"></i> Today's Schedule</h2>
        <a href="today.php" class="manage-link">View all →</a>
      </div>
      <div class="card-body">
        <div class="appt-row head">
          <span>Patient</span>
          <span>Service</span>
          <span>Time</span>
          <span>Status</span>
        </div>
        <?php if ($todaysAppointments): ?>
          <?php foreach ($todaysAppointments as $ap): ?>
            <div class="appt-row">
              <div>
                <div class="appt-name"><?= h($ap['full_name'] ?: 'Unnamed patient') ?></div>
                <div class="appt-meta">
                  <?= !empty($ap['dentist_first_name']) ? h('Dr. ' . $ap['dentist_first_name'] . ' ' . $ap['dentist_last_name']) : 'Unassigned' ?>
                </div>
              </div>
              <span><?= h(serviceNames($ap['services'])) ?></span>
              <span><?= h($ap['appointment_time'] ? date('g:i A', strtotime($ap['appointment_time'])) : '—') ?></span>
              <span><span class="status-badge <?= statusClass($ap['status']) ?>"><?= h(statusLabel($ap['status'])) ?></span></span>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="empty-row"><i class="fas fa-calendar-xmark"></i>No appointments scheduled for today.</div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Quick actions + clinic status -->
    <div class="card">
      <div class="card-header">
        <h2><i class="fas fa-bolt"></i> Quick Actions</h2>
      </div>
      <div class="quick-grid">
        <a href="patients.php" class="quick-item"><i class="fas fa-user-plus"></i> <span>Add Patient</span></a>
        <a href="appointments.php" class="quick-item"><i class="fas fa-calendar-plus"></i> <span>Book Appointment</span></a>
        <a href="manage_dentists.php" class="quick-item"><i class="fas fa-user-md"></i> <span>Dentists</span></a>
        <a href="availability.php" class="quick-item"><i class="fas fa-clock"></i> <span>Availability</span></a>
        <a href="queue.php" class="quick-item"><i class="fas fa-users-line"></i> <span>Clinic Queue</span></a>
        <a href="homeset.php" class="quick-item"><i class="fas fa-sliders-h"></i> <span>Site Settings</span></a>
      </div>
      <div class="clinic-status">
        <div style="font-size:11px; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:.4px;">Clinic Status</div>
        <div class="row">
          <span class="dot"></span>
          <span class="font-medium">Open · <?= h($clinicHours) ?></span>
        </div>
        <div style="font-size:12px; color:#94a3b8; margin-top:4px;">
          <?php if ($nextAppt): ?>
            Next: <?= h($nextAppt['full_name'] ?: 'Patient') ?> at <?= h(date('g:i A', strtotime($nextAppt['appointment_time']))) ?>
          <?php else: ?>
            No upcoming appointments left today.
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- BOTTOM ROW: Services + Gallery preview -->
  <div class="row-2">
    <div class="card">
      <div class="card-header">
        <h2><i class="fas fa-list-ul"></i> Active Services</h2>
        <a href="homeset.php" class="manage-link">Manage →</a>
      </div>
      <div>
        <?php if ($services): ?>
          <?php foreach ($services as $sv): ?>
            <div class="service-item">
              <div class="left">
                <i class="fas <?= h($sv['icon'] ?: 'fa-tooth') ?>"></i>
                <span><?= h($sv['title']) ?></span>
                <span style="font-size:11px; color:#94a3b8;"><?= h($sv['duration']) ?> min</span>
              </div>
              <span class="price"><?= money($sv['price']) ?></span>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="empty-row"><i class="fas fa-tooth"></i>No active services yet.</div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <h2><i class="fas fa-camera"></i> Gallery</h2>
        <a href="homeset.php" class="manage-link">Edit →</a>
      </div>
      <?php if ($gallery): ?>
        <div class="gallery-preview">
          <?php foreach ($gallery as $g): ?>
            <div class="thumb">
              <?php if (!empty($g['image'])): ?>
                <!-- FIXED: Added ../ since dashboard is in admin folder and images are in uploads/gallery/ -->
                <img src="../../<?= h($g['image']) ?>" alt="<?= h($g['tag'] ?? 'Gallery photo') ?>">
              <?php else: ?>
                <i class="fas fa-image"></i>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
        <div style="padding: 12px 16px 16px; text-align:center; font-size:12px; color:#94a3b8;">
          <?= (int)$galleryCount ?> items · <a href="homeset.php" class="text-teal font-semibold">view all</a>
        </div>
      <?php else: ?>
        <div class="empty-row"><i class="fas fa-image"></i>No gallery items yet.</div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Footer -->
  <div class="footer-note">
    <i class="fas fa-tooth" style="color: var(--teal); opacity:.5; margin-right:4px;"></i> <?= h($clinicName) ?> · Admin Dashboard
  </div>
</div>
</div>

<script>
  (function() {
    const hr = new Date().getHours();
    let greet = 'Good evening';
    if (hr < 12) greet = 'Good morning';
    else if (hr < 18) greet = 'Good afternoon';
    document.getElementById('greeting').textContent = greet;
  })();
</script>

</body>
</html>