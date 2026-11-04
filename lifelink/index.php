<?php
/**
 * LifeLink – Home Page
 */
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/csrf.php';

startSecureSession();

$pageTitle       = 'LifeLink – Blood Donor & Emergency Request Network';
$metaDescription = 'LifeLink connects blood donors with patients in need. Find donors, post emergency requests, and join donation camps near you.';

// Fetch stats for home page
$db = getDB();
$totalDonors  = (int)$db->query("SELECT COUNT(*) FROM users WHERE role='donor' AND status='active'")->fetchColumn();
$totalRequests= (int)$db->query("SELECT COUNT(*) FROM requests")->fetchColumn();
$totalFulfilled=(int)$db->query("SELECT COUNT(*) FROM donations")->fetchColumn();
$livesHelped  = $totalFulfilled * LIVES_MULTIPLIER;

// Upcoming camps
$upcomingCamps = $db->query(
    "SELECT c.*, a.name AS area_name, u.name AS organizer_name,
            (SELECT COUNT(*) FROM camp_interest ci WHERE ci.camp_id=c.id) AS interested
     FROM camps c JOIN areas a ON a.id=c.area_id JOIN users u ON u.id=c.organizer_id
     WHERE c.status='approved' AND c.camp_date >= CURDATE()
     ORDER BY c.camp_date LIMIT 3"
)->fetchAll();

require __DIR__ . '/includes/header.php';
?>

<!-- ================================================================ -->
<!-- LIVE TICKER -->
<!-- ================================================================ -->
<div class="ticker-bar" aria-label="Live urgent requests">
    <div class="container">
        <div class="ticker-inner">
            <span class="ticker-label">🔴 URGENT</span>
            <div class="ticker-scroll" aria-live="polite">
                <div class="ticker-items" id="tickerItems">
                    <span class="ticker-item">Loading urgent requests...</span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ================================================================ -->
<!-- HERO -->
<!-- ================================================================ -->
<section class="hero" aria-labelledby="heroHeading">
    <div class="container">
        <div class="hero-content">
            <span class="hero-eyebrow">🩸 India's Emergency Blood Network</span>
            <h1 id="heroHeading">Give Blood.<br>Save Lives.</h1>
            <p class="hero-sub">
                LifeLink connects blood donors with patients in critical need.
                Every donation saves up to <strong>3 lives</strong>. Be someone's hero today.
            </p>
            <div class="hero-actions">
                <a href="<?= APP_URL ?>/pages/register.php?role=donor" class="btn-hero-primary">🩸 Become a Donor</a>
                <a href="<?= APP_URL ?>/pages/requester/post_request.php" class="btn-hero-outline">🚨 Post Emergency Request</a>
                <a href="<?= APP_URL ?>/pages/public/search.php" class="btn-hero-outline">🔍 Find Donors</a>
            </div>
        </div>
    </div>
</section>

<!-- ================================================================ -->
<!-- STATS BAR -->
<!-- ================================================================ -->
<div class="stats-bar" aria-label="Platform statistics">
    <div class="container">
        <div class="stats-grid">
            <div class="stat-item">
                <span class="stat-number" data-count="<?= $totalDonors ?>" id="statDonors">0</span>
                <span class="stat-label">Registered Donors</span>
            </div>
            <div class="stat-item">
                <span class="stat-number" data-count="<?= $totalRequests ?>">0</span>
                <span class="stat-label">Blood Requests</span>
            </div>
            <div class="stat-item">
                <span class="stat-number" data-count="<?= $totalFulfilled ?>">0</span>
                <span class="stat-label">Donations Made</span>
            </div>
            <div class="stat-item">
                <span class="stat-number" data-count="<?= $livesHelped ?>">0</span>
                <span class="stat-label">Lives Helped</span>
            </div>
        </div>
    </div>
</div>

<!-- ================================================================ -->
<!-- HOW IT WORKS -->
<!-- ================================================================ -->
<section class="section" aria-labelledby="howItWorksHeading">
    <div class="container">
        <h2 class="section-title" id="howItWorksHeading">How LifeLink Works</h2>
        <p class="section-sub">From registration to donation in four simple steps</p>
        <div class="steps-grid">
            <div class="step-card fade-in">
                <div class="step-icon">📋</div>
                <div class="step-number">1</div>
                <h3>Register</h3>
                <p>Sign up as a donor, requester, or organizer. Your data stays private and secure.</p>
            </div>
            <div class="step-card fade-in">
                <div class="step-icon">🔍</div>
                <div class="step-number">2</div>
                <h3>Match</h3>
                <p>Our engine finds compatible, nearby donors based on blood group, location, and eligibility.</p>
            </div>
            <div class="step-card fade-in">
                <div class="step-icon">📞</div>
                <div class="step-number">3</div>
                <h3>Connect</h3>
                <p>Donors accept requests. Contact details are shared only upon acceptance — privacy first.</p>
            </div>
            <div class="step-card fade-in">
                <div class="step-icon">❤️</div>
                <div class="step-number">4</div>
                <h3>Save Lives</h3>
                <p>Requester confirms the donation. Lives helped counter updates and donor earns badges.</p>
            </div>
        </div>
    </div>
</section>

<!-- ================================================================ -->
<!-- BLOOD GROUPS QUICK SEARCH -->
<!-- ================================================================ -->
<section class="section-sm" style="background:var(--red-50);" aria-labelledby="groupsHeading">
    <div class="container">
        <h2 class="section-title" id="groupsHeading" style="font-size:var(--text-2xl);margin-bottom:var(--space-6)">Find Donors by Blood Group</h2>
        <div style="display:flex;flex-wrap:wrap;gap:var(--space-3);justify-content:center">
            <?php foreach (BLOOD_GROUPS as $group): ?>
            <a href="<?= APP_URL ?>/pages/public/search.php?blood_group=<?= urlencode($group) ?>"
               class="blood-badge <?= bloodGroupBadgeClass($group) ?>"
               style="font-size:1.1rem;padding:10px 20px;border-radius:var(--radius-md)"
               aria-label="Search <?= e($group) ?> donors">
                <?= e($group) ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ================================================================ -->
<!-- UPCOMING DONATION CAMPS -->
<!-- ================================================================ -->
<?php if ($upcomingCamps): ?>
<section class="section" aria-labelledby="campsHeading">
    <div class="container">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:var(--space-8)">
            <div>
                <h2 id="campsHeading">Upcoming Donation Camps</h2>
                <p class="text-muted">Join a camp near you and donate collectively</p>
            </div>
            <a href="<?= APP_URL ?>/pages/public/calendar.php" class="btn btn-outline">View All</a>
        </div>
        <div class="grid-3">
            <?php foreach ($upcomingCamps as $camp): ?>
            <div class="camp-card card-hover">
                <div class="camp-card-header">
                    <div class="camp-date-badge">
                        <span class="camp-date-month"><?= date('M', strtotime($camp['camp_date'])) ?></span>
                        <span class="camp-date-day"><?= date('d', strtotime($camp['camp_date'])) ?></span>
                    </div>
                    <h3 style="color:var(--white);font-size:var(--text-base)"><?= e($camp['title']) ?></h3>
                </div>
                <div class="camp-card-body">
                    <p style="font-size:var(--text-sm);color:var(--gray-600);margin-bottom:var(--space-3)">
                        📍 <?= e($camp['venue']) ?>, <?= e($camp['area_name']) ?>
                    </p>
                    <p style="font-size:var(--text-sm);color:var(--gray-500)">
                        🕐 <?= date('h:i A', strtotime($camp['start_time'])) ?> – <?= date('h:i A', strtotime($camp['end_time'])) ?>
                    </p>
                    <p style="font-size:var(--text-sm);color:var(--gray-500);margin-top:var(--space-2)">
                        👥 <?= (int)$camp['interested'] ?> interested
                    </p>
                    <div style="margin-top:var(--space-4)">
                        <a href="<?= APP_URL ?>/pages/public/calendar.php" class="btn btn-outline btn-sm">Learn More</a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ================================================================ -->
<!-- CALL TO ACTION -->
<!-- ================================================================ -->
<section class="section" style="background:linear-gradient(135deg,var(--red-900),var(--red-700));color:var(--white)" aria-labelledby="ctaHeading">
    <div class="container" style="text-align:center">
        <h2 id="ctaHeading" style="color:var(--white);font-size:var(--text-4xl);margin-bottom:var(--space-4)">
            Ready to Save a Life?
        </h2>
        <p style="color:rgba(255,255,255,0.8);font-size:var(--text-lg);margin-bottom:var(--space-8);max-width:500px;margin-left:auto;margin-right:auto">
            Join thousands of donors who have already made a difference.
            It only takes a few minutes to register.
        </p>
        <div style="display:flex;flex-wrap:wrap;gap:var(--space-3);justify-content:center">
            <a href="<?= APP_URL ?>/pages/register.php" class="btn-hero-primary">Get Started Today</a>
            <a href="<?= APP_URL ?>/pages/public/leaderboard.php" class="btn-hero-outline">🏆 View Leaderboard</a>
        </div>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
