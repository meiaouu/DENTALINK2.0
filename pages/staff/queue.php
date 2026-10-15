<?php
require '../../conn/conn.php';
date_default_timezone_set('Asia/Manila');

// This page is meant to run unattended on a lobby/waiting-room monitor,
// so it intentionally does NOT require a staff login like the other admin pages.

function h($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

// Patients booked without a name (or walk-ins entered incompletely) show
// their tracking token instead, so the board never displays a blank row.
function patientLabel($appt) {
    $name = trim($appt['full_name'] ?? '');
    if ($name !== '') return $name;
    $token = trim($appt['tracking_token'] ?? '');
    if ($token !== '') return 'Track #' . strtoupper(substr($token, 0, 8));
    return 'Track #' . str_pad($appt['id'], 6, '0', STR_PAD_LEFT);
}

$today   = date('Y-m-d');
$nowTime = date('H:i:s');

$stmt = $conn->prepare("
    SELECT a.id, a.full_name, a.tracking_token, a.appointment_time, a.status,
           a.arrived_at, a.treatment_started_at, a.treatment_completed_at,
           d.first_name AS dentist_first_name, d.last_name AS dentist_last_name
    FROM appointments a
    LEFT JOIN dentists d ON a.dentist_id = d.id
    WHERE (a.appointment_date = ? OR (a.status = 'confirmed' AND DATE(a.arrived_at) = ?) OR (a.status = 'completed' AND DATE(a.treatment_completed_at) = ?) OR a.status = 'in_progress')
      AND a.status IN ('approved', 'confirmed', 'in_progress', 'completed')
    ORDER BY a.appointment_time ASC
");
$stmt->bind_param('sss', $today, $today, $today);
$stmt->execute();
$appointments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$completedList = array_values(array_filter($appointments, fn($a) => $a['status'] === 'completed'));

// "In progress" now comes from a real status set by staff (the "Start Treatment"
// button in appointments.php) rather than a time-based guess.
$inProgressList = array_values(array_filter($appointments, fn($a) => $a['status'] === 'in_progress'));
$inProgressAppt = $inProgressList[0] ?? null;

// Waiting = every appointment for today that's checked in / approved / confirmed
// (not yet started, not completed). The earliest one is also highlighted as
// "Next" below, but stays included in this same waiting list.
$queueList = array_values(array_filter($appointments, fn($a) => in_array($a['status'], ['approved', 'confirmed'])));

$nextPatient = $queueList[0] ?? null;

// Remove the "Next" patient from the waiting list so they don't appear twice
$waitingRest = $queueList;
if ($nextPatient && count($waitingRest) > 0) {
    // Remove the first element (which is the next patient)
    array_shift($waitingRest);
}

$waitingCount    = count($queueList);
$inProgressCount = count($inProgressList);
$completedCount  = count($completedList);

function dentistName($appt) {
    if (!empty($appt['dentist_first_name'])) {
        return 'Dr. ' . $appt['dentist_first_name'] . ' ' . $appt['dentist_last_name'];
    }
    return 'Unassigned';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta http-equiv="refresh" content="60">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Clinic Queue · Wansi Calacat Dental Clinic</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap');
* { box-sizing: border-box; font-family: 'DM Sans', system-ui, sans-serif; }
html, body { margin: 0; background: #f7f9fb; color: #111827; }

.topbar {
    display: flex; align-items: center; justify-content: space-between;
    padding: 18px 32px; background: white; border-bottom: 1px solid #111827;
}
.topbar h1 { font-size: 19px; font-weight: 800; margin: 0; color: #0b1628; }
.topbar .icons { display: flex; gap: 10px; }
.icon-btn {
    width: 36px; height: 36px; border-radius: 10px; border: 1px solid #e5e7eb; background: white;
    color: #4b5563; display: flex; align-items: center; justify-content: center; font-size: 14px; cursor: pointer;
}
.icon-btn:hover { background: #f3f4f6; }

.page { max-width: 1240px; margin: 0 auto; padding: 24px 32px 48px; }

.subheader { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; }
.subheader .date { font-size: 13.5px; color: #6b7280; font-weight: 500; }
.refresh-btn {
    padding: 8px 16px; border-radius: 8px; border: 1px solid #e5e7eb; background: white;
    font-size: 13px; font-weight: 700; color: #111827; cursor: pointer;
}
.refresh-btn:hover { background: #f3f4f6; }

.stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 20px; }
.stat-card {
    background: white; border: 1px solid #eef1f6; border-left: 4px solid #0d9e8c;
    border-radius: 10px; padding: 16px 18px;
}
.stat-card .lbl { font-size: 11px; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: .4px; }
.stat-card .num { font-size: 28px; font-weight: 800; color: #0b1628; margin: 4px 0 2px; }
.stat-card .sub { font-size: 12.5px; color: #9aa4b2; }

.main-panel {
    background: linear-gradient(135deg, #14a08e 0%, #0a5f54 100%);
    border-radius: 16px; padding: 32px; position: relative; margin-bottom: 24px;
    display: grid; grid-template-columns: 1fr 1fr; gap: 32px;
    min-height: 340px;
}
.main-panel .col-right { border-left: 1px solid rgba(255,255,255,.25); padding-left: 32px; }
.panel-section h3 { font-size: 15px; font-weight: 700; color: white; margin: 0 0 6px; }
.panel-section .placeholder { font-size: 13.5px; color: rgba(255,255,255,.7); }
.panel-section + .panel-section { margin-top: 34px; }

.queue-row {
    display: flex; align-items: center; justify-content: space-between;
    padding: 9px 0; border-bottom: 1px solid rgba(255,255,255,.15); font-size: 14px; color: white;
}
.queue-row:last-child { border-bottom: none; }
.queue-row .qname { font-weight: 700; }
.queue-row .qmeta { font-size: 12px; color: rgba(255,255,255,.65); margin-top: 1px; }
.queue-row .qtime { font-variant-numeric: tabular-nums; font-weight: 700; font-size: 13px; color: rgba(255,255,255,.85); }

.in-progress-card .qname { font-size: 22px; font-weight: 800; color: white; }
.in-progress-card .qmeta { font-size: 13.5px; color: rgba(255,255,255,.7); margin-top: 4px; }

.fullscreen-btn {
    position: absolute; bottom: 18px; right: 18px; width: 34px; height: 34px;
    border-radius: 8px; background: rgba(255,255,255,.15); border: none; color: white;
    display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 13px;
}
.fullscreen-btn:hover { background: rgba(255,255,255,.28); }

.completed-card { background: white; border: 1px solid #eef1f6; border-radius: 12px; padding: 20px 24px; }
.completed-card h3 { font-size: 15px; font-weight: 700; margin: 0 0 2px; color: #0b1628; }
.completed-card .sub { font-size: 12.5px; color: #9aa4b2; margin-bottom: 14px; }

.completed-empty {
    border: 1px dashed #e5e7eb; border-radius: 10px; padding: 22px; text-align: center;
    font-size: 13.5px; color: #9aa4b2; background: #fafbfc;
}
.completed-table { width: 100%; border-collapse: collapse; }
.completed-table td { padding: 9px 6px; font-size: 13.5px; border-bottom: 1px solid #f1f5f9; color: #111827; }
.completed-table tr:last-child td { border-bottom: none; }
.completed-table .c-dentist { color: #9aa4b2; font-size: 12.5px; }

@media (max-width: 900px) {
    .page { padding: 20px 18px 40px; }
    .stats-row { grid-template-columns: repeat(2, 1fr); }
    .main-panel { grid-template-columns: 1fr; }
    .main-panel .col-right { border-left: none; padding-left: 0; border-top: 1px solid rgba(255,255,255,.25); padding-top: 24px; }
}
</style>
</head>
<body>

<div class="topbar">
    <h1>Clinic Queue</h1>
  
</div>

<div class="page">

    <div class="subheader">
        <div class="date"><?= h(date('F j, Y')) ?></div>
        <button type="button" class="refresh-btn" onclick="window.location.reload()">Refresh</button>
    </div>

    <div class="stats-row">
        <div class="stat-card">
            <div class="lbl">Next Patient</div>
            <div class="num"><?= $nextPatient ? 1 : 0 ?></div>
            <div class="sub"><?= $nextPatient ? h(patientLabel($nextPatient)) : 'No next patient' ?></div>
        </div>
        <div class="stat-card">
            <div class="lbl">Waiting</div>
            <div class="num"><?= $waitingCount ?></div>
            <div class="sub">Checked-in patients</div>
        </div>
        <div class="stat-card">
            <div class="lbl">In Progress</div>
            <div class="num"><?= $inProgressCount ?></div>
            <div class="sub">Currently being treated</div>
        </div>
        <div class="stat-card">
            <div class="lbl">Completed</div>
            <div class="num"><?= $completedCount ?></div>
            <div class="sub">Finished today</div>
        </div>
    </div>

    <div class="main-panel" id="mainPanel">
        <div class="col-left">
            <div class="panel-section">
                <h3>Next</h3>
                <?php if ($nextPatient): ?>
                    <div class="queue-row" style="border-bottom:none;">
                        <div>
                            <div class="qname"><?= h(patientLabel($nextPatient)) ?></div>
                            <div class="qmeta"><?= h(dentistName($nextPatient)) ?></div>
                        </div>
                        <div class="qtime"><?= h($nextPatient['appointment_time'] ? date('g:i A', strtotime($nextPatient['appointment_time'])) : '—') ?></div>
                    </div>
                <?php else: ?>
                    <div class="placeholder">No next patient.</div>
                <?php endif; ?>
            </div>

            <div class="panel-section">
                <h3>Waiting...</h3>
                <?php if ($waitingRest): ?>
                    <?php foreach ($waitingRest as $wa): ?>
                        <div class="queue-row">
                            <div>
                                <div class="qname"><?= h(patientLabel($wa)) ?></div>
                                <div class="qmeta"><?= h(dentistName($wa)) ?></div>
                            </div>
                            <div class="qtime"><?= h($wa['appointment_time'] ? date('g:i A', strtotime($wa['appointment_time'])) : '—') ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="placeholder">No waiting patients.</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-right">
            <div class="panel-section in-progress-card">
                <h3>In Progress</h3>
                <?php if ($inProgressList): ?>
                    <?php foreach ($inProgressList as $progress): ?>
                        <div class="queue-row">
                            <div>
                                <div class="qname"><?= h(patientLabel($progress)) ?></div>
                                <div class="qmeta"><?= h(dentistName($progress)) ?></div>
                            </div>
                            <div class="qtime"><?= h($progress['appointment_time'] ? date('g:i A', strtotime($progress['appointment_time'])) : '—') ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="placeholder">No treatment in progress.</div>
                <?php endif; ?>
            </div>
        </div>

        <button type="button" class="fullscreen-btn" onclick="toggleFullscreen()" title="Fullscreen">
            <i class="fas fa-expand"></i>
        </button>
    </div>

    <div class="completed-card">
        <h3>Completed</h3>
        <div class="sub">Finished today or listed on today’s original schedule. Showing actual finish time when recorded.</div>

        <?php if ($completedList): ?>
            <table class="completed-table">
                <tbody>
                <?php foreach ($completedList as $ca): ?>
                    <tr>
                        <td style="font-weight:700; width: 90px;"><?= h(!empty($ca['treatment_completed_at']) ? date('M j, g:i A', strtotime($ca['treatment_completed_at'])) : ($ca['appointment_time'] ? date('g:i A', strtotime($ca['appointment_time'])) : '—')) ?></td>
                        <td><?= h(patientLabel($ca)) ?></td>
                        <td class="c-dentist"><?= h(dentistName($ca)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="completed-empty">No completed appointments yet.</div>
        <?php endif; ?>
    </div>

</div>

<script>
function toggleFullscreen() {
    if (!document.fullscreenElement) {
        document.documentElement.requestFullscreen().catch(() => {});
    } else {
        document.exitFullscreen();
    }
}

// Auto-refresh every 30 seconds for live updates
setInterval(function() {
    window.location.reload();
}, 30000);
</script>

</body>
</html>