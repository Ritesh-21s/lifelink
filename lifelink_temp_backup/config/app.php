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
define('ROLES', ['donor','requester','organizer','admin']);

// -----------------------------------------------------------
// Login rate limiting
// -----------------------------------------------------------
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_MINUTES',    10);

// -----------------------------------------------------------
// Ticker poll interval (seconds) – also used in JS
// -----------------------------------------------------------
define('TICKER_POLL_SECONDS', 12);
