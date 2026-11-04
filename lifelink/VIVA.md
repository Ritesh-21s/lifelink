# LifeLink VIVA – Technical Documentation

## 1. System Architecture Overview

```
Browser (HTML5/CSS3/Vanilla JS)
    │
    ├── pages/          PHP 8 rendered views (role-guarded)
    ├── api/            JSON endpoints (AJAX, CSRF-protected)
    ├── includes/       Shared PHP: auth, matching, geocode, mailer, helpers
    ├── config/         DB, mail, app constants
    ├── assets/         CSS, JS (location.js, app.js)
    └── cron/           CLI scripts for waves + email queue
         │
         └── MySQL/MariaDB 10.4 (PDO, prepared statements)
                  +
             PHPMailer (SMTP via Gmail, queue-based)
```

---

## 2. Database Schema (Key Tables)

| Table | Purpose |
|---|---|
| `users` | All accounts (donor/requester/organizer/organization/admin). Has lat, lng, city, state, country, location_consent |
| `donors` | Donor-specific: blood_group, availability, lat/lng, location fields |
| `requests` | Blood requests with lat/lng of hospital |
| `camps` | Donation camps with lat/lng of venue |
| `organizations` | Hospitals, blood banks, NGOs with location + blood_stock |
| `responses` | Links donors to requests: status = notified/accepted/declined/donated |
| `donations` | Confirmed donations (triggers reward/thank-you email) |
| `conversations` | One-to-one chat room created on acceptance (UNIQUE request_id + donor_id) |
| `messages` | Chat messages (user_id, body, is_read, created_at) |
| `chat_reports` | Abuse reports on conversations |
| `notification_queue` | Async email queue (status: pending/sent/failed, retry logic) |
| `location_cache` | 30-day Nominatim response cache (key = rounded coords or normalised query) |
| `areas` | Legacy; kept for backward compat migration only |

---

## 3. Upgrade 1 – Location Collection

### 3.1 Location Flow

```
User opens form (register / post_request / post_camp)
    │
    ├── Auto-detect: navigator.geolocation.getCurrentPosition (high-accuracy, 10s timeout)
    │       ↓
    │   api/reverse_geocode.php → includes/geocode.php → Nominatim /reverse
    │       ↓ fills City, State, Country inputs (all editable)
    │
    ├── Manual entry: user types City → form blocks submit until resolved
    │       ↓
    │   api/geocode_search.php → includes/geocode.php → Nominatim /search
    │       ↓ returns lat/lng → shows Leaflet map with draggable pin
    │       ↓ dragging pin → reverse geocodes again
    │
    └── Consent checkbox → required for saving coordinates
         ↓ server validates + stores: lat, lng, city, state, country,
           location_source (gps/manual/geocoded), location_consent=1,
           location_updated_at = NOW()
```

### 3.2 Haversine Distance Formula

For two points (φ₁,λ₁) and (φ₂,λ₂) in degrees:

$$a = \sin^2\!\tfrac{\Delta\phi}{2} + \cos\phi_1\cos\phi_2\sin^2\!\tfrac{\Delta\lambda}{2}$$
$$d = 2R\arcsin\!\sqrt{a}, \quad R = 6{,}371\text{ km}$$

**SQL implementation** (with domain-error guard):

```sql
ROUND(
  6371 * ACOS(
    LEAST(1, COS(RADIANS(req_lat)) * COS(RADIANS(d.lat))
              * COS(RADIANS(d.lng) - RADIANS(req_lng))
          + SIN(RADIANS(req_lat)) * SIN(RADIANS(d.lat)))
  ), 1
) AS dist_km
```

`LEAST(1, …)` prevents `ACOS` domain errors when floating-point rounding produces values slightly > 1.

### 3.3 Bounding Box Pre-Filter

Before computing Haversine for all donors, a bounding box limits the dataset:

```
dLat = radius_km / 111.0
dLng = radius_km / (111.0 × cos(lat_rad))

WHERE d.lat BETWEEN (req_lat - dLat) AND (req_lat + dLat)
  AND d.lng BETWEEN (req_lng - dLng) AND (req_lng + dLng)
```

This uses the `idx_donor_lat_lng` composite index → O(log n) scan instead of full table.

### 3.4 Nominatim Compliance

- **User-Agent**: `LifeLink/1.0 (lifelink-app contact@lifelink.local)` (configurable in `config/app.php → GEOCODE_UA`)
- **Rate limit**: 1 request/second enforced by a temp-file lock in `includes/geocode.php → _geocodeThrottle()`
- **Cache**: `location_cache` table stores JSON responses for 30 days (key = `round(lat,2),round(lng,2)` or normalised query string)
- **Provider abstraction**: swap in `config/app.php → GEOCODE_PROVIDER` and implement alternate in `includes/geocode.php`

---

## 4. Upgrade 2 – Nearby Donor Matching & Wave Notifications

### 4.1 Candidate Selection (per request)

1. **Compatible blood groups** – from compatibility table (e.g. O- can give to all)
2. **Donor must be**: verified=1, is_available=1, status='active', not blocked
3. **Eligibility**: age 18–65, gender-based cooldown (90/120 days after last donation), weight ≥ 45 kg
4. **Has coordinates** + `location_consent = 1`
5. **Not on ignored streak**: `ignored_streak < IGNORED_STREAK_CAP (5)`
6. **Daily alert cap**: `alerts_today < DAILY_ALERT_CAP (3)` (counted from `responses` table today)

### 4.2 Radius Rings

```
RADIUS_RINGS = [5, 10, 25, 50] km   (configurable in config/app.php)
RADIUS_MIN_CANDIDATES = 3           (keep widening if fewer than this found)
```

First ring with ≥ `RADIUS_MIN_CANDIDATES` eligible donors is used.

### 4.3 Donor Ranking Score

```
score = (exact_group_match ? 100 : 0)      // exact > compatible
      - (dist_km × 2)                       // nearer is better
      + (days_since_donation / 10)          // longer rest = higher priority
      + (trust_score × 5)                   // past response rate
      - (ignored_streak × 10)               // penalise no-shows
```

Donors are sorted descending by score; top N are notified in each wave.

### 4.4 Wave Notification System

```
Wave 1: notify top 5 donors (top 3 for Critical)
  → wait WAVE_TIMEOUT_MIN (10 min) or WAVE_TIMEOUT_CRITICAL (5 min)
  → if accepted_count < units_needed:

Wave 2: notify next 10 donors, widen to next radius ring
  → wait again …

Wave 3+: notify next 20 donors, keep widening until:
  • units_needed covered, OR
  • request expired, OR
  • all rings exhausted (50 km)
```

Each wave is tracked in `requests.wave` (integer) and `requests.wave_notified_at` (datetime).  
`cron/escalate_requests.php` fires every 5 minutes and calls `escalateRequest()` for overdue requests.

### 4.5 Privacy – Coordinate Masking

- **Public API** (`api/request_donors.php`): returns `ROUND(lat, 3)` and `ROUND(lng, 3)` — ≈ 100m precision only
- **Map display**: uses rounded coordinates → pins are approximate (donor can't be pinpointed to exact address)
- **Admin pages**: city/state only; never exact coordinates
- **Emails**: hospital city + approximate distance ("about 3 km away"); no requester phone in email body

---

## 5. Upgrade 3 – In-App Chat

### 5.1 Conversation Lifecycle

```
Donor/Org accepts request
    ↓
acceptResponse() in matching.php:
    → INSERT INTO conversations (request_id, donor_id, org_id, status='open')
       ON DUPLICATE KEY UPDATE id=id   ← UNIQUE(request_id, donor_id) prevents duplicates
    ↓
Chat page (pages/chat.php) opens for both parties
    ↓
Short polling every 3 seconds: GET api/chat_messages.php?conv_id=X&since_id=Y
    ↓
Tab hidden? → polling pauses (document.visibilityState API)
    ↓
Request Fulfilled/Expired/Cancelled → escalate_requests.php sets conversations.status='closed'
    ↓
Chat becomes read-only (banner shown, send box hidden)
```

### 5.2 Rate Limiting

- Chat sends: 20 messages/minute per session (`$_SESSION['chat_last_min_ts']` + `$_SESSION['chat_count']`)
- Geocode API: 60 calls/session for reverse geocode, 30 for forward search
- Login: 5 attempts per 10-minute window (stored in `login_attempts` table)

### 5.3 Security

| Threat | Mitigation |
|---|---|
| SQL Injection | PDO prepared statements everywhere |
| XSS | `e()` = `htmlspecialchars(ENT_QUOTES)` on all output |
| CSRF | `csrfField()` / `csrfValidate()` on all POST forms |
| IDOR (access other chats) | Ownership check: `WHERE c.id=? AND (c.donor_id=? OR c.requester_id=? OR c.org_id=?)` |
| Brute force login | 5-attempt lockout, 10-min window |
| Coord exposure | `ROUND(lat,3)` in all public API responses |
| Email enumeration | Same "check your email" message regardless of whether account exists |

---

## 6. Email Queue Design

```
Any code that needs to send email → queueMail(userId, requestId, type, subject, body)
    ↓ INSERT INTO notification_queue (status='pending', attempts=0, send_after=NOW())

cron/process_queue.php (runs every 1 minute):
    SELECT 20 rows WHERE status='pending' AND send_after <= NOW() AND attempts < 3
    For each:
        sendMail() via PHPMailer SMTP
        SUCCESS → status='sent', sent_at=NOW()
        FAIL    → attempts++, send_after = NOW() + 5^attempts seconds (1min, 5min, 25min)
        3 fails → status='failed', logged to storage/logs/mail.log
```

Backoff: exponential — attempt 1→60s, 2→300s, 3→1500s before final failure.

---

## 7. Cron Jobs

| Script | Schedule | Purpose |
|---|---|---|
| `cron/process_queue.php` | Every 1 min | Send batches of 20 queued emails |
| `cron/escalate_requests.php` | Every 5 min | Expire old requests, fire next wave, close stale conversations, send location reminders |

**Windows setup:**
```bat
schtasks /create /sc MINUTE /mo 1 /tn "LifeLink Email Queue" /tr "C:\xampp\php\php.exe C:\xampp\htdocs\lifelink\cron\process_queue.php"
schtasks /create /sc MINUTE /mo 5 /tn "LifeLink Escalation" /tr "C:\xampp\php\php.exe C:\xampp\htdocs\lifelink\cron\escalate_requests.php"
```

---

## 8. Performance Notes

- **Bounding box index**: `idx_donor_lat_lng (lat, lng)` on `donors` → composite range scan
- **City index**: `idx_donor_city (city, state, country)` for text-based fallback search
- **Expected**: < 100ms for 10,000 donors with index hit; verified via `EXPLAIN` on bounding box query
- **Location cache**: Nominatim responses cached 30 days → zero repeat API calls for same city/coords
- **Chat polling**: tab-hidden detection pauses AJAX → no wasted requests when browser is backgrounded

---

## 9. Roles

| Role | Can Do |
|---|---|
| `donor` | Receive notifications, accept/decline requests, chat, update location |
| `requester` | Post requests, see matched donors, confirm donations, chat |
| `organizer` | Post donation camps (with location) |
| `organization` | Hospital/blood bank/NGO — receive and accept requests like donors |
| `admin` | Approve users, manage camps, close chat reports, view all logs |

---

## 10. Key Files Reference

| File | Role |
|---|---|
| `includes/auth.php` | Session, login, register, role guards |
| `includes/matching.php` | Haversine matching, wave notification, acceptResponse() |
| `includes/geocode.php` | Nominatim proxy, cache, validateLatLng(), haversineKm() |
| `includes/mailer.php` | sendMail(), queueMail(), all email templates |
| `assets/js/location.js` | Reusable location block (GPS, Leaflet map, geocode, consent) |
| `api/chat_*.php` | Chat send/receive/list/mark-read/report |
| `api/request_donors.php` | Masked donor list for requester (no exact coords) |
| `cron/process_queue.php` | Async email sender |
| `cron/escalate_requests.php` | Wave escalation + expiry |
| `database/migration_002_location_chat.sql` | Upgrades existing DB safely |
| `database/schema.sql` | Fresh install schema (v2) |
