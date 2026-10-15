<?php
 
require '../../conn/conn.php';
require '../../functions/audit_log.php';

// Check if user is logged in as staff
if (!isset($_SESSION['staff_logged_in']) || $_SESSION['staff_logged_in'] !== true) {
    header('Location: ../../index.php');
    exit;
}

$staffName = $_SESSION['staff_name'] ?? 'Staff';
$staffId = $_SESSION['staff_id'] ?? 0;
$staffRole = $_SESSION['staff_role'] ?? 'staff';

// ------------------- AJAX endpoints -------------------
if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');

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

    if ($_GET['action'] === 'get_messages') {
        $guestId = $_GET['guest_id'] ?? '';
        if ($guestId === '') { echo json_encode(['success' => false]); exit; }
        $stmt = $conn->prepare("SELECT id, sender, message, mode, created_at FROM chat_messages WHERE guest_id = ? ORDER BY id ASC");
        $stmt->bind_param('s', $guestId);
        $stmt->execute();
        $res = $stmt->get_result();
        $messages = [];
        while ($row = $res->fetch_assoc()) $messages[] = $row;

        // mark guest messages as read
        $esc = $conn->real_escape_string($guestId);
        $conn->query("UPDATE chat_messages SET is_read = 1 WHERE guest_id = '{$esc}' AND sender = 'guest'");

        echo json_encode(['success' => true, 'messages' => $messages]);
        exit;
    }

    if ($_GET['action'] === 'send_reply' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $guestId = $input['guest_id'] ?? '';
        $message = trim($input['message'] ?? '');
        if ($guestId === '' || $message === '') { echo json_encode(['success' => false, 'message' => 'Missing data']); exit; }

        $stmt = $conn->prepare("INSERT INTO chat_messages (guest_id, sender, message, mode, is_read, is_replied, created_at) VALUES (?, 'staff', ?, 'human', 1, 1, NOW())");
        $stmt->bind_param('ss', $guestId, $message);
        $stmt->execute();

        // mark the conversation as taken over by staff (bot stops answering for hybrid mode)
        $esc = $conn->real_escape_string($guestId);
        $conn->query("UPDATE chat_sessions SET status = 'staff_assigned', last_message_at = NOW() WHERE guest_id = '{$esc}'");
        $conn->query("UPDATE chat_messages SET is_replied = 1 WHERE guest_id = '{$esc}' AND sender = 'guest'");

        log_staff_activity(
            $conn, 'create', 'chat',
            "Replied to guest chat {$guestId}",
            'chat_session', null
        );

        echo json_encode(['success' => true]);
        exit;
    }

    if ($_GET['action'] === 'close_session' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $guestId = $input['guest_id'] ?? '';
        $esc = $conn->real_escape_string($guestId);
        $conn->query("UPDATE chat_sessions SET status = 'closed' WHERE guest_id = '{$esc}'");

        log_staff_activity(
            $conn, 'update', 'chat',
            "Closed guest chat {$guestId}",
            'chat_session', null
        );

        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);
    exit;
}

include 'ssidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Staff Chat — Dental Clinic</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
  /* Reset body to work with sidebar */
  body {
    font-family: system-ui, sans-serif;
    background: #f7f9fc;
    margin: 0;
    padding: 0;
  }
  
  /* Main content wrapper for sidebar compatibility */
  .main-content {
    margin-left: 270px;
    height: 100vh;
    overflow: hidden;
  }
  
  @media (max-width: 1023px) {
    .main-content {
      margin-left: 0;
    }
  }
  
  /* Chat container */
  .chat-container {
    display: flex;
    height: 100vh;
    background: #f7f9fc;
  }
  
  .session-item {
    cursor: pointer;
    transition: .15s;
  }
  .session-item:hover {
    background: #eef6f4;
  }
  .session-item.active {
    background: #e6f7f5;
    border-left: 3px solid #0d9e8c;
  }
  .bubble {
    max-width: 75%;
    padding: 10px 14px;
    border-radius: 12px;
    font-size: 13px;
    line-height: 1.5;
  }
  .bubble.guest {
    background: #fff;
    align-self: flex-start;
    border-bottom-left-radius: 4px;
    box-shadow: 0 1px 4px rgba(0,0,0,.06);
  }
  .bubble.staff, .bubble.admin {
    background: #0d9e8c;
    color: #fff;
    align-self: flex-end;
    border-bottom-right-radius: 4px;
  }
  .bubble.bot {
    background: #e2e8f0;
    align-self: flex-start;
    border-bottom-left-radius: 4px;
  }
  .badge {
    font-size: 10px;
    background: #e53e3e;
    color: #fff;
    border-radius: 50px;
    padding: 1px 7px;
  }
  
  /* Scrollbar styling */
  .overflow-y-auto::-webkit-scrollbar {
    width: 5px;
  }
  .overflow-y-auto::-webkit-scrollbar-track {
    background: #f1f1f1;
  }
  .overflow-y-auto::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 10px;
  }
  .overflow-y-auto::-webkit-scrollbar-thumb:hover {
    background: #a8a8a8;
  }
  
  /* Responsive adjustments */
  @media (max-width: 768px) {
    .chat-container {
      flex-direction: column;
      height: calc(100vh - 60px);
    }
    .sessions-panel {
      width: 100% !important;
      max-height: 200px;
      border-right: none !important;
      border-bottom: 1px solid #e5e7eb;
    }
    .thread-panel {
      height: calc(100vh - 260px);
    }
  }
</style>
</head>
<body>

<!-- ===== MAIN CONTENT WITH SIDEBAR ===== -->
<div class="main-content">
  
  <div class="chat-container">
    <!-- Sessions list -->
    <div class="sessions-panel" style="width: 320px; background: white; border-right: 1px solid #e5e7eb; display: flex; flex-direction: column; flex-shrink: 0;">
      <div class="p-4 border-b border-gray-200 flex items-center justify-between bg-white">
        <h2 class="font-bold text-sm text-[#0b1628]">
          <i class="fas fa-comments text-[#0d9e8c] mr-1.5"></i>Conversations
        </h2>
        <div class="flex items-center gap-2">
          <span class="text-[11px] text-gray-400">Hi, <?= htmlspecialchars($staffName) ?></span>
          <button onclick="loadSessions()" class="text-[#0d9e8c] hover:text-[#0a7d6e]" title="Refresh">
            <i class="fas fa-sync-alt text-xs"></i>
          </button>
        </div>
      </div>
      <div id="sessionList" class="flex-1 overflow-y-auto" style="background: #fafbfc;"></div>
    </div>

    <!-- Thread -->
    <div class="thread-panel flex-1 flex flex-col" style="min-width: 0;">
      <div id="threadHeader" class="p-4 border-b border-gray-200 bg-white flex items-center justify-between" style="min-height: 65px;">
        <span class="text-sm text-gray-400">Select a conversation to start replying</span>
      </div>
      <div id="threadMessages" class="flex-1 overflow-y-auto p-5 flex flex-col gap-2.5 bg-[#f7f9fc]"></div>
      <div id="threadFooter" class="p-3 border-t border-gray-200 bg-white flex gap-2 hidden">
        <textarea id="replyInput" rows="1" placeholder="Type your reply..." class="flex-1 border-2 border-gray-200 rounded-lg px-3 py-2 text-sm resize-none focus:outline-none focus:border-[#0d9e8c]"></textarea>
        <button id="sendReplyBtn" class="bg-[#0d9e8c] text-white px-5 rounded-lg text-sm font-semibold hover:bg-[#0a7d6e] transition-colors">
          <i class="fas fa-paper-plane mr-1"></i> Send
        </button>
      </div>
    </div>
  </div>
  
</div>

<script>
let currentGuestId = null;
let lastMsgId = 0;
let sessionsPoll = null, messagesPoll = null;

async function loadSessions() {
    try {
        const res = await fetch('?action=list_sessions');
        const data = await res.json();
        if (!data.success) return;
        const list = document.getElementById('sessionList');
        
        if (!data.sessions || data.sessions.length === 0) {
            list.innerHTML = `
                <div class="flex flex-col items-center justify-center h-full p-8 text-center">
                    <i class="fas fa-comment-slash text-3xl text-gray-300 mb-3"></i>
                    <p class="text-xs text-gray-400">No conversations yet</p>
                    <p class="text-[10px] text-gray-300 mt-1">Waiting for patients to start a chat</p>
                </div>
            `;
            return;
        }
        
        list.innerHTML = data.sessions.map(s => `
            <div class="session-item p-3.5 border-b border-gray-50 ${s.guest_id === currentGuestId ? 'active' : ''}" onclick="openSession('${s.guest_id}')">
                <div class="flex justify-between items-center">
                    <span class="text-xs font-semibold text-[#0b1628] flex items-center gap-2">
                        <i class="fas fa-user-circle text-gray-400"></i>
                        ${s.guest_id.replace('guest_','Guest ').slice(0,18)}
                    </span>
                    ${s.unread > 0 ? `<span class="badge">${s.unread}</span>` : ''}
                </div>
                <p class="text-[11px] text-gray-500 truncate mt-1">${(s.last_message || '').replace(/</g,'&lt;')}</p>
                <div class="flex justify-between items-center mt-1">
                    <span class="text-[10px] text-gray-400">${s.status}</span>
                    <span class="text-[10px] text-gray-400">${s.last_message_at}</span>
                </div>
            </div>
        `).join('');
    } catch (e) {
        console.error('Error loading sessions:', e);
    }
}

async function openSession(guestId) {
    currentGuestId = guestId;
    document.getElementById('threadFooter').classList.remove('hidden');
    document.getElementById('threadHeader').innerHTML = `
        <div class="flex items-center gap-3">
            <i class="fas fa-user-circle text-2xl text-gray-400"></i>
            <div>
                <span class="text-sm font-bold text-[#0b1628]">${guestId.replace('guest_', 'Guest ')}</span>
                <span class="text-[10px] text-gray-400 block">Active conversation</span>
            </div>
        </div>
        <div class="flex gap-2">
            <button onclick="closeSession()" class="text-[11px] text-red-500 hover:underline flex items-center gap-1">
                <i class="fas fa-times-circle"></i> Close
            </button>
        </div>
    `;
    await loadMessages();
    loadSessions();
    if (messagesPoll) clearInterval(messagesPoll);
    messagesPoll = setInterval(loadMessages, 4000);
}

async function loadMessages() {
    if (!currentGuestId) return;
    try {
        const res = await fetch('?action=get_messages&guest_id=' + encodeURIComponent(currentGuestId));
        const data = await res.json();
        if (!data.success) return;
        const box = document.getElementById('threadMessages');
        
        if (!data.messages || data.messages.length === 0) {
            box.innerHTML = `
                <div class="flex flex-col items-center justify-center h-full text-center text-gray-400">
                    <i class="fas fa-comment text-2xl mb-2 opacity-50"></i>
                    <p class="text-sm">No messages yet</p>
                    <p class="text-xs">Start the conversation by sending a reply</p>
                </div>
            `;
            return;
        }
        
        box.innerHTML = data.messages.map(m => `
            <div class="bubble ${m.sender}">
                ${m.message.replace(/</g,'&lt;')}
                <div style="font-size:9px;opacity:.6;margin-top:3px;">${m.created_at}</div>
            </div>
        `).join('');
        box.scrollTop = box.scrollHeight;
    } catch (e) {
        console.error('Error loading messages:', e);
    }
}

async function sendReply() {
    const input = document.getElementById('replyInput');
    const message = input.value.trim();
    if (!message || !currentGuestId) return;
    input.value = '';
    input.style.height = 'auto';
    await fetch('?action=send_reply', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({guest_id: currentGuestId, message})
    });
    loadMessages();
}

async function closeSession() {
    if (!currentGuestId) return;
    if (!confirm('Close this conversation? The patient will no longer be able to chat.')) return;
    await fetch('?action=close_session', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({guest_id: currentGuestId})
    });
    currentGuestId = null;
    document.getElementById('threadHeader').innerHTML = `<span class="text-sm text-gray-400">Select a conversation to start replying</span>`;
    document.getElementById('threadMessages').innerHTML = '';
    document.getElementById('threadFooter').classList.add('hidden');
    loadSessions();
}

// Auto-resize textarea
document.getElementById('replyInput').addEventListener('input', function() {
    this.style.height = 'auto';
    this.style.height = Math.min(this.scrollHeight, 100) + 'px';
});

document.getElementById('sendReplyBtn').addEventListener('click', sendReply);
document.getElementById('replyInput').addEventListener('keydown', e => {
    if (e.key === 'Enter' && !e.shiftKey) { 
        e.preventDefault(); 
        sendReply(); 
    }
});

// Initial load
loadSessions();
sessionsPoll = setInterval(loadSessions, 6000);

// Cleanup on page unload
window.addEventListener('beforeunload', function() {
    if (sessionsPoll) clearInterval(sessionsPoll);
    if (messagesPoll) clearInterval(messagesPoll);
});
</script>

</body>
</html>