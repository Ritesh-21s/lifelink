<?php
/**
 * LifeLink – Admin: User Management
 */
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/csrf.php';

requireAdmin();
$db = getDB();

// Handle actions
$action = sanitizeInput($_GET['action'] ?? '');
$id     = (int)($_GET['id'] ?? 0);

if ($id && in_array($action, ['approve','reject','block','unblock'])) {
    if ($action === 'approve') {
        // Approve: set status active, also verify donor if applicable
        $db->prepare("UPDATE users SET status='active' WHERE id=?")->execute([$id]);
        $db->prepare("UPDATE donors SET verified=1 WHERE user_id=?")->execute([$id]);
        $db->prepare("UPDATE organizers SET verified=1 WHERE user_id=?")->execute([$id]);
        setFlash('success', 'User approved and activated.');
    } elseif ($action === 'reject') {
        // Reject: delete the account
        $db->prepare("DELETE FROM users WHERE id=? AND role != 'admin'")->execute([$id]);
        setFlash('success', 'User rejected and removed.');
    } elseif ($action === 'block') {
        $db->prepare("UPDATE users SET status='blocked' WHERE id=? AND role!='admin'")->execute([$id]);
        setFlash('success', 'User blocked.');
    } elseif ($action === 'unblock') {
        $db->prepare("UPDATE users SET status='active' WHERE id=? AND role!='admin'")->execute([$id]);
        setFlash('success', 'User unblocked.');
    }
    header('Location: ' . APP_URL . '/pages/admin/users.php');
    exit;
}

// Filter
$filterRole   = in_array($_GET['role']??'', array_merge([''],ROLES)) ? ($_GET['role']??'') : '';
$filterStatus = in_array($_GET['status']??'', ['','pending','active','blocked']) ? ($_GET['status']??'') : '';

$sql = "SELECT u.id, u.name, u.email, u.phone, u.role, u.status, u.created_at,
               d.blood_group, d.area_id, a.name AS area_name
        FROM users u
        LEFT JOIN donors d ON d.user_id=u.id
        LEFT JOIN areas a ON a.id=d.area_id
        WHERE u.role != 'admin'";
$params = [];
if ($filterRole)   { $sql .= ' AND u.role=?';   $params[] = $filterRole; }
if ($filterStatus) { $sql .= ' AND u.status=?';  $params[] = $filterStatus; }
$sql .= ' ORDER BY u.status="pending" DESC, u.created_at DESC LIMIT 100';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

$pageTitle = 'User Management';
require __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
    <div class="container">
        <h1>👥 User Management</h1>
        <p>Approve, block, and manage all users</p>
    </div>
</div>
<section class="section-sm">
    <div class="container">
        <!-- Back to admin -->
        <a href="<?= APP_URL ?>/pages/admin/dashboard.php" class="btn btn-secondary btn-sm mb-6">← Admin Dashboard</a>

        <!-- Filters -->
        <form method="GET" style="display:flex;gap:var(--space-3);margin-bottom:var(--space-4);flex-wrap:wrap">
            <select name="role" onchange="this.form.submit()" style="min-width:140px;padding:var(--space-2) var(--space-3);border:2px solid var(--gray-200);border-radius:var(--radius-md)">
                <option value="">All Roles</option>
                <?php foreach(['donor','requester','organizer'] as $r): ?>
                <option value="<?= $r ?>" <?= $filterRole===$r?'selected':'' ?>><?= ucfirst($r) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="status" onchange="this.form.submit()" style="min-width:140px;padding:var(--space-2) var(--space-3);border:2px solid var(--gray-200);border-radius:var(--radius-md)">
                <option value="">All Status</option>
                <option value="pending"  <?= $filterStatus==='pending' ?'selected':'' ?>>Pending</option>
                <option value="active"   <?= $filterStatus==='active'  ?'selected':'' ?>>Active</option>
                <option value="blocked"  <?= $filterStatus==='blocked' ?'selected':'' ?>>Blocked</option>
            </select>
        </form>

        <div class="table-wrapper">
            <table>
                <thead><tr>
                    <th>Name</th><th>Email</th><th>Phone</th><th>Role</th><th>Blood</th><th>Area</th><th>Status</th><th>Joined</th><th>Actions</th>
                </tr></thead>
                <tbody>
                <?php foreach($users as $u): ?>
                <tr>
                    <td><strong><?= e($u['name']) ?></strong></td>
                    <td><?= e($u['email']) ?></td>
                    <td><?= e($u['phone']) ?></td>
                    <td><span class="role-badge role-<?= $u['role'] ?>"><?= ucfirst($u['role']) ?></span></td>
                    <td><?= $u['blood_group'] ? '<span class="blood-badge '.bloodGroupBadgeClass($u['blood_group']).'">'.e($u['blood_group']).'</span>' : '—' ?></td>
                    <td><?= $u['area_name'] ? e($u['area_name']) : '—' ?></td>
                    <td>
                        <span class="status-badge <?= $u['status']==='active'?'status-fulfilled':($u['status']==='pending'?'status-open':'status-cancelled') ?>">
                            <?= ucfirst($u['status']) ?>
                        </span>
                    </td>
                    <td><?= timeAgo($u['created_at']) ?></td>
                    <td style="white-space:nowrap">
                        <?php if($u['status']==='pending'): ?>
                            <a href="?action=approve&id=<?= $u['id'] ?>" class="btn btn-success btn-sm">✓ Approve</a>
                            <a href="?action=reject&id=<?= $u['id'] ?>" class="btn btn-danger btn-sm" data-confirm="Permanently delete this user?">✗ Reject</a>
                        <?php elseif($u['status']==='active'): ?>
                            <a href="?action=block&id=<?= $u['id'] ?>" class="btn btn-secondary btn-sm" data-confirm="Block this user?">⛔ Block</a>
                        <?php elseif($u['status']==='blocked'): ?>
                            <a href="?action=unblock&id=<?= $u['id'] ?>" class="btn btn-outline btn-sm">✓ Unblock</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if(empty($users)): ?><div class="empty-state"><div class="empty-icon">👥</div><h3>No users found</h3></div><?php endif; ?>
    </div>
</section>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
