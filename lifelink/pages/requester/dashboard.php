<?php
/**
 * LifeLink – Requester Dashboard
 */
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/csrf.php';

requireRole('requester');
$db     = getDB();
$userId = currentUserId();

$requests = $db->prepare(
    "SELECT r.*, a.name AS area_name,
            (SELECT COUNT(*) FROM responses resp WHERE resp.request_id=r.id AND resp.status='accepted') AS accepted_count,
            (SELECT COUNT(*) FROM responses resp2 WHERE resp2.request_id=r.id) AS notified_count
     FROM requests r LEFT JOIN areas a ON a.id=r.area_id
     WHERE r.requester_id=?
     ORDER BY r.created_at DESC"
);
$requests->execute([$userId]);
$myRequests = $requests->fetchAll();

// Stats
$open      = array_filter($myRequests, fn($r) => $r['status'] === 'Open');
$fulfilled = array_filter($myRequests, fn($r) => $r['status'] === 'Fulfilled');

$pageTitle = 'Requester Dashboard';
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div class="container">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:var(--space-4)">
            <div>
                <h1>My Dashboard</h1>
                <p>Welcome, <?= e(currentUser()['name']) ?>!</p>
            </div>
            <a href="<?= APP_URL ?>/pages/requester/post_request.php" class="btn btn-primary" style="background:var(--white);color:var(--red-700)">
                🚨 Post Emergency Request
            </a>
        </div>
    </div>
</div>

<section class="section-sm">
    <div class="container">

        <!-- Stats -->
        <div class="grid-4 mb-8">
            <div class="admin-stat-card">
                <div class="admin-stat-number"><?= count($myRequests) ?></div>
                <div class="admin-stat-label">Total Requests</div>
            </div>
            <div class="admin-stat-card">
                <div class="admin-stat-number" style="color:var(--gold)"><?= count($open) ?></div>
                <div class="admin-stat-label">Open</div>
            </div>
            <div class="admin-stat-card">
                <div class="admin-stat-number" style="color:var(--green)"><?= count($fulfilled) ?></div>
                <div class="admin-stat-label">Fulfilled</div>
            </div>
            <div class="admin-stat-card">
                <div class="admin-stat-number"><?= count(array_filter($myRequests, fn($r) => $r['status']==='Donor Found')) ?></div>
                <div class="admin-stat-label">Donor Found</div>
            </div>
        </div>

        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--space-4)">
            <h2>My Requests</h2>
            <a href="<?= APP_URL ?>/pages/requester/post_request.php" class="btn btn-primary btn-sm">+ New Request</a>
        </div>

        <?php if (empty($myRequests)): ?>
            <div class="empty-state card">
                <div class="empty-icon">🏥</div>
                <h3>No Requests Yet</h3>
                <p>Post your first emergency blood request and we'll match compatible donors.</p>
                <a href="<?= APP_URL ?>/pages/requester/post_request.php" class="btn btn-primary">Post Request</a>
            </div>
        <?php else: ?>
            <div style="display:flex;flex-direction:column;gap:var(--space-4)">
            <?php foreach ($myRequests as $req): ?>
                <div class="request-card <?= $req['urgency'] === 'Critical' ? 'critical' : ($req['urgency'] === 'Within 24 hours' ? 'urgent' : 'planned') ?>">
                    <div class="request-header">
                        <div style="display:flex;align-items:center;gap:var(--space-3)">
                            <?php if ($req['urgency'] === 'Critical' && $req['status'] === 'Open'): ?><span class="pulse-dot"></span><?php endif; ?>
                            <span class="blood-badge <?= bloodGroupBadgeClass($req['blood_group']) ?>"><?= e($req['blood_group']) ?></span>
                            <div>
                                <strong><?= e($req['patient_name']) ?></strong>
                                <div class="text-xs text-muted"><?= timeAgo($req['created_at']) ?></div>
                            </div>
                        </div>
                        <div style="display:flex;gap:var(--space-2);align-items:center">
                            <span class="status-badge <?= urgencyClass($req['urgency']) ?>"><?= e($req['urgency']) ?></span>
                            <span class="status-badge <?= statusClass($req['status']) ?>"><?= e($req['status']) ?></span>
                        </div>
                    </div>
                    <div class="request-body">
                        <div class="request-meta">
                            <span class="request-meta-item">🏥 <?= e($req['hospital']) ?></span>
                            <span class="request-meta-item">📍 <?= e($req['city'] ?? $req['area_name'] ?? 'Location pending') ?></span>
                            <span class="request-meta-item">🩸 <?= (int)$req['units_accepted'] ?>/<?= (int)$req['units_needed'] ?> units accepted</span>
                            <span class="request-meta-item">👥 <?= (int)$req['notified_count'] ?> notified</span>
                            <span class="request-meta-item">⏰ Needed by <?= formatDateTime($req['needed_by']) ?></span>
                        </div>
                        <!-- Progress bar -->
                        <div style="margin-top:var(--space-3)">
                            <div style="display:flex;justify-content:space-between;font-size:var(--text-xs);color:var(--gray-500);margin-bottom:4px">
                                <span>Units filled</span>
                                <span><?= (int)$req['units_accepted'] ?> / <?= (int)$req['units_needed'] ?></span>
                            </div>
                            <div style="height:6px;background:var(--gray-200);border-radius:3px">
                                <div style="height:100%;background:var(--green);border-radius:3px;width:<?= $req['units_needed'] > 0 ? round(($req['units_accepted']/$req['units_needed'])*100) : 0 ?>%"></div>
                            </div>
                        </div>
                    </div>
                    <div class="request-actions">
                        <a href="<?= APP_URL ?>/pages/requester/request_detail.php?id=<?= (int)$req['id'] ?>" class="btn btn-outline btn-sm">
                            👁 View Details
                        </a>
                        <?php if ($req['status'] === 'Open' || $req['status'] === 'Donor Found'): ?>
                        <a href="<?= APP_URL ?>/pages/requester/request_detail.php?id=<?= (int)$req['id'] ?>&action=cancel"
                           class="btn btn-secondary btn-sm"
                           data-confirm="Cancel this request? This cannot be undone.">
                            ✗ Cancel
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
