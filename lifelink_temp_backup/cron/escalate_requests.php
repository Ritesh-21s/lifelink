<?php
/**
 * LifeLink – Cron: Escalate Critical Requests
 *
 * For Critical requests with no accepted response after ESCALATION_MINUTES,
 * widens the search to adjacent areas and notifies additional donors.
 *
 * Windows: schtasks /create /sc MINUTE /mo 5 /tn "LifeLink Escalation" /tr "php C:\xampp\htdocs\lifelink\cron\escalate_requests.php"
 * Linux:   */5 * * * * php /var/www/html/lifelink/cron/escalate_requests.php >> /var/log/lifelink_escalation.log 2>&1
 */

if (PHP_SAPI !== 'cli' && ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
    http_response_code(403);
    die('Cron scripts can only run from CLI or localhost.');
}

$base = dirname(__DIR__);
require_once $base . '/config/db.php';
require_once $base . '/config/app.php';
require_once $base . '/includes/matching.php';
require_once $base . '/includes/mailer.php';
require_once $base . '/includes/helpers.php';

echo "[" . date('Y-m-d H:i:s') . "] Running escalation cron...\n";

$db = getDB();

// Find Critical requests that:
// 1. Are still Open
// 2. Have 0 accepted responses
// 3. Were created more than ESCALATION_MINUTES ago
// 4. Have not been escalated yet

$threshold = date('Y-m-d H:i:s', time() - ESCALATION_MINUTES * 60);

$stmt = $db->query(
    "SELECT r.* FROM requests r
     WHERE r.urgency='Critical'
       AND r.status='Open'
       AND r.escalated_at IS NULL
       AND r.created_at <= '{$threshold}'
       AND (
           SELECT COUNT(*) FROM responses resp
           WHERE resp.request_id=r.id AND resp.status='accepted'
       ) = 0"
);
$criticals = $stmt->fetchAll();

$escalated = 0;

foreach ($criticals as $req) {
    echo "  Escalating request #{$req['id']} – {$req['patient_name']} ({$req['blood_group']})\n";

    // Mark as escalated
    $db->prepare("UPDATE requests SET escalated_at=NOW() WHERE id=?")->execute([$req['id']]);

    // Notify adjacent-area donors (expanded=true)
    $notified = notifyMatchingDonors($req, true);

    // Send urgent email to newly notified donors
    if ($notified > 0) {
        $newDonors = $db->prepare(
            "SELECT u.email, u.name, a.name AS area_name
             FROM responses resp
             JOIN users u ON u.id=resp.donor_id
             JOIN donors d ON d.user_id=resp.donor_id
             JOIN areas a ON a.id=d.area_id
             WHERE resp.request_id=? AND resp.status='notified'
               AND resp.responded_at IS NULL"
        );
        $newDonors->execute([$req['id']]);
        foreach ($newDonors->fetchAll() as $d) {
            mailUrgentRequest($d['email'], $d['name'], $req, $d['area_name']);
        }
        echo "    Notified {$notified} additional donor(s) in adjacent areas.\n";
    } else {
        echo "    No additional donors found in adjacent areas.\n";
    }

    $escalated++;
}

// Also expire old requests past their expires_at
$expired = $db->exec(
    "UPDATE requests SET status='Expired'
     WHERE status IN ('Open') AND expires_at IS NOT NULL AND expires_at < NOW()"
);
if ($expired > 0) echo "  Expired {$expired} old request(s).\n";

echo "[" . date('Y-m-d H:i:s') . "] Done. Escalated: {$escalated} request(s).\n";
