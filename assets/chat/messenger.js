/* ============================================================
   Shared Messenger-style chat helpers
   Include on every role's page:
   <script src="[...]/assets/chat/messenger.js"></script>
   ============================================================ */

const MSGR_AVATAR_COLORS = [
    '#0d9e8c', '#7c3aed', '#e0793c', '#2563eb', '#db2777',
    '#059669', '#d97706', '#4f46e5', '#dc2626', '#0891b2'
];

function msgrInitials(name) {
    if (!name) return '?';
    const parts = name.trim().split(/\s+/);
    const first = parts[0]?.[0] || '';
    const second = parts.length > 1 ? parts[parts.length - 1][0] : '';
    return (first + second).toUpperCase();
}

function msgrColorFor(name) {
    let hash = 0;
    for (let i = 0; i < (name || '').length; i++) hash = name.charCodeAt(i) + ((hash << 5) - hash);
    return MSGR_AVATAR_COLORS[Math.abs(hash) % MSGR_AVATAR_COLORS.length];
}

/**
 * Returns avatar HTML. Pass onlineState = true/false to show a status dot,
 * or null/undefined to omit the dot entirely.
 */
function msgrAvatar(name, size = 40, onlineState = null) {
    const sizeClass = size === 32 ? 'size-32' : (size === 28 ? 'size-28' : '');
    const dot = (onlineState === null || onlineState === undefined)
        ? ''
        : `<span class="msgr-online-dot ${onlineState ? '' : 'offline'}"></span>`;
    return `<div class="msgr-avatar ${sizeClass}" style="background:${msgrColorFor(name)}">${msgrInitials(name)}${dot}</div>`;
}

function msgrTime(dateStr) {
    if (!dateStr) return '';
    const d = new Date(dateStr.replace(' ', 'T'));
    if (isNaN(d.getTime())) return dateStr;
    const now = new Date();
    const isToday = d.toDateString() === now.toDateString();
    const timeStr = d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    if (isToday) return timeStr;
    const isThisYear = d.getFullYear() === now.getFullYear();
    const dateStrShort = d.toLocaleDateString([], isThisYear ? { month: 'short', day: 'numeric' } : { month: 'short', day: 'numeric', year: 'numeric' });
    return `${dateStrShort} ${timeStr}`;
}

/**
 * Groups consecutive messages from the same sender within a 3-minute
 * window, Messenger-style. Mutates and returns the array with two flags
 * added to each message: `groupEnd` (last of its run — show avatar/time)
 * and `grouped` (not the run's end — tighten spacing/radius).
 */
function msgrGroupMessages(messages) {
    const GAP_MS = 3 * 60 * 1000;
    return messages.map((m, i) => {
        const next = messages[i + 1];
        const sameSenderAsNext = next && next.sender_role === m.sender_role;
        const closeToNext = next && (new Date(next.created_at.replace(' ', 'T')) - new Date(m.created_at.replace(' ', 'T'))) < GAP_MS;
        const groupEnd = !(sameSenderAsNext && closeToNext);
        return { ...m, groupEnd, grouped: !groupEnd };
    });
}

function msgrTypingDotsHtml() {
    return `<div class="msgr-typing-bubble"><span></span><span></span><span></span></div>`;
}

/**
 * Wires up a minimize/reopen toggle for the chat box. Purely client-side —
 * hides `shellSelector` and shows a floating round bubble in its place;
 * clicking the bubble brings it back. State is remembered per-browser via
 * localStorage under `storageKey`, so each user's hide/show choice is their
 * own and never depends on another role reopening anything.
 */
function msgrInitMinimizeToggle(storageKey, shellSelector) {
    const shell = document.querySelector(shellSelector);
    if (!shell) return;

    let bubble = document.getElementById('msgrFloatBubble');
    if (!bubble) {
        bubble = document.createElement('button');
        bubble.id = 'msgrFloatBubble';
        bubble.className = 'msgr-float-bubble msgr-hidden';
        bubble.innerHTML = '<i class="fas fa-comment-dots"></i>';
        bubble.title = 'Open chat';
        document.body.appendChild(bubble);
    }

    function setHidden(hidden) {
        if (hidden) {
            shell.classList.add('msgr-hidden');
            bubble.classList.remove('msgr-hidden');
        } else {
            shell.classList.remove('msgr-hidden');
            bubble.classList.add('msgr-hidden');
        }
        try { localStorage.setItem(storageKey, hidden ? '1' : '0'); } catch (e) {}
    }

    bubble.addEventListener('click', () => setHidden(false));

    let initiallyHidden = false;
    try { initiallyHidden = localStorage.getItem(storageKey) === '1'; } catch (e) {}
    setHidden(initiallyHidden);

    return setHidden;
}