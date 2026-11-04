<?php
/**
 * LifeLink – Donor Profile Edit (v2 with location)
 */
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/geocode.php';

requireRole('donor');

$db     = getDB();
$userId = currentUserId();
$errors = [];

// Fetch donor + user data
$stmt = $db->prepare(
    'SELECT d.*, u.name, u.email, u.phone,
            u.lat, u.lng, u.city, u.state, u.country,
            u.location_source, u.location_updated_at, u.location_consent, u.location_incomplete,
            u.notify_email
     FROM donors d
     JOIN users u ON u.id = d.user_id
     WHERE d.user_id = ?'
);
$stmt->execute([$userId]);
$donor = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$donor) {
    redirect(APP_URL . '/pages/donor/dashboard.php', 'error', 'Profile not found.');
}

$locationStale = false;
if ($donor['location_updated_at']) {
    $staleDays = defined('LOCATION_STALE_DAYS') ? LOCATION_STALE_DAYS : 180;
    $locationStale = (time() - strtotime($donor['location_updated_at'])) > $staleDays * 86400;
}

if (isPost()) {
    if (!csrfValidate()) {
        $errors[] = 'Invalid form submission.';
    } else {
        $action = $_POST['_action'] ?? 'profile';

        if ($action === 'remove_location') {
            // Remove location → set unavailable
            $db->prepare('UPDATE users SET lat=NULL,lng=NULL,city=NULL,state=NULL,country=NULL,location_source=NULL,location_consent=0 WHERE id=?')->execute([$userId]);
            $db->prepare('UPDATE donors SET lat=NULL,lng=NULL,location_consent=0,is_available=0 WHERE user_id=?')->execute([$userId]);
            redirect(APP_URL . '/pages/donor/profile.php', 'success', 'Location removed. You have been set as unavailable.');
        }

        $name      = sanitizeInput($_POST['name'] ?? '');
        $phone     = sanitizeInput($_POST['phone'] ?? '');
        $weight    = $_POST['weight'] ?? '';
        $lastDon   = $_POST['last_donation_date'] ?? '';
        $notifyEmail = !empty($_POST['notify_email']) ? 1 : 0;

        // Location
        $lat     = isset($_POST['lat']) && $_POST['lat'] !== '' ? (float)$_POST['lat'] : null;
        $lng     = isset($_POST['lng']) && $_POST['lng'] !== '' ? (float)$_POST['lng'] : null;
        $city    = sanitizeInput($_POST['city']    ?? '');
        $state   = sanitizeInput($_POST['state']   ?? '');
        $country = sanitizeInput($_POST['country'] ?? '');
        $locSrc  = in_array($_POST['location_source'] ?? '', ['gps','manual','geocoded']) ? $_POST['location_source'] : 'manual';
        $consent = !empty($_POST['location_consent']) ? 1 : 0;

        if (strlen($name) < 2)   $errors[] = 'Name must be at least 2 characters.';
        if (!validatePhone($phone)) $errors[] = 'Invalid phone number.';
        if ($weight !== '' && ((float)$weight < 10 || (float)$weight > 300)) $errors[] = 'Invalid weight.';
        if ($lastDon && strtotime($lastDon) > time()) $errors[] = 'Last donation date cannot be in the future.';

        // Validate and geocode
        if ($lat !== null && $lng !== null && !validateLatLng($lat, $lng)) {
            $errors[] = 'Invalid coordinates.'; $lat = $lng = null;
        }
        if (empty($errors) && $consent && ($lat === null || $lng === null) && $city) {
            $q = implode(', ', array_filter([$city, $state, $country]));
            $results = geocodeSearch($q);
            if (!empty($results)) {
                $lat = $results[0]['lat']; $lng = $results[0]['lng'];
                if (!$city)    $city    = $results[0]['city']    ?? $city;
                if (!$state)   $state   = $results[0]['state']   ?? $state;
                if (!$country) $country = $results[0]['country'] ?? $country;
                $locSrc = 'geocoded';
            }
        }

        if (empty($errors)) {
            $db->prepare(
                'UPDATE users SET name=?, phone=?, notify_email=?,
                 lat=?, lng=?, city=?, state=?, country=?,
                 location_source=?, location_updated_at=NOW(), location_consent=?,
                 location_incomplete=?
                 WHERE id=?'
            )->execute([
                $name, $phone, $notifyEmail,
                $consent ? $lat : null,
                $consent ? $lng : null,
                $consent ? ($city ?: null) : null,
                $consent ? ($state ?: null) : null,
                $consent ? ($country ?: null) : null,
                $consent ? $locSrc : null,
                $consent,
                ($consent && ($lat === null || $lng === null)) ? 1 : 0,
                $userId,
            ]);
            $db->prepare(
                'UPDATE donors SET last_donation_date=?, weight=?,
                 lat=?, lng=?, location_consent=?
                 WHERE user_id=?'
            )->execute([
                $lastDon ?: null,
                $weight  ?: null,
                $consent ? $lat : null,
                $consent ? $lng : null,
                $consent,
                $userId,
            ]);
            $_SESSION['user_name'] = $name;
            redirect(APP_URL . '/pages/donor/dashboard.php', 'success', 'Profile updated successfully!');
        }
    }
}

$pageTitle = 'My Profile';
require __DIR__ . '/../../includes/header.php';
?>
<style>
.loc-block { background:#f0fdf4;border:1.5px solid #86efac;border-radius:var(--radius-lg);padding:var(--space-4); }
.loc-header { display:flex;align-items:center;gap:8px;font-weight:700;margin-bottom:var(--space-3); }
.loc-consent-row { margin-bottom:var(--space-3); }
.loc-consent-label { display:flex;gap:8px;align-items:flex-start;font-size:var(--text-sm);cursor:pointer; }
.loc-status { font-size:var(--text-xs);padding:6px 10px;border-radius:var(--radius-sm);margin-top:6px; }
.loc-status-ok { background:#d1fae5;color:#065f46; }
.loc-status-warn { background:#fef3c7;color:#92400e; }
.loc-status-info { background:#dbeafe;color:#1e40af; }
.loc-status-error { background:#fee2e2;color:#991b1b; }
.loc-badge { font-size:11px;background:var(--gray-100);border-radius:99px;padding:2px 8px;color:var(--gray-600); }
.loc-privacy-link { font-size:11px;color:var(--red-600); }
.loc-map-hint { font-size:11px;color:var(--gray-500);margin:4px 0 0;text-align:center; }
</style>

<div class="page-header">
  <div class="container"><h1>My Profile</h1><p>Update your donor information and location</p></div>
</div>

<section class="section-sm">
<div class="container" style="max-width:620px">

<?php if ($locationStale): ?>
<div class="alert" style="background:#fef3c7;border:1px solid #fbbf24;border-radius:var(--radius-md);padding:12px 16px;margin-bottom:16px">
  📍 Your location was last updated over <?= LOCATION_STALE_DAYS ?> days ago.
  <strong>Please refresh it</strong> so we can keep matching you with nearby requests.
</div>
<?php endif; ?>

<?php if ($donor['location_incomplete']): ?>
<div class="alert alert-warning mb-4">
  ⚠️ Your location is incomplete — we couldn't match your city to coordinates.
  Please update it below to start receiving nearby blood requests.
</div>
<?php endif; ?>

<div class="card">
<div class="card-body">

<?php if ($errors): ?>
<div class="alert alert-danger mb-4">
  <?php foreach ($errors as $e): ?><p><?= e($e) ?></p><?php endforeach; ?>
</div>
<?php endif; ?>

<form method="POST" novalidate>
  <?= csrfField() ?>
  <input type="hidden" name="_action" value="profile">

  <h3 style="margin-bottom:var(--space-4);color:var(--red-700)">Account Details</h3>

  <div class="form-row mb-4">
    <div class="form-group">
      <label for="name">Full Name <span class="required">*</span></label>
      <input type="text" id="name" name="name" required value="<?= e($donor['name']) ?>">
    </div>
    <div class="form-group">
      <label for="phone">Mobile Number <span class="required">*</span></label>
      <input type="tel" id="phone" name="phone" required value="<?= e($donor['phone']) ?>">
    </div>
  </div>

  <div class="form-group mb-4">
    <label>Email (cannot change)</label>
    <input type="email" value="<?= e($donor['email']) ?>" disabled style="background:var(--gray-100)">
  </div>

  <h3 style="margin-bottom:var(--space-4);color:var(--red-700)">Donor Details</h3>

  <div class="form-row mb-4">
    <div class="form-group">
      <label>Blood Group (cannot change)</label>
      <input value="<?= e($donor['blood_group']) ?>" disabled style="background:var(--gray-100)">
    </div>
    <div class="form-group">
      <label>Gender (cannot change)</label>
      <input value="<?= e(ucfirst($donor['gender'])) ?>" disabled style="background:var(--gray-100)">
    </div>
  </div>

  <div class="form-row mb-4">
    <div class="form-group">
      <label for="weight">Weight (kg)</label>
      <input type="number" id="weight" name="weight" min="45" max="200" step="0.1"
             value="<?= e($donor['weight'] ?? '') ?>" placeholder="e.g. 65">
    </div>
    <div class="form-group">
      <label for="last_donation_date">Last Donation Date</label>
      <input type="date" id="last_donation_date" name="last_donation_date"
             value="<?= e($donor['last_donation_date'] ?? '') ?>" max="<?= date('Y-m-d') ?>">
      <small class="text-muted">Used to calculate your next eligible date.</small>
    </div>
  </div>

  <!-- Location section -->
  <h3 style="margin-bottom:var(--space-3);color:var(--red-700)">📍 Your Location</h3>

  <?php if ($donor['lat'] && $donor['lng']): ?>
  <div style="background:var(--gray-50);border:1px solid var(--gray-200);border-radius:var(--radius-md);padding:12px 16px;margin-bottom:12px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
    <div>
      <strong><?= e($donor['city'] ?? '') ?><?= $donor['state'] ? ', ' . e($donor['state']) : '' ?></strong>
      <span style="font-size:11px;color:var(--gray-400);margin-left:8px"><?= e(ucfirst($donor['location_source'] ?? '')) ?></span>
      <?php if ($donor['location_updated_at']): ?>
      <div style="font-size:11px;color:var(--gray-400)">Last updated: <?= formatDate($donor['location_updated_at']) ?></div>
      <?php endif; ?>
    </div>
    <button type="submit" name="_action" value="remove_location" class="btn btn-sm"
            style="background:#fee2e2;color:#991b1b;border:none"
            onclick="return confirm('Remove your location? You will be set as unavailable.')">
      🗑 Remove Location
    </button>
  </div>
  <?php endif; ?>

  <div id="locationBlock"></div>

  <!-- Notification preference -->
  <h3 style="margin:var(--space-5) 0 var(--space-3);color:var(--red-700)">Notifications</h3>
  <label style="display:flex;align-items:center;gap:8px;font-size:var(--text-sm);cursor:pointer">
    <input type="checkbox" name="notify_email" value="1" <?= $donor['notify_email'] ? 'checked' : '' ?>>
    Receive email alerts when blood requests match my profile
  </label>

  <div style="display:flex;gap:var(--space-3);margin-top:var(--space-5)">
    <button type="submit" class="btn btn-primary">Save Changes</button>
    <a href="<?= APP_URL ?>/pages/donor/dashboard.php" class="btn btn-secondary">Cancel</a>
  </div>
</form>
</div>
</div>
</div>
</section>

<script>
window.APP_URL = <?= json_encode(APP_URL) ?>;
</script>
<script src="<?= APP_URL ?>/assets/js/location.js"></script>
<script>
(function(){
  if (typeof LifeLinkLocation === 'undefined') return;
  LifeLinkLocation.init('#locationBlock', {
    required: false,
    autoDetect: false,
  });
  // Pre-fill existing location
  const consent = document.getElementById('loc_consent');
  if (consent && <?= $donor['location_consent'] ? 'true' : 'false' ?>) {
    consent.checked = true;
    const body = document.getElementById('loc-body');
    if (body) body.style.display = '';
  }
  const setVal = (id, v) => { const el = document.getElementById(id); if (el && v) el.value = v; };
  setVal('loc_lat',     <?= json_encode($donor['lat']     ?? '') ?>);
  setVal('loc_lng',     <?= json_encode($donor['lng']     ?? '') ?>);
  setVal('loc_city',    <?= json_encode($donor['city']    ?? '') ?>);
  setVal('loc_state',   <?= json_encode($donor['state']   ?? '') ?>);
  setVal('loc_country', <?= json_encode($donor['country'] ?? 'India') ?>);
  setVal('loc_source',  <?= json_encode($donor['location_source'] ?? 'manual') ?>);
})();
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
