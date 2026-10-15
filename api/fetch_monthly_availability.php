<?php
header('Content-Type: application/json');
require '../conn/conn.php';
date_default_timezone_set('Asia/Manila');

$month = (int)($_GET['month'] ?? date('n'));
$year  = (int)($_GET['year']  ?? date('Y'));

if ($month < 1 || $month > 12) { $month = (int)date('n'); }
if ($year < 2000 || $year > 2100) { $year = (int)date('Y'); }

// ---- Range to fetch (whole visible grid: Sun before 1st → Sat after last) ----
$firstOfMonth = new DateTime(sprintf('%04d-%02d-01', $year, $month));
$startDow     = (int)$firstOfMonth->format('w');
$gridStart    = (clone $firstOfMonth)->modify("-$startDow days");
$daysInMonth  = (int)$firstOfMonth->format('t');
$lastOfMonth  = (clone $firstOfMonth)->modify('+' . ($daysInMonth - 1) . ' days');
$endDow       = (int)$lastOfMonth->format('w');
$trailing     = 6 - $endDow;
$gridEnd      = (clone $lastOfMonth)->modify("+$trailing days");

$startStr = $gridStart->format('Y-m-d');
$endStr   = $gridEnd->format('Y-m-d');

// ---- Working hours (per weekday) ----
$whRows = $conn->query("SELECT day_of_week, is_active, start_time, end_time 
                        FROM dentist_working_hours 
                        ORDER BY day_of_week")->fetch_all(MYSQLI_ASSOC);
$workingHours = [];
foreach ($whRows as $r) {
    $workingHours[(int)$r['day_of_week']] = [
        'is_active' => (int)$r['is_active'] === 1,
        // Keep the raw string; we'll normalize it later with strtotime()
        'start'     => $r['start_time'],
        'end'       => $r['end_time']
    ];
}

// ---- Exceptions in range ----
$stmt = $conn->prepare("SELECT exception_date, status, start_time, end_time 
                        FROM dentist_availability_exceptions 
                        WHERE exception_date BETWEEN ? AND ?");
$stmt->bind_param('ss', $startStr, $endStr);
$stmt->execute();
$exRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$exceptions = [];
foreach ($exRows as $r) {
    $exceptions[$r['exception_date']] = [
        'status' => $r['status'],
        'start'  => $r['start_time'],
        'end'    => $r['end_time']
    ];
}

// ---- Build per-day map for the range ----
$todayStr = date('Y-m-d');
$days = [];
$cursor = clone $gridStart;
while ($cursor <= $gridEnd) {
    $dStr = $cursor->format('Y-m-d');
    $dow  = (int)$cursor->format('w');

    if ($dStr < $todayStr) {
        $days[$dStr] = ['status' => 'past', 'start' => null, 'end' => null];
    } elseif (isset($exceptions[$dStr])) {
        $ex = $exceptions[$dStr];
        $days[$dStr] = [
            'status' => $ex['status'],
            // FIX: Force 24-hour HH:MM format for the frontend
            'start'  => $ex['start'] ? date('H:i', strtotime($ex['start'])) : null,
            'end'    => $ex['end']   ? date('H:i', strtotime($ex['end']))   : null
        ];
    } elseif (!empty($workingHours[$dow]['is_active']) && !empty($workingHours[$dow]['start']) && !empty($workingHours[$dow]['end'])) {
        $days[$dStr] = [
            'status' => 'available',
            // FIX: Force 24-hour HH:MM format for the frontend
            'start'  => date('H:i', strtotime($workingHours[$dow]['start'])),
            'end'    => date('H:i', strtotime($workingHours[$dow]['end']))
        ];
    } else {
        $days[$dStr] = ['status' => 'unavailable', 'start' => null, 'end' => null];
    }

    $cursor->modify('+1 day');
}

// ---- Compute next available date within 90 days ----
$today = new DateTime();
$nextAvailable = null;
for ($i = 0; $i <= 90; $i++) {
    $d = (clone $today)->modify("+$i days");
    $ds = $d->format('Y-m-d');
    $dow = (int)$d->format('w');

    if (isset($exceptions[$ds])) {
        if (in_array($exceptions[$ds]['status'], ['available', 'half_day'])) {
            $nextAvailable = $ds; break;
        }
    } elseif (!empty($workingHours[$dow]['is_active'])) {
        $nextAvailable = $ds; break;
    }
}

echo json_encode([
    'success'        => true,
    'month'          => $month,
    'year'           => $year,
    'days'           => $days,
    'next_available' => $nextAvailable
]);