<?php
/**
 * LifeLink – My Chats (conversation list)
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';

requireLogin();
$userId = currentUserId();
$db     = getDB();
$responderSql = conversationResponderSql($db);

$stmt = $db->prepare("
    SELECT
        c.id, c.request_id, c.requester_id, {$responderSql} AS responder_id, c.status AS conv_status, c.created_at, c.closed_reason,
        r.blood_group, r.hospital, r.urgency, r.status AS req_status, r.city AS req_city,
        req_u.name AS requester_name,
        res_u.name AS responder_name,
        (SELECT body       FROM messages WHERE conversation_id = c.id ORDER BY id DESC LIMIT 1) AS last_message,
        (SELECT created_at FROM messages WHERE conversation_id = c.id ORDER BY id DESC LIMIT 1) AS last_message_at,
        (SELECT COUNT(*)   FROM messages WHERE conversation_id = c.id AND sender_id != ? AND read_at IS NULL) AS unread_count
    FROM conversations c
    JOIN requests r   ON r.id = c.request_id
    JOIN users req_u  ON req_u.id = c.requester_id
    JOIN users res_u  ON res_u.id = {$responderSql}
    WHERE c.requester_id = ? OR {$responderSql} = ?
    ORDER BY last_message_at DESC, c.created_at DESC
");
$stmt->execute([$userId, $userId, $userId]);
$conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'My Chats';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <div class="container">
    <h1>💬 My Chats</h1>
    <p>Conversations with donors and requesters</p>
  </div>
</div>

<section class="section-sm">
<div class="container" style="max-width:720px">

<?php if (empty($conversations)): ?>
<div class="card">
  <div class="card-body" style="text-align:center;padding:48px">
    <div style="font-size:3rem;margin-bottom:12px">💬</div>
    <h3>No conversations yet</h3>
    <p style="color:var(--gray-500)">Chats open automatically when a donor accepts a blood request.</p>
  </div>
</div>
<?php else: ?>

<div style="display:flex;flex-direction:column;gap:8px">
<?php foreach ($conversations as $conv):
    $isRequester = ($userId === (int)$conv['requester_id']);
    $otherName = $isRequester ? $conv['responder_name'] : $conv['requester_name'];
?>
<a href="<?= APP_URL ?>/pages/chat.php?id=<?= $conv['id'] ?>" class="card card-hover" style="text-decoration:none;color:inherit">
  <div class="card-body" style="display:flex;gap:12px;align-items:center;padding:14px 16px">
    <div style="width:44px;height:44px;border-radius:50%;background:var(--red-100);display:grid;place-items:center;font-size:1.3rem;flex-shrink:0">
      <?= $conv['conv_status'] === 'closed' ? '🔒' : '💬' ?>
    </div>
    <div style="flex:1;min-width:0">
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
        <span style="font-weight:600;font-size:var(--text-sm)"><?= e($otherName) ?></span>
        <?php if ($conv['unread_count'] > 0): ?>
        <span class="badge" style="background:var(--red-600);color:#fff;border-radius:99px;padding:1px 7px;font-size:10px"><?= $conv['unread_count'] ?></span>
        <?php endif; ?>
        <?php if ($conv['conv_status'] === 'closed'): ?>
        <span class="badge" style="background:var(--gray-200);color:var(--gray-600);border-radius:4px;font-size:10px;padding:1px 6px">Closed</span>
        <?php endif; ?>
      </div>
      <div style="font-size:12px;color:var(--gray-500);margin:2px 0">
        <?= e($conv['blood_group']) ?> · <?= e($conv['hospital']) ?> · <?= e($conv['req_city'] ?? '') ?>
      </div>
      <?php if ($conv['last_message']): ?>
      <div style="font-size:12px;color:var(--gray-600);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:400px">
        <?= e(mb_substr($conv['last_message'], 0, 80)) ?>
      </div>
      <?php else: ?>
      <div style="font-size:12px;color:var(--gray-400);font-style:italic">No messages yet — say hello!</div>
      <?php endif; ?>
    </div>
    <div style="text-align:right;flex-shrink:0;font-size:11px;color:var(--gray-400)">
      <?= $conv['last_message_at'] ? timeAgo($conv['last_message_at']) : timeAgo($conv['created_at']) ?>
    </div>
  </div>
</a>
<?php endforeach; ?>
</div>
<?php endif; ?>

</div>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>

