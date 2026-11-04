<?php
/**
 * LifeLink – Matching Engine v2 (coordinate-based)
 *
 * Ranking score formula (documented for VIVA.md):
 *  +100  exact blood group match (vs compatible)
 *  -dist  distance penalty: -(km * 2)  so nearer = higher
 *  +gap   days since last donation (longer gap = higher score, max 365 bonus)
 *  +10   trust: donor accepted at least one request in the past
 *  -5    penalty per ignored alert in current streak (max 5 alerts checked)
 *
 * Wave escalation:
 *  Wave 1 → top WAVE_SIZES[0] donors within RADIUS_RINGS[0] km
 *  If no acceptance in WAVE_TIMEOUT_MIN minutes → Wave 2, etc.
 *  Radius widens with each wave through RADIUS_RINGS[].
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/eligibility.php';
require_once __DIR__ . '/../includes/geocode.php';

// ──────────────────────────────────────────────────────────────
// Blood group helpers
// ──────────────────────────────────────────────────────────────

function getCompatibleDonorGroups(string $recipientGroup): array {
    $stmt = getDB()->prepare('SELECT donor_group FROM compatibility WHERE recipient_group = ?');
    $stmt->execute([$recipientGroup]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// ──────────────────────────────────────────────────────────────
// Core spatial query: donors within a bounding box + Haversine filter
// ──────────────────────────────────────────────────────────────

/**
 * Find ranked candidate donors for a request using coordinate-based matching.
 *
 * @param array $request   Full request row (must include lat, lng, blood_group, id)
 * @param float $radiusKm  Search radius in km
 * @param int   $wave      Current wave number (for recording in responses)
 * @return array Ranked donor rows (not yet in responses table for this request)
 */
function findMatchingDonorsByRadius(array $request, float $radiusKm, int $wave = 1): array {
    $db = getDB();

    $reqLat = (float)($request['lat'] ?? 0);
    $reqLng = (float)($request['lng'] ?? 0);

    // Bounding box pre-filter (fast index scan)
    $dLat = $radiusKm / 111.0;
    $dLng = $radiusKm / (111.0 * max(0.0001, cos(deg2rad($reqLat))));

    $latMin = $reqLat - $dLat;
    $latMax = $reqLat + $dLat;
    $lngMin = $reqLng - $dLng;
    $lngMax = $reqLng + $dLng;

    $compatibleGroups = getCompatibleDonorGroups($request['blood_group']);
    if (empty($compatibleGroups)) return [];

    // PDO does not allow positional and named placeholders in the same
    // statement. Use named placeholders throughout so this query works with
    // MySQL's native prepared statements as well as emulated ones.
    $groupParams = [];
    foreach ($compatibleGroups as $index => $group) {
        $name = ':group' . $index;
        $groupParams[$name] = $group;
    }
    $gPlaceholders = implode(',', array_keys($groupParams));

    // Haversine distance in SQL — uses LEAST(1,...) inside ACOS to prevent domain errors
    $havSql = "(6371 * 2 * ASIN(LEAST(1, SQRT(
        POW(SIN(RADIANS(d.lat - :reqLat) / 2), 2)
        + COS(RADIANS(:reqLat2)) * COS(RADIANS(d.lat))
        * POW(SIN(RADIANS(d.lng - :reqLng) / 2), 2)
    ))))";

    $sql = "
        SELECT
            u.id        AS user_id,
            u.name,
            u.notify_email,
            u.city      AS user_city,
            u.state     AS user_state,
            d.blood_group,
            d.gender,
            d.dob,
            d.area_id,
            d.last_donation_date,
            d.is_available,
            d.verified,
            d.lat,
            d.lng,
            d.total_alerts,
            d.total_accepted,
            d.ignored_streak,
            d.daily_alerts_sent,
            d.daily_alert_date,
            d.location_consent,
            DATEDIFF(CURDATE(), d.last_donation_date) AS days_since_donation,
            {$havSql} AS dist_km
        FROM donors d
        JOIN users u ON u.id = d.user_id
        LEFT JOIN responses r ON r.request_id = :reqId AND r.donor_id = u.id
        WHERE
            u.status          = 'active'
            AND d.is_available = 1
            AND d.verified     = 1
            AND d.location_consent = 1
            AND d.lat IS NOT NULL
            AND d.lng IS NOT NULL
            AND d.lat BETWEEN :latMin AND :latMax
            AND d.lng BETWEEN :lngMin AND :lngMax
            AND d.blood_group IN ({$gPlaceholders})
            AND r.id IS NULL
            AND (d.daily_alert_date != CURDATE() OR d.daily_alerts_sent < :dailyCap)
            AND d.ignored_streak < :ignoreCap
        HAVING dist_km <= :radius
        ORDER BY dist_km ASC
        LIMIT 200
    ";

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':reqLat',   $reqLat);
    $stmt->bindValue(':reqLat2',  $reqLat);
    $stmt->bindValue(':reqLng',   $reqLng);
    $stmt->bindValue(':reqId',    (int)$request['id'], PDO::PARAM_INT);
    $stmt->bindValue(':latMin',   $latMin);
    $stmt->bindValue(':latMax',   $latMax);
    $stmt->bindValue(':lngMin',   $lngMin);
    $stmt->bindValue(':lngMax',   $lngMax);
    $stmt->bindValue(':radius',   $radiusKm);
    $stmt->bindValue(':dailyCap', defined('DAILY_ALERT_CAP') ? DAILY_ALERT_CAP : 3, PDO::PARAM_INT);
    $stmt->bindValue(':ignoreCap', defined('IGNORED_STREAK_CAP') ? IGNORED_STREAK_CAP : 5, PDO::PARAM_INT);

    foreach ($groupParams as $name => $group) {
        $stmt->bindValue($name, $group);
    }

    $stmt->execute();
    $donors = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Apply eligibility filter + compute ranking score
    $ranked = [];
    foreach ($donors as $d) {
        $userRow  = ['status' => 'active'];
        $donorRow = $d;
        if (!isDonorEligible($donorRow, $userRow)) continue;

        $score  = 0;
        $distKm = (float)$d['dist_km'];

        // +100 exact blood group match
        if ($d['blood_group'] === $request['blood_group']) $score += 100;

        // Distance penalty: every 2 km = -1 point
        $score -= ($distKm * 2);

        // Donation gap bonus (max 365)
        $gap = min(365, (int)($d['days_since_donation'] ?? 0));
        $score += $gap;

        // Trust bonus: has accepted at least once
        if ((int)$d['total_accepted'] > 0) $score += 10;

        // Ignored streak penalty
        $score -= ((int)$d['ignored_streak'] * 5);

        $d['_score']   = $score;
        $d['_dist_km'] = $distKm;
        $ranked[]      = $d;
    }

    usort($ranked, fn($a, $b) => $b['_score'] <=> $a['_score']);
    return $ranked;
}

/**
 * Find the next ring that has enough candidates, returning (radius, donors[]).
 * Searches RADIUS_RINGS in order until RADIUS_MIN_CANDIDATES found (or rings exhausted).
 *
 * @param array $request
 * @param int   $startRingIndex  Ring to begin from (0 = innermost)
 * @param int   $wave
 * @return array ['radius'=>float, 'donors'=>array, 'ring_index'=>int]
 */
function findMatchingDonorsNextRing(array $request, int $startRingIndex = 0, int $wave = 1): array {
    $rings   = defined('RADIUS_RINGS') ? RADIUS_RINGS : [5, 10, 25, 50];
    $minCand = defined('RADIUS_MIN_CANDIDATES') ? RADIUS_MIN_CANDIDATES : 3;

    for ($i = $startRingIndex; $i < count($rings); $i++) {
        $donors = findMatchingDonorsByRadius($request, $rings[$i], $wave);
        if (count($donors) >= $minCand || $i === count($rings) - 1) {
            return ['radius' => $rings[$i], 'donors' => $donors, 'ring_index' => $i];
        }
    }
    return ['radius' => end($rings), 'donors' => [], 'ring_index' => count($rings) - 1];
}

/**
 * Notify a batch of donors for the current wave.
 * Inserts into responses (INSERT IGNORE) and queues notification emails.
 * Updates donor daily_alerts_sent counter.
 *
 * @param array $request
 * @param array $donors    Ranked donor rows from findMatchingDonorsByRadius()
 * @param int   $wave
 * @param int   $batchSize How many donors to notify this wave
 * @return int  Number newly notified
 */
function notifyDonorWave(array $request, array $donors, int $wave, int $batchSize): int {
    $db    = getDB();
    $count = 0;
    $today = date('Y-m-d');
    $batch = array_slice($donors, 0, $batchSize);

    $hasResponseWave = dbHasColumn($db, 'responses', 'wave');
    $stmtResp = $db->prepare($hasResponseWave
        ? 'INSERT IGNORE INTO responses (request_id, donor_id, status, wave) VALUES (?,?,\'notified\',?)'
        : 'INSERT IGNORE INTO responses (request_id, donor_id, status) VALUES (?,?,\'notified\')'
    );
    $stmtAlerts = $db->prepare(
        'UPDATE donors
         SET daily_alerts_sent = IF(daily_alert_date = ?, daily_alerts_sent + 1, 1),
             daily_alert_date  = ?,
             total_alerts      = total_alerts + 1
         WHERE user_id = ?'
    );

    foreach ($batch as $d) {
        $params = [(int)$request['id'], (int)$d['user_id']];
        if ($hasResponseWave) $params[] = $wave;
        $stmtResp->execute($params);
        if ($stmtResp->rowCount() > 0) {
            $stmtAlerts->execute([$today, $today, (int)$d['user_id']]);
            // Queue notification email (non-blocking)
            if (!empty($d['notify_email'])) {
                queueDonorRequestEmail($d, $request);
            }
            $count++;
        }
    }
    return $count;
}

/**
 * Main entry point: notify matching donors for a brand-new request (wave 1).
 */
function notifyMatchingDonors(array $request, bool $expanded = false): int {
    // If request has no coordinates, fall back gracefully
    if (empty($request['lat']) || empty($request['lng'])) {
        return 0; // Cannot match without coordinates
    }

    $rings     = defined('RADIUS_RINGS') ? RADIUS_RINGS : [5, 10, 25, 50];
    $waveSizes = defined('WAVE_SIZES') ? WAVE_SIZES : [5, 10, 20];
    $startRing = $expanded ? 1 : 0;

    $result = findMatchingDonorsNextRing($request, $startRing, 1);
    $donors = $result['donors'];

    if (empty($donors)) return 0;

    $batchSize = $waveSizes[0] ?? 5;

    // For Critical, use smaller initial batch for faster first response
    if (($request['urgency'] ?? '') === 'Critical') {
        $batchSize = max(3, (int)($batchSize * 0.6));
    }

    $notified = notifyDonorWave($request, $donors, 1, $batchSize);

    // Record wave metadata only on databases that have the v2 columns.
    $db = getDB();
    if (dbHasColumn($db, 'requests', 'wave') && dbHasColumn($db, 'requests', 'wave_notified_at')) {
        $db->prepare('UPDATE requests SET wave = 1, wave_notified_at = NOW() WHERE id = ?')
           ->execute([(int)$request['id']]);
    }

    return $notified;
}

/**
 * Escalation: fire the next wave for a request.
 * Called by cron/escalate_requests.php.
 *
 * @param array $request  Full request row (must include wave, lat, lng, id, urgency)
 * @return array ['notified'=>int, 'wave'=>int, 'radius'=>float]
 */
function escalateRequest(array $request): array {
    $waveSizes = defined('WAVE_SIZES') ? WAVE_SIZES : [5, 10, 20];
    $rings     = defined('RADIUS_RINGS') ? RADIUS_RINGS : [5, 10, 25, 50];

    $currentWave = (int)($request['wave'] ?? 0);
    $nextWave    = $currentWave + 1;
    // Ring index grows with wave (widening search)
    $ringIndex   = min($currentWave, count($rings) - 1);

    $result  = findMatchingDonorsNextRing($request, $ringIndex, $nextWave);
    $donors  = $result['donors'];
    $radius  = $result['radius'];

    if (empty($donors)) {
        return ['notified' => 0, 'wave' => $nextWave, 'radius' => $radius];
    }

    $batchSize = $waveSizes[min($currentWave, count($waveSizes) - 1)] ?? 20;
    $notified  = notifyDonorWave($request, $donors, $nextWave, $batchSize);

    $db = getDB();
    if (dbHasColumn($db, 'requests', 'wave') && dbHasColumn($db, 'requests', 'wave_notified_at')) {
        $db->prepare('UPDATE requests SET wave = ?, wave_notified_at = NOW() WHERE id = ?')
           ->execute([$nextWave, (int)$request['id']]);
    }

    return ['notified' => $notified, 'wave' => $nextWave, 'radius' => $radius];
}

/**
 * Get nearby notified/matched donors for a request (for requester view).
 * Returns sanitized data: no exact coords, no email, no phone.
 *
 * @param int   $requestId
 * @param float $reqLat    Request latitude
 * @param float $reqLng    Request longitude
 * @return array
 */
function getRequestDonorsForRequester(int $requestId, float $reqLat, float $reqLng): array {
    $db   = getDB();
    $waveSelect = dbHasColumn($db, 'responses', 'wave') ? 'r.wave' : '1';
    $stmt = $db->prepare("
        SELECT
            r.status          AS response_status,
            r.responded_at,
            {$waveSelect}     AS wave,
            u.name            AS donor_name,
            u.city            AS donor_city,
            d.blood_group,
            d.last_donation_date,
            d.lat             AS donor_lat,
            d.lng             AS donor_lng
        FROM responses r
        JOIN users  u ON u.id = r.donor_id
        JOIN donors d ON d.user_id = r.donor_id
        WHERE r.request_id = ?
        ORDER BY FIELD(r.status,'accepted','notified','declined','donated'), r.responded_at DESC
    ");
    $stmt->execute([$requestId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return array_map(function ($row) use ($reqLat, $reqLng) {
        $dLat = (float)($row['donor_lat'] ?? 0);
        $dLng = (float)($row['donor_lng'] ?? 0);
        $dist = ($dLat && $dLng && $reqLat && $reqLng)
            ? haversineKm($reqLat, $reqLng, $dLat, $dLng)
            : null;
        return [
            'donor_name'      => $row['donor_name'],
            'city'            => $row['donor_city'] ?? '',
            'blood_group'     => $row['blood_group'],
            'last_donation'   => $row['last_donation_date'],
            'days_since_donation' => $row['last_donation_date']
                ? max(0, (int)((time() - strtotime($row['last_donation_date'])) / 86400))
                : null,
            'response_status' => $row['response_status'],
            'responded_at'    => $row['responded_at'],
            'wave'            => $row['wave'],
            'dist_km'         => $dist !== null ? round($dist, 1) : null,
            'dist_label'      => $dist !== null ? formatDistance($dist) : 'Location unknown',
            // Rounded coords (3 dec ≈ 111m) for map pin — NEVER exact
            'lat_public'      => $dLat ? roundCoordPublic($dLat) : null,
            'lng_public'      => $dLng ? roundCoordPublic($dLng) : null,
        ];
    }, $rows);
}

// ──────────────────────────────────────────────────────────────
// Accept / Decline (with chat conversation creation)
// ──────────────────────────────────────────────────────────────

/**
 * Accept a response (donor or organization accepts a request).
 * Also creates a chat conversation (UNIQUE constraint prevents duplicates).
 *
 * Returns ['success'=>bool, 'message'=>string, 'conversation_id'=>int|null]
 */
function acceptResponse(int $requestId, int $donorId): array {
    $db = getDB();
    try {
        $db->beginTransaction();

        // Lock the request row
        $stmt = $db->prepare(
            'SELECT id, requester_id, units_needed, units_accepted, status
             FROM requests WHERE id = ? FOR UPDATE'
        );
        $stmt->execute([$requestId]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$req) {
            $db->rollBack();
            return ['success' => false, 'message' => 'Request not found.', 'conversation_id' => null];
        }
        if ($req['status'] !== 'Open') {
            $db->rollBack();
            return ['success' => false, 'message' => 'This request is no longer open.', 'conversation_id' => null];
        }
        if ($req['units_accepted'] >= $req['units_needed']) {
            $db->rollBack();
            return ['success' => false, 'message' => 'All units for this request have been filled.', 'conversation_id' => null];
        }

        // Check donor has a 'notified' response
        $stmt2 = $db->prepare(
            'SELECT id, status FROM responses WHERE request_id = ? AND donor_id = ?'
        );
        $stmt2->execute([$requestId, $donorId]);
        $resp = $stmt2->fetch(PDO::FETCH_ASSOC);

        if (!$resp) {
            $db->rollBack();
            return ['success' => false, 'message' => 'You were not notified for this request.', 'conversation_id' => null];
        }
        if ($resp['status'] === 'accepted') {
            // Chat is optional for databases created before the current
            // conversation schema. Never let it block an accepted donation.
            $conv = null;
            if (dbHasColumn($db, 'conversations', 'responder_id')) {
                $convStmt = $db->prepare(
                    'SELECT id FROM conversations WHERE request_id = ? AND responder_id = ?'
                );
                $convStmt->execute([$requestId, $donorId]);
                $conv = $convStmt->fetch(PDO::FETCH_ASSOC);
            }
            $db->rollBack();
            return ['success' => false, 'message' => 'You have already accepted this request.',
                    'conversation_id' => $conv ? (int)$conv['id'] : null];
        }
        if ($resp['status'] === 'declined') {
            $db->rollBack();
            return ['success' => false, 'message' => 'You have already declined this request.', 'conversation_id' => null];
        }

        // Update response → accepted
        $db->prepare(
            'UPDATE responses SET status = \'accepted\', responded_at = NOW() WHERE id = ?'
        )->execute([$resp['id']]);

        // Update donor stats (accepted count, reset ignored streak)
        $db->prepare(
            'UPDATE donors SET total_accepted = total_accepted + 1, ignored_streak = 0 WHERE user_id = ?'
        )->execute([$donorId]);

        // Increment units_accepted, possibly close request
        $newAccepted = $req['units_accepted'] + 1;
        $newStatus   = ($newAccepted >= $req['units_needed']) ? 'Donor Found' : 'Open';
        $db->prepare(
            'UPDATE requests SET units_accepted = ?, status = ? WHERE id = ?'
        )->execute([$newAccepted, $newStatus, $requestId]);

        $convId = null;
        if (dbHasColumn($db, 'conversations', 'responder_id')) {
            // Create chat conversation (UNIQUE(request_id, responder_id) prevents duplicates)
            $convStmt = $db->prepare(
                'INSERT IGNORE INTO conversations (request_id, requester_id, responder_id)
                 VALUES (?, ?, ?)'
            );
            $convStmt->execute([$requestId, (int)$req['requester_id'], $donorId]);
            $convId = (int)$db->lastInsertId();

            if ($convId === 0) {
                $fetchStmt = $db->prepare(
                    'SELECT id FROM conversations WHERE request_id = ? AND responder_id = ?'
                );
                $fetchStmt->execute([$requestId, $donorId]);
                $convId = (int)($fetchStmt->fetchColumn() ?? 0);
            }
        }

        $db->commit();

        // Queue notification to requester (non-blocking)
        queueAcceptanceNotification((int)$req['requester_id'], $requestId, $donorId);

        return [
            'success'         => true,
            'message'         => $convId ? 'You have accepted the request. Chat with the requester now.' : 'You have accepted the request.',
            'conversation_id' => $convId,
        ];
    } catch (Throwable $e) {
        $db->rollBack();
        error_log('acceptResponse error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'An error occurred. Please try again.', 'conversation_id' => null];
    }
}

/**
 * Decline a response. Updates donor's ignored_streak if it was a simple ignore.
 */
function declineResponse(int $requestId, int $donorId): array {
    $db   = getDB();
    $stmt = $db->prepare(
        'UPDATE responses SET status = \'declined\', responded_at = NOW()
         WHERE request_id = ? AND donor_id = ? AND status = \'notified\''
    );
    $stmt->execute([$requestId, $donorId]);
    if ($stmt->rowCount() === 0) {
        return ['success' => false, 'message' => 'Could not decline. Already responded?'];
    }
    // Note: ignored_streak is incremented by cron for truly ignored (no response) alerts
    return ['success' => true, 'message' => 'You have declined the request.'];
}

// ──────────────────────────────────────────────────────────────
// Notification queue helpers (queue, never send inline)
// ──────────────────────────────────────────────────────────────

function queueDonorRequestEmail(array $donor, array $request): void {
    require_once __DIR__ . '/../includes/mailer.php';
    $subject = sprintf(
        '[LifeLink] %s blood needed at %s – %s km away',
        $request['blood_group'],
        $request['hospital'],
        $donor['_dist_km'] ? round($donor['_dist_km'], 1) : '?'
    );
    $distLabel = isset($donor['_dist_km']) ? formatDistance((float)$donor['_dist_km']) : '';
    $link      = APP_URL . '/pages/donor/dashboard.php';
    $body      = emailLayout($subject, "
        <h2 style='color:#c0392b;margin:0 0 16px'>🩸 Blood Request Near You</h2>
        <p>Hi <strong>" . htmlspecialchars($donor['name'] ?? 'Donor') . "</strong>, a patient near you needs blood.</p>
        <table style='width:100%;border-collapse:collapse;margin:16px 0'>
            <tr><td style='padding:6px;font-weight:600;color:#555'>Blood Group</td><td style='padding:6px'><strong style='color:#c0392b'>" . htmlspecialchars($request['blood_group']) . "</strong></td></tr>
            <tr style='background:#f9f9f9'><td style='padding:6px;font-weight:600;color:#555'>Hospital</td><td style='padding:6px'>" . htmlspecialchars($request['hospital']) . "</td></tr>
            <tr><td style='padding:6px;font-weight:600;color:#555'>City</td><td style='padding:6px'>" . htmlspecialchars($request['city'] ?? '') . "</td></tr>
            <tr style='background:#f9f9f9'><td style='padding:6px;font-weight:600;color:#555'>Distance</td><td style='padding:6px'>" . htmlspecialchars($distLabel) . "</td></tr>
            <tr><td style='padding:6px;font-weight:600;color:#555'>Urgency</td><td style='padding:6px'>" . htmlspecialchars($request['urgency']) . "</td></tr>
            <tr style='background:#f9f9f9'><td style='padding:6px;font-weight:600;color:#555'>Units Needed</td><td style='padding:6px'>" . (int)$request['units_needed'] . "</td></tr>
            <tr><td style='padding:6px;font-weight:600;color:#555'>Needed By</td><td style='padding:6px'>" . htmlspecialchars($request['needed_by']) . "</td></tr>
        </table>
        <p style='text-align:center;margin:24px 0'>
            <a href='" . htmlspecialchars($link) . "' style='background:linear-gradient(135deg,#c0392b,#e74c3c);color:#fff;padding:14px 32px;border-radius:8px;text-decoration:none;font-weight:bold;display:inline-block'>
                View Request &amp; Respond
            </a>
        </p>
        <p style='font-size:12px;color:#999'>You can update your notification preference in your donor profile.</p>
    ");
    queueMail((int)$donor['user_id'], (int)$request['id'], 'donor_request', $subject, $body);
}

function queueAcceptanceNotification(int $requesterId, int $requestId, int $donorId): void {
    require_once __DIR__ . '/../includes/mailer.php';
    $db      = getDB();
    $userStmt = $db->prepare('SELECT name, email, notify_email FROM users WHERE id = ?');
    $userStmt->execute([$requesterId]);
    $requester = $userStmt->fetch(PDO::FETCH_ASSOC);
    if (!$requester || !$requester['notify_email']) return;

    $donorStmt = $db->prepare('SELECT name FROM users WHERE id = ?');
    $donorStmt->execute([$donorId]);
    $donor = $donorStmt->fetch(PDO::FETCH_ASSOC);

    $link    = APP_URL . '/pages/my_chats.php';
    $subject = '[LifeLink] A donor has accepted your request – open chat now';
    $body    = emailLayout($subject, "
        <h2 style='color:#c0392b;margin:0 0 16px'>✅ Donor Accepted Your Request</h2>
        <p>Hi <strong>" . htmlspecialchars($requester['name']) . "</strong>,</p>
        <p>Great news! <strong>" . htmlspecialchars($donor['name'] ?? 'A donor') . "</strong> has accepted your blood request.</p>
        <p>You can now coordinate via the chat to arrange the donation.</p>
        <p style='text-align:center;margin:24px 0'>
            <a href='" . htmlspecialchars($link) . "' style='background:linear-gradient(135deg,#c0392b,#e74c3c);color:#fff;padding:14px 32px;border-radius:8px;text-decoration:none;font-weight:bold;display:inline-block'>
                💬 Open Chat
            </a>
        </p>
    ");
    queueMail($requesterId, $requestId, 'acceptance_notify', $subject, $body);
}
