<?php
/**
 * LifeLink – Registration Page (all roles)
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/mailer.php';

startSecureSession();

if (isLoggedIn()) {
    header('Location: ' . APP_URL . '/index.php');
    exit;
}

$areas  = getAreas();
$errors = [];
$step   = (int)($_GET['step'] ?? 1);
$role   = in_array($_GET['role'] ?? '', ['donor','requester','organizer']) ? ($_GET['role'] ?? 'donor') : 'donor';
$old    = [];

if (isPost()) {
    if (!csrfValidate()) {
        $errors[] = 'Invalid form submission. Please try again.';
    } else {
        $role     = in_array($_POST['role'] ?? '', ['donor','requester','organizer']) ? $_POST['role'] : 'donor';
        $name     = sanitizeInput($_POST['name'] ?? '');
        $email    = strtolower(sanitizeInput($_POST['email'] ?? ''));
        $phone    = sanitizeInput($_POST['phone'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';
        $old      = $_POST;

        // Validation
        if (strlen($name) < 2)                         $errors[] = 'Name must be at least 2 characters.';
        if (!validateEmail($email))                     $errors[] = 'Enter a valid email address.';
        if (!validatePhone($phone))                     $errors[] = 'Enter a valid 10-digit Indian mobile number.';
        if (strlen($password) < 8)                     $errors[] = 'Password must be at least 8 characters.';
        if (!preg_match('/[A-Z]/', $password))         $errors[] = 'Password must contain at least one uppercase letter.';
        if (!preg_match('/[0-9]/', $password))         $errors[] = 'Password must contain at least one number.';
        if ($password !== $confirm)                    $errors[] = 'Passwords do not match.';

        if ($role === 'donor') {
            if (!validateBloodGroup($_POST['blood_group'] ?? '')) $errors[] = 'Select a valid blood group.';
            if (!in_array($_POST['gender'] ?? '', ['male','female','other'])) $errors[] = 'Select your gender.';

            $dob = $_POST['dob'] ?? '';
            if (!$dob || !strtotime($dob)) {
                $errors[] = 'Enter a valid date of birth.';
            } else {
                $age = (int)(new DateTimeImmutable($dob))->diff(new DateTimeImmutable('today'))->y;
                if ($age < 16) $errors[] = 'You must be at least 16 to register (18 to donate).';
                if ($age > 70) $errors[] = 'Age seems invalid.';
            }
            if (empty($_POST['area_id']) || (int)$_POST['area_id'] < 1) $errors[] = 'Select your area.';

            $weight = $_POST['weight'] ?? '';
            if ($weight !== '' && ((float)$weight < 10 || (float)$weight > 300)) $errors[] = 'Weight seems invalid.';

            $lastDon = $_POST['last_donation_date'] ?? '';
            if ($lastDon && (!strtotime($lastDon) || strtotime($lastDon) > time())) {
                $errors[] = 'Last donation date cannot be in the future.';
            }
        }

        if ($role === 'organizer') {
            if (empty($_POST['org_name'])) $errors[] = 'Organization name is required.';
        }

        if (empty($errors)) {
            $result = registerUser([
                'name'               => $name,
                'email'              => $email,
                'phone'              => $phone,
                'password'           => $password,
                'role'               => $role,
                'blood_group'        => $_POST['blood_group'] ?? '',
                'gender'             => $_POST['gender'] ?? '',
                'dob'                => $_POST['dob'] ?? '',
                'area_id'            => $_POST['area_id'] ?? 0,
                'last_donation_date' => $_POST['last_donation_date'] ?? '',
                'weight'             => $_POST['weight'] ?? '',
                'org_name'           => $_POST['org_name'] ?? '',
            ]);

            if ($result['success']) {
                mailWelcome($email, $name, $result['role']);
                redirect(
                    APP_URL . '/pages/login.php',
                    'success',
                    '✅ Registration successful! Your account is pending admin approval. You can log in once approved.'
                );
            } else {
                $errors[] = $result['message'];
            }
        }
    }
}

$pageTitle = 'Register';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div class="container">
        <h1>Create Your Account</h1>
        <p>Join the LifeLink network and start saving lives</p>
    </div>
</div>

<section class="section-sm">
    <div class="container" style="max-width:600px">

        <!-- Role tabs -->
        <div style="display:flex;gap:var(--space-2);margin-bottom:var(--space-6);border-bottom:2px solid var(--gray-200);padding-bottom:0">
            <?php foreach (['donor' => '🩸 Donor', 'requester' => '🏥 Requester', 'organizer' => '🏢 Organizer'] as $r => $label): ?>
            <a href="?role=<?= $r ?>" class="nav-link <?= $role === $r ? 'active' : '' ?>"
               style="border-bottom:3px solid <?= $role === $r ? 'var(--red-600)' : 'transparent' ?>;border-radius:0;padding-bottom:var(--space-3)">
                <?= $label ?>
            </a>
            <?php endforeach; ?>
        </div>

        <div class="card">
            <div class="card-body">
                <h2 style="font-size:var(--text-xl);margin-bottom:var(--space-2)">
                    Register as <?= ucfirst($role) ?>
                </h2>
                <p style="font-size:var(--text-sm);color:var(--gray-500);margin-bottom:var(--space-6)">
                    All accounts require admin approval before activation.
                </p>

                <?php if ($errors): ?>
                    <div class="alert alert-danger mb-6" role="alert">
                        <ul style="margin:0;padding-left:var(--space-4)">
                            <?php foreach ($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="POST" action="?role=<?= $role ?>" data-validate novalidate>
                    <?= csrfField() ?>
                    <input type="hidden" name="role" value="<?= e($role) ?>">

                    <!-- Common fields -->
                    <div class="form-row mb-4">
                        <div class="form-group">
                            <label for="name">Full Name <span class="required">*</span></label>
                            <input type="text" id="name" name="name" required
                                   value="<?= e($old['name'] ?? '') ?>" placeholder="Your full name">
                            <span class="field-error"></span>
                        </div>
                        <div class="form-group">
                            <label for="phone">Mobile Number <span class="required">*</span></label>
                            <input type="tel" id="phone" name="phone" required
                                   value="<?= e($old['phone'] ?? '') ?>" placeholder="10-digit mobile">
                            <span class="field-error"></span>
                        </div>
                    </div>

                    <div class="form-group mb-4">
                        <label for="email">Email Address <span class="required">*</span></label>
                        <input type="email" id="email" name="email" required
                               value="<?= e($old['email'] ?? '') ?>" placeholder="you@example.com">
                        <span class="field-error"></span>
                    </div>

                    <div class="form-row mb-4">
                        <div class="form-group">
                            <label for="password">Password <span class="required">*</span></label>
                            <input type="password" id="password" name="password" required
                                   placeholder="Min 8 chars, 1 uppercase, 1 number">
                            <span class="field-error"></span>
                        </div>
                        <div class="form-group">
                            <label for="confirm_password">Confirm Password <span class="required">*</span></label>
                            <input type="password" id="confirm_password" name="confirm_password" required
                                   placeholder="Re-enter password">
                            <span class="field-error"></span>
                        </div>
                    </div>

                    <?php if ($role === 'donor'): ?>
                    <!-- Donor-specific fields -->
                    <hr style="margin:var(--space-6) 0;border-color:var(--gray-100)">
                    <h3 style="font-size:var(--text-base);margin-bottom:var(--space-4);color:var(--red-700)">🩸 Donor Details</h3>

                    <div class="form-row mb-4">
                        <div class="form-group">
                            <label for="blood_group">Blood Group <span class="required">*</span></label>
                            <select id="blood_group" name="blood_group" required>
                                <option value="">Select blood group</option>
                                <?php foreach (BLOOD_GROUPS as $g): ?>
                                <option value="<?= $g ?>" <?= ($old['blood_group'] ?? '') === $g ? 'selected' : '' ?>><?= $g ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span class="field-error"></span>
                        </div>
                        <div class="form-group">
                            <label for="gender">Gender <span class="required">*</span></label>
                            <select id="gender" name="gender" required>
                                <option value="">Select gender</option>
                                <option value="male"   <?= ($old['gender'] ?? '') === 'male'   ? 'selected' : '' ?>>Male</option>
                                <option value="female" <?= ($old['gender'] ?? '') === 'female' ? 'selected' : '' ?>>Female</option>
                                <option value="other"  <?= ($old['gender'] ?? '') === 'other'  ? 'selected' : '' ?>>Other</option>
                            </select>
                            <span class="field-error"></span>
                        </div>
                    </div>

                    <div class="form-row mb-4">
                        <div class="form-group">
                            <label for="dob">Date of Birth <span class="required">*</span></label>
                            <input type="date" id="dob" name="dob" required
                                   value="<?= e($old['dob'] ?? '') ?>"
                                   max="<?= date('Y-m-d', strtotime('-16 years')) ?>">
                            <span class="field-error"></span>
                        </div>
                        <div class="form-group">
                            <label for="area_id">Your Area / City <span class="required">*</span></label>
                            <select id="area_id" name="area_id" required>
                                <option value="">Select your area</option>
                                <?php foreach ($areas as $a): ?>
                                <option value="<?= $a['id'] ?>" <?= ($old['area_id'] ?? '') == $a['id'] ? 'selected' : '' ?>>
                                    <?= e($a['name']) ?>, <?= e($a['city']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <span class="field-error"></span>
                        </div>
                    </div>

                    <div class="form-row mb-4">
                        <div class="form-group">
                            <label for="last_donation_date">Last Donation Date <span style="color:var(--gray-400)">(optional)</span></label>
                            <input type="date" id="last_donation_date" name="last_donation_date"
                                   value="<?= e($old['last_donation_date'] ?? '') ?>"
                                   max="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="form-group">
                            <label for="weight">Weight (kg) <span style="color:var(--gray-400)">(optional)</span></label>
                            <input type="number" id="weight" name="weight" min="45" max="200" step="0.1"
                                   value="<?= e($old['weight'] ?? '') ?>" placeholder="e.g. 65">
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($role === 'organizer'): ?>
                    <div class="form-group mb-4">
                        <label for="org_name">Organization Name <span class="required">*</span></label>
                        <input type="text" id="org_name" name="org_name" required
                               value="<?= e($old['org_name'] ?? '') ?>" placeholder="NGO / College / Hospital name">
                        <span class="field-error"></span>
                    </div>
                    <?php endif; ?>

                    <button type="submit" class="btn btn-primary btn-full btn-lg" style="margin-top:var(--space-4)">
                        Create Account
                    </button>
                </form>

                <div style="text-align:center;margin-top:var(--space-5);font-size:var(--text-sm);color:var(--gray-600)">
                    Already have an account? <a href="<?= APP_URL ?>/pages/login.php" style="font-weight:600">Login here</a>
                </div>
            </div>
        </div>
    </div>
</section>

<script>
// Password strength check
const passInput = document.getElementById('password');
const confInput = document.getElementById('confirm_password');
if (passInput) {
    passInput.addEventListener('input', function() {
        const v = this.value;
        let ok = v.length >= 8 && /[A-Z]/.test(v) && /[0-9]/.test(v);
        this.style.borderColor = v.length === 0 ? '' : (ok ? 'var(--green)' : 'var(--red-500)');
    });
}
if (confInput) {
    confInput.addEventListener('input', function() {
        const match = this.value === passInput?.value;
        this.style.borderColor = this.value.length === 0 ? '' : (match ? 'var(--green)' : 'var(--red-500)');
    });
}
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
