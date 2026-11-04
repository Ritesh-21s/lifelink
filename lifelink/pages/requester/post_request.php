<?php
/**
 * LifeLink – Post Emergency Request (v2 with location)
 */
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/matching.php';
require_once __DIR__ . '/../../includes/mailer.php';
require_once __DIR__ . '/../../includes/geocode.php';

requireRole('requester');
$db     = getDB();
$userId = currentUserId();
$errors = [];
$old    = [];

// Check if requester has location (if not, we'll ask in the form)
$userLoc = $db->prepare('SELECT lat, lng, city, state, country FROM users WHERE id = ?');
$userLoc->execute([$userId]);
$userLoc = $userLoc->fetch(PDO::FETCH_ASSOC);
$hasLocation = !empty($userLoc['lat']) && !empty($userLoc['lng']);

if (isPost()) {
    if (!csrfValidate()) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        $old = $_POST;

        // Rate limit
        $stmt2 = $db->prepare(
            "SELECT COUNT(*) FROM requests WHERE requester_id=? AND DATE(created_at)=CURDATE() AND status IN ('Open','Donor Found')"
        );
        $stmt2->execute([$userId]);
        if ((int)$stmt2->fetchColumn() >= MAX_OPEN_REQUESTS_PER_DAY) {
            $errors[] = 'Rate limit: You can have at most ' . MAX_OPEN_REQUESTS_PER_DAY . ' open requests per day.';
        }

        $patientName  = sanitizeInput($_POST['patient_name'] ?? '');
        $bloodGroup   = $_POST['blood_group'] ?? '';
        $unitsNeeded  = (int)($_POST['units_needed'] ?? 1);
        $hospital     = sanitizeInput($_POST['hospital'] ?? '');
        $urgency      = $_POST['urgency'] ?? 'Planned';
        $contactPhone = sanitizeInput($_POST['contact_phone'] ?? '');
        $neededBy     = $_POST['needed_by'] ?? '';
        $notes        = sanitizeInput($_POST['notes'] ?? '');

        // Location
        $lat     = isset($_POST['lat']) && $_POST['lat'] !== '' ? (float)$_POST['lat'] : null;
        $lng     = isset($_POST['lng']) && $_POST['lng'] !== '' ? (float)$_POST['lng'] : null;
        $city    = sanitizeInput($_POST['city']    ?? '');
        $state   = sanitizeInput($_POST['state']   ?? '');
        $country = sanitizeInput($_POST['country'] ?? '');
        $consent = !empty($_POST['location_consent']);

        if (strlen($patientName) < 2)                    $errors[] = 'Patient name is required.';
        if (!validateBloodGroup($bloodGroup))             $errors[] = 'Select a valid blood group.';
        if ($unitsNeeded < 1 || $unitsNeeded > 10)       $errors[] = 'Units needed must be between 1 and 10.';
        if (strlen($hospital) < 2)                       $errors[] = 'Hospital name is required.';
        if (!in_array($urgency, ['Critical','Within 24 hours','Planned'])) $errors[] = 'Invalid urgency.';
        if (!validatePhone($contactPhone))               $errors[] = 'Enter a valid 10-digit contact number.';
        if (!$neededBy || strtotime($neededBy) < time()) $errors[] = 'Needed-by date/time must be in the future.';
        if (strlen($city) < 2)                           $errors[] = 'Please enter the hospital city/location.';
        if (!$consent)                                   $errors[] = 'Please consent to using the hospital location for matching.';

        // Validate and geocode lat/lng
        if ($lat !== null && $lng !== null && !validateLatLng($lat, $lng)) {
            $errors[] = 'Invalid coordinates. Please re-detect location.';
            $lat = $lng = null;
        }
        if (empty($errors) && ($lat === null || $lng === null) && $city) {
            $q       = implode(', ', array_filter([$city, $state, $country]));
            $results = geocodeSearch($q);
            if (!empty($results)) {
                $lat     = $results[0]['lat'];
                $lng     = $results[0]['lng'];
                if (!$city)    $city    = $results[0]['city'] ?? $city;
                if (!$state)   $state   = $results[0]['state'] ?? $state;
                if (!$country) $country = $results[0]['country'] ?? $country;
            }
        }

        if (empty($errors)) {
            $expiresAt = date('Y-m-d H:i:s', strtotime($neededBy) + REQUEST_EXPIRY_HOURS * 3600);
            $stmt = $db->prepare(
                'INSERT INTO requests
                 (requester_id, patient_name, blood_group, units_needed, hospital,
                  lat, lng, city, state, country, urgency, contact_phone, needed_by, expires_at, notes)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([
                $userId, $patientName, $bloodGroup, $unitsNeeded, $hospital,
                $lat, $lng, $city ?: null, $state ?: null, $country ?: null,
                $urgency, $contactPhone, $neededBy, $expiresAt, $notes ?: null
            ]);
            $requestId = (int)$db->lastInsertId();

            $requestRow = $db->prepare('SELECT * FROM requests WHERE id=?');
            $requestRow->execute([$requestId]);
            $requestRow = $requestRow->fetch(PDO::FETCH_ASSOC);

            // Notify matching donors (wave 1) — uses coordinate matching if lat/lng set
            $notified = notifyMatchingDonors($requestRow);

            redirect(
                APP_URL . '/pages/requester/request_detail.php?id=' . $requestId,
                'success',
                "Request posted! {$notified} compatible donor(s) notified in your area."
            );
        }
    }
}

$pageTitle = 'Post Blood Request';
require __DIR__ . '/../../includes/header.php';
?>
<style>
.loc-block { background:#f0fdf4;border:1.5px solid #86efac;border-radius:var(--radius-lg);padding:var(--space-4);margin-top:var(--space-4); }
.loc-header { display:flex;align-items:center;gap:8px;font-weight:700;margin-bottom:var(--space-3); }
.loc-consent-row { margin-bottom:var(--space-3); }
.loc-consent-label { display:flex;gap:8px;align-items:flex-start;font-size:var(--text-sm);cursor:pointer; }
.loc-status { font-size:var(--text-xs);padding:6px 10px;border-radius:var(--radius-sm);margin-top:6px; }
.loc-status-ok { background:#d1fae5;color:#065f46; }
.loc-status-error { background:#fee2e2;color:#991b1b; }
.loc-status-warn { background:#fef3c7;color:#92400e; }
.loc-status-info { background:#dbeafe;color:#1e40af; }
.loc-badge { font-size:11px;background:var(--gray-100);border-radius:99px;padding:2px 8px;color:var(--gray-600); }
.loc-privacy-link { font-size:11px;color:var(--red-600); }
.loc-map-hint { font-size:11px;color:var(--gray-500);margin:4px 0 0;text-align:center; }
</style>

<div class="page-header">
  <div class="container"><h1>🏥 Post Emergency Blood Request</h1><p>We will find nearby compatible donors immediately.</p></div>
</div>

<section class="section-sm">
<div class="container" style="max-width:640px">

<?php if ($errors): ?>
<div class="alert alert-danger mb-4">
  <ul style="margin:0;padding-left:var(--space-5)">
    <?php foreach ($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<div class="card">
<div class="card-body">
<form method="POST" novalidate>
  <?= csrfField() ?>

  <p class="section-divider">Patient Information</p>
  <div class="form-row mb-4">
    <div class="form-group">
      <label for="patient_name">Patient Name <span class="required">*</span></label>
      <input type="text" id="patient_name" name="patient_name" required
             value="<?= e($old['patient_name'] ?? '') ?>" placeholder="Full name">
    </div>
    <div class="form-group">
      <label for="blood_group">Blood Group Needed <span class="required">*</span></label>
      <select id="blood_group" name="blood_group" required>
        <option value="">— Select —</option>
        <?php foreach (BLOOD_GROUPS as $g): ?>
        <option value="<?= $g ?>" <?= ($old['blood_group'] ?? '') === $g ? 'selected' : '' ?>><?= $g ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <div class="form-row mb-4">
    <div class="form-group">
      <label for="units_needed">Units Needed <span class="required">*</span></label>
      <input type="number" id="units_needed" name="units_needed" min="1" max="10" required
             value="<?= (int)($old['units_needed'] ?? 1) ?>">
    </div>
    <div class="form-group">
      <label for="urgency">Urgency <span class="required">*</span></label>
      <select id="urgency" name="urgency" required>
        <option value="Planned"       <?= ($old['urgency'] ?? '') === 'Planned'        ? 'selected' : '' ?>>Planned</option>
        <option value="Within 24 hours" <?= ($old['urgency'] ?? '') === 'Within 24 hours'? 'selected' : '' ?>>Within 24 hours</option>
        <option value="Critical"      <?= ($old['urgency'] ?? '') === 'Critical'       ? 'selected' : '' ?>>Critical 🚨</option>
      </select>
    </div>
  </div>

  <p class="section-divider">Hospital Details</p>
  <div class="form-group mb-4">
    <label for="hospital">Hospital / Centre Name <span class="required">*</span></label>
    <input type="text" id="hospital" name="hospital" required
           value="<?= e($old['hospital'] ?? '') ?>" placeholder="e.g. Apollo Hospital, Anna Nagar">
  </div>

  <div class="form-row mb-4">
    <div class="form-group">
      <label for="contact_phone">Contact Phone <span class="required">*</span></label>
      <input type="tel" id="contact_phone" name="contact_phone" required
             value="<?= e($old['contact_phone'] ?? '') ?>" placeholder="10-digit number">
    </div>
    <div class="form-group">
      <label for="needed_by">Needed By <span class="required">*</span></label>
      <input type="datetime-local" id="needed_by" name="needed_by" required
             value="<?= e($old['needed_by'] ?? '') ?>"
             min="<?= date('Y-m-d\TH:i') ?>">
    </div>
  </div>

  <!-- Location block -->
  <div id="locationBlock"></div>

  <div class="form-group mt-4">
    <label for="notes">Additional Notes <em style="font-weight:400;color:var(--gray-400)">(optional)</em></label>
    <textarea id="notes" name="notes" rows="3" maxlength="500"
              placeholder="Any other details (urgency reason, specific requirements…)"><?= e($old['notes'] ?? '') ?></textarea>
  </div>

  <button type="submit" class="btn btn-danger btn-full btn-lg" style="margin-top:var(--space-5)">
    🩸 Post Request &amp; Find Donors
  </button>
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
    required: true,
    autoDetect: false,  // hospital location, not user's GPS
  });
  // Pre-fill old values
  <?php if (!empty($old)): ?>
  document.getElementById('loc_city').value    = <?= json_encode($old['city'] ?? '') ?>;
  document.getElementById('loc_state').value   = <?= json_encode($old['state'] ?? '') ?>;
  document.getElementById('loc_country').value = <?= json_encode($old['country'] ?? '') ?>;
  if (<?= json_encode($old['city'] ?? '') ?>) {
    document.getElementById('loc_consent').checked = true;
    document.getElementById('loc-body').style.display = '';
  }
  <?php endif; ?>
})();
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
