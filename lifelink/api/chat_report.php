<?php
/**
 * LifeLink – API: Report a Chat Conversation
 * POST api/chat_report.php  body: conversation_id, reason, csrf_token
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');
startSecureSession();

if (!isLoggedIn()) { http_response_code(401); echo json_encode(['error'=>'Not logged in.']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
if (!csrfValidate()) { http_response_code(403); echo json_encode(['error'=>'Invalid CSRF token.']); exit; }

$userId = currentUserId();
$convId = (int)($_POST['conversation_id'] ?? 0);
$reason = trim(sanitizeInput($_POST['reason'] ?? ''));

if ($convId < 1)        { http_response_code(400); echo json_encode(['error'=>'Missing conversation_id.']); exit; }
if (strlen($reason) < 5){ http_response_code(400); echo json_encode(['error'=>'Please provide a reason (min 5 chars).']); exit; }
if (strlen($reason) > 300){ $reason = mb_substr($reason, 0, 300); }

$db   = getDB();
$conv = $db->prepare('SELECT requester_id, responder_id FROM conversations WHERE id = ?');
$conv->execute([$convId]);
$conv = $conv->fetch(PDO::FETCH_ASSOC);

if (!$conv) { http_response_code(404); echo json_encode(['error'=>'Conversation not found.']); exit; }
if ($userId !== (int)$conv['requester_id'] && $userId !== (int)$conv['responder_id']) {
    http_response_code(403); echo json_encode(['error'=>'Access denied.']); exit;
}

// Prevent duplicate reports from the same user
$dup = $db->prepare('SELECT id FROM chat_reports WHERE conversation_id = ? AND reporter_id = ?');
$dup->execute([$convId, $userId]);
if ($dup->fetch()) {
    http_response_code(409); echo json_encode(['error'=>'You have already reported this conversation.']); exit;
}

$db->prepare(
    'INSERT INTO chat_reports (conversation_id, reporter_id, reason) VALUES (?, ?, ?)'
)->execute([$convId, $userId, $reason]);

echo json_encode(['success' => true, 'message' => 'Report submitted. Our team will review it.']);

