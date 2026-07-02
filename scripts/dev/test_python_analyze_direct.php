<?php
$payload = json_encode([
    'allergies' => 'walay',
    'current_medications' => 'masakit ulo ko',
]);
$start = microtime(true);
$ch = curl_init('http://127.0.0.1:8765/analyze-medical-profile');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 130,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => $payload,
]);
$body = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$elapsed = round(microtime(true) - $start, 2);
echo "http=$code elapsed={$elapsed}s\n";
$j = json_decode((string) $body, true);
$d = $j['data'] ?? $j ?? [];
echo 'engine=' . ($d['engine'] ?? 'n/a') . "\n";
echo 'service=' . json_encode($j['success'] ?? null) . "\n";
