<?php
/**
 * LifeLink – Privacy Notice (location data)
 */
$pageTitle = 'Privacy – Location Data';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
startSecureSession();
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <div class="container"><h1>📍 Location Privacy Notice</h1></div>
</div>
<section class="section-sm">
<div class="container" style="max-width:680px">
<div class="card"><div class="card-body">

<h2 style="color:var(--red-700)">How We Use Your Location</h2>

<h3>What we collect</h3>
<p>When you allow location access or type your city, we store:</p>
<ul>
  <li>Latitude and longitude (GPS or geocoded from your city name)</li>
  <li>City, state and country (human-readable place name)</li>
  <li>How the location was obtained: GPS, typed manually, or geocoded</li>
</ul>

<h3>How we use it</h3>
<ul>
  <li>To match you with blood requests near you — donors within a few kilometres are notified first.</li>
  <li>To show requesters an approximate distance ("about 3 km away") — <strong>never your exact address</strong>.</li>
  <li>To display a map pin rounded to the nearest ~111 metres (3 decimal places).</li>
  <li>Admin reports show city and state only — never exact coordinates.</li>
</ul>

<h3>What we never share</h3>
<ul>
  <li>Exact GPS coordinates are <strong>never shown</strong> to other users or in API responses.</li>
  <li>We do not sell or share your location with third parties.</li>
  <li>We do not send your location to analytics platforms.</li>
</ul>

<h3>Geocoding</h3>
<p>
  If you type your city, we look it up using
  <a href="https://nominatim.openstreetmap.org" target="_blank" rel="noopener">OpenStreetMap Nominatim</a>
  — a privacy-respecting, open-source service.
  Your browser only contacts <em>our server</em>; we proxy the request.
  Results are cached for 30 days on our server to minimise requests to Nominatim.
</p>

<h3>Your rights</h3>
<ul>
  <li>You can update your location any time from your profile page.</li>
  <li>You can remove your location — this sets your availability to offline and removes you from matching.</li>
  <li>If you deny location permission, you can still use the app by typing your city manually.</li>
</ul>

<p style="margin-top:24px">
  <a href="javascript:history.back()" class="btn btn-outline btn-sm">← Go Back</a>
</p>

</div></div>
</div>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>

