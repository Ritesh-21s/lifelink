<?php
/**
 * LifeLink – Top Donors Leaderboard
 */
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

startSecureSession();
$db = getDB();

// Top 20 donors by donation count (show first name + area only for privacy)
$topDonors = $db->query(
    "SELECT u.name, d.blood_group, a.name AS area_name,
            COUNT(don.id) AS donation_count,
            COUNT(don.id) * " . LIVES_MULTIPLIER . " AS lives_helped,
            MAX(don.confirmed_at) AS last_donated
     FROM donations don
     JOIN users u ON u.id=don.donor_id
     JOIN donors d ON d.user_id=don.donor_id
     LEFT JOIN areas a ON a.id=d.area_id
     WHERE u.status='active'
     GROUP BY don.donor_id
     ORDER BY donation_count DESC, last_donated DESC
     LIMIT 20"
)->fetchAll();

// City-wise totals
$cityTotals = $db->query(
    "SELECT a.city, COUNT(don.id) AS total_donations, COUNT(don.id)*".LIVES_MULTIPLIER." AS lives_helped
     FROM donations don JOIN users u ON u.id=don.donor_id JOIN donors d ON d.user_id=don.donor_id LEFT JOIN areas a ON a.id=d.area_id
     WHERE u.status='active'
     GROUP BY a.city ORDER BY total_donations DESC"
)->fetchAll();

$globalTotal = (int)$db->query('SELECT COUNT(*) FROM donations')->fetchColumn();
$globalLives = $globalTotal * LIVES_MULTIPLIER;

$pageTitle       = 'Donor Leaderboard';
$metaDescription = 'Top blood donors on LifeLink. See who has saved the most lives.';
require __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
    <div class="container">
        <h1>🏆 Donor Leaderboard</h1>
        <p>Celebrating our life-saving heroes</p>
    </div>
</div>
<section class="section-sm">
    <div class="container">
        <!-- Global stats -->
        <div class="grid-3 mb-8">
            <div class="admin-stat-card" style="text-align:center">
                <div class="admin-stat-number" data-count="<?= $globalTotal ?>"><?= $globalTotal ?></div>
                <div class="admin-stat-label">Total Donations</div>
            </div>
            <div class="admin-stat-card" style="text-align:center">
                <div class="admin-stat-number" style="color:var(--green)" data-count="<?= $globalLives ?>"><?= $globalLives ?></div>
                <div class="admin-stat-label">Lives Helped Globally</div>
            </div>
            <div class="admin-stat-card" style="text-align:center">
                <div class="admin-stat-number" data-count="<?= count($topDonors) ?>"><?= count($topDonors) ?></div>
                <div class="admin-stat-label">Active Donors</div>
            </div>
        </div>

        <div style="display:grid;gap:var(--space-8);grid-template-columns:1fr;" >
            <!-- Top donors -->
            <div class="card">
                <div class="card-header"><h2 style="font-size:var(--text-lg)">🏆 Top Donors</h2></div>
                <?php if(empty($topDonors)): ?>
                    <div class="empty-state"><div class="empty-icon">🏆</div><h3>No donations yet</h3></div>
                <?php else: ?>
                    <?php foreach($topDonors as $i => $donor): ?>
                    <div class="leaderboard-item">
                        <div class="leaderboard-rank rank-<?= $i+1 ?>">
                            <?php if($i===0): ?>🥇<?php elseif($i===1): ?>🥈<?php elseif($i===2): ?>🥉<?php else: ?><?= $i+1 ?><?php endif; ?>
                        </div>
                        <div class="donor-avatar" style="width:40px;height:40px;font-size:1rem;flex-shrink:0">
                            <?= strtoupper(substr($donor['name'],0,1)) ?>
                        </div>
                        <div style="flex:1">
                            <!-- Privacy: first name + area only -->
                            <strong><?= e(explode(' ',$donor['name'])[0]) ?> <?= isset(explode(' ',$donor['name'])[1]) ? substr(explode(' ',$donor['name'])[1],0,1).'.' : '' ?></strong>
                            <div class="text-xs text-muted">📍 <?= e($donor['area_name']) ?></div>
                        </div>
                        <div style="text-align:center">
                            <span class="blood-badge <?= bloodGroupBadgeClass($donor['blood_group']) ?>"><?= e($donor['blood_group']) ?></span>
                        </div>
                        <div style="text-align:right">
                            <div style="font-weight:800;font-size:var(--text-xl);color:var(--red-600)"><?= (int)$donor['donation_count'] ?></div>
                            <div class="text-xs text-muted">donations</div>
                        </div>
                        <div style="text-align:right">
                            <div style="font-weight:700;color:var(--green)"><?= (int)$donor['lives_helped'] ?></div>
                            <div class="text-xs text-muted">lives</div>
                        </div>
                        <div><?= donorBadge((int)$donor['donation_count']) ?? '' ?></div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- City totals -->
            <?php if($cityTotals): ?>
            <div class="card">
                <div class="card-header"><h2 style="font-size:var(--text-lg)">🏙️ City-wise Impact</h2></div>
                <div class="table-wrapper">
                    <table>
                        <thead><tr><th>City</th><th>Donations</th><th>Lives Helped</th></tr></thead>
                        <tbody>
                        <?php foreach($cityTotals as $c): ?>
                        <tr>
                            <td style="font-weight:600"><?= e($c['city']) ?></td>
                            <td><?= (int)$c['total_donations'] ?></td>
                            <td style="color:var(--green);font-weight:700"><?= (int)$c['lives_helped'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="alert alert-info mt-6">
            🔒 <strong>Privacy:</strong> Only first name and area are displayed. Full identity is never revealed publicly.
        </div>
    </div>
</section>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
