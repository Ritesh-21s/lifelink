<?php
/**
 * LifeLink – Site Header
 * Included at the top of every page.
 * Expects $pageTitle to be set before including this file.
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';

startSecureSession();

$user     = currentUser();
$role     = $user['role'] ?? '';
$userName = $user ? e($user['name']) : '';
$pageTitle = isset($pageTitle) ? e($pageTitle) . ' – ' . APP_NAME : APP_NAME;
$bodyClass = isset($bodyClass) ? e($bodyClass) : '';

$successMsg = getFlash('success');
$errorMsg   = getFlash('error');
$infoMsg    = getFlash('info');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= isset($metaDescription) ? e($metaDescription) : 'LifeLink connects blood donors with patients in need. Join the emergency blood donation network.' ?>">
    <title><?= $pageTitle ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🩸</text></svg>">
</head>
<body class="<?= $bodyClass ?>">

<!-- Skip to main content for accessibility -->
<a href="#main-content" class="skip-link">Skip to main content</a>

<!-- ======================================================== -->
<!-- NAVBAR -->
<!-- ======================================================== -->
<header class="navbar" role="banner">
    <div class="container">
        <a href="<?= APP_URL ?>/index.php" class="brand" aria-label="LifeLink Home">
            <span class="brand-icon">🩸</span>
            <span class="brand-name">Life<span class="brand-accent">Link</span></span>
        </a>

        <button class="nav-toggle" aria-label="Toggle navigation" aria-expanded="false" id="navToggle">
            <span></span><span></span><span></span>
        </button>

        <nav class="nav-links" id="navLinks" aria-label="Main navigation">
            <a href="<?= APP_URL ?>/index.php" class="nav-link">Home</a>
            <a href="<?= APP_URL ?>/pages/public/search.php" class="nav-link">Find Donors</a>
            <a href="<?= APP_URL ?>/pages/public/calendar.php" class="nav-link">Camps</a>
            <a href="<?= APP_URL ?>/pages/public/leaderboard.php" class="nav-link">Leaderboard</a>
            <a href="<?= APP_URL ?>/pages/public/analytics.php" class="nav-link">Analytics</a>

            <?php if ($user): ?>
                <!-- Role-specific links -->
                <?php if ($role === 'donor'): ?>
                    <a href="<?= APP_URL ?>/pages/donor/dashboard.php" class="nav-link">My Dashboard</a>
                    <a href="<?= APP_URL ?>/pages/donor/responses.php" class="nav-link">My Responses</a>
                <?php elseif ($role === 'requester'): ?>
                    <a href="<?= APP_URL ?>/pages/requester/dashboard.php" class="nav-link">My Dashboard</a>
                    <a href="<?= APP_URL ?>/pages/requester/post_request.php" class="nav-link btn-nav">+ Post Request</a>
                <?php elseif ($role === 'organizer'): ?>
                    <a href="<?= APP_URL ?>/pages/organizer/dashboard.php" class="nav-link">My Dashboard</a>
                    <a href="<?= APP_URL ?>/pages/organizer/post_camp.php" class="nav-link">+ Post Camp</a>
                <?php elseif ($role === 'admin'): ?>
                    <a href="<?= APP_URL ?>/pages/admin/dashboard.php" class="nav-link">Admin Panel</a>
                <?php endif; ?>

                <div class="nav-user-menu" id="userMenuTrigger">
                    <button class="user-avatar" aria-haspopup="true" aria-expanded="false" id="userMenuBtn">
                        <?= strtoupper(substr($userName, 0, 1)) ?>
                        <span class="user-name-short"><?= $userName ?></span>
                        <svg width="12" height="12" viewBox="0 0 12 12"><path d="M2 4l4 4 4-4" stroke="currentColor" stroke-width="1.5" fill="none" stroke-linecap="round"/></svg>
                    </button>
                    <div class="user-dropdown" id="userDropdown" role="menu">
                        <div class="dropdown-header">
                            <strong><?= $userName ?></strong>
                            <span class="role-badge role-<?= $role ?>"><?= ucfirst($role) ?></span>
                        </div>
                        <?php if ($role === 'donor'): ?>
                            <a href="<?= APP_URL ?>/pages/donor/profile.php" class="dropdown-item" role="menuitem">👤 My Profile</a>
                        <?php endif; ?>
                        <a href="<?= APP_URL ?>/pages/logout.php" class="dropdown-item dropdown-danger" role="menuitem">🚪 Logout</a>
                    </div>
                </div>
            <?php else: ?>
                <a href="<?= APP_URL ?>/pages/login.php" class="nav-link">Login</a>
                <a href="<?= APP_URL ?>/pages/register.php" class="btn btn-primary btn-nav">Register</a>
            <?php endif; ?>
        </nav>
    </div>
</header>

<!-- ======================================================== -->
<!-- FLASH MESSAGES -->
<!-- ======================================================== -->
<?php if ($successMsg || $errorMsg || $infoMsg): ?>
<div class="flash-container" role="alert" aria-live="polite">
    <div class="container">
        <?php if ($successMsg): ?><div class="flash flash-success"><span>✅</span><?= e($successMsg) ?><button class="flash-close" aria-label="Close">&times;</button></div><?php endif; ?>
        <?php if ($errorMsg):   ?><div class="flash flash-error"  ><span>❌</span><?= e($errorMsg)   ?><button class="flash-close" aria-label="Close">&times;</button></div><?php endif; ?>
        <?php if ($infoMsg):    ?><div class="flash flash-info"   ><span>ℹ️</span><?= e($infoMsg)    ?><button class="flash-close" aria-label="Close">&times;</button></div><?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ======================================================== -->
<!-- MAIN -->
<!-- ======================================================== -->
<main id="main-content">
