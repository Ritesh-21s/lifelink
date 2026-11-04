<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/csrf.php';
http_response_code(404);
$pageTitle = '404 – Page Not Found';
require __DIR__ . '/includes/header.php';
?>
<section class="section" style="text-align:center">
    <div class="container">
        <div style="font-size:6rem">🩸</div>
        <h1 style="font-size:var(--text-5xl);color:var(--red-600)">404</h1>
        <h2>Page Not Found</h2>
        <p class="text-muted" style="margin:var(--space-4) 0">The page you're looking for doesn't exist or has been moved.</p>
        <a href="<?= APP_URL ?>/index.php" class="btn btn-primary">← Go Home</a>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
