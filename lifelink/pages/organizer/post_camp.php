<?php
/**
 * LifeLink – Post Donation Camp
 * Updated: replaced area_id dropdown with location block (lat/lng/city/state/country)
 */
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/geocode.php';

// Verified organizations can host camps too; both roles use the same review
// workflow and every submission remains pending until admin approval.
requireRole('organizer', 'organization');
$db     = getDB();
$userId = currentUserId();
$errors = [];
$old    = [];

if (isPost()) {
    if (!csrfValidate()) { $errors[] = 'Invalid form submission.'; }
    else {
        $old     = $_POST;
        $title   = sanitizeInput($_POST['title']   ?? '');
        $venue   = sanitizeInput($_POST['venue']   ?? '');
        $date    = $_POST['camp_date']  ?? '';
        $start   = $_POST['start_time'] ?? '';
        $end     = $_POST['end_time']   ?? '';
        $contact = sanitizeInput($_POST['contact'] ?? '');
        $desc    = sanitizeInput($_POST['description'] ?? '');

        // Location fields
        $lat      = filter_var($_POST['lat']     ?? '', FILTER_VALIDATE_FLOAT);
        $lng      = filter_var($_POST['lng']     ?? '', FILTER_VALIDATE_FLOAT);
        $city     = sanitizeInput($_POST['city']    ?? '');
        $state    = sanitizeInput($_POST['state']   ?? '');
        $country  = sanitizeInput($_POST['country'] ?? '');
        $locSrc   = in_array($_POST['location_source'] ?? '', ['gps','manual','geocoded'])
                    ? $_POST['location_source'] : 'manual';
        $consent  = isset($_POST['location_consent']) ? 1 : 0;

        if (strlen($title) < 3)     $errors[] = 'Title must be at least 3 characters.';
        if (strlen($venue) < 3)     $errors[] = 'Venue is required.';
        if (!$date || strtotime($date) < strtotime('today')) $errors[] = 'Camp date must be today or in the future.';
        if (!$start)                $errors[] = 'Start time is required.';
        if (!$end || $end <= $start) $errors[] = 'End time must be after start time.';
        if (!validatePhone($contact)) $errors[] = 'Enter a valid contact number (10-digit Indian mobile).';
        if (strlen($city) < 2)      $errors[] = 'City is required.';
        if (!$consent)              $errors[] = 'You must consent to location usage.';

        // Server-side geocode if lat/lng missing but city typed
        if (empty($errors) && ($lat === false || $lng === false) && $city) {
            $geo = geocodeSearch("$city,$state,$country");
            if ($geo) {
                $best   = $geo[0];
                $lat    = $best['lat'];
                $lng    = $best['lng'];
                $city   = $best['city']    ?: $city;
                $state  = $best['state']   ?: $state;
                $country = $best['country'] ?: $country;
                $locSrc = 'geocoded';
            } else {
                // Accept text-only; flag incomplete
                $lat = null; $lng = null;
                $locSrc = 'manual';
            }
        }

        // Validate lat/lng if present
        if ($lat !== null && $lat !== false && !validateLatLng($lat, $lng)) {
            $errors[] = 'Invalid coordinates. Please re-detect or type your city.';
        }

        if (empty($errors)) {
            $db->prepare(
                'INSERT INTO camps
                    (organizer_id, title, venue, camp_date, start_time, end_time, contact, description,
                     lat, lng, city, state, country, location_source, location_consent)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $userId, $title, $venue, $date, $start, $end, $contact, $desc ?: null,
                $lat ?: null, $lng ?: null, $city, $state, $country, $locSrc, $consent,
            ]);
            redirect(APP_URL . '/pages/organizer/dashboard.php', 'success', 'Camp submitted for admin approval!');
        }
    }
}

$pageTitle = 'Post Donation Camp';
require __DIR__ . '/../../includes/header.php';
?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<div class="page-header">
    <div class="container">
        <h1>Post a Donation Camp</h1>
        <p>Submit a camp for admin approval before it appears publicly.</p>
    </div>
</div>

<section class="section-sm">
    <div class="container" style="max-width:680px">
        <div class="card">
            <div class="card-body">
                <?php if ($errors): ?>
                    <div class="alert alert-danger mb-4">
                        <ul style="margin:0;padding-left:var(--space-4)">
                            <?php foreach ($errors as $err): ?>
                                <li><?= e($err) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="POST" data-validate novalidate id="campForm">
                    <?= csrfField() ?>

                    <div class="form-group mb-4">
                        <label for="title">Camp Title <span class="required">*</span></label>
                        <input type="text" id="title" name="title" required value="<?= e($old['title'] ?? '') ?>" placeholder="e.g. World Blood Donor Day Camp">
                        <span class="field-error"></span>
                    </div>

                    <div class="form-group mb-4">
                        <label for="venue">Venue <span class="required">*</span></label>
                        <input type="text" id="venue" name="venue" required value="<?= e($old['venue'] ?? '') ?>" placeholder="Hall name, street, landmark">
                        <span class="field-error"></span>
                    </div>

                    <div class="form-row mb-4">
                        <div class="form-group">
                            <label for="camp_date">Camp Date <span class="required">*</span></label>
                            <input type="date" id="camp_date" name="camp_date" required min="<?= date('Y-m-d') ?>" value="<?= e($old['camp_date'] ?? '') ?>">
                            <span class="field-error"></span>
                        </div>
                        <div class="form-group">
                            <label for="contact">Contact Number <span class="required">*</span></label>
                            <input type="tel" id="contact" name="contact" required value="<?= e($old['contact'] ?? '') ?>" placeholder="10-digit mobile">
                            <span class="field-error"></span>
                        </div>
                    </div>

                    <div class="form-row mb-4">
                        <div class="form-group">
                            <label for="start_time">Start Time <span class="required">*</span></label>
                            <input type="time" id="start_time" name="start_time" required value="<?= e($old['start_time'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label for="end_time">End Time <span class="required">*</span></label>
                            <input type="time" id="end_time" name="end_time" required value="<?= e($old['end_time'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="form-group mb-6">
                        <label for="description">Description</label>
                        <textarea id="description" name="description" placeholder="Details about the camp, what to bring, etc."><?= e($old['description'] ?? '') ?></textarea>
                    </div>

                    <!-- ── Location Block ── -->
                    <div style="border:1px solid var(--gray-200);border-radius:var(--radius-lg);padding:var(--space-5);margin-bottom:var(--space-6);background:var(--gray-50)">
                        <h3 style="font-size:var(--text-base);margin:0 0 var(--space-4);color:var(--navy)">📍 Venue Location</h3>
                        <div id="locationBlock"></div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-full btn-lg" id="campSubmitBtn">Submit for Approval</button>
                </form>
            </div>
        </div>
    </div>
</section>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="<?= APP_URL ?>/assets/js/location.js"></script>
<script>
LifeLinkLocation.init('#locationBlock', {
    autoDetect:    false,
    required:      true,
    appUrl:         <?= json_encode(APP_URL) ?>,
});
<?php if (!empty($old)): ?>
(function () {
    const values = {
        loc_lat: <?= json_encode($old['lat'] ?? '') ?>,
        loc_lng: <?= json_encode($old['lng'] ?? '') ?>,
        loc_city: <?= json_encode($old['city'] ?? '') ?>,
        loc_state: <?= json_encode($old['state'] ?? '') ?>,
        loc_country: <?= json_encode($old['country'] ?? '') ?>,
        loc_source: <?= json_encode($old['location_source'] ?? '') ?>,
    };
    Object.entries(values).forEach(([id, value]) => {
        const field = document.getElementById(id);
        if (field && value !== '') field.value = value;
    });
    if (<?= !empty($old['location_consent']) ? 'true' : 'false' ?>) {
        const consent = document.getElementById('loc_consent');
        if (consent) {
            consent.checked = true;
            document.getElementById('loc-body').style.display = '';
        }
    }
})();
<?php endif; ?>
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
