<?php
/**
 * LifeLink – Authentication helpers
 * Handles session, login, logout, registration, and role guards.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';

// -----------------------------------------------------------
// Session bootstrap
// -----------------------------------------------------------
function startSecureSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_name(SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path'     => '/',
            // Do not send an authenticated session cookie over an unencrypted
            // connection when the application is deployed behind HTTPS.
            'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                          || (($_SERVER['SERVER_PORT'] ?? null) == 443),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

// -----------------------------------------------------------
// Auth state helpers
// -----------------------------------------------------------
function isLoggedIn(): bool {
    startSecureSession();
    return !empty($_SESSION['user_id']);
}

function currentUser(): ?array {
    if (!isLoggedIn()) return null;
    return [
        'id'     => $_SESSION['user_id'],
        'name'   => $_SESSION['user_name'],
        'email'  => $_SESSION['user_email'],
        'role'   => $_SESSION['user_role'],
        'status' => $_SESSION['user_status'],
    ];
}

function currentUserId(): int {
    return (int)($_SESSION['user_id'] ?? 0);
}

function currentRole(): string {
    return $_SESSION['user_role'] ?? '';
}

// -----------------------------------------------------------
// Role guards (call at top of every protected page)
// -----------------------------------------------------------
function requireLogin(): void {
    startSecureSession();
    if (!isLoggedIn()) {
        $_SESSION['flash_error'] = 'Please log in to continue.';
        header('Location: ' . APP_URL . '/pages/login.php');
        exit;
    }
}

function requireRole(string ...$roles): void {
    requireLogin();
    $user = currentUser();
    if (!in_array($user['role'], $roles, true)) {
        http_response_code(403);
        require __DIR__ . '/../pages/403.php';
        exit;
    }
    if ($user['status'] === 'unverified') {
        logoutUser();
        setFlash('error', 'Please verify your email before logging in. Check your inbox.');
        header('Location: ' . APP_URL . '/pages/login.php');
        exit;
    }
    if ($user['status'] === 'blocked') {
        logoutUser();
        setFlash('error', 'Your account has been blocked. Contact admin.');
        header('Location: ' . APP_URL . '/pages/login.php');
        exit;
    }
    if ($user['status'] === 'pending' && $user['role'] !== 'admin') {
        require __DIR__ . '/../pages/pending.php';
        exit;
    }
}


function requireAdmin(): void {
    requireRole('admin');
}

// -----------------------------------------------------------
// Login
// -----------------------------------------------------------
function loginUser(string $email, string $password): array {
    $db  = getDB();
    $email = trim(strtolower($email));

    // Fetch user
    $stmt = $db->prepare('SELECT id, name, email, phone, password_hash, role, status, login_attempts, locked_until FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        return ['success' => false, 'message' => 'Invalid email or password.'];
    }

    // Block unverified accounts
    if ($user['status'] === 'unverified') {
        return ['success' => false, 'message' => 'Please verify your email first. Check your inbox for the activation link.'];
    }

    // Check lockout
    if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
        $mins = ceil((strtotime($user['locked_until']) - time()) / 60);
        return ['success' => false, 'message' => "Account locked. Try again in {$mins} minute(s)."];
    }

    // Verify password
    if (!password_verify($password, $user['password_hash'])) {
        $attempts = $user['login_attempts'] + 1;
        if ($attempts >= MAX_LOGIN_ATTEMPTS) {
            $lockedUntil = date('Y-m-d H:i:s', time() + LOCKOUT_MINUTES * 60);
            $db->prepare('UPDATE users SET login_attempts = ?, locked_until = ? WHERE id = ?')
               ->execute([$attempts, $lockedUntil, $user['id']]);
            return ['success' => false, 'message' => 'Too many failed attempts. Account locked for ' . LOCKOUT_MINUTES . ' minutes.'];
        }
        $db->prepare('UPDATE users SET login_attempts = ? WHERE id = ?')->execute([$attempts, $user['id']]);
        $remaining = MAX_LOGIN_ATTEMPTS - $attempts;
        return ['success' => false, 'message' => "Invalid email or password. {$remaining} attempt(s) remaining."];
    }

    // Reset attempts on success
    $db->prepare('UPDATE users SET login_attempts = 0, locked_until = NULL WHERE id = ?')->execute([$user['id']]);

    // Start session
    startSecureSession();
    session_regenerate_id(true);
    $_SESSION['user_id']     = $user['id'];
    $_SESSION['user_name']   = $user['name'];
    $_SESSION['user_email']  = $user['email'];
    $_SESSION['user_role']   = $user['role'];
    $_SESSION['user_status'] = $user['status'];

    return ['success' => true, 'role' => $user['role'], 'status' => $user['status'], 'name' => $user['name']];

}

// -----------------------------------------------------------
// Registration
// -----------------------------------------------------------
function registerUser(array $data): array {
    $db = getDB();

    // Auto-migrate: ensure all v2 columns exist (safe to run repeatedly)
    try {
        $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS email_token         VARCHAR(64)  NULL DEFAULT NULL");
        $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS email_token_expires DATETIME     NULL DEFAULT NULL");
        $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS lat                 DECIMAL(9,6) NULL DEFAULT NULL");
        $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS lng                 DECIMAL(9,6) NULL DEFAULT NULL");
        $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS city                VARCHAR(100) NULL DEFAULT NULL");
        $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS state               VARCHAR(100) NULL DEFAULT NULL");
        $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS country             VARCHAR(100) NULL DEFAULT NULL");
        $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS location_source     ENUM('gps','manual','geocoded') NULL DEFAULT NULL");
        $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS location_updated_at DATETIME     NULL DEFAULT NULL");
        $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS location_consent    TINYINT(1)   NOT NULL DEFAULT 0");
        $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS location_incomplete TINYINT(1)   NOT NULL DEFAULT 0");
        $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS notify_email        TINYINT(1)   NOT NULL DEFAULT 1");
        $db->exec("ALTER TABLE donors ADD COLUMN IF NOT EXISTS lat                DECIMAL(9,6) NULL DEFAULT NULL");
        $db->exec("ALTER TABLE donors ADD COLUMN IF NOT EXISTS lng                DECIMAL(9,6) NULL DEFAULT NULL");
        $db->exec("ALTER TABLE donors ADD COLUMN IF NOT EXISTS location_consent   TINYINT(1)   NOT NULL DEFAULT 0");
        $db->exec("ALTER TABLE donors ADD COLUMN IF NOT EXISTS location_reminder_sent TINYINT(1) NOT NULL DEFAULT 0");
        $db->exec("ALTER TABLE donors ADD COLUMN IF NOT EXISTS daily_alerts_sent  TINYINT UNSIGNED NOT NULL DEFAULT 0");
        $db->exec("ALTER TABLE donors ADD COLUMN IF NOT EXISTS daily_alert_date   DATE NULL DEFAULT NULL");
        $db->exec("ALTER TABLE donors ADD COLUMN IF NOT EXISTS total_alerts       INT UNSIGNED NOT NULL DEFAULT 0");
        $db->exec("ALTER TABLE donors ADD COLUMN IF NOT EXISTS total_accepted     INT UNSIGNED NOT NULL DEFAULT 0");
        $db->exec("ALTER TABLE donors ADD COLUMN IF NOT EXISTS ignored_streak     TINYINT UNSIGNED NOT NULL DEFAULT 0");
    } catch (Throwable $e) {
        // Columns may already exist — ignore
    }

    // Ensure new tables exist (chat, notification queue)
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS notification_queue (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL, request_id INT UNSIGNED NULL,
            type VARCHAR(50) NOT NULL DEFAULT 'general',
            subject VARCHAR(255) NOT NULL, body MEDIUMTEXT NOT NULL,
            status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            send_after DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            sent_at DATETIME NULL, error VARCHAR(500) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_nq_status (status, send_after),
            INDEX idx_nq_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS conversations (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            request_id INT UNSIGNED NOT NULL, requester_id INT UNSIGNED NOT NULL,
            responder_id INT UNSIGNED NOT NULL,
            status ENUM('open','closed') NOT NULL DEFAULT 'open',
            closed_reason VARCHAR(100) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, closed_at DATETIME NULL,
            UNIQUE KEY uq_conv (request_id, responder_id),
            INDEX idx_conv_requester (requester_id), INDEX idx_conv_responder (responder_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS messages (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            conversation_id INT UNSIGNED NOT NULL, sender_id INT UNSIGNED NOT NULL,
            body VARCHAR(1000) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, read_at DATETIME NULL,
            INDEX idx_msg_conv_id (conversation_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS location_cache (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            cache_key VARCHAR(100) NOT NULL, response_json TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_cache_key (cache_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) { /* ignore */ }

    // Check duplicate email
    $stmt = $db->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([strtolower($data['email'])]);
    if ($stmt->fetch()) {
        return ['success' => false, 'message' => 'This email is already registered.'];
    }

    $hash = password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => 12]);
    $role = in_array($data['role'], ROLES) ? $data['role'] : 'donor';
    if ($role === 'admin') $role = 'donor';

    // Generate email verification token (24h expiry)
    $token   = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', time() + 86400);

    // Location data
    $lat      = isset($data['lat']) && $data['lat'] !== null && $data['lat'] !== '' ? (float)$data['lat'] : null;
    $lng      = isset($data['lng']) && $data['lng'] !== null && $data['lng'] !== '' ? (float)$data['lng'] : null;
    $city     = trim($data['city'] ?? '');
    $state    = trim($data['state'] ?? '');
    $country  = trim($data['country'] ?? '');
    $locSrc   = $data['location_source'] ?? 'manual';
    $consent  = (int)($data['location_consent'] ?? 0);
    $locIncomplete = ($lat === null || $lng === null) ? 1 : 0;

    $stmt = $db->prepare(
        'INSERT INTO users (name, email, phone, password_hash, role, status,
                            email_token, email_token_expires,
                            lat, lng, city, state, country,
                            location_source, location_updated_at, location_consent, location_incomplete)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?,?)'
    );
    $stmt->execute([
        trim($data['name']),
        strtolower(trim($data['email'])),
        trim($data['phone']),
        $hash,
        $role,
        'unverified',
        $token,
        $expires,
        $lat,
        $lng,
        $city ?: null,
        $state ?: null,
        $country ?: null,
        $locSrc,
        $consent,
        $locIncomplete,
    ]);
    $userId = (int)$db->lastInsertId();

    if ($role === 'donor') {
        $areaId = !empty($data['area_id']) ? (int)$data['area_id'] : null;
        $stmt = $db->prepare(
            'INSERT INTO donors (user_id, blood_group, gender, dob, area_id,
                                 last_donation_date, weight, lat, lng, location_consent)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $userId,
            $data['blood_group'],
            $data['gender'],
            $data['dob'],
            $areaId,
            $data['last_donation_date'] ?: null,
            $data['weight'] ? (float)$data['weight'] : null,
            $lat,
            $lng,
            $consent,
        ]);
    }

    if ($role === 'organizer') {
        $stmt = $db->prepare('INSERT INTO organizers (user_id, org_name) VALUES (?,?)');
        $stmt->execute([$userId, trim($data['org_name'] ?? 'My Organization')]);
    }

    if ($role === 'organization') {
        // Ensure organizations table exists
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS organizations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL, org_name VARCHAR(200) NOT NULL,
                org_type ENUM('hospital','blood_bank','ngo','college','other') NOT NULL DEFAULT 'other',
                verified TINYINT(1) NOT NULL DEFAULT 0, blood_stock JSON NULL,
                lat DECIMAL(9,6) NULL, lng DECIMAL(9,6) NULL,
                city VARCHAR(100) NULL, state VARCHAR(100) NULL, country VARCHAR(100) NULL,
                location_consent TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_org_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (Throwable) {}
        $stmt = $db->prepare(
            'INSERT INTO organizations (user_id, org_name, org_type, lat, lng, city, state, country, location_consent)
             VALUES (?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $userId,
            trim($data['org_name'] ?? 'My Organization'),
            $data['org_type'] ?? 'other',
            $lat, $lng,
            $city ?: null, $state ?: null, $country ?: null,
            $consent,
        ]);
    }

    return ['success' => true, 'user_id' => $userId, 'role' => $role, 'token' => $token];
}

// -----------------------------------------------------------

// Logout
// -----------------------------------------------------------
function logoutUser(): void {
    startSecureSession();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

// -----------------------------------------------------------
// Flash messages
// -----------------------------------------------------------
function setFlash(string $type, string $message): void {
    startSecureSession();
    $_SESSION["flash_{$type}"] = $message;
}

function getFlash(string $type): ?string {
    startSecureSession();
    $msg = $_SESSION["flash_{$type}"] ?? null;
    unset($_SESSION["flash_{$type}"]);
    return $msg;
}
