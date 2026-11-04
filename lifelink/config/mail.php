<?php
/**
 * LifeLink – Mail Configuration
 *
 * ╔══════════════════════════════════════════════════════════╗
 * ║   HOW TO SET UP GMAIL APP PASSWORD                      ║
 * ║   1. Go to https://myaccount.google.com/security        ║
 * ║   2. Enable 2-Step Verification (if not already)        ║
 * ║   3. Search "App Passwords" → Create one for "Mail"     ║
 * ║   4. Copy the 16-char password (e.g. abcd efgh ijkl mn) ║
 * ║   5. Paste it in MAIL_PASSWORD below (no spaces)        ║
 * ╚══════════════════════════════════════════════════════════╝
 */

// ─── CONFIGURE THESE TWO LINES ──────────────────────────────
define('MAIL_USERNAME',  'riteshdvas@gmail.com');
define('MAIL_PASSWORD',  'vorgeraygcojqxlf');          // ← App Password (spaces removed)

// ────────────────────────────────────────────────────────────

define('MAIL_USE_SMTP',   true);
define('MAIL_HOST',       'smtp.gmail.com');
define('MAIL_PORT',       587);
define('MAIL_ENCRYPTION', 'tls');
define('MAIL_FROM_EMAIL', MAIL_USERNAME);
define('MAIL_FROM_NAME',  APP_NAME);
define('MAIL_LOG_PATH',   __DIR__ . '/../storage/logs/mail.log');
