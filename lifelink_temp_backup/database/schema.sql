-- LifeLink Blood Donor & Emergency Request Network
-- Database Schema
-- Character set: utf8mb4, Engine: InnoDB

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO';

CREATE DATABASE IF NOT EXISTS lifelink CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE lifelink;

-- ============================================================
-- USERS (base account for all roles)
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    email           VARCHAR(150) NOT NULL,
    phone           VARCHAR(20)  NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    role            ENUM('donor','requester','organizer','admin') NOT NULL DEFAULT 'donor',
    status          ENUM('pending','active','blocked') NOT NULL DEFAULT 'pending',
    login_attempts  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until    DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_email (email),
    INDEX idx_role   (role),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- AREAS
-- ============================================================
CREATE TABLE IF NOT EXISTS areas (
    id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name    VARCHAR(100) NOT NULL,
    city    VARCHAR(100) NOT NULL,
    INDEX idx_city (city)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- AREA ADJACENCY (undirected; insert both directions)
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
    user_id             INT UNSIGNED NOT NULL PRIMARY KEY,
    blood_group         ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-') NOT NULL,
    gender              ENUM('male','female','other') NOT NULL,
    dob                 DATE NOT NULL,
    area_id             INT UNSIGNED NOT NULL,
    last_donation_date  DATE NULL,
    is_available        TINYINT(1) NOT NULL DEFAULT 1,
    verified            TINYINT(1) NOT NULL DEFAULT 0,
    reminder_sent_for   DATE NULL,
    weight              DECIMAL(5,2) NULL,
    FOREIGN KEY (user_id)  REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (area_id)  REFERENCES areas(id),
    INDEX idx_blood_group  (blood_group),
    INDEX idx_area         (area_id),
    INDEX idx_available    (is_available)
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
    area_id         INT UNSIGNED NOT NULL,
    urgency         ENUM('Critical','Within 24 hours','Planned') NOT NULL DEFAULT 'Planned',
    contact_phone   VARCHAR(20) NOT NULL,
    needed_by       DATETIME NOT NULL,
    status          ENUM('Open','Donor Found','Fulfilled','Expired','Cancelled') NOT NULL DEFAULT 'Open',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at      DATETIME NULL,
    escalated_at    DATETIME NULL,
    notes           TEXT NULL,
    FOREIGN KEY (requester_id) REFERENCES users(id),
    FOREIGN KEY (area_id)      REFERENCES areas(id),
    INDEX idx_status     (status),
    INDEX idx_blood_group (blood_group),
    INDEX idx_urgency    (urgency),
    INDEX idx_created    (created_at),
    INDEX idx_requester  (requester_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- RESPONSES (donor responses to requests)
-- ============================================================
CREATE TABLE IF NOT EXISTS responses (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id   INT UNSIGNED NOT NULL,
    donor_id     INT UNSIGNED NOT NULL,
    status       ENUM('notified','accepted','declined','donated') NOT NULL DEFAULT 'notified',
    responded_at DATETIME NULL,
    UNIQUE KEY uq_request_donor (request_id, donor_id),
    FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
    FOREIGN KEY (donor_id)   REFERENCES users(id)    ON DELETE CASCADE,
    INDEX idx_donor_status (donor_id, status)
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
    INDEX idx_donor      (donor_id),
    INDEX idx_confirmed  (confirmed_at)
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
    area_id      INT UNSIGNED NOT NULL,
    camp_date    DATE NOT NULL,
    start_time   TIME NOT NULL,
    end_time     TIME NOT NULL,
    contact      VARCHAR(20) NOT NULL,
    description  TEXT NULL,
    status       ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (organizer_id) REFERENCES users(id),
    FOREIGN KEY (area_id)      REFERENCES areas(id),
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
    email_type VARCHAR(50) NOT NULL,
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

SET FOREIGN_KEY_CHECKS = 1;
