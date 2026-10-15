<?php
/**
 * Guest-facing chat API.
 * Handles: sending a guest message (bot/hybrid/human modes) and polling for staff replies.
 *
 * Tables used (already in your dental_clinic DB):
 *   chat_sessions(id, guest_id, status, assigned_to, last_message_at, created_at)
 *   chat_messages(id, guest_id, sender, message, mode, is_read, is_replied, staff_reply_id, ip_address, user_agent, created_at)
 *   clinic_settings, services  -> used to build bot answers
 */
 
header('Content-Type: application/json; charset=utf-8');
require '../conn/conn.php'; // must expose mysqli connection as $conn

function json_out($data) {
    echo json_encode($data);
    exit;
}

$raw   = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) $input = [];

$action = $input['action'] ?? ($_GET['action'] ?? 'send');

if ($action === 'poll') {
    poll_messages($conn, array_merge($_GET, $input));
} elseif ($action === 'history') {
    get_history($conn, array_merge($_GET, $input));
} elseif ($action === 'mark_read') {
    mark_read($conn, array_merge($_GET, $input));
} elseif ($action === 'clear') {
    clear_history($conn, array_merge($_GET, $input));
} else {
    send_message($conn, $input);
}

// ----------------------------------------------------------------------
// HISTORY — returns full conversation WITHOUT marking anything as read.
// The client decides what's "unread" using the is_read flag returned here.
// ----------------------------------------------------------------------
function get_history($conn, $params) {
    $guestId = $params['guest_id'] ?? '';
    if ($guestId === '') json_out(['success' => true, 'messages' => []]);

    $stmt = $conn->prepare("SELECT id, sender, message, is_read, created_at FROM chat_messages WHERE guest_id = ? ORDER BY id ASC");
    $stmt->bind_param('s', $guestId);
    $stmt->execute();
    $res = $stmt->get_result();
    $messages = [];
    while ($row = $res->fetch_assoc()) $messages[] = $row;

    // NOTE: intentionally NOT marking messages as read here anymore.
    // Loading history (e.g. on page refresh) is not the same as the guest
    // actually opening/viewing the chat window. See mark_read() below,
    // which the frontend calls only when the chat window is opened.

    json_out(['success' => true, 'messages' => $messages]);
}
// ----------------------------------------------------------------------
// CLEAR HISTORY — permanently deletes this guest's messages so a refresh
// won't bring them back. Also resets their session status.
// ----------------------------------------------------------------------
function clear_history($conn, $params) {
    $guestId = $params['guest_id'] ?? '';
    if ($guestId === '') json_out(['success' => false, 'message' => 'Missing guest_id']);

    $esc = $conn->real_escape_string($guestId);
    $conn->query("DELETE FROM chat_messages WHERE guest_id = '{$esc}'");
    $conn->query("UPDATE chat_sessions SET status = 'active', assigned_to = NULL WHERE guest_id = '{$esc}'");

    json_out(['success' => true]);
}
// ----------------------------------------------------------------------
// MARK READ — called explicitly by the frontend when the guest opens
// the chat window (i.e. they are actually looking at the messages now).
// ----------------------------------------------------------------------
function mark_read($conn, $params) {
    $guestId = $params['guest_id'] ?? '';
    if ($guestId === '') json_out(['success' => false, 'message' => 'Missing guest_id']);

    $esc = $conn->real_escape_string($guestId);
    $conn->query("UPDATE chat_messages SET is_read = 1 WHERE guest_id = '{$esc}'");

    json_out(['success' => true]);
}

// ----------------------------------------------------------------------
function get_client_ip() {
    return $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
}

function get_or_create_session($conn, $guestId) {
    if (!empty($guestId)) {
        $stmt = $conn->prepare("SELECT guest_id, status, assigned_to FROM chat_sessions WHERE guest_id = ? LIMIT 1");
        $stmt->bind_param('s', $guestId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row) return $row;
    }
    $newId = 'guest_' . substr(md5(uniqid((string)mt_rand(), true)), 0, 13) . '_' . time();
    $stmt = $conn->prepare("INSERT INTO chat_sessions (guest_id, status, created_at, last_message_at) VALUES (?, 'active', NOW(), NOW())");
    $stmt->bind_param('s', $newId);
    $stmt->execute();
    return ['guest_id' => $newId, 'status' => 'active', 'assigned_to' => null];
}

function send_message($conn, $input) {
    $message = trim($input['message'] ?? '');
    $mode    = $input['mode'] ?? 'hybrid';
    $guestId = $input['guest_id'] ?? '';

    if ($message === '') json_out(['success' => false, 'message' => 'Empty message.']);
    if (!in_array($mode, ['bot', 'hybrid', 'human'], true)) $mode = 'hybrid';

    $session = get_or_create_session($conn, $guestId);
    $guestId = $session['guest_id'];
    $ip = get_client_ip();
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

    // Save the guest's message
    $stmt = $conn->prepare("INSERT INTO chat_messages (guest_id, sender, message, mode, is_read, is_replied, ip_address, user_agent, created_at) VALUES (?, 'guest', ?, ?, 0, 0, ?, ?, NOW())");
    $stmt->bind_param('sssss', $guestId, $message, $mode, $ip, $ua);
    $stmt->execute();

    $esc = $conn->real_escape_string($guestId);
    $conn->query("UPDATE chat_sessions SET last_message_at = NOW() WHERE guest_id = '{$esc}'");

    // Decide whether a human is needed for this message
    $staffHasTakenOver = !empty($session['assigned_to']) || $session['status'] === 'staff_assigned';
    $needsStaff = ($mode === 'human') || ($mode === 'hybrid' && $staffHasTakenOver);

    if ($needsStaff) {
        if ($session['status'] !== 'staff_assigned') {
            $conn->query("UPDATE chat_sessions SET status = 'pending_staff' WHERE guest_id = '{$esc}'");
        }
        $ack = "Thanks! I've forwarded your message to our staff \xE2\x80\x94 they'll reply here shortly. \xF0\x9F\x99\x8F";
        json_out(['success' => true, 'response' => $ack, 'guest_id' => $guestId, 'awaiting_staff' => true]);
    }

    // Otherwise, answer with the bot
    $botReply = generate_bot_response($conn, $message);
    $stmt = $conn->prepare("INSERT INTO chat_messages (guest_id, sender, message, mode, is_read, is_replied, ip_address, user_agent, created_at) VALUES (?, 'bot', ?, ?, 1, 1, ?, ?, NOW())");
    $stmt->bind_param('sssss', $guestId, $botReply, $mode, $ip, $ua);
    $stmt->execute();

    json_out(['success' => true, 'response' => $botReply, 'guest_id' => $guestId]);
}

function poll_messages($conn, $params) {
    $guestId = $params['guest_id'] ?? '';
    $afterId = (int)($params['after_id'] ?? 0);
    if ($guestId === '') json_out(['success' => false, 'message' => 'Missing guest_id']);

    // Only staff/admin replies are polled — bot replies already return synchronously above.
    $stmt = $conn->prepare("SELECT id, sender, message, created_at FROM chat_messages WHERE guest_id = ? AND id > ? AND sender IN ('staff','admin') ORDER BY id ASC");
    $stmt->bind_param('si', $guestId, $afterId);
    $stmt->execute();
    $res = $stmt->get_result();
    $messages = [];
    while ($row = $res->fetch_assoc()) $messages[] = $row;

    // NOTE: no longer force-marking these as read here either — polling can
    // happen while the chat window is closed, so it shouldn't silently
    // consume the unread badge. The frontend's own unread-badge logic
    // (incremented in addChatMessage when the window is closed) handles
    // this, and mark_read() clears it once the guest actually opens the chat.

    json_out(['success' => true, 'messages' => $messages]);
}

// ----------------------------------------------------------------------
// Rule-based bot: pulls real answers from clinic_settings / services
// ----------------------------------------------------------------------
function generate_bot_response($conn, $message) {
    $msg = strtolower($message);
    $settings = $conn->query("SELECT * FROM clinic_settings WHERE id = 1")->fetch_assoc() ?: [];

    if (preg_match('/\b(hi|hello|hey|kumusta|good (morning|afternoon|evening))\b/', $msg)) {
        return "Hello! \xF0\x9F\x98\x8A I'm your virtual dental assistant. Ask me about our services, prices, hours, or location \xE2\x80\x94 or type \"book\" to start an appointment.";
    }

    if (preg_match('/\b(gago|kupal|bobo|tanga|inutil)\b/', $msg)) {
        return "tantadoooooo! \xF0\x9F\x98\x8A abay gago palamunin ka nga eh \xE2\x80\x94 ";
    }
       if (preg_match('/\b(sorry|pasensya|paumanhin)\b/', $msg)) {
        return "yannnn! \xF0\x9F\x98\x8A matutuo ka mag sorry bobo ka \xE2\x80\x94  ";
    }
    
       if (preg_match('/\b(sorry|pasensya|paumanhin)\b/', $msg)) {
        return "yannnn! \xF0\x9F\x98\x8A matutuo ka mag sorry bobo ka \xE2\x80\x94  ";
    }

    if (preg_match('/\b(hours?|open|closing|closed|close|schedules?|available)\b/', $msg)) {
        $hours = $settings['clinic_hours'] ?? 'Monday to Saturday, 8:00 AM - 5:00 PM';
        return "\xF0\x9F\x8F\xA5 We're open **{$hours}**. Feel free to book a slot that works for you!";
    }

    if (preg_match('/\b(locations?|address(es)?|where|directions?)\b/', $msg)) {
        $loc = $settings['location'] ?? 'our clinic';
        return "\xF0\x9F\x93\x8D You can find us at **{$loc}**.";
    }

    if (preg_match('/\b(phones?|contact(s|us)?|calls?|numbers?|emails?)\b/', $msg)) {
        $phone = $settings['phone'] ?? '';
        $email = $settings['email'] ?? '';
        return "\xF0\x9F\x93\x9E You can reach us at **{$phone}** or email **{$email}**.";
    }

    if (preg_match('/\b(books?|appointments?|schedule an?|reserve|reservations?)\b/', $msg)) {
        return "\xF0\x9F\x93\x85 Great! Click the **Book Appointment** button on the site \xE2\x80\x94 you'll pick a service, verify with an OTP, then choose your schedule.";
    }

    if (preg_match('/\b(prices?|costs?|how much|fees?|pricing?|rates?)\b/', $msg)) {
        $services = $conn->query("SELECT title, price FROM services WHERE is_active = 1 ORDER BY sort_order ASC")->fetch_all(MYSQLI_ASSOC);
        foreach ($services as $s) {
            if (stripos($msg, strtolower($s['title'])) !== false) {
                return "\xF0\x9F\x92\xB0 **{$s['title']}** costs \xE2\x82\xB1" . number_format((float)$s['price'], 2) . ".";
            }
        }
        if ($services) {
            $lines = array_map(fn($s) => "\xE2\x80\xA2 {$s['title']} \xE2\x80\x94 \xE2\x82\xB1" . number_format((float)$s['price'], 2), array_slice($services, 0, 6));
            return "\xF0\x9F\x92\xB0 Here are some of our service prices:\n" . implode("\n", $lines) . "\n\nAsk me about a specific service for its exact price!";
        }
        return "I don't have pricing info handy right now \xE2\x80\x94 please contact the clinic directly for exact rates.";
    }

    if (preg_match('/\b(services?|treatments?|offers?|procedures?)\b/', $msg)) {
        $services = $conn->query("SELECT title FROM services WHERE is_active = 1 ORDER BY sort_order ASC")->fetch_all(MYSQLI_ASSOC);
        if ($services) {
            $lines = array_map(fn($s) => "\xE2\x80\xA2 {$s['title']}", $services);
            return "\xF0\x9F\xA6\xB7 Here's what we offer:\n" . implode("\n", $lines) . "\n\nWant pricing or details on any of these? Just ask!";
        }
        return "We offer a range of dental services \xE2\x80\x94 check the Services section on our homepage for details.";
    }

    // Direct service-name match fallback (e.g. "tell me about teeth whitening")
    $services = $conn->query("SELECT title, description, duration, price FROM services WHERE is_active = 1")->fetch_all(MYSQLI_ASSOC);
    foreach ($services as $s) {
        if (stripos($msg, strtolower($s['title'])) !== false) {
            return "\xF0\x9F\xA6\xB7 **{$s['title']}** ({$s['duration']}) \xE2\x80\x94 \xE2\x82\xB1" . number_format((float)$s['price'], 2) . ". " . $s['description'];
        }
    }

    return "I'm not fully sure about that one \xF0\x9F\xA4\x94 \xE2\x80\x94 switch to **Staff** mode above and a team member will help you directly, or ask me about services, prices, hours, or location.";
}