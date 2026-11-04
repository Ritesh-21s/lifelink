<?php
/**
 * LifeLink – Donor Eligibility Calculator
 * All eligibility logic lives here so it can be reused across pages and cron.
 */

require_once __DIR__ . '/../config/app.php';

/**
 * Calculate eligibility for a donor.
 *
 * @param array $donor  Row from donors JOIN users (must include gender, dob, last_donation_date, is_available, verified)
 * @param array $user   Row from users (must include status)
 * @return array [
 *   'eligible' => bool,
 *   'reason'   => string,          // human-readable reason if not eligible
 *   'eligible_on' => string|null,  // date string if not yet eligible
 *   'days_until' => int|null,
 * ]
 */
function checkEligibility(array $donor, array $user): array {
    // 1. Account active
    if ($user['status'] !== 'active') {
        return ['eligible' => false, 'reason' => 'Account is not active.', 'eligible_on' => null, 'days_until' => null];
    }

    // 2. Blocked / not verified
    if (!$donor['verified']) {
        return ['eligible' => false, 'reason' => 'Donor profile not yet verified by admin.', 'eligible_on' => null, 'days_until' => null];
    }

    // 3. Availability toggle
    if (!$donor['is_available']) {
        return ['eligible' => false, 'reason' => 'You have set yourself as unavailable.', 'eligible_on' => null, 'days_until' => null];
    }

    // 4. Age check
    $dob  = new DateTimeImmutable($donor['dob']);
    $now  = new DateTimeImmutable('today');
    $age  = (int)$dob->diff($now)->y;

    if ($age < MIN_AGE) {
        $eligible_on = $dob->modify('+' . MIN_AGE . ' years')->format('Y-m-d');
        $days = (int)(new DateTimeImmutable($eligible_on))->diff($now)->days;
        return ['eligible' => false, 'reason' => "Minimum age is " . MIN_AGE . ". You will be eligible on {$eligible_on}.", 'eligible_on' => $eligible_on, 'days_until' => $days];
    }

    if ($age > MAX_AGE) {
        return ['eligible' => false, 'reason' => "Maximum donor age is " . MAX_AGE . ".", 'eligible_on' => null, 'days_until' => null];
    }

    // 5. Donation gap
    if (!empty($donor['last_donation_date'])) {
        $gap = ($donor['gender'] === 'female') ? FEMALE_DONATION_GAP_DAYS : MALE_DONATION_GAP_DAYS;
        $lastDate    = new DateTimeImmutable($donor['last_donation_date']);
        $eligibleOn  = $lastDate->modify("+{$gap} days");
        $daysUntil   = (int)$now->diff($eligibleOn)->days;
        $sign        = ($now >= $eligibleOn) ? 0 : $daysUntil;

        if ($now < $eligibleOn) {
            return [
                'eligible'    => false,
                'reason'      => "Minimum gap is {$gap} days for " . ($donor['gender'] === 'female' ? 'females' : 'males') . ".",
                'eligible_on' => $eligibleOn->format('Y-m-d'),
                'days_until'  => $sign,
            ];
        }
    }

    return ['eligible' => true, 'reason' => 'Eligible to donate now.', 'eligible_on' => null, 'days_until' => 0];
}

/**
 * Get the next eligible date for a donor (returns today if already eligible).
 */
function nextEligibleDate(array $donor): DateTimeImmutable {
    if (empty($donor['last_donation_date'])) return new DateTimeImmutable('today');
    $gap = ($donor['gender'] === 'female') ? FEMALE_DONATION_GAP_DAYS : MALE_DONATION_GAP_DAYS;
    $lastDate   = new DateTimeImmutable($donor['last_donation_date']);
    $eligibleOn = $lastDate->modify("+{$gap} days");
    $today      = new DateTimeImmutable('today');
    return ($eligibleOn > $today) ? $eligibleOn : $today;
}

/**
 * Is a donor currently eligible for matching?
 * Quick boolean version used by the matching engine.
 */
function isDonorEligible(array $donor, array $user): bool {
    return checkEligibility($donor, $user)['eligible'];
}
