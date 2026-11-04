<?php
/**
 * LifeLink – Analytics Dashboard (public)
 * Data rendered server-side — no AJAX needed, charts load instantly.
 */
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

startSecureSession();
$db = getDB();

// All data fetched server-side
$donorsByGroup = $db->query(
    "SELECT d.blood_group, COUNT(*) AS count
     FROM donors d JOIN users u ON u.id=d.user_id
     WHERE u.status='active' AND d.is_available=1 AND d.verified=1
     GROUP BY d.blood_group ORDER BY d.blood_group"
)->fetchAll();

$requestsByUrgency = $db->query(
    "SELECT urgency, COUNT(*) AS count FROM requests GROUP BY urgency"
)->fetchAll();

$monthlyTrend = $db->query(
    "SELECT DATE_FORMAT(created_at,'%b %Y') AS month,
            DATE_FORMAT(created_at,'%Y-%m') AS sort_key,
            COUNT(*) AS requests,
            SUM(CASE WHEN status='Fulfilled' THEN 1 ELSE 0 END) AS fulfilled
     FROM requests
     WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
     GROUP BY sort_key, month ORDER BY sort_key"
)->fetchAll();

$threshold = (int)getSetting('shortage_threshold', SHORTAGE_THRESHOLD);
$shortages = $db->query(
    "SELECT a.name AS area_name, d.blood_group, COUNT(*) AS count
     FROM donors d JOIN users u ON u.id=d.user_id JOIN areas a ON a.id=d.area_id
     WHERE u.status='active' AND d.is_available=1 AND d.verified=1
     GROUP BY d.area_id, d.blood_group
     HAVING count < {$threshold}
     ORDER BY count ASC, a.name"
)->fetchAll();

// Pre-encode for JS
$jsGroups    = json_encode(array_column($donorsByGroup,    'blood_group'));
$jsCounts    = json_encode(array_map('intval', array_column($donorsByGroup,    'count')));
$jsUrgLabels = json_encode(array_column($requestsByUrgency,'urgency'));
$jsUrgCounts = json_encode(array_map('intval', array_column($requestsByUrgency,'count')));
$jsMonths    = json_encode(array_column($monthlyTrend,     'month'));
$jsRequests  = json_encode(array_map('intval', array_column($monthlyTrend,     'requests')));
$jsFulfilled = json_encode(array_map('intval', array_column($monthlyTrend,     'fulfilled')));

$pageTitle       = 'Analytics Dashboard';
$metaDescription = 'LifeLink blood donation analytics: donor availability, request urgency, and monthly trends.';
require __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
    <div class="container">
        <h1>📊 Analytics Dashboard</h1>
        <p>Real-time blood donation network statistics</p>
    </div>
</div>
<section class="section-sm">
    <div class="container">

        <div class="grid-2 mb-8">
            <!-- Donors by blood group -->
            <div class="card">
                <div class="card-header"><h2 style="font-size:var(--text-base)">Available Donors by Blood Group</h2></div>
                <div class="card-body">
                    <?php if (empty($donorsByGroup)): ?>
                        <p class="text-muted text-center" style="padding:var(--space-8) 0">No donor data yet.</p>
                    <?php else: ?>
                        <div class="chart-container"><canvas id="donorsByGroupChart"></canvas></div>
                    <?php endif; ?>
                </div>
            </div>
            <!-- Requests by urgency -->
            <div class="card">
                <div class="card-header"><h2 style="font-size:var(--text-base)">Requests by Urgency</h2></div>
                <div class="card-body">
                    <?php if (empty($requestsByUrgency)): ?>
                        <p class="text-muted text-center" style="padding:var(--space-8) 0">No request data yet.</p>
                    <?php else: ?>
                        <div class="chart-container"><canvas id="requestsByUrgencyChart"></canvas></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Monthly trend -->
        <div class="card mb-8">
            <div class="card-header"><h2 style="font-size:var(--text-base)">Monthly Requests vs Fulfilled (Last 6 Months)</h2></div>
            <div class="card-body">
                <?php if (empty($monthlyTrend)): ?>
                    <p class="text-muted text-center" style="padding:var(--space-8) 0">No trend data yet.</p>
                <?php else: ?>
                    <div class="chart-container" style="height:300px"><canvas id="monthlyTrendChart"></canvas></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Shortage alerts -->
        <div class="card">
            <div class="card-header"><h2 style="font-size:var(--text-base)">⚠️ Area Shortage Alerts</h2></div>
            <div class="card-body">
                <?php if (empty($shortages)): ?>
                    <p style="color:var(--green)">✅ No shortage flags at this time. All areas have sufficient donors.</p>
                <?php else: ?>
                    <div class="table-wrapper">
                        <table>
                            <thead><tr><th>Area</th><th>Blood Group</th><th>Available Donors</th><th>Status</th></tr></thead>
                            <tbody>
                            <?php foreach ($shortages as $s): ?>
                            <tr>
                                <td><?= e($s['area_name']) ?></td>
                                <td><span class="blood-badge <?= bloodGroupBadgeClass($s['blood_group']) ?>"><?= e($s['blood_group']) ?></span></td>
                                <td><?= (int)$s['count'] ?></td>
                                <td><span class="shortage-flag">⚠️ Shortage</span></td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>
</section>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const REDS  = ['#e74c3c','#c0392b','#a93226','#922b21','#7b241c','#641e16','#f1948a','#fadbd8'];
const GREEN = '#10b981';
const RED   = '#c0392b';

<?php if (!empty($donorsByGroup)): ?>
new Chart(document.getElementById('donorsByGroupChart'), {
    type: 'bar',
    data: {
        labels: <?= $jsGroups ?>,
        datasets: [{
            label: 'Eligible Donors',
            data: <?= $jsCounts ?>,
            backgroundColor: REDS,
            borderRadius: 6
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
    }
});
<?php endif; ?>

<?php if (!empty($requestsByUrgency)): ?>
new Chart(document.getElementById('requestsByUrgencyChart'), {
    type: 'doughnut',
    data: {
        labels: <?= $jsUrgLabels ?>,
        datasets: [{ data: <?= $jsUrgCounts ?>, backgroundColor: ['#e74c3c','#f59e0b','#10b981'], borderWidth: 0 }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom' } }
    }
});
<?php endif; ?>

<?php if (!empty($monthlyTrend)): ?>
new Chart(document.getElementById('monthlyTrendChart'), {
    type: 'line',
    data: {
        labels: <?= $jsMonths ?>,
        datasets: [
            { label: 'Requests', data: <?= $jsRequests ?>, borderColor: RED,   backgroundColor: 'rgba(192,57,43,0.1)', fill: true, tension: 0.4 },
            { label: 'Fulfilled', data: <?= $jsFulfilled ?>, borderColor: GREEN, backgroundColor: 'rgba(16,185,129,0.1)', fill: true, tension: 0.4 }
        ]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { position: 'top' } },
        scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
    }
});
<?php endif; ?>
</script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
