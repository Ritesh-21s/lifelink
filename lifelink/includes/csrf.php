<?php
/**
 * LifeLink – CSRF Token helpers
 */

require_once __DIR__ . '/../includes/auth.php';

function csrfGenerate(): string {
    startSecureSession();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Backwards-compatible shorthand used by pages that need the raw token. */
function csrfToken(): string {
    return csrfGenerate();
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfGenerate()) . '">';
}

function csrfValidate(): bool {
    startSecureSession();
    $token = $_POST['csrf_token'] ?? '';
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function csrfValidateOrDie(): void {
    if (!csrfValidate()) {
        http_response_code(403);
        die(json_encode(['success' => false, 'message' => 'Invalid CSRF token. Please refresh and try again.']));
    }
}

// For JSON/fetch POST validation that returns JSON
function csrfValidateJson(): void {
    startSecureSession();
    $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'CSRF validation failed.']);
        exit;
    }
}
