<?php
/**
 * LifeLink – Login Page
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';

startSecureSession();

$redirects = [
    'donor'     => APP_URL . '/pages/donor/dashboard.php',
    'requester' => APP_URL . '/pages/requester/dashboard.php',
    'organizer' => APP_URL . '/pages/organizer/dashboard.php',
    'admin'     => APP_URL . '/pages/admin/dashboard.php',
];

// Already logged in – go to dashboard
if (isLoggedIn()) {
    $r = currentRole();
    header('Location: ' . ($redirects[$r] ?? APP_URL . '/index.php'));
    exit;
}

$error = '';

if (isPost()) {
    if (!csrfValidate()) {
        $error = 'Invalid form submission. Please try again.';
    } else {
        $email    = sanitizeInput($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (!$email || !$password) {
            $error = 'Please enter your email and password.';
        } elseif (!validateEmail($email)) {
            $error = 'Please enter a valid email address.';
        } else {
            $result = loginUser($email, $password);
            if ($result['success']) {
                if ($result['status'] === 'pending') {
                    redirect(APP_URL . '/pages/pending.php');
                }
                redirect(
                    $redirects[$result['role']] ?? APP_URL . '/index.php',
                    'success',
                    'Welcome back, ' . $result['name'] . '!'
                );
            } else {
                $error = $result['message'];
            }
        }
    }
}

$pageTitle = 'Login';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div class="container">
        <h1>Welcome Back</h1>
        <p>Sign in to your LifeLink account</p>
    </div>
</div>

<section class="section-sm">
    <div class="container" style="max-width:460px">
        <div class="card">
            <div class="card-body">
                <h2 style="text-align:center;margin-bottom:var(--space-6);font-size:var(--text-xl)">
                    🩸 Log In to LifeLink
                </h2>

                <?php if ($error): ?>
                <div class="alert alert-danger" role="alert">⚠️ <?= e($error) ?></div>
                <?php endif; ?>

                <form method="POST" action="" novalidate>
                    <?= csrfField() ?>

                    <div class="form-group mb-4">
                        <label for="email">Email Address <span class="required">*</span></label>
                        <input type="email" id="email" name="email" required
                               value="<?= e($_POST['email'] ?? '') ?>"
                               placeholder="you@example.com"
                               autocomplete="email">
                        <span class="field-error" role="alert"></span>
                    </div>

                    <div class="form-group mb-6">
                        <label for="password">Password <span class="required">*</span></label>
                        <div style="position:relative">
                            <input type="password" id="password" name="password" required
                                   placeholder="Your password"
                                   autocomplete="current-password">
                            <button type="button" id="togglePassword"
                                    style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--gray-400);font-size:1rem"
                                    aria-label="Toggle password visibility">👁</button>
                        </div>
                        <span class="field-error" role="alert"></span>
                    </div>

                    <button type="submit" class="btn btn-primary btn-full btn-lg">Sign In</button>
                </form>

                <div style="text-align:center;margin-top:var(--space-5);font-size:var(--text-sm);color:var(--gray-600)">
                    Don't have an account?
                    <a href="<?= APP_URL ?>/pages/register.php" style="font-weight:600;color:var(--red-600)">Register here</a>
                </div>

                <!-- Demo credentials -->
                <div class="alert alert-info mt-6" style="font-size:var(--text-xs)">
                    <strong>Demo Accounts</strong> (Password: <code>Password@123</code>)<br>
                    Admin: <code>admin@lifelink.local</code><br>
                    Donor: <code>arjun@example.com</code><br>
                    Requester: <code>ravi@example.com</code><br>
                    Organizer: <code>lifecare@example.com</code>
                </div>
            </div>
        </div>
    </div>
</section>

<script>
document.getElementById('togglePassword')?.addEventListener('click', function () {
    const inp = document.getElementById('password');
    inp.type = inp.type === 'password' ? 'text' : 'password';
    this.textContent = inp.type === 'password' ? '👁' : '🙈';
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
