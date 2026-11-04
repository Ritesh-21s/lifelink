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
            'secure'   => false,   // set true on HTTPS
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
    if ($user['status'] === 'blocked') {
        logoutUser();
        $_SESSION['flash_error'] = 'Your account has been blocked. Contact admin.';
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

    return ['success' => true, 'role' => $user['role'], 'status' => $user['status']];
}

// -----------------------------------------------------------
// Registration
// -----------------------------------------------------------
function registerUser(array $data): array {
    $db = getDB();

    // Check duplicate email
    $stmt = $db->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([strtolower($data['email'])]);
    if ($stmt->fetch()) {
        return ['success' => false, 'message' => 'This email is already registered.'];
    }

    $hash = password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => 12]);
    $role = in_array($data['role'], ROLES) ? $data['role'] : 'donor';
    // Admins cannot self-register
    if ($role === 'admin') $role = 'donor';
    $status = 'pending'; // admin must approve

    $stmt = $db->prepare(
        'INSERT INTO users (name, email, phone, password_hash, role, status) VALUES (?,?,?,?,?,?)'
    );
    $stmt->execute([
        trim($data['name']),
        strtolower(trim($data['email'])),
        trim($data['phone']),
        $hash,
        $role,
        $status,
    ]);
    $userId = (int)$db->lastInsertId();

    // Role-specific details
    if ($role === 'donor') {
        $stmt = $db->prepare(
            'INSERT INTO donors (user_id, blood_group, gender, dob, area_id, last_donation_date, weight)
             VALUES (?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $userId,
            $data['blood_group'],
            $data['gender'],
            $data['dob'],
            (int)$data['area_id'],
            $data['last_donation_date'] ?: null,
            $data['weight'] ? (float)$data['weight'] : null,
        ]);
    }

    if ($role === 'organizer') {
        $stmt = $db->prepare('INSERT INTO organizers (user_id, org_name) VALUES (?,?)');
        $stmt->execute([$userId, trim($data['org_name'] ?? 'My Organization')]);
    }

    return ['success' => true, 'user_id' => $userId, 'role' => $role];
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
