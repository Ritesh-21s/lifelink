<?php
/**
 * LifeLink – Registration Page (all roles) – v2 with location component
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/geocode.php';

startSecureSession();

if (isLoggedIn()) {
    header('Location: ' . APP_URL . '/index.php');
    exit;
}

$errors   = [];
$oldInput = [];

$regRole = in_array($_GET['role'] ?? '', ['donor','requester','organizer','organization'])
           ? $_GET['role']
           : 'donor';

if (isPost()) {
    if (!csrfValidate()) {
        $errors[] = 'Invalid form submission. Please try again.';
    } else {
        $regRole  = in_array($_POST['role'] ?? '', ['donor','requester','organizer','organization']) ? $_POST['role'] : 'donor';
        $name     = sanitizeInput($_POST['name'] ?? '');
        $email    = strtolower(sanitizeInput($_POST['email'] ?? ''));
        $phone    = sanitizeInput($_POST['phone'] ?? '')  ;
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';
        $oldInput = $_POST;

        // Location fields
        $lat      = isset($_POST['lat'])     && $_POST['lat']     !== '' ? (float)$_POST['lat']     : null;
        $lng      = isset($_POST['lng'])     && $_POST['lng']     !== '' ? (float)$_POST['lng']     : null;
        $city     = sanitizeInput($_POST['city']    ?? '');
        $state    = sanitizeInput($_POST['state']   ?? '');
        $country  = sanitizeInput($_POST['country'] ?? '');
        $locSrc   = in_array($_POST['location_source'] ?? '', ['gps','manual','geocoded'])
                    ? $_POST['location_source'] : 'manual';
        $consent  = !empty($_POST['location_consent']);

        // Basic validation
        if (strlen($name) < 2)             $errors[] = 'Name must be at least 2 characters.';
        if (!validateEmail($email))         $errors[] = 'Enter a valid email address.';
        if (!validatePhone($phone))         $errors[] = 'Enter a valid 10-digit mobile number.';
        if (strlen($password) < 8)         $errors[] = 'Password must be at least 8 characters.';
        if (!preg_match('/[A-Z]/', $password)) $errors[] = 'Password must contain at least one uppercase letter.';
        if (!preg_match('/[0-9]/', $password)) $errors[] = 'Password must contain at least one number.';
        if ($password !== $confirm)        $errors[] = 'Passwords do not match.';
        if (!$consent)                     $errors[] = 'Please tick the location consent checkbox.';
        if (strlen($city) < 2)             $errors[] = 'Please enter your city.';

        // Validate lat/lng if provided
        if ($lat !== null && $lng !== null) {
            if (!validateLatLng($lat, $lng)) {
                $errors[] = 'Invalid coordinates. Please re-detect your location.';
                $lat = $lng = null;
            }
        }

        // If lat/lng missing but city provided, geocode server-side
        if (empty($errors) && ($lat === null || $lng === null) && $city) {
            $q       = implode(', ', array_filter([$city, $state, $country]));
            $results = geocodeSearch($q);
            if (!empty($results)) {
                $lat    = $results[0]['lat'];
                $lng    = $results[0]['lng'];
                $locSrc = 'geocoded';
                if (empty($city))    $city    = $results[0]['city'] ?? $city;
                if (empty($state))   $state   = $results[0]['state'] ?? $state;
                if (empty($country)) $country = $results[0]['country'] ?? $country;
            } else {
                // Geocode failed — save as location_incomplete
                $lat = $lng = null;
            }
        }

        // Donor-specific validation
        if ($regRole === 'donor') {
            if (!validateBloodGroup($_POST['blood_group'] ?? '')) $errors[] = 'Select a valid blood group.';
            if (!in_array($_POST['gender'] ?? '', ['male','female','other'])) $errors[] = 'Select your gender.';
            $dob = $_POST['dob'] ?? '';
            if (!$dob || !strtotime($dob)) {
                $errors[] = 'Enter a valid date of birth.';
            } else {
                $age = (int)(new DateTimeImmutable($dob))->diff(new DateTimeImmutable('today'))->y;
                if ($age < MIN_AGE) $errors[] = 'You must be at least ' . MIN_AGE . ' years old.';
                if ($age > MAX_AGE) $errors[] = 'Age must not exceed ' . MAX_AGE . ' years.';
            }
        }

        if (in_array($regRole, ['organizer','organization']) && empty($_POST['org_name'])) {
            $errors[] = 'Organization name is required.';
        }

        if (empty($errors)) {
            $result = registerUser([
                'name'               => $name,
                'email'              => $email,
                'phone'              => $phone,
                'password'           => $password,
                'role'               => $regRole,
                'blood_group'        => $_POST['blood_group'] ?? '',
                'gender'             => $_POST['gender'] ?? '',
                'dob'                => $_POST['dob'] ?? '',
                'last_donation_date' => $_POST['last_donation_date'] ?? '',
                'weight'             => $_POST['weight'] ?? '',
                'org_name'           => $_POST['org_name'] ?? '',
                'org_type'           => $_POST['org_type'] ?? 'other',
                // Location
                'lat'                => $lat,
                'lng'                => $lng,
                'city'               => $city,
                'state'              => $state,
                'country'            => $country,
                'location_source'    => $locSrc,
                'location_consent'   => $consent ? 1 : 0,
            ]);

            if ($result['success']) {
                mailVerification($email, $name, $result['token']);
                redirect(
                    APP_URL . '/pages/login.php',
                    'success',
                    'Registration successful! We sent a verification link to ' . $email . '. Check your inbox.'
                );
            } else {
                $errors[] = $result['message'];
            }
        }
    }
}

$roleInfo = [
    'donor'        => ['icon' => '🩸', 'label' => 'Donor',        'desc' => 'Give blood and save lives'],
    'requester'    => ['icon' => '🏥', 'label' => 'Requester',    'desc' => 'Request blood for a patient'],
    'organizer'    => ['icon' => '🏢', 'label' => 'Organizer',    'desc' => 'Host donation camps'],
    'organization' => ['icon' => '🏛️', 'label' => 'Organization', 'desc' => 'Hospital / Blood Bank / NGO'],
];

$pageTitle = 'Register';
require __DIR__ . '/../includes/header.php';
?>
<style>
.role-tab-btn { flex:1;display:flex;flex-direction:column;align-items:center;gap:4px;padding:12px 6px;border-radius:var(--radius-md);text-decoration:none;font-weight:600;font-size:var(--text-sm);transition:all 0.2s;border:2px solid var(--gray-200);background:var(--white);color:var(--gray-500); }
.role-tab-btn.tab-donor        { background:#fff0f0;border-color:#c0392b;color:#c0392b; }
.role-tab-btn.tab-requester    { background:#eff6ff;border-color:#2980b9;color:#2980b9; }
.role-tab-btn.tab-organizer    { background:#f5f0ff;border-color:#8e44ad;color:#8e44ad; }
.role-tab-btn.tab-organization { background:#f0fff4;border-color:#27ae60;color:#27ae60; }
.role-banner { display:flex;align-items:center;gap:12px;padding:12px 16px;border-radius:var(--radius-md);margin-bottom:var(--space-5);font-weight:600; }
.role-banner.banner-donor        { background:#fff0f0;border:1.5px solid #e74c3c;color:#c0392b; }
.role-banner.banner-requester    { background:#eff6ff;border:1.5px solid #3b82f6;color:#1d4ed8; }
.role-banner.banner-organizer    { background:#f5f0ff;border:1.5px solid #8b5cf6;color:#6d28d9; }
.role-banner.banner-organization { background:#f0fff4;border:1.5px solid #27ae60;color:#155724; }
.donor-section { background:var(--red-50);border:1px solid #fca5a5;border-radius:var(--radius-lg);padding:var(--space-5);margin-top:var(--space-5); }
.org-section   { background:#f5f0ff;border:1px solid #c4b5fd;border-radius:var(--radius-lg);padding:var(--space-5);margin-top:var(--space-5); }
.loc-block { background:#f0fdf4;border:1.5px solid #86efac;border-radius:var(--radius-lg);padding:var(--space-4);margin-top:var(--space-5); }
.loc-header { display:flex;align-items:center;gap:8px;font-weight:700;margin-bottom:var(--space-3); }
.loc-consent-row { margin-bottom:var(--space-3); }
.loc-consent-label { display:flex;gap:8px;align-items:flex-start;font-size:var(--text-sm);cursor:pointer; }
.loc-status { font-size:var(--text-xs);padding:6px 10px;border-radius:var(--radius-sm);margin-top:6px; }
.loc-status-ok    { background:#d1fae5;color:#065f46; }
.loc-status-error { background:#fee2e2;color:#991b1b; }
.loc-status-warn  { background:#fef3c7;color:#92400e; }
.loc-status-info  { background:#dbeafe;color:#1e40af; }
.loc-badge { font-size:11px;background:var(--gray-100);border-radius:99px;padding:2px 8px;color:var(--gray-600); }
.loc-privacy-link { font-size:11px;color:var(--red-600); }
.loc-map-hint { font-size:11px;color:var(--gray-500);margin:4px 0 0;text-align:center; }
.section-divider { font-size:var(--text-xs);font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:var(--gray-400);margin:var(--space-5) 0 var(--space-3); }
</style>

<div class="page-header">
  <div class="container"><h1>Create Your Account</h1><p>Join the LifeLink network and start saving lives</p></div>
</div>

<section class="section-sm">
<div class="container" style="max-width:640px">

  <!-- Role tabs -->
  <div style="display:flex;gap:var(--space-2);margin-bottom:var(--space-4);flex-wrap:wrap">
    <?php foreach ($roleInfo as $r => $info): ?>
    <a href="?role=<?= $r ?>" class="role-tab-btn <?= $regRole === $r ? 'tab-'.$r : '' ?>" title="Register as <?= $info['label'] ?>">
      <span style="font-size:1.4rem"><?= $info['icon'] ?></span>
      <span><?= $info['label'] ?></span>
      <?php if ($regRole === $r): ?><span style="font-size:10px">✓</span><?php endif; ?>
    </a>
    <?php endforeach; ?>
  </div>

  <div class="role-banner banner-<?= $regRole ?>">
    <span style="font-size:1.4rem"><?= $roleInfo[$regRole]['icon'] ?></span>
    <div>
      <div>Registering as: <strong><?= $roleInfo[$regRole]['label'] ?></strong></div>
      <div style="font-size:var(--text-xs);opacity:0.8"><?= $roleInfo[$regRole]['desc'] ?></div>
    </div>
  </div>

  <div class="card">
    <div class="card-body">

      <?php if ($errors): ?>
      <div class="alert alert-danger mb-6" role="alert">
        <ul style="margin:0;padding-left:var(--space-5)">
          <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>

      <form method="POST" action="?role=<?= $regRole ?>" novalidate id="regForm">
        <?= csrfField() ?>
        <input type="hidden" name="role" value="<?= e($regRole) ?>">

        <p class="section-divider">Account Information</p>

        <div class="form-row mb-4">
          <div class="form-group">
            <label for="reg_name">Full Name <span class="required">*</span></label>
            <input type="text" id="reg_name" name="name" required
                   value="<?= e($oldInput['name'] ?? '') ?>" placeholder="Your full name">
          </div>
          <div class="form-group">
            <label for="reg_phone">Mobile Number <span class="required">*</span></label>
            <input type="tel" id="reg_phone" name="phone" required
                   value="<?= e($oldInput['phone'] ?? '') ?>" placeholder="10-digit mobile">
          </div>
        </div>

        <div class="form-group mb-4">
          <label for="reg_email">Email Address <span class="required">*</span></label>
          <input type="email" id="reg_email" name="email" required
                 value="<?= e($oldInput['email'] ?? '') ?>" placeholder="you@example.com">
        </div>

        <div class="form-row mb-4">
          <div class="form-group">
            <label for="reg_password">Password <span class="required">*</span></label>
            <input type="password" id="reg_password" name="password" required
                   placeholder="Min 8 chars, 1 uppercase, 1 number">
            <span class="field-error" id="passError" style="color:var(--red-600);font-size:var(--text-xs)"></span>
          </div>
          <div class="form-group">
            <label for="reg_confirm">Confirm Password <span class="required">*</span></label>
            <input type="password" id="reg_confirm" name="confirm_password" required
                   placeholder="Re-enter password">
            <span class="field-error" id="confError" style="color:var(--red-600);font-size:var(--text-xs)"></span>
          </div>
        </div>

        <?php if ($regRole === 'donor'): ?>
        <!-- Donor fields -->
        <div class="donor-section">
          <h3 style="color:var(--red-700);font-size:var(--text-base);margin-bottom:var(--space-3)">
            🩸 Donor Details
          </h3>
          <div class="form-row mb-4">
            <div class="form-group">
              <label for="blood_group">Blood Group <span class="required">*</span></label>
              <select id="blood_group" name="blood_group" required>
                <option value="">— Select —</option>
                <?php foreach (BLOOD_GROUPS as $g): ?>
                <option value="<?= $g ?>" <?= ($oldInput['blood_group'] ?? '') === $g ? 'selected' : '' ?>><?= $g ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label for="gender">Gender <span class="required">*</span></label>
              <select id="gender" name="gender" required>
                <option value="">— Select —</option>
                <option value="male"   <?= ($oldInput['gender'] ?? '') === 'male'   ? 'selected' : '' ?>>Male</option>
                <option value="female" <?= ($oldInput['gender'] ?? '') === 'female' ? 'selected' : '' ?>>Female</option>
                <option value="other"  <?= ($oldInput['gender'] ?? '') === 'other'  ? 'selected' : '' ?>>Other</option>
              </select>
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label for="dob">Date of Birth <span class="required">*</span></label>
              <input type="date" id="dob" name="dob" required
                     value="<?= e($oldInput['dob'] ?? '') ?>"
                     max="<?= date('Y-m-d', strtotime('-' . MIN_AGE . ' years')) ?>">
            </div>
            <div class="form-group">
              <label for="last_donation_date">Last Donation Date <em style="font-weight:400;color:var(--gray-400)">(optional)</em></label>
              <input type="date" id="last_donation_date" name="last_donation_date"
                     value="<?= e($oldInput['last_donation_date'] ?? '') ?>" max="<?= date('Y-m-d') ?>">
            </div>
          </div>
          <div class="form-group mt-3">
            <label for="weight">Weight kg <em style="font-weight:400;color:var(--gray-400)">(optional)</em></label>
            <input type="number" id="weight" name="weight" min="45" max="200" step="0.1"
                   value="<?= e($oldInput['weight'] ?? '') ?>" placeholder="e.g. 65">
          </div>
        </div>
        <?php endif; ?>

        <?php if (in_array($regRole, ['organizer','organization'])): ?>
        <!-- Organizer / Organization fields -->
        <div class="org-section">
          <h3 style="color:#6d28d9;font-size:var(--text-base);margin-bottom:var(--space-3)">🏢 Organization Details</h3>
          <div class="form-group mb-3">
            <label for="org_name">Organization Name <span class="required">*</span></label>
            <input type="text" id="org_name" name="org_name" required
                   value="<?= e($oldInput['org_name'] ?? '') ?>" placeholder="NGO / College / Hospital name">
          </div>
          <?php if ($regRole === 'organization'): ?>
          <div class="form-group">
            <label for="org_type">Organization Type</label>
            <select id="org_type" name="org_type">
              <option value="hospital"   <?= ($oldInput['org_type'] ?? '') === 'hospital'   ? 'selected' : '' ?>>Hospital</option>
              <option value="blood_bank" <?= ($oldInput['org_type'] ?? '') === 'blood_bank' ? 'selected' : '' ?>>Blood Bank</option>
              <option value="ngo"        <?= ($oldInput['org_type'] ?? '') === 'ngo'        ? 'selected' : '' ?>>NGO</option>
              <option value="college"    <?= ($oldInput['org_type'] ?? '') === 'college'    ? 'selected' : '' ?>>College / University</option>
              <option value="other"      <?= ($oldInput['org_type'] ?? '') === 'other'      ? 'selected' : '' ?>>Other</option>
            </select>
          </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Location block -->
        <div id="locationBlock"></div>

        <button type="submit" class="btn btn-primary btn-full btn-lg" style="margin-top:var(--space-6)">
          <?= $roleInfo[$regRole]['icon'] ?> Create <?= $roleInfo[$regRole]['label'] ?> Account
        </button>
      </form>

      <div style="text-align:center;margin-top:var(--space-5);font-size:var(--text-sm);color:var(--gray-600)">
        Already have an account?
        <a href="<?= APP_URL ?>/pages/login.php" style="font-weight:600;color:var(--red-600)">Login here</a>
      </div>
    </div>
  </div>
  <p style="text-align:center;font-size:var(--text-xs);color:var(--gray-400);margin-top:var(--space-4)">
    All accounts require admin approval before activation.
  </p>
</div>
</section>

<script>
window.APP_URL = <?= json_encode(APP_URL) ?>;
</script>
<script src="<?= APP_URL ?>/assets/js/location.js"></script>
<script>
(function(){
  // Password validation
  const pass = document.getElementById('reg_password');
  const conf = document.getElementById('reg_confirm');
  const pe   = document.getElementById('passError');
  const ce   = document.getElementById('confError');
  if (pass) {
    pass.addEventListener('input', function(){
      const v = this.value, ok = v.length>=8 && /[A-Z]/.test(v) && /[0-9]/.test(v);
      this.style.borderColor = v ? (ok ? 'var(--green)' : 'var(--red-500)') : '';
      pe.textContent = v && !ok ? 'Min 8 chars, 1 uppercase, 1 number' : '';
    });
    conf.addEventListener('input', function(){
      const m = this.value === pass.value;
      this.style.borderColor = this.value ? (m ? 'var(--green)' : 'var(--red-500)') : '';
      ce.textContent = this.value && !m ? 'Passwords do not match' : '';
    });
  }

  // Init location component
  if (typeof LifeLinkLocation !== 'undefined') {
    LifeLinkLocation.init('#locationBlock', {
      required: true,
      autoDetect: true,
    });
    // Pre-fill from old input if server-side validation failed
    <?php if (!empty($oldInput)): ?>
    const lat = <?= json_encode($oldInput['lat'] ?? '') ?>;
    const lng = <?= json_encode($oldInput['lng'] ?? '') ?>;
    if (lat) document.getElementById('loc_lat').value = lat;
    if (lng) document.getElementById('loc_lng').value = lng;
    document.getElementById('loc_city').value    = <?= json_encode($oldInput['city']    ?? '') ?>;
    document.getElementById('loc_state').value   = <?= json_encode($oldInput['state']   ?? '') ?>;
    document.getElementById('loc_country').value = <?= json_encode($oldInput['country'] ?? '') ?>;
    if (lat || <?= json_encode($oldInput['city'] ?? '') ?>) {
      document.getElementById('loc_consent').checked = true;
      document.getElementById('loc-body').style.display = '';
    }
    <?php endif; ?>
  }
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
