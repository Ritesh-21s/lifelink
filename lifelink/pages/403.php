<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
http_response_code(403);
$pageTitle = '403 – Access Denied';
require __DIR__ . '/../includes/header.php';
?>
<section class="section" style="text-align:center">
    <div class="container">
        <div style="font-size:4rem">🚫</div>
        <h1 style="color:var(--red-600)">Access Denied</h1>
        <p class="text-muted" style="margin:var(--space-4) 0">You don't have permission to view this page.</p>
        <a href="<?= APP_URL ?>/index.php" class="btn btn-primary">← Go Home</a>
    </div>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
