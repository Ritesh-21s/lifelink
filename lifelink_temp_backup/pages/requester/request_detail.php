<?php
/**
 * LifeLink – Request Detail (Requester view)
 * Shows matched donors (masked), responses, and allows cancellation/fulfillment.
 */
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/mailer.php';

requireRole('requester');
$db     = getDB();
$userId = currentUserId();

$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(APP_URL . '/pages/requester/dashboard.php', 'error', 'Invalid request.');

$stmt = $db->prepare(
    'SELECT r.*, a.name AS area_name FROM requests r JOIN areas a ON a.id=r.area_id WHERE r.id=? AND r.requester_id=?'
);
$stmt->execute([$id, $userId]);
$req = $stmt->fetch();
if (!$req) redirect(APP_URL . '/pages/requester/dashboard.php', 'error', 'Request not found or access denied.');

// Handle actions
$action = sanitizeInput($_GET['action'] ?? '');
if ($action === 'cancel' && in_array($req['status'], ['Open','Donor Found'])) {
    if (isGet()) {
        // Show confirm (handled by data-confirm in link, so direct GET cancel if confirmed)
        $db->prepare("UPDATE requests SET status='Cancelled' WHERE id=?")->execute([$id]);
        redirect(APP_URL . '/pages/requester/dashboard.php', 'success', 'Request cancelled.');
    }
}

// Confirm donation POST
if (isPost() && csrfValidate()) {
    $donorId = (int)($_POST['donor_id'] ?? 0);
    $respId  = (int)($_POST['response_id'] ?? 0);
    if ($donorId && $respId) {
        // Mark response as donated
        $db->prepare("UPDATE responses SET status='donated' WHERE id=? AND donor_id=? AND request_id=?")->execute([$respId, $donorId, $id]);
        // Create donation record
        $db->prepare("INSERT INTO donations (request_id, donor_id, requester_id, units) VALUES (?,?,?,1)")->execute([$id, $donorId, $userId]);
        // Update donor's last_donation_date, set unavailable
        $db->prepare("UPDATE donors SET last_donation_date=CURDATE(), is_available=0, reminder_sent_for=NULL WHERE user_id=?")->execute([$donorId]);

        // Check if all units fulfilled
        $stmt2 = $db->prepare("SELECT COUNT(*) FROM responses WHERE request_id=? AND status='donated'");
        $stmt2->execute([$id]);
        $donatedCount = (int)$stmt2->fetchColumn();
        if ($donatedCount >= $req['units_needed']) {
            $db->prepare("UPDATE requests SET status='Fulfilled' WHERE id=?")->execute([$id]);
        } else {
            $db->prepare("UPDATE requests SET units_accepted=? WHERE id=?")->execute([$donatedCount, $id]);
        }

        // Thank-you email to donor
        $donorUser = $db->prepare("SELECT u.email, u.name FROM users u WHERE u.id=?")->execute([$donorId]);
        $du = $db->prepare("SELECT u.email, u.name FROM users u WHERE u.id=?");
        $du->execute([$donorId]);
        $donorUser = $du->fetch();
        if ($donorUser) {
            mailThankYou($donorUser['email'], $donorUser['name'], $req['patient_name'], $req['hospital']);
        }

        redirect(APP_URL . '/pages/requester/request_detail.php?id=' . $id, 'success', 'Donation confirmed! Thank you.');
    }
}

// Re-fetch updated request
$stmt->execute([$id, $userId]);
$req = $stmt->fetch();

// Get responses
$responses = $db->prepare(
    "SELECT resp.id AS resp_id, resp.status, resp.responded_at,
            u.id AS user_id, u.name, u.phone,
            d.blood_group, d.area_id,
            a.name AS area_name,
            DATEDIFF(CURDATE(), d.last_donation_date) AS days_since_donation
     FROM responses resp
     JOIN users u ON u.id=resp.donor_id
     JOIN donors d ON d.user_id=resp.donor_id
     JOIN areas a ON a.id=d.area_id
     WHERE resp.request_id=?
     ORDER BY FIELD(resp.status,'accepted','donated','notified','declined'), resp.responded_at"
);
$responses->execute([$id]);
$donorResponses = $responses->fetchAll();

$pageTitle = 'Request Details';
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div class="container">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:var(--space-4)">
            <div>
                <h1>Request #<?= $id ?></h1>
                <p>Patient: <?= e($req['patient_name']) ?></p>
            </div>
            <a href="<?= APP_URL ?>/pages/requester/dashboard.php" class="btn btn-outline" style="border-color:rgba(255,255,255,0.5);color:var(--white)">← Back</a>
        </div>
    </div>
</div>

<section class="section-sm">
    <div class="container">
        <div style="display:grid;gap:var(--space-6);grid-template-columns:1fr;" >

            <!-- Request details card -->
            <div class="card">
                <div class="card-header">
                    <h2 style="font-size:var(--text-lg)">Request Details</h2>
                    <div style="display:flex;gap:var(--space-2)">
                        <span class="status-badge <?= urgencyClass($req['urgency']) ?>"><?= e($req['urgency']) ?></span>
                        <span class="status-badge <?= statusClass($req['status']) ?>"><?= e($req['status']) ?></span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="grid-2 gap-4">
                        <div><label class="text-xs text-muted">Patient</label><p style="font-weight:600"><?= e($req['patient_name']) ?></p></div>
                        <div><label class="text-xs text-muted">Blood Group</label><p><span class="blood-badge <?= bloodGroupBadgeClass($req['blood_group']) ?>"><?= e($req['blood_group']) ?></span></p></div>
                        <div><label class="text-xs text-muted">Units</label><p><?= (int)$req['units_accepted'] ?> / <?= (int)$req['units_needed'] ?> filled</p></div>
                        <div><label class="text-xs text-muted">Hospital</label><p><?= e($req['hospital']) ?></p></div>
                        <div><label class="text-xs text-muted">Area</label><p><?= e($req['area_name']) ?></p></div>
                        <div><label class="text-xs text-muted">Contact</label><p><?= e($req['contact_phone']) ?></p></div>
                        <div><label class="text-xs text-muted">Needed By</label><p><?= formatDateTime($req['needed_by']) ?></p></div>
                        <div><label class="text-xs text-muted">Posted</label><p><?= formatDateTime($req['created_at']) ?></p></div>
                    </div>
                    <?php if ($req['notes']): ?>
                        <div style="margin-top:var(--space-4);padding:var(--space-4);background:var(--gray-50);border-radius:var(--radius-md)">
                            <label class="text-xs text-muted">Notes</label>
                            <p><?= e($req['notes']) ?></p>
                        </div>
                    <?php endif; ?>

                    <!-- Progress bar -->
                    <div style="margin-top:var(--space-5)">
                        <div style="display:flex;justify-content:space-between;font-size:var(--text-sm);margin-bottom:var(--space-2)">
                            <span>Units Filled</span>
                            <strong><?= (int)$req['units_accepted'] ?> / <?= (int)$req['units_needed'] ?></strong>
                        </div>
                        <div style="height:10px;background:var(--gray-200);border-radius:5px">
                            <div style="height:100%;background:var(--green);border-radius:5px;width:<?= $req['units_needed'] > 0 ? round(($req['units_accepted']/$req['units_needed'])*100) : 0 ?>%;transition:width 0.5s"></div>
                        </div>
                    </div>
                </div>
                <?php if (in_array($req['status'], ['Open','Donor Found'])): ?>
                <div class="card-footer">
                    <a href="?id=<?= $id ?>&action=cancel"
                       class="btn btn-danger btn-sm"
                       data-confirm="Cancel this request? All donors will be un-notified.">
                        ✗ Cancel Request
                    </a>
                </div>
                <?php endif; ?>
            </div>

            <!-- Donor responses -->
            <div class="card">
                <div class="card-header">
                    <h2 style="font-size:var(--text-lg)">Matched Donors (<?= count($donorResponses) ?>)</h2>
                </div>
                <?php if (empty($donorResponses)): ?>
                    <div class="empty-state" style="padding:var(--space-8)">
                        <div class="empty-icon">👤</div>
                        <h3>No Donors Notified Yet</h3>
                        <p>We're searching for compatible donors. Check back soon.</p>
                    </div>
                <?php else: ?>
                    <div class="table-wrapper">
                        <table>
                            <thead><tr>
                                <th>Donor</th>
                                <th>Blood Group</th>
                                <th>Area</th>
                                <th>Last Donated</th>
                                <th>Response</th>
                                <th>Contact</th>
                                <th>Action</th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($donorResponses as $dr): ?>
                            <tr>
                                <td>
                                    <div style="font-weight:600">
                                        <?= $dr['status'] === 'accepted' ? e($dr['name']) : e(explode(' ', $dr['name'])[0]) . ' ***' ?>
                                    </div>
                                </td>
                                <td><span class="blood-badge <?= bloodGroupBadgeClass($dr['blood_group']) ?>"><?= e($dr['blood_group']) ?></span></td>
                                <td><?= e($dr['area_name']) ?></td>
                                <td><?= $dr['days_since_donation'] ? $dr['days_since_donation'] . ' days ago' : 'Never' ?></td>
                                <td>
                                    <span class="status-badge <?= $dr['status'] === 'accepted' ? 'status-found' : ($dr['status'] === 'donated' ? 'status-fulfilled' : 'status-expired') ?>">
                                        <?= e(ucfirst($dr['status'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($dr['status'] === 'accepted'): ?>
                                        <!-- Phone revealed; log it -->
                                        <?php
                                        // Log reveal (idempotent - only log once per pair)
                                        $chk = $db->prepare("SELECT id FROM contact_reveals WHERE request_id=? AND donor_id=? AND requester_id=?");
                                        $chk->execute([$id, $dr['user_id'], $userId]);
                                        if (!$chk->fetch()) {
                                            $db->prepare("INSERT INTO contact_reveals (request_id,donor_id,requester_id) VALUES (?,?,?)")->execute([$id, $dr['user_id'], $userId]);
                                        }
                                        ?>
                                        <a href="tel:<?= e($dr['phone']) ?>" style="color:var(--red-600);font-weight:600">
                                            📞 <?= e($dr['phone']) ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted text-xs">🔒 Hidden</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($dr['status'] === 'accepted' && in_array($req['status'], ['Open','Donor Found'])): ?>
                                        <form method="POST" style="display:inline">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="donor_id" value="<?= (int)$dr['user_id'] ?>">
                                            <input type="hidden" name="response_id" value="<?= (int)$dr['resp_id'] ?>">
                                            <button type="submit" class="btn btn-success btn-sm"
                                                    data-confirm="Confirm that <?= e(explode(' ', $dr['name'])[0]) ?> donated blood?">
                                                ✅ Confirm Donation
                                            </button>
                                        </form>
                                    <?php elseif ($dr['status'] === 'donated'): ?>
                                        <span style="color:var(--green);font-weight:600">✅ Donated</span>
                                    <?php endif; ?>
                                </td>
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

<?php require __DIR__ . '/../../includes/footer.php'; ?>
