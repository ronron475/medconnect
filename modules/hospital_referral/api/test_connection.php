<?php
// Quick connectivity test — delete this file after testing
header('Content-Type: application/json');

$tests = [];

// Test 1: allow_url_fopen
$tests['allow_url_fopen'] = (bool) ini_get('allow_url_fopen');

// Test 2: cURL available
$tests['curl_enabled'] = function_exists('curl_init');

// Test 3: Reach Overpass API
$overpassUrl = 'https://overpass-api.de/api/interpreter?data=' . rawurlencode('[out:json][timeout:5];node["amenity"="hospital"](around:5000,10.5333,122.9333);out 1;');

if (function_exists('curl_init')) {
    $ch = curl_init($overpassUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'BagoCityTest/1.0',
    ]);
    $res  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $tests['overpass_curl'] = $res !== false ? "OK (HTTP $code, " . strlen($res) . " bytes)" : "FAILED: $err";
} else {
    $tests['overpass_curl'] = 'curl not available';
}

// Test 4: Reach OSRM
if (function_exists('curl_init')) {
    $ch = curl_init('https://router.project-osrm.org/route/v1/driving/122.93,10.53;122.95,10.55?overview=false');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'BagoCityTest/1.0',
    ]);
    $res  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $tests['osrm_curl'] = $res !== false ? "OK (HTTP $code)" : "FAILED: $err";
}

echo json_encode($tests, JSON_PRETTY_PRINT);
