<?php
/**
 * LifeLink – Admin: Request Management
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

if ($id && in_array($action, ['close','flag','reopen'])) {
    $map = ['close' => 'Cancelled', 'flag' => 'Cancelled', 'reopen' => 'Open'];
    $db->prepare("UPDATE requests SET status=? WHERE id=?")->execute([$map[$action], $id]);
    setFlash('success', 'Request updated.');
    header('Location: ' . APP_URL . '/pages/admin/requests.php');
    exit;
}

$filterStatus = sanitizeInput($_GET['status'] ?? '');
$sql = "SELECT r.*, a.name AS area_name, u.name AS requester_name,
               (SELECT COUNT(*) FROM responses resp WHERE resp.request_id=r.id AND resp.status='accepted') AS accepted_count
        FROM requests r JOIN areas a ON a.id=r.area_id JOIN users u ON u.id=r.requester_id WHERE 1=1";
$params = [];
if ($filterStatus) { $sql .= ' AND r.status=?'; $params[] = $filterStatus; }
$sql .= ' ORDER BY r.urgency="Critical" DESC, r.created_at DESC LIMIT 100';
$stmt = $db->prepare($sql); $stmt->execute($params);
$requests = $stmt->fetchAll();

$pageTitle = 'Request Management';
require __DIR__ . '/../../includes/header.php';
?>
<div class="page-header"><div class="container"><h1>🩸 Request Management</h1><p>View and manage all emergency blood requests</p></div></div>
<section class="section-sm">
    <div class="container">
        <a href="<?= APP_URL ?>/pages/admin/dashboard.php" class="btn btn-secondary btn-sm mb-6">← Admin Dashboard</a>
        <form method="GET" style="display:flex;gap:var(--space-3);margin-bottom:var(--space-4)">
            <select name="status" onchange="this.form.submit()" style="padding:var(--space-2) var(--space-3);border:2px solid var(--gray-200);border-radius:var(--radius-md)">
                <option value="">All Status</option>
                <?php foreach(['Open','Donor Found','Fulfilled','Expired','Cancelled'] as $s): ?>
                <option value="<?= $s ?>" <?= $filterStatus===$s?'selected':'' ?>><?= $s ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>Patient</th><th>Group</th><th>Hospital</th><th>Area</th><th>Urgency</th><th>Units</th><th>Status</th><th>Requester</th><th>Posted</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach($requests as $r): ?>
                <tr>
                    <td><strong><?= e($r['patient_name']) ?></strong></td>
                    <td><span class="blood-badge <?= bloodGroupBadgeClass($r['blood_group']) ?>"><?= e($r['blood_group']) ?></span></td>
                    <td><?= e(substr($r['hospital'],0,20)) ?></td>
                    <td><?= e($r['area_name']) ?></td>
                    <td><span class="status-badge <?= urgencyClass($r['urgency']) ?>"><?= e($r['urgency']) ?></span></td>
                    <td><?= (int)$r['units_accepted'] ?>/<?= (int)$r['units_needed'] ?></td>
                    <td><span class="status-badge <?= statusClass($r['status']) ?>"><?= e($r['status']) ?></span></td>
                    <td><?= e($r['requester_name']) ?></td>
                    <td><?= timeAgo($r['created_at']) ?></td>
                    <td style="white-space:nowrap">
                        <?php if(in_array($r['status'],['Open','Donor Found'])): ?>
                            <a href="?action=close&id=<?= $r['id'] ?>" class="btn btn-secondary btn-sm" data-confirm="Close this request?">Close</a>
                            <a href="?action=flag&id=<?= $r['id'] ?>" class="btn btn-danger btn-sm" data-confirm="Flag as fake/abusive and cancel?">Flag</a>
                        <?php endif; ?>
                        <a href="<?= APP_URL ?>/pages/public/request_detail.php?id=<?= $r['id'] ?>" class="btn btn-outline btn-sm">View</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if(empty($requests)): ?><div class="empty-state"><div class="empty-icon">🩸</div><h3>No requests</h3></div><?php endif; ?>
    </div>
</section>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
