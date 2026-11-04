<?php
/**
 * LifeLink – API: List Conversations (for "My Chats" page and unread badge)
 * GET api/chat_list.php?unread_only=1  → returns just unread count (for navbar)
 * GET api/chat_list.php                → returns full list
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');
startSecureSession();

if (!isLoggedIn()) { http_response_code(401); echo json_encode(['error'=>'Not logged in.']); exit; }

$userId     = currentUserId();
$unreadOnly = !empty($_GET['unread_only']);
$db         = getDB();
$responderSql = conversationResponderSql($db);

if ($unreadOnly) {
    // Just the unread count across all conversations (for navbar badge)
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM messages m
        JOIN conversations c ON c.id = m.conversation_id
        WHERE (c.requester_id = ? OR {$responderSql} = ?)
          AND m.sender_id != ?
          AND m.read_at IS NULL
    ");
    $stmt->execute([$userId, $userId, $userId]);
    echo json_encode(['unread' => (int)$stmt->fetchColumn()]);
    exit;
}

// Full conversation list
$stmt = $db->prepare("
    SELECT
        c.id,
        c.request_id,
        c.requester_id,
        {$responderSql} AS responder_id,
        c.status        AS conv_status,
        c.created_at,
        c.closed_at,
        c.closed_reason,
        r.blood_group,
        r.hospital,
        r.urgency,
        r.status        AS request_status,
        r.city          AS request_city,
        req_u.name      AS requester_name,
        res_u.name      AS responder_name,
        (SELECT body        FROM messages WHERE conversation_id = c.id ORDER BY id DESC LIMIT 1) AS last_message,
        (SELECT created_at  FROM messages WHERE conversation_id = c.id ORDER BY id DESC LIMIT 1) AS last_message_at,
        (SELECT COUNT(*)    FROM messages WHERE conversation_id = c.id AND sender_id != ? AND read_at IS NULL) AS unread_count
    FROM conversations c
    JOIN requests r   ON r.id   = c.request_id
    JOIN users req_u  ON req_u.id = c.requester_id
    JOIN users res_u  ON res_u.id = {$responderSql}
    WHERE c.requester_id = ? OR {$responderSql} = ?
    ORDER BY last_message_at DESC, c.created_at DESC
");
$stmt->execute([$userId, $userId, $userId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$conversations = array_map(fn($r) => [
    'id'             => (int)$r['id'],
    'request_id'     => (int)$r['request_id'],
    'conv_status'    => $r['conv_status'],
    'created_at'     => $r['created_at'],
    'closed_reason'  => $r['closed_reason'],
    'blood_group'    => $r['blood_group'],
    'hospital'       => $r['hospital'],
    'urgency'        => $r['urgency'],
    'request_status' => $r['request_status'],
    'request_city'   => $r['request_city'],
    'requester_name' => $r['requester_name'],
    'responder_name' => $r['responder_name'],
    'last_message'   => $r['last_message'] ? mb_substr($r['last_message'], 0, 80) : null,
    'last_message_at'=> $r['last_message_at'],
    'unread_count'   => (int)$r['unread_count'],
    'i_am'           => ($userId === (int)$r['requester_id']) ? 'requester' : 'responder',
], $rows);

echo json_encode(['conversations' => $conversations]);

