<?php
/**
 * LifeLink – API: Send Chat Message
 * POST api/chat_send.php
 * Body: conversation_id, body, csrf_token
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

startSecureSession();
if (!isLoggedIn()) { http_response_code(401); echo json_encode(['error'=>'Not logged in.']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error'=>'POST required.']); exit; }
if (!csrfValidate()) { http_response_code(403); echo json_encode(['error'=>'Invalid CSRF token.']); exit; }

$userId = currentUserId();
$convId = (int)($_POST['conversation_id'] ?? 0);
$body   = trim($_POST['body'] ?? '');

if ($convId < 1) { http_response_code(400); echo json_encode(['error'=>'Missing conversation_id.']); exit; }
if ($body === '') { http_response_code(400); echo json_encode(['error'=>'Message cannot be empty.']); exit; }
if (mb_strlen($body) > CHAT_MAX_LENGTH) { http_response_code(400); echo json_encode(['error'=>'Message too long (max '.CHAT_MAX_LENGTH.' chars).']); exit; }

$db = getDB();

// Fetch conversation and verify ownership
$conv = $db->prepare('SELECT * FROM conversations WHERE id = ?');
$conv->execute([$convId]);
$conv = $conv->fetch(PDO::FETCH_ASSOC);

if (!$conv) { http_response_code(404); echo json_encode(['error'=>'Conversation not found.']); exit; }

$isParticipant = ($userId === (int)$conv['requester_id'] || $userId === (int)$conv['responder_id']);
if (!$isParticipant && currentRole() !== 'admin') {
    http_response_code(403); echo json_encode(['error'=>'Access denied.']); exit;
}

if ($conv['status'] !== 'open') {
    http_response_code(403); echo json_encode(['error'=>'This chat is closed: ' . $conv['closed_reason']]); exit;
}

// Rate limit: 20 messages per minute per user
$rateKey = "chat_rate_{$userId}";
$now     = time();
if (!isset($_SESSION[$rateKey])) $_SESSION[$rateKey] = [];
$_SESSION[$rateKey] = array_filter($_SESSION[$rateKey], fn($t) => $now - $t < 60);
if (count($_SESSION[$rateKey]) >= CHAT_RATE_LIMIT_PER_MIN) {
    http_response_code(429); echo json_encode(['error'=>'Rate limit: max '.CHAT_RATE_LIMIT_PER_MIN.' messages per minute.']); exit;
}
$_SESSION[$rateKey][] = $now;

// Insert message
$stmt = $db->prepare(
    'INSERT INTO messages (conversation_id, sender_id, body) VALUES (?, ?, ?)'
);
$stmt->execute([$convId, $userId, $body]);
$msgId = (int)$db->lastInsertId();

// Fetch back with created_at
$row = $db->prepare('SELECT id, sender_id, body, created_at FROM messages WHERE id = ?');
$row->execute([$msgId]);
$msg = $row->fetch(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'message' => [
        'id'          => (int)$msg['id'],
        'sender_id'   => (int)$msg['sender_id'],
        'body'        => $msg['body'],
        'created_at'  => $msg['created_at'],
        'is_mine'     => true,
    ],
]);

