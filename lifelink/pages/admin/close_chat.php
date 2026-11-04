<?php
/**
 * LifeLink – Admin: Close a reported conversation
 */
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireAdmin();
$db     = getDB();
$convId = (int)($_GET['conv_id']   ?? 0);
$repId  = (int)($_GET['report_id'] ?? 0);

if ($convId) {
    $db->prepare(
        "UPDATE conversations SET status='closed', closed_at=NOW(), closed_reason='Admin action' WHERE id=?"
    )->execute([$convId]);
}
if ($repId) {
    $db->prepare(
        "UPDATE chat_reports SET status='reviewed', reviewed_at=NOW() WHERE id=?"
    )->execute([$repId]);
}

redirect(APP_URL . '/pages/admin/dashboard.php', 'success', 'Conversation closed and report marked reviewed.');

