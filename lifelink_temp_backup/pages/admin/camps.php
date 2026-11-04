<?php
/**
 * LifeLink – Admin: Camp Approvals
 */
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/csrf.php';

requireAdmin();
$db = getDB();

$action = sanitizeInput($_GET['action'] ?? '');
$id     = (int)($_GET['id'] ?? 0);
if ($id && in_array($action,['approve','reject'])) {
    $db->prepare("UPDATE camps SET status=? WHERE id=?")->execute([$action==='approve'?'approved':'rejected', $id]);
    setFlash('success','Camp '.($action==='approve'?'approved':'rejected').'.');
    header('Location: '.$APP_URL.'/pages/admin/camps.php'); exit;
}

$camps = $db->query(
    "SELECT c.*, a.name AS area_name, u.name AS organizer_name,
            (SELECT COUNT(*) FROM camp_interest ci WHERE ci.camp_id=c.id) AS interested
     FROM camps c JOIN areas a ON a.id=c.area_id JOIN users u ON u.id=c.organizer_id
     ORDER BY c.status='pending' DESC, c.camp_date ASC LIMIT 100"
)->fetchAll();

$pageTitle = 'Camp Management';
require __DIR__ . '/../../includes/header.php';
?>
<div class="page-header"><div class="container"><h1>🏕️ Camp Management</h1></div></div>
<section class="section-sm">
    <div class="container">
        <a href="<?= APP_URL ?>/pages/admin/dashboard.php" class="btn btn-secondary btn-sm mb-6">← Admin Dashboard</a>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>Title</th><th>Organizer</th><th>Area</th><th>Date</th><th>Time</th><th>Contact</th><th>Interested</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach($camps as $c): ?>
                <tr>
                    <td><strong><?= e($c['title']) ?></strong><div class="text-xs text-muted"><?= e(substr($c['venue'],0,30)) ?></div></td>
                    <td><?= e($c['organizer_name']) ?></td>
                    <td><?= e($c['area_name']) ?></td>
                    <td><?= formatDate($c['camp_date']) ?></td>
                    <td><?= date('h:i A',strtotime($c['start_time'])) ?></td>
                    <td><?= e($c['contact']) ?></td>
                    <td><?= (int)$c['interested'] ?></td>
                    <td><span class="status-badge <?= $c['status']==='approved'?'status-fulfilled':($c['status']==='pending'?'status-open':'status-cancelled') ?>"><?= ucfirst($c['status']) ?></span></td>
                    <td>
                        <?php if($c['status']==='pending'): ?>
                            <a href="?action=approve&id=<?= $c['id'] ?>" class="btn btn-success btn-sm">✓ Approve</a>
                            <a href="?action=reject&id=<?= $c['id'] ?>"  class="btn btn-danger btn-sm" data-confirm="Reject this camp?">✗ Reject</a>
                        <?php elseif($c['status']==='approved'): ?>
                            <a href="?action=reject&id=<?= $c['id'] ?>"  class="btn btn-secondary btn-sm" data-confirm="Remove camp approval?">Unpublish</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
