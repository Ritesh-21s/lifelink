<?php
/**
 * LifeLink – Donor Search (masked)
 */
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/eligibility.php';
require_once __DIR__ . '/../../includes/csrf.php';

startSecureSession();
$db    = getDB();
$areas = getAreas();

$filterGroup = in_array($_GET['blood_group']??'', BLOOD_GROUPS) ? $_GET['blood_group'] : '';
$filterArea  = (int)($_GET['area_id'] ?? 0);

$sql = "SELECT d.blood_group, d.gender, d.dob, d.area_id, d.last_donation_date, d.is_available, d.verified,
               d.user_id, u.name, u.status AS user_status,
               a.name AS area_name, a.city,
               DATEDIFF(CURDATE(), d.last_donation_date) AS days_since_donation
        FROM donors d
        JOIN users u ON u.id=d.user_id
        JOIN areas a ON a.id=d.area_id
        WHERE u.status='active' AND d.is_available=1 AND d.verified=1";

$params = [];
if ($filterGroup) { $sql .= ' AND d.blood_group=?'; $params[] = $filterGroup; }
if ($filterArea)  { $sql .= ' AND d.area_id=?';    $params[] = $filterArea; }
$sql .= ' ORDER BY d.blood_group, d.last_donation_date ASC LIMIT 60';

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
$metaDescription = 'Search for available and eligible blood donors near you. Filter by blood group and area.';
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
                <form method="GET" style="display:grid;grid-template-columns:1fr 1fr auto;gap:var(--space-4);align-items:end">
                    <div class="form-group">
                        <label for="blood_group">Blood Group Needed</label>
                        <select id="blood_group" name="blood_group">
                            <option value="">All blood groups</option>
                            <?php foreach(BLOOD_GROUPS as $g): ?>
                            <option value="<?= $g ?>" <?= $filterGroup===$g?'selected':'' ?>><?= $g ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="area_id">Area</label>
                        <select id="area_id" name="area_id">
                            <option value="">All areas</option>
                            <?php foreach($areas as $a): ?>
                            <option value="<?= $a['id'] ?>" <?= $filterArea==$a['id']?'selected':'' ?>><?= e($a['name']) ?>, <?= e($a['city']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">🔍 Search</button>
                </form>
            </div>
        </div>

        <!-- Blood group quick badges -->
        <div style="display:flex;flex-wrap:wrap;gap:var(--space-2);margin-bottom:var(--space-6)">
            <?php foreach(BLOOD_GROUPS as $g): ?>
            <a href="?blood_group=<?= urlencode($g) ?>" class="blood-badge <?= bloodGroupBadgeClass($g) ?> <?= $filterGroup===$g?'':'opacity-80' ?>" style="padding:6px 14px;font-size:var(--text-sm)"><?= $g ?></a>
            <?php endforeach; ?>
        </div>

        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--space-4)">
            <h2 style="font-size:var(--text-xl)">
                <?= count($eligible) ?> Available Donor<?= count($eligible)!==1?'s':'' ?>
                <?php if($filterGroup): ?> · <span class="blood-badge <?= bloodGroupBadgeClass($filterGroup) ?>"><?= $filterGroup ?></span><?php endif; ?>
                <?php if($filterArea): ?> · <?= e(getAreaName($filterArea)) ?><?php endif; ?>
            </h2>
        </div>

        <?php if(empty($eligible)): ?>
            <div class="empty-state card">
                <div class="empty-icon">👤</div>
                <h3>No Available Donors Found</h3>
                <p>Try a different blood group or area, or post an emergency request.</p>
                <?php if(isLoggedIn() && currentRole()==='requester'): ?>
                    <a href="<?= APP_URL ?>/pages/requester/post_request.php" class="btn btn-primary">Post Emergency Request</a>
                <?php else: ?>
                    <a href="<?= APP_URL ?>/pages/register.php?role=requester" class="btn btn-primary">Request Blood</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="grid-3">
            <?php foreach($eligible as $d): ?>
                <div class="donor-card card-hover">
                    <div class="donor-card-header">
                        <div class="donor-avatar"><?= strtoupper(substr($d['name'],0,1)) ?></div>
                        <div class="donor-info">
                            <!-- Privacy: show first name only -->
                            <h3><?= e(explode(' ',$d['name'])[0]) ?> <?= str_repeat('*', max(1, strlen(explode(' ',$d['name'])[1] ?? 'X') - 1)) ?></h3>
                            <div class="donor-meta">📍 <?= e($d['area_name']) ?>, <?= e($d['city']) ?></div>
                        </div>
                    </div>
                    <div style="display:flex;gap:var(--space-2);flex-wrap:wrap">
                        <span class="blood-badge <?= bloodGroupBadgeClass($d['blood_group']) ?>"><?= e($d['blood_group']) ?></span>
                        <span class="status-badge status-fulfilled">✅ Available</span>
                        <span class="status-badge status-found">✓ Eligible</span>
                    </div>
                    <div class="donor-meta" style="font-size:var(--text-xs)">
                        <?php if($d['days_since_donation']): ?>
                            Last donated <?= (int)$d['days_since_donation'] ?> days ago
                        <?php else: ?>
                            New donor
                        <?php endif; ?>
                    </div>
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
