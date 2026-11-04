<?php
/**
 * LifeLink – API: Forward Geocode proxy
 * Browser → This endpoint → Nominatim (search by city/state/country text)
 *
 * GET api/geocode_search.php?q=Anna+Nagar,Tamil+Nadu,India
 * Returns: [{"lat":13.085,"lng":80.209,"city":"Anna Nagar","state":"Tamil Nadu","country":"India","display_name":"..."}]
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/geocode.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';

header('Content-Type: application/json; charset=utf-8');

startSecureSession();
$_SESSION['geocode_calls'] = ($_SESSION['geocode_calls'] ?? 0) + 1;
if ($_SESSION['geocode_calls'] > 60) {
    http_response_code(429);
    echo json_encode(['error' => 'Rate limit exceeded.']);
    exit;
}

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2 || strlen($q) > 200) {
    http_response_code(400);
    echo json_encode(['error' => 'Query too short or too long.']);
    exit;
}

$results = geocodeSearch($q);
echo json_encode($results, JSON_UNESCAPED_UNICODE);

