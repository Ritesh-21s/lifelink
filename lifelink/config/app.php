<?php
/**
 * LifeLink – Application Configuration
 * All tuneable constants. Change values here; never hardcode elsewhere.
 */

// -----------------------------------------------------------
// App identity
// -----------------------------------------------------------
define('APP_NAME',    'LifeLink');
define('APP_URL',     'http://localhost/lifelink');   // trailing slash omitted
define('APP_VERSION', '1.0.0');

// -----------------------------------------------------------
// Eligibility rules (days; stored in DB settings but also here
// as PHP constants for server-side calculations)
// -----------------------------------------------------------
define('MALE_DONATION_GAP_DAYS',   90);
define('FEMALE_DONATION_GAP_DAYS', 120);
define('MIN_AGE',   18);
define('MAX_AGE',   65);
define('MIN_WEIGHT', 45.0); // kg

// -----------------------------------------------------------
// Request rules
// -----------------------------------------------------------
define('MAX_OPEN_REQUESTS_PER_DAY', 3);
define('ESCALATION_MINUTES',        30);   // after this, widen search for Critical requests
define('REQUEST_EXPIRY_HOURS',      48);   // default request lifetime

// -----------------------------------------------------------
// Lives helped
// -----------------------------------------------------------
define('LIVES_MULTIPLIER', 3);

// -----------------------------------------------------------
// Analytics
// -----------------------------------------------------------
define('SHORTAGE_THRESHOLD', 3);    // fewer donors than this = shortage flag

// -----------------------------------------------------------
// Session
// -----------------------------------------------------------
define('SESSION_NAME',     'lifelink_sess');
define('SESSION_LIFETIME', 3600 * 8); // 8 hours

// -----------------------------------------------------------
// Blood groups (whitelist for validation)
// -----------------------------------------------------------
define('BLOOD_GROUPS', ['A+','A-','B+','B-','AB+','AB-','O+','O-']);

// -----------------------------------------------------------
// Roles
// -----------------------------------------------------------
define('ROLES', ['donor','requester','organizer','organization','admin']);

// -----------------------------------------------------------
// Login rate limiting
// -----------------------------------------------------------
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_MINUTES',    10);

// -----------------------------------------------------------
// Ticker poll interval (seconds) – also used in JS
// -----------------------------------------------------------
define('TICKER_POLL_SECONDS', 12);

// -----------------------------------------------------------
// Geocoding (Nominatim / OpenStreetMap)
// -----------------------------------------------------------
// Change GEOCODE_PROVIDER to 'nominatim' or add another key for paid APIs
define('GEOCODE_PROVIDER',  'nominatim');
define('GEOCODE_UA',        'LifeLink/1.0 (lifelink-app contact@lifelink.local)');
define('GEOCODE_CACHE_DAYS', 30);

// -----------------------------------------------------------
// Matching radius rings (km) – searched in order until min candidates found
// -----------------------------------------------------------
define('RADIUS_RINGS',       [5, 10, 25, 50]);
define('RADIUS_MIN_CANDIDATES', 3);   // keep widening until we have at least this many

// -----------------------------------------------------------
// Wave notification settings
// -----------------------------------------------------------
define('WAVE_SIZES',         [5, 10, 20]);        // donors notified per wave
define('WAVE_TIMEOUT_MIN',   10);                  // minutes before next wave fires
define('WAVE_TIMEOUT_CRITICAL', 5);               // minutes for Critical urgency
define('DAILY_ALERT_CAP',    3);                  // max alerts per donor per day
define('IGNORED_STREAK_CAP', 5);                  // skip donor after 5 ignored alerts

// -----------------------------------------------------------
// Chat settings
// -----------------------------------------------------------
define('CHAT_POLL_SECONDS',       3);   // foreground chat polling
define('CHAT_BADGE_POLL_SECONDS', 20);  // background unread-badge polling
define('CHAT_MAX_LENGTH',         1000);
define('CHAT_RATE_LIMIT_PER_MIN', 20);

// -----------------------------------------------------------
// Location staleness prompt (days)
// -----------------------------------------------------------
define('LOCATION_STALE_DAYS', 180);
