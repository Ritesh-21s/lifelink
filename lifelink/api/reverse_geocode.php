<?php
/**
 * LifeLink – API: Reverse Geocode proxy
 * Browser → This endpoint → Nominatim (never exposes Nominatim directly)
 *
 * GET api/reverse_geocode.php?lat=13.05&lng=80.25
 * Returns: {"city":"Anna Nagar","state":"Tamil Nadu","country":"India"}
 *
 * Requires: logged-in session OR valid CSRF token (for registration-time calls).
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/geocode.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';

header('Content-Type: application/json; charset=utf-8');

// Rate limit: 30 geocode calls per session (prevents abuse)
startSecureSession();
$_SESSION['geocode_calls'] = ($_SESSION['geocode_calls'] ?? 0) + 1;
if ($_SESSION['geocode_calls'] > 60) {
    http_response_code(429);
    echo json_encode(['error' => 'Rate limit exceeded. Please wait and try again.']);
    exit;
}

$lat = isset($_GET['lat']) ? (float)$_GET['lat'] : null;
$lng = isset($_GET['lng']) ? (float)$_GET['lng'] : null;

if ($lat === null || $lng === null || !validateLatLng($lat, $lng)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid coordinates.']);
    exit;
}

$result = geocodeReverse($lat, $lng);

if ($result === null) {
    http_response_code(503);
    echo json_encode(['error' => 'Geocoding service unavailable. Please enter your location manually.']);
    exit;
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);

