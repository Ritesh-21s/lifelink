<?php
/**
 * LifeLink – API: Request Donors (for requester's real-time view)
 * GET api/request_donors.php?request_id=X
 * Returns nearby/notified donors for a request. No exact coords, no phone/email.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/matching.php';

header('Content-Type: application/json; charset=utf-8');
startSecureSession();

if (!isLoggedIn()) { http_response_code(401); echo json_encode(['error'=>'Not logged in.']); exit; }

$userId    = currentUserId();
$role      = currentRole();
$requestId = (int)($_GET['request_id'] ?? 0);
if ($requestId < 1) { http_response_code(400); echo json_encode(['error'=>'Missing request_id.']); exit; }

$db  = getDB();
$req = $db->prepare('SELECT * FROM requests WHERE id = ?');
$req->execute([$requestId]);
$req = $req->fetch(PDO::FETCH_ASSOC);

if (!$req) { http_response_code(404); echo json_encode(['error'=>'Request not found.']); exit; }

// Only requester who owns it, or admin, can see donor list
if ($role !== 'admin' && (int)$req['requester_id'] !== $userId) {
    http_response_code(403); echo json_encode(['error'=>'Access denied.']); exit;
}

$donors = getRequestDonorsForRequester(
    $requestId,
    (float)($req['lat'] ?? 0),
    (float)($req['lng'] ?? 0)
);

// Keep the endpoint easy to consume while never exposing contact details or
// exact coordinates. These aliases are used by the request-detail live view.
$donors = array_map(static fn(array $donor) => $donor + [
    'name'        => $donor['donor_name'],
    'status'      => $donor['response_status'],
    'distance_km' => $donor['dist_km'],
], $donors);

echo json_encode([
    'request_id' => $requestId,
    'status'     => $req['status'],
    'wave'       => $req['wave'],
    'donors'     => $donors,
]);

