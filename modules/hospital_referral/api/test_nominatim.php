<?php
$ch = curl_init('https://nominatim.openstreetmap.org/search?amenity=hospital&format=json&limit=3&lat=10.5333&lon=122.9333&countrycodes=ph');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 8,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_USERAGENT      => 'BagoCityHealthReferral/1.0',
]);
$res = curl_exec($ch);
$err = curl_error($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
header('Content-Type: application/json');
echo $res === false ? json_encode(['error' => $err]) : "HTTP $code: " . substr($res, 0, 500);
