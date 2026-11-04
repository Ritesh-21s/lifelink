<?php
/**
 * LifeLink – Post Emergency Request
 */
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/matching.php';
require_once __DIR__ . '/../../includes/mailer.php';

requireRole('requester');
$db     = getDB();
$userId = currentUserId();
$areas  = getAreas();
$errors = [];
$old    = [];

if (isPost()) {
    if (!csrfValidate()) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        $old = $_POST;

        // Rate limit: max 3 open requests today
        $todayCount = (int)$db->prepare(
            "SELECT COUNT(*) FROM requests WHERE requester_id=? AND DATE(created_at)=CURDATE() AND status IN ('Open','Donor Found')"
        )->execute([$userId]) ? $db->prepare(
            "SELECT COUNT(*) FROM requests WHERE requester_id=? AND DATE(created_at)=CURDATE() AND status IN ('Open','Donor Found')"
        )->execute([$userId]) && $db->query("SELECT COUNT(*) FROM requests WHERE requester_id={$userId} AND DATE(created_at)=CURDATE() AND status IN ('Open','Donor Found')")->fetchColumn() : 0;

        $stmt2 = $db->prepare("SELECT COUNT(*) FROM requests WHERE requester_id=? AND DATE(created_at)=CURDATE() AND status IN ('Open','Donor Found')");
        $stmt2->execute([$userId]);
        $todayCount = (int)$stmt2->fetchColumn();

        if ($todayCount >= MAX_OPEN_REQUESTS_PER_DAY) {
            $errors[] = 'Rate limit: You can have at most ' . MAX_OPEN_REQUESTS_PER_DAY . ' open requests per day.';
        }

        // Field validation
        $patientName  = sanitizeInput($_POST['patient_name'] ?? '');
        $bloodGroup   = $_POST['blood_group'] ?? '';
        $unitsNeeded  = (int)($_POST['units_needed'] ?? 1);
        $hospital     = sanitizeInput($_POST['hospital'] ?? '');
        $areaId       = (int)($_POST['area_id'] ?? 0);
        $urgency      = $_POST['urgency'] ?? 'Planned';
        $contactPhone = sanitizeInput($_POST['contact_phone'] ?? '');
        $neededBy     = $_POST['needed_by'] ?? '';
        $notes        = sanitizeInput($_POST['notes'] ?? '');

        if (strlen($patientName) < 2)            $errors[] = 'Patient name is required.';
        if (!validateBloodGroup($bloodGroup))     $errors[] = 'Select a valid blood group.';
        if ($unitsNeeded < 1 || $unitsNeeded > 10) $errors[] = 'Units needed must be between 1 and 10.';
        if (strlen($hospital) < 2)               $errors[] = 'Hospital name is required.';
        if ($areaId < 1)                         $errors[] = 'Select the area/location.';
        if (!in_array($urgency, ['Critical','Within 24 hours','Planned'])) $errors[] = 'Invalid urgency.';
        if (!validatePhone($contactPhone))        $errors[] = 'Enter a valid 10-digit contact number.';
        if (!$neededBy || strtotime($neededBy) < time()) $errors[] = 'Needed-by date/time must be in the future.';

        if (empty($errors)) {
            $expiresAt = date('Y-m-d H:i:s', strtotime($neededBy) + REQUEST_EXPIRY_HOURS * 3600);
            $stmt = $db->prepare(
                'INSERT INTO requests (requester_id, patient_name, blood_group, units_needed, hospital, area_id, urgency, contact_phone, needed_by, expires_at, notes)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([
                $userId, $patientName, $bloodGroup, $unitsNeeded, $hospital,
                $areaId, $urgency, $contactPhone, $neededBy, $expiresAt, $notes ?: null
            ]);
            $requestId = (int)$db->lastInsertId();

            // Fetch the new request row for matching
            $newReq = $db->prepare('SELECT * FROM requests WHERE id=?');
            $newReq->execute([$requestId]);
            $requestRow = $newReq->fetch();

            // Run matching and notify donors
            $notified = notifyMatchingDonors($requestRow);

            // For Critical requests, send email to matched donors
            if ($urgency === 'Critical') {
                $donorsList = $db->prepare(
                    "SELECT u.email, u.name, resp.donor_id, a.name AS area_name
                     FROM responses resp JOIN users u ON u.id=resp.donor_id
                     JOIN donors d ON d.user_id=resp.donor_id
                     JOIN areas a ON a.id=d.area_id
                     WHERE resp.request_id=? AND resp.status='notified'"
                );
                $donorsList->execute([$requestId]);
                foreach ($donorsList->fetchAll() as $d) {
                    mailUrgentRequest($d['email'], $d['name'], $requestRow, $d['area_name']);
                }
            }

            redirect(
                APP_URL . '/pages/requester/request_detail.php?id=' . $requestId,
                'success',
                "✅ Request posted! {$notified} compatible donor(s) notified."
            );
        }
    }
}

$pageTitle = 'Post Emergency Request';
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div class="container">
        <h1>🚨 Post Emergency Request</h1>
        <p>We'll instantly notify compatible, available donors near you</p>
    </div>
</div>

<section class="section-sm">
    <div class="container" style="max-width:640px">
        <div class="card">
            <div class="card-body">
                <?php if ($errors): ?>
                    <div class="alert alert-danger mb-6">
                        <ul style="margin:0;padding-left:var(--space-4)">
                            <?php foreach ($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="POST" data-validate novalidate>
                    <?= csrfField() ?>

                    <h3 style="margin-bottom:var(--space-4);color:var(--red-700)">Patient Information</h3>

                    <div class="form-row mb-4">
                        <div class="form-group">
                            <label for="patient_name">Patient Name <span class="required">*</span></label>
                            <input type="text" id="patient_name" name="patient_name" required
                                   value="<?= e($old['patient_name'] ?? '') ?>" placeholder="Full name of patient">
                            <span class="field-error"></span>
                        </div>
                        <div class="form-group">
                            <label for="blood_group">Blood Group Needed <span class="required">*</span></label>
                            <select id="blood_group" name="blood_group" required>
                                <option value="">Select blood group</option>
                                <?php foreach (BLOOD_GROUPS as $g): ?>
                                <option value="<?= $g ?>" <?= ($old['blood_group'] ?? '') === $g ? 'selected' : '' ?>><?= $g ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span class="field-error"></span>
                        </div>
                    </div>

                    <div class="form-row mb-4">
                        <div class="form-group">
                            <label for="units_needed">Units Needed <span class="required">*</span></label>
                            <input type="number" id="units_needed" name="units_needed" required
                                   min="1" max="10" value="<?= (int)($old['units_needed'] ?? 1) ?>">
                            <span class="field-error"></span>
                        </div>
                        <div class="form-group">
                            <label for="urgency">Urgency <span class="required">*</span></label>
                            <select id="urgency" name="urgency" required>
                                <option value="Critical"        <?= ($old['urgency'] ?? '') === 'Critical'        ? 'selected' : '' ?>>🔴 Critical</option>
                                <option value="Within 24 hours" <?= ($old['urgency'] ?? '') === 'Within 24 hours' ? 'selected' : '' ?>>🟡 Within 24 hours</option>
                                <option value="Planned"         <?= ($old['urgency'] ?? 'Planned') === 'Planned' ? 'selected' : '' ?>>🟢 Planned</option>
                            </select>
                            <span class="field-error"></span>
                        </div>
                    </div>

                    <h3 style="margin-bottom:var(--space-4);color:var(--red-700)">Location & Hospital</h3>

                    <div class="form-group mb-4">
                        <label for="hospital">Hospital Name <span class="required">*</span></label>
                        <input type="text" id="hospital" name="hospital" required
                               value="<?= e($old['hospital'] ?? '') ?>" placeholder="e.g. Apollo Hospital, Chennai">
                        <span class="field-error"></span>
                    </div>

                    <div class="form-row mb-4">
                        <div class="form-group">
                            <label for="area_id">Area / Location <span class="required">*</span></label>
                            <select id="area_id" name="area_id" required>
                                <option value="">Select area</option>
                                <?php foreach ($areas as $a): ?>
                                <option value="<?= $a['id'] ?>" <?= ($old['area_id'] ?? '') == $a['id'] ? 'selected' : '' ?>>
                                    <?= e($a['name']) ?>, <?= e($a['city']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <span class="field-error"></span>
                        </div>
                        <div class="form-group">
                            <label for="contact_phone">Contact Number <span class="required">*</span></label>
                            <input type="tel" id="contact_phone" name="contact_phone" required
                                   value="<?= e($old['contact_phone'] ?? '') ?>" placeholder="10-digit mobile">
                            <span class="field-error"></span>
                        </div>
                    </div>

                    <div class="form-group mb-4">
                        <label for="needed_by">Needed By <span class="required">*</span></label>
                        <input type="datetime-local" id="needed_by" name="needed_by" required
                               value="<?= e($old['needed_by'] ?? '') ?>">
                        <span class="field-error"></span>
                    </div>

                    <div class="form-group mb-6">
                        <label for="notes">Additional Notes</label>
                        <textarea id="notes" name="notes" placeholder="Any additional details (medical condition, ward number, etc.)"><?= e($old['notes'] ?? '') ?></textarea>
                    </div>

                    <div class="alert alert-warning mb-4">
                        ⚠️ This platform connects donors and requesters. Always consult medical professionals before donation.
                    </div>

                    <button type="submit" class="btn btn-primary btn-full btn-lg">
                        🚨 Post Emergency Request
                    </button>
                </form>
            </div>
        </div>
    </div>
</section>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
