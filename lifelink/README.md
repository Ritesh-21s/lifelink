# LifeLink – Blood Donor & Emergency Request Platform

A full-stack blood donation matching web application built with **PHP 8 + PDO + MySQL + PHPMailer + vanilla JS**.  
Zero frameworks. Runs on XAMPP.

---

## Features

### Core
- Donor registration and profile management
- Emergency blood requests with urgency levels
- Admin approval workflow for all roles
- Email verification on signup
- Role-based dashboards (donor / requester / organizer / organization / admin)
- Donation camps listing and submission

### Upgrade 1 – GPS Location
- Auto-detect location via browser GPS → reverse-geocoded city/state/country via Nominatim
- Manual city entry → server-side forward geocode → Leaflet map with draggable pin
- Location consent checkbox (required before saving)
- 30-day Nominatim response cache (`location_cache` table)
- Server-side geocode proxy (browser never calls third-party APIs directly)
- Stale location warning after 180 days

### Upgrade 2 – Coordinate-Based Matching & Wave Notifications
- Haversine distance matching with bounding-box SQL index pre-filter
- Radius rings: 5 → 10 → 25 → 50 km (widens until minimum candidates found)
- Wave notification: 5 → 10 → 20 donors per wave (3/5/10 for Critical)
- Daily alert cap per donor (default 3); ignored-streak tracking
- Async email queue (`notification_queue` table); `cron/process_queue.php` sends in batches
- Requester sees masked donor list with approximate distance; Leaflet map with rounded pins
- Organizations (hospitals, blood banks, NGOs) included in matching

### Upgrade 3 – In-App Chat
- One-to-one chat created automatically when a donor accepts a request
- 3-second short polling (pauses when tab is hidden)
- Read-only after request ends (fulfilled/expired/cancelled)
- Unread badge in nav bar (20-second background polling)
- Report abuse → admin sees open reports on dashboard and can close conversations
- Rate limit: 20 messages/minute per session

---

## Quick Start (XAMPP)

### 1. Prerequisites
- [XAMPP](https://www.apachefriends.org/) with PHP 8+ and MariaDB 10.4+
- Start **Apache** and **MySQL** from the XAMPP Control Panel

### 2. Install
```bat
REM Copy project to htdocs
robocopy D:\blood-donation\lifelink C:\xampp\htdocs\lifelink /E /XD vendor .git

REM Create database and run schema
C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE IF NOT EXISTS lifelink CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
C:\xampp\mysql\bin\mysql.exe -u root lifelink < C:\xampp\htdocs\lifelink\database\schema.sql
C:\xampp\mysql\bin\mysql.exe -u root lifelink < C:\xampp\htdocs\lifelink\database\seed.sql
```

### 3. Configure Mail (Gmail SMTP)
Edit `config/mail.php`:
```php
define('MAIL_HOST',       'smtp.gmail.com');
define('MAIL_PORT',       587);
define('MAIL_USERNAME',   'your-email@gmail.com');
define('MAIL_PASSWORD',   'your-app-password');   // Gmail App Password (2FA required)
define('MAIL_FROM_EMAIL', 'your-email@gmail.com');
define('MAIL_FROM_NAME',  'LifeLink');
define('MAIL_USE_SMTP',   true);
define('MAIL_LOG_PATH',   __DIR__ . '/../storage/logs/mail.log');
```

### 4. Run Migration (existing database)
```bat
C:\xampp\mysql\bin\mysql.exe -u root lifelink < D:\blood-donation\lifelink\database\migration_002_location_chat.sql
```

Or use the provided deploy script:
```bat
D:\blood-donation\deploy_and_migrate.bat
```

### 5. Open the App
Visit: **http://localhost/lifelink**

Default admin login from seed: `admin@lifelink.com` / `Admin@123`

---

## Geocoding Setup (Nominatim / OpenStreetMap)

- **No API key required** – uses free OpenStreetMap Nominatim
- Configure the User-Agent in `config/app.php`:
  ```php
  define('GEOCODE_UA', 'LifeLink/1.0 (your-app contact@example.com)');
  ```
- Rate limit: 1 req/sec (enforced server-side via temp-file lock)
- Cache: 30-day DB cache in `location_cache` table
- **Production**: consider a paid geocoder for high traffic. Swap provider in `includes/geocode.php → geocodeReverse()` / `geocodeSearch()`.
- **HTTPS requirement**: `navigator.geolocation` requires HTTPS or `localhost`. On production, use SSL.

---

## Cron Jobs

### Windows (run as Administrator)
```bat
schtasks /create /sc MINUTE /mo 1 /tn "LifeLink Email Queue" ^
  /tr "C:\xampp\php\php.exe C:\xampp\htdocs\lifelink\cron\process_queue.php"

schtasks /create /sc MINUTE /mo 5 /tn "LifeLink Escalation" ^
  /tr "C:\xampp\php\php.exe C:\xampp\htdocs\lifelink\cron\escalate_requests.php"
```

### Linux
```cron
* * * * * php /var/www/html/lifelink/cron/process_queue.php >> /var/log/lifelink_queue.log 2>&1
*/5 * * * * php /var/www/html/lifelink/cron/escalate_requests.php >> /var/log/lifelink_escalation.log 2>&1
```

---

## Security

| Feature | Implementation |
|---|---|
| SQL Injection | PDO prepared statements everywhere |
| XSS | `htmlspecialchars(ENT_QUOTES)` via `e()` on all output |
| CSRF | Token in every POST form, validated server-side |
| Brute-force | 5-attempt lockout, 10-min window |
| Role guards | `requireRole()` at top of every protected page |
| Coordinate privacy | Exact coords never in public API; `ROUND(lat,3)` only |
| Email privacy | No phone numbers in notification emails |

---

## Folder Structure

```
lifelink/
├── api/                JSON endpoints (AJAX)
├── assets/
│   ├── css/style.css   Red/navy UI theme
│   └── js/
│       ├── app.js      Core JS (ticker, toggles, CSRF helper)
│       └── location.js Reusable location block (GPS/geocode/Leaflet)
├── config/
│   ├── app.php         All tuneable constants
│   ├── db.php          PDO singleton
│   └── mail.php        SMTP credentials
├── cron/               CLI scripts (email queue, wave escalation)
├── database/
│   ├── schema.sql          Fresh install schema (v2)
│   ├── seed.sql            Demo data
│   ├── seed_scale.php      Generator for 10,000 synthetic donors
│   ├── migration_002_location_chat.sql  Upgrade existing DB
│   └── migration_email_verification.sql
├── includes/           Shared PHP (auth, matching, geocode, mailer, helpers)
├── pages/
│   ├── admin/
│   ├── donor/
│   ├── organizer/
│   ├── public/         (search, request_detail, leaderboard, analytics)
│   └── requester/
├── storage/logs/       mail.log, error logs
└── vendor/phpmailer/   PHPMailer (manual install)
```

---

## New Roles in v2

| Role | Registration | Purpose |
|---|---|---|
| `organization` | `/pages/register.php?role=organization` | Hospitals, blood banks, NGOs. Receive matching alerts, accept requests, stock tracking |

---

## Environment Notes

- **PHP 8.0+** required (match expressions, named arguments, nullsafe operator)
- **MariaDB 10.4** — `ADD COLUMN IF NOT EXISTS` supported in migration
- **Nominatim** — follow [usage policy](https://operations.osmfoundation.org/policies/nominatim/); not for bulk geocoding
- Delete `migrate.php` and any `test_mail.php` from htdocs before production
