<?php
require '../../conn/conn.php';
require '../../api/chat_upload.php';
require '../../functions/audit_log.php';

// ---- Auth: staff must be logged in ----
if (!isset($_SESSION['staff_logged_in']) || $_SESSION['staff_logged_in'] !== true) {
    header('Location: ../../index.php');
    exit;
}

$staffId   = (int)($_SESSION['staff_id'] ?? 0);
$staffName = $_SESSION['staff_name'] ?? 'Staff';

function touchPresence(mysqli $conn, int $userId, string $role): void
{
    $stmt = $conn->prepare("INSERT INTO chat_presence (user_id, role, last_seen_at) VALUES (?, ?, NOW())
                             ON DUPLICATE KEY UPDATE last_seen_at = NOW(), role = VALUES(role)");
    $stmt->bind_param('is', $userId, $role);
    $stmt->execute();
}

// ============================================================
// AJAX endpoints
// PATIENT (account-based) actions keep their original names:
//   list_conversations, get_messages, typing, send_reply,
//   close_conversation, reopen_conversation
// GUEST (landing page, no account) actions are prefixed with
// "guest_" so they never collide with the patient ones above:
//   list_sessions, get_guest_messages, send_guest_reply,
//   close_guest_session
// ============================================================
if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    touchPresence($conn, $staffId, 'staff');

    // ---------------- PATIENT (account) chat ----------------

    if ($_GET['action'] === 'list_conversations') {
        $sql = "SELECT c.id, c.patient_id, c.status, c.assigned_to, c.assigned_role, c.last_message_at,
                       u.full_name AS patient_name,
                       (SELECT message FROM chat_thread_messages m WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_message,
                       (SELECT sender_role FROM chat_thread_messages m WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_sender,
                       (SELECT COUNT(*) FROM chat_thread_messages m WHERE m.conversation_id = c.id AND m.sender_role = 'patient' AND m.is_read = 0) AS unread,
                       (SELECT last_seen_at FROM chat_presence WHERE user_id = c.patient_id AND role = 'patient') AS patient_last_seen
                FROM chat_conversations c
                LEFT JOIN users u ON u.id = c.patient_id
                ORDER BY (c.status = 'closed') ASC, c.last_message_at DESC";
        $res = $conn->query($sql);
        $conversations = [];
        while ($row = $res->fetch_assoc()) {
            $row['patient_online'] = !empty($row['patient_last_seen']) && strtotime($row['patient_last_seen']) >= time() - 30;
            unset($row['patient_last_seen']);
            $conversations[] = $row;
        }
        echo json_encode(['success' => true, 'conversations' => $conversations]);
        exit;
    }

    if ($_GET['action'] === 'get_messages') {
        $conversationId = (int)($_GET['conversation_id'] ?? 0);
        if ($conversationId <= 0) { echo json_encode(['success' => false]); exit; }

        $stmt = $conn->prepare("SELECT id, sender_role, message, attachment, attachment_type, created_at
                                 FROM chat_thread_messages WHERE conversation_id = ? ORDER BY id ASC");
        $stmt->bind_param('i', $conversationId);
        $stmt->execute();
        $res = $stmt->get_result();
        $messages = [];
        while ($row = $res->fetch_assoc()) $messages[] = $row;

        $stmt = $conn->prepare("UPDATE chat_conversations SET staff_last_read_at = NOW(), staff_typing_at = NULL WHERE id = ?");
        $stmt->bind_param('i', $conversationId);
        $stmt->execute();

        $stmt = $conn->prepare("SELECT patient_id, patient_typing_at, patient_last_read_at FROM chat_conversations WHERE id = ?");
        $stmt->bind_param('i', $conversationId);
        $stmt->execute();
        $conv = $stmt->get_result()->fetch_assoc() ?: [];


        $stmt = $conn->prepare("UPDATE chat_thread_messages SET is_read = 1 WHERE conversation_id = ? AND sender_role = 'patient' AND is_read = 0");
        $stmt->bind_param('i', $conversationId);
        $stmt->execute();

        $patientOnline = false;
        if (!empty($conv['patient_id'])) {
            $stmt = $conn->prepare("SELECT 1 FROM chat_presence WHERE user_id = ? AND role = 'patient' AND last_seen_at >= NOW() - INTERVAL 30 SECOND");
            $stmt->bind_param('i', $conv['patient_id']);
            $stmt->execute();
            $patientOnline = (bool)$stmt->get_result()->fetch_assoc();
        }

        $patientTyping = !empty($conv['patient_typing_at']) && strtotime($conv['patient_typing_at']) >= time() - 5;

        echo json_encode([
            'success' => true,
            'messages' => $messages,
            'patient_online' => $patientOnline,
            'patient_typing' => $patientTyping,
            'patient_last_read_at' => $conv['patient_last_read_at'] ?? null,
        ]);
        exit;
    }

    if ($_GET['action'] === 'typing' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $conversationId = (int)($_POST['conversation_id'] ?? 0);
        if ($conversationId > 0) {
            $stmt = $conn->prepare("UPDATE chat_conversations SET staff_typing_at = NOW() WHERE id = ?");
            $stmt->bind_param('i', $conversationId);
            $stmt->execute();
        }
        echo json_encode(['success' => true]);
        exit;
    }

    if ($_GET['action'] === 'send_reply' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $conversationId = (int)($_POST['conversation_id'] ?? 0);
        $message = trim($_POST['message'] ?? '');

        if ($conversationId <= 0) { echo json_encode(['success' => false, 'message' => 'Missing conversation']); exit; }

        $attachment = null;
        $attachmentType = null;
        try {
            $upload = handleChatImageUpload();
            if ($upload) {
                $attachment = $upload['path'];
                $attachmentType = $upload['type'];
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }

        if ($message === '' && !$attachment) {
            echo json_encode(['success' => false, 'message' => 'Message is empty']);
            exit;
        }

        $stmt = $conn->prepare("INSERT INTO chat_thread_messages
                                 (conversation_id, sender_role, sender_id, message, attachment, attachment_type, is_read, created_at)
                                 VALUES (?, 'staff', ?, ?, ?, ?, 1, NOW())");
        $stmt->bind_param('iisss', $conversationId, $staffId, $message, $attachment, $attachmentType);
        $stmt->execute();

        $stmt = $conn->prepare("UPDATE chat_conversations
                                 SET status = 'staff_assigned', assigned_to = ?, assigned_role = 'staff',
                                     last_message_at = NOW(), staff_last_read_at = NOW(), staff_typing_at = NULL
                                 WHERE id = ?");
        $stmt->bind_param('ii', $staffId, $conversationId);
        $stmt->execute();

        log_staff_activity(
            $conn, 'create', 'chat',
            "Replied to patient conversation #{$conversationId}",
            'chat_conversation', $conversationId
        );

        echo json_encode(['success' => true]);
        exit;
    }

    if ($_GET['action'] === 'close_conversation' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $conversationId = (int)($input['conversation_id'] ?? 0);
        $stmt = $conn->prepare("UPDATE chat_conversations SET status = 'closed' WHERE id = ?");
        $stmt->bind_param('i', $conversationId);
        $stmt->execute();

        log_staff_activity(
            $conn, 'status_change', 'chat',
            "Closed patient conversation #{$conversationId}",
            'chat_conversation', $conversationId, null, ['status' => 'closed']
        );

        echo json_encode(['success' => true]);
        exit;
    }

    if ($_GET['action'] === 'reopen_conversation' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $conversationId = (int)($input['conversation_id'] ?? 0);
        $stmt = $conn->prepare("UPDATE chat_conversations SET status = 'open' WHERE id = ?");
        $stmt->bind_param('i', $conversationId);
        $stmt->execute();

        log_staff_activity(
            $conn, 'status_change', 'chat',
            "Reopened patient conversation #{$conversationId}",
            'chat_conversation', $conversationId, null, ['status' => 'open']
        );

        echo json_encode(['success' => true]);
        exit;
    }

    // ---------------- GUEST (no account, landing page) chat ----------------

    if ($_GET['action'] === 'list_sessions') {
        $sql = "SELECT s.guest_id, s.status, s.assigned_to, s.last_message_at,
                       (SELECT message FROM chat_messages m WHERE m.guest_id = s.guest_id ORDER BY m.id DESC LIMIT 1) AS last_message,
                       (SELECT COUNT(*) FROM chat_messages m WHERE m.guest_id = s.guest_id AND m.sender = 'guest' AND m.is_read = 0) AS unread
                FROM chat_sessions s
                ORDER BY s.last_message_at DESC";
        $res = $conn->query($sql);
        $sessions = [];
        while ($row = $res->fetch_assoc()) $sessions[] = $row;
        echo json_encode(['success' => true, 'sessions' => $sessions]);
        exit;
    }

    if ($_GET['action'] === 'get_guest_messages') {
        $guestId = $_GET['guest_id'] ?? '';
        if ($guestId === '') { echo json_encode(['success' => false]); exit; }
        $stmt = $conn->prepare("SELECT id, sender, message, mode, created_at FROM chat_messages WHERE guest_id = ? ORDER BY id ASC");
        $stmt->bind_param('s', $guestId);
        $stmt->execute();
        $res = $stmt->get_result();
        $messages = [];
        while ($row = $res->fetch_assoc()) $messages[] = $row;

        $stmt = $conn->prepare("UPDATE chat_messages SET is_read = 1 WHERE guest_id = ? AND sender = 'guest'");
        $stmt->bind_param('s', $guestId);
        $stmt->execute();

        echo json_encode(['success' => true, 'messages' => $messages]);
        exit;
    }

    if ($_GET['action'] === 'send_guest_reply' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $guestId = $input['guest_id'] ?? '';
        $message = trim($input['message'] ?? '');
        if ($guestId === '' || $message === '') { echo json_encode(['success' => false, 'message' => 'Missing data']); exit; }

        $stmt = $conn->prepare("INSERT INTO chat_messages (guest_id, sender, message, mode, is_read, is_replied, created_at) VALUES (?, 'staff', ?, 'human', 1, 1, NOW())");
        $stmt->bind_param('ss', $guestId, $message);
        $stmt->execute();

        $stmt = $conn->prepare("UPDATE chat_sessions SET status = 'staff_assigned', last_message_at = NOW() WHERE guest_id = ?");
        $stmt->bind_param('s', $guestId);
        $stmt->execute();

        $stmt = $conn->prepare("UPDATE chat_messages SET is_replied = 1 WHERE guest_id = ? AND sender = 'guest'");
        $stmt->bind_param('s', $guestId);
        $stmt->execute();

        log_staff_activity(
            $conn, 'create', 'chat',
            "Replied to guest chat {$guestId}",
            'guest_chat_session', null
        );

        echo json_encode(['success' => true]);
        exit;
    }

    if ($_GET['action'] === 'close_guest_session' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $guestId = $input['guest_id'] ?? '';
        $stmt = $conn->prepare("UPDATE chat_sessions SET status = 'closed' WHERE guest_id = ?");
        $stmt->bind_param('s', $guestId);
        $stmt->execute();

        log_staff_activity(
            $conn, 'status_change', 'chat',
            "Closed guest chat {$guestId}",
            'guest_chat_session', null, null, ['status' => 'closed']
        );

        echo json_encode(['success' => true]);
        exit;
    }

    // ---------------- TEAM CHAT (staff/admin talking to each other) ----------------
    // Actions are prefixed "team_" so they never collide with the
    // patient or guest actions above:
    //   team_list_staff, team_list_dms, team_open_dm, team_get_dm_messages,
    //   team_send_dm, team_get_channel_messages, team_send_channel_message

    function getOrCreateDmConversationId(mysqli $conn, int $userA, int $userB): int
    {
        $lo = min($userA, $userB);
        $hi = max($userA, $userB);
        $stmt = $conn->prepare("SELECT id FROM staff_dm_conversations WHERE user_one_id = ? AND user_two_id = ?");
        $stmt->bind_param('ii', $lo, $hi);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row) return (int)$row['id'];

        $stmt = $conn->prepare("INSERT INTO staff_dm_conversations (user_one_id, user_two_id, last_message_at) VALUES (?, ?, NOW())");
        $stmt->bind_param('ii', $lo, $hi);
        $stmt->execute();
        return (int)$conn->insert_id;
    }

    // Directory of colleagues (everyone with role staff/admin, excluding yourself)
    if ($_GET['action'] === 'team_list_staff') {
        $stmt = $conn->prepare("SELECT u.id, u.full_name, u.role,
                (SELECT last_seen_at FROM chat_presence WHERE user_id = u.id AND role IN ('staff','admin')) AS last_seen
                FROM users u
                WHERE u.role IN ('staff','admin') AND u.id != ? AND u.is_active = 1
                ORDER BY u.full_name ASC");
        $stmt->bind_param('i', $staffId);
        $stmt->execute();
        $res = $stmt->get_result();
        $list = [];
        while ($row = $res->fetch_assoc()) {
            $row['online'] = !empty($row['last_seen']) && strtotime($row['last_seen']) >= time() - 30;
            unset($row['last_seen']);
            $list[] = $row;
        }
        echo json_encode(['success' => true, 'staff' => $list]);
        exit;
    }

    // Your existing DM threads, newest first, with the other person's name/role/online + unread count
    if ($_GET['action'] === 'team_list_dms') {
        $stmt = $conn->prepare("
            SELECT x.id, x.last_message_at, x.other_id, x.last_message, x.last_sender_id, x.unread,
                   u.full_name AS other_name, u.role AS other_role,
                   (SELECT last_seen_at FROM chat_presence WHERE user_id = x.other_id AND role IN ('staff','admin')) AS other_last_seen
            FROM (
                SELECT d.id, d.last_message_at,
                       IF(d.user_one_id = ?, d.user_two_id, d.user_one_id) AS other_id,
                       (SELECT message FROM staff_dm_messages m WHERE m.conversation_id = d.id ORDER BY m.id DESC LIMIT 1) AS last_message,
                       (SELECT sender_id FROM staff_dm_messages m WHERE m.conversation_id = d.id ORDER BY m.id DESC LIMIT 1) AS last_sender_id,
                       (SELECT COUNT(*) FROM staff_dm_messages m WHERE m.conversation_id = d.id AND m.sender_id != ? AND m.is_read = 0) AS unread
                FROM staff_dm_conversations d
                WHERE d.user_one_id = ? OR d.user_two_id = ?
            ) x
            LEFT JOIN users u ON u.id = x.other_id
            ORDER BY x.last_message_at DESC");
        $stmt->bind_param('iiii', $staffId, $staffId, $staffId, $staffId);
        $stmt->execute();
        $res = $stmt->get_result();
        $dms = [];
        while ($row = $res->fetch_assoc()) {
            $row['other_online'] = !empty($row['other_last_seen']) && strtotime($row['other_last_seen']) >= time() - 30;
            unset($row['other_last_seen']);
            $dms[] = $row;
        }
        echo json_encode(['success' => true, 'dms' => $dms]);
        exit;
    }

    // Click a colleague in the directory -> get or create the DM thread with them
    if ($_GET['action'] === 'team_open_dm') {
        $targetId = (int)($_GET['user_id'] ?? 0);
        if ($targetId <= 0 || $targetId === $staffId) { echo json_encode(['success' => false, 'message' => 'Invalid user']); exit; }
        $conversationId = getOrCreateDmConversationId($conn, $staffId, $targetId);
        echo json_encode(['success' => true, 'conversation_id' => $conversationId]);
        exit;
    }

    if ($_GET['action'] === 'team_get_dm_messages') {
        $conversationId = (int)($_GET['conversation_id'] ?? 0);
        if ($conversationId <= 0) { echo json_encode(['success' => false]); exit; }

        // Make sure the requesting user is actually part of this conversation
        $stmt = $conn->prepare("SELECT user_one_id, user_two_id FROM staff_dm_conversations WHERE id = ?");
        $stmt->bind_param('i', $conversationId);
        $stmt->execute();
        $conv = $stmt->get_result()->fetch_assoc();
        if (!$conv || ($conv['user_one_id'] != $staffId && $conv['user_two_id'] != $staffId)) {
            echo json_encode(['success' => false, 'message' => 'Not your conversation']);
            exit;
        }

        $stmt = $conn->prepare("SELECT id, sender_id, message, attachment, attachment_type, created_at
                                 FROM staff_dm_messages WHERE conversation_id = ? ORDER BY id ASC");
        $stmt->bind_param('i', $conversationId);
        $stmt->execute();
        $res = $stmt->get_result();
        $messages = [];
        while ($row = $res->fetch_assoc()) $messages[] = $row;

        // Mark the other person's messages as read now that we've viewed the thread
        $stmt = $conn->prepare("UPDATE staff_dm_messages SET is_read = 1 WHERE conversation_id = ? AND sender_id != ?");
        $stmt->bind_param('ii', $conversationId, $staffId);
        $stmt->execute();

        echo json_encode(['success' => true, 'messages' => $messages]);
        exit;
    }

    if ($_GET['action'] === 'team_send_dm' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $conversationId = (int)($_POST['conversation_id'] ?? 0);
        $message = trim($_POST['message'] ?? '');
        if ($conversationId <= 0) { echo json_encode(['success' => false, 'message' => 'Missing conversation']); exit; }

        $stmt = $conn->prepare("SELECT user_one_id, user_two_id FROM staff_dm_conversations WHERE id = ?");
        $stmt->bind_param('i', $conversationId);
        $stmt->execute();
        $conv = $stmt->get_result()->fetch_assoc();
        if (!$conv || ($conv['user_one_id'] != $staffId && $conv['user_two_id'] != $staffId)) {
            echo json_encode(['success' => false, 'message' => 'Not your conversation']);
            exit;
        }

        $attachment = null;
        $attachmentType = null;
        try {
            $upload = handleChatImageUpload();
            if ($upload) {
                $attachment = $upload['path'];
                $attachmentType = $upload['type'];
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }

        if ($message === '' && !$attachment) {
            echo json_encode(['success' => false, 'message' => 'Message is empty']);
            exit;
        }

        $stmt = $conn->prepare("INSERT INTO staff_dm_messages
                                 (conversation_id, sender_id, message, attachment, attachment_type, is_read, created_at)
                                 VALUES (?, ?, ?, ?, ?, 0, NOW())");
        $stmt->bind_param('iisss', $conversationId, $staffId, $message, $attachment, $attachmentType);
        $stmt->execute();

        $stmt = $conn->prepare("UPDATE staff_dm_conversations SET last_message_at = NOW() WHERE id = ?");
        $stmt->bind_param('i', $conversationId);
        $stmt->execute();

        echo json_encode(['success' => true]);
        exit;
    }

    // Shared channel — everyone with role staff/admin reads and posts to the same feed
    if ($_GET['action'] === 'team_get_channel_messages') {
        $res = $conn->query("SELECT m.id, m.sender_id, m.message, m.attachment, m.attachment_type, m.created_at, u.full_name AS sender_name, u.role AS sender_role
                              FROM staff_channel_messages m
                              LEFT JOIN users u ON u.id = m.sender_id
                              ORDER BY m.id ASC
                              LIMIT 200");
        $messages = [];
        $maxId = 0;
        while ($row = $res->fetch_assoc()) {
            $messages[] = $row;
            if ((int)$row['id'] > $maxId) $maxId = (int)$row['id'];
        }

        // Remember that this user has now seen up to the latest message
        $stmt = $conn->prepare("INSERT INTO staff_channel_reads (user_id, last_read_message_id) VALUES (?, ?)
                                 ON DUPLICATE KEY UPDATE last_read_message_id = GREATEST(last_read_message_id, VALUES(last_read_message_id))");
        $stmt->bind_param('ii', $staffId, $maxId);
        $stmt->execute();

        echo json_encode(['success' => true, 'messages' => $messages]);
        exit;
    }

    if ($_GET['action'] === 'team_send_channel_message' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $message = trim($_POST['message'] ?? '');

        $attachment = null;
        $attachmentType = null;
        try {
            $upload = handleChatImageUpload();
            if ($upload) {
                $attachment = $upload['path'];
                $attachmentType = $upload['type'];
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }

        if ($message === '' && !$attachment) {
            echo json_encode(['success' => false, 'message' => 'Message is empty']);
            exit;
        }

        $stmt = $conn->prepare("INSERT INTO staff_channel_messages (sender_id, message, attachment, attachment_type, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->bind_param('isss', $staffId, $message, $attachment, $attachmentType);
        $stmt->execute();

        echo json_encode(['success' => true]);
        exit;
    }

    // How many unread items live under the Team Chat tab right now (DMs + channel combined)
    if ($_GET['action'] === 'team_unread_count') {
        $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM staff_dm_messages m
                                 JOIN staff_dm_conversations d ON d.id = m.conversation_id
                                 WHERE (d.user_one_id = ? OR d.user_two_id = ?) AND m.sender_id != ? AND m.is_read = 0");
        $stmt->bind_param('iii', $staffId, $staffId, $staffId);
        $stmt->execute();
        $dmUnread = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);

        $stmt = $conn->prepare("SELECT last_read_message_id FROM staff_channel_reads WHERE user_id = ?");
        $stmt->bind_param('i', $staffId);
        $stmt->execute();
        $lastRead = (int)($stmt->get_result()->fetch_assoc()['last_read_message_id'] ?? 0);

        $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM staff_channel_messages WHERE id > ? AND sender_id != ?");
        $stmt->bind_param('ii', $lastRead, $staffId);
        $stmt->execute();
        $channelUnread = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);

        echo json_encode(['success' => true, 'dm_unread' => $dmUnread, 'channel_unread' => $channelUnread]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Staff Chat — Dental Clinic</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="../../assets/chat/messenger.css">
<style>
  /* Tab bar */
  .chat-tabs {
      display: flex;
      background: #fff;
      border-bottom: 1px solid #e5e7eb;
  }
  .chat-tab-btn {
      flex: 1;
      padding: 12px 10px;
      font-size: 13px;
      font-weight: 700;
      color: #65676b;
      background: transparent;
      border: none;
      cursor: pointer;
      border-bottom: 3px solid transparent;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      transition: color .12s, border-color .12s;
  }
  .chat-tab-btn:hover { color: var(--chat-accent); }
  .chat-tab-btn.active { color: var(--chat-accent); border-bottom-color: var(--chat-accent); }
  .chat-tab-badge {
      background: #e53e3e;
      color: #fff;
      font-size: 10px;
      font-weight: 700;
      border-radius: 50px;
      min-width: 16px;
      height: 16px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: 0 4px;
  }
  .chat-panel { display: none; height: 100%; }
  .chat-panel.active { display: flex; }

  /* Guest bubble styling (kept simple, separate from .msgr-bubble) */
  .g-bubble {
      max-width: 70%;
      padding: 10px 14px;
      border-radius: 12px;
      font-size: 13px;
      line-height: 1.5;
  }
  .g-bubble.guest { background: #fff; align-self: flex-start; border-bottom-left-radius: 4px; box-shadow: 0 1px 4px rgba(0,0,0,.06); }
  .g-bubble.staff, .g-bubble.admin { background: var(--chat-accent); color: #fff; align-self: flex-end; border-bottom-right-radius: 4px; }
  .g-bubble.bot { background: #e2e8f0; align-self: flex-start; border-bottom-left-radius: 4px; }
  .g-session-item { cursor: pointer; transition: .15s; }
  .g-session-item:hover { background: #eef6f4; }
  .g-session-item.active { background: #e6f7f5; border-left: 3px solid var(--chat-accent); }

  /* Team chat sub-tabs (Channel / Direct Messages) */
  .team-subtabs {
      display: flex;
      gap: 4px;
      padding: 8px 12px 0;
      background: #fff;
      border-bottom: 1px solid #f0f0f0;
  }
  .team-subtab-btn {
      padding: 8px 12px;
      font-size: 12.5px;
      font-weight: 700;
      color: #65676b;
      background: transparent;
      border: none;
      border-bottom: 2px solid transparent;
      cursor: pointer;
  }
  .team-subtab-btn.active { color: var(--chat-accent); border-bottom-color: var(--chat-accent); }
  .team-subpanel { display: none; flex: 1; min-height: 0; }
  .team-subpanel.active { display: flex; }

  /* Channel message row (own vs others), reuses msgr-bubble look */
   .team-channel-row.mine { align-self: flex-end; align-items: flex-end; }
  .team-channel-row.theirs { align-self: flex-start; align-items: flex-start; }
  .team-channel-sender { font-size: 11px; font-weight: 700; color: #65676b; margin: 0 4px 2px; }
  .team-channel-row.mine .msgr-bubble { background: var(--chat-accent); color: #fff; }
  .team-channel-row.theirs .msgr-bubble { background: var(--chat-bubble-in); color: var(--chat-text-dark); }

  /* "+ New Message" directory picker */
  .team-dm-newbtn {
      margin: 8px 12px;
      padding: 8px 12px;
      background: var(--chat-accent);
      color: #fff;
      border: none;
      border-radius: 8px;
      font-size: 12.5px;
      font-weight: 700;
      cursor: pointer;
      display: flex;
      align-items: center;
      gap: 6px;
      justify-content: center;
  }
  .team-dm-newbtn:hover { background: var(--chat-accent-dark); }
  .team-directory-overlay {
      position: absolute;
      inset: 0;
      background: #fff;
      z-index: 20;
      display: flex;
      flex-direction: column;
  }
  .team-directory-header {
      padding: 12px 14px;
      display: flex;
      align-items: center;
      gap: 10px;
      border-bottom: 1px solid #f0f0f0;
      font-weight: 700;
      font-size: 14px;
  }
  .team-role-badge {
      font-size: 10px;
      padding: 1px 7px;
      border-radius: 50px;
      font-weight: 600;
      text-transform: capitalize;
  }
  .team-role-badge.admin { background: #ede9fe; color: #6d28d9; }
  .team-role-badge.staff { background: #dbeafe; color: #1e40af; }
</style>
</head>
<body class="messenger-body role-staff">

<?php include 'ssidebar.php'; ?>

<div class="msgr-page-wrapper">
<div class="msgr-shell" style="flex-direction: column;">

  <!-- ===== Tab bar ===== -->
  <div class="chat-tabs">
    <button class="chat-tab-btn active" id="tabBtnPatient" onclick="switchTab('patient')">
      <i class="fas fa-user-injured"></i> Patient Chats
      <span id="patientTabBadge"></span>
    </button>
    <button class="chat-tab-btn" id="tabBtnGuest" onclick="switchTab('guest')">
      <i class="fas fa-globe"></i> Website Guests
      <span id="guestTabBadge"></span>
    </button>
    <button class="chat-tab-btn" id="tabBtnTeam" onclick="switchTab('team')">
      <i class="fas fa-people-group"></i> Team Chat
      <span id="teamTabBadge"></span>
    </button>
  </div>

  <div style="flex:1; display:flex; min-height:0;">

    <!-- ===== PATIENT PANEL ===== -->
    <div class="chat-panel active" id="panelPatient" style="flex:1;">
      <div class="msgr-sidebar">
        <div class="msgr-sidebar-header">
          <span class="msgr-sidebar-title">Patients</span>
          <div class="flex items-center gap-2">
            <span class="text-[11px] text-gray-400">Hi, <?= htmlspecialchars($staffName) ?></span>
            <button onclick="loadConversations()" class="msgr-icon-btn" style="width:28px;height:28px;" title="Refresh"><i class="fas fa-sync-alt text-xs"></i></button>
          </div>
        </div>
        <div class="msgr-search">
          <i class="fas fa-magnifying-glass"></i>
          <input type="text" id="searchInput" placeholder="Search conversations" oninput="renderConversationList()">
        </div>
        <div class="msgr-conv-list" id="conversationList"></div>
      </div>

      <div class="msgr-thread">
        <div class="msgr-thread-header" id="threadHeader">
          <span class="text-sm text-gray-400">Select a conversation to start replying</span>
        </div>
        <div class="msgr-messages" id="threadMessages"></div>
        <div id="previewStrip" class="msgr-preview-strip hidden"></div>
        <div class="msgr-composer hidden" id="composer">
          <input type="file" id="attachInput" accept="image/*" class="hidden">
          <button class="msgr-icon-btn" id="attachBtn" title="Attach image"><i class="fas fa-image"></i></button>
          <div class="msgr-input-wrap">
            <textarea id="replyInput" rows="1" placeholder="Type your reply..."></textarea>
          </div>
          <button class="msgr-send-btn" id="sendReplyBtn" title="Send"><i class="fas fa-paper-plane"></i></button>
        </div>
      </div>
    </div>

    <!-- ===== GUEST PANEL ===== -->
    <div class="chat-panel" id="panelGuest" style="flex:1;">
      <div class="msgr-sidebar">
        <div class="msgr-sidebar-header">
          <span class="msgr-sidebar-title">Website Guests</span>
          <button onclick="loadSessions()" class="msgr-icon-btn" style="width:28px;height:28px;" title="Refresh"><i class="fas fa-sync-alt text-xs"></i></button>
        </div>
        <div class="msgr-conv-list" id="sessionList" style="padding:4px 0;"></div>
      </div>

      <div class="msgr-thread">
        <div class="msgr-thread-header" id="guestThreadHeader">
          <span class="text-sm text-gray-400">Select a conversation to start replying</span>
        </div>
        <div class="msgr-messages" id="guestThreadMessages" style="display:flex; flex-direction:column; gap:8px;"></div>
        <div id="guestComposer" class="msgr-composer hidden">
          <div class="msgr-input-wrap">
            <textarea id="guestReplyInput" rows="1" placeholder="Type your reply..."></textarea>
          </div>
          <button class="msgr-send-btn" id="guestSendBtn" title="Send"><i class="fas fa-paper-plane"></i></button>
        </div>
      </div>
    </div>

    <!-- ===== TEAM CHAT PANEL ===== -->
    <div class="chat-panel" id="panelTeam" style="flex:1; flex-direction: column;">

      <div class="team-subtabs">
        <button class="team-subtab-btn active" id="teamSubtabChannel" onclick="switchTeamSubtab('channel')">
          <i class="fas fa-hashtag"></i> Team Channel
        </button>
        <button class="team-subtab-btn" id="teamSubtabDms" onclick="switchTeamSubtab('dms')">
          <i class="fas fa-comment-dots"></i> Direct Messages
          <span id="dmSubtabBadge"></span>
        </button>
      </div>

      <div style="flex:1; display:flex; min-height:0;">

        <!-- Shared channel: everyone posts and reads the same feed -->
        <div class="team-subpanel active" id="teamSubpanelChannel">
          <div class="msgr-thread" style="flex:1;">
            <div class="msgr-thread-header">
              <div class="msgr-thread-header-info">
                <div class="msgr-avatar" style="background:var(--chat-accent);"><i class="fas fa-hashtag"></i></div>
                <div>
                  <div class="msgr-thread-header-name">Team Channel</div>
                  <div class="msgr-thread-header-status">Visible to all staff &amp; admin</div>
                </div>
              </div>
            </div>
            <div class="msgr-messages" id="channelMessages"></div>
            <div class="msgr-composer">
              <div class="msgr-input-wrap">
                <textarea id="channelReplyInput" rows="1" placeholder="Message the team..."></textarea>
              </div>
              <button class="msgr-send-btn" id="channelSendBtn" title="Send"><i class="fas fa-paper-plane"></i></button>
            </div>
          </div>
        </div>

        <!-- Direct messages between two staff/admin -->
        <div class="team-subpanel" id="teamSubpanelDms" style="position: relative;">
          <div class="msgr-sidebar">
            <div class="msgr-sidebar-header">
              <span class="msgr-sidebar-title">Direct Messages</span>
              <button onclick="loadDms()" class="msgr-icon-btn" style="width:28px;height:28px;" title="Refresh"><i class="fas fa-sync-alt text-xs"></i></button>
            </div>
            <button class="team-dm-newbtn" onclick="openDirectory()"><i class="fas fa-pen"></i> New Message</button>
            <div class="msgr-conv-list" id="dmList"></div>
          </div>

          <div class="msgr-thread">
            <div class="msgr-thread-header" id="dmThreadHeader">
              <span class="text-sm text-gray-400">Select a colleague to start messaging</span>
            </div>
            <div class="msgr-messages" id="dmThreadMessages"></div>
            <div class="msgr-composer hidden" id="dmComposer">
              <div class="msgr-input-wrap">
                <textarea id="dmReplyInput" rows="1" placeholder="Type a message..."></textarea>
              </div>
              <button class="msgr-send-btn" id="dmSendBtn" title="Send"><i class="fas fa-paper-plane"></i></button>
            </div>
          </div>

          <!-- Directory picker overlay for starting a new DM -->
          <div class="team-directory-overlay hidden" id="directoryOverlay">
            <div class="team-directory-header">
              <button onclick="closeDirectory()" class="msgr-minimize-btn"><i class="fas fa-arrow-left"></i></button>
              Start a conversation
            </div>
            <div class="msgr-conv-list" id="directoryList" style="flex:1;"></div>
          </div>
        </div>

      </div>
    </div>

  </div>
</div>
</div>

<script src="../../assets/chat/messenger.js"></script>
<script>
const currentUserId = <?= (int)$staffId ?>; // the logged-in staff/admin's own id, used to tell "mine" vs "theirs" in Team Chat

/* ============================================================
   TAB SWITCHING
   Polling only runs for the active tab so we don't hit the
   server every couple seconds for a panel nobody is looking at.
   ============================================================ */
let activeTab = 'patient';

function switchTab(tab) {
    if (tab === activeTab) return;
    activeTab = tab;

    document.getElementById('panelPatient').classList.toggle('active', tab === 'patient');
    document.getElementById('panelGuest').classList.toggle('active', tab === 'guest');
    document.getElementById('panelTeam').classList.toggle('active', tab === 'team');
    document.getElementById('tabBtnPatient').classList.toggle('active', tab === 'patient');
    document.getElementById('tabBtnGuest').classList.toggle('active', tab === 'guest');
    document.getElementById('tabBtnTeam').classList.toggle('active', tab === 'team');

    // Stop every thread-level poll first, then start only what the active tab needs
    if (messagesPoll) { clearInterval(messagesPoll); messagesPoll = null; }
    if (guestMessagesPoll) { clearInterval(guestMessagesPoll); guestMessagesPoll = null; }
    if (channelPoll) { clearInterval(channelPoll); channelPoll = null; }
    if (dmMessagesPoll) { clearInterval(dmMessagesPoll); dmMessagesPoll = null; }

    if (tab === 'patient') {
        loadConversations();
        if (currentConversationId) messagesPoll = setInterval(loadMessages, 2500);
    } else if (tab === 'guest') {
        loadSessions();
        if (currentGuestId) guestMessagesPoll = setInterval(loadGuestMessages, 2500);
    } else if (tab === 'team') {
        loadDms();
        if (teamSubtab === 'channel') {
            loadChannelMessages();
            channelPoll = setInterval(loadChannelMessages, 3000);
        } else if (currentDmConversationId) {
            loadDmMessages();
            dmMessagesPoll = setInterval(loadDmMessages, 2500);
        }
    }
}

/* ============================================================
   PATIENT (account-based) CHAT
   ============================================================ */
let currentConversationId = null;
let currentStatus = null;
let pendingFile = null;
let typingThrottle = 0;
let allConversations = [];
let conversationsPoll = null, messagesPoll = null;

async function loadConversations() {
    try {
        const res = await fetch('?action=list_conversations');
        const data = await res.json();
        if (!data.success) return;
        allConversations = data.conversations || [];
        renderConversationList();
        const totalUnread = allConversations.reduce((sum, c) => sum + (parseInt(c.unread, 10) || 0), 0);
        document.getElementById('patientTabBadge').innerHTML = totalUnread > 0 ? `<span class="chat-tab-badge">${totalUnread}</span>` : '';
    } catch (e) {
        console.error('Error loading conversations:', e);
    }
}

function renderConversationList() {
    const list = document.getElementById('conversationList');
    const query = (document.getElementById('searchInput').value || '').toLowerCase();
    const filtered = allConversations.filter(c => (c.patient_name || '').toLowerCase().includes(query));

    if (filtered.length === 0) {
        list.innerHTML = `<div class="msgr-empty-state"><i class="fas fa-comment-slash"></i><p>No conversations found</p></div>`;
        return;
    }

    list.innerHTML = filtered.map(c => {
        const name = c.patient_name || ('Patient #' + c.patient_id);
        const previewPrefix = c.last_sender === 'patient' ? '' : 'You: ';
        const previewText = (c.last_message || '').replace(/</g,'&lt;');
        return `
        <div class="msgr-conv-item ${c.id == currentConversationId ? 'active' : ''} ${c.status === 'closed' ? 'is-closed' : ''}" onclick="openConversation(${c.id}, '${c.status}')">
            ${msgrAvatar(name, 40, c.patient_online)}
            <div class="msgr-conv-meta">
                <div class="msgr-conv-name-row">
                    <span class="msgr-conv-name">${name}</span>
                    <span class="msgr-conv-time">${msgrTime(c.last_message_at)}</span>
                </div>
                <div class="msgr-conv-preview ${c.unread > 0 ? 'unread' : ''}">${previewPrefix}${previewText}</div>
            </div>
            ${c.unread > 0 ? `<span class="msgr-unread-badge">${c.unread}</span>` : ''}
        </div>`;
    }).join('');
}

async function openConversation(id, status) {
    currentConversationId = id;
    currentStatus = status;
    document.getElementById('composer').classList.remove('hidden');
    renderConversationList();
    await loadMessages();
    loadConversations();
    if (messagesPoll) clearInterval(messagesPoll);
    messagesPoll = setInterval(loadMessages, 2500);
}

function renderHeader(name, online, typing, status) {
    const actionBtn = status === 'closed'
        ? `<button onclick="reopenConversation()" class="text-[11px] text-green-600 hover:underline flex items-center gap-1"><i class="fas fa-rotate-left"></i> Reopen</button>`
        : `<button onclick="closeConversation()" class="text-[11px] text-red-500 hover:underline flex items-center gap-1"><i class="fas fa-times-circle"></i> Close</button>`;

    document.getElementById('threadHeader').innerHTML = `
        <div class="msgr-thread-header-info">
            ${msgrAvatar(name, 40, online)}
            <div>
                <div class="msgr-thread-header-name">${name}</div>
                <div class="msgr-thread-header-status">${typing ? 'Typing...' : (online ? 'Online' : 'Offline')}</div>
            </div>
        </div>
        <div class="flex items-center gap-3">
            ${actionBtn}
            <button onclick="closeThreadPanel()" class="msgr-minimize-btn" title="Close this chat box"><i class="fas fa-times"></i></button>
        </div>
    `;
}

function closeThreadPanel() {
    currentConversationId = null;
    currentStatus = null;
    if (messagesPoll) clearInterval(messagesPoll);
    document.getElementById('threadHeader').innerHTML = `<span class="text-sm text-gray-400">Select a conversation to start replying</span>`;
    document.getElementById('threadMessages').innerHTML = '';
    document.getElementById('composer').classList.add('hidden');
    renderConversationList();
}

function renderMessages(messages, patientLastReadAt, patientName) {
    const box = document.getElementById('threadMessages');
    if (!messages || messages.length === 0) {
        box.innerHTML = `<div class="msgr-empty-state"><i class="fas fa-comment"></i><p>No messages yet</p></div>`;
        return;
    }

    const grouped = msgrGroupMessages(messages);
    let html = '';
    grouped.forEach((m, idx) => {
        const mine = m.sender_role === 'staff' || m.sender_role === 'admin';
        const text = m.message ? m.message.replace(/</g,'&lt;') : '';
        const img = m.attachment ? `<img src="../../${m.attachment}" onclick="window.open('../../${m.attachment}','_blank')">` : '';
        const rowClasses = `msgr-row ${mine ? 'mine' : 'theirs'} ${m.groupEnd ? 'group-end' : 'grouped'}`;
        const avatarSlot = (!mine && m.groupEnd) ? msgrAvatar(patientName, 28, null) : (!mine ? '<span class="msgr-avatar-spacer"></span>' : '');

        html += `<div class="${rowClasses}">${avatarSlot}<div class="msgr-bubble">${text}${img}</div></div>`;
        if (m.groupEnd) html += `<div class="msgr-timestamp ${mine ? '' : 'left'}">${msgrTime(m.created_at)}</div>`;

        const isVeryLast = idx === grouped.length - 1;
        if (isVeryLast && mine && patientLastReadAt && new Date(patientLastReadAt.replace(' ','T')) >= new Date(m.created_at.replace(' ','T'))) {
            html += `<div class="msgr-seen"><i class="fas fa-check-circle" style="font-size:9px;"></i> Seen</div>`;
        }
    });

    box.innerHTML = html;
    box.scrollTop = box.scrollHeight;
}

function renderTyping(isTyping, patientName) {
    const box = document.getElementById('threadMessages');
    const existing = document.getElementById('typingIndicatorRow');
    if (existing) existing.remove();
    if (isTyping) {
        const row = document.createElement('div');
        row.id = 'typingIndicatorRow';
        row.className = 'msgr-typing-row';
        row.innerHTML = msgrAvatar(patientName, 28, null) + msgrTypingDotsHtml();
        box.appendChild(row);
        box.scrollTop = box.scrollHeight;
    }
}

async function loadMessages() {
    if (!currentConversationId) return;
    try {
        const res = await fetch('?action=get_messages&conversation_id=' + currentConversationId);
        const data = await res.json();
        if (!data.success) return;

        const conv = allConversations.find(c => c.id == currentConversationId);
        const name = conv ? (conv.patient_name || ('Patient #' + conv.patient_id)) : 'Patient';

        renderHeader(name, data.patient_online, data.patient_typing, currentStatus);
        renderMessages(data.messages, data.patient_last_read_at, name);
        renderTyping(data.patient_typing, name);
    } catch (e) {
        console.error('Error loading messages:', e);
    }
}

document.getElementById('attachBtn').addEventListener('click', () => document.getElementById('attachInput').click());
document.getElementById('attachInput').addEventListener('change', e => {
    pendingFile = e.target.files[0] || null;
    const strip = document.getElementById('previewStrip');
    if (pendingFile) {
        const url = URL.createObjectURL(pendingFile);
        strip.innerHTML = `<img src="${url}"><span class="text-xs text-gray-500">${pendingFile.name}</span>
            <button onclick="clearAttachment()" class="text-red-400 text-xs ml-1"><i class="fas fa-times"></i></button>`;
        strip.classList.remove('hidden');
    }
});
function clearAttachment() {
    pendingFile = null;
    document.getElementById('attachInput').value = '';
    document.getElementById('previewStrip').classList.add('hidden');
    document.getElementById('previewStrip').innerHTML = '';
}

async function sendReply() {
    const input = document.getElementById('replyInput');
    const message = input.value.trim();
    if (!message && !pendingFile) return;
    if (!currentConversationId) return;

    const formData = new FormData();
    formData.append('conversation_id', currentConversationId);
    formData.append('message', message);
    if (pendingFile) formData.append('attachment', pendingFile);

    input.value = '';
    input.style.height = 'auto';
    clearAttachment();

    const res = await fetch('?action=send_reply', { method: 'POST', body: formData });
    const data = await res.json();
    if (!data.success) alert(data.message || 'Failed to send');
    loadMessages();
}

async function closeConversation() {
    if (!currentConversationId) return;
    if (!confirm('Close this conversation? You can reopen it yourself anytime from the sidebar.')) return;
    await fetch('?action=close_conversation', {
        method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({conversation_id: currentConversationId})
    });
    currentStatus = 'closed';
    loadConversations();
    loadMessages();
}

async function reopenConversation() {
    if (!currentConversationId) return;
    await fetch('?action=reopen_conversation', {
        method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({conversation_id: currentConversationId})
    });
    currentStatus = 'open';
    loadConversations();
    loadMessages();
}

document.getElementById('replyInput').addEventListener('input', function() {
    this.style.height = 'auto';
    this.style.height = Math.min(this.scrollHeight, 100) + 'px';

    const now = Date.now();
    if (now - typingThrottle > 2000 && currentConversationId) {
        typingThrottle = now;
        const fd = new FormData();
        fd.append('conversation_id', currentConversationId);
        fetch('?action=typing', { method: 'POST', body: fd });
    }
});
document.getElementById('sendReplyBtn').addEventListener('click', sendReply);
document.getElementById('replyInput').addEventListener('keydown', e => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendReply(); }
});

/* ============================================================
   GUEST (no account, landing page) CHAT
   ============================================================ */
let currentGuestId = null;
let guestSessionsPoll = null, guestMessagesPoll = null;

async function loadSessions() {
    try {
        const res = await fetch('?action=list_sessions');
        const data = await res.json();
        if (!data.success) return;
        const list = document.getElementById('sessionList');

        if (!data.sessions || data.sessions.length === 0) {
            list.innerHTML = `<div class="msgr-empty-state"><i class="fas fa-comment-slash"></i><p>No guest conversations yet</p></div>`;
            document.getElementById('guestTabBadge').innerHTML = '';
            return;
        }

        const totalUnread = data.sessions.reduce((sum, s) => sum + (parseInt(s.unread, 10) || 0), 0);
        document.getElementById('guestTabBadge').innerHTML = totalUnread > 0 ? `<span class="chat-tab-badge">${totalUnread}</span>` : '';

        list.innerHTML = data.sessions.map(s => `
            <div class="g-session-item msgr-conv-item ${s.guest_id === currentGuestId ? 'active' : ''}" onclick="openGuestSession('${s.guest_id}')">
                ${msgrAvatar(s.guest_id.replace('guest_', 'G'), 40, null)}
                <div class="msgr-conv-meta">
                    <div class="msgr-conv-name-row">
                        <span class="msgr-conv-name">${s.guest_id.replace('guest_','Guest ').slice(0,18)}</span>
                        <span class="msgr-conv-time">${msgrTime(s.last_message_at)}</span>
                    </div>
                    <div class="msgr-conv-preview ${s.unread > 0 ? 'unread' : ''}">${(s.last_message || '').replace(/</g,'&lt;')}</div>
                </div>
                ${s.unread > 0 ? `<span class="msgr-unread-badge">${s.unread}</span>` : ''}
            </div>
        `).join('');
    } catch (e) {
        console.error('Error loading guest sessions:', e);
    }
}

async function openGuestSession(guestId) {
    currentGuestId = guestId;
    document.getElementById('guestComposer').classList.remove('hidden');
    document.getElementById('guestThreadHeader').innerHTML = `
        <div class="msgr-thread-header-info">
            ${msgrAvatar(guestId.replace('guest_', 'G'), 40, null)}
            <div>
                <div class="msgr-thread-header-name">${guestId.replace('guest_', 'Guest ')}</div>
                <div class="msgr-thread-header-status">Website visitor (no account)</div>
            </div>
        </div>
        <button onclick="closeGuestSession()" class="text-[11px] text-red-500 hover:underline flex items-center gap-1"><i class="fas fa-times-circle"></i> Close</button>
    `;
    await loadGuestMessages();
    loadSessions();
    if (guestMessagesPoll) clearInterval(guestMessagesPoll);
    guestMessagesPoll = setInterval(loadGuestMessages, 2500);
}

async function loadGuestMessages() {
    if (!currentGuestId) return;
    try {
        const res = await fetch('?action=get_guest_messages&guest_id=' + encodeURIComponent(currentGuestId));
        const data = await res.json();
        if (!data.success) return;
        const box = document.getElementById('guestThreadMessages');

        if (!data.messages || data.messages.length === 0) {
            box.innerHTML = `<div class="msgr-empty-state"><i class="fas fa-comment"></i><p>No messages yet</p></div>`;
            return;
        }

        box.innerHTML = data.messages.map(m => `
            <div class="g-bubble ${m.sender}">
                ${m.message.replace(/</g,'&lt;')}
                <div style="font-size:9px;opacity:.6;margin-top:3px;">${msgrTime(m.created_at)}</div>
            </div>
        `).join('');
        box.scrollTop = box.scrollHeight;
    } catch (e) {
        console.error('Error loading guest messages:', e);
    }
}

async function sendGuestReply() {
    const input = document.getElementById('guestReplyInput');
    const message = input.value.trim();
    if (!message || !currentGuestId) return;
    input.value = '';
    input.style.height = 'auto';
    await fetch('?action=send_guest_reply', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({guest_id: currentGuestId, message})
    });
    loadGuestMessages();
}

async function closeGuestSession() {
    if (!currentGuestId) return;
    if (!confirm('Close this conversation? The guest will no longer be able to chat.')) return;
    await fetch('?action=close_guest_session', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({guest_id: currentGuestId})
    });
    currentGuestId = null;
    if (guestMessagesPoll) clearInterval(guestMessagesPoll);
    document.getElementById('guestThreadHeader').innerHTML = `<span class="text-sm text-gray-400">Select a conversation to start replying</span>`;
    document.getElementById('guestThreadMessages').innerHTML = '';
    document.getElementById('guestComposer').classList.add('hidden');
    loadSessions();
}

document.getElementById('guestReplyInput').addEventListener('input', function() {
    this.style.height = 'auto';
    this.style.height = Math.min(this.scrollHeight, 100) + 'px';
});
document.getElementById('guestSendBtn').addEventListener('click', sendGuestReply);
document.getElementById('guestReplyInput').addEventListener('keydown', e => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendGuestReply(); }
});

/* ============================================================
   TEAM CHAT — staff/admin talking to each other.
   Two sub-tabs: shared Channel, and 1-on-1 Direct Messages.
   ============================================================ */
let teamSubtab = 'channel';
let currentDmConversationId = null;
let currentDmOtherName = 'Colleague';
let channelPoll = null, dmMessagesPoll = null, dmListPoll = null;

function switchTeamSubtab(sub) {
    if (sub === teamSubtab) return;
    teamSubtab = sub;
    document.getElementById('teamSubpanelChannel').classList.toggle('active', sub === 'channel');
    document.getElementById('teamSubpanelDms').classList.toggle('active', sub === 'dms');
    document.getElementById('teamSubtabChannel').classList.toggle('active', sub === 'channel');
    document.getElementById('teamSubtabDms').classList.toggle('active', sub === 'dms');

    if (channelPoll) { clearInterval(channelPoll); channelPoll = null; }
    if (dmMessagesPoll) { clearInterval(dmMessagesPoll); dmMessagesPoll = null; }

    if (sub === 'channel') {
        loadChannelMessages();
        channelPoll = setInterval(loadChannelMessages, 3000);
    } else {
        loadDms();
        if (currentDmConversationId) {
            loadDmMessages();
            dmMessagesPoll = setInterval(loadDmMessages, 2500);
        }
    }
}

/* ---- Shared channel ---- */
async function loadChannelMessages() {
    try {
        const res = await fetch('?action=team_get_channel_messages');
        const data = await res.json();
        if (!data.success) return;
        const box = document.getElementById('channelMessages');

        if (!data.messages || data.messages.length === 0) {
            box.innerHTML = `<div class="msgr-empty-state"><i class="fas fa-hashtag"></i><p>No messages yet — say hi to the team!</p></div>`;
            return;
        }

        box.innerHTML = data.messages.map(m => {
            const mine = m.sender_id == currentUserId;
            const img = m.attachment ? `<img src="../../${m.attachment}" onclick="window.open('../../${m.attachment}','_blank')">` : '';
            return `
            <div class="team-channel-row ${mine ? 'mine' : 'theirs'}">
                ${!mine ? `<span class="team-channel-sender">${(m.sender_name || 'Staff').replace(/</g,'&lt;')}</span>` : ''}
                <div class="msgr-bubble">${(m.message || '').replace(/</g,'&lt;')}${img}</div>
                <span class="msgr-timestamp ${mine ? '' : 'left'}" style="margin:2px 4px;">${msgrTime(m.created_at)}</span>
            </div>`;
        }).join('');
        box.scrollTop = box.scrollHeight;
    } catch (e) {
        console.error('Error loading channel messages:', e);
    }
}

async function sendChannelMessage() {
    const input = document.getElementById('channelReplyInput');
    const message = input.value.trim();
    if (!message) return;
    input.value = '';
    input.style.height = 'auto';

    const fd = new FormData();
    fd.append('message', message);
    await fetch('?action=team_send_channel_message', { method: 'POST', body: fd });
    loadChannelMessages();
}

document.getElementById('channelSendBtn').addEventListener('click', sendChannelMessage);
document.getElementById('channelReplyInput').addEventListener('input', function() {
    this.style.height = 'auto';
    this.style.height = Math.min(this.scrollHeight, 100) + 'px';
});
document.getElementById('channelReplyInput').addEventListener('keydown', e => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendChannelMessage(); }
});

/* ---- Direct messages ---- */
async function loadDms() {
    try {
        const res = await fetch('?action=team_list_dms');
        const data = await res.json();
        if (!data.success) return;
        const list = document.getElementById('dmList');
        const dmUnread = (data.dms || []).reduce((sum, d) => sum + (parseInt(d.unread, 10) || 0), 0);

        document.getElementById('dmSubtabBadge').innerHTML = dmUnread > 0 ? `<span class="chat-tab-badge">${dmUnread}</span>` : '';

        if (!data.dms || data.dms.length === 0) {
            list.innerHTML = `<div class="msgr-empty-state"><i class="fas fa-comment-slash"></i><p>No direct messages yet</p></div>`;
            return;
        }

        list.innerHTML = data.dms.map(d => {
            const name = d.other_name || ('User #' + d.other_id);
            const previewPrefix = d.last_sender_id == currentUserId ? 'You: ' : '';
            const previewText = (d.last_message || '').replace(/</g,'&lt;');
            return `
            <div class="msgr-conv-item ${d.id == currentDmConversationId ? 'active' : ''}" onclick="openDmConversation(${d.id}, '${name.replace(/'/g,"\\'")}')">
                ${msgrAvatar(name, 40, !!d.other_online)}
                <div class="msgr-conv-meta">
                    <div class="msgr-conv-name-row">
                        <span class="msgr-conv-name">${name}</span>
                        <span class="msgr-conv-time">${msgrTime(d.last_message_at)}</span>
                    </div>
                    <div class="msgr-conv-preview ${d.unread > 0 ? 'unread' : ''}">${previewPrefix}${previewText}</div>
                </div>
                ${d.unread > 0 ? `<span class="msgr-unread-badge">${d.unread}</span>` : ''}
            </div>`;
        }).join('');
    } catch (e) {
        console.error('Error loading DMs:', e);
    }
}

function openDmConversation(conversationId, otherName) {
    currentDmConversationId = conversationId;
    currentDmOtherName = otherName;
    document.getElementById('dmComposer').classList.remove('hidden');
    document.getElementById('dmThreadHeader').innerHTML = `
        <div class="msgr-thread-header-info">
            ${msgrAvatar(otherName, 40, null)}
            <div class="msgr-thread-header-name">${otherName}</div>
        </div>`;
    loadDms();
    loadDmMessages();
    if (dmMessagesPoll) clearInterval(dmMessagesPoll);
    dmMessagesPoll = setInterval(loadDmMessages, 2500);
}

async function loadDmMessages() {
    if (!currentDmConversationId) return;
    try {
        const res = await fetch('?action=team_get_dm_messages&conversation_id=' + currentDmConversationId);
        const data = await res.json();
        if (!data.success) return;
        const box = document.getElementById('dmThreadMessages');

        if (!data.messages || data.messages.length === 0) {
            box.innerHTML = `<div class="msgr-empty-state"><i class="fas fa-comment"></i><p>No messages yet</p></div>`;
            return;
        }

        const grouped = msgrGroupMessages(data.messages.map(m => ({...m, sender_role: (m.sender_id == currentUserId ? 'me' : 'them')})));
        let html = '';
        grouped.forEach(m => {
            const mine = m.sender_id == currentUserId;
            const text = m.message ? m.message.replace(/</g,'&lt;') : '';
            const img = m.attachment ? `<img src="../../${m.attachment}" onclick="window.open('../../${m.attachment}','_blank')">` : '';
            const rowClasses = `msgr-row ${mine ? 'mine' : 'theirs'} ${m.groupEnd ? 'group-end' : 'grouped'}`;
            const avatarSlot = (!mine && m.groupEnd) ? msgrAvatar(currentDmOtherName, 28, null) : (!mine ? '<span class="msgr-avatar-spacer"></span>' : '');
            html += `<div class="${rowClasses}">${avatarSlot}<div class="msgr-bubble">${text}${img}</div></div>`;
            if (m.groupEnd) html += `<div class="msgr-timestamp ${mine ? '' : 'left'}">${msgrTime(m.created_at)}</div>`;
        });

        box.innerHTML = html;
        box.scrollTop = box.scrollHeight;
        loadDms(); // refresh unread badges now that this thread's been read
    } catch (e) {
        console.error('Error loading DM messages:', e);
    }
}

async function sendDmMessage() {
    const input = document.getElementById('dmReplyInput');
    const message = input.value.trim();
    if (!message || !currentDmConversationId) return;
    input.value = '';
    input.style.height = 'auto';

    const fd = new FormData();
    fd.append('conversation_id', currentDmConversationId);
    fd.append('message', message);
    await fetch('?action=team_send_dm', { method: 'POST', body: fd });
    loadDmMessages();
}

document.getElementById('dmSendBtn').addEventListener('click', sendDmMessage);
document.getElementById('dmReplyInput').addEventListener('input', function() {
    this.style.height = 'auto';
    this.style.height = Math.min(this.scrollHeight, 100) + 'px';
});
document.getElementById('dmReplyInput').addEventListener('keydown', e => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendDmMessage(); }
});

async function refreshTeamTabBadge() {
    try {
        const res = await fetch('?action=team_unread_count');
        const data = await res.json();
        if (!data.success) return;
        const total = (data.dm_unread || 0) + (data.channel_unread || 0);
        document.getElementById('teamTabBadge').innerHTML = total > 0 ? `<span class="chat-tab-badge">${total}</span>` : '';
    } catch (e) {
        console.error('Error loading team unread count:', e);
    }
}

/* ---- "New Message" directory picker ---- */
async function openDirectory() {
    document.getElementById('directoryOverlay').classList.remove('hidden');
    try {
        const res = await fetch('?action=team_list_staff');
        const data = await res.json();
        const list = document.getElementById('directoryList');
        if (!data.success || !data.staff || data.staff.length === 0) {
            list.innerHTML = `<div class="msgr-empty-state"><i class="fas fa-users-slash"></i><p>No other staff/admin found</p></div>`;
            return;
        }
        list.innerHTML = data.staff.map(u => `
            <div class="msgr-conv-item" onclick="startDmWith(${u.id}, '${(u.full_name || '').replace(/'/g,"\\'")}')">
                ${msgrAvatar(u.full_name, 40, u.online)}
                <div class="msgr-conv-meta">
                    <div class="msgr-conv-name-row">
                        <span class="msgr-conv-name">${(u.full_name || 'Unnamed').replace(/</g,'&lt;')}</span>
                        <span class="team-role-badge ${u.role}">${u.role}</span>
                    </div>
                </div>
            </div>
        `).join('');
    } catch (e) {
        console.error('Error loading staff directory:', e);
    }
}

function closeDirectory() {
    document.getElementById('directoryOverlay').classList.add('hidden');
}

async function startDmWith(userId, name) {
    try {
        const res = await fetch('?action=team_open_dm&user_id=' + userId);
        const data = await res.json();
        if (!data.success) { alert(data.message || 'Could not open conversation'); return; }
        closeDirectory();
        openDmConversation(data.conversation_id, name);
    } catch (e) {
        console.error('Error opening DM:', e);
    }
}

/* ============================================================
   INIT — always keep unread badges + sidebars fresh,
   but only run the message-thread poll for the active tab.
   ============================================================ */
loadConversations();
loadSessions();
loadDms();
refreshTeamTabBadge();
conversationsPoll = setInterval(loadConversations, 4000);
guestSessionsPoll = setInterval(loadSessions, 4000);
dmListPoll = setInterval(loadDms, 4000);
let teamBadgePoll = setInterval(refreshTeamTabBadge, 4000);

window.addEventListener('beforeunload', () => {
    [conversationsPoll, messagesPoll, guestSessionsPoll, guestMessagesPoll, channelPoll, dmMessagesPoll, dmListPoll, teamBadgePoll]
        .forEach(p => { if (p) clearInterval(p); });
});
</script>

</body>
</html>