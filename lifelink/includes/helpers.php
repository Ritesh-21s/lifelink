<?php
/**
 * LifeLink – General helper functions
 */

function e(mixed $value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Check optional columns while supporting databases from earlier releases. */
function dbHasColumn(PDO $db, string $table, string $column): bool {
    static $cache = [];
    $key = $table . '.' . $column;
    if (!array_key_exists($key, $cache)) {
        // MariaDB does not accept a bound parameter in SHOW COLUMNS ... LIKE.
        // information_schema is portable and safely supports prepared values.
        $stmt = $db->prepare(
            'SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
        );
        $stmt->execute([$table, $column]);
        $cache[$key] = (bool)$stmt->fetch();
    }
    return $cache[$key];
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

/**
 * Return the responder column used by the installed conversation schema.
 * Early LifeLink databases used donor_id (and sometimes org_id); current
 * databases use responder_id. This lets upgraded code keep working until the
 * one-time database migration has been run.
 */
function conversationResponderSql(PDO $db, string $alias = 'c'): string {
    static $columns = null;
    if ($columns === null) {
        $columns = array_flip($db->query('SHOW COLUMNS FROM conversations')->fetchAll(PDO::FETCH_COLUMN));
    }
    if (isset($columns['responder_id'])) return "{$alias}.responder_id";
    if (isset($columns['donor_id']) && isset($columns['org_id'])) return "COALESCE({$alias}.donor_id, {$alias}.org_id)";
    if (isset($columns['donor_id'])) return "{$alias}.donor_id";
    if (isset($columns['org_id'])) return "{$alias}.org_id";
    throw new RuntimeException('The conversations table has no responder column. Run the database upgrade.');
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
