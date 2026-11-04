<?php
/**
 * LifeLink – Donor Search (public, masked)
 * Updated: replaced area_id filter with city text + radius filter.
 *          Shows approximate distance if city is provided.
 */
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/eligibility.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/geocode.php';

startSecureSession();
$db = getDB();

$filterGroup  = in_array($_GET['blood_group'] ?? '', BLOOD_GROUPS) ? $_GET['blood_group'] : '';
$filterCity   = sanitizeInput($_GET['city'] ?? '');
$radiusInput = filter_input(INPUT_GET, 'radius', FILTER_VALIDATE_INT);
$filterRadius = in_array($radiusInput, [5, 10, 25, 50], true) ? $radiusInput : 25;

// Geocode search city server-side (avoid browser calling Nominatim directly)
$searchLat = null; $searchLng = null;
if ($filterCity) {
    // Try to geocode to get coordinates for radius filter
    $geo = geocodeSearch($filterCity . ', India');
    if (!empty($geo) && isset($geo[0]['lat'], $geo[0]['lng'])) {
        $searchLat = (float)$geo[0]['lat'];
        $searchLng = (float)$geo[0]['lng'];
    }
}

$params = [];
$having = '';

if ($searchLat !== null) {
    // Bounding-box pre-filter + Haversine
    $dLat = $filterRadius / 111.0;
    $dLng = $filterRadius / (111.0 * max(cos(deg2rad($searchLat)), 0.0001));
    $latMin = $searchLat - $dLat; $latMax = $searchLat + $dLat;
    $lngMin = $searchLng - $dLng; $lngMax = $searchLng + $dLng;

    $distExpr = "ROUND(6371 * ACOS(LEAST(1, COS(RADIANS(?)) * COS(RADIANS(d.lat)) * COS(RADIANS(d.lng) - RADIANS(?)) + SIN(RADIANS(?)) * SIN(RADIANS(d.lat)))), 1)";
    $params[] = $searchLat; $params[] = $searchLng; $params[] = $searchLat;
    $having   = "HAVING dist_km <= $filterRadius";

    $sql = "SELECT d.blood_group, d.gender, d.dob, d.last_donation_date, d.is_available, d.verified,
                   d.user_id, u.city AS donor_city, u.state AS donor_state,
                   u.name, u.status AS user_status,
                   DATEDIFF(CURDATE(), d.last_donation_date) AS days_since_donation,
                   $distExpr AS dist_km
            FROM donors d
            JOIN users u ON u.id = d.user_id
            WHERE u.status = 'active' AND d.is_available = 1 AND d.verified = 1
              AND d.lat IS NOT NULL AND d.lat BETWEEN ? AND ? AND d.lng BETWEEN ? AND ?";
    $params[] = $latMin; $params[] = $latMax; $params[] = $lngMin; $params[] = $lngMax;
} else {
    $sql = "SELECT d.blood_group, d.gender, d.dob, d.last_donation_date, d.is_available, d.verified,
                   d.user_id, u.city AS donor_city, u.state AS donor_state,
                   u.name, u.status AS user_status,
                   DATEDIFF(CURDATE(), d.last_donation_date) AS days_since_donation,
                   NULL AS dist_km
            FROM donors d
            JOIN users u ON u.id = d.user_id
            LEFT JOIN areas a ON a.id = d.area_id
            WHERE u.status = 'active' AND d.is_available = 1 AND d.verified = 1";
}

if ($filterGroup) {
    $sql .= ' AND d.blood_group = ?';
    $params[] = $filterGroup;
}

// City text fallback (if geocode failed, do LIKE match on stored city column)
if ($filterCity && $searchLat === null) {
    $sql .= ' AND u.city LIKE ?';
    $params[] = '%' . $filterCity . '%';
}

$sql .= " $having ORDER BY dist_km ASC, d.blood_group ASC LIMIT 60";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$donors = $stmt->fetchAll();

// Filter eligible
$eligible = [];
foreach ($donors as $d) {
    if (isDonorEligible($d, ['status' => $d['user_status']])) {
        $eligible[] = $d;
    }
}

$pageTitle       = 'Find Donors';
$metaDescription = 'Search for available and eligible blood donors near you. Filter by blood group and city.';
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div class="container">
        <h1>Find Blood Donors</h1>
        <p>Search available, eligible donors. Contact details are never shown here.</p>
    </div>
</div>

<section class="section-sm">
    <div class="container">
        <!-- Search filters -->
        <div class="card mb-6">
            <div class="card-body">
                <form method="GET" style="display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:var(--space-4);align-items:end" id="searchForm">
                    <div class="form-group">
                        <label for="blood_group">Blood Group Needed</label>
                        <select id="blood_group" name="blood_group">
                            <option value="">All blood groups</option>
                            <?php foreach (BLOOD_GROUPS as $g): ?>
                            <option value="<?= $g ?>" <?= $filterGroup === $g ? 'selected' : '' ?>><?= $g ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="city">City / Area</label>
                        <input type="text" id="city" name="city" value="<?= e($filterCity) ?>" placeholder="e.g. Chennai, Mumbai…">
                    </div>
                    <div class="form-group">
                        <label for="radius">Search Radius</label>
                        <select id="radius" name="radius">
                            <?php foreach ([5, 10, 25, 50] as $r): ?>
                            <option value="<?= $r ?>" <?= $filterRadius === $r ? 'selected' : '' ?>><?= $r ?> km</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">🔍 Search</button>
                </form>

                <?php if ($filterCity && $searchLat === null): ?>
                <div class="alert alert-info mt-4" style="margin-bottom:0">
                    ℹ️ Could not geocode "<strong><?= e($filterCity) ?></strong>" — showing text-match results. Try a more specific city name.
                </div>
                <?php elseif ($filterCity && $searchLat !== null): ?>
                <div style="font-size:var(--text-xs);color:var(--gray-400);margin-top:var(--space-2)">
                    📍 Showing donors within <?= $filterRadius ?> km of <?= e($filterCity) ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Blood group quick badges -->
        <div style="display:flex;flex-wrap:wrap;gap:var(--space-2);margin-bottom:var(--space-6)">
            <?php foreach (BLOOD_GROUPS as $g): ?>
            <a href="?blood_group=<?= urlencode($g) ?><?= $filterCity ? '&city=' . urlencode($filterCity) : '' ?>"
               class="blood-badge <?= bloodGroupBadgeClass($g) ?> <?= $filterGroup === $g ? '' : 'opacity-80' ?>"
               style="padding:6px 14px;font-size:var(--text-sm)"><?= $g ?></a>
            <?php endforeach; ?>
        </div>

        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--space-4)">
            <h2 style="font-size:var(--text-xl)">
                <?= count($eligible) ?> Available Donor<?= count($eligible) !== 1 ? 's' : '' ?>
                <?php if ($filterGroup): ?> · <span class="blood-badge <?= bloodGroupBadgeClass($filterGroup) ?>"><?= $filterGroup ?></span><?php endif; ?>
                <?php if ($filterCity): ?> · Near <?= e($filterCity) ?><?php endif; ?>
            </h2>
        </div>

        <?php if (empty($eligible)): ?>
            <div class="empty-state card">
                <div class="empty-icon">👤</div>
                <h3>No Available Donors Found</h3>
                <p>Try a different blood group, city, or wider radius, or post an emergency request.</p>
                <?php if (isLoggedIn() && currentRole() === 'requester'): ?>
                    <a href="<?= APP_URL ?>/pages/requester/post_request.php" class="btn btn-primary">Post Emergency Request</a>
                <?php else: ?>
                    <a href="<?= APP_URL ?>/pages/register.php?role=requester" class="btn btn-primary">Request Blood</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="grid-3">
            <?php foreach ($eligible as $d): ?>
                <div class="donor-card card-hover">
                    <div class="donor-card-header">
                        <div class="donor-avatar"><?= strtoupper(substr($d['name'], 0, 1)) ?></div>
                        <div class="donor-info">
                            <!-- Privacy: show first name only -->
                            <h3><?= e(explode(' ', $d['name'])[0]) ?> <?= str_repeat('*', max(1, strlen(explode(' ', $d['name'])[1] ?? 'X') - 1)) ?></h3>
                            <div class="donor-meta">
                                📍 <?= e($d['donor_city'] ?? 'India') ?><?= $d['donor_state'] ? ', ' . e($d['donor_state']) : '' ?>
                            </div>
                        </div>
                    </div>
                    <div style="display:flex;gap:var(--space-2);flex-wrap:wrap">
                        <span class="blood-badge <?= bloodGroupBadgeClass($d['blood_group']) ?>"><?= e($d['blood_group']) ?></span>
                        <span class="status-badge status-fulfilled">✅ Available</span>
                        <span class="status-badge status-found">✓ Eligible</span>
                    </div>
                    <div class="donor-meta" style="font-size:var(--text-xs)">
                        <?php if ($d['days_since_donation']): ?>
                            Last donated <?= (int)$d['days_since_donation'] ?> days ago
                        <?php else: ?>
                            New donor
                        <?php endif; ?>
                    </div>
                    <?php if ($d['dist_km'] !== null): ?>
                    <div style="font-size:var(--text-xs);color:var(--gray-500)">
                        📏 about <?= $d['dist_km'] ?> km away
                    </div>
                    <?php endif; ?>
                    <div style="font-size:var(--text-xs);color:var(--gray-400)">
                        🔒 Contact hidden for privacy
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="alert alert-info mt-6">
            ℹ️ <strong>Privacy Notice:</strong> Donor contact details are never shown here. Post an emergency request and the matching donor will decide whether to share their contact.
        </div>
    </div>
</section>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
