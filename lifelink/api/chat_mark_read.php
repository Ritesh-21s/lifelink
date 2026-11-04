<?php
/**
 * LifeLink – API: Mark Chat Messages as Read
 * POST api/chat_mark_read.php  body: conversation_id
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

header('Content-Type: application/json; charset=utf-8');
startSecureSession();

if (!isLoggedIn()) { http_response_code(401); echo json_encode(['error'=>'Not logged in.']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error'=>'POST required.']); exit; }
if (!csrfValidate()) { http_response_code(403); echo json_encode(['error'=>'Invalid CSRF token.']); exit; }

$userId = currentUserId();
$convId = (int)($_POST['conversation_id'] ?? 0);
if ($convId < 1) { http_response_code(400); echo json_encode(['error'=>'Missing conversation_id.']); exit; }

$db   = getDB();
$conv = $db->prepare('SELECT requester_id, responder_id FROM conversations WHERE id = ?');
$conv->execute([$convId]);
$conv = $conv->fetch(PDO::FETCH_ASSOC);

if (!$conv) { http_response_code(404); echo json_encode(['error'=>'Not found.']); exit; }
if ($userId !== (int)$conv['requester_id'] && $userId !== (int)$conv['responder_id']) {
    http_response_code(403); echo json_encode(['error'=>'Access denied.']); exit;
}

$db->prepare(
    'UPDATE messages SET read_at = NOW()
     WHERE conversation_id = ? AND sender_id != ? AND read_at IS NULL'
)->execute([$convId, $userId]);

echo json_encode(['success' => true]);

