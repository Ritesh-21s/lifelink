<?php
/**
 * LifeLink – Cron: Process Notification Queue
 *
 * Sends queued emails in batches of 20. Retries failed emails up to 3 times
 * with exponential backoff. Falls back to mail.log if SMTP is not configured.
 *
 * Windows schedule (every minute):
 *   schtasks /create /sc MINUTE /mo 1 /tn "LifeLink Queue" /tr "php C:\xampp\htdocs\lifelink\cron\process_queue.php"
 *
 * Linux cron (every minute):
 *   * * * * * php /var/www/html/lifelink/cron/process_queue.php >> /var/log/lifelink_queue.log 2>&1
 */

if (PHP_SAPI !== 'cli' && ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
    http_response_code(403); die('Cron only.');
}

$base = dirname(__DIR__);
require_once $base . '/config/db.php';
require_once $base . '/config/app.php';
require_once $base . '/includes/mailer.php';
require_once $base . '/includes/helpers.php';

$batchSize = 20;
$maxRetries = 3;

echo '[' . date('Y-m-d H:i:s') . "] Processing notification queue (batch={$batchSize})...\n";

$db = getDB();

// Fetch pending emails (including retries with backoff)
$stmt = $db->prepare("
    SELECT id, user_id, request_id, type, subject, body, attempts
    FROM notification_queue
    WHERE status = 'pending'
      AND send_after <= NOW()
      AND attempts < ?
    ORDER BY send_after ASC
    LIMIT ?
");
$stmt->execute([$maxRetries, $batchSize]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($items)) {
    echo "  No pending items.\n";
    exit(0);
}

$sent   = 0;
$failed = 0;

$stmtUser = $db->prepare('SELECT name, email, notify_email FROM users WHERE id = ?');

foreach ($items as $item) {
    $stmtUser->execute([(int)$item['user_id']]);
    $user = $stmtUser->fetch(PDO::FETCH_ASSOC);

    if (!$user || !$user['notify_email']) {
        // User opted out or deleted; mark sent to remove from queue
        $db->prepare("UPDATE notification_queue SET status='sent', sent_at=NOW() WHERE id=?")->execute([$item['id']]);
        continue;
    }

    $ok = sendMail($user['email'], $user['name'], $item['subject'], $item['body'], (int)$item['user_id'], $item['type']);

    if ($ok) {
        $db->prepare(
            "UPDATE notification_queue SET status='sent', sent_at=NOW(), attempts=attempts+1 WHERE id=?"
        )->execute([$item['id']]);
        $sent++;
        echo "  SENT  [{$item['type']}] → {$user['email']}\n";
    } else {
        $newAttempts = (int)$item['attempts'] + 1;
        $status      = $newAttempts >= $maxRetries ? 'failed' : 'pending';
        // Exponential backoff: 1 min, 5 min, 25 min
        $backoff     = pow(5, $newAttempts) * 60;
        $db->prepare(
            "UPDATE notification_queue
             SET status=?, attempts=?, send_after=DATE_ADD(NOW(), INTERVAL ? SECOND), error=?
             WHERE id=?"
        )->execute([$status, $newAttempts, $backoff, 'SMTP error', $item['id']]);
        $failed++;
        echo "  FAIL  [{$item['type']}] → {$user['email']} (attempt {$newAttempts}/{$maxRetries})\n";
    }
}

echo "Done. Sent: {$sent}, Failed: {$failed}\n";

