<?php
/**
 * LifeLink – Admin: Reports with CSV export
 */
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/csrf.php';

requireAdmin();
$db = getDB();

$report = sanitizeInput($_GET['report'] ?? 'donors');
$export = ($_GET['export'] ?? '') === 'csv';

// Run the selected report
switch ($report) {
    case 'donors':
        $sql = "SELECT u.name, u.email, u.phone, u.status, d.blood_group, d.gender,
                       d.dob, a.name AS area, d.last_donation_date, d.is_available,
                       COUNT(don.id) AS donations
                FROM users u JOIN donors d ON d.user_id=u.id
                LEFT JOIN areas a ON a.id=d.area_id
                LEFT JOIN donations don ON don.donor_id=u.id
                WHERE u.role='donor'
                GROUP BY u.id ORDER BY u.name";
        $headers = ['Name','Email','Phone','Status','Blood Group','Gender','DOB','Area','Last Donation','Available','Donations'];
        break;
    case 'requests':
        $sql = "SELECT r.id, r.patient_name, r.blood_group, r.units_needed, r.units_accepted,
                       r.hospital, a.name AS area, r.urgency, r.status, r.created_at, r.needed_by
                FROM requests r JOIN areas a ON a.id=r.area_id ORDER BY r.created_at DESC";
        $headers = ['ID','Patient','Blood Group','Units Needed','Units Accepted','Hospital','Area','Urgency','Status','Created','Needed By'];
        break;
    case 'donations':
        $sql = "SELECT don.id, u.name AS donor, d.blood_group, r.patient_name, r.hospital,
                       don.units, don.confirmed_at
                FROM donations don
                JOIN users u ON u.id=don.donor_id
                JOIN donors d ON d.user_id=don.donor_id
                LEFT JOIN requests r ON r.id=don.request_id
                ORDER BY don.confirmed_at DESC";
        $headers = ['ID','Donor','Blood Group','Patient','Hospital','Units','Confirmed At'];
        break;
    default:
        $sql = ''; $headers = [];
}

$rows = $sql ? $db->query($sql)->fetchAll() : [];

// CSV export
if ($export && $rows) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="lifelink_' . $report . '_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, $headers);
    foreach ($rows as $row) fputcsv($out, array_values($row));
    fclose($out);
    exit;
}

$pageTitle = 'Reports';
require __DIR__ . '/../../includes/header.php';
?>
<div class="page-header"><div class="container"><h1>📈 Reports</h1></div></div>
<section class="section-sm">
    <div class="container">
        <a href="<?= APP_URL ?>/pages/admin/dashboard.php" class="btn btn-secondary btn-sm mb-6">← Admin Dashboard</a>

        <!-- Report tabs -->
        <div style="display:flex;gap:var(--space-2);margin-bottom:var(--space-6);border-bottom:2px solid var(--gray-200);padding-bottom:0">
            <?php foreach(['donors'=>'🩸 Donors','requests'=>'📋 Requests','donations'=>'💉 Donations'] as $r=>$label): ?>
            <a href="?report=<?= $r ?>" class="nav-link <?= $report===$r?'active':'' ?>"
               style="border-bottom:3px solid <?= $report===$r?'var(--red-600)':'transparent' ?>;border-radius:0;padding-bottom:var(--space-3)">
                <?= $label ?>
            </a>
            <?php endforeach; ?>
        </div>

        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:var(--space-4)">
            <h2 style="font-size:var(--text-lg)"><?= ucfirst($report) ?> Report (<?= count($rows) ?> records)</h2>
            <a href="?report=<?= $report ?>&export=csv" class="btn btn-outline btn-sm">⬇ Export CSV</a>
        </div>

        <?php if(empty($rows)): ?>
            <div class="empty-state card"><div class="empty-icon">📊</div><h3>No data</h3></div>
        <?php else: ?>
            <div class="table-wrapper">
                <table>
                    <thead><tr><?php foreach($headers as $h): ?><th><?= e($h) ?></th><?php endforeach; ?></tr></thead>
                    <tbody>
                    <?php foreach(array_slice($rows,0,100) as $row): ?>
                    <tr><?php foreach(array_values($row) as $cell): ?><td><?= e((string)($cell??'')) ?></td><?php endforeach; ?></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if(count($rows)>100): ?><p class="text-sm text-muted mt-4">Showing first 100 rows. Export CSV for all <?= count($rows) ?> records.</p><?php endif; ?>
        <?php endif; ?>
    </div>
</section>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
