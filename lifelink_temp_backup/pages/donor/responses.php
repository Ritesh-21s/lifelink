<?php
/**
 * LifeLink – Donor: All Responses
 */
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/csrf.php';

requireRole('donor');
$db     = getDB();
$userId = currentUserId();

$stmt = $db->prepare(
    "SELECT resp.id, resp.status AS resp_status, resp.responded_at,
            r.id AS req_id, r.patient_name, r.blood_group, r.urgency,
            r.hospital, r.units_needed, r.units_accepted, r.status AS req_status,
            r.needed_by, r.contact_phone, r.created_at,
            a.name AS area_name,
            req_user.name AS requester_name
     FROM responses resp
     JOIN requests r ON r.id=resp.request_id
     JOIN areas a ON a.id=r.area_id
     JOIN users req_user ON req_user.id=r.requester_id
     WHERE resp.donor_id=?
     ORDER BY resp.responded_at DESC, r.created_at DESC"
);
$stmt->execute([$userId]);
$responses = $stmt->fetchAll();

$pageTitle = 'My Responses';
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div class="container">
        <h1>My Responses</h1>
        <p>All blood requests you've been notified about or responded to</p>
    </div>
</div>

<section class="section-sm">
    <div class="container">
        <?php if (empty($responses)): ?>
            <div class="empty-state card">
                <div class="empty-icon">📋</div>
                <h3>No Responses Yet</h3>
                <p>When you're matched with a blood request, it will appear here.</p>
                <a href="<?= APP_URL ?>/pages/donor/dashboard.php" class="btn btn-primary">Go to Dashboard</a>
            </div>
        <?php else: ?>
            <div class="table-wrapper">
                <table>
                    <thead><tr>
                        <th>Patient</th>
                        <th>Blood Group</th>
                        <th>Hospital / Area</th>
                        <th>Urgency</th>
                        <th>My Response</th>
                        <th>Contact</th>
                        <th>Request Status</th>
                        <th>Actions</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($responses as $r): ?>
                    <tr>
                        <td>
                            <?= e($r['patient_name']) ?>
                            <div class="text-xs text-muted"><?= timeAgo($r['created_at']) ?></div>
                        </td>
                        <td><span class="blood-badge <?= bloodGroupBadgeClass($r['blood_group']) ?>"><?= e($r['blood_group']) ?></span></td>
                        <td>
                            <?= e($r['hospital']) ?>
                            <div class="text-xs text-muted">📍 <?= e($r['area_name']) ?></div>
                        </td>
                        <td><span class="status-badge <?= urgencyClass($r['urgency']) ?>"><?= e($r['urgency']) ?></span></td>
                        <td>
                            <span class="status-badge <?= $r['resp_status'] === 'accepted' ? 'status-found' : ($r['resp_status'] === 'donated' ? 'status-fulfilled' : 'status-expired') ?>">
                                <?= e(ucfirst($r['resp_status'])) ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($r['resp_status'] === 'accepted'): ?>
                                <!-- Phone revealed only for accepted responses -->
                                <a href="tel:<?= e($r['contact_phone']) ?>" style="font-weight:600;color:var(--red-600)">
                                    📞 <?= e($r['contact_phone']) ?>
                                </a>
                            <?php else: ?>
                                <span class="text-muted text-xs">🔒 Accept to reveal</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="status-badge <?= statusClass($r['req_status']) ?>"><?= e($r['req_status']) ?></span></td>
                        <td>
                            <?php if ($r['resp_status'] === 'notified' && $r['req_status'] === 'Open'): ?>
                                <button class="btn btn-success btn-sm" data-respond="accept" data-request-id="<?= (int)$r['req_id'] ?>">Accept</button>
                                <button class="btn btn-outline btn-sm" data-respond="decline" data-request-id="<?= (int)$r['req_id'] ?>">Decline</button>
                            <?php endif; ?>
                            <a href="<?= APP_URL ?>/pages/public/request_detail.php?id=<?= (int)$r['req_id'] ?>" class="btn btn-secondary btn-sm">View</a>
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
