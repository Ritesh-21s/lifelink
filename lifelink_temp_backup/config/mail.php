<?php
/**
 * LifeLink – Mail Configuration
 *
 * Fill in your Gmail SMTP credentials.
 * If MAIL_USE_SMTP = false, emails are logged to storage/mail.log instead,
 * so the app works offline / without SMTP configured.
 */

define('MAIL_USE_SMTP',  false);               // Set to true when SMTP is ready
define('MAIL_HOST',      'smtp.gmail.com');
define('MAIL_PORT',      587);
define('MAIL_USERNAME',  'your_gmail@gmail.com');
define('MAIL_PASSWORD',  'your_app_password');  // Use Gmail App Password (not your Gmail login)
define('MAIL_ENCRYPTION','tls');
define('MAIL_FROM_EMAIL','noreply@lifelink.local');
define('MAIL_FROM_NAME', APP_NAME);
define('MAIL_LOG_PATH',  __DIR__ . '/../storage/logs/mail.log');
