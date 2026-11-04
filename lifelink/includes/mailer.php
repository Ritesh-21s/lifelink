<?php
/**
 * LifeLink – Mailer
 * Uses PHPMailer for Gmail SMTP. Falls back to file logging if SMTP fails.
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/../config/db.php';

// ── Load PHPMailer ──────────────────────────────────────────
$_phpMailerSrc = __DIR__ . '/../vendor/phpmailer/phpmailer/src/';
if (file_exists($_phpMailerSrc . 'PHPMailer.php')) {
    require_once $_phpMailerSrc . 'Exception.php';
    require_once $_phpMailerSrc . 'SMTP.php';
    require_once $_phpMailerSrc . 'PHPMailer.php';
    define('PHPMAILER_LOADED', true);
} else {
    define('PHPMAILER_LOADED', false);
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as MailException;

// ── Ensure log directory exists ─────────────────────────────
if (!is_dir(dirname(MAIL_LOG_PATH))) {
    @mkdir(dirname(MAIL_LOG_PATH), 0755, true);
}

/**
 * Core send function. Always logs. Sends via SMTP if configured.
 */
function sendMail(string $to, string $toName, string $subject, string $body, ?int $userId = null, string $emailType = 'general'): bool {
    $status = 'logged';

    if (MAIL_USE_SMTP && PHPMAILER_LOADED) {
        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = MAIL_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = MAIL_USERNAME;
            $mail->Password   = MAIL_PASSWORD;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = MAIL_PORT;
            $mail->CharSet    = 'UTF-8';
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer'       => false,
                    'verify_peer_name'  => false,
                    'allow_self_signed' => true,
                ],
            ];
            $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
            $mail->addAddress($to, $toName);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $body;
            $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $body));
            $mail->send();
            $status = 'sent';
        } catch (Throwable $e) {
            error_log('Mailer SMTP error: ' . $e->getMessage());
            // Always fall through to file log so link is never lost
            logMailToFile($to, $subject, $body, 'SMTP ERROR: ' . $e->getMessage());
            $status = 'failed';
        }
    } else {
        logMailToFile($to, $subject, $body, PHPMAILER_LOADED ? '' : 'PHPMailer not loaded');
        $status = 'logged';
    }

    // Log to DB (non-fatal if fails)
    try {
        $db   = getDB();
        $stmt = $db->prepare('INSERT INTO email_log (user_id, email_type, recipient, status) VALUES (?,?,?,?)');
        $stmt->execute([$userId, $emailType, $to, $status]);
    } catch (Throwable $e) { /* ignore */ }

    return $status === 'sent' || $status === 'logged';
}

function logMailToFile(string $to, string $subject, string $body, string $note = ''): void {
    $logPath = MAIL_LOG_PATH;
    $dir     = dirname($logPath);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    $entry = sprintf(
        "[%s]\nTO: %s\nSUBJECT: %s\n%s\n%s\n%s\n",
        date('Y-m-d H:i:s'),
        $to,
        $subject,
        $note ? "NOTE: {$note}" : '',
        strip_tags(str_replace(['<br>', '<br/>', '<a href='], ["\n", "\n", "\nLINK: "], $body)),
        str_repeat('-', 80)
    );
    file_put_contents($logPath, $entry, FILE_APPEND | LOCK_EX);
}

// ──────────────────────────────────────────────────────────────
// Email Templates
// ──────────────────────────────────────────────────────────────

function mailVerification(string $to, string $name, string $token): bool {
    $link    = APP_URL . '/pages/verify.php?token=' . urlencode($token);
    $subject = 'Verify your email – ' . APP_NAME;
    $body    = emailLayout($subject, "
        <h2 style='color:#c0392b;margin:0 0 16px'>🩸 Verify Your Email Address</h2>
        <p>Hi <strong>" . htmlspecialchars($name) . "</strong>, thank you for joining <strong>" . APP_NAME . "</strong>!</p>
        <p>Click the button below to verify your email and activate your account.
           This link expires in <strong>24 hours</strong>.</p>
        <p style='text-align:center;margin:35px 0'>
            <a href='{$link}'
               style='background:linear-gradient(135deg,#c0392b,#e74c3c);color:#fff;
                      padding:16px 40px;border-radius:8px;text-decoration:none;
                      font-size:16px;font-weight:bold;display:inline-block;letter-spacing:0.5px'>
                ✅ Verify My Email
            </a>
        </p>
        <p style='font-size:13px;color:#666'>If the button doesn't work, copy this link into your browser:</p>
        <p style='font-size:12px;word-break:break-all;background:#f5f5f5;padding:10px 14px;border-radius:6px;border-left:4px solid #c0392b'>{$link}</p>
        <hr style='border:none;border-top:1px solid #eee;margin:24px 0'>
        <p style='font-size:12px;color:#999'>If you didn't register, ignore this email.</p>
    ");
    return sendMail($to, $name, $subject, $body, null, 'verification');
}

function mailWelcome(string $to, string $name, string $role): bool {
    $subject = 'Welcome to ' . APP_NAME . ' – Account Verified!';
    $body    = emailLayout($subject, "
        <h2>Welcome, " . htmlspecialchars($name) . "! 🎉</h2>
        <p>Your email is verified. Your <strong>{$role}</strong> account is now under admin review.</p>
        <p>You will be notified once approved. Once approved, log in and start saving lives.</p>
        <p style='text-align:center;margin:30px 0'>
            <a href='" . APP_URL . "/pages/login.php'
               style='background:#c0392b;color:#fff;padding:12px 30px;border-radius:6px;text-decoration:none;font-weight:bold'>
                Login to LifeLink
            </a>
        </p>
    ");
    return sendMail($to, $name, $subject, $body, null, 'welcome');
}

function mailThankYou(string $to, string $donorName, string $patientName, string $hospital): bool {
    $subject = 'Thank you for saving a life – ' . APP_NAME;
    $body    = emailLayout($subject, "
        <h2>You're a hero, " . htmlspecialchars($donorName) . "! 🎉</h2>
        <p>Your donation for <strong>" . htmlspecialchars($patientName) . "</strong> at <strong>" . htmlspecialchars($hospital) . "</strong> is confirmed.</p>
        <p>Every donation saves up to <strong>3 lives</strong>. Thank you.</p>
    ");
    return sendMail($to, $donorName, $subject, $body, null, 'thank_you');
}

function mailUrgentRequest(string $to, string $donorName, array $request, string $areaName): bool {
    $subject = '🚨 Urgent Blood Needed Near You – ' . APP_NAME;
    $bg      = htmlspecialchars($request['blood_group'] ?? '');
    $hosp    = htmlspecialchars($request['hospital'] ?? '');
    $units   = (int)($request['units_needed'] ?? 1);
    $urg     = htmlspecialchars($request['urgency'] ?? '');
    $body    = emailLayout($subject, "
        <h2>Urgent Request Near You</h2>
        <p>Hi " . htmlspecialchars($donorName) . ", there is a critical blood request near <strong>" . htmlspecialchars($areaName) . "</strong>.</p>
        <table style='width:100%;border-collapse:collapse;margin:15px 0'>
            <tr><td style='padding:8px;font-weight:bold'>Blood Group</td><td style='padding:8px'>{$bg}</td></tr>
            <tr style='background:#f9f9f9'><td style='padding:8px;font-weight:bold'>Hospital</td><td style='padding:8px'>{$hosp}</td></tr>
            <tr><td style='padding:8px;font-weight:bold'>Units Needed</td><td style='padding:8px'>{$units}</td></tr>
            <tr style='background:#f9f9f9'><td style='padding:8px;font-weight:bold'>Urgency</td><td style='padding:8px'>{$urg}</td></tr>
        </table>
        <p style='text-align:center;margin:30px 0'>
            <a href='" . APP_URL . "/pages/donor/responses.php'
               style='background:#c0392b;color:#fff;padding:12px 30px;border-radius:6px;text-decoration:none;font-weight:bold'>
                View &amp; Respond
            </a>
        </p>
    ");
    return sendMail($to, $donorName, $subject, $body, null, 'urgent_request');
}

function mailEligibleReminder(string $to, string $name): bool {
    $subject = 'You can donate blood again! – ' . APP_NAME;
    $body    = emailLayout($subject, "
        <h2>You're eligible to donate again! 💉</h2>
        <p>Hi " . htmlspecialchars($name) . ", your rest period is complete. You can donate blood again.</p>
        <p>Log in and mark yourself available — your donation can save up to <strong>3 lives</strong>.</p>
        <p style='text-align:center;margin:30px 0'>
            <a href='" . APP_URL . "/pages/donor/dashboard.php'
               style='background:#c0392b;color:#fff;padding:12px 30px;border-radius:6px;text-decoration:none;font-weight:bold'>
                Go to Dashboard
            </a>
        </p>
    ");
    return sendMail($to, $name, $subject, $body, null, 'eligible_reminder');
}

// ──────────────────────────────────────────────────────────────
// Notification Queue (never send inline; let cron/process_queue.php handle it)
// ──────────────────────────────────────────────────────────────

/**
 * Insert an email into the notification_queue for async delivery.
 * Call this instead of sendMail() from matching and chat code.
 */
function queueMail(int $userId, ?int $requestId, string $type, string $subject, string $body, int $delaySecs = 0): void {
    try {
        $db   = getDB();
        $stmt = $db->prepare(
            'INSERT INTO notification_queue (user_id, request_id, type, subject, body, send_after)
             VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))'
        );
        $stmt->execute([$userId, $requestId, $type, $subject, $body, $delaySecs]);
    } catch (Throwable $e) {
        error_log('queueMail failed: ' . $e->getMessage());
    }
}

function emailLayout(string $title, string $content): string {
    return "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>" . htmlspecialchars($title) . "</title></head>
    <body style='margin:0;padding:0;background:#f5f5f5;font-family:Arial,sans-serif'>
    <table width='100%' cellpadding='0' cellspacing='0' style='background:#f5f5f5;padding:30px 0'>
    <tr><td align='center'>
    <table width='600' cellpadding='0' cellspacing='0' style='background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,0.1)'>
    <tr><td style='background:linear-gradient(135deg,#c0392b,#e74c3c);padding:30px;text-align:center'>
        <h1 style='color:#fff;margin:0;font-size:28px'>🩸 " . APP_NAME . "</h1>
        <p style='color:rgba(255,255,255,0.8);margin:5px 0 0'>Blood Donor &amp; Emergency Network</p>
    </td></tr>
    <tr><td style='padding:30px;color:#333;line-height:1.6'>{$content}</td></tr>
    <tr><td style='background:#f9f9f9;padding:20px;text-align:center;font-size:12px;color:#999'>
        Sent by " . APP_NAME . ". Do not reply to this email.<br>
        <em>This platform does not replace hospital or blood bank screening.</em>
    </td></tr>
    </table></td></tr></table></body></html>";
}
