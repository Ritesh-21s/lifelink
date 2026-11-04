-- ============================================================
-- LifeLink Migration 002 – Location, Chat & Notification Queue
-- Run ONCE on an existing lifelink database to upgrade it safely.
-- All ALTER TABLE statements use IF NOT EXISTS / IF EXISTS to be idempotent.
-- ============================================================

USE lifelink;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- 1. USERS – add location columns + email verification cols
-- ============================================================
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS email_token          VARCHAR(64)  NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS email_token_expires  DATETIME     NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS lat                  DECIMAL(9,6) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS lng                  DECIMAL(9,6) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS city                 VARCHAR(100) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS state                VARCHAR(100) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS country              VARCHAR(100) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS location_source      ENUM('gps','manual','geocoded') NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS location_updated_at  DATETIME NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS location_consent     TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS location_incomplete  TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS notify_email         TINYINT(1) NOT NULL DEFAULT 1;

-- Indexes for location lookups
ALTER TABLE users
  ADD INDEX IF NOT EXISTS idx_lat_lng      (lat, lng),
  ADD INDEX IF NOT EXISTS idx_country_state_city (country, state, city),
  ADD INDEX IF NOT EXISTS idx_email_token  (email_token);

-- ============================================================
-- 2. DONORS – drop old area_id FK, add lat/lng
-- ============================================================
-- Note: we keep area_id for backward compat with old data but make it nullable
ALTER TABLE donors
  ADD COLUMN IF NOT EXISTS lat                 DECIMAL(9,6) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS lng                 DECIMAL(9,6) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS location_consent    TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS daily_alerts_sent   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS daily_alert_date    DATE NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS total_alerts        INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS total_accepted      INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS ignored_streak      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS location_reminder_sent TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE donors
  ADD INDEX IF NOT EXISTS idx_donor_lat_lng (lat, lng),
  ADD INDEX IF NOT EXISTS idx_donor_avail   (is_available, verified);

-- Make area_id nullable for backward compatibility
ALTER TABLE donors MODIFY COLUMN area_id INT UNSIGNED NULL DEFAULT NULL;

-- ============================================================
-- 3. REQUESTS – add location columns
-- ============================================================
ALTER TABLE requests
  ADD COLUMN IF NOT EXISTS lat     DECIMAL(9,6) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS lng     DECIMAL(9,6) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS city    VARCHAR(100) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS state   VARCHAR(100) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS country VARCHAR(100) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS wave    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS wave_notified_at DATETIME NULL DEFAULT NULL;

-- Make area_id nullable
ALTER TABLE requests MODIFY COLUMN area_id INT UNSIGNED NULL DEFAULT NULL;

ALTER TABLE requests
  ADD INDEX IF NOT EXISTS idx_req_lat_lng (lat, lng);

-- ============================================================
-- 4. CAMPS – add location columns
-- ============================================================
ALTER TABLE camps
  ADD COLUMN IF NOT EXISTS lat     DECIMAL(9,6) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS lng     DECIMAL(9,6) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS city    VARCHAR(100) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS state   VARCHAR(100) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS country VARCHAR(100) NULL DEFAULT NULL;
  
ALTER TABLE camps
  ADD COLUMN IF NOT EXISTS location_source ENUM('gps','manual','geocoded') NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS location_consent TINYINT(1) NOT NULL DEFAULT 0;

-- Make area_id nullable
ALTER TABLE camps MODIFY COLUMN area_id INT UNSIGNED NULL DEFAULT NULL;

-- ============================================================
-- 5. ORGANIZATIONS table (hospitals, blood banks, NGOs)
-- ============================================================
CREATE TABLE IF NOT EXISTS organizations (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    org_name        VARCHAR(200) NOT NULL,
    org_type        ENUM('hospital','blood_bank','ngo','college','other') NOT NULL DEFAULT 'other',
    verified        TINYINT(1) NOT NULL DEFAULT 0,
    blood_stock     JSON NULL COMMENT 'e.g. {"A+":5,"O-":2}',
    lat             DECIMAL(9,6) NULL,
    lng             DECIMAL(9,6) NULL,
    city            VARCHAR(100) NULL,
    state           VARCHAR(100) NULL,
    country         VARCHAR(100) NULL,
    location_consent TINYINT(1) NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_org_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_org_lat_lng (lat, lng),
    INDEX idx_org_type (org_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add 'organization' to users.role ENUM if not present (MariaDB-safe)
-- We do this by modifying the column
ALTER TABLE users MODIFY COLUMN role ENUM('donor','requester','organizer','organization','admin') NOT NULL DEFAULT 'donor';

-- ============================================================
-- 6. LOCATION CACHE table (Nominatim response cache)
-- ============================================================
CREATE TABLE IF NOT EXISTS location_cache (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cache_key    VARCHAR(100) NOT NULL,
    response_json TEXT NOT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cache_key (cache_key),
    INDEX idx_cache_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 7. NOTIFICATION QUEUE table
-- ============================================================
CREATE TABLE IF NOT EXISTS notification_queue (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NOT NULL,
    request_id   INT UNSIGNED NULL,
    type         VARCHAR(50) NOT NULL DEFAULT 'general',
    subject      VARCHAR(255) NOT NULL,
    body         MEDIUMTEXT NOT NULL,
    status       ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
    attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    send_after   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at      DATETIME NULL,
    error        VARCHAR(500) NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE,
    FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE SET NULL,
    INDEX idx_nq_status      (status, send_after),
    INDEX idx_nq_user        (user_id),
    INDEX idx_nq_request     (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 8. CONVERSATIONS table (chat)
-- ============================================================
CREATE TABLE IF NOT EXISTS conversations (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id    INT UNSIGNED NOT NULL,
    requester_id  INT UNSIGNED NOT NULL,
    responder_id  INT UNSIGNED NOT NULL,
    status        ENUM('open','closed') NOT NULL DEFAULT 'open',
    closed_reason VARCHAR(100) NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    closed_at     DATETIME NULL,
    UNIQUE KEY uq_conv (request_id, responder_id),
    FOREIGN KEY (request_id)   REFERENCES requests(id) ON DELETE CASCADE,
    FOREIGN KEY (requester_id) REFERENCES users(id)    ON DELETE CASCADE,
    FOREIGN KEY (responder_id) REFERENCES users(id)    ON DELETE CASCADE,
    INDEX idx_conv_requester  (requester_id),
    INDEX idx_conv_responder  (responder_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 9. MESSAGES table (chat messages)
-- ============================================================
CREATE TABLE IF NOT EXISTS messages (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT UNSIGNED NOT NULL,
    sender_id       INT UNSIGNED NOT NULL,
    body            VARCHAR(1000) NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read_at         DATETIME NULL,
    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    FOREIGN KEY (sender_id)       REFERENCES users(id)         ON DELETE CASCADE,
    INDEX idx_msg_conv_id (conversation_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 10. CHAT REPORTS table
-- ============================================================
CREATE TABLE IF NOT EXISTS chat_reports (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT UNSIGNED NOT NULL,
    reporter_id     INT UNSIGNED NOT NULL,
    reason          VARCHAR(300) NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status          ENUM('open','reviewed','closed') NOT NULL DEFAULT 'open',
    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    FOREIGN KEY (reporter_id)     REFERENCES users(id)         ON DELETE CASCADE,
    INDEX idx_report_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 11. Seed existing area data with approximate coordinates
--     (map Chennai areas to lat/lng so old data isn't broken)
-- ============================================================
UPDATE areas SET
    -- Using approximate centre coords for each Chennai area
    city = CASE id
        WHEN 1 THEN 'Anna Nagar'
        WHEN 2 THEN 'Adyar'
        WHEN 3 THEN 'T. Nagar'
        WHEN 4 THEN 'Velachery'
        WHEN 5 THEN 'Tambaram'
        WHEN 6 THEN 'Porur'
        WHEN 7 THEN 'Mylapore'
        WHEN 8 THEN 'Chromepet'
        ELSE city
    END
WHERE id BETWEEN 1 AND 8;

-- Backfill user location from their area for existing seed users
-- Map area_id -> lat/lng (approximate centres, Chennai)
UPDATE users u
JOIN donors d ON d.user_id = u.id
SET
    u.lat     = CASE d.area_id
        WHEN 1 THEN 13.085800
        WHEN 2 THEN 13.000900
        WHEN 3 THEN 13.040600
        WHEN 4 THEN 12.978200
        WHEN 5 THEN 12.923900
        WHEN 6 THEN 13.033700
        WHEN 7 THEN 13.033400
        WHEN 8 THEN 12.951700
        ELSE NULL
    END,
    u.lng     = CASE d.area_id
        WHEN 1 THEN 80.209400
        WHEN 2 THEN 80.255600
        WHEN 3 THEN 80.231600
        WHEN 4 THEN 80.220100
        WHEN 5 THEN 80.073700
        WHEN 6 THEN 80.158500
        WHEN 7 THEN 80.269900
        WHEN 8 THEN 80.137600
        ELSE NULL
    END,
    u.city    = CASE d.area_id
        WHEN 1 THEN 'Anna Nagar'
        WHEN 2 THEN 'Adyar'
        WHEN 3 THEN 'T. Nagar'
        WHEN 4 THEN 'Velachery'
        WHEN 5 THEN 'Tambaram'
        WHEN 6 THEN 'Porur'
        WHEN 7 THEN 'Mylapore'
        WHEN 8 THEN 'Chromepet'
        ELSE NULL
    END,
    u.state   = 'Tamil Nadu',
    u.country = 'India',
    u.location_source    = 'manual',
    u.location_consent   = 1,
    u.location_updated_at = NOW()
WHERE d.area_id IS NOT NULL;

-- Backfill donors.lat/lng from users
UPDATE donors d
JOIN users u ON u.id = d.user_id
SET d.lat = u.lat, d.lng = u.lng, d.location_consent = u.location_consent
WHERE u.lat IS NOT NULL;

-- Backfill requests location from area
UPDATE requests r
SET
    r.lat     = CASE r.area_id
        WHEN 1 THEN 13.085800 WHEN 2 THEN 13.000900 WHEN 3 THEN 13.040600
        WHEN 4 THEN 12.978200 WHEN 5 THEN 12.923900 WHEN 6 THEN 13.033700
        WHEN 7 THEN 13.033400 WHEN 8 THEN 12.951700 ELSE NULL END,
    r.lng     = CASE r.area_id
        WHEN 1 THEN 80.209400 WHEN 2 THEN 80.255600 WHEN 3 THEN 80.231600
        WHEN 4 THEN 80.220100 WHEN 5 THEN 80.073700 WHEN 6 THEN 80.158500
        WHEN 7 THEN 80.269900 WHEN 8 THEN 80.137600 ELSE NULL END,
    r.city    = CASE r.area_id
        WHEN 1 THEN 'Anna Nagar' WHEN 2 THEN 'Adyar' WHEN 3 THEN 'T. Nagar'
        WHEN 4 THEN 'Velachery'  WHEN 5 THEN 'Tambaram' WHEN 6 THEN 'Porur'
        WHEN 7 THEN 'Mylapore'   WHEN 8 THEN 'Chromepet' ELSE NULL END,
    r.state   = 'Tamil Nadu',
    r.country = 'India'
WHERE r.area_id IS NOT NULL;

-- Backfill camps location from area
UPDATE camps c
SET
    c.lat     = CASE c.area_id
        WHEN 1 THEN 13.085800 WHEN 2 THEN 13.000900 WHEN 3 THEN 13.040600
        WHEN 4 THEN 12.978200 WHEN 5 THEN 12.923900 WHEN 6 THEN 13.033700
        WHEN 7 THEN 13.033400 WHEN 8 THEN 12.951700 ELSE NULL END,
    c.lng     = CASE c.area_id
        WHEN 1 THEN 80.209400 WHEN 2 THEN 80.255600 WHEN 3 THEN 80.231600
        WHEN 4 THEN 80.220100 WHEN 5 THEN 80.073700 WHEN 6 THEN 80.158500
        WHEN 7 THEN 80.269900 WHEN 8 THEN 80.137600 ELSE NULL END,
    c.city    = CASE c.area_id
        WHEN 1 THEN 'Anna Nagar' WHEN 2 THEN 'Adyar' WHEN 3 THEN 'T. Nagar'
        WHEN 4 THEN 'Velachery'  WHEN 5 THEN 'Tambaram' WHEN 6 THEN 'Porur'
        WHEN 7 THEN 'Mylapore'   WHEN 8 THEN 'Chromepet' ELSE NULL END,
    c.state   = 'Tamil Nadu',
    c.country = 'India'
WHERE c.area_id IS NOT NULL;

SET FOREIGN_KEY_CHECKS = 1;

SELECT 'Migration 002 complete.' AS result;

