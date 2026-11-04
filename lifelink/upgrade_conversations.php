<?php
/**
 * One-time local repair for databases created by the older chat build.
 * Run only from localhost, then delete this file.
 */
if (PHP_SAPI !== 'cli' && !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . '/config/db.php';
$db = getDB();

try {
    $columns = $db->query('SHOW COLUMNS FROM conversations')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('responder_id', $columns, true)) {
        $db->exec('ALTER TABLE conversations ADD COLUMN responder_id INT UNSIGNED NULL AFTER requester_id');
        $columns[] = 'responder_id';
    }

    // Older builds called the responder donor_id or org_id. Preserve every
    // existing conversation while moving to the single current column.
    if (in_array('donor_id', $columns, true)) {
        $db->exec('UPDATE conversations SET responder_id = donor_id WHERE responder_id IS NULL AND donor_id IS NOT NULL');
    }
    if (in_array('org_id', $columns, true)) {
        $db->exec('UPDATE conversations SET responder_id = org_id WHERE responder_id IS NULL AND org_id IS NOT NULL');
    }

    $missing = (int)$db->query('SELECT COUNT(*) FROM conversations WHERE responder_id IS NULL')->fetchColumn();
    if ($missing > 0) {
        throw new RuntimeException("{$missing} conversation(s) have no responder and need manual review.");
    }
    $db->exec('ALTER TABLE conversations MODIFY COLUMN responder_id INT UNSIGNED NOT NULL');
    echo 'Conversation schema repaired successfully. Delete upgrade_conversations.php now.';
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Upgrade failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
}
