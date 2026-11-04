<?php
/**
 * LifeLink – API: Urgent Requests (live ticker)
 * Supports last_updated optimization to reduce payload.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

startSecureSession();
$db = getDB();

$clientLastUpdated = (int)($_GET['last_updated'] ?? 0);

// Lightweight check: max(created_at) of open requests
$latestTs = (int)strtotime(
    $db->query("SELECT MAX(created_at) FROM requests WHERE status IN ('Open','Donor Found')")->fetchColumn() ?: '1970-01-01'
);

// If client is up to date, return no_change
if ($clientLastUpdated > 0 && $clientLastUpdated >= $latestTs) {
    echo json_encode(['no_change' => true, 'last_updated' => $latestTs]);
    exit;
}

$stmt = $db->query(
    "SELECT r.id, r.blood_group, r.urgency, r.hospital,
            r.units_needed, r.units_accepted,
            (r.units_needed - r.units_accepted) AS units_still_needed,
            r.created_at,
            COALESCE(r.city, a.name, 'Location pending') AS area_name
     FROM requests r LEFT JOIN areas a ON a.id=r.area_id
     WHERE r.status IN ('Open','Donor Found')
       AND r.expires_at > NOW()
     ORDER BY r.urgency='Critical' DESC, r.created_at DESC
     LIMIT 15"
);
$rows = $stmt->fetchAll();

$out = [];
foreach ($rows as $r) {
    $out[] = [
        'id'                => (int)$r['id'],
        'blood_group'       => $r['blood_group'],
        'urgency'           => $r['urgency'],
        'hospital'          => $r['hospital'],
        'area_name'         => $r['area_name'],
        'units_still_needed'=> (int)$r['units_still_needed'],
        'posted_ago'        => timeAgo($r['created_at']),
    ];
}

echo json_encode(['requests' => $out, 'last_updated' => $latestTs, 'no_change' => false]);
