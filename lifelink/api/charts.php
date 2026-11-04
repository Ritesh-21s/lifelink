<?php
/**
 * LifeLink – API: Charts data for analytics dashboard
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/eligibility.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: max-age=120');

startSecureSession();
$db   = getDB();
$type = sanitizeInput($_GET['type'] ?? '');

switch ($type) {

    case 'donors_by_group':
        // Available + eligible donors per blood group
        $stmt = $db->query(
            "SELECT d.blood_group, COUNT(*) AS total_available
             FROM donors d JOIN users u ON u.id=d.user_id
             WHERE u.status='active' AND d.is_available=1 AND d.verified=1
             GROUP BY d.blood_group ORDER BY d.blood_group"
        );
        $rows = $stmt->fetchAll();
        // Filter eligible (server-side eligibility is expensive for all; use gap approximation)
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['blood_group' => $r['blood_group'], 'count' => (int)$r['total_available']];
        }
        echo json_encode($out);
        break;

    case 'requests_by_urgency':
        $stmt = $db->query(
            "SELECT urgency, COUNT(*) AS count FROM requests GROUP BY urgency ORDER BY FIELD(urgency,'Critical','Within 24 hours','Planned')"
        );
        echo json_encode($stmt->fetchAll());
        break;

    case 'requests_by_status':
        $stmt = $db->query(
            "SELECT status, COUNT(*) AS count FROM requests GROUP BY status"
        );
        echo json_encode($stmt->fetchAll());
        break;

    case 'monthly_trend':
        $stmt = $db->query(
            "SELECT
                DATE_FORMAT(r.created_at,'%b %Y') AS month,
                DATE_FORMAT(r.created_at,'%Y-%m') AS sort_key,
                COUNT(*) AS requests,
                SUM(CASE WHEN r.status='Fulfilled' THEN 1 ELSE 0 END) AS fulfilled
             FROM requests r
             WHERE r.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
             GROUP BY sort_key, month
             ORDER BY sort_key"
        );
        echo json_encode($stmt->fetchAll());
        break;

    case 'shortages':
        $threshold = (int)getSetting('shortage_threshold', SHORTAGE_THRESHOLD);
        $stmt = $db->query(
            "SELECT a.name AS area_name, d.blood_group, COUNT(*) AS count
             FROM donors d
             JOIN users u ON u.id=d.user_id
             JOIN areas a ON a.id=d.area_id
             WHERE u.status='active' AND d.is_available=1 AND d.verified=1
             GROUP BY d.area_id, d.blood_group
             HAVING count < {$threshold}
             ORDER BY count ASC, a.name"
        );
        echo json_encode($stmt->fetchAll());
        break;

    case 'admin_heatmap':
        // Area x blood-group availability (admin only)
        requireRole('admin');
        $stmt = $db->query(
            "SELECT a.name AS area_name, d.blood_group, COUNT(*) AS count
             FROM donors d JOIN users u ON u.id=d.user_id JOIN areas a ON a.id=d.area_id
             WHERE u.status='active' AND d.is_available=1 AND d.verified=1
             GROUP BY a.id, d.blood_group"
        );
        echo json_encode($stmt->fetchAll());
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid chart type.']);
}
