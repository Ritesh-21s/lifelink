<?php
/**
 * LifeLink – Email Verification Page
 * Validates the token from the verification email and activates the account.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/mailer.php';

startSecureSession();

$token  = trim($_GET['token'] ?? '');
$status = 'error'; // 'success' | 'expired' | 'already' | 'error'
$msg    = '';

if (!$token || strlen($token) !== 64) {
    $status = 'error';
    $msg    = 'Invalid verification link.';
} else {
    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT id, name, email, role, status, email_token_expires
         FROM users WHERE email_token = ? LIMIT 1'
    );
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if (!$user) {
        $status = 'error';
        $msg    = 'This verification link is invalid or has already been used.';
    } elseif ($user['status'] === 'active' || $user['status'] === 'pending') {
        $status = 'already';
        $msg    = 'Your email is already verified. You can log in now.';
    } elseif (strtotime($user['email_token_expires']) < time()) {
        $status = 'expired';
        $msg    = 'This verification link has expired (links are valid for 24 hours).';
    } else {
        // Activate: set status to 'pending' (admin still needs to approve)
        $db->prepare(
            "UPDATE users SET status='pending', email_token=NULL, email_token_expires=NULL WHERE id=?"
        )->execute([$user['id']]);

        // Send the welcome email
        mailWelcome($user['email'], $user['name'], $user['role']);

        $status = 'success';
        $msg    = 'Your email has been verified successfully!';
    }
}

// Handle resend request
$resendDone = false;
if (isset($_GET['resend']) && isset($_GET['email'])) {
    $reEmail = strtolower(trim($_GET['email']));
    $db2     = getDB();
    $s2      = $db2->prepare('SELECT id, name, email_token FROM users WHERE email=? AND status=?');
    $s2->execute([$reEmail, 'unverified']);
    $u2 = $s2->fetch();
    if ($u2 && $u2['email_token']) {
        mailVerification($reEmail, '', $u2['email_token']);
        $resendDone = true;
    }
}

$pageTitle = 'Email Verification';
require __DIR__ . '/../includes/header.php';
?>

<style>
.verify-box {
    max-width: 500px;
    margin: 60px auto;
    text-align: center;
    padding: 0 var(--space-4);
}
.verify-icon { font-size: 4rem; margin-bottom: var(--space-4); }
.verify-box h1 { font-size: var(--text-2xl); margin-bottom: var(--space-3); }
.verify-box p  { color: var(--gray-600); margin-bottom: var(--space-6); line-height: 1.7; }
.verify-card {
    background: var(--white);
    border-radius: var(--radius-xl);
    padding: var(--space-8);
    box-shadow: var(--shadow-lg);
    border: 1px solid var(--gray-100);
}
</style>

<section class="section-sm">
    <div class="verify-box">

        <?php if ($status === 'success'): ?>
        <div class="verify-card">
            <div class="verify-icon">✅</div>
            <h1 style="color:var(--green)">Email Verified!</h1>
            <p>
                Your email has been verified successfully.<br>
                Your account is now <strong>pending admin approval</strong>.<br>
                You will be able to log in once an admin reviews your account.
            </p>
            <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:var(--radius-md);padding:var(--space-4);margin-bottom:var(--space-6);font-size:var(--text-sm);color:#166534">
                📬 We've sent you a welcome email with next steps.
            </div>
            <a href="<?= APP_URL ?>/pages/login.php" class="btn btn-primary btn-full">Go to Login</a>
        </div>

        <?php elseif ($status === 'already'): ?>
        <div class="verify-card">
            <div class="verify-icon">👍</div>
            <h1 style="color:var(--blue-600)">Already Verified</h1>
            <p><?= e($msg) ?></p>
            <a href="<?= APP_URL ?>/pages/login.php" class="btn btn-primary btn-full">Go to Login</a>
        </div>

        <?php elseif ($status === 'expired'): ?>
        <div class="verify-card">
            <div class="verify-icon">⏰</div>
            <h1 style="color:var(--orange)">Link Expired</h1>
            <p>Your verification link has expired. Links are valid for 24 hours.<br>
               Enter your email below to get a new verification link.</p>

            <?php if ($resendDone): ?>
            <div class="alert alert-success mb-4">✅ A new verification email has been sent! Check your inbox.</div>
            <?php else: ?>
            <form method="GET" action="verify.php" style="margin-bottom:var(--space-4)">
                <input type="hidden" name="resend" value="1">
                <div class="form-group mb-3">
                    <input type="email" name="email" required placeholder="your@email.com"
                           style="text-align:center">
                </div>
                <button type="submit" class="btn btn-primary btn-full">📧 Resend Verification Email</button>
            </form>
            <?php endif; ?>
            <a href="<?= APP_URL ?>/pages/login.php" style="font-size:var(--text-sm);color:var(--gray-500)">Back to Login</a>
        </div>

        <?php else: ?>
        <div class="verify-card">
            <div class="verify-icon">❌</div>
            <h1 style="color:var(--red-600)">Invalid Link</h1>
            <p><?= e($msg) ?></p>
            <a href="<?= APP_URL ?>/pages/register.php" class="btn btn-primary btn-full">Register Again</a>
        </div>
        <?php endif; ?>

    </div>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
