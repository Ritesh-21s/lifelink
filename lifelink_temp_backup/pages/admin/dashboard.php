<?php
/**
 * LifeLink – Admin Dashboard
 */
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/csrf.php';

requireAdmin();
$db = getDB();

$totalDonors    = (int)$db->query("SELECT COUNT(*) FROM users WHERE role='donor'")->fetchColumn();
$pendingUsers   = (int)$db->query("SELECT COUNT(*) FROM users WHERE status='pending'")->fetchColumn();
$openRequests   = (int)$db->query("SELECT COUNT(*) FROM requests WHERE status='Open'")->fetchColumn();
$totalDonations = (int)$db->query("SELECT COUNT(*) FROM donations")->fetchColumn();
$pendingCamps   = (int)$db->query("SELECT COUNT(*) FROM camps WHERE status='pending'")->fetchColumn();
$revealCount    = (int)$db->query("SELECT COUNT(*) FROM contact_reveals")->fetchColumn();

// Recent pending users
$pendingList = $db->query(
    "SELECT id, name, email, role, created_at FROM users WHERE status='pending' ORDER BY created_at DESC LIMIT 10"
)->fetchAll();

// Recent requests
$recentRequests = $db->query(
    "SELECT r.id, r.patient_name, r.blood_group, r.urgency, r.status, r.created_at, a.name AS area_name
     FROM requests r JOIN areas a ON a.id=r.area_id ORDER BY r.created_at DESC LIMIT 8"
)->fetchAll();

$pageTitle = 'Admin Dashboard';
require __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
    <div class="container">
        <h1>⚙️ Admin Dashboard</h1>
        <p>LifeLink Control Panel</p>
    </div>
</div>
<section class="section-sm">
    <div class="container">
        <div style="display:grid;gap:var(--space-8);grid-template-columns:220px 1fr">

            <!-- Admin sidebar -->
            <aside>
                <div class="card admin-sidebar">
                    <nav style="padding:var(--space-3)">
                        <a href="<?= APP_URL ?>/pages/admin/dashboard.php" class="active">📊 Dashboard</a>
                        <a href="<?= APP_URL ?>/pages/admin/users.php">👥 Users <?php if($pendingUsers>0): ?><span class="status-badge" style="background:var(--red-100);color:var(--red-700);margin-left:auto"><?= $pendingUsers ?></span><?php endif; ?></a>
                        <a href="<?= APP_URL ?>/pages/admin/requests.php">🩸 Requests</a>
                        <a href="<?= APP_URL ?>/pages/admin/camps.php">🏕️ Camps <?php if($pendingCamps>0): ?><span class="status-badge" style="background:var(--red-100);color:var(--red-700);margin-left:auto"><?= $pendingCamps ?></span><?php endif; ?></a>
                        <a href="<?= APP_URL ?>/pages/admin/areas.php">📍 Areas</a>
                        <a href="<?= APP_URL ?>/pages/admin/reports.php">📈 Reports</a>
                        <a href="<?= APP_URL ?>/pages/admin/logs.php">📋 Logs</a>
                    </nav>
                </div>
            </aside>

            <!-- Main content -->
            <div>
                <!-- Stats grid -->
                <div class="grid-4 mb-8">
                    <div class="admin-stat-card">
                        <div class="admin-stat-number"><?= $totalDonors ?></div>
                        <div class="admin-stat-label">Total Donors</div>
                    </div>
                    <div class="admin-stat-card">
                        <div class="admin-stat-number" style="color:var(--gold)"><?= $pendingUsers ?></div>
                        <div class="admin-stat-label">Pending Approvals</div>
                    </div>
                    <div class="admin-stat-card">
                        <div class="admin-stat-number" style="color:var(--red-600)"><?= $openRequests ?></div>
                        <div class="admin-stat-label">Open Requests</div>
                    </div>
                    <div class="admin-stat-card">
                        <div class="admin-stat-number" style="color:var(--green)"><?= $totalDonations ?></div>
                        <div class="admin-stat-label">Confirmed Donations</div>
                    </div>
                </div>

                <!-- Pending approvals -->
                <?php if($pendingList): ?>
                <div class="card mb-6">
                    <div class="card-header">
                        <h2 style="font-size:var(--text-base)">⏳ Pending Account Approvals</h2>
                        <a href="<?= APP_URL ?>/pages/admin/users.php" class="btn btn-sm btn-outline">View All</a>
                    </div>
                    <div class="table-wrapper">
                        <table>
                            <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Registered</th><th>Action</th></tr></thead>
                            <tbody>
                            <?php foreach($pendingList as $u): ?>
                            <tr>
                                <td><?= e($u['name']) ?></td>
                                <td><?= e($u['email']) ?></td>
                                <td><span class="role-badge role-<?= $u['role'] ?>"><?= ucfirst($u['role']) ?></span></td>
                                <td><?= timeAgo($u['created_at']) ?></td>
                                <td>
                                    <a href="<?= APP_URL ?>/pages/admin/users.php?action=approve&id=<?= $u['id'] ?>" class="btn btn-success btn-sm">✓ Approve</a>
                                    <a href="<?= APP_URL ?>/pages/admin/users.php?action=reject&id=<?= $u['id'] ?>" class="btn btn-danger btn-sm" data-confirm="Reject and delete this user?">✗ Reject</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Recent requests -->
                <div class="card mb-6">
                    <div class="card-header">
                        <h2 style="font-size:var(--text-base)">🩸 Recent Requests</h2>
                        <a href="<?= APP_URL ?>/pages/admin/requests.php" class="btn btn-sm btn-outline">View All</a>
                    </div>
                    <div class="table-wrapper">
                        <table>
                            <thead><tr><th>Patient</th><th>Group</th><th>Area</th><th>Urgency</th><th>Status</th><th>Time</th></tr></thead>
                            <tbody>
                            <?php foreach($recentRequests as $r): ?>
                            <tr>
                                <td><?= e($r['patient_name']) ?></td>
                                <td><span class="blood-badge <?= bloodGroupBadgeClass($r['blood_group']) ?>"><?= e($r['blood_group']) ?></span></td>
                                <td><?= e($r['area_name']) ?></td>
                                <td><span class="status-badge <?= urgencyClass($r['urgency']) ?>"><?= e($r['urgency']) ?></span></td>
                                <td><span class="status-badge <?= statusClass($r['status']) ?>"><?= e($r['status']) ?></span></td>
                                <td><?= timeAgo($r['created_at']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Charts -->
                <div class="grid-2">
                    <div class="card">
                        <div class="card-header"><h2 style="font-size:var(--text-sm)">Donors by Blood Group</h2></div>
                        <div class="card-body"><div class="chart-container" style="height:220px"><canvas id="adminGroupChart"></canvas></div></div>
                    </div>
                    <div class="card">
                        <div class="card-header"><h2 style="font-size:var(--text-sm)">Requests by Status</h2></div>
                        <div class="card-body"><div class="chart-container" style="height:220px"><canvas id="adminStatusChart"></canvas></div></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
fetch(APP_URL+'/api/charts.php?type=donors_by_group').then(r=>r.json()).then(data=>{
    new Chart(document.getElementById('adminGroupChart'),{type:'bar',data:{labels:data.map(d=>d.blood_group),datasets:[{label:'Donors',data:data.map(d=>d.count),backgroundColor:'#c0392b',borderRadius:4}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{stepSize:1}}}}});
});
fetch(APP_URL+'/api/charts.php?type=requests_by_status').then(r=>r.json()).then(data=>{
    if(!data||!data.length) return;
    new Chart(document.getElementById('adminStatusChart'),{type:'doughnut',data:{labels:data.map(d=>d.status),datasets:[{data:data.map(d=>d.count),backgroundColor:['#f59e0b','#3b82f6','#10b981','#9ca3af','#ef4444'],borderWidth:0}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom'}}}});
}).catch(()=>{});

// Fallback: load requests_by_status from inline query
(async()=>{
    const el = document.getElementById('adminStatusChart');
    if(!el) return;
    const r = await fetch(APP_URL+'/api/stats.php?metric=all');
    const d = await r.json();
})();
</script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
