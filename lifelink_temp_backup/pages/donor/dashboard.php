<?php
/**
 * LifeLink – Donor Dashboard
 */
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/eligibility.php';
require_once __DIR__ . '/../../includes/csrf.php';

requireRole('donor');

$db     = getDB();
$userId = currentUserId();
$user   = currentUser();

// Fetch donor profile
$stmt = $db->prepare(
    'SELECT d.*, a.name AS area_name, u.email, u.phone
     FROM donors d JOIN areas a ON a.id=d.area_id JOIN users u ON u.id=d.user_id
     WHERE d.user_id=?'
);
$stmt->execute([$userId]);
$donor = $stmt->fetch();

if (!$donor) redirect(APP_URL . '/pages/donor/profile.php', 'error', 'Please complete your donor profile.');

$eligibility = checkEligibility($donor, ['status' => $user['status']]);

// Stats
$totalDonations = countDonorDonations($userId);
$livesHelped    = $totalDonations * LIVES_MULTIPLIER;
$badge          = donorBadge($totalDonations);

// Pending responses (notified but not yet responded)
$pending = $db->prepare(
    "SELECT r.id, r.patient_name, r.blood_group, r.urgency, r.hospital,
            r.units_needed, r.units_accepted, r.needed_by, r.created_at,
            a.name AS area_name, resp.status AS resp_status
     FROM responses resp
     JOIN requests r ON r.id=resp.request_id
     JOIN areas a ON a.id=r.area_id
     WHERE resp.donor_id=? AND resp.status='notified' AND r.status='Open'
     ORDER BY r.urgency='Critical' DESC, r.needed_by ASC
     LIMIT 10"
);
$pending->execute([$userId]);
$pendingRequests = $pending->fetchAll();

// Recent accepted
$accepted = $db->prepare(
    "SELECT r.id, r.patient_name, r.blood_group, r.urgency, r.hospital,
            r.status AS req_status, resp.status AS resp_status, resp.responded_at,
            a.name AS area_name
     FROM responses resp
     JOIN requests r ON r.id=resp.request_id
     JOIN areas a ON a.id=r.area_id
     WHERE resp.donor_id=? AND resp.status IN ('accepted','donated')
     ORDER BY resp.responded_at DESC LIMIT 5"
);
$accepted->execute([$userId]);
$acceptedRequests = $accepted->fetchAll();

// Recent donations
$donations = $db->prepare(
    "SELECT don.*, r.patient_name, r.hospital, r.blood_group
     FROM donations don LEFT JOIN requests r ON r.id=don.request_id
     WHERE don.donor_id=? ORDER BY don.confirmed_at DESC LIMIT 5"
);
$donations->execute([$userId]);
$donationHistory = $donations->fetchAll();

$pageTitle = 'Donor Dashboard';
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div class="container">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:var(--space-4)">
            <div>
                <h1>My Dashboard</h1>
                <p>Welcome back, <?= e($user['name']) ?>! 👋</p>
            </div>
            <a href="<?= APP_URL ?>/pages/donor/profile.php" class="btn btn-outline" style="border-color:rgba(255,255,255,0.5);color:var(--white)">
                ✏️ Edit Profile
            </a>
        </div>
    </div>
</div>

<!-- Live Ticker -->
<div class="ticker-bar">
    <div class="container">
        <div class="ticker-inner">
            <span class="ticker-label">🔴 URGENT</span>
            <div class="ticker-scroll"><div class="ticker-items" id="tickerItems">Loading...</div></div>
        </div>
    </div>
</div>

<section class="section-sm">
    <div class="container">
        <div class="dashboard-layout">

            <!-- SIDEBAR -->
            <aside class="sidebar">
                <!-- Profile card -->
                <div class="sidebar-card">
                    <div style="background:linear-gradient(135deg,var(--red-700),var(--red-500));padding:var(--space-5);text-align:center;color:var(--white)">
                        <div style="width:64px;height:64px;border-radius:50%;background:rgba(255,255,255,0.2);display:flex;align-items:center;justify-content:center;font-size:2rem;margin:0 auto var(--space-3)">
                            <?= strtoupper(substr($user['name'], 0, 1)) ?>
                        </div>
                        <strong><?= e($user['name']) ?></strong>
                        <div style="margin-top:var(--space-2)">
                            <span class="blood-badge <?= bloodGroupBadgeClass($donor['blood_group']) ?>"><?= e($donor['blood_group']) ?></span>
                        </div>
                        <div style="font-size:var(--text-xs);opacity:0.8;margin-top:var(--space-2)"><?= e($donor['area_name']) ?></div>
                    </div>
                    <div style="padding:var(--space-4)">
                        <!-- Availability toggle -->
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--space-4)">
                            <span style="font-size:var(--text-sm);font-weight:600">Availability</span>
                            <label class="toggle-switch" aria-label="Toggle availability">
                                <div class="toggle-track <?= $donor['is_available'] ? 'on' : '' ?>" id="availabilityTrack">
                                    <div class="toggle-thumb"></div>
                                </div>
                                <input type="checkbox" id="availabilityToggle" <?= $donor['is_available'] ? 'checked' : '' ?> hidden>
                            </label>
                        </div>
                        <div style="font-size:var(--text-xs);color:var(--gray-500)" id="availabilityLabel">
                            <?= $donor['is_available'] ? '✅ Available to donate' : '⛔ Not available' ?>
                        </div>
                    </div>
                </div>

                <!-- Eligibility card -->
                <div class="eligibility-card <?= $eligibility['eligible'] ? 'eligible' : 'ineligible' ?>">
                    <div class="eligibility-icon"><?= $eligibility['eligible'] ? '✅' : '⏳' ?></div>
                    <div>
                        <div class="eligibility-label">
                            <?= $eligibility['eligible'] ? 'Eligible Now' : 'Not Yet Eligible' ?>
                        </div>
                        <div style="font-size:var(--text-sm);margin-top:4px"><?= e($eligibility['reason']) ?></div>
                        <?php if (!$eligibility['eligible'] && $eligibility['eligible_on']): ?>
                            <div style="font-size:var(--text-xs);margin-top:4px;font-weight:600">
                                Eligible on: <?= formatDate($eligibility['eligible_on']) ?>
                                (<?= $eligibility['days_until'] ?> days)
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Quick stats -->
                <div class="sidebar-card">
                    <div style="padding:var(--space-4)">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-3)">
                            <div style="text-align:center;padding:var(--space-3);background:var(--red-50);border-radius:var(--radius-md)">
                                <div style="font-size:var(--text-2xl);font-weight:800;color:var(--red-600)"><?= $totalDonations ?></div>
                                <div style="font-size:var(--text-xs);color:var(--gray-500)">Donations</div>
                            </div>
                            <div style="text-align:center;padding:var(--space-3);background:#d1fae5;border-radius:var(--radius-md)">
                                <div style="font-size:var(--text-2xl);font-weight:800;color:var(--green)"><?= $livesHelped ?></div>
                                <div style="font-size:var(--text-xs);color:var(--gray-500)">Lives Helped</div>
                            </div>
                        </div>
                        <?php if ($badge): ?>
                        <div style="text-align:center;margin-top:var(--space-3);font-size:var(--text-sm);font-weight:600">
                            <?= $badge ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Nav -->
                <div class="sidebar-card sidebar-nav">
                    <a href="<?= APP_URL ?>/pages/donor/dashboard.php" class="active">📊 Dashboard</a>
                    <a href="<?= APP_URL ?>/pages/donor/profile.php">👤 My Profile</a>
                    <a href="<?= APP_URL ?>/pages/donor/responses.php">📋 All Responses</a>
                    <a href="<?= APP_URL ?>/pages/public/leaderboard.php">🏆 Leaderboard</a>
                </div>
            </aside>

            <!-- MAIN CONTENT -->
            <div>

                <!-- Pending requests (need response) -->
                <div class="card mb-6">
                    <div class="card-header">
                        <h2 style="font-size:var(--text-lg)">
                            🔔 Requests Waiting for Your Response
                            <?php if ($pendingRequests): ?>
                                <span class="status-badge" style="background:var(--red-100);color:var(--red-700)"><?= count($pendingRequests) ?></span>
                            <?php endif; ?>
                        </h2>
                    </div>
                    <?php if (empty($pendingRequests)): ?>
                        <div class="empty-state">
                            <div class="empty-icon">💤</div>
                            <h3>No Pending Requests</h3>
                            <p>You'll be notified here when a matching request is posted.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($pendingRequests as $req): ?>
                        <div class="request-card <?= urgencyClass($req['urgency']) ?>" style="border-radius:0;border-left:none;border-right:none;border-top:none">
                            <div class="request-header">
                                <div style="display:flex;align-items:center;gap:var(--space-3)">
                                    <?php if ($req['urgency'] === 'Critical'): ?><span class="pulse-dot"></span><?php endif; ?>
                                    <span class="blood-badge <?= bloodGroupBadgeClass($req['blood_group']) ?>"><?= e($req['blood_group']) ?></span>
                                    <strong><?= e($req['patient_name']) ?></strong>
                                </div>
                                <span class="status-badge <?= urgencyClass($req['urgency']) ?>"><?= e($req['urgency']) ?></span>
                            </div>
                            <div class="request-body">
                                <div class="request-meta">
                                    <span class="request-meta-item">🏥 <?= e($req['hospital']) ?></span>
                                    <span class="request-meta-item">📍 <?= e($req['area_name']) ?></span>
                                    <span class="request-meta-item">🩸 <?= (int)$req['units_needed'] - (int)$req['units_accepted'] ?> unit(s) still needed</span>
                                    <span class="request-meta-item">⏰ Needed by <?= formatDateTime($req['needed_by']) ?></span>
                                    <span class="request-meta-item text-muted">Posted <?= timeAgo($req['created_at']) ?></span>
                                </div>
                            </div>
                            <div class="request-actions">
                                <button class="btn btn-success btn-sm" data-respond="accept" data-request-id="<?= (int)$req['id'] ?>">
                                    ✅ Accept
                                </button>
                                <button class="btn btn-outline btn-sm" data-respond="decline" data-request-id="<?= (int)$req['id'] ?>">
                                    ❌ Decline
                                </button>
                                <a href="<?= APP_URL ?>/pages/public/request_detail.php?id=<?= (int)$req['id'] ?>" class="btn btn-secondary btn-sm">
                                    View Details
                                </a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Accepted requests -->
                <?php if ($acceptedRequests): ?>
                <div class="card mb-6">
                    <div class="card-header">
                        <h2 style="font-size:var(--text-lg)">✅ My Accepted Requests</h2>
                    </div>
                    <div class="table-wrapper">
                        <table>
                            <thead><tr>
                                <th>Patient</th><th>Blood</th><th>Hospital</th><th>Urgency</th><th>Response</th><th>Status</th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($acceptedRequests as $r): ?>
                            <tr>
                                <td><?= e($r['patient_name']) ?></td>
                                <td><span class="blood-badge <?= bloodGroupBadgeClass($r['blood_group']) ?>"><?= e($r['blood_group']) ?></span></td>
                                <td><?= e($r['hospital']) ?></td>
                                <td><span class="status-badge <?= urgencyClass($r['urgency']) ?>"><?= e($r['urgency']) ?></span></td>
                                <td><?= e(ucfirst($r['resp_status'])) ?></td>
                                <td><span class="status-badge <?= statusClass($r['req_status']) ?>"><?= e($r['req_status']) ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Donation history -->
                <div class="card">
                    <div class="card-header">
                        <h2 style="font-size:var(--text-lg)">💉 Donation History</h2>
                    </div>
                    <?php if (empty($donationHistory)): ?>
                        <div class="empty-state" style="padding:var(--space-8)">
                            <div class="empty-icon">💉</div>
                            <h3>No Donations Yet</h3>
                            <p>Accept a request to make your first donation!</p>
                        </div>
                    <?php else: ?>
                        <div class="table-wrapper">
                            <table>
                                <thead><tr><th>Date</th><th>Patient</th><th>Hospital</th><th>Blood</th><th>Units</th></tr></thead>
                                <tbody>
                                <?php foreach ($donationHistory as $d): ?>
                                <tr>
                                    <td><?= formatDate($d['confirmed_at']) ?></td>
                                    <td><?= e($d['patient_name'] ?? 'Walk-in donation') ?></td>
                                    <td><?= e($d['hospital'] ?? '—') ?></td>
                                    <td><span class="blood-badge <?= bloodGroupBadgeClass($d['blood_group'] ?? $donor['blood_group']) ?>"><?= e($d['blood_group'] ?? $donor['blood_group']) ?></span></td>
                                    <td><?= (int)$d['units'] ?></td>
                                </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>
</section>

<script>
// Sync availability toggle track with label
document.getElementById('availabilityToggle')?.addEventListener('change', function() {
    const track = document.getElementById('availabilityTrack');
    const label = document.getElementById('availabilityLabel');
    track.classList.toggle('on', this.checked);
    label.textContent = this.checked ? '✅ Available to donate' : '⛔ Not available';
    // actual save is handled by app.js postJSON
});
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
