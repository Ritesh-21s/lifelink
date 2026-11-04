<?php
/**
 * LifeLink – API: Get Chat Messages (polling endpoint)
 * GET api/chat_messages.php?conversation_id=X&since_id=Y
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');
startSecureSession();

if (!isLoggedIn()) { http_response_code(401); echo json_encode(['error'=>'Not logged in.']); exit; }

$userId = currentUserId();
$convId  = (int)($_GET['conversation_id'] ?? 0);
$sinceId = (int)($_GET['since_id'] ?? 0);

if ($convId < 1) { http_response_code(400); echo json_encode(['error'=>'Missing conversation_id.']); exit; }

$db   = getDB();
$conv = $db->prepare('SELECT * FROM conversations WHERE id = ?');
$conv->execute([$convId]);
$conv = $conv->fetch(PDO::FETCH_ASSOC);

if (!$conv) { http_response_code(404); echo json_encode(['error'=>'Not found.']); exit; }

$isParticipant = ($userId === (int)$conv['requester_id'] || $userId === (int)$conv['responder_id']);
if (!$isParticipant && currentRole() !== 'admin') {
    http_response_code(403); echo json_encode(['error'=>'Access denied.']); exit;
}

// Fetch messages newer than since_id
$stmt = $db->prepare(
    'SELECT m.id, m.sender_id, m.body, m.created_at, m.read_at,
            u.name AS sender_name
     FROM messages m
     JOIN users u ON u.id = m.sender_id
     WHERE m.conversation_id = ? AND m.id > ?
     ORDER BY m.id ASC
     LIMIT 100'
);
$stmt->execute([$convId, $sinceId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Mark fetched messages as read (if sent to us)
if (!empty($rows)) {
    $ids = implode(',', array_map('intval', array_column($rows, 'id')));
    $db->exec(
        "UPDATE messages SET read_at = NOW()
         WHERE conversation_id = {$convId}
           AND sender_id != {$userId}
           AND read_at IS NULL"
    );
}

$messages = array_map(fn($r) => [
    'id'          => (int)$r['id'],
    'sender_id'   => (int)$r['sender_id'],
    'sender_name' => $r['sender_name'],
    'body'        => $r['body'],
    'created_at'  => $r['created_at'],
    'read_at'     => $r['read_at'],
    'is_mine'     => (int)$r['sender_id'] === $userId,
], $rows);

echo json_encode([
    'messages'     => $messages,
    'conv_status'  => $conv['status'],
    'closed_reason'=> $conv['closed_reason'],
]);

