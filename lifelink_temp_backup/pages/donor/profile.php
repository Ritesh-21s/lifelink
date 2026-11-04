<?php
/**
 * LifeLink – Donor Profile Edit
 */
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/csrf.php';

requireRole('donor');

$db     = getDB();
$userId = currentUserId();
$areas  = getAreas();
$errors = [];
$successMsg = '';

$stmt = $db->prepare('SELECT d.*, a.name AS area_name, u.name, u.email, u.phone FROM donors d JOIN areas a ON a.id=d.area_id JOIN users u ON u.id=d.user_id WHERE d.user_id=?');
$stmt->execute([$userId]);
$donor = $stmt->fetch();

if (!$donor) {
    redirect(APP_URL . '/pages/donor/dashboard.php', 'error', 'Profile not found.');
}

if (isPost()) {
    if (!csrfValidate()) {
        $errors[] = 'Invalid form submission.';
    } else {
        $name   = sanitizeInput($_POST['name'] ?? '');
        $phone  = sanitizeInput($_POST['phone'] ?? '');
        $areaId = (int)($_POST['area_id'] ?? 0);
        $weight = $_POST['weight'] ?? '';
        $lastDon= $_POST['last_donation_date'] ?? '';

        if (strlen($name) < 2) $errors[] = 'Name must be at least 2 characters.';
        if (!validatePhone($phone)) $errors[] = 'Invalid phone number.';
        if ($areaId < 1) $errors[] = 'Select your area.';
        if ($weight !== '' && ((float)$weight < 10 || (float)$weight > 300)) $errors[] = 'Invalid weight.';
        if ($lastDon && strtotime($lastDon) > time()) $errors[] = 'Last donation date cannot be in the future.';

        if (empty($errors)) {
            $db->prepare('UPDATE users SET name=?, phone=? WHERE id=?')->execute([$name, $phone, $userId]);
            $db->prepare('UPDATE donors SET area_id=?, last_donation_date=?, weight=? WHERE user_id=?')
               ->execute([$areaId, $lastDon ?: null, $weight ?: null, $userId]);

            // Refresh session name
            $_SESSION['user_name'] = $name;

            redirect(APP_URL . '/pages/donor/dashboard.php', 'success', 'Profile updated successfully!');
        }
    }
}

$pageTitle = 'My Profile';
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div class="container">
        <h1>My Profile</h1>
        <p>Update your donor information</p>
    </div>
</div>

<section class="section-sm">
    <div class="container" style="max-width:600px">
        <div class="card">
            <div class="card-body">
                <?php if ($errors): ?>
                    <div class="alert alert-danger mb-4">
                        <?php foreach ($errors as $e): ?><p><?= e($e) ?></p><?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <form method="POST" data-validate novalidate>
                    <?= csrfField() ?>

                    <h3 style="margin-bottom:var(--space-4);color:var(--red-700)">Account Details</h3>

                    <div class="form-row mb-4">
                        <div class="form-group">
                            <label for="name">Full Name <span class="required">*</span></label>
                            <input type="text" id="name" name="name" required value="<?= e($donor['name']) ?>">
                        </div>
                        <div class="form-group">
                            <label for="phone">Mobile Number <span class="required">*</span></label>
                            <input type="tel" id="phone" name="phone" required value="<?= e($donor['phone']) ?>">
                        </div>
                    </div>

                    <div class="form-group mb-6">
                        <label>Email (cannot change)</label>
                        <input type="email" value="<?= e($donor['email']) ?>" disabled style="background:var(--gray-100)">
                    </div>

                    <h3 style="margin-bottom:var(--space-4);color:var(--red-700)">Donor Details</h3>

                    <div class="form-row mb-4">
                        <div class="form-group">
                            <label>Blood Group (cannot change)</label>
                            <input type="text" value="<?= e($donor['blood_group']) ?>" disabled style="background:var(--gray-100)">
                        </div>
                        <div class="form-group">
                            <label>Gender (cannot change)</label>
                            <input type="text" value="<?= e(ucfirst($donor['gender'])) ?>" disabled style="background:var(--gray-100)">
                        </div>
                    </div>

                    <div class="form-row mb-4">
                        <div class="form-group">
                            <label for="area_id">Area <span class="required">*</span></label>
                            <select id="area_id" name="area_id" required>
                                <?php foreach ($areas as $a): ?>
                                <option value="<?= $a['id'] ?>" <?= $a['id'] == $donor['area_id'] ? 'selected' : '' ?>>
                                    <?= e($a['name']) ?>, <?= e($a['city']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="weight">Weight (kg)</label>
                            <input type="number" id="weight" name="weight" min="45" max="200" step="0.1"
                                   value="<?= e($donor['weight'] ?? '') ?>" placeholder="e.g. 65">
                        </div>
                    </div>

                    <div class="form-group mb-6">
                        <label for="last_donation_date">Last Donation Date</label>
                        <input type="date" id="last_donation_date" name="last_donation_date"
                               value="<?= e($donor['last_donation_date'] ?? '') ?>"
                               max="<?= date('Y-m-d') ?>">
                        <small class="text-muted">Used to calculate your next eligible donation date.</small>
                    </div>

                    <div style="display:flex;gap:var(--space-3)">
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                        <a href="<?= APP_URL ?>/pages/donor/dashboard.php" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</section>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
