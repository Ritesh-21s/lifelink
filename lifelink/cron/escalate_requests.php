<?php
/*
 * LifeLink – Cron: Escalate Requests (Wave Notifications)
 *
 * For requests with no accepted response after WAVE_TIMEOUT_MIN minutes,
 * fires the next wave of notifications using coordinate-based radius rings.
 * Also closes conversations when requests expire/cancel.
 *
 * Windows (every 5 min):
 *   schtasks /create /sc MINUTE /mo 5 /tn "LifeLink Escalation" /tr "php C:\xampp\htdocs\lifelink\cron\escalate_requests.php"
 * Linux: every-5-min cron:
 *   php /var/www/html/lifelink/cron/escalate_requests.php >> /var/log/lifelink_escalation.log 2>&1
 */

if (PHP_SAPI !== 'cli' && ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
    http_response_code(403); die('Cron only.');
}

$base = dirname(__DIR__);
require_once $base . '/config/db.php';
require_once $base . '/config/app.php';
require_once $base . '/includes/matching.php';
require_once $base . '/includes/mailer.php';
require_once $base . '/includes/helpers.php';

echo '[' . date('Y-m-d H:i:s') . "] Running wave escalation cron...\n";

$db = getDB();

// ── 1. Expire overdue requests ─────────────────────────────────────────────
$db->exec(
    "UPDATE requests SET status='Expired'
     WHERE status='Open' AND expires_at IS NOT NULL AND expires_at < NOW()"
);

// ── 2. Close conversations when request is no longer Open ─────────────────
$db->exec(
    "UPDATE conversations c
     JOIN requests r ON r.id = c.request_id
     SET c.status = 'closed',
         c.closed_at = NOW(),
         c.closed_reason = CONCAT('Request ', r.status)
     WHERE c.status = 'open'
       AND r.status IN ('Fulfilled','Expired','Cancelled')"
);

// ── 3. Update donor ignored_streak for requests they were notified but ignored ─
$db->exec(
    "UPDATE donors d
     JOIN responses r ON r.donor_id = d.user_id
     JOIN requests  req ON req.id = r.request_id
     SET d.ignored_streak = LEAST(d.ignored_streak + 1, 10)
     WHERE r.status = 'notified'
       AND r.responded_at IS NULL
       AND req.status IN ('Fulfilled','Expired','Cancelled','Donor Found')"
);

// ── 4. Find Open requests that need the next wave ──────────────────────────
$critTimeout  = defined('WAVE_TIMEOUT_CRITICAL') ? WAVE_TIMEOUT_CRITICAL : 5;
$normTimeout  = defined('WAVE_TIMEOUT_MIN')      ? WAVE_TIMEOUT_MIN      : 10;
$maxRingIndex = count(defined('RADIUS_RINGS') ? RADIUS_RINGS : [5,10,25,50]) - 1;

$sql = "
    SELECT r.*
    FROM requests r
    WHERE r.status = 'Open'
      AND r.lat IS NOT NULL
      AND r.lng IS NOT NULL
      AND r.wave < ?
      AND (
          (r.urgency = 'Critical'       AND r.wave_notified_at <= DATE_SUB(NOW(), INTERVAL ? MINUTE))
          OR
          (r.urgency != 'Critical'      AND r.wave_notified_at <= DATE_SUB(NOW(), INTERVAL ? MINUTE))
          OR
          r.wave_notified_at IS NULL
      )
      AND (
          SELECT COUNT(*) FROM responses resp
          WHERE resp.request_id = r.id AND resp.status = 'accepted'
      ) < r.units_needed
";
$stmt = $db->prepare($sql);
$stmt->execute([$maxRingIndex + 1, $critTimeout, $normTimeout]);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

$escalated = 0;
foreach ($requests as $req) {
    echo "  Escalating request #{$req['id']} ({$req['blood_group']}, wave {$req['wave']})...\n";
    $result = escalateRequest($req);
    echo "    Wave {$result['wave']}: {$result['notified']} donors notified within {$result['radius']} km\n";
    $escalated++;
}

// ── 5. Send location-missing reminder email to donors (once only) ──────────
$noLocDonors = $db->query(
    "SELECT u.id, u.name, u.email, u.notify_email
     FROM users u
     JOIN donors d ON d.user_id = u.id
     WHERE u.status = 'active'
       AND (d.lat IS NULL OR d.lng IS NULL)
       AND d.location_reminder_sent = 0
       AND u.notify_email = 1"
)->fetchAll(PDO::FETCH_ASSOC);

foreach ($noLocDonors as $donor) {
    $subject = 'Add your location to start receiving blood requests – ' . APP_NAME;
    $body    = emailLayout($subject, "
        <h2 style='color:#c0392b'>📍 Complete Your Profile</h2>
        <p>Hi <strong>" . htmlspecialchars($donor['name']) . "</strong>,</p>
        <p>We noticed your donor profile is missing a location. Without a location,
        we cannot match you with nearby blood requests.</p>
        <p>It only takes 30 seconds — just allow location when prompted, or type your city.</p>
        <p style='text-align:center;margin:24px 0'>
            <a href='" . APP_URL . "/pages/donor/profile.php'
               style='background:#c0392b;color:#fff;padding:12px 30px;border-radius:6px;text-decoration:none;font-weight:bold'>
                Update Location Now
            </a>
        </p>
    ");
    queueMail((int)$donor['id'], null, 'location_reminder', $subject, $body);
    $db->prepare('UPDATE donors SET location_reminder_sent = 1 WHERE user_id = ?')->execute([$donor['id']]);
    echo "  Queued location reminder for {$donor['email']}\n";
}

echo "Done. Escalated: {$escalated} requests, " . count($noLocDonors) . " location reminders queued.\n";
