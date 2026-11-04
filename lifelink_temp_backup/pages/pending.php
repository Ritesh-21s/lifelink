<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
$pageTitle = 'Account Pending Approval';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header"><div class="container"><h1>⏳ Account Pending Approval</h1></div></div>
<section class="section-sm"><div class="container" style="max-width:600px;text-align:center">
    <div class="card"><div class="card-body">
        <div style="font-size:4rem;margin-bottom:var(--space-4)">⏳</div>
        <h2>Your account is under review</h2>
        <p style="color:var(--gray-600);margin-top:var(--space-4)">
            An administrator will verify and approve your account shortly.
            You'll receive a confirmation once approved.
        </p>
        <div style="margin-top:var(--space-6)">
            <a href="<?= APP_URL ?>/pages/logout.php" class="btn btn-outline">Logout</a>
        </div>
    </div></div>
</div></section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
