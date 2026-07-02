<?php
/**
 * =============================================================================
 * nearest_hospital.php  —  Hospital Referral API Endpoint
 * =============================================================================
 * Works on localhost (XAMPP) AND live/deployed servers automatically.
 *
 * Services (100% FREE, no API key):
 *   - Nominatim API  → nearest hospital search
 *   - OSRM API       → driving distance & travel time
 *   - PHP Session    → 5-minute result cache per user
 * =============================================================================
 */

declare(strict_types=1);

// ── Load environment config ───────────────────────────────────────────────────
require_once __DIR__ . '/../config.php';

// ── Headers ───────────────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store, no-cache, must-revalidate');

// Allow same-origin AJAX + CORS for your own domain on live
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = [
    'http://localhost',
    'http://127.0.0.1',
    'http://localhost/medconnect',
    BASE_URL,
];
if (in_array($origin, $allowed, true) || $origin === '') {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: GET');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Vary: Origin');
}

// ── Constants ─────────────────────────────────────────────────────────────────
const SEARCH_RADIUS_KM = 15;
const HTTP_TIMEOUT     = 10;
const CACHE_TTL_SEC    = 300;   // 5 min
const OSRM_BASE        = 'https://router.project-osrm.org/route/v1/driving/';
const NOMINATIM_BASE   = 'https://nominatim.openstreetmap.org/search';
const USER_AGENT       = 'BagoCityHealthReferral/1.0 (contact@bagocity.gov.ph)';

// ── Session init ──────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── Simple rate limiting ──────────────────────────────────────────────────────
// Only enforced on live servers to protect the free API quota
if (!IS_LOCALHOST) {
    $ip      = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $rateKey = 'rl_' . md5($ip);

    if (!isset($_SESSION[$rateKey])) {
        $_SESSION[$rateKey] = ['count' => 0, 'window_start' => time()];
    }

    $rl = &$_SESSION[$rateKey];

    if ((time() - $rl['window_start']) > RATE_LIMIT_WINDOW) {
        // Reset window
        $rl = ['count' => 0, 'window_start' => time()];
    }

    $rl['count']++;

    if ($rl['count'] > RATE_LIMIT_MAX) {
        jsonError('Too many requests. Please wait a moment and try again.', 429);
    }
}

// ── Input validation ──────────────────────────────────────────────────────────
$rawLat = filter_input(INPUT_GET, 'lat', FILTER_VALIDATE_FLOAT)
       ?? filter_var($_GET['lat'] ?? null, FILTER_VALIDATE_FLOAT);
$rawLng = filter_input(INPUT_GET, 'lng', FILTER_VALIDATE_FLOAT)
       ?? filter_var($_GET['lng'] ?? null, FILTER_VALIDATE_FLOAT);
$bustCache = filter_input(INPUT_GET, 'bust', FILTER_VALIDATE_BOOLEAN) ?? false;

$lat = $rawLat;
$lng = $rawLng;

if ($lat === false || $lng === false || $lat === null || $lng === null) {
    jsonError('Invalid or missing latitude / longitude parameters.', 400);
}
if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
    jsonError('Coordinates are out of valid geographic range.', 400);
}

// ── Session cache ─────────────────────────────────────────────────────────────
$cacheKey = 'hospital_' . round((float)$lat, 3) . '_' . round((float)$lng, 3);

if (!$bustCache && isset($_SESSION[$cacheKey])) {
    $cached = $_SESSION[$cacheKey];
    if ((time() - ($cached['_cached_at'] ?? 0)) < CACHE_TTL_SEC) {
        $cached['cached'] = true;
        unset($cached['_cached_at']);
        echo json_encode($cached, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    unset($_SESSION[$cacheKey]);
}

// ── Nominatim search ──────────────────────────────────────────────────────────
$hospitals  = nominatimSearch('hospital', (float)$lat, (float)$lng);
$clinics    = nominatimSearch('clinic',   (float)$lat, (float)$lng);
$candidates = array_merge($hospitals, $clinics);

if (empty($candidates)) {
    jsonError('No hospitals or clinics found within ' . SEARCH_RADIUS_KM . ' km. For emergencies, call 911.', 200);
}

// ── Sort all candidates by distance, keep top 5 unique ───────────────────────
$scored = [];
foreach ($candidates as $place) {
    $pLat = (float)($place['lat'] ?? 0);
    $pLng = (float)($place['lon'] ?? 0);
    if ($pLat === 0.0 && $pLng === 0.0) continue;
    $dist = haversineKm((float)$lat, (float)$lng, $pLat, $pLng);
    $scored[] = ['place' => $place, 'dist' => $dist];
}

if (empty($scored)) {
    jsonError('Could not determine any hospital locations.', 200);
}

usort($scored, fn($a, $b) => $a['dist'] <=> $b['dist']);

// Deduplicate by name, keep top 5
$seen    = [];
$top5    = [];
foreach ($scored as $item) {
    $name = trim($item['place']['name'] ?? '');
    if ($name === '' || isset($seen[$name])) continue;
    $seen[$name] = true;
    $top5[] = $item;
    if (count($top5) >= 5) break;
}

if (empty($top5)) {
    jsonError('Could not determine the nearest hospital location.', 200);
}

// ── Build list with OSRM routing for each ────────────────────────────────────
$hospitalList = [];
foreach ($top5 as $item) {
    $place    = $item['place'];
    $hLat     = (float)$place['lat'];
    $hLng     = (float)$place['lon'];
    $hName    = $place['name'] ?? extractNameFromDisplay($place['display_name'] ?? '');
    $hAddress = buildAddress($place['display_name'] ?? '', $hName);

    $route    = getOsrmRoute((float)$lat, (float)$lng, $hLat, $hLng);
    $hospitalList[] = [
        'name'        => $hName,
        'address'     => $hAddress,
        'distance'    => $route['distance'] ?? haversineFormatted((float)$lat, (float)$lng, $hLat, $hLng),
        'travel_time' => $route['duration'] ?? estimateTravelTime((float)$lat, (float)$lng, $hLat, $hLng),
        'lat'         => $hLat,
        'lng'         => $hLng,
        'place_id'    => (string)($place['osm_id'] ?? ''),
    ];
}

// ── Build & cache result ──────────────────────────────────────────────────────
$result = [
    'hospitals'   => $hospitalList,          // full list for prev/next nav
    'name'        => $hospitalList[0]['name'],        // keep top-level for BC
    'address'     => $hospitalList[0]['address'],
    'distance'    => $hospitalList[0]['distance'],
    'travel_time' => $hospitalList[0]['travel_time'],
    'lat'         => $hospitalList[0]['lat'],
    'lng'         => $hospitalList[0]['lng'],
    'place_id'    => $hospitalList[0]['place_id'],
    'user_lat'    => (float)$lat,
    'user_lng'    => (float)$lng,
    'cached'      => false,
    '_cached_at'  => time(),
];

$_SESSION[$cacheKey] = $result;

$output = $result;
unset($output['_cached_at']);
echo json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);


// =============================================================================
// Helpers
// =============================================================================

function nominatimSearch(string $amenity, float $lat, float $lng): array
{
    $offset = SEARCH_RADIUS_KM / 111;
    $params = http_build_query([
        'amenity'        => $amenity,
        'format'         => 'json',
        'limit'          => 5,
        'lat'            => $lat,
        'lon'            => $lng,
        'countrycodes'   => 'ph',
        'addressdetails' => 1,
        'bounded'        => 1,
        'viewbox'        => implode(',', [
            round($lng - $offset, 4), round($lat + $offset, 4),
            round($lng + $offset, 4), round($lat - $offset, 4),
        ]),
    ]);
    $raw = curlGet(NOMINATIM_BASE . '?' . $params);
    if ($raw === false) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function getOsrmRoute(float $oLat, float $oLng, float $dLat, float $dLng): ?array
{
    $url = OSRM_BASE . "{$oLng},{$oLat};{$dLng},{$dLat}?overview=false&steps=false";
    $raw = curlGet($url);
    if ($raw === false) return null;
    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || ($data['code'] ?? '') !== 'Ok') return null;
    $route = $data['routes'][0] ?? null;
    if (!$route) return null;

    $m = (float)($route['distance'] ?? 0);
    $s = (float)($route['duration'] ?? 0);
    $distStr = $m < 1000 ? round($m) . ' m' : number_format($m / 1000, 1) . ' km';
    $min = (int) round($s / 60);
    if ($min < 1)      $dur = '< 1 min';
    elseif ($min < 60) $dur = $min . ' min';
    else { $h = (int)floor($min/60); $r = $min%60; $dur = $h.'hr'.($r?" {$r}min":''); }
    return ['distance' => $distStr, 'duration' => $dur];
}

function extractNameFromDisplay(string $d): string
{
    return $d ? trim(explode(',', $d)[0]) : 'Nearest Hospital';
}

function buildAddress(string $d, string $name): string
{
    if (!$d) return 'Address not available';
    $parts = array_map('trim', explode(',', $d));
    if ($parts[0] === $name) array_shift($parts);
    if (strtolower(end($parts)) === 'philippines') array_pop($parts);
    return implode(', ', $parts) ?: 'Address not available';
}

function estimateTravelTime(float $lat1, float $lng1, float $lat2, float $lng2): string
{
    $km  = haversineKm($lat1, $lng1, $lat2, $lng2);
    $min = (int) round(($km / 40) * 60);
    if ($min < 1)  return '< 1 min (est.)';
    if ($min < 60) return $min . ' min (est.)';
    $h = (int)floor($min/60); $m = $min%60;
    return $h . ' hr' . ($m ? " {$m} min" : '') . ' (est.)';
}

function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $R = 6371.0;
    $a = sin(deg2rad($lat2-$lat1)/2)**2
       + cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin(deg2rad($lon2-$lon1)/2)**2;
    return $R * 2 * atan2(sqrt($a), sqrt(1-$a));
}

function haversineFormatted(float $lat1, float $lon1, float $lat2, float $lon2): string
{
    $km = haversineKm($lat1, $lon1, $lat2, $lon2);
    return $km < 1 ? round($km*1000).' m' : number_format($km,1).' km';
}

function curlGet(string $url): string|false
{
    if (!function_exists('curl_init')) {
        $ctx = stream_context_create(['http' => [
            'timeout' => HTTP_TIMEOUT, 'ignore_errors' => true,
            'header'  => 'User-Agent: ' . USER_AGENT . "\r\n",
        ]]);
        return @file_get_contents($url, false, $ctx);
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => HTTP_TIMEOUT,
        // Verify SSL on live, skip on localhost (XAMPP self-signed cert)
        CURLOPT_SSL_VERIFYPEER => CURL_VERIFY_SSL,
        CURLOPT_SSL_VERIFYHOST => CURL_VERIFY_SSL ? 2 : 0,
        CURLOPT_USERAGENT      => USER_AGENT,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $result = curl_exec($ch);
    $error  = curl_error($ch);
    curl_close($ch);
    if ($result === false) error_log('[BagoReferral] cURL error: ' . $error . ' URL: ' . $url);
    return $result;
}

function jsonError(string $message, int $status = 400): never
{
    http_response_code($status);
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}
