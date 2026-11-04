<?php
/**
 * LifeLink – General helper functions
 */

function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function timeAgo(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)       return 'just now';
    if ($diff < 3600)     return floor($diff / 60) . ' min ago';
    if ($diff < 86400)    return floor($diff / 3600) . ' hr ago';
    if ($diff < 604800)   return floor($diff / 86400) . ' days ago';
    return date('d M Y', strtotime($datetime));
}

function formatDate(string $date, string $format = 'd M Y'): string {
    return date($format, strtotime($date));
}

function formatDateTime(string $dt): string {
    return date('d M Y, h:i A', strtotime($dt));
}

function bloodGroupBadgeClass(string $group): string {
    $map = [
        'O-'  => 'badge-o-neg',
        'O+'  => 'badge-o-pos',
        'A+'  => 'badge-a-pos',
        'A-'  => 'badge-a-neg',
        'B+'  => 'badge-b-pos',
        'B-'  => 'badge-b-neg',
        'AB+' => 'badge-ab-pos',
        'AB-' => 'badge-ab-neg',
    ];
    return $map[$group] ?? 'badge-default';
}

function urgencyClass(string $urgency): string {
    return match($urgency) {
        'Critical'        => 'urgency-critical',
        'Within 24 hours' => 'urgency-urgent',
        default           => 'urgency-planned',
    };
}

function statusClass(string $status): string {
    return match($status) {
        'Open'         => 'status-open',
        'Donor Found'  => 'status-found',
        'Fulfilled'    => 'status-fulfilled',
        'Expired'      => 'status-expired',
        'Cancelled'    => 'status-cancelled',
        default        => '',
    };
}

function validateEmail(string $email): bool {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function validatePhone(string $phone): bool {
    return (bool)preg_match('/^[6-9]\d{9}$/', preg_replace('/\D/', '', $phone));
}

function validateBloodGroup(string $group): bool {
    return in_array($group, BLOOD_GROUPS, true);
}

function getAreas(): array {
    static $areas = null;
    if ($areas === null) {
        $areas = getDB()->query('SELECT id, name, city FROM areas ORDER BY name')->fetchAll();
    }
    return $areas;
}

function getAreaName(int $areaId): string {
    foreach (getAreas() as $a) {
        if ((int)$a['id'] === $areaId) return $a['name'];
    }
    return 'Unknown';
}

function getSettings(): array {
    static $settings = null;
    if ($settings === null) {
        $rows = getDB()->query('SELECT `key`, `value` FROM settings')->fetchAll();
        $settings = [];
        foreach ($rows as $r) $settings[$r['key']] = $r['value'];
    }
    return $settings;
}

function getSetting(string $key, mixed $default = null): mixed {
    return getSettings()[$key] ?? $default;
}

function redirect(string $url, string $flashType = '', string $flashMsg = ''): void {
    if ($flashType && $flashMsg) setFlash($flashType, $flashMsg);
    header('Location: ' . $url);
    exit;
}

function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function isPost(): bool { return $_SERVER['REQUEST_METHOD'] === 'POST'; }
function isGet():  bool { return $_SERVER['REQUEST_METHOD'] === 'GET'; }

function sanitizeInput(string $value): string {
    return trim(strip_tags($value));
}

function maskPhone(string $phone): string {
    return substr($phone, 0, 4) . '******' . substr($phone, -2);
}

function donorBadge(int $donations): ?string {
    if ($donations >= 10) return '🏆 Gold Lifesaver (10+)';
    if ($donations >= 5)  return '🥈 Silver Hero (5+)';
    if ($donations >= 1)  return '🥉 Bronze Donor (1+)';
    return null;
}

function countDonorDonations(int $userId): int {
    $stmt = getDB()->prepare('SELECT COUNT(*) FROM donations WHERE donor_id = ?');
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

function countLivesHelped(int $userId): int {
    return countDonorDonations($userId) * LIVES_MULTIPLIER;
}

function globalLivesHelped(): int {
    $count = (int)getDB()->query('SELECT COUNT(*) FROM donations')->fetchColumn();
    return $count * LIVES_MULTIPLIER;
}
