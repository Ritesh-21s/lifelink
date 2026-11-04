# VIVA.md – LifeLink Technical Explanation

## 1. Eligibility Rules

### Why these rules?
Blood donation has medical safety intervals to protect donor health.

| Rule | Value | Reason |
|------|-------|--------|
| Minimum age | 18 | Legal and physiological maturity |
| Maximum age | 65 | Higher risk of complications |
| Male gap | 90 days | Iron replenishment time for males |
| Female gap | 120 days | Longer due to menstrual iron loss |
| Minimum weight | 45 kg | Ensures adequate blood volume |

### How it's calculated (server-side in `includes/eligibility.php`)
1. Check `users.status = 'active'` and `donors.verified = 1`
2. Check `donors.is_available = 1` (donor's own toggle)
3. Calculate age from `donors.dob` using PHP `DateTimeImmutable`
4. If `last_donation_date` is set, compute `last_donation_date + gap_days` → `eligible_on`
5. If today < eligible_on → show countdown "Eligible on `<date>`"

> Gap values are **PHP constants** in `config/app.php` (also stored in DB `settings` table for future admin editing).

---

## 2. Blood Compatibility Matching

### ABO + Rh System
| Donor | Can donate to |
|-------|--------------|
| O−   | Everyone (universal donor) |
| O+   | O+, A+, B+, AB+ |
| A−   | A+, A−, AB+, AB− |
| A+   | A+, AB+ |
| B−   | B+, B−, AB+, AB− |
| B+   | B+, AB+ |
| AB−  | AB+, AB− |
| AB+  | AB+ only (universal recipient) |

### How it's stored
The `compatibility` table has `(donor_group, recipient_group)` pairs — seeded once in `seed.sql`. The matching engine queries:
```sql
SELECT donor_group FROM compatibility WHERE recipient_group = ?
```
This means "which blood types CAN donate TO the requested group?"

---

## 3. Ranking Score Algorithm (`includes/matching.php`)

For each eligible candidate donor, a score is calculated:

```
score = area_bonus + group_bonus + days_since_donation
```

| Bonus | Points | Condition |
|-------|--------|-----------|
| Area bonus | +100 | Donor is in the **same area** as the request |
| Area bonus | +50  | Donor is in an **adjacent area** |
| Group bonus | +20 | Donor has the **exact** blood group (vs compatible) |
| Gap bonus | +N days | Days since last donation (longer = higher priority) |

> **Why days since donation?** A donor who donated 300 days ago is healthier than one who donated 91 days ago — same eligibility, but better recovery.

The list is sorted `ORDER BY score DESC` and top donors are notified first.

---

## 4. Escalation Logic (`cron/escalate_requests.php`)

### Trigger Condition
A `Critical` request that has:
- Status = `Open`
- Zero `accepted` responses
- `created_at` older than `ESCALATION_MINUTES` (default: 30 minutes)
- `escalated_at IS NULL` (not already escalated)

### What happens
1. `escalated_at` is set to `NOW()` (prevents re-escalation)
2. `findMatchingDonors($request, expanded: true)` is called — this adds donors from **adjacent areas** (via `area_adjacency` table)
3. New donors get `status = 'notified'` responses (`INSERT IGNORE`)
4. An urgent email is sent to each newly notified donor

### Lazy trigger
The escalation cron can also be called lazily on page load (e.g., when the request detail page loads) to handle cases where the cron isn't scheduled.

---

## 5. Phone Privacy Design

### What is always hidden
- `users.phone` is **never** included in any public API response
- The donor search page (`pages/public/search.php`) shows only first name + area
- The matched donors table (requester's request detail) shows masked name + blood group + area

### When phone is revealed
1. A donor's `responses.status` changes to `'accepted'` for a specific request
2. On the requester's `request_detail.php`, a `SELECT phone FROM users WHERE id=donor_id` runs — **but only when `resp.status = 'accepted'`**
3. Every reveal is logged to `contact_reveals (request_id, donor_id, requester_id, revealed_at)`
4. The reveal is **idempotent** — checked before inserting a duplicate log entry

### Who can see what
| Page | What's shown |
|------|-------------|
| Public search | First name + area only |
| Requester's request detail (notified donor) | First name masked (`Arjun ***`) |
| Requester's request detail (accepted donor) | Full name + phone number |
| Donor's own responses page | Phone visible only for accepted requests |
| Admin logs | Full reveal audit trail |

---

## 6. Multi-Unit Request Locking

### Problem
If two donors simultaneously try to accept the last unit of a 2-unit request, both might succeed — causing 3 acceptances for a 2-unit request.

### Solution
`acceptResponse()` in `includes/matching.php` uses a **MySQL transaction with `FOR UPDATE` row lock**:

```php
$db->beginTransaction();
$stmt = $db->prepare('SELECT units_needed, units_accepted, status FROM requests WHERE id=? FOR UPDATE');
// ... check units_accepted < units_needed
// ... UPDATE responses SET status='accepted'
// ... UPDATE requests SET units_accepted = units_accepted + 1
$db->commit();
```

`FOR UPDATE` locks the row until the transaction commits, so the second concurrent request waits and then sees the updated `units_accepted`, preventing over-acceptance.

---

## 7. Security Measures

| Measure | Implementation |
|---------|---------------|
| SQL Injection | PDO prepared statements on every DB query — no string concatenation |
| XSS | All output wrapped in `htmlspecialchars()` via `e()` helper |
| CSRF | Every form has `<?= csrfField() ?>` → `hash_equals()` validation on POST |
| Fetch POST CSRF | `X-CSRF-TOKEN` header sent by `postJSON()` in `app.js` |
| Session fixation | `session_regenerate_id(true)` called on successful login |
| Brute force | Max 5 login attempts → 10-minute lockout (stored in `users.locked_until`) |
| Cookie hardening | `HttpOnly=true`, `SameSite=Lax` via `session_set_cookie_params()` |
| Rate limiting | Max 3 open requests per requester per day (checked via `COUNT + DATE()`) |
| Role guards | `requireRole('donor')` / `requireAdmin()` at top of every protected page |
| API guards | All API endpoints check `requireRole()` before processing |
| Input whitelist | Blood groups validated against `BLOOD_GROUPS` constant array |
| Password hashing | `password_hash($pass, PASSWORD_BCRYPT, ['cost'=>12])` — never stored plain |

---

## 8. Live Ticker Optimization

The ticker polls `api/urgent_requests.php` every 12 seconds.

**Without optimization:** Every poll fetches the full list (wasted bandwidth).

**With `last_updated` optimization:**
1. Client sends `?last_updated=<unix_timestamp>`
2. Server queries `MAX(created_at)` of open requests (1 fast query)
3. If server's timestamp == client's timestamp → returns `{"no_change": true}` (tiny response)
4. Only if data changed does it return the full list

This reduces payload by ~95% on idle periods.

---

## 9. Database Design Decisions

| Decision | Reason |
|----------|--------|
| `users` + role-specific tables | Avoids nullable columns; cleaner JOINs |
| `compatibility` table | Editable without code changes; reflects real ABO rules |
| `area_adjacency` (bidirectional) | Both `(A→B)` and `(B→A)` stored for simpler queries |
| `UNIQUE(request_id, donor_id)` on responses | Prevents duplicate notifications; `INSERT IGNORE` is safe |
| `contact_reveals` log | Full audit trail for privacy compliance |
| `reminder_sent_for DATE` on donors | Prevents duplicate daily reminders per cycle |
| `FOR UPDATE` in transaction | Race-condition-safe multi-unit acceptance |
| `utf8mb4` + `InnoDB` | Emoji support + foreign keys + ACID transactions |
