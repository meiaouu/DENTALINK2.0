<?php
require '../conn/conn.php';
date_default_timezone_set('Asia/Manila');

if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json');

// Not logged in → return zeros, don't leak anything
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false]);
    exit;
}

$staffId = (int)($_SESSION['user_id'] ?? 0);
$counts  = [];

// ---- Pending appointments TOTAL (bell — always shows full count, ignores "seen") ----
$res = $conn->query("SELECT COUNT(*) AS c FROM appointments WHERE status = 'pending'");
$counts['pending_total'] = (int)($res->fetch_assoc()['c'] ?? 0);

// ---- Pending appointments NEW since last view of appointments.php (parent + submenu badges) ----
$stmt = $conn->prepare("SELECT seen_appointments_at FROM users WHERE id = ?");
$stmt->bind_param('i', $staffId);
$stmt->execute();
$seenAppt = $stmt->get_result()->fetch_assoc()['seen_appointments_at'] ?? null;
$stmt->close();

if ($seenAppt) {
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM appointments WHERE status = 'pending' AND created_at > ?");
    $stmt->bind_param('s', $seenAppt);
} else {
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM appointments WHERE status = 'pending'");
}
$stmt->execute();
$counts['pending_appointments'] = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

// ---- Today's appointments NEW since last view of today.php ----
$today = date('Y-m-d');

$stmt = $conn->prepare("SELECT seen_today_at FROM users WHERE id = ?");
$stmt->bind_param('i', $staffId);
$stmt->execute();
$seenToday = $stmt->get_result()->fetch_assoc()['seen_today_at'] ?? null;
$stmt->close();

if ($seenToday) {
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM appointments WHERE appointment_date = ? AND created_at > ?");
    $stmt->bind_param('ss', $today, $seenToday);
} else {
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM appointments WHERE appointment_date = ?");
    $stmt->bind_param('s', $today);
}
$stmt->execute();
$counts['today_appointments'] = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

// ---- Unread patient chat messages ----
$res = $conn->query("
    SELECT COUNT(*) AS c FROM chat_thread_messages m
    JOIN chat_conversations c ON c.id = m.conversation_id
    WHERE m.sender_role = 'patient' AND m.is_read = 0
");
$counts['patient_chat'] = (int)($res->fetch_assoc()['c'] ?? 0);

// ---- Unread guest chat messages ----
$res = $conn->query("SELECT COUNT(*) AS c FROM chat_messages WHERE sender = 'guest' AND is_read = 0");
$counts['guest_chat'] = (int)($res->fetch_assoc()['c'] ?? 0);

// ---- Unread team DMs + channel (only for this staff member) ----
$stmt = $conn->prepare("
    SELECT COUNT(*) AS c FROM staff_dm_messages m
    JOIN staff_dm_conversations d ON d.id = m.conversation_id
    WHERE (d.user_one_id = ? OR d.user_two_id = ?) AND m.sender_id != ? AND m.is_read = 0
");
$stmt->bind_param('iii', $staffId, $staffId, $staffId);
$stmt->execute();
$dmUnread = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

$stmt = $conn->prepare("SELECT last_read_message_id FROM staff_channel_reads WHERE user_id = ?");
$stmt->bind_param('i', $staffId);
$stmt->execute();
$lastRead = (int)($stmt->get_result()->fetch_assoc()['last_read_message_id'] ?? 0);
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM staff_channel_messages WHERE id > ? AND sender_id != ?");
$stmt->bind_param('ii', $lastRead, $staffId);
$stmt->execute();
$channelUnread = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

$counts['team_chat'] = $dmUnread + $channelUnread;

// ---- Unverified pending appointments ----
$res = $conn->query("SELECT COUNT(*) AS c FROM appointments WHERE status = 'pending' AND is_verified = 0");
$counts['unverified'] = (int)($res->fetch_assoc()['c'] ?? 0);

// ---- Pending list (only when the sidebar bell asks for it) ----
$response = ['success' => true, 'counts' => $counts];

if (isset($_GET['with_pending']) && $_GET['with_pending'] === '1') {
    $pendingList = [];

    $stmt = $conn->prepare("
        SELECT id, full_name, appointment_date, appointment_time
        FROM appointments
        WHERE status = 'pending'
        ORDER BY appointment_date ASC, appointment_time ASC, id ASC
        LIMIT 8
    ");
    $stmt->execute();
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $pendingList[] = $row;
    }
    $stmt->close();

    $response['pending_list'] = $pendingList;
}

// ---- Return ----
echo json_encode($response);