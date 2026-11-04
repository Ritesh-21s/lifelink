<?php
/**
 * LifeLink – Request Detail (Requester view)
 * Shows matched donors (masked), nearby donor map (Leaflet), and AJAX polling.
 * Updated: coordinate-based matching, no area_id dependency.
 */
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/mailer.php';

requireRole('requester');
$db     = getDB();
$userId = currentUserId();

$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(APP_URL . '/pages/requester/dashboard.php', 'error', 'Invalid request.');

// Fetch request – use LEFT JOIN areas for backward compat
$stmt = $db->prepare(
    'SELECT r.*, COALESCE(r.city, a.name) AS location_label, a.name AS area_name
     FROM requests r
     LEFT JOIN areas a ON a.id = r.area_id
     WHERE r.id = ? AND r.requester_id = ?'
);
$stmt->execute([$id, $userId]);
$req = $stmt->fetch();
if (!$req) redirect(APP_URL . '/pages/requester/dashboard.php', 'error', 'Request not found or access denied.');

// Request changes are POST-only so that a shared or preloaded URL cannot
// cancel a request. Both cancellation and donation confirmation use CSRF.
if (isPost() && csrfValidate() && ($_POST['action'] ?? '') === 'cancel'
    && in_array($req['status'], ['Open', 'Donor Found'], true)) {
    $db->prepare("UPDATE requests SET status='Cancelled' WHERE id=? AND requester_id=?")
       ->execute([$id, $userId]);
    redirect(APP_URL . '/pages/requester/dashboard.php', 'success', 'Request cancelled.');
}

// Confirm donation POST
if (isPost() && csrfValidate() && ($_POST['action'] ?? '') === 'confirm_donation') {
    $donorId = (int)($_POST['donor_id'] ?? 0);
    $respId  = (int)($_POST['response_id'] ?? 0);
    if ($donorId && $respId && in_array($req['status'], ['Open', 'Donor Found'], true)) {
        $db->beginTransaction();
        $updateResponse = $db->prepare(
            "UPDATE responses SET status='donated' WHERE id=? AND donor_id=? AND request_id=? AND status='accepted'"
        );
        $updateResponse->execute([$respId, $donorId, $id]);
        if ($updateResponse->rowCount() !== 1) {
            $db->rollBack();
            redirect(APP_URL . '/pages/requester/request_detail.php?id=' . $id, 'error', 'Only an accepted donor can be confirmed once.');
        }
        $db->prepare("INSERT INTO donations (request_id, donor_id, requester_id, units) VALUES (?,?,?,1)")
           ->execute([$id, $donorId, $userId]);
        $db->prepare("UPDATE donors SET last_donation_date=CURDATE(), is_available=0, reminder_sent_for=NULL WHERE user_id=?")
           ->execute([$donorId]);

        $stmt2 = $db->prepare("SELECT COUNT(*) FROM responses WHERE request_id=? AND status='donated'");
        $stmt2->execute([$id]);
        $donatedCount = (int)$stmt2->fetchColumn();
        if ($donatedCount >= $req['units_needed']) {
            $db->prepare("UPDATE requests SET status='Fulfilled' WHERE id=?")->execute([$id]);
        } else {
            $db->prepare("UPDATE requests SET units_accepted=? WHERE id=?")->execute([$donatedCount, $id]);
        }
        $db->commit();

        // Thank-you email
        $du = $db->prepare("SELECT u.email, u.name FROM users u WHERE u.id=?");
        $du->execute([$donorId]);
        $donorUser = $du->fetch();
        if ($donorUser) {
            mailThankYou($donorUser['email'], $donorUser['name'], $req['patient_name'], $req['hospital']);
        }

        redirect(APP_URL . '/pages/requester/request_detail.php?id=' . $id, 'success', 'Donation confirmed! Thank you.');
    }
}

// Re-fetch updated request
$stmt->execute([$id, $userId]);
$req = $stmt->fetch();

// Get donor responses – LEFT JOIN areas for backward compat, include city from donors table
$responses = $db->prepare(
    "SELECT resp.id AS resp_id, resp.status, resp.responded_at,
            u.id AS user_id, u.name,
            d.blood_group,
            COALESCE(u.city, a.name, 'Unknown') AS location_label,
            u.city AS donor_city,
            DATEDIFF(CURDATE(), d.last_donation_date) AS days_since_donation,
            ROUND(d.lat, 3) AS lat_pub, ROUND(d.lng, 3) AS lng_pub
     FROM responses resp
     JOIN users u ON u.id = resp.donor_id
     JOIN donors d ON d.user_id = resp.donor_id
     LEFT JOIN areas a ON a.id = d.area_id
     WHERE resp.request_id = ?
     ORDER BY FIELD(resp.status,'accepted','donated','notified','declined'), resp.responded_at"
);
$responses->execute([$id]);
$donorResponses = $responses->fetchAll();

// Count accepted to show chat links
$acceptedCount = 0;
foreach ($donorResponses as $dr) {
    if ($dr['status'] === 'accepted') $acceptedCount++;
}

// Legacy installations may have an incompatible conversations table. Chat
// discovery must not block request posting or donor matching; accepted donors
// can still be reached through the My Chats page once that table is migrated.
$convMap = [];

$pageTitle = 'Request Details';
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div class="container">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:var(--space-4)">
            <div>
                <h1>Request #<?= $id ?></h1>
                <p>Patient: <?= e($req['patient_name']) ?></p>
            </div>
            <a href="<?= APP_URL ?>/pages/requester/dashboard.php" class="btn btn-outline" style="border-color:rgba(255,255,255,0.5);color:var(--white)">← Back</a>
        </div>
    </div>
</div>

<section class="section-sm">
    <div class="container">
        <div style="display:grid;gap:var(--space-6);grid-template-columns:1fr;">

            <!-- Request details card -->
            <div class="card">
                <div class="card-header">
                    <h2 style="font-size:var(--text-lg)">Request Details</h2>
                    <div style="display:flex;gap:var(--space-2)">
                        <span class="status-badge <?= urgencyClass($req['urgency']) ?>"><?= e($req['urgency']) ?></span>
                        <span class="status-badge <?= statusClass($req['status']) ?>"><?= e($req['status']) ?></span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="grid-2 gap-4">
                        <div><label class="text-xs text-muted">Patient</label><p style="font-weight:600"><?= e($req['patient_name']) ?></p></div>
                        <div><label class="text-xs text-muted">Blood Group</label><p><span class="blood-badge <?= bloodGroupBadgeClass($req['blood_group']) ?>"><?= e($req['blood_group']) ?></span></p></div>
                        <div><label class="text-xs text-muted">Units</label><p><?= (int)$req['units_accepted'] ?> / <?= (int)$req['units_needed'] ?> filled</p></div>
                        <div><label class="text-xs text-muted">Hospital</label><p><?= e($req['hospital']) ?></p></div>
                        <div><label class="text-xs text-muted">Location</label><p><?= e($req['location_label'] ?? $req['area_name'] ?? '—') ?><?= $req['state'] ? ', ' . e($req['state']) : '' ?></p></div>
                        <div><label class="text-xs text-muted">Contact</label><p><?= e($req['contact_phone']) ?></p></div>
                        <div><label class="text-xs text-muted">Needed By</label><p><?= formatDateTime($req['needed_by']) ?></p></div>
                        <div><label class="text-xs text-muted">Posted</label><p><?= formatDateTime($req['created_at']) ?></p></div>
                    </div>
                    <?php if ($req['notes']): ?>
                        <div style="margin-top:var(--space-4);padding:var(--space-4);background:var(--gray-50);border-radius:var(--radius-md)">
                            <label class="text-xs text-muted">Notes</label>
                            <p><?= e($req['notes']) ?></p>
                        </div>
                    <?php endif; ?>

                    <!-- Progress bar -->
                    <div style="margin-top:var(--space-5)">
                        <div style="display:flex;justify-content:space-between;font-size:var(--text-sm);margin-bottom:var(--space-2)">
                            <span>Units Filled</span>
                            <strong><?= (int)$req['units_accepted'] ?> / <?= (int)$req['units_needed'] ?></strong>
                        </div>
                        <div style="height:10px;background:var(--gray-200);border-radius:5px">
                            <div style="height:100%;background:var(--green);border-radius:5px;width:<?= $req['units_needed'] > 0 ? round(($req['units_accepted']/$req['units_needed'])*100) : 0 ?>%;transition:width 0.5s"></div>
                        </div>
                    </div>
                </div>
                <?php if (in_array($req['status'], ['Open','Donor Found'])): ?>
                <div class="card-footer">
                    <form method="POST" style="display:inline">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="cancel">
                        <button type="submit" class="btn btn-danger btn-sm" data-confirm="Cancel this request? Donors will no longer be able to respond.">
                            ✗ Cancel Request
                        </button>
                    </form>
                </div>
                <?php endif; ?>
            </div>

            <!-- Matched donor map (Leaflet) – only if request has coordinates -->
            <?php if ($req['lat'] && $req['lng'] && !empty($donorResponses)): ?>
            <div class="card">
                <div class="card-header">
                    <h2 style="font-size:var(--text-lg)">🗺️ Nearby Donor Map</h2>
                    <span style="font-size:var(--text-xs);color:var(--gray-400)">Donor positions are approximate</span>
                </div>
                <div id="donorMap" style="height:320px;border-radius:0 0 var(--radius-lg) var(--radius-lg)"></div>
                <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
                <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
                <script>
                (function() {
                    const map = L.map('donorMap').setView([<?= (float)$req['lat'] ?>, <?= (float)$req['lng'] ?>], 11);
                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        attribution: '© OpenStreetMap contributors'
                    }).addTo(map);

                    // Hospital marker (red)
                    const hospitalIcon = L.divIcon({className:'', html:'<div style="background:#c0392b;color:#fff;border-radius:50%;width:28px;height:28px;display:flex;align-items:center;justify-content:center;font-size:14px;border:2px solid #fff;box-shadow:0 2px 4px rgba(0,0,0,0.3)">🏥</div>', iconSize:[28,28], iconAnchor:[14,14]});
                    L.marker([<?= (float)$req['lat'] ?>, <?= (float)$req['lng'] ?>], {icon: hospitalIcon})
                     .bindPopup('<strong><?= e($req['hospital']) ?></strong><br><?= e($req['location_label'] ?? '') ?>').addTo(map);

                    // Donor markers (blue, rounded coords from PHP)
                    const donors = <?= json_encode(array_values(array_filter(array_map(function($dr) {
                        if (!$dr['lat_pub'] && !$dr['lng_pub']) return null;
                        return [
                            'lat'    => (float)$dr['lat_pub'],
                            'lng'    => (float)$dr['lng_pub'],
                            'name'   => explode(' ', $dr['name'])[0] . ' ***',
                            'bg'     => $dr['blood_group'],
                            'city'   => $dr['donor_city'] ?? $dr['location_label'],
                            'status' => $dr['status'],
                        ];
                    }, $donorResponses)))) ?>;

                    donors.forEach(function(d) {
                        const col = d.status === 'accepted' ? '#16a34a' : '#3b82f6';
                        const icon = L.divIcon({className:'', html:'<div style="background:'+col+';color:#fff;border-radius:50%;width:24px;height:24px;display:flex;align-items:center;justify-content:center;font-size:12px;border:2px solid #fff;box-shadow:0 2px 4px rgba(0,0,0,0.3)">🩸</div>', iconSize:[24,24], iconAnchor:[12,12]});
                        L.marker([d.lat, d.lng], {icon: icon})
                         .bindPopup('<strong>'+d.name+'</strong><br>'+d.bg+' · '+d.city+'<br><em>'+d.status+'</em>').addTo(map);
                    });
                })();
                </script>
            </div>
            <?php endif; ?>

            <!-- Donor responses -->
            <div class="card" id="donorListCard">
                <div class="card-header">
                    <h2 style="font-size:var(--text-lg)">Matched Donors (<span id="donorCount"><?= count($donorResponses) ?></span>)</h2>
                    <?php if ($req['status'] === 'Open' || $req['status'] === 'Donor Found'): ?>
                    <span style="font-size:var(--text-xs);color:var(--gray-400)" id="pollStatus">🔄 Live updates every 10s</span>
                    <?php endif; ?>
                </div>
                <div id="donorList">
                <?php if (empty($donorResponses)): ?>
                    <div class="empty-state" style="padding:var(--space-8)">
                        <div class="empty-icon">👤</div>
                        <h3>No Donors Notified Yet</h3>
                        <p>We're searching for compatible donors. Check back soon.</p>
                    </div>
                <?php else: ?>
                    <div class="table-wrapper">
                        <table>
                            <thead><tr>
                                <th>Donor</th>
                                <th>Blood Group</th>
                                <th>Location</th>
                                <th>Last Donated</th>
                                <th>Response</th>
                                <th>Action</th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($donorResponses as $dr): ?>
                            <tr>
                                <td>
                                    <div style="font-weight:600">
                                        <?= $dr['status'] === 'accepted' || $dr['status'] === 'donated'
                                            ? e($dr['name'])
                                            : e(explode(' ', $dr['name'])[0]) . ' ***' ?>
                                    </div>
                                </td>
                                <td><span class="blood-badge <?= bloodGroupBadgeClass($dr['blood_group']) ?>"><?= e($dr['blood_group']) ?></span></td>
                                <td><?= e($dr['location_label']) ?></td>
                                <td><?= $dr['days_since_donation'] ? $dr['days_since_donation'] . ' days ago' : 'Never' ?></td>
                                <td>
                                    <span class="status-badge <?= $dr['status'] === 'accepted' ? 'status-found' : ($dr['status'] === 'donated' ? 'status-fulfilled' : ($dr['status'] === 'declined' ? 'status-cancelled' : 'status-open')) ?>">
                                        <?= e(ucfirst($dr['status'])) ?>
                                    </span>
                                </td>
                                <td style="white-space:nowrap">
                                    <?php if ($dr['status'] === 'accepted' && in_array($req['status'], ['Open','Donor Found'])): ?>
                                        <?php
                                        // Check for conversation with this donor
                                        $convId = $convMap[$dr['user_id']] ?? null;
                                        ?>
                                        <?php if ($convId): ?>
                                            <a href="<?= APP_URL ?>/pages/chat.php?conv=<?= (int)$convId ?>" class="btn btn-primary btn-sm" style="margin-bottom:4px">💬 Chat</a>
                                        <?php endif; ?>
                                        <form method="POST" style="display:inline">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="confirm_donation">
                                            <input type="hidden" name="donor_id" value="<?= (int)$dr['user_id'] ?>">
                                            <input type="hidden" name="response_id" value="<?= (int)$dr['resp_id'] ?>">
                                            <button type="submit" class="btn btn-success btn-sm"
                                                    data-confirm="Confirm that <?= e(explode(' ', $dr['name'])[0]) ?> donated blood?">
                                                ✅ Confirm Donation
                                            </button>
                                        </form>
                                    <?php elseif ($dr['status'] === 'donated'): ?>
                                        <span style="color:var(--green);font-weight:600">✅ Donated</span>
                                    <?php elseif ($dr['status'] === 'declined'): ?>
                                        <span class="text-muted text-xs">Declined</span>
                                    <?php else: ?>
                                        <span class="text-muted text-xs">Awaiting response</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                </div>
            </div>

        </div>
    </div>
</section>

<?php if (in_array($req['status'], ['Open', 'Donor Found'])): ?>
<script>
// Poll for donor updates every 10 seconds
(function() {
    const requestId  = <?= $id ?>;
    const pollSec    = 10;
    let   timer      = null;
    let   lastCount  = <?= count($donorResponses) ?>;

    function poll() {
        if (document.hidden) return;
        fetch('<?= APP_URL ?>/api/request_donors.php?request_id=' + requestId, {credentials: 'same-origin'})
            .then(r => r.json())
            .then(data => {
                if (!data.donors) return;
                document.getElementById('donorCount').textContent = data.donors.length;

                if (data.donors.length !== lastCount) {
                    // Rebuild donor table
                    lastCount = data.donors.length;
                    const wrap = document.getElementById('donorList');
                    if (data.donors.length === 0) {
                        wrap.innerHTML = '<div class="empty-state" style="padding:var(--space-8)"><div class="empty-icon">👤</div><h3>No Donors Notified Yet</h3><p>We\'re searching for compatible donors. Check back soon.</p></div>';
                        return;
                    }
                    let rows = data.donors.map(d => {
                        const statusClass = d.status === 'accepted' ? 'status-found' :
                                            d.status === 'donated'  ? 'status-fulfilled' :
                                            d.status === 'declined' ? 'status-cancelled' : 'status-open';
                        const name = (d.status === 'accepted' || d.status === 'donated')
                                     ? escHtml(d.name) : escHtml(d.name.split(' ')[0]) + ' ***';
                        const dist = d.distance_km ? '<br><small style="color:var(--gray-400)">~' + d.distance_km + ' km away</small>' : '';
                        return `<tr>
                            <td><div style="font-weight:600">${name}</div></td>
                            <td><span class="blood-badge">${escHtml(d.blood_group)}</span></td>
                            <td>${escHtml(d.city || d.location_label || '')}${dist}</td>
                            <td>${d.days_since_donation ? d.days_since_donation + ' days ago' : 'Never'}</td>
                            <td><span class="status-badge ${statusClass}">${escHtml(d.status.charAt(0).toUpperCase() + d.status.slice(1))}</span></td>
                            <td><span class="text-muted text-xs">Refresh page to act</span></td>
                        </tr>`;
                    }).join('');
                    wrap.innerHTML = '<div class="table-wrapper"><table><thead><tr><th>Donor</th><th>Blood Group</th><th>Location</th><th>Last Donated</th><th>Response</th><th>Action</th></tr></thead><tbody>' + rows + '</tbody></table></div>';
                }
                document.getElementById('pollStatus').textContent = '🔄 Updated ' + new Date().toLocaleTimeString();
            })
            .catch(() => { document.getElementById('pollStatus').textContent = '⚠️ Update failed'; });
    }

    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // Poll every 10 seconds; pause when tab is hidden
    document.addEventListener('visibilitychange', function() {
        if (document.hidden) { clearInterval(timer); } else { poll(); timer = setInterval(poll, pollSec * 1000); }
    });
    timer = setInterval(poll, pollSec * 1000);
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
