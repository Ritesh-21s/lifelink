-- LifeLink Blood Donor & Emergency Request Network
-- Database Schema (v2 – with location, chat, notification queue, organizations)
-- Character set: utf8mb4, Engine: InnoDB

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO';

CREATE DATABASE IF NOT EXISTS lifelink CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE lifelink;

-- ============================================================
-- USERS (base account for all roles)
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name                 VARCHAR(100)  NOT NULL,
    email                VARCHAR(150)  NOT NULL,
    phone                VARCHAR(20)   NOT NULL,
    password_hash        VARCHAR(255)  NOT NULL,
    role                 ENUM('donor','requester','organizer','organization','admin') NOT NULL DEFAULT 'donor',
    status               ENUM('unverified','pending','active','blocked') NOT NULL DEFAULT 'unverified',
    login_attempts       TINYINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until         DATETIME NULL,
    email_token          VARCHAR(64)   NULL DEFAULT NULL,
    email_token_expires  DATETIME      NULL DEFAULT NULL,
    -- Location
    lat                  DECIMAL(9,6)  NULL DEFAULT NULL,
    lng                  DECIMAL(9,6)  NULL DEFAULT NULL,
    city                 VARCHAR(100)  NULL DEFAULT NULL,
    state                VARCHAR(100)  NULL DEFAULT NULL,
    country              VARCHAR(100)  NULL DEFAULT NULL,
    location_source      ENUM('gps','manual','geocoded') NULL DEFAULT NULL,
    location_updated_at  DATETIME      NULL DEFAULT NULL,
    location_consent     TINYINT(1)    NOT NULL DEFAULT 0,
    location_incomplete  TINYINT(1)    NOT NULL DEFAULT 0,
    notify_email         TINYINT(1)    NOT NULL DEFAULT 1,
    created_at           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_email         (email),
    INDEX idx_role              (role),
    INDEX idx_status            (status),
    INDEX idx_lat_lng           (lat, lng),
    INDEX idx_country_state_city(country, state, city),
    INDEX idx_email_token       (email_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- AREAS (kept for legacy compatibility; new code uses lat/lng)
-- ============================================================
CREATE TABLE IF NOT EXISTS areas (
    id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    city VARCHAR(100) NOT NULL,
    lat  DECIMAL(9,6) NULL,
    lng  DECIMAL(9,6) NULL,
    INDEX idx_city (city)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- AREA ADJACENCY (kept for legacy; no longer used for matching)
-- ============================================================
CREATE TABLE IF NOT EXISTS area_adjacency (
    area_id          INT UNSIGNED NOT NULL,
    adjacent_area_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (area_id, adjacent_area_id),
    FOREIGN KEY (area_id)          REFERENCES areas(id) ON DELETE CASCADE,
    FOREIGN KEY (adjacent_area_id) REFERENCES areas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- BLOOD GROUP COMPATIBILITY
-- ============================================================
CREATE TABLE IF NOT EXISTS compatibility (
    donor_group     ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-') NOT NULL,
    recipient_group ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-') NOT NULL,
    PRIMARY KEY (donor_group, recipient_group)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- DONORS (extends users where role='donor')
-- ============================================================
CREATE TABLE IF NOT EXISTS donors (
    user_id               INT UNSIGNED NOT NULL PRIMARY KEY,
    blood_group           ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-') NOT NULL,
    gender                ENUM('male','female','other') NOT NULL,
    dob                   DATE NOT NULL,
    area_id               INT UNSIGNED NULL DEFAULT NULL,
    last_donation_date    DATE NULL,
    is_available          TINYINT(1) NOT NULL DEFAULT 1,
    verified              TINYINT(1) NOT NULL DEFAULT 0,
    reminder_sent_for     DATE NULL,
    weight                DECIMAL(5,2) NULL,
    -- Location (denormalised from users for fast spatial queries)
    lat                   DECIMAL(9,6) NULL DEFAULT NULL,
    lng                   DECIMAL(9,6) NULL DEFAULT NULL,
    location_consent      TINYINT(1) NOT NULL DEFAULT 0,
    location_reminder_sent TINYINT(1) NOT NULL DEFAULT 0,
    -- Alert tracking
    daily_alerts_sent     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    daily_alert_date      DATE NULL DEFAULT NULL,
    total_alerts          INT UNSIGNED NOT NULL DEFAULT 0,
    total_accepted        INT UNSIGNED NOT NULL DEFAULT 0,
    ignored_streak        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (area_id) REFERENCES areas(id) ON DELETE SET NULL,
    INDEX idx_blood_group  (blood_group),
    INDEX idx_area         (area_id),
    INDEX idx_available    (is_available),
    INDEX idx_donor_lat_lng(lat, lng),
    INDEX idx_donor_avail  (is_available, verified)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- ORGANIZERS (extends users where role='organizer')
-- ============================================================
CREATE TABLE IF NOT EXISTS organizers (
    user_id  INT UNSIGNED NOT NULL PRIMARY KEY,
    org_name VARCHAR(200) NOT NULL,
    verified TINYINT(1) NOT NULL DEFAULT 0,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- ORGANIZATIONS (hospitals, blood banks, NGOs – can respond to requests)
-- ============================================================
CREATE TABLE IF NOT EXISTS organizations (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id          INT UNSIGNED NOT NULL,
    org_name         VARCHAR(200) NOT NULL,
    org_type         ENUM('hospital','blood_bank','ngo','college','other') NOT NULL DEFAULT 'other',
    verified         TINYINT(1) NOT NULL DEFAULT 0,
    blood_stock      JSON NULL COMMENT 'e.g. {"A+":5,"O-":2}',
    lat              DECIMAL(9,6) NULL,
    lng              DECIMAL(9,6) NULL,
    city             VARCHAR(100) NULL,
    state            VARCHAR(100) NULL,
    country          VARCHAR(100) NULL,
    location_consent TINYINT(1) NOT NULL DEFAULT 0,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_org_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_org_lat_lng (lat, lng),
    INDEX idx_org_type    (org_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- EMERGENCY REQUESTS
-- ============================================================
CREATE TABLE IF NOT EXISTS requests (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    requester_id    INT UNSIGNED NOT NULL,
    patient_name    VARCHAR(100) NOT NULL,
    blood_group     ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-') NOT NULL,
    units_needed    TINYINT UNSIGNED NOT NULL DEFAULT 1,
    units_accepted  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    hospital        VARCHAR(200) NOT NULL,
    area_id         INT UNSIGNED NULL DEFAULT NULL,
    -- Location (hospital / patient location)
    lat             DECIMAL(9,6) NULL DEFAULT NULL,
    lng             DECIMAL(9,6) NULL DEFAULT NULL,
    city            VARCHAR(100) NULL DEFAULT NULL,
    state           VARCHAR(100) NULL DEFAULT NULL,
    country         VARCHAR(100) NULL DEFAULT NULL,
    urgency         ENUM('Critical','Within 24 hours','Planned') NOT NULL DEFAULT 'Planned',
    contact_phone   VARCHAR(20) NOT NULL,
    needed_by       DATETIME NOT NULL,
    status          ENUM('Open','Donor Found','Fulfilled','Expired','Cancelled') NOT NULL DEFAULT 'Open',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at      DATETIME NULL,
    escalated_at    DATETIME NULL,
    wave            TINYINT UNSIGNED NOT NULL DEFAULT 0,
    wave_notified_at DATETIME NULL,
    notes           TEXT NULL,
    FOREIGN KEY (requester_id) REFERENCES users(id),
    FOREIGN KEY (area_id)      REFERENCES areas(id) ON DELETE SET NULL,
    INDEX idx_status      (status),
    INDEX idx_blood_group (blood_group),
    INDEX idx_urgency     (urgency),
    INDEX idx_created     (created_at),
    INDEX idx_requester   (requester_id),
    INDEX idx_req_lat_lng (lat, lng)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- RESPONSES (donor / organization responses to requests)
-- ============================================================
CREATE TABLE IF NOT EXISTS responses (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id   INT UNSIGNED NOT NULL,
    donor_id     INT UNSIGNED NOT NULL,
    status       ENUM('notified','accepted','declined','donated') NOT NULL DEFAULT 'notified',
    responded_at DATETIME NULL,
    wave         TINYINT UNSIGNED NOT NULL DEFAULT 1,
    UNIQUE KEY uq_request_donor (request_id, donor_id),
    FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
    FOREIGN KEY (donor_id)   REFERENCES users(id)    ON DELETE CASCADE,
    INDEX idx_donor_status (donor_id, status),
    INDEX idx_req_status   (request_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- DONATIONS (confirmed donations)
-- ============================================================
CREATE TABLE IF NOT EXISTS donations (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id   INT UNSIGNED NULL,
    donor_id     INT UNSIGNED NOT NULL,
    requester_id INT UNSIGNED NULL,
    confirmed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    units        TINYINT UNSIGNED NOT NULL DEFAULT 1,
    FOREIGN KEY (request_id)   REFERENCES requests(id) ON DELETE SET NULL,
    FOREIGN KEY (donor_id)     REFERENCES users(id)    ON DELETE CASCADE,
    FOREIGN KEY (requester_id) REFERENCES users(id)    ON DELETE SET NULL,
    INDEX idx_donor     (donor_id),
    INDEX idx_confirmed (confirmed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- CONTACT REVEALS LOG
-- ============================================================
CREATE TABLE IF NOT EXISTS contact_reveals (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id   INT UNSIGNED NOT NULL,
    donor_id     INT UNSIGNED NOT NULL,
    requester_id INT UNSIGNED NOT NULL,
    revealed_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id)   REFERENCES requests(id) ON DELETE CASCADE,
    FOREIGN KEY (donor_id)     REFERENCES users(id)    ON DELETE CASCADE,
    FOREIGN KEY (requester_id) REFERENCES users(id)    ON DELETE CASCADE,
    INDEX idx_request (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- DONATION CAMPS
-- ============================================================
CREATE TABLE IF NOT EXISTS camps (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organizer_id INT UNSIGNED NOT NULL,
    title        VARCHAR(200) NOT NULL,
    venue        VARCHAR(300) NOT NULL,
    area_id      INT UNSIGNED NULL DEFAULT NULL,
    -- Location
    lat          DECIMAL(9,6) NULL,
    lng          DECIMAL(9,6) NULL,
    city         VARCHAR(100) NULL,
    state        VARCHAR(100) NULL,
    country      VARCHAR(100) NULL,
    location_source ENUM('gps','manual','geocoded') NULL DEFAULT NULL,
    location_consent TINYINT(1) NOT NULL DEFAULT 0,
    camp_date    DATE NOT NULL,
    start_time   TIME NOT NULL,
    end_time     TIME NOT NULL,
    contact      VARCHAR(20) NOT NULL,
    description  TEXT NULL,
    status       ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (organizer_id) REFERENCES users(id),
    FOREIGN KEY (area_id)      REFERENCES areas(id) ON DELETE SET NULL,
    INDEX idx_date   (camp_date),
    INDEX idx_status (status),
    INDEX idx_area   (area_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- CAMP INTEREST
-- ============================================================
CREATE TABLE IF NOT EXISTS camp_interest (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    camp_id    INT UNSIGNED NOT NULL,
    donor_id   INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_camp_donor (camp_id, donor_id),
    FOREIGN KEY (camp_id)  REFERENCES camps(id) ON DELETE CASCADE,
    FOREIGN KEY (donor_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- EMAIL LOG
-- ============================================================
CREATE TABLE IF NOT EXISTS email_log (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NULL,
    email_type VARCHAR(50)  NOT NULL,
    recipient  VARCHAR(150) NOT NULL,
    sent_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status     ENUM('sent','failed','logged') NOT NULL DEFAULT 'sent',
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_type    (email_type),
    INDEX idx_user    (user_id),
    INDEX idx_sent_at (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- SETTINGS
-- ============================================================
CREATE TABLE IF NOT EXISTS settings (
    `key`   VARCHAR(100) NOT NULL PRIMARY KEY,
    `value` TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- LOCATION CACHE (Nominatim response cache, 30-day TTL)
-- ============================================================
CREATE TABLE IF NOT EXISTS location_cache (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cache_key     VARCHAR(100) NOT NULL,
    response_json TEXT NOT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cache_key   (cache_key),
    INDEX idx_cache_created   (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- NOTIFICATION QUEUE (emails queued; sent by cron/process_queue.php)
-- ============================================================
CREATE TABLE IF NOT EXISTS notification_queue (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    request_id  INT UNSIGNED NULL,
    type        VARCHAR(50)  NOT NULL DEFAULT 'general',
    subject     VARCHAR(255) NOT NULL,
    body        MEDIUMTEXT   NOT NULL,
    status      ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
    attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    send_after  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at     DATETIME NULL,
    error       VARCHAR(500) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE,
    FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE SET NULL,
    INDEX idx_nq_status  (status, send_after),
    INDEX idx_nq_user    (user_id),
    INDEX idx_nq_request (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- CONVERSATIONS (one per request + responder after acceptance)
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
    INDEX idx_conv_requester (requester_id),
    INDEX idx_conv_responder (responder_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- MESSAGES (chat messages, 1000 char limit enforced in app)
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
-- CHAT REPORTS
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

SET FOREIGN_KEY_CHECKS = 1;
