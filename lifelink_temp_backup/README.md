# 🩸 LifeLink – Blood Donor & Emergency Request Network

A complete, production-ready blood donation management platform built with **PHP 8 + MySQL + Vanilla JS**.

---

## 📦 XAMPP Setup

### 1. Install XAMPP
Download from [apachefriends.org](https://www.apachefriends.org/) and install.  
Start **Apache** and **MySQL** from the XAMPP Control Panel.

### 2. Copy Project Files
```
Copy the lifelink/ folder to:  C:\xampp\htdocs\lifelink\
```

### 3. Import the Database

**Option A – phpMyAdmin (GUI):**
1. Open `http://localhost/phpmyadmin`
2. Click **New** → create database `lifelink` (charset: utf8mb4, collation: utf8mb4_unicode_ci)
3. Select the `lifelink` database → click **Import**
4. Choose `database/schema.sql` → click Go
5. Repeat import for `database/seed.sql`

**Option B – Command line:**
```bash
cd C:\xampp\mysql\bin
mysql -u root -p -e "source C:/xampp/htdocs/lifelink/database/schema.sql"
mysql -u root -p lifelink -e "source C:/xampp/htdocs/lifelink/database/seed.sql"
```

### 4. Configure the App
Edit `config/db.php`:
```php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');          // Your MySQL root password (empty by default in XAMPP)
define('DB_NAME', 'lifelink');
```

Edit `config/app.php` — the defaults work for XAMPP. Change `APP_URL` if deploying to a different path.

### 5. Open in Browser
```
http://localhost/lifelink/
```

---

## ⚙️ Configuration Settings

| File | Setting | Default | Description |
|------|---------|---------|-------------|
| `config/db.php` | `DB_PASS` | `''` | MySQL root password |
| `config/app.php` | `APP_URL` | `http://localhost/lifelink` | Base URL |
| `config/app.php` | `MALE_DONATION_GAP_DAYS` | `90` | Min days between male donations |
| `config/app.php` | `FEMALE_DONATION_GAP_DAYS` | `120` | Min days between female donations |
| `config/app.php` | `ESCALATION_MINUTES` | `30` | Minutes before Critical request escalates |
| `config/app.php` | `MAX_OPEN_REQUESTS_PER_DAY` | `3` | Rate limit per requester |
| `config/mail.php` | `MAIL_USE_SMTP` | `false` | Set `true` to enable email sending |
| `config/mail.php` | `MAIL_USERNAME` | — | Gmail address |
| `config/mail.php` | `MAIL_PASSWORD` | — | Gmail App Password |

---

## 🔑 Demo Login Credentials

**Password for all accounts:** `Password@123`

| Role | Email | Notes |
|------|-------|-------|
| Admin | `admin@lifelink.local` | Full admin access |
| Donor | `arjun@example.com` | Active, A+ blood, Anna Nagar |
| Donor (O-) | `karthik@example.com` | Universal donor |
| Requester | `ravi@example.com` | Has open requests |
| Requester | `meena@example.com` | Has open requests |
| Organizer | `lifecare@example.com` | LifeCare NGO |
| Organizer | `redcross@example.com` | College Red Cross |

---

## 📧 Email (SMTP) Setup

1. Enable 2-Factor Authentication on your Gmail account.
2. Go to Google Account → Security → App Passwords.
3. Generate an App Password for "Mail".
4. Edit `config/mail.php`:
   ```php
   define('MAIL_USE_SMTP',  true);
   define('MAIL_USERNAME',  'your.gmail@gmail.com');
   define('MAIL_PASSWORD',  'abcd efgh ijkl mnop');  // 16-char app password
   ```
5. If SMTP is not configured (`MAIL_USE_SMTP = false`), emails are logged to `storage/logs/mail.log`.

---

## ⏰ Scheduling Cron Scripts

### Windows Task Scheduler

**Daily eligibility reminders (8 AM):**
```
schtasks /create /sc DAILY /tn "LifeLink Reminders" /tr "C:\xampp\php\php.exe C:\xampp\htdocs\lifelink\cron\send_reminders.php" /st 08:00
```

**Escalation check (every 5 minutes):**
```
schtasks /create /sc MINUTE /mo 5 /tn "LifeLink Escalation" /tr "C:\xampp\php\php.exe C:\xampp\htdocs\lifelink\cron\escalate_requests.php"
```

### Linux/macOS Cron
```bash
crontab -e
# Add:
0 8 * * *   php /var/www/html/lifelink/cron/send_reminders.php >> /var/log/lifelink_reminders.log 2>&1
*/5 * * * * php /var/www/html/lifelink/cron/escalate_requests.php >> /var/log/lifelink_escalation.log 2>&1
```

---

## 🚀 Features

### 🩸 Donor Module
- Register with blood group, area, gender, DOB, weight
- Availability toggle (available/not available)
- Eligibility calculation: age 18–65, gender-specific donation gap (90/120 days)
- Dashboard with pending requests, accepted responses, donation history
- Lives helped counter + earned badges (Bronze/Silver/Gold)
- Accept/decline requests with race-condition-safe locking

### 🏥 Requester Module
- Post emergency blood requests (patient, blood group, units, hospital, urgency)
- Rate limit: max 3 open requests per day
- View matched donors (masked) and their responses
- Confirm donation → triggers thank-you email + updates donor record
- Cancel requests with confirm dialog

### 🏢 Organizer Module
- Post donation camps (title, venue, area, date/time, contact)
- Admin approval required before public listing

### ⚙️ Admin Module
- Approve/reject/block/unblock users
- Manage requests, camps, areas and adjacency
- Configure shortage threshold
- View contact reveal audit log and email log
- Reports with CSV export (donors, requests, donations)

### 🔍 Matching Engine
- ABO + Rh compatibility from DB compatibility table
- Ranking: same area (+100) > adjacent area (+50) > exact blood group (+20) > days since last donation
- Escalation: Critical requests with no response after 30 min → widen to adjacent areas
- Multi-unit: transaction + row lock prevents over-acceptance

### 🔒 Privacy & Security
- Donor phone never shown publicly
- Contact revealed only on acceptance, only to the request owner
- Every reveal logged in `contact_reveals`
- CSRF tokens on all forms and fetch POSTs
- PDO prepared statements throughout
- Login rate limiting (5 attempts → 10-min lockout)
- HttpOnly + SameSite=Lax session cookies
- Output escaped with `htmlspecialchars`

### 📊 Analytics
- Bar chart: eligible donors by blood group
- Doughnut chart: requests by urgency
- Line chart: monthly requests vs fulfilled (6 months)
- Shortage flags: areas below configurable threshold

### 🏕️ Camp Calendar
- Vanilla JS monthly grid with prev/next navigation
- Clickable day popups showing camp details
- Area filter
- "I'll Attend" toggle for donors

### 📧 Email System
- Welcome email on registration
- Urgent request email for Critical requests
- Thank-you email after confirmed donation
- Eligibility reminder email (daily cron)
- Offline fallback: logs to `storage/logs/mail.log`

---

## 🗂️ Folder Structure

```
lifelink/
├── config/          # db.php, app.php, mail.php
├── includes/        # auth.php, csrf.php, matching.php, eligibility.php, mailer.php, header.php, footer.php, helpers.php
├── api/             # JSON endpoints (ticker, stats, charts, respond, etc.)
├── pages/
│   ├── public/      # search, leaderboard, analytics, calendar, request_detail
│   ├── donor/       # dashboard, profile, responses
│   ├── requester/   # dashboard, post_request, request_detail
│   ├── organizer/   # dashboard, post_camp
│   └── admin/       # dashboard, users, requests, camps, areas, reports, logs
├── assets/
│   ├── css/style.css
│   └── js/app.js
├── cron/            # send_reminders.php, escalate_requests.php
├── database/        # schema.sql, seed.sql
├── storage/logs/    # mail.log (offline email fallback)
├── vendor/          # PHPMailer (optional)
├── index.php        # Home page
├── 404.php
├── README.md
└── VIVA.md
```
