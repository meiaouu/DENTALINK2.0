<?php
require '../../conn/conn.php';
require '../../api/chat_upload.php';

// ---- Auth: patient must be logged in ----
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || ($_SESSION['user_role'] ?? '') !== 'patient') {
    header('Location: ../../index.php');
    exit;
}

$patientId   = (int)($_SESSION['user_id'] ?? 0);
$patientName = $_SESSION['user_name'] ?? 'Patient';

function touchPresence(mysqli $conn, int $userId, string $role): void
{
    $stmt = $conn->prepare("INSERT INTO chat_presence (user_id, role, last_seen_at) VALUES (?, ?, NOW())
                             ON DUPLICATE KEY UPDATE last_seen_at = NOW(), role = VALUES(role)");
    $stmt->bind_param('is', $userId, $role);
    $stmt->execute();
}

function getOrCreateConversationId(mysqli $conn, int $patientId): int
{
    $stmt = $conn->prepare("SELECT id FROM chat_conversations WHERE patient_id = ? AND status != 'closed' ORDER BY id DESC LIMIT 1");
    $stmt->bind_param('i', $patientId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row) return (int)$row['id'];

    $stmt = $conn->prepare("INSERT INTO chat_conversations (patient_id, status, last_message_at) VALUES (?, 'open', NOW())");
    $stmt->bind_param('i', $patientId);
    $stmt->execute();
    return (int)$conn->insert_id;
}

// ------------------- AJAX endpoints -------------------
if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    touchPresence($conn, $patientId, 'patient');

    if ($_GET['action'] === 'get_messages') {
        $conversationId = getOrCreateConversationId($conn, $patientId);

        $stmt = $conn->prepare("SELECT id, sender_role, message, attachment, attachment_type, created_at
                                 FROM chat_thread_messages WHERE conversation_id = ? ORDER BY id ASC");
        $stmt->bind_param('i', $conversationId);
        $stmt->execute();
        $res = $stmt->get_result();
        $messages = [];
        while ($row = $res->fetch_assoc()) $messages[] = $row;

        // Patient has now seen everything up to now; also clear their typing flag.
        $stmt = $conn->prepare("UPDATE chat_conversations SET patient_last_read_at = NOW(), patient_typing_at = NULL WHERE id = ?");
        $stmt->bind_param('i', $conversationId);
        $stmt->execute();

        // Is any staff/admin currently online, and did the assigned one type recently?
        $stmt = $conn->prepare("SELECT assigned_to, assigned_role, staff_typing_at, staff_last_read_at FROM chat_conversations WHERE id = ?");
        $stmt->bind_param('i', $conversationId);
        $stmt->execute();
        $conv = $stmt->get_result()->fetch_assoc() ?: [];

        $staffOnline = false;
        if (!empty($conv['assigned_to'])) {
            $stmt = $conn->prepare("SELECT last_seen_at FROM chat_presence WHERE user_id = ? AND role IN ('staff','admin') AND last_seen_at >= NOW() - INTERVAL 30 SECOND");
            $stmt->bind_param('i', $conv['assigned_to']);
            $stmt->execute();
            $staffOnline = (bool)$stmt->get_result()->fetch_assoc();
        } else {
            $res = $conn->query("SELECT 1 FROM chat_presence WHERE role IN ('staff','admin') AND last_seen_at >= NOW() - INTERVAL 30 SECOND LIMIT 1");
            $staffOnline = (bool)$res->fetch_assoc();
        }

        $staffTyping = !empty($conv['staff_typing_at']) && strtotime($conv['staff_typing_at']) >= time() - 5;

        echo json_encode([
            'success' => true,
            'conversation_id' => $conversationId,
            'messages' => $messages,
            'staff_online' => $staffOnline,
            'staff_typing' => $staffTyping,
            'staff_last_read_at' => $conv['staff_last_read_at'] ?? null,
        ]);
        exit;
    }

    if ($_GET['action'] === 'typing' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $conversationId = getOrCreateConversationId($conn, $patientId);
        $stmt = $conn->prepare("UPDATE chat_conversations SET patient_typing_at = NOW() WHERE id = ?");
        $stmt->bind_param('i', $conversationId);
        $stmt->execute();
        echo json_encode(['success' => true]);
        exit;
    }

    if ($_GET['action'] === 'send_message' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $conversationId = getOrCreateConversationId($conn, $patientId);
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

        $stmt = $conn->prepare("INSERT INTO chat_thread_messages
                                 (conversation_id, sender_role, sender_id, message, attachment, attachment_type, is_read, created_at)
                                 VALUES (?, 'patient', ?, ?, ?, ?, 0, NOW())");
        $stmt->bind_param('iisss', $conversationId, $patientId, $message, $attachment, $attachmentType);
        $stmt->execute();

        $stmt = $conn->prepare("UPDATE chat_conversations SET last_message_at = NOW(), patient_last_read_at = NOW(),
                                 patient_typing_at = NULL, status = IF(status = 'closed', 'open', status) WHERE id = ?");
        $stmt->bind_param('i', $conversationId);
        $stmt->execute();

        echo json_encode(['success' => true]);
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
<title>Chat with the Clinic</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="../../assets/chat/messenger.css">
</head>

<body class="messenger-body role-patient">

<?php require 'psidebar.php'; ?>

<div class="msgr-page-wrapper">
<div class="msgr-shell">
  <!-- Sidebar (single conversation, kept for a consistent Messenger layout) -->
  <div class="msgr-sidebar">
    <div class="msgr-sidebar-header">
      <span class="msgr-sidebar-title">Chats</span>
      <button class="msgr-minimize-btn" id="minimizeBtn" title="Hide chat" Hidden><i class="fas fa-chevron-down" ></i></button>
    </div>
    <div class="msgr-search">
      <i class="fas fa-magnifying-glass"></i>
      <input type="text" placeholder="Search" disabled>
    </div>
    <div class="msgr-conv-list">
      <div class="msgr-conv-item active" id="onlyConvItem">
        <span id="sidebarAvatar"></span>
        <div class="msgr-conv-meta">
          <div class="msgr-conv-name-row">
            <span class="msgr-conv-name">Clinic Support</span>
            <span class="msgr-conv-time" id="sidebarTime"></span>
          </div>
          <div class="msgr-conv-preview" id="sidebarPreview">Say hello!</div>
        </div>
      </div>
    </div>
  </div>

  <!-- Thread -->
  <div class="msgr-thread">
    <div class="msgr-thread-header">
      <div class="msgr-thread-header-info">
        <span id="headerAvatar"></span>
        <div>
          <div class="msgr-thread-header-name">Clinic Support</div>
          <div class="msgr-thread-header-status" id="headerStatus">Offline</div>
        </div>
      </div>
    </div>

    <div class="msgr-messages" id="threadMessages"></div>

    <div id="previewStrip" class="msgr-preview-strip hidden"></div>

    <div class="msgr-composer">
      <input type="file" id="attachInput" accept="image/*" class="hidden">
      <button class="msgr-icon-btn" id="attachBtn" title="Attach image"><i class="fas fa-image"></i></button>
      <div class="msgr-input-wrap">
        <textarea id="msgInput" rows="1" placeholder="Aa"></textarea>
      </div>
      <button class="msgr-send-btn" id="sendBtn" title="Send"><i class="fas fa-paper-plane"></i></button>
    </div>
  </div>
</div>
</div>

<script src="../../assets/chat/messenger.js"></script>
<script>
let pendingFile = null;
let messagesPoll = null;
let typingThrottle = 0;

document.getElementById('sidebarAvatar').innerHTML = msgrAvatar('Clinic Support', 40, false);
document.getElementById('headerAvatar').innerHTML = msgrAvatar('Clinic Support', 40, false);

msgrInitMinimizeToggle('chatHidden_patient', '.msgr-shell');
document.getElementById('minimizeBtn').addEventListener('click', () => {
    document.querySelector('.msgr-shell').classList.add('msgr-hidden');
    document.getElementById('msgrFloatBubble').classList.remove('msgr-hidden');
    try { localStorage.setItem('chatHidden_patient', '1'); } catch (e) {}
});

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

function renderMessages(messages, staffLastReadAt) {
    const box = document.getElementById('threadMessages');

    if (!messages || messages.length === 0) {
        box.innerHTML = `<div class="msgr-empty-state">
            <i class="fas fa-comment-dots"></i>
            <p>Say hello! Ask us anything about your appointment.</p>
        </div>`;
        return;
    }

    const grouped = msgrGroupMessages(messages);
    let html = '';
    grouped.forEach((m, idx) => {
        const mine = m.sender_role === 'patient';
        const text = m.message ? m.message.replace(/</g,'&lt;') : '';
        const img = m.attachment
            ? `<img src="../../${m.attachment}" onclick="window.open('../../${m.attachment}','_blank')">`
            : '';
        const rowClasses = `msgr-row ${mine ? 'mine' : 'theirs'} ${m.groupEnd ? 'group-end' : 'grouped'}`;
        const avatarSlot = (!mine && m.groupEnd) ? msgrAvatar('Clinic Support', 28, null) : (!mine ? '<span class="msgr-avatar-spacer"></span>' : '');

        html += `<div class="${rowClasses}">${avatarSlot}<div class="msgr-bubble">${text}${img}</div></div>`;

        if (m.groupEnd) {
            html += `<div class="msgr-timestamp ${mine ? '' : 'left'}">${msgrTime(m.created_at)}</div>`;
        }

        // "Seen" label under the very last message, only if it's mine and staff has read it
        const isVeryLast = idx === grouped.length - 1;
        if (isVeryLast && mine && staffLastReadAt && new Date(staffLastReadAt.replace(' ','T')) >= new Date(m.created_at.replace(' ','T'))) {
            html += `<div class="msgr-seen"><i class="fas fa-check-circle" style="font-size:9px;"></i> Seen</div>`;
        }
    });

    box.innerHTML = html;
    box.scrollTop = box.scrollHeight;
}

function renderTyping(isTyping) {
    const box = document.getElementById('threadMessages');
    const existing = document.getElementById('typingIndicatorRow');
    if (existing) existing.remove();
    if (isTyping) {
        const row = document.createElement('div');
        row.id = 'typingIndicatorRow';
        row.className = 'msgr-typing-row';
        row.innerHTML = msgrAvatar('Clinic Support', 28, null) + msgrTypingDotsHtml();
        box.appendChild(row);
        box.scrollTop = box.scrollHeight;
    }
}

async function loadMessages() {
    try {
        const res = await fetch('?action=get_messages');
        const data = await res.json();
        if (!data.success) return;

        renderMessages(data.messages, data.staff_last_read_at);
        renderTyping(data.staff_typing);

        document.getElementById('sidebarAvatar').innerHTML = msgrAvatar('Clinic Support', 40, data.staff_online);
        document.getElementById('headerAvatar').innerHTML = msgrAvatar('Clinic Support', 40, data.staff_online);
        document.getElementById('headerStatus').textContent = data.staff_typing ? 'Typing...' : (data.staff_online ? 'Online' : 'Offline');

        if (data.messages && data.messages.length > 0) {
            const last = data.messages[data.messages.length - 1];
            const prefix = last.sender_role === 'patient' ? 'You: ' : '';
            document.getElementById('sidebarPreview').textContent = prefix + (last.message || (last.attachment ? '📷 Photo' : ''));
            document.getElementById('sidebarTime').textContent = msgrTime(last.created_at);
        }
    } catch (e) {
        console.error('Error loading messages:', e);
    }
}

async function sendMessage() {
    const input = document.getElementById('msgInput');
    const message = input.value.trim();
    if (!message && !pendingFile) return;

    const formData = new FormData();
    formData.append('message', message);
    if (pendingFile) formData.append('attachment', pendingFile);

    input.value = '';
    input.style.height = 'auto';
    clearAttachment();

    const res = await fetch('?action=send_message', { method: 'POST', body: formData });
    const data = await res.json();
    if (!data.success) alert(data.message || 'Failed to send message');
    loadMessages();
}

document.getElementById('msgInput').addEventListener('input', function() {
    this.style.height = 'auto';
    this.style.height = Math.min(this.scrollHeight, 100) + 'px';

    const now = Date.now();
    if (now - typingThrottle > 2000) {
        typingThrottle = now;
        fetch('?action=typing', { method: 'POST' });
    }
});
document.getElementById('sendBtn').addEventListener('click', sendMessage);
document.getElementById('msgInput').addEventListener('keydown', e => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
});

loadMessages();
messagesPoll = setInterval(loadMessages, 2500);
window.addEventListener('beforeunload', () => { if (messagesPoll) clearInterval(messagesPoll); });
</script>

</body>
</html>