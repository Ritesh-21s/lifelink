<?php
/**
 * LifeLink – API: Respond to a request (accept/decline)
 * Called via fetch() POST from donor pages.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/matching.php';

header('Content-Type: application/json; charset=utf-8');

requireRole('donor');
csrfValidateJson();

$requestId = (int)($_POST['request_id'] ?? 0);
$action    = sanitizeInput($_POST['action'] ?? '');
$donorId   = currentUserId();

if (!$requestId || !in_array($action, ['accept','decline'])) {
    jsonResponse(['success' => false, 'message' => 'Invalid request.'], 400);
}

if ($action === 'accept') {
    $result = acceptResponse($requestId, $donorId);
} else {
    $result = declineResponse($requestId, $donorId);
}

echo json_encode($result);
