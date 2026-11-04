<?php
/**
 * LifeLink – API: Toggle donor availability
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';

header('Content-Type: application/json; charset=utf-8');

requireRole('donor');
csrfValidateJson();

$available = (int)($_POST['available'] ?? 0);
$available = $available ? 1 : 0;
$userId    = currentUserId();

$db = getDB();
$db->prepare('UPDATE donors SET is_available=? WHERE user_id=?')->execute([$available, $userId]);

echo json_encode(['success' => true, 'available' => $available]);
