<?php
/**
 * LifeLink – Site Footer
 */
?>
</main><!-- /#main-content -->

<!-- ======================================================== -->
<!-- FOOTER -->
<!-- ======================================================== -->
<footer class="site-footer" role="contentinfo">
    <div class="container">
        <div class="footer-grid">
            <div class="footer-brand">
                <a href="<?= APP_URL ?>/index.php" class="brand" aria-label="LifeLink Home">
                    <span class="brand-icon">🩸</span>
                    <span class="brand-name">Life<span class="brand-accent">Link</span></span>
                </a>
                <p class="footer-tagline">Connecting donors. Saving lives. Every drop counts.</p>
                <div class="footer-social">
                    <span class="social-placeholder">💉 <strong id="footerLivesCount">...</strong> lives helped</span>
                </div>
            </div>
            <div class="footer-links">
                <h3>Navigation</h3>
                <ul>
                    <li><a href="<?= APP_URL ?>/index.php">Home</a></li>
                    <li><a href="<?= APP_URL ?>/pages/public/search.php">Find Donors</a></li>
                    <li><a href="<?= APP_URL ?>/pages/public/calendar.php">Donation Camps</a></li>
                    <li><a href="<?= APP_URL ?>/pages/public/leaderboard.php">Leaderboard</a></li>
                    <li><a href="<?= APP_URL ?>/pages/public/analytics.php">Analytics</a></li>
                </ul>
            </div>
            <div class="footer-links">
                <h3>Account</h3>
                <ul>
                    <li><a href="<?= APP_URL ?>/pages/register.php">Register as Donor</a></li>
                    <li><a href="<?= APP_URL ?>/pages/register.php?role=requester">Register as Requester</a></li>
                    <li><a href="<?= APP_URL ?>/pages/register.php?role=organizer">Register as Organizer</a></li>
                    <li><a href="<?= APP_URL ?>/pages/login.php">Login</a></li>
                </ul>
            </div>
            <div class="footer-contact">
                <h3>Emergency Blood Groups</h3>
                <div class="footer-blood-groups">
                    <?php foreach (['A+','A-','B+','B-','AB+','AB-','O+','O-'] as $g): ?>
                        <a href="<?= APP_URL ?>/pages/public/search.php?blood_group=<?= urlencode($g) ?>"
                           class="blood-badge <?= bloodGroupBadgeClass($g) ?>"><?= $g ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="footer-bottom">
            <p class="footer-disclaimer">
                ⚠️ <strong>Disclaimer:</strong> LifeLink connects blood donors and requesters.
                It does not replace professional hospital or blood bank screening.
                Always consult medical professionals before and during donation.
            </p>
            <p class="footer-copy">&copy; <?= date('Y') ?> <?= APP_NAME ?>. Built to save lives.</p>
        </div>
    </div>
</footer>

<!-- ======================================================== -->
<!-- SCRIPTS -->
<!-- ======================================================== -->
<script>
    const APP_URL = '<?= APP_URL ?>';
    const CSRF_TOKEN = '<?= csrfGenerate() ?>';
    const TICKER_POLL_SECONDS = <?= TICKER_POLL_SECONDS ?>;
</script>
<script src="<?= APP_URL ?>/assets/js/app.js"></script>

<!-- Confirm dialog overlay (used by destructive actions) -->
<div class="confirm-overlay" id="confirmOverlay" aria-hidden="true">
    <div class="confirm-box" role="dialog" aria-modal="true" aria-labelledby="confirmTitle">
        <h3 id="confirmTitle">Are you sure?</h3>
        <p id="confirmMessage">This action cannot be undone.</p>
        <div class="confirm-actions">
            <button class="btn btn-outline" id="confirmCancel">Cancel</button>
            <button class="btn btn-danger" id="confirmOk">Confirm</button>
        </div>
    </div>
</div>
</body>
</html>
