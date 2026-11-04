<?php
/**
 * LifeLink – Admin: Dismiss a chat report (no action needed)
 */
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireAdmin();
$db    = getDB();
$repId = (int)($_GET['report_id'] ?? 0);

if ($repId) {
    $db->prepare(
        "UPDATE chat_reports SET status='dismissed', reviewed_at=NOW() WHERE id=?"
    )->execute([$repId]);
}

redirect(APP_URL . '/pages/admin/dashboard.php', 'success', 'Report dismissed.');

