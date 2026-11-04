<?php
/**
 * LifeLink – API: Stats (for footer lives counter etc.)
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: max-age=60');

startSecureSession();
$db = getDB();

$metric = sanitizeInput($_GET['metric'] ?? 'all');

switch ($metric) {
    case 'lives_helped':
        $count = (int)$db->query('SELECT COUNT(*) FROM donations')->fetchColumn();
        echo json_encode(['value' => $count * LIVES_MULTIPLIER]);
        break;
    case 'donors':
        $count = (int)$db->query("SELECT COUNT(*) FROM users WHERE role='donor' AND status='active'")->fetchColumn();
        echo json_encode(['value' => $count]);
        break;
    case 'open_requests':
        $count = (int)$db->query("SELECT COUNT(*) FROM requests WHERE status='Open'")->fetchColumn();
        echo json_encode(['value' => $count]);
        break;
    default:
        $donations  = (int)$db->query('SELECT COUNT(*) FROM donations')->fetchColumn();
        $donors     = (int)$db->query("SELECT COUNT(*) FROM users WHERE role='donor' AND status='active'")->fetchColumn();
        $requests   = (int)$db->query("SELECT COUNT(*) FROM requests")->fetchColumn();
        $fulfilled  = (int)$db->query("SELECT COUNT(*) FROM requests WHERE status='Fulfilled'")->fetchColumn();
        echo json_encode([
            'donations'    => $donations,
            'lives_helped' => $donations * LIVES_MULTIPLIER,
            'donors'       => $donors,
            'requests'     => $requests,
            'fulfilled'    => $fulfilled,
        ]);
}
