<?php
/**
 * LifeLink – Admin: Logs (contact reveals + email log)
 */
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireAdmin();
$db  = getDB();
$tab = sanitizeInput($_GET['tab'] ?? 'reveals');

$reveals = $tab === 'reveals' ? $db->query(
    "SELECT cr.id, cr.revealed_at,
            req_u.name AS requester_name, don_u.name AS donor_name,
            r.patient_name, r.blood_group
     FROM contact_reveals cr
     JOIN users req_u ON req_u.id=cr.requester_id
     JOIN users don_u ON don_u.id=cr.donor_id
     JOIN requests r ON r.id=cr.request_id
     ORDER BY cr.revealed_at DESC LIMIT 100"
)->fetchAll() : [];

$emailLogs = $tab === 'email' ? $db->query(
    "SELECT el.id, el.email_type, el.recipient, el.status, el.sent_at, u.name AS user_name
     FROM email_log el LEFT JOIN users u ON u.id=el.user_id
     ORDER BY el.sent_at DESC LIMIT 100"
)->fetchAll() : [];

$pageTitle = 'Logs';
require __DIR__ . '/../../includes/header.php';
?>
<div class="page-header"><div class="container"><h1>📋 System Logs</h1></div></div>
<section class="section-sm">
    <div class="container">
        <a href="<?= APP_URL ?>/pages/admin/dashboard.php" class="btn btn-secondary btn-sm mb-6">← Admin Dashboard</a>
        <div style="display:flex;gap:var(--space-2);margin-bottom:var(--space-6);border-bottom:2px solid var(--gray-200)">
            <a href="?tab=reveals" class="nav-link <?= $tab==='reveals'?'active':'' ?>" style="border-bottom:3px solid <?= $tab==='reveals'?'var(--red-600)':'transparent' ?>;border-radius:0;padding-bottom:var(--space-3)">🔓 Contact Reveals</a>
            <a href="?tab=email"   class="nav-link <?= $tab==='email'?'active':'' ?>"   style="border-bottom:3px solid <?= $tab==='email'?'var(--red-600)':'transparent' ?>;border-radius:0;padding-bottom:var(--space-3)">📧 Email Log</a>
        </div>

        <?php if($tab === 'reveals'): ?>
            <div class="table-wrapper">
                <table>
                    <thead><tr><th>#</th><th>Requester</th><th>Donor</th><th>Patient</th><th>Blood</th><th>Revealed At</th></tr></thead>
                    <tbody>
                    <?php foreach($reveals as $r): ?>
                    <tr>
                        <td><?= $r['id'] ?></td>
                        <td><?= e($r['requester_name']) ?></td>
                        <td><?= e($r['donor_name']) ?></td>
                        <td><?= e($r['patient_name']) ?></td>
                        <td><span class="blood-badge <?= bloodGroupBadgeClass($r['blood_group']) ?>"><?= e($r['blood_group']) ?></span></td>
                        <td><?= formatDateTime($r['revealed_at']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($reveals)): ?><tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--gray-400)">No reveals logged yet.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="table-wrapper">
                <table>
                    <thead><tr><th>#</th><th>Type</th><th>Recipient</th><th>User</th><th>Status</th><th>Sent At</th></tr></thead>
                    <tbody>
                    <?php foreach($emailLogs as $e): ?>
                    <tr>
                        <td><?= $e['id'] ?></td>
                        <td><?= e($e['email_type']) ?></td>
                        <td><?= e($e['recipient']) ?></td>
                        <td><?= e($e['user_name']??'—') ?></td>
                        <td><span class="status-badge <?= $e['status']==='sent'?'status-fulfilled':($e['status']==='logged'?'status-open':'status-cancelled') ?>"><?= ucfirst($e['status']) ?></span></td>
                        <td><?= formatDateTime($e['sent_at']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($emailLogs)): ?><tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--gray-400)">No emails logged yet.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
