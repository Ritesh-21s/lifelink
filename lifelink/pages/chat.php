<?php
/**
 * LifeLink – Chat Page
 * /pages/chat.php?id=CONVERSATION_ID
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';

requireLogin();
$userId = currentUserId();
$convId = (int)($_GET['id'] ?? 0);
$db     = getDB();

if ($convId < 1) {
    redirect(APP_URL . '/pages/my_chats.php', 'error', 'Invalid conversation.');
}

// Fetch conversation + request info
$stmt = $db->prepare("
    SELECT c.*,
           r.blood_group, r.hospital, r.urgency, r.status AS req_status,
           r.patient_name, r.city AS req_city, r.needed_by,
           req_u.name AS requester_name,
           res_u.name AS responder_name
    FROM conversations c
    JOIN requests r   ON r.id = c.request_id
    JOIN users req_u  ON req_u.id = c.requester_id
    JOIN users res_u  ON res_u.id = c.responder_id
    WHERE c.id = ?
");
$stmt->execute([$convId]);
$conv = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$conv) {
    redirect(APP_URL . '/pages/my_chats.php', 'error', 'Conversation not found.');
}

// Access check
$isRequester = ($userId === (int)$conv['requester_id']);
$isResponder = ($userId === (int)$conv['responder_id']);
if (!$isRequester && !$isResponder && currentRole() !== 'admin') {
    http_response_code(403); require __DIR__ . '/403.php'; exit;
}

$isClosed   = ($conv['status'] === 'closed');
$otherName  = $isRequester ? $conv['responder_name'] : $conv['requester_name'];
$csrfToken  = csrfToken();

// Load initial messages (last 50)
$msgStmt = $db->prepare("
    SELECT m.id, m.sender_id, m.body, m.created_at, m.read_at,
           u.name AS sender_name
    FROM messages m
    JOIN users u ON u.id = m.sender_id
    WHERE m.conversation_id = ?
    ORDER BY m.id ASC
    LIMIT 200
");
$msgStmt->execute([$convId]);
$messages = $msgStmt->fetchAll(PDO::FETCH_ASSOC);

// Mark all incoming as read
$db->prepare(
    'UPDATE messages SET read_at = NOW()
     WHERE conversation_id = ? AND sender_id != ? AND read_at IS NULL'
)->execute([$convId, $userId]);

$lastId    = empty($messages) ? 0 : (int)end($messages)['id'];
$pageTitle = 'Chat with ' . e($otherName);
require __DIR__ . '/../includes/header.php';
?>
<style>
.chat-container { display:flex; flex-direction:column; height:calc(100vh - 140px); max-width:760px; margin:0 auto; }
.chat-info-bar  { background:var(--navy); color:#fff; border-radius:var(--radius-lg) var(--radius-lg) 0 0; padding:12px 16px; display:flex; align-items:center; gap:12px; flex-shrink:0; }
.chat-info-bar .chat-avatar { width:40px; height:40px; border-radius:50%; background:rgba(255,255,255,0.2); display:grid; place-items:center; font-size:1.2rem; flex-shrink:0; }
.chat-messages  { flex:1; overflow-y:auto; padding:16px; background:#f8f9fa; display:flex; flex-direction:column; gap:8px; }
.chat-bubble    { max-width:72%; padding:10px 14px; border-radius:18px; font-size:var(--text-sm); line-height:1.5; word-break:break-word; }
.bubble-mine    { background:var(--red-600); color:#fff; border-radius:18px 18px 4px 18px; align-self:flex-end; }
.bubble-theirs  { background:#fff; color:var(--gray-800); border:1px solid var(--gray-200); border-radius:18px 18px 18px 4px; align-self:flex-start; }
.bubble-sender  { font-size:10px; font-weight:600; opacity:0.7; margin-bottom:2px; }
.bubble-time    { font-size:10px; opacity:0.6; margin-top:4px; text-align:right; }
.chat-date-sep  { text-align:center; font-size:11px; color:var(--gray-400); font-weight:600; padding:4px 0; }
.chat-input-bar { background:#fff; border:1px solid var(--gray-200); border-top:none; border-radius:0 0 var(--radius-lg) var(--radius-lg); padding:12px; display:flex; gap:8px; align-items:flex-end; flex-shrink:0; }
.chat-input-bar textarea { flex:1; border:1px solid var(--gray-300); border-radius:var(--radius-md); padding:10px 12px; resize:none; font-size:var(--text-sm); line-height:1.4; max-height:120px; }
.chat-input-bar textarea:focus { outline:none; border-color:var(--red-400); }
.chat-send-btn  { background:var(--red-600); color:#fff; border:none; border-radius:var(--radius-md); padding:10px 18px; cursor:pointer; font-weight:600; white-space:nowrap; }
.chat-send-btn:hover { background:var(--red-700); }
.chat-send-btn:disabled { opacity:0.5; cursor:not-allowed; }
.chat-closed-banner { background:#fff3cd; border:1px solid #ffc107; border-radius:var(--radius-md); padding:10px 14px; text-align:center; font-size:var(--text-sm); color:#856404; margin:0 0 8px; flex-shrink:0; }
.char-counter { font-size:10px; color:var(--gray-400); text-align:right; }
.chat-report-btn { font-size:11px; color:var(--gray-400); background:none; border:none; cursor:pointer; padding:0; }
.chat-report-btn:hover { color:var(--red-500); }
.chat-empty { text-align:center; padding:40px 16px; color:var(--gray-400); }
</style>

<div class="section-sm">
<div class="chat-container">
  <!-- Info bar -->
  <div class="chat-info-bar">
    <div class="chat-avatar">💬</div>
    <div style="flex:1;min-width:0">
      <div style="font-weight:700;font-size:var(--text-sm)"><?= e($otherName) ?></div>
      <div style="font-size:11px;opacity:0.75">
        <?= e($conv['blood_group']) ?> · <?= e($conv['hospital']) ?> · <?= e($conv['urgency']) ?>
      </div>
    </div>
    <a href="<?= APP_URL ?>/pages/my_chats.php" class="btn btn-sm" style="background:rgba(255,255,255,0.15);color:#fff;font-size:12px">← Chats</a>
  </div>

  <?php if ($isClosed): ?>
  <div class="chat-closed-banner">
    🔒 This chat is closed: <strong><?= e($conv['closed_reason'] ?? 'Request ended') ?></strong>.
    Messages are read-only.
  </div>
  <?php endif; ?>

  <!-- Messages -->
  <div class="chat-messages" id="chatMessages">
    <?php if (empty($messages)): ?>
    <div class="chat-empty">No messages yet. Say hello! 👋</div>
    <?php else: ?>
    <?php
    $lastDate = '';
    foreach ($messages as $msg):
        $msgDate = date('d M Y', strtotime($msg['created_at']));
        if ($msgDate !== $lastDate):
            $lastDate = $msgDate;
    ?>
    <div class="chat-date-sep"><?= e($msgDate) ?></div>
    <?php endif;
        $mine = ((int)$msg['sender_id'] === $userId);
    ?>
    <div class="chat-bubble <?= $mine ? 'bubble-mine' : 'bubble-theirs' ?>" data-id="<?= $msg['id'] ?>">
      <?php if (!$mine): ?>
      <div class="bubble-sender"><?= e($msg['sender_name']) ?></div>
      <?php endif; ?>
      <?= nl2br(e($msg['body'])) ?>
      <div class="bubble-time"><?= date('h:i A', strtotime($msg['created_at'])) ?></div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- Input -->
  <?php if (!$isClosed): ?>
  <div class="chat-input-bar">
    <div style="flex:1">
      <textarea id="chatInput" placeholder="Type a message… (Enter to send, Shift+Enter for new line)"
                rows="1" maxlength="<?= CHAT_MAX_LENGTH ?>" aria-label="Message"></textarea>
      <div class="char-counter"><span id="charCount">0</span>/<?= CHAT_MAX_LENGTH ?></div>
    </div>
    <button class="chat-send-btn" id="chatSendBtn" type="button">Send</button>
  </div>
  <?php else: ?>
  <div style="padding:12px;text-align:center;background:#fff;border-radius:0 0 var(--radius-lg) var(--radius-lg);">
    <span style="font-size:var(--text-sm);color:var(--gray-400)">Chat is closed and read-only.</span>
  </div>
  <?php endif; ?>
</div>

<!-- Report button -->
<?php if (!$isClosed): ?>
<div style="max-width:760px;margin:8px auto;text-align:right">
  <button class="chat-report-btn" id="reportBtn">⚑ Report this conversation</button>
</div>
<!-- Report modal -->
<div id="reportModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9999;display:none;align-items:center;justify-content:center">
  <div class="card" style="max-width:400px;width:90%;padding:24px">
    <h3 style="margin:0 0 12px">Report Conversation</h3>
    <textarea id="reportReason" placeholder="Describe the issue (min 5 characters)…" rows="4"
              style="width:100%;border:1px solid var(--gray-300);border-radius:var(--radius-md);padding:10px;font-size:var(--text-sm)"></textarea>
    <div style="display:flex;gap:8px;margin-top:12px">
      <button class="btn btn-danger btn-sm" id="submitReportBtn">Submit Report</button>
      <button class="btn btn-outline btn-sm" id="cancelReportBtn">Cancel</button>
    </div>
    <div id="reportMsg" style="margin-top:8px;font-size:var(--text-xs)"></div>
  </div>
</div>
<?php endif; ?>
</div>

<script>
(function(){
const CONV_ID    = <?= $convId ?>;
const USER_ID    = <?= $userId ?>;
const CSRF       = <?= json_encode($csrfToken) ?>;
const CHAT_URL   = <?= json_encode(APP_URL . '/api') ?>;
const IS_CLOSED  = <?= $isClosed ? 'true' : 'false' ?>;
const MAX_LEN    = <?= CHAT_MAX_LENGTH ?>;

let lastId       = <?= $lastId ?>;
let pollTimer    = null;
let isPolling    = false;

const box        = document.getElementById('chatMessages');
const input      = document.getElementById('chatInput');
const sendBtn    = document.getElementById('chatSendBtn');
const charCount  = document.getElementById('charCount');

// ── Auto-scroll to bottom ─────────────────────────────────
function scrollBottom(){ box.scrollTop = box.scrollHeight; }
scrollBottom();

// ── Render a message bubble ───────────────────────────────
function renderBubble(msg) {
    const mine = msg.is_mine || msg.sender_id === USER_ID;

    // Date separator
    const msgDate = new Date(msg.created_at).toLocaleDateString('en-GB', {day:'2-digit', month:'short', year:'numeric'});
    const lastSep = box.querySelector('.chat-date-sep:last-of-type');
    if (!lastSep || lastSep.textContent !== msgDate) {
        const sep  = document.createElement('div');
        sep.className = 'chat-date-sep';
        sep.textContent = msgDate;
        box.appendChild(sep);
    }

    const div = document.createElement('div');
    div.className = 'chat-bubble ' + (mine ? 'bubble-mine' : 'bubble-theirs');
    div.dataset.id = msg.id;
    div.innerHTML = (msg.sender_name && !mine ? `<div class="bubble-sender">${esc(msg.sender_name)}</div>` : '')
        + esc(msg.body).replace(/\n/g, '<br>')
        + `<div class="bubble-time">${new Date(msg.created_at).toLocaleTimeString('en-IN',{hour:'2-digit',minute:'2-digit'})}</div>`;
    box.appendChild(div);
}

// ── Escape HTML ───────────────────────────────────────────
function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
                    .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

// ── Poll for new messages ─────────────────────────────────
function poll() {
    if (isPolling || document.hidden) return;
    isPolling = true;
    fetch(`${CHAT_URL}/chat_messages.php?conversation_id=${CONV_ID}&since_id=${lastId}`)
        .then(r => r.json())
        .then(data => {
            if (data.messages && data.messages.length > 0) {
                const wasAtBottom = box.scrollHeight - box.clientHeight - box.scrollTop < 80;
                data.messages.forEach(m => { renderBubble(m); lastId = Math.max(lastId, m.id); });
                if (wasAtBottom) scrollBottom();
            }
            // Close banner if chat was closed remotely
            if (data.conv_status === 'closed' && !IS_CLOSED) {
                location.reload();
            }
        })
        .catch(() => {})
        .finally(() => { isPolling = false; });
}

if (!IS_CLOSED) {
    pollTimer = setInterval(poll, <?= CHAT_POLL_SECONDS * 1000 ?>);
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) clearInterval(pollTimer);
        else pollTimer = setInterval(poll, <?= CHAT_POLL_SECONDS * 1000 ?>);
    });
}

// ── Send message ──────────────────────────────────────────
function sendMessage() {
    if (!input || IS_CLOSED) return;
    const body = input.value.trim();
    if (!body) return;
    if (body.length > MAX_LEN) return;
    sendBtn.disabled = true;

    const fd = new FormData();
    fd.append('conversation_id', CONV_ID);
    fd.append('body', body);
    fd.append('csrf_token', CSRF);

    // Remove empty-state placeholder
    const empty = box.querySelector('.chat-empty');
    if (empty) empty.remove();

    fetch(`${CHAT_URL}/chat_send.php`, { method:'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                renderBubble(data.message);
                lastId = Math.max(lastId, data.message.id);
                input.value = '';
                charCount.textContent = '0';
                input.style.height = '';
                scrollBottom();
            } else {
                alert(data.error || 'Failed to send.');
            }
        })
        .catch(() => alert('Network error. Please try again.'))
        .finally(() => { sendBtn.disabled = false; input.focus(); });
}

if (input) {
    // Enter to send, Shift+Enter for newline
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
    });
    // Auto-grow textarea
    input.addEventListener('input', function() {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 120) + 'px';
        charCount.textContent = this.value.length;
        charCount.style.color = this.value.length > MAX_LEN * 0.9 ? 'var(--red-500)' : '';
    });
}

if (sendBtn) sendBtn.addEventListener('click', sendMessage);

// ── Report ────────────────────────────────────────────────
const reportBtn    = document.getElementById('reportBtn');
const reportModal  = document.getElementById('reportModal');
const reportReason = document.getElementById('reportReason');
const submitReport = document.getElementById('submitReportBtn');
const cancelReport = document.getElementById('cancelReportBtn');
const reportMsg    = document.getElementById('reportMsg');

if (reportBtn) {
    reportBtn.addEventListener('click', () => { reportModal.style.display = 'flex'; });
}
if (cancelReport) {
    cancelReport.addEventListener('click', () => { reportModal.style.display = 'none'; });
}
if (submitReport) {
    submitReport.addEventListener('click', () => {
        const reason = reportReason.value.trim();
        if (reason.length < 5) { reportMsg.textContent = 'Please provide more detail.'; return; }
        const fd = new FormData();
        fd.append('conversation_id', CONV_ID);
        fd.append('reason', reason);
        fd.append('csrf_token', CSRF);
        fetch(`${CHAT_URL}/chat_report.php`, { method:'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                reportMsg.textContent = d.message || d.error || '';
                if (d.success) setTimeout(() => { reportModal.style.display = 'none'; }, 2000);
            }).catch(() => { reportMsg.textContent = 'Network error.'; });
    });
}

})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>

