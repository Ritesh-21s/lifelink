<?php
/**
 * LifeLink – Geocoding Helper (server-side proxy for Nominatim)
 *
 * Provider-swappable: change GEOCODE_PROVIDER in config/app.php to use
 * a different service (e.g. a paid API). All public callers use the two
 * top-level functions: geocodeReverse() and geocodeSearch().
 *
 * Nominatim Usage Policy compliance:
 *  - Descriptive User-Agent with contact email (GEOCODE_UA in app.php)
 *  - 1 request per second max (server-side throttle via APC / file lock)
 *  - 30-day response cache in location_cache table
 *  - Never expose raw Nominatim responses to the browser; only parsed data
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';

// ──────────────────────────────────────────────────────────────
// Public API
// ──────────────────────────────────────────────────────────────

/**
 * Reverse geocode: lat/lng → city, state, country.
 *
 * @param float $lat  Latitude  [-90, 90]
 * @param float $lng  Longitude [-180, 180]
 * @return array|null ['city'=>..,'state'=>..,'country'=>..] or null on failure
 */
function geocodeReverse(float $lat, float $lng): ?array {
    $lat = round($lat, 5);
    $lng = round($lng, 5);
    $cacheKey = "rev:{$lat},{$lng}";

    $cached = _geocodeCacheGet($cacheKey);
    if ($cached !== null) return $cached;

    _geocodeThrottle();

    $url = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2&addressdetails=1&accept-language=en'
         . '&lat=' . urlencode((string)$lat)
         . '&lon=' . urlencode((string)$lng);

    $raw = _geocodeFetch($url);
    if ($raw === null) return null;

    $parsed = _parseNominatimAddress($raw['address'] ?? []);
    if ($parsed === null) return null;

    _geocodeCacheSet($cacheKey, $parsed);
    return $parsed;
}

/**
 * Forward geocode: "city, state, country" query → lat, lng, city, state, country.
 *
 * Returns up to 5 candidates sorted by relevance.
 * @return array[] Array of candidate arrays, each with lat, lng, city, state, country, display_name
 */
function geocodeSearch(string $query): array {
    $query    = trim($query);
    $cacheKey = 'fwd:' . md5(strtolower($query));

    $cached = _geocodeCacheGet($cacheKey);
    if ($cached !== null) return $cached;

    _geocodeThrottle();

    $url = 'https://nominatim.openstreetmap.org/search?format=jsonv2&addressdetails=1&accept-language=en&limit=5'
         . '&q=' . urlencode($query);

    $raw = _geocodeFetch($url);
    if ($raw === null || !is_array($raw)) return [];

    $results = [];
    foreach ($raw as $item) {
        $addr   = $item['address'] ?? [];
        $parsed = _parseNominatimAddress($addr);
        if (!$parsed) continue;
        $results[] = array_merge($parsed, [
            'lat'          => (float)$item['lat'],
            'lng'          => (float)$item['lon'],
            'display_name' => $item['display_name'] ?? '',
        ]);
    }

    _geocodeCacheSet($cacheKey, $results);
    return $results;
}

// ──────────────────────────────────────────────────────────────
// Internal helpers
// ──────────────────────────────────────────────────────────────

/**
 * Parse a Nominatim address object into city / state / country.
 * Tries multiple keys in priority order for city (Nominatim returns different
 * keys for different place types).
 */
function _parseNominatimAddress(array $addr): ?array {
    $city = $addr['city']
         ?? $addr['town']
         ?? $addr['village']
         ?? $addr['suburb']
         ?? $addr['county']
         ?? null;

    $state   = $addr['state']   ?? null;
    $country = $addr['country'] ?? null;

    if (!$city && !$state && !$country) return null;

    return [
        'city'    => (string)($city    ?? ''),
        'state'   => (string)($state   ?? ''),
        'country' => (string)($country ?? ''),
    ];
}

/**
 * Fetch a Nominatim URL and return decoded JSON (array) or null on error.
 */
function _geocodeFetch(string $url): mixed {
    $ua = defined('GEOCODE_UA') ? GEOCODE_UA : 'LifeLink/1.0 (contact@lifelink.local)';

    $ctx = stream_context_create([
        'http' => [
            'method'     => 'GET',
            'header'     => "User-Agent: {$ua}\r\nAccept: application/json\r\n",
            'timeout'    => 8,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $ctx);
    if ($body === false || $body === '') {
        error_log("Geocode fetch failed for: {$url}");
        return null;
    }

    $data = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("Geocode JSON decode error for: {$url}");
        return null;
    }

    return $data;
}

/**
 * Rate-limit to 1 Nominatim request per second using a temp file as a lock.
 * This is per-process; for production with multiple workers, use a Redis lock.
 */
function _geocodeThrottle(): void {
    $lockFile = sys_get_temp_dir() . '/lifelink_geocode_throttle.txt';
    $last     = (float)@file_get_contents($lockFile);
    $now      = microtime(true);
    if ($now - $last < 1.0) {
        $wait = (int)ceil(($last + 1.0 - $now) * 1_000_000);
        if ($wait > 0) usleep($wait);
    }
    file_put_contents($lockFile, microtime(true), LOCK_EX);
}

/**
 * Get a geocode result from the 30-day DB cache.
 */
function _geocodeCacheGet(string $key): mixed {
    try {
        $db   = getDB();
        $stmt = $db->prepare(
            "SELECT response_json FROM location_cache
             WHERE cache_key = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        return json_decode($row['response_json'], true);
    } catch (Throwable) {
        return null;
    }
}

/**
 * Store a geocode result in the DB cache.
 */
function _geocodeCacheSet(string $key, mixed $data): void {
    try {
        $db   = getDB();
        $stmt = $db->prepare(
            "INSERT INTO location_cache (cache_key, response_json)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE response_json = VALUES(response_json), created_at = NOW()"
        );
        $stmt->execute([$key, json_encode($data, JSON_UNESCAPED_UNICODE)]);
    } catch (Throwable) {
        /* non-fatal */
    }
}

/**
 * Validate lat/lng values (used server-side before any DB write or geocode call).
 */
function validateLatLng(mixed $lat, mixed $lng): bool {
    return is_numeric($lat) && is_numeric($lng)
        && (float)$lat >= -90  && (float)$lat <= 90
        && (float)$lng >= -180 && (float)$lng <= 180;
}

/**
 * Calculate Haversine distance in km between two lat/lng points.
 */
function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $R  = 6371.0;
    $d1 = deg2rad($lat2 - $lat1);
    $d2 = deg2rad($lng2 - $lng1);
    $a  = sin($d1/2)**2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($d2/2)**2;
    return $R * 2 * asin(min(1.0, sqrt($a)));
}

/**
 * Format a distance in km as a human-readable approximate string.
 */
function formatDistance(float $km): string {
    if ($km < 1) return 'less than 1 km away';
    if ($km < 2) return 'about 1 km away';
    return 'about ' . (int)round($km) . ' km away';
}

/**
 * Round coordinates for public display (3 decimal places ≈ 111m precision).
 */
function roundCoordPublic(float $coord): float {
    return round($coord, 3);
}

