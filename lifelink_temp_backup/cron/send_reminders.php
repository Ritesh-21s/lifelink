<?php
/**
 * LifeLink – Cron: Send eligibility reminder emails
 *
 * Run daily via Windows Task Scheduler or cron.
 * Windows: schtasks /create /sc DAILY /tn "LifeLink Reminders" /tr "php C:\xampp\htdocs\lifelink\cron\send_reminders.php" /st 08:00
 * Linux:   0 8 * * * php /var/www/html/lifelink/cron/send_reminders.php >> /var/log/lifelink_reminders.log 2>&1
 */

// CLI or web guard
if (PHP_SAPI !== 'cli' && ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
    http_response_code(403);
    die('Cron scripts can only run from CLI or localhost.');
}

$base = dirname(__DIR__);
require_once $base . '/config/db.php';
require_once $base . '/config/app.php';
require_once $base . '/includes/eligibility.php';
require_once $base . '/includes/mailer.php';
require_once $base . '/includes/helpers.php';

echo "[" . date('Y-m-d H:i:s') . "] Running eligibility reminder cron...\n";

$db    = getDB();
$today = date('Y-m-d');

// Find donors who:
// 1. Are active and verified
// 2. Have never been reminded for this cycle (reminder_sent_for != today-ish date when they became eligible)
// 3. Their next eligible date is today or in the past
// 4. Have not been reminded for this eligibility cycle already

$donors = $db->query(
    "SELECT d.user_id, d.gender, d.dob, d.last_donation_date, d.is_available, d.verified, d.reminder_sent_for,
            u.name, u.email, u.status
     FROM donors d JOIN users u ON u.id=d.user_id
     WHERE u.status='active' AND d.verified=1 AND d.is_available=1"
)->fetchAll();

$sent   = 0;
$skipped= 0;

foreach ($donors as $donor) {
    $userRow  = ['status' => $donor['status']];
    $elig     = checkEligibility($donor, $userRow);

    if (!$elig['eligible']) { $skipped++; continue; }

    // Calculate next eligible date from last donation
    $nextElig = nextEligibleDate($donor);
    $nextEligStr = $nextElig->format('Y-m-d');

    // Already reminded for this cycle?
    if (!empty($donor['reminder_sent_for']) && $donor['reminder_sent_for'] >= $nextEligStr) {
        $skipped++;
        continue;
    }

    // Check duplicate in email_log (belt+suspenders)
    $chk = $db->prepare(
        "SELECT id FROM email_log WHERE user_id=? AND email_type='eligible_reminder' AND DATE(sent_at)=CURDATE()"
    );
    $chk->execute([$donor['user_id']]);
    if ($chk->fetch()) { $skipped++; continue; }

    // Send reminder
    $ok = mailEligibleReminder($donor['email'], $donor['name']);
    // Log to email_log (mailEligibleReminder calls sendMail which logs it, but set user_id explicitly)
    if ($ok) {
        // Update reminder_sent_for to today so we don't re-send
        $db->prepare("UPDATE donors SET reminder_sent_for=? WHERE user_id=?")->execute([$today, $donor['user_id']]);
        echo "  Sent reminder to: {$donor['email']} ({$donor['name']})\n";
        $sent++;
    } else {
        echo "  FAILED sending to: {$donor['email']}\n";
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Done. Sent: {$sent}, Skipped: {$skipped}\n";
