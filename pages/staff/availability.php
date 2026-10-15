<?php
require '../../conn/conn.php';
date_default_timezone_set('Asia/Manila');

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

function h($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

// ========================= SETUP =========================
function ensureDefaultWorkingHours($conn) {
    $check = $conn->query("SELECT COUNT(*) c FROM dentist_working_hours")->fetch_assoc();
    if ((int)$check['c'] > 0) return;

    $stmt = $conn->prepare("INSERT INTO dentist_working_hours (day_of_week, is_active, start_time, end_time) VALUES (?, ?, ?, ?)");
    for ($day = 0; $day <= 6; $day++) {
        $active = ($day === 0) ? 0 : 1;
        $start = '08:00:00';
        $end = '17:00:00';
        $stmt->bind_param('iiss', $day, $active, $start, $end);
        $stmt->execute();
    }
    $stmt->close();
}
ensureDefaultWorkingHours($conn);

// ========================= DATA HELPERS =========================
function getWorkingHoursMap($conn) {
    $rows = $conn->query("SELECT * FROM dentist_working_hours ORDER BY day_of_week")->fetch_all(MYSQLI_ASSOC);
    $map = [];
    foreach ($rows as $r) $map[(int)$r['day_of_week']] = $r;
    return $map;
}

function getExceptionsInRange($conn, $start, $end) {
    $stmt = $conn->prepare("SELECT * FROM dentist_availability_exceptions WHERE exception_date BETWEEN ? AND ?");
    $stmt->bind_param('ss', $start, $end);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $map = [];
    foreach ($rows as $r) $map[$r['exception_date']] = $r;
    return $map;
}

function getExceptionForDate($conn, $date) {
    $stmt = $conn->prepare("SELECT * FROM dentist_availability_exceptions WHERE exception_date = ?");
    $stmt->bind_param('s', $date);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row;
}

// Get all services
function getServices($conn) {
    $result = $conn->query("SELECT * FROM services WHERE is_active = 1 ORDER BY sort_order ASC");
    $services = [];
    while ($row = $result->fetch_assoc()) {
        $services[$row['id']] = $row;
    }
    return $services;
}

// Parse services from appointment
function parseAppointmentServices($servicesStr, $servicesMap) {
    if (empty($servicesStr)) return [];
    
    // Check if it's JSON
    $decoded = json_decode($servicesStr, true);
    if (is_array($decoded)) {
        $serviceIds = $decoded;
    } else {
        // Try comma-separated
        $serviceIds = array_map('trim', explode(',', $servicesStr));
    }
    
    $result = [];
    foreach ($serviceIds as $id) {
        if (isset($servicesMap[$id])) {
            $result[] = $servicesMap[$id];
        }
    }
    return $result;
}
function getAppointmentsInRange($conn, $start, $end) {
    $servicesMap = getServices($conn);
    
    $stmt = $conn->prepare("
        SELECT id, phone, email, services, status, created_at, DATE(created_at) as appointment_date 
        FROM appointments 
        WHERE DATE(created_at) BETWEEN ? AND ? 
        AND status IN ('approved', 'confirmed')
        ORDER BY created_at ASC
    ");
    $stmt->bind_param('ss', $start, $end);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    $map = [];
    foreach ($rows as $r) {
        $date = $r['appointment_date'];
        if (!isset($map[$date])) {
            $map[$date] = [];
        }
        
        // Parse services
        $r['service_details'] = parseAppointmentServices($r['services'], $servicesMap);
        $r['service_names'] = array_column($r['service_details'], 'title');
        $r['service_names_str'] = implode(', ', $r['service_names']);
        $r['total_price'] = array_sum(array_column($r['service_details'], 'price'));
        
        $map[$date][] = $r;
    }
    return $map;
}
function getAppointmentsForDate($conn, $date) {
    $servicesMap = getServices($conn);
    
    $stmt = $conn->prepare("
        SELECT id, phone, email, services, status, created_at 
        FROM appointments 
        WHERE DATE(created_at) = ? 
        AND status IN ('approved', 'confirmed')
        ORDER BY created_at ASC
    ");
    $stmt->bind_param('s', $date);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    foreach ($rows as &$r) {
        $r['service_details'] = parseAppointmentServices($r['services'], $servicesMap);
        $r['service_names'] = array_column($r['service_details'], 'title');
        $r['service_names_str'] = implode(', ', $r['service_names']);
        $r['total_price'] = array_sum(array_column($r['service_details'], 'price'));
    }
    
    return $rows;
}

// NEW: Get appointment count for a date
function getAppointmentCountForDate($conn, $date) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) as count 
        FROM appointments 
        WHERE DATE(created_at) = ? 
        AND status IN ('approved', 'confirmed')
    ");
    $stmt->bind_param('s', $date);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $result['count'] ?? 0;
}

// Resolve what a given date's status is: exception override > weekday default > past
function computeDayStatus($dateStr, $workingHoursMap, $exceptionsMap, $todayStr) {
    if ($dateStr < $todayStr) {
        return ['status' => 'past', 'label' => 'Past', 'start' => null, 'end' => null];
    }
    if (isset($exceptionsMap[$dateStr])) {
        $ex = $exceptionsMap[$dateStr];
        $labels = ['available' => 'Available', 'half_day' => 'Half Day', 'unavailable' => 'Unavailable'];
        return [
            'status' => $ex['status'],
            'label' => $labels[$ex['status']] ?? ucfirst($ex['status']),
            'start' => $ex['start_time'],
            'end' => $ex['end_time']
        ];
    }
    $dow = (int)date('w', strtotime($dateStr));
    $wh = $workingHoursMap[$dow] ?? null;
    if ($wh && (int)$wh['is_active'] === 1) {
        return ['status' => 'available', 'label' => 'Available', 'start' => $wh['start_time'], 'end' => $wh['end_time']];
    }
    return ['status' => 'unavailable', 'label' => 'Unavailable', 'start' => null, 'end' => null];
}

function findNextAvailableDate($conn, $workingHoursMap) {
    $today = new DateTime();
    $todayStr = $today->format('Y-m-d');
    $rangeEnd = (clone $today)->modify('+90 days')->format('Y-m-d');
    $exceptions = getExceptionsInRange($conn, $todayStr, $rangeEnd);

    for ($i = 0; $i <= 90; $i++) {
        $d = (clone $today)->modify("+$i days");
        $status = computeDayStatus($d->format('Y-m-d'), $workingHoursMap, $exceptions, $todayStr);
        if (in_array($status['status'], ['available', 'half_day'])) {
            return $d;
        }
    }
    return null;
}

// ========================= RENDERERS =========================
function renderMonthCalendar($conn, $year, $month) {
    $workingHoursMap = getWorkingHoursMap($conn);
    $todayStr = date('Y-m-d');

    $firstOfMonth = new DateTime(sprintf('%04d-%02d-01', $year, $month));
    $startDow = (int)$firstOfMonth->format('w');
    $daysInMonth = (int)$firstOfMonth->format('t');

    $gridStart = (clone $firstOfMonth)->modify("-$startDow days");

    $lastOfMonth = (clone $firstOfMonth)->modify('+' . ($daysInMonth - 1) . ' days');
    $endDow = (int)$lastOfMonth->format('w');
    $trailingDays = 6 - $endDow;
    $gridEnd = (clone $lastOfMonth)->modify("+$trailingDays days");

    $exceptions = getExceptionsInRange($conn, $gridStart->format('Y-m-d'), $gridEnd->format('Y-m-d'));
    
    // NEW: Get appointments for the month
    $appointments = getAppointmentsInRange($conn, $gridStart->format('Y-m-d'), $gridEnd->format('Y-m-d'));

    $available = 0; $half = 0; $unavailable = 0;

    ob_start();
    $cursor = clone $gridStart;
    while ($cursor <= $gridEnd) {
        echo '<div class="cal-week">';
        for ($i = 0; $i < 7; $i++) {
            $dStr = $cursor->format('Y-m-d');
            $inMonth = ((int)$cursor->format('n') === (int)$month);
            $dayNum = (int)$cursor->format('j');
            $status = computeDayStatus($dStr, $workingHoursMap, $exceptions, $todayStr);

            if ($inMonth) {
                if ($status['status'] === 'available') $available++;
                elseif ($status['status'] === 'half_day') $half++;
                elseif ($status['status'] === 'unavailable') $unavailable++;
            }

            $clickable = $inMonth && $dStr >= $todayStr;
            $isToday = ($dStr === $todayStr);
            
            // NEW: Check if there are appointments on this date
            $appointmentCount = isset($appointments[$dStr]) ? count($appointments[$dStr]) : 0;
            $hasAppointments = $appointmentCount > 0;

            $cls = 'cal-day status-' . $status['status'];
            if (!$inMonth) $cls .= ' other-month';
            if ($clickable) $cls .= ' clickable';
            if ($hasAppointments && $inMonth) $cls .= ' has-appointments';

            // Replace this section:
echo '<div class="' . $cls . '"' . ($clickable ? ' onclick="openDateModal(\'' . $dStr . '\')"' : '') . '>';
echo '<span class="cal-day-num' . ($isToday ? ' today-badge' : '') . '">' . $dayNum . '</span>';
if ($inMonth) {
    echo '<span class="cal-day-status"><i class="fas fa-circle"></i> ' . h($status['label']) . '</span>';
    // NEW: Show appointment indicator
    if ($hasAppointments) {
echo '<span class="cal-day-appointments" onclick="event.stopPropagation(); showAppointmentDetails(\'' . $dStr . '\')"><i class="fas fa-calendar-check"></i> ' . $appointmentCount . ' appt' . ($appointmentCount > 1 ? 's' : '') . '</span>';    }
}
echo '</div>';

            $cursor->modify('+1 day');
        }
        echo '</div>';
    }
    $html = ob_get_clean();

    return [
        'html' => $html,
        'stats' => [
            'available' => $available,
            'half' => $half,
            'unavailable' => $unavailable,
            'total' => $daysInMonth
        ]
    ];
}

function renderWeek($conn, $weekStartStr) {
    $workingHoursMap = getWorkingHoursMap($conn);
    $todayStr = date('Y-m-d');
    $weekStart = new DateTime($weekStartStr);
    $weekEnd = (clone $weekStart)->modify('+6 days');
    $exceptions = getExceptionsInRange($conn, $weekStart->format('Y-m-d'), $weekEnd->format('Y-m-d'));
    // NEW: Get appointments for the week
    $appointments = getAppointmentsInRange($conn, $weekStart->format('Y-m-d'), $weekEnd->format('Y-m-d'));

    $days = [];
    for ($i = 0; $i < 7; $i++) {
        $d = (clone $weekStart)->modify("+$i days");
        $dStr = $d->format('Y-m-d');
        $status = computeDayStatus($dStr, $workingHoursMap, $exceptions, $todayStr);
        $dayAppointments = isset($appointments[$dStr]) ? $appointments[$dStr] : [];
        $days[] = [
            'date' => $d, 
            'dateStr' => $dStr, 
            'status' => $status,
            'appointments' => $dayAppointments,
            'appointmentCount' => count($dayAppointments)
        ];
    }

    $slotStartHour = 7;
    $slotEndHour = 18;

    ob_start();
    ?>
    <div class="week-label"><?= $weekStart->format('M j') ?> &ndash; <?= $weekEnd->format('M j') ?></div>
    <div class="week-grid-wrap">
    <table class="week-grid">
        <thead>
            <tr>
                <th class="time-col"></th>
                <?php foreach ($days as $d): ?>
                <th class="<?= in_array($d['status']['status'], ['unavailable', 'past']) ? 'day-off' : '' ?>">
                    <?= $d['date']->format('D') ?><br><span class="wk-date"><?= $d['date']->format('M j') ?></span>
                    <?php if ($d['appointmentCount'] > 0): ?>
                    <br><span class="wk-appt-badge"><?= $d['appointmentCount'] ?> appt<?= $d['appointmentCount'] > 1 ? 's' : '' ?></span>
                    <?php endif; ?>
                </th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php for ($hr = $slotStartHour; $hr < $slotEndHour; $hr++):
                $slotStart = sprintf('%02d:00:00', $hr);
                $slotEnd = sprintf('%02d:00:00', $hr + 1);
            ?>
            <tr>
                <td class="time-col"><?= date('g:i A', strtotime($slotStart)) ?> &ndash; <?= date('g:i A', strtotime($slotEnd)) ?></td>
                <?php foreach ($days as $d):
                    $st = $d['status'];
                    $cellClass = 'slot-cell';
                    if (in_array($st['status'], ['unavailable', 'past'])) {
                        $cellClass .= ' slot-off';
                    } elseif ($st['start'] && $st['end'] && $slotStart >= $st['start'] && $slotStart < $st['end']) {
                        $cellClass .= ' slot-open';
                    }
                    
                    // NEW: Check if there's an appointment in this time slot
                    $hasAppointment = false;
                    foreach ($d['appointments'] as $appt) {
                        $apptTime = date('H:i:s', strtotime($appt['created_at']));
                        if ($apptTime >= $slotStart && $apptTime < $slotEnd) {
                            $hasAppointment = true;
                            break;
                        }
                    }
                    if ($hasAppointment) {
                        $cellClass .= ' slot-appointment';
                    }
                ?>
                <td class="<?= $cellClass ?>">
                    <?php if ($hasAppointment): ?>
                    <span class="appt-indicator" title="Has appointment"></span>
                    <?php endif; ?>
                </td>
                <?php endforeach; ?>
            </tr>
            <?php endfor; ?>
        </tbody>
    </table>
    </div>
    <?php
    return ob_get_clean();
}

function renderManagePanel($conn) {
    $map = getWorkingHoursMap($conn);
    $dayNames = [0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'];

    ob_start();
    foreach ([0, 1, 2, 3, 4, 5, 6] as $day):
        $row = $map[$day] ?? ['is_active' => 0, 'start_time' => '08:00:00', 'end_time' => '17:00:00'];
        $isActive = (int)$row['is_active'] === 1;
    ?>
    <div class="wh-row" data-day="<?= $day ?>" data-start="<?= h($row['start_time']) ?>" data-end="<?= h($row['end_time']) ?>">
        <span class="wh-day"><?= $dayNames[$day] ?></span>
        <span class="wh-toggle">
            <label class="switch">
                <input type="checkbox" <?= $isActive ? 'checked' : '' ?> onchange="toggleDayActive(<?= $day ?>, this.checked)">
                <span class="slider"></span>
            </label>
        </span>
        <span class="wh-time"><?= date('g:i A', strtotime($row['start_time'])) ?></span>
        <span class="wh-time"><?= date('g:i A', strtotime($row['end_time'])) ?></span>
        <span class="wh-action">
            <button type="button" class="btn-outline btn-xs" onclick="openEditDayModal(<?= $day ?>)"><i class="fas fa-pen"></i> Edit</button>
        </span>
    </div>
    <?php endforeach;
    return ob_get_clean();
}

// ========================= AJAX =========================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];

    switch ($_POST['action']) {

        case 'get_month':
            $year = (int)($_POST['year'] ?? date('Y'));
            $month = (int)($_POST['month'] ?? date('n'));
            if ($month < 1) { $month = 12; $year--; }
            if ($month > 12) { $month = 1; $year++; }

            $cal = renderMonthCalendar($conn, $year, $month);
            $whMap = getWorkingHoursMap($conn);
            $next = findNextAvailableDate($conn, $whMap);

            $response['success'] = true;
            $response['html'] = $cal['html'];
            $response['stats'] = $cal['stats'];
            $response['label'] = date('F Y', strtotime("$year-$month-01"));
            $response['year'] = $year;
            $response['month'] = $month;
            $response['next_available'] = $next ? $next->format('l, M j') : 'None in next 90 days';
            break;

        case 'get_week':
            $weekStart = $_POST['week_start'] ?? date('Y-m-d', strtotime('monday this week'));
            $response['success'] = true;
            $response['html'] = renderWeek($conn, $weekStart);
            break;

        case 'get_manage_panel':
            $response['success'] = true;
            $response['html'] = renderManagePanel($conn);
            break;

        case 'toggle_day':
            $day = (int)($_POST['day'] ?? -1);
            $active = (int)($_POST['is_active'] ?? 0);
            if ($day < 0 || $day > 6) {
                $response['message'] = 'Invalid day.';
                break;
            }
            $stmt = $conn->prepare("UPDATE dentist_working_hours SET is_active = ? WHERE day_of_week = ?");
            $stmt->bind_param('ii', $active, $day);
            if ($stmt->execute()) {
                $response['success'] = true;
                $response['message'] = 'Updated.';
            } else {
                $response['message'] = 'Failed to update.';
            }
            $stmt->close();
            break;

        case 'save_day_hours':
            $day = (int)($_POST['day'] ?? -1);
            $start = $_POST['start_time'] ?? '';
            $end = $_POST['end_time'] ?? '';
            if ($day < 0 || $day > 6 || empty($start) || empty($end)) {
                $response['message'] = 'Missing required fields.';
                break;
            }
            if (strtotime($start) >= strtotime($end)) {
                $response['message'] = 'Start time must be before end time.';
                break;
            }
            $stmt = $conn->prepare("UPDATE dentist_working_hours SET start_time = ?, end_time = ? WHERE day_of_week = ?");
            $stmt->bind_param('ssi', $start, $end, $day);
            if ($stmt->execute()) {
                $response['success'] = true;
                $response['message'] = 'Working hours updated.';
            } else {
                $response['message'] = 'Failed to update.';
            }
            $stmt->close();
            break;

        case 'save_all_hours':
            $start = $_POST['start_time'] ?? '';
            $end = $_POST['end_time'] ?? '';
            
            $days = [];
            if (isset($_POST['days_json'])) {
                $decoded = json_decode($_POST['days_json'], true);
                if (is_array($decoded)) {
                    $days = $decoded;
                }
            }
            if (empty($days) && isset($_POST['days']) && is_array($_POST['days'])) {
                $days = $_POST['days'];
            }
            if (empty($days)) {
                $input = file_get_contents('php://input');
                parse_str($input, $parsed);
                if (isset($parsed['days']) && is_array($parsed['days'])) {
                    $days = $parsed['days'];
                }
            }
            
            if (empty($start) || empty($end)) {
                $response['message'] = 'Missing start or end time.';
                break;
            }
            if (empty($days)) {
                $response['message'] = 'No days selected.';
                break;
            }
            if (strtotime($start) >= strtotime($end)) {
                $response['message'] = 'Start time must be before end time.';
                break;
            }
            
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("
                    INSERT INTO dentist_working_hours (day_of_week, is_active, start_time, end_time) 
                    VALUES (?, 1, ?, ?) 
                    ON DUPLICATE KEY UPDATE 
                        start_time = VALUES(start_time), 
                        end_time = VALUES(end_time),
                        is_active = 1
                ");
                if (!$stmt) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                $updated = 0;
                foreach ($days as $d) {
                    $d = (int)$d;
                    if ($d < 0 || $d > 6) continue;
                    $stmt->bind_param('iss', $d, $start, $end);
                    if ($stmt->execute()) {
                        $updated++;
                    } else {
                        throw new Exception("Failed to update day $d: " . $stmt->error);
                    }
                }
                $stmt->close();
                $conn->commit();
                $response['success'] = true;
                $response['message'] = "Working hours updated for $updated day(s).";
            } catch (Exception $e) {
                $conn->rollback();
                $response['message'] = 'Failed to update: ' . $e->getMessage();
                error_log('save_all_hours error: ' . $e->getMessage());
            }
            break;

        case 'get_date_status':
            $date = $_POST['date'] ?? '';
            if (empty($date)) {
                $response['message'] = 'Missing date.';
                break;
            }
            $ex = getExceptionForDate($conn, $date);
            // NEW: Get appointments for this date
            $appointments = getAppointmentsForDate($conn, $date);
            $response['success'] = true;
            $response['has_exception'] = (bool)$ex;
            $response['status'] = $ex['status'] ?? 'available';
            $response['start_time'] = $ex['start_time'] ?? '08:00:00';
            $response['end_time'] = $ex['end_time'] ?? '17:00:00';
            $response['appointments'] = $appointments;
            $response['appointment_count'] = count($appointments);
            break;

        case 'save_date_status':
            $date = $_POST['date'] ?? '';
            $status = $_POST['status'] ?? '';
            $start = $_POST['start_time'] ?? null;
            $end = $_POST['end_time'] ?? null;

            if (empty($date) || !in_array($status, ['available', 'half_day', 'unavailable'])) {
                $response['message'] = 'Invalid data.';
                break;
            }
            if ($date < date('Y-m-d')) {
                $response['message'] = 'Cannot set availability for a past date.';
                break;
            }
            if ($status === 'half_day') {
                if (empty($start) || empty($end) || strtotime($start) >= strtotime($end)) {
                    $response['message'] = 'Half day requires a valid start and end time.';
                    break;
                }
            } else {
                $start = null;
                $end = null;
            }

            $stmt = $conn->prepare("
                INSERT INTO dentist_availability_exceptions (exception_date, status, start_time, end_time)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE status = VALUES(status), start_time = VALUES(start_time), end_time = VALUES(end_time)
            ");
            $stmt->bind_param('ssss', $date, $status, $start, $end);
            if ($stmt->execute()) {
                $response['success'] = true;
                $response['message'] = 'Availability saved.';
            } else {
                $response['message'] = 'Failed to save.';
            }
            $stmt->close();
            break;

        case 'clear_date_status':
            $date = $_POST['date'] ?? '';
            if (empty($date)) {
                $response['message'] = 'Missing date.';
                break;
            }
            $stmt = $conn->prepare("DELETE FROM dentist_availability_exceptions WHERE exception_date = ?");
            $stmt->bind_param('s', $date);
            if ($stmt->execute()) {
                $response['success'] = true;
                $response['message'] = 'Reset to default schedule.';
            } else {
                $response['message'] = 'Failed to reset.';
            }
            $stmt->close();
            break;
            
        // NEW: Get appointments for a specific date
       case 'get_appointments':
    $date = $_POST['date'] ?? '';
    if (empty($date)) {
        $response['message'] = 'Missing date.';
        break;
    }
    $appointments = getAppointmentsForDate($conn, $date);
    $response['success'] = true;
    $response['appointments'] = $appointments;
    $response['count'] = count($appointments);
    break;
    }

    echo json_encode($response);
    exit;
}

// ========================= INITIAL LOAD =========================
$year = (int)($_GET['year'] ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));
$cal = renderMonthCalendar($conn, $year, $month);
$whMap = getWorkingHoursMap($conn);
$nextAvailable = findNextAvailableDate($conn, $whMap);
$monthLabel = date('F Y', strtotime("$year-$month-01"));

$currentWeekStart = date('Y-m-d', strtotime('monday this week'));

include 'ssidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dentist Availability · Dental Clinic Dashboard</title>
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
    --off: #f7f9fc;
    --slate: #64748b;
    --amber: #b45309;
    --amber-bg: #fef3d7;
    --red: #b91c1c;
    --red-bg: #fee9e7;
    --shadow-card: 0 2px 8px rgba(11,22,40,.06), 0 12px 32px rgba(11,22,40,.08);
    --appointment-blue: #3b82f6;
}


.text-teal { color: var(--teal); }
.bg-dark-blue { background-color: var(--navy); }
.text-dark-blue { color: var(--navy); }
.container-modern { max-width: 1280px; margin: 0 auto; padding: 0 1.5rem; }
@media (min-width: 1024px) { .main-content { margin-left: 260px; } }
@media (max-width: 1023px) { .main-content { margin-left: 0; } }
@media (max-width: 640px) { .container-modern { padding: 0 1rem; } }

.top-header {
    align-items: center; justify-content: space-between;
    padding: 1.25rem 1.5rem; 
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

.btn-primary {
    background: var(--teal); color: white; padding: 9px 18px; border-radius: 10px;
    font-weight: 600; font-size: 13px; border: none; cursor: pointer;
    display: inline-flex; align-items: center; gap: 8px; transition: all .2s;
}
.btn-primary:hover { background: var(--teal-dark); }
.btn-outline {
    border: 1.5px solid var(--teal); color: var(--teal); padding: 8px 16px;
    border-radius: 10px; font-weight: 600; font-size: 12px; background: transparent;
    cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
}
.btn-outline:hover { background: var(--teal); color: white; }
.btn-xs { padding: 5px 10px; font-size: 11px; }
.btn-dark {
    background: var(--navy); color: white; padding: 8px 16px; border-radius: 10px;
    font-weight: 600; font-size: 12px; border: none; cursor: pointer;
    display: inline-flex; align-items: center; gap: 6px;
}
.view-toggle {
    display: flex; background: #f1f4f8; border-radius: 10px; padding: 3px; gap: 2px;
}
.view-toggle button {
    padding: 7px 16px; border-radius: 8px; border: none; background: transparent;
    font-size: 12.5px; font-weight: 600; color: var(--slate); cursor: pointer;
}
.view-toggle button.active { background: white; color: var(--teal); box-shadow: 0 2px 8px rgba(0,0,0,.08); }

.cal-nav-btn {
    width: 30px; height: 30px; border-radius: 8px; border: 1px solid #e2e8f0;
    background: white; color: var(--slate); cursor: pointer;
}
.cal-nav-btn:hover { border-color: var(--teal); color: var(--teal); }

.cal-head-row { display: grid; grid-template-columns: repeat(7,1fr); text-align: center; }
.cal-head-row span {
    font-size: 11px; font-weight: 700; letter-spacing: .5px; color: var(--slate);
    text-transform: uppercase; padding: 8px 0;
}
.cal-week { display: grid; grid-template-columns: repeat(7,1fr); }
.cal-day {
    border-top: 1px solid #f1f4f8; border-right: 1px solid #f1f4f8;
    min-height: 78px; padding: 8px; font-size: 13px; position: relative;
}
.cal-week > .cal-day:first-child { border-left: 1px solid #f1f4f8; }
.cal-day.clickable { cursor: pointer; transition: background .15s; }
.cal-day.clickable:hover { background: #fafbfc; }
.cal-day.other-month { color: #cbd5e1; background: #fbfcfd; }
.cal-day.other-month .cal-day-num, .cal-day.other-month .cal-day-status { color: #cbd5e1; }
.cal-day-num {
    display: inline-flex; align-items: center; justify-content: center;
    font-weight: 700; color: var(--navy); font-size: 13px;
}
.today-badge {
    background: var(--navy); color: white; width: 22px; height: 22px;
    border-radius: 50%; display: inline-flex; align-items: center; justify-content: center;
}
.cal-day-status {
    display: block; margin-top: 6px; font-size: 10.5px; font-weight: 600;
}
.cal-day-status i { font-size: 6px; margin-right: 4px; }
.status-available .cal-day-status { color: var(--teal-dark); }
.status-available .cal-day-status i { color: var(--teal); }
.status-half_day .cal-day-status { color: var(--amber); }
.status-half_day .cal-day-status i { color: #d97706; }
.status-unavailable .cal-day-status { color: var(--red); }
.status-unavailable .cal-day-status i { color: #ef4444; }
.status-past .cal-day-status { color: #94a3b8; }
.status-past .cal-day-status i { color: #cbd5e1; }

/* NEW: Appointment styles */
.cal-day-appointments {
    display: block;
    margin-top: 3px;
    font-size: 9px;
    font-weight: 600;
    color: var(--appointment-blue);
    background: #eff6ff;
    padding: 1px 6px;
    border-radius: 10px;
    text-align: center;
}
.cal-day-appointments i {
    font-size: 8px;
    margin-right: 2px;
}
.has-appointments {
    border-bottom: 3px solid var(--appointment-blue);
}

/* Week view appointment badges */
.wk-appt-badge {
    display: inline-block;
    font-size: 9px;
    font-weight: 600;
    color: white;
    background: var(--appointment-blue);
    padding: 1px 8px;
    border-radius: 10px;
    margin-top: 2px;
}
.slot-appointment {
    background: #dbeafe !important;
    position: relative;
}
.appt-indicator {
    display: block;
    width: 8px;
    height: 8px;
    background: var(--appointment-blue);
    border-radius: 50%;
    margin: 0 auto;
}

.cal-legend { display: flex; flex-wrap: wrap; gap: 16px; padding: 12px 4px 2px; font-size: 11.5px; color: var(--slate); }
.cal-legend span { display: inline-flex; align-items: center; gap: 5px; }
.cal-legend i { font-size: 8px; }
.legend-available i { color: var(--teal); }
.legend-half i { color: #d97706; }
.legend-unavailable i { color: #ef4444; }
.legend-past i { color: #cbd5e1; }
.legend-appointment i { color: var(--appointment-blue); }

/* Weekly view */
.week-label { font-weight: 700; color: var(--navy); font-size: 15px; margin-bottom: 10px; }
.week-grid-wrap { overflow-x: auto; }
.week-grid { width: 100%; border-collapse: collapse; min-width: 700px; font-size: 12px; }
.week-grid th {
    padding: 8px 6px; font-size: 11.5px; font-weight: 700; color: var(--navy);
    border-bottom: 2px solid #e9edf2; text-align: center;
}
.week-grid th.day-off { color: #cbd5e1; }
.wk-date { font-weight: 400; color: #94a3b8; font-size: 10.5px; }
.week-grid td.time-col {
    font-size: 11px; color: var(--slate); white-space: nowrap; padding: 10px 10px 10px 0;
    border-bottom: 1px solid #f1f4f8;
}
.slot-cell { border: 1px solid #f4f6f9; height: 34px; }
.slot-cell.slot-open { background: var(--teal-light); }
.slot-cell.slot-off { background: repeating-linear-gradient(45deg,#fafbfc,#fafbfc 6px,#f3f5f8 6px,#f3f5f8 12px); }

/* Sidebar stat cards */
.stat-mini {
    background: white; border-radius: 14px; padding: 14px; border: 1px solid #eef2f6;
    box-shadow: var(--shadow-card);
}
.stat-mini p.label { font-size: 10.5px; font-weight: 600; color: #94a3b8; text-transform: uppercase; letter-spacing: .3px; }
.stat-mini p.num { font-size: 26px; font-weight: 800; color: var(--navy); margin-top: 2px; }

/* Manage panel (slide drawer) */
.drawer-overlay {
    position: fixed; inset: 0; background: rgba(11,22,40,.5); z-index: 70;
    display: none;
}
.drawer-overlay.open { display: block; }
.drawer-panel {
    position: fixed; top: 0; right: 0; height: 100%; width: 420px; max-width: 92vw;
    background: white; z-index: 71; box-shadow: -12px 0 40px rgba(11,22,40,.2);
    transform: translateX(100%); transition: transform .25s ease; overflow-y: auto;
}
.drawer-panel.open { transform: translateX(0); }
.drawer-header {
    padding: 20px 22px; border-bottom: 1px solid #f0f3f7; display: flex;
    align-items: center; justify-content: space-between;
}
.drawer-header h3 { font-size: 17px; font-weight: 700; color: var(--navy); font-family: 'DM Sans'; }
.drawer-body { padding: 20px 22px; }
.drawer-close { background: none; border: none; font-size: 20px; color: #94a3b8; cursor: pointer; }

.wh-row {
    display: grid; grid-template-columns: 1.1fr .6fr .9fr .9fr .8fr;
    align-items: center; padding: 10px 0; border-bottom: 1px solid #f4f6f9; font-size: 12.5px;
}
.wh-day { font-weight: 600; color: var(--navy); }
.wh-time { color: var(--slate); }

.switch { position: relative; display: inline-block; width: 38px; height: 21px; }
.switch input { opacity: 0; width: 0; height: 0; }
.slider {
    position: absolute; cursor: pointer; inset: 0; background: #cbd5e1;
    border-radius: 999px; transition: .2s;
}
.slider:before {
    content: ""; position: absolute; height: 15px; width: 15px; left: 3px; bottom: 3px;
    background: white; border-radius: 50%; transition: .2s;
}
input:checked + .slider { background: var(--teal); }
input:checked + .slider:before { transform: translateX(17px); }

/* Modals */
.modal-overlay {
    position: fixed; inset: 0; z-index: 80; background: rgba(11,22,40,.55);
    display: none; align-items: center; justify-content: center; padding: 1rem;
}
.modal-overlay.open { display: flex; }
.modal-box {
    background: white; border-radius: 18px; width: 100%; max-width: 480px;
    box-shadow: 0 24px 60px rgba(11,22,40,.35); overflow: hidden; max-height: 90vh;
    overflow-y: auto;
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
.form-field { margin-bottom: 14px; }
.form-field label {
    display: block; font-size: 11px; font-weight: 700; color: var(--slate);
    text-transform: uppercase; letter-spacing: .3px; margin-bottom: 6px;
}
.form-field input[type=time], .form-field select {
    width: 100%; border: 1px solid #e2e8f0; border-radius: 10px; padding: 9px 12px;
    font-size: 13px; outline: none;
}
.status-choice { display: flex; gap: 8px; flex-wrap: wrap; }
.status-choice label {
    flex: 1; min-width: 90px; text-align: center; border: 1.5px solid #e2e8f0; border-radius: 10px;
    padding: 10px 6px; font-size: 12px; font-weight: 600; color: var(--slate); cursor: pointer;
}
.status-choice input { display: none; }
.status-choice input:checked + span { color: white; }
.status-choice label:has(input:checked) { border-color: var(--teal); background: var(--teal); }
.status-choice label:has(input:checked) span { color: white; }
.half-time-fields { display: none; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 12px; }
.half-time-fields.show { display: grid; }

/* NEW: Appointments list in modal */
.appointments-list {
    margin-top: 10px;
    border-top: 1px solid #f0f3f7;
    padding-top: 12px;
}
.appointment-item {
    background: #f7f9fc;
    padding: 10px 12px;
    border-radius: 10px;
    margin-bottom: 8px;
    border-left: 3px solid var(--appointment-blue);
}
.appointment-item .appt-phone {
    font-weight: 600;
    color: var(--navy);
    font-size: 13px;
}
.appointment-item .appt-email {
    color: var(--slate);
    font-size: 11px;
}
.appointment-item .appt-services {
    font-size: 11px;
    color: #475569;
    margin-top: 2px;
}
.appointment-item .appt-status {
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    padding: 1px 8px;
    border-radius: 10px;
    display: inline-block;
}
.appt-status-approved { background: #dcfce7; color: #166534; }
.appt-status-confirmed { background: #dbeafe; color: #1e40af; }
.no-appointments {
    text-align: center;
    color: #94a3b8;
    font-size: 13px;
    padding: 15px 0;
}


/* Clickable appointments */
.cal-day-appointments {
    display: block;
    margin-top: 3px;
    font-size: 9px;
    font-weight: 600;
    color: var(--appointment-blue);
    background: #eff6ff;
    padding: 2px 6px;
    border-radius: 10px;
    text-align: center;
    cursor: pointer;
    transition: all 0.2s;
}
.cal-day-appointments:hover {
    background: #dbeafe;
    transform: scale(1.05);
}
.cal-day-appointments i {
    font-size: 8px;
    margin-right: 2px;
}


.appt-status-approved { 
    background: #dcfce7; 
    color: #166534; 
    padding: 2px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
}
.appt-status-confirmed { 
    background: #dbeafe; 
    color: #1e40af; 
    padding: 2px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
}
</style>
</head>
<body class="bg-soft-gray">

<div class="main-content px-4 py-4" style="
    min-height:100vh;
    background-color:#f7f9fc;
    background-image:
        linear-gradient(rgba(11,22,40,0.04) 1px, transparent 1px),
        linear-gradient(90deg, rgba(11,22,40,0.04) 1px, transparent 1px);
    background-size: 32px 32px;
    background-position: -1px -1px;
"> 
      <div class="top-header">
    <h1 class="text-xl font-bold" style="color:var(--navy);">Dentist Availability</h1>
         <p class="text-sm text-gray-500 mt-1">Manage your availability calendar  </p>

  </div>
  

  <main class=" ">
    <div class="container-modern">
      <div class="grid grid-cols-1 lg:grid-cols-[1fr_320px] gap-5 items-start">

        <!-- CALENDAR CARD -->
        <div class="dashboard-card">
          <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
            <div class="flex items-center gap-2">
              <button class="cal-nav-btn" id="prevBtn"><i class="fas fa-chevron-left"></i></button>
              <button class="cal-nav-btn" id="nextBtn"><i class="fas fa-chevron-right"></i></button>
              <h2 class="text-lg font-bold text-dark-blue ml-1" id="calLabel"><?= h($monthLabel) ?></h2>
              <span class="text-teal text-xs font-bold" id="calYearBadge"><?= $year ?></span>
            </div>
            <div class="flex items-center gap-2">
              <div class="view-toggle">
                <button id="btnMonthly" class="active" onclick="switchView('monthly')">Monthly</button>
                <button id="btnWeekly" onclick="switchView('weekly')">Weekly</button>
              </div>
              <button class="btn-dark" onclick="openManagePanel()"><i class="fas fa-calendar-days"></i> Manage</button>
            </div>
          </div>

          <!-- Monthly view -->
          <div id="monthlyView">
            <div class="cal-head-row">
              <span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span>
            </div>
            <div id="calGrid"><?= $cal['html'] ?></div>
            <div class="cal-legend">
              <span class="legend-available"><i class="fas fa-circle"></i> Available</span>
              <span class="legend-half"><i class="fas fa-circle"></i> Half Day</span>
              <span class="legend-unavailable"><i class="fas fa-circle"></i> Unavailable</span>
              <span class="legend-past"><i class="fas fa-circle"></i> Past</span>
              <span class="legend-appointment"><i class="fas fa-calendar-check"></i> Has Appointments</span>
            </div>
          </div>

          <!-- Weekly view -->
          <div id="weeklyView" class="hidden">
            <div class="flex items-center justify-end gap-2 mb-2">
              <button class="btn-outline btn-xs" id="weekPrevBtn"><i class="fas fa-chevron-left"></i> Prev</button>
              <button class="btn-outline btn-xs" id="weekNextBtn">Next <i class="fas fa-chevron-right"></i></button>
            </div>
            <div id="weekGrid"></div>
          </div>
        </div>

        <!-- SIDEBAR -->
        <div class="space-y-4">
          <div class="dashboard-card">
            <p class="text-sm font-bold text-dark-blue flex items-center gap-2">
              <i class="fas fa-circle-info text-teal"></i> <span id="overviewLabel"><?= h($monthLabel) ?> Overview</span>
            </p>
            <p class="text-xs text-gray-400 mt-0.5">Availability summary</p>
            <div class="mt-3 bg-soft-gray rounded-xl p-3 text-xs text-gray-600 flex items-start gap-2" style="background:#f7f9fc;">
              <i class="fas fa-calendar-days text-teal mt-0.5"></i>
              <span>Click a future date to set availability. Use Manage to update default weekly working hours.</span>
            </div>
          </div>

          <div class="bg-dark-blue rounded-2xl p-4 text-white">
            <p class="text-[10px] uppercase tracking-wide text-white/60 font-semibold">Next Available Date</p>
            <p class="text-lg font-bold mt-1" id="nextAvailableLabel"><?= $nextAvailable ? h($nextAvailable->format('l, M j')) : 'None in next 90 days' ?></p>
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div class="stat-mini">
              <p class="label">Available Days</p>
              <p class="num" style="color:var(--teal-dark)" id="statAvailable"><?= $cal['stats']['available'] ?></p>
            </div>
            <div class="stat-mini">
              <p class="label">Half Days</p>
              <p class="num" style="color:var(--amber)" id="statHalf"><?= $cal['stats']['half'] ?></p>
            </div>
            <div class="stat-mini">
              <p class="label">Unavailable</p>
              <p class="num" style="color:var(--red)" id="statUnavailable"><?= $cal['stats']['unavailable'] ?></p>
            </div>
            <div class="stat-mini">
              <p class="label">Total Days</p>
              <p class="num" id="statTotal"><?= $cal['stats']['total'] ?></p>
            </div>
          </div>
        </div>

      </div>
    </div>
  </main>
</div>

<!-- MANAGE DRAWER -->
<div class="drawer-overlay" id="drawerOverlay" onclick="closeManagePanel()"></div>
<div class="drawer-panel" id="drawerPanel">
  <div class="drawer-header">
    <h3>Set default working hours per day.</h3>
    <button class="drawer-close" onclick="closeManagePanel()">&times;</button>
  </div>
  <div class="drawer-body">
    <div class="bg-light-teal rounded-xl p-3 flex items-start gap-3 mb-4" style="background:var(--teal-light);">
      <i class="fas fa-calendar-days text-teal mt-0.5"></i>
      <div>
        <p class="text-sm font-bold text-dark-blue">Weekly Working Hours</p>
        <p class="text-xs text-gray-500">Applies as the default schedule each week.</p>
      </div>
    </div>

    <p class="text-[10.5px] font-bold text-gray-400 uppercase tracking-wide mb-1">Working Days &amp; Hours</p>
    <div id="whRows"><?= renderManagePanel($conn) ?></div>

    <button type="button" class="btn-outline w-full justify-center mt-5" onclick="openEditAllModal()">
      <i class="fas fa-pen"></i> Edit All
    </button>
  </div>
</div>

<!-- DATE STATUS MODAL (Updated with appointments) -->
<div class="modal-overlay" id="dateModal">
  <div class="modal-box">
    <div class="modal-header">
      <h3 id="dateModalTitle">Set Availability</h3>
      <button class="modal-close-btn" onclick="closeDateModal()">&times;</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="dateModalDate">
      
      <!-- Appointment section -->
      <div id="appointmentsSection" style="display:none;">
        <div class="form-field">
          <label><i class="fas fa-calendar-check"></i> Appointments on this day</label>
          <div id="appointmentsList" class="appointments-list"></div>
        </div>
        <hr class="my-3">
      </div>
      
      <div class="form-field">
        <label>Status</label>
        <div class="status-choice">
          <label><input type="radio" name="dateStatus" value="available" onchange="onDateStatusChange()"><span>Available</span></label>
          <label><input type="radio" name="dateStatus" value="half_day" onchange="onDateStatusChange()"><span>Half Day</span></label>
          <label><input type="radio" name="dateStatus" value="unavailable" onchange="onDateStatusChange()"><span>Unavailable</span></label>
        </div>
        <div class="half-time-fields" id="halfTimeFields">
          <div>
            <label class="text-[10px] text-gray-400 font-semibold">Start</label>
            <input type="time" id="dateStart" value="08:00">
          </div>
          <div>
            <label class="text-[10px] text-gray-400 font-semibold">End</label>
            <input type="time" id="dateEnd" value="12:00">
          </div>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn-outline" id="resetDateBtn" onclick="resetDateStatus()">Reset to Default</button>
      <button type="button" class="btn-primary" onclick="saveDateStatus()"><i class="fas fa-save"></i> Save</button>
    </div>
  </div>
</div>

<!-- EDIT WORKING HOURS MODAL -->
<div class="modal-overlay" id="hoursModal">
  <div class="modal-box">
    <div class="modal-header">
      <h3 id="hoursModalTitle">Edit Hours</h3>
      <button class="modal-close-btn" onclick="closeHoursModal()">&times;</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="hoursModalMode">
      <input type="hidden" id="hoursModalDay">

      <div class="form-field" id="daysCheckboxField" style="display:none;">
        <label>Apply to Days</label>
        <div class="grid grid-cols-2 gap-2 text-xs">
          <?php $dn = [0=>'Sunday',1=>'Monday',2=>'Tuesday',3=>'Wednesday',4=>'Thursday',5=>'Friday',6=>'Saturday']; ?>
          <?php foreach ($dn as $num => $name): ?>
          <label class="flex items-center gap-2">
            <input type="checkbox" class="hours-day-cb" value="<?= $num ?>" checked> <?= $name ?>
          </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="grid grid-cols-2 gap-3">
        <div class="form-field">
          <label>Start Time</label>
          <input type="time" id="hoursStart" value="08:00">
        </div>
        <div class="form-field">
          <label>End Time</label>
          <input type="time" id="hoursEnd" value="17:00">
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn-outline" onclick="closeHoursModal()">Cancel</button>
      <button type="button" class="btn-primary" onclick="saveHours()"><i class="fas fa-save"></i> Save</button>
    </div>
  </div>
</div>

<script>
let currentYear = <?= (int)$year ?>;
let currentMonth = <?= (int)$month ?>;
let currentView = 'monthly';
let currentWeekStart = '<?= $currentWeekStart ?>';

// ---------- MONTH NAV ----------
function loadMonth(year, month) {
    const formData = new FormData();
    formData.append('action', 'get_month');
    formData.append('year', year);
    formData.append('month', month);

    fetch(window.location.href, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;
            currentYear = data.year;
            currentMonth = data.month;
            document.getElementById('calGrid').innerHTML = data.html;
            document.getElementById('calLabel').textContent = data.label;
            document.getElementById('calYearBadge').textContent = data.year;
            document.getElementById('overviewLabel').textContent = data.label + ' Overview';
            document.getElementById('nextAvailableLabel').textContent = data.next_available;
            document.getElementById('statAvailable').textContent = data.stats.available;
            document.getElementById('statHalf').textContent = data.stats.half;
            document.getElementById('statUnavailable').textContent = data.stats.unavailable;
            document.getElementById('statTotal').textContent = data.stats.total;

            const url = new URL(window.location.href);
            url.searchParams.set('year', currentYear);
            url.searchParams.set('month', currentMonth);
            window.history.replaceState({}, '', url);
        });
}

document.getElementById('prevBtn').addEventListener('click', () => {
    if (currentView === 'monthly') {
        loadMonth(currentYear, currentMonth - 1);
    } else {
        shiftWeek(-7);
    }
});
document.getElementById('nextBtn').addEventListener('click', () => {
    if (currentView === 'monthly') {
        loadMonth(currentYear, currentMonth + 1);
    } else {
        shiftWeek(7);
    }
});

// ---------- VIEW SWITCH ----------
function switchView(view) {
    currentView = view;
    document.getElementById('btnMonthly').classList.toggle('active', view === 'monthly');
    document.getElementById('btnWeekly').classList.toggle('active', view === 'weekly');
    document.getElementById('monthlyView').classList.toggle('hidden', view !== 'monthly');
    document.getElementById('weeklyView').classList.toggle('hidden', view !== 'weekly');
    if (view === 'weekly') loadWeek(currentWeekStart);
}

// ---------- WEEK NAV ----------
function loadWeek(weekStart) {
    const formData = new FormData();
    formData.append('action', 'get_week');
    formData.append('week_start', weekStart);
    fetch(window.location.href, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) document.getElementById('weekGrid').innerHTML = data.html;
        });
}

function shiftWeek(days) {
    const d = new Date(currentWeekStart + 'T00:00:00');
    d.setDate(d.getDate() + days);
    currentWeekStart = d.toISOString().slice(0, 10);
    loadWeek(currentWeekStart);
}

document.getElementById('weekPrevBtn').addEventListener('click', () => shiftWeek(-7));
document.getElementById('weekNextBtn').addEventListener('click', () => shiftWeek(7));

// ---------- MANAGE DRAWER ----------
function openManagePanel() {
    document.getElementById('drawerOverlay').classList.add('open');
    document.getElementById('drawerPanel').classList.add('open');
}
function closeManagePanel() {
    document.getElementById('drawerOverlay').classList.remove('open');
    document.getElementById('drawerPanel').classList.remove('open');
}

function refreshManagePanel() {
    const formData = new FormData();
    formData.append('action', 'get_manage_panel');
    fetch(window.location.href, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) document.getElementById('whRows').innerHTML = data.html;
        });
}

function toggleDayActive(day, isActive) {
    const formData = new FormData();
    formData.append('action', 'toggle_day');
    formData.append('day', day);
    formData.append('is_active', isActive ? 1 : 0);
    fetch(window.location.href, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                loadMonth(currentYear, currentMonth);
                if (currentView === 'weekly') loadWeek(currentWeekStart);
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: data.message });
            }
        });
}

// ---------- EDIT DAY / EDIT ALL MODAL ----------
function openEditDayModal(day) {
    const row = document.querySelector('.wh-row[data-day="' + day + '"]');
    document.getElementById('hoursModalMode').value = 'single';
    document.getElementById('hoursModalDay').value = day;
    document.getElementById('hoursModalTitle').textContent = 'Edit Hours';
    document.getElementById('daysCheckboxField').style.display = 'none';
    document.getElementById('hoursStart').value = (row.dataset.start || '08:00:00').slice(0, 5);
    document.getElementById('hoursEnd').value = (row.dataset.end || '17:00:00').slice(0, 5);
    document.getElementById('hoursModal').classList.add('open');
}

function openEditAllModal() {
    document.getElementById('hoursModalMode').value = 'all';
    document.getElementById('hoursModalTitle').textContent = 'Edit All Working Hours';
    document.getElementById('daysCheckboxField').style.display = 'block';
    document.querySelectorAll('.hours-day-cb').forEach(cb => cb.checked = true);
    document.getElementById('hoursStart').value = '08:00';
    document.getElementById('hoursEnd').value = '17:00';
    document.getElementById('hoursModal').classList.add('open');
}

function closeHoursModal() {
    document.getElementById('hoursModal').classList.remove('open');
}

function saveHours() {
    const mode = document.getElementById('hoursModalMode').value;
    const start = document.getElementById('hoursStart').value;
    const end = document.getElementById('hoursEnd').value;

    if (!start || !end) {
        Swal.fire({ icon: 'warning', title: 'Missing time', text: 'Please set both start and end time.' });
        return;
    }

    const formData = new FormData();
    formData.append('action', mode === 'single' ? 'save_day_hours' : 'save_all_hours');
    formData.append('start_time', start + ':00');
    formData.append('end_time', end + ':00');

    if (mode === 'single') {
        formData.append('day', document.getElementById('hoursModalDay').value);
    } else {
        const checked = Array.from(document.querySelectorAll('.hours-day-cb:checked')).map(cb => cb.value);
        if (checked.length === 0) {
            Swal.fire({ icon: 'warning', title: 'No days selected', text: 'Select at least one day.' });
            return;
        }
        formData.append('days_json', JSON.stringify(checked));
        checked.forEach(d => formData.append('days[]', d));
    }

    Swal.fire({
        title: 'Saving...',
        text: 'Please wait',
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });

    fetch(window.location.href, { 
        method: 'POST', 
        body: formData 
    })
    .then(r => r.text().then(text => {
        try {
            return JSON.parse(text);
        } catch (e) {
            console.error('Non-JSON response from server:', text);
            throw new Error('Server returned an unexpected response. Check console for details.');
        }
    }))
    .then(data => {
        Swal.close();
        if (data.success) {
            closeHoursModal();
            refreshManagePanel();
            loadMonth(currentYear, currentMonth);
            if (currentView === 'weekly') loadWeek(currentWeekStart);
            Swal.fire({ 
                icon: 'success', 
                title: 'Saved', 
                text: data.message, 
                timer: 1500, 
                showConfirmButton: false 
            });
        } else {
            Swal.fire({ 
                icon: 'error', 
                title: 'Error', 
                text: data.message || 'Failed to save' 
            });
        }
    })
    .catch(err => {
        Swal.close();
        Swal.fire({ 
            icon: 'error', 
            title: 'Request failed', 
            text: err.message 
        });
    });
}

// ---------- DATE STATUS MODAL (Updated) ----------
function openDateModal(dateStr) {
    document.getElementById('dateModalDate').value = dateStr;
    const d = new Date(dateStr + 'T00:00:00');
    document.getElementById('dateModalTitle').textContent = 'Set Availability — ' +
        d.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' });

    const formData = new FormData();
    formData.append('action', 'get_date_status');
    formData.append('date', dateStr);

    fetch(window.location.href, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;
            
            // Set status
            document.querySelectorAll('input[name="dateStatus"]').forEach(r => {
                r.checked = (r.value === data.status);
            });
            document.getElementById('dateStart').value = (data.start_time || '08:00:00').slice(0, 5);
            document.getElementById('dateEnd').value = (data.end_time || '12:00:00').slice(0, 5);
            document.getElementById('resetDateBtn').style.display = data.has_exception ? 'inline-flex' : 'none';
            onDateStatusChange();
            
            // Show appointments
            const appointmentsSection = document.getElementById('appointmentsSection');
            const appointmentsList = document.getElementById('appointmentsList');
            
            if (data.appointment_count > 0) {
                appointmentsSection.style.display = 'block';
                let html = '';
                data.appointments.forEach(appt => {
                    const statusClass = appt.status === 'approved' ? 'appt-status-approved' : 'appt-status-confirmed';
                    html += `
                        <div class="appointment-item">
                            <div class="appt-phone"><i class="fas fa-phone"></i> ${appt.phone || 'N/A'}</div>
                            <div class="appt-email"><i class="fas fa-envelope"></i> ${appt.email || 'N/A'}</div>
                            <div class="appt-services"><i class="fas fa-tooth"></i> ${appt.services || 'No services specified'}</div>
                            <div style="margin-top:4px;">
                                <span class="appt-status ${statusClass}">${appt.status}</span>
                                <span style="font-size:10px;color:#94a3b8;margin-left:8px;">
                                    <i class="fas fa-clock"></i> ${new Date(appt.created_at).toLocaleTimeString()}
                                </span>
                            </div>
                        </div>
                    `;
                });
                appointmentsList.innerHTML = html;
            } else {
                appointmentsSection.style.display = 'block';
                appointmentsList.innerHTML = '<div class="no-appointments"><i class="fas fa-calendar-day"></i> No appointments on this day</div>';
            }
            
            document.getElementById('dateModal').classList.add('open');
        });
}

function onDateStatusChange() {
    const selected = document.querySelector('input[name="dateStatus"]:checked');
    const show = selected && selected.value === 'half_day';
    document.getElementById('halfTimeFields').classList.toggle('show', show);
}

function closeDateModal() {
    document.getElementById('dateModal').classList.remove('open');
}

function saveDateStatus() {
    const date = document.getElementById('dateModalDate').value;
    const selected = document.querySelector('input[name="dateStatus"]:checked');
    if (!selected) {
        Swal.fire({ icon: 'warning', title: 'Choose a status', text: 'Select Available, Half Day, or Unavailable.' });
        return;
    }
    const status = selected.value;
    const formData = new FormData();
    formData.append('action', 'save_date_status');
    formData.append('date', date);
    formData.append('status', status);
    if (status === 'half_day') {
        formData.append('start_time', document.getElementById('dateStart').value + ':00');
        formData.append('end_time', document.getElementById('dateEnd').value + ':00');
    }

    fetch(window.location.href, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                closeDateModal();
                loadMonth(currentYear, currentMonth);
                if (currentView === 'weekly') loadWeek(currentWeekStart);
                Swal.fire({ icon: 'success', title: 'Saved', text: data.message, timer: 1500, showConfirmButton: false });
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: data.message });
            }
        });
}

function resetDateStatus() {
    const date = document.getElementById('dateModalDate').value;
    const formData = new FormData();
    formData.append('action', 'clear_date_status');
    formData.append('date', date);

    fetch(window.location.href, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                closeDateModal();
                loadMonth(currentYear, currentMonth);
                if (currentView === 'weekly') loadWeek(currentWeekStart);
                Swal.fire({ icon: 'success', title: 'Reset', text: data.message, timer: 1500, showConfirmButton: false });
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: data.message });
            }
        });
}

// NEW: Show appointment details for a specific date
function showAppointmentDetails(dateStr) {
    Swal.fire({
        title: 'Loading appointments...',
        text: 'Please wait',
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });

    const formData = new FormData();
    formData.append('action', 'get_appointments');
    formData.append('date', dateStr);

    fetch(window.location.href, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            Swal.close();
            
            if (!data.success || data.count === 0) {
                Swal.fire({
                    icon: 'info',
                    title: 'No Appointments',
                    text: 'No appointments found for this date.'
                });
                return;
            }

            let html = `<div style="text-align:left;max-height:400px;overflow-y:auto;">`;
            
            data.appointments.forEach((appt, index) => {
                const statusClass = appt.status === 'approved' ? 'appt-status-approved' : 'appt-status-confirmed';
                const time = new Date(appt.created_at).toLocaleTimeString();
                
                html += `
                    <div style="background:#f7f9fc;padding:12px;border-radius:10px;margin-bottom:10px;border-left:3px solid #3b82f6;">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                            <strong style="color:#0b1628;">Appointment #${index + 1}</strong>
                            <span class="appt-status ${statusClass}">${appt.status}</span>
                        </div>
                        <div style="font-size:13px;color:#475569;">
                            <div><i class="fas fa-phone"></i> ${appt.phone || 'N/A'}</div>
                            <div><i class="fas fa-envelope"></i> ${appt.email || 'N/A'}</div>
                            <div><i class="fas fa-clock"></i> ${time}</div>
                            ${appt.service_names_str ? `
                                <div style="margin-top:6px;padding-top:6px;border-top:1px solid #e2e8f0;">
                                    <strong style="color:#0b1628;font-size:12px;">Services:</strong>
                                    <div style="margin-top:4px;">
                                        ${appt.service_details.map(service => `
                                            <div style="display:flex;justify-content:space-between;padding:4px 0;font-size:12px;border-bottom:1px solid #f1f5f9;">
                                                <span>${service.title}</span>
                                                <span style="font-weight:600;color:#0b1628;">$${parseFloat(service.price).toFixed(2)}</span>
                                            </div>
                                        `).join('')}
                                    </div>
                                    <div style="display:flex;justify-content:space-between;padding:6px 0;font-weight:700;color:#0b1628;border-top:2px solid #e2e8f0;margin-top:4px;">
                                        <span>Total</span>
                                        <span>$${parseFloat(appt.total_price).toFixed(2)}</span>
                                    </div>
                                </div>
                            ` : '<div style="margin-top:6px;color:#94a3b8;font-size:12px;">No services specified</div>'}
                        </div>
                    </div>
                `;
            });
            
            html += `</div>`;
            
            Swal.fire({
                title: `Appointments for ${new Date(dateStr).toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' })}`,
                html: html,
                width: 600,
                confirmButtonColor: '#0d9e8c',
                confirmButtonText: 'Close'
            });
        })
        .catch(err => {
            Swal.close();
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'Failed to load appointments: ' + err.message
            });
        });
}

// ---------- CLOSE ON ESC ----------
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeDateModal();
        closeHoursModal();
        closeManagePanel();
    }
});
document.getElementById('dateModal').addEventListener('click', function(e) { if (e.target === this) closeDateModal(); });
document.getElementById('hoursModal').addEventListener('click', function(e) { if (e.target === this) closeHoursModal(); });
</script>

</body>
</html>