<?php
/**
 * LifeLink – Mailer
 * Wraps PHPMailer with SMTP or file-based fallback logging.
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/../config/db.php';

// Try to load PHPMailer if available
$phpMailerLoaded = false;
$phpMailerPaths = [
    __DIR__ . '/../vendor/phpmailer/phpmailer/src/PHPMailer.php',
    __DIR__ . '/../vendor/PHPMailer/PHPMailer.php',
    __DIR__ . '/../vendor/autoload.php',
];
foreach ($phpMailerPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $phpMailerLoaded = true;
        break;
    }
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Send an email. Falls back to file logging if SMTP is not configured.
 *
 * @param string $to        Recipient email
 * @param string $toName    Recipient name
 * @param string $subject   Subject line
 * @param string $body      HTML body
 * @param int|null $userId  User ID for logging
 * @param string $emailType Email type for logging
 * @return bool
 */
function sendMail(string $to, string $toName, string $subject, string $body, ?int $userId = null, string $emailType = 'general'): bool {
    $status = 'failed';

    if (MAIL_USE_SMTP && $GLOBALS['phpMailerLoaded'] ?? false) {
        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = MAIL_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = MAIL_USERNAME;
            $mail->Password   = MAIL_PASSWORD;
            $mail->SMTPSecure = MAIL_ENCRYPTION === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port       = MAIL_PORT;
            $mail->CharSet    = 'UTF-8';

            $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
            $mail->addAddress($to, $toName);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $body;
            $mail->AltBody = strip_tags($body);

            $mail->send();
            $status = 'sent';
        } catch (Throwable $e) {
            error_log('Mailer error: ' . $e->getMessage());
            logMailToFile($to, $subject, $body, 'SMTP ERROR: ' . $e->getMessage());
            $status = 'failed';
        }
    } else {
        // Offline/development mode: log to file
        logMailToFile($to, $subject, $body);
        $status = 'logged';
    }

    // Log to DB
    try {
        $db = getDB();
        $stmt = $db->prepare('INSERT INTO email_log (user_id, email_type, recipient, status) VALUES (?,?,?,?)');
        $stmt->execute([$userId, $emailType, $to, $status]);
    } catch (Throwable $e) {
        error_log('Email log DB error: ' . $e->getMessage());
    }

    return $status !== 'failed';
}

function logMailToFile(string $to, string $subject, string $body, string $note = ''): void {
    $dir = dirname(MAIL_LOG_PATH);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    $line = sprintf(
        "[%s] TO: %s | SUBJECT: %s%s\n%s\n%s\n",
        date('Y-m-d H:i:s'),
        $to,
        $subject,
        $note ? " | NOTE: {$note}" : '',
        strip_tags($body),
        str_repeat('-', 80)
    );
    file_put_contents(MAIL_LOG_PATH, $line, FILE_APPEND | LOCK_EX);
}

// -----------------------------------------------------------
// Email templates
// -----------------------------------------------------------

function mailWelcome(string $to, string $name, string $role): bool {
    $subject = 'Welcome to ' . APP_NAME . '!';
    $body    = emailLayout($subject, "
        <h2>Welcome, {$name}!</h2>
        <p>Thank you for joining <strong>" . APP_NAME . "</strong> as a <strong>{$role}</strong>.</p>
        <p>Your account is under review. An admin will approve it shortly.</p>
        <p>Once approved, you can log in and start making a difference.</p>
        <p style='text-align:center;margin:30px 0'>
            <a href='" . APP_URL . "/pages/login.php' style='background:#c0392b;color:#fff;padding:12px 30px;border-radius:6px;text-decoration:none'>Login to LifeLink</a>
        </p>
    ");
    return sendMail($to, $name, $subject, $body, null, 'welcome');
}

function mailThankYou(string $to, string $donorName, string $patientName, string $hospital): bool {
    $subject = 'Thank you for saving a life – ' . APP_NAME;
    $body    = emailLayout($subject, "
        <h2>You're a hero, {$donorName}! 🎉</h2>
        <p>Your blood donation for <strong>{$patientName}</strong> at <strong>{$hospital}</strong> has been confirmed.</p>
        <p>Every donation saves up to <strong>3 lives</strong>. Thank you for being part of our mission.</p>
        <p>Your next eligible donation date has been updated accordingly.</p>
    ");
    return sendMail($to, $donorName, $subject, $body, null, 'thank_you');
}

function mailUrgentRequest(string $to, string $donorName, array $request, string $areaName): bool {
    $subject = '🚨 Urgent Blood Needed Near You – ' . APP_NAME;
    $body    = emailLayout($subject, "
        <h2>Urgent Request Near You</h2>
        <p>Hi {$donorName}, there is a critical blood request near <strong>{$areaName}</strong>.</p>
        <table style='width:100%;border-collapse:collapse;margin:15px 0'>
            <tr><td style='padding:8px;font-weight:bold'>Blood Group</td><td style='padding:8px'>{$request['blood_group']}</td></tr>
            <tr style='background:#f9f9f9'><td style='padding:8px;font-weight:bold'>Hospital</td><td style='padding:8px'>{$request['hospital']}</td></tr>
            <tr><td style='padding:8px;font-weight:bold'>Units Needed</td><td style='padding:8px'>{$request['units_needed']}</td></tr>
            <tr style='background:#f9f9f9'><td style='padding:8px;font-weight:bold'>Urgency</td><td style='padding:8px'>{$request['urgency']}</td></tr>
        </table>
        <p style='text-align:center;margin:30px 0'>
            <a href='" . APP_URL . "/pages/donor/responses.php' style='background:#c0392b;color:#fff;padding:12px 30px;border-radius:6px;text-decoration:none'>View & Respond</a>
        </p>
    ");
    return sendMail($to, $donorName, $subject, $body, null, 'urgent_request');
}

function mailEligibleReminder(string $to, string $name): bool {
    $subject = 'You can donate blood again! – ' . APP_NAME;
    $body    = emailLayout($subject, "
        <h2>You're eligible to donate again! 💉</h2>
        <p>Hi {$name}, you've completed your rest period and can now donate blood again.</p>
        <p>Your donation can save up to <strong>3 lives</strong>. Log in to mark yourself available.</p>
        <p style='text-align:center;margin:30px 0'>
            <a href='" . APP_URL . "/pages/donor/dashboard.php' style='background:#c0392b;color:#fff;padding:12px 30px;border-radius:6px;text-decoration:none'>Go to Dashboard</a>
        </p>
    ");
    return sendMail($to, $name, $subject, $body, null, 'eligible_reminder');
}

function emailLayout(string $title, string $content): string {
    return "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>{$title}</title></head>
    <body style='margin:0;padding:0;background:#f5f5f5;font-family:Arial,sans-serif'>
    <table width='100%' cellpadding='0' cellspacing='0' style='background:#f5f5f5;padding:30px 0'>
    <tr><td align='center'>
    <table width='600' cellpadding='0' cellspacing='0' style='background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,0.1)'>
    <tr><td style='background:linear-gradient(135deg,#c0392b,#e74c3c);padding:30px;text-align:center'>
        <h1 style='color:#fff;margin:0;font-size:28px'>🩸 " . APP_NAME . "</h1>
        <p style='color:rgba(255,255,255,0.8);margin:5px 0 0'>Blood Donor & Emergency Network</p>
    </td></tr>
    <tr><td style='padding:30px;color:#333;line-height:1.6'>{$content}</td></tr>
    <tr><td style='background:#f9f9f9;padding:20px;text-align:center;font-size:12px;color:#999'>
        This email was sent by " . APP_NAME . ". Do not reply to this email.<br>
        <em>This platform connects donors and requesters. It does not replace hospital or blood bank screening.</em>
    </td></tr>
    </table></td></tr></table></body></html>";
}
