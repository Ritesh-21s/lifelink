<?php
/**
 * LifeLink – Organizer Dashboard
 */
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/csrf.php';

requireRole('organizer');
$db     = getDB();
$userId = currentUserId();

$camps = $db->prepare(
    "SELECT c.*, a.name AS area_name,
            (SELECT COUNT(*) FROM camp_interest ci WHERE ci.camp_id=c.id) AS interested
     FROM camps c LEFT JOIN areas a ON a.id=c.area_id
     WHERE c.organizer_id=?
     ORDER BY c.camp_date DESC"
);
$camps->execute([$userId]);
$myCamps = $camps->fetchAll();

$pageTitle = 'Organizer Dashboard';
require __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
    <div class="container">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:var(--space-4)">
            <div><h1>Organizer Dashboard</h1><p>Welcome, <?= e(currentUser()['name']) ?>!</p></div>
            <a href="<?= APP_URL ?>/pages/organizer/post_camp.php" class="btn btn-primary" style="background:var(--white);color:var(--red-700)">
                + Post Donation Camp
            </a>
        </div>
    </div>
</div>
<section class="section-sm">
    <div class="container">
        <h2 style="margin-bottom:var(--space-6)">My Donation Camps</h2>
        <?php if (empty($myCamps)): ?>
            <div class="empty-state card">
                <div class="empty-icon">🏕️</div>
                <h3>No Camps Yet</h3>
                <p>Post your first donation camp to engage donors in your area.</p>
                <a href="<?= APP_URL ?>/pages/organizer/post_camp.php" class="btn btn-primary">Post a Camp</a>
            </div>
        <?php else: ?>
            <div class="table-wrapper">
                <table>
                    <thead><tr><th>Title</th><th>Date</th><th>Area</th><th>Time</th><th>Interested</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($myCamps as $c): ?>
                    <tr>
                        <td><strong><?= e($c['title']) ?></strong><div class="text-xs text-muted"><?= e($c['venue']) ?></div></td>
                        <td><?= formatDate($c['camp_date']) ?></td>
                        <td><?= e($c['area_name']) ?></td>
                        <td><?= date('h:i A', strtotime($c['start_time'])) ?> – <?= date('h:i A', strtotime($c['end_time'])) ?></td>
                        <td><span style="font-weight:700;color:var(--red-600)"><?= (int)$c['interested'] ?></span> people</td>
                        <td><span class="status-badge <?= $c['status']==='approved'?'status-fulfilled':($c['status']==='pending'?'status-open':'status-cancelled') ?>">
                            <?= ucfirst($c['status']) ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
