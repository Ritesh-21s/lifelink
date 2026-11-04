<?php
/**
 * LifeLink – Matching Engine
 * Finds and ranks compatible, eligible donors for a blood request.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/eligibility.php';

/**
 * Get all donors compatible with the given blood group.
 * Returns array of donor_group strings.
 */
function getCompatibleDonorGroups(string $recipientGroup): array {
    $stmt = getDB()->prepare('SELECT donor_group FROM compatibility WHERE recipient_group = ?');
    $stmt->execute([$recipientGroup]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Get adjacent area IDs for a given area.
 */
function getAdjacentAreaIds(int $areaId): array {
    $stmt = getDB()->prepare('SELECT adjacent_area_id FROM area_adjacency WHERE area_id = ?');
    $stmt->execute([$areaId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Find ranked candidate donors for a request.
 *
 * Ranking score (higher = better):
 *  +100  same area as request
 *  +50   adjacent area
 *  +20   exact blood group match (vs compatible)
 *  +DATEDIFF(TODAY, last_donation_date)  – longer gap ranks higher
 *
 * @param array $request  Full request row (blood_group, area_id, units_needed, units_accepted, id)
 * @param bool  $expanded Whether to include adjacent areas (escalation)
 * @return array  Ranked donor rows
 */
function findMatchingDonors(array $request, bool $expanded = false): array {
    $db = getDB();

    $compatibleGroups = getCompatibleDonorGroups($request['blood_group']);
    if (empty($compatibleGroups)) return [];

    $adjacentAreaIds = getAdjacentAreaIds((int)$request['area_id']);

    // Build placeholders for IN clause
    $groupPlaceholders = implode(',', array_fill(0, count($compatibleGroups), '?'));

    // Exclude donors who have already responded to this request
    $sql = "
        SELECT
            u.id AS user_id,
            u.name,
            u.status AS user_status,
            d.blood_group,
            d.gender,
            d.dob,
            d.area_id,
            d.last_donation_date,
            d.is_available,
            d.verified,
            a.name AS area_name,
            DATEDIFF(CURDATE(), d.last_donation_date) AS days_since_donation
        FROM donors d
        JOIN users u ON u.id = d.user_id
        JOIN areas a ON a.id = d.area_id
        LEFT JOIN responses r ON r.request_id = ? AND r.donor_id = u.id
        WHERE
            u.status = 'active'
            AND d.is_available = 1
            AND d.verified = 1
            AND d.blood_group IN ({$groupPlaceholders})
            AND r.id IS NULL
    ";

    $params = [(int)$request['id']];
    foreach ($compatibleGroups as $g) $params[] = $g;

    // Area filter: same area always, adjacent only if expanded
    if (!$expanded) {
        $sql .= ' AND d.area_id = ?';
        $params[] = (int)$request['area_id'];
    } else {
        $areaIds = array_merge([(int)$request['area_id']], $adjacentAreaIds);
        $areaPlaceholders = implode(',', array_fill(0, count($areaIds), '?'));
        $sql .= " AND d.area_id IN ({$areaPlaceholders})";
        foreach ($areaIds as $id) $params[] = $id;
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $donors = $stmt->fetchAll();

    // Filter by eligibility and calculate score
    $ranked = [];
    foreach ($donors as $d) {
        $userRow  = ['status' => $d['user_status']];
        $donorRow = $d;
        if (!isDonorEligible($donorRow, $userRow)) continue;

        $score = 0;
        if ((int)$d['area_id'] === (int)$request['area_id']) $score += 100;
        elseif (in_array((int)$d['area_id'], array_map('intval', $adjacentAreaIds))) $score += 50;
        if ($d['blood_group'] === $request['blood_group']) $score += 20;
        $score += ($d['days_since_donation'] ?? 999);   // longer gap = higher score

        $d['_score'] = $score;
        $ranked[] = $d;
    }

    usort($ranked, fn($a, $b) => $b['_score'] <=> $a['_score']);
    return $ranked;
}

/**
 * Notify matching donors for a request (insert 'notified' responses).
 * Only notifies donors not already in the responses table for this request.
 */
function notifyMatchingDonors(array $request, bool $expanded = false): int {
    $donors = findMatchingDonors($request, $expanded);
    $db     = getDB();
    $count  = 0;
    $stmt   = $db->prepare(
        'INSERT IGNORE INTO responses (request_id, donor_id, status) VALUES (?,?,\'notified\')'
    );
    foreach ($donors as $d) {
        $stmt->execute([(int)$request['id'], (int)$d['user_id']]);
        $count += $stmt->rowCount();
    }
    return $count;
}

/**
 * Accept a response (donor accepts a request) with row-level locking
 * to prevent over-acceptance on multi-unit requests.
 *
 * Returns ['success'=>bool, 'message'=>string]
 */
function acceptResponse(int $requestId, int $donorId): array {
    $db = getDB();
    try {
        $db->beginTransaction();

        // Lock the request row
        $stmt = $db->prepare(
            'SELECT id, units_needed, units_accepted, status FROM requests WHERE id = ? FOR UPDATE'
        );
        $stmt->execute([$requestId]);
        $req = $stmt->fetch();

        if (!$req) {
            $db->rollBack();
            return ['success' => false, 'message' => 'Request not found.'];
        }
        if ($req['status'] !== 'Open') {
            $db->rollBack();
            return ['success' => false, 'message' => 'This request is no longer open.'];
        }
        if ($req['units_accepted'] >= $req['units_needed']) {
            $db->rollBack();
            return ['success' => false, 'message' => 'All units for this request have been filled.'];
        }

        // Check donor has a 'notified' response
        $stmt2 = $db->prepare('SELECT id, status FROM responses WHERE request_id = ? AND donor_id = ?');
        $stmt2->execute([$requestId, $donorId]);
        $resp = $stmt2->fetch();

        if (!$resp) {
            $db->rollBack();
            return ['success' => false, 'message' => 'You were not notified for this request.'];
        }
        if ($resp['status'] === 'accepted') {
            $db->rollBack();
            return ['success' => false, 'message' => 'You have already accepted this request.'];
        }
        if ($resp['status'] === 'declined') {
            $db->rollBack();
            return ['success' => false, 'message' => 'You have already declined this request.'];
        }

        // Update response to accepted
        $db->prepare(
            'UPDATE responses SET status = \'accepted\', responded_at = NOW() WHERE id = ?'
        )->execute([$resp['id']]);

        // Increment units_accepted and possibly close request
        $newAccepted = $req['units_accepted'] + 1;
        $newStatus   = ($newAccepted >= $req['units_needed']) ? 'Donor Found' : 'Open';

        $db->prepare(
            'UPDATE requests SET units_accepted = ?, status = ? WHERE id = ?'
        )->execute([$newAccepted, $newStatus, $requestId]);

        $db->commit();
        return ['success' => true, 'message' => 'You have accepted the request. Requester contact details are now visible.'];
    } catch (Throwable $e) {
        $db->rollBack();
        error_log('acceptResponse error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'An error occurred. Please try again.'];
    }
}

/**
 * Decline a response.
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
    return ['success' => true, 'message' => 'You have declined the request.'];
}
