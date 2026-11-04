<?php
/**
 * LifeLink – Public Request Detail
 */
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/csrf.php';

startSecureSession();
$db = getDB();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: '.APP_URL.'/index.php'); exit; }

$stmt = $db->prepare(
    'SELECT r.*, a.name AS area_name, u.name AS requester_name
     FROM requests r LEFT JOIN areas a ON a.id=r.area_id JOIN users u ON u.id=r.requester_id
     WHERE r.id=?'
);
$stmt->execute([$id]);
$req = $stmt->fetch();
if (!$req) { http_response_code(404); require __DIR__.'/../../pages/404.php'; exit; }

$pageTitle = 'Request #'.$id.' – '.$req['patient_name'];
require __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
    <div class="container">
        <h1>Blood Request #<?= $id ?></h1>
        <p>Patient: <?= e($req['patient_name']) ?></p>
    </div>
</div>
<section class="section-sm">
    <div class="container" style="max-width:700px">
        <div class="card">
            <div class="card-header">
                <div style="display:flex;gap:var(--space-3);align-items:center">
                    <?php if($req['urgency']==='Critical'): ?><span class="pulse-dot"></span><?php endif; ?>
                    <span class="blood-badge <?= bloodGroupBadgeClass($req['blood_group']) ?>"><?= e($req['blood_group']) ?></span>
                    <span class="status-badge <?= urgencyClass($req['urgency']) ?>"><?= e($req['urgency']) ?></span>
                </div>
                <span class="status-badge <?= statusClass($req['status']) ?>"><?= e($req['status']) ?></span>
            </div>
            <div class="card-body">
                <div class="grid-2 gap-4">
                    <div><label class="text-xs text-muted">Blood Group</label><p class="text-bold"><?= e($req['blood_group']) ?></p></div>
                    <div><label class="text-xs text-muted">Units Needed</label><p class="text-bold"><?= (int)$req['units_needed'] - (int)$req['units_accepted'] ?> remaining</p></div>
                    <div><label class="text-xs text-muted">Hospital</label><p><?= e($req['hospital']) ?></p></div>
                    <div><label class="text-xs text-muted">Area</label><p><?= e($req['area_name']) ?></p></div>
                    <div><label class="text-xs text-muted">Needed By</label><p><?= formatDateTime($req['needed_by']) ?></p></div>
                    <div><label class="text-xs text-muted">Posted</label><p><?= timeAgo($req['created_at']) ?></p></div>
                </div>
                <?php if($req['notes']): ?>
                    <div style="margin-top:var(--space-4);padding:var(--space-4);background:var(--gray-50);border-radius:var(--radius-md)">
                        <label class="text-xs text-muted">Notes</label>
                        <p><?= e($req['notes']) ?></p>
                    </div>
                <?php endif; ?>
            </div>
            <div class="card-footer">
                <?php if(!isLoggedIn()): ?>
                    <a href="<?= APP_URL ?>/pages/login.php" class="btn btn-primary">Login to Respond</a>
                    <a href="<?= APP_URL ?>/pages/register.php?role=donor" class="btn btn-outline">Register as Donor</a>
                <?php elseif(currentRole()==='donor' && $req['status']==='Open'): ?>
                    <p class="text-sm text-muted">Check your <a href="<?= APP_URL ?>/pages/donor/responses.php">responses page</a> to accept this request if you were matched.</p>
                <?php endif; ?>
                <div class="alert alert-warning mt-4" style="font-size:var(--text-xs)">
                    ⚠️ This platform connects donors and requesters. It does not replace hospital or blood bank screening.
                </div>
            </div>
        </div>
    </div>
</section>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
