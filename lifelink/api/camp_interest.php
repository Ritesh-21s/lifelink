<?php
/**
 * LifeLink – API: Camp Interest toggle
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';

header('Content-Type: application/json; charset=utf-8');

requireRole('donor');
csrfValidateJson();

$campId  = (int)($_POST['camp_id'] ?? 0);
$donorId = currentUserId();
$db      = getDB();

if (!$campId) {
    echo json_encode(['success' => false, 'message' => 'Invalid camp.']);
    exit;
}

// Check camp exists and is approved
$camp = $db->prepare('SELECT id FROM camps WHERE id=? AND status=\'approved\'');
$camp->execute([$campId]);
if (!$camp->fetch()) {
    echo json_encode(['success' => false, 'message' => 'Camp not found.']);
    exit;
}

// Toggle
$existing = $db->prepare('SELECT id FROM camp_interest WHERE camp_id=? AND donor_id=?');
$existing->execute([$campId, $donorId]);
if ($existing->fetch()) {
    $db->prepare('DELETE FROM camp_interest WHERE camp_id=? AND donor_id=?')->execute([$campId, $donorId]);
    $attending = false;
} else {
    $db->prepare('INSERT IGNORE INTO camp_interest (camp_id, donor_id) VALUES (?,?)')->execute([$campId, $donorId]);
    $attending = true;
}

$cStmt = $db->prepare('SELECT COUNT(*) FROM camp_interest WHERE camp_id=?');
$cStmt->execute([$campId]);
$count = (int)$cStmt->fetchColumn();

echo json_encode(['success' => true, 'attending' => $attending, 'count' => $count]);
