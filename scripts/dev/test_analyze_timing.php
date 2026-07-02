<?php
$payload = json_encode([
    'allergies' => 'walay',
    'current_medications' => 'masakit ulo ko',
]);

function timed(string $label, callable $fn) {
    $start = microtime(true);
    $result = $fn();
    $elapsed = round(microtime(true) - $start, 2);
    echo "{$label}: {$elapsed}s\n";
    return $result;
}

$ch = curl_init('http://127.0.0.1:8765/analyze-medical-profile');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 130,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => $payload,
]);
$start = microtime(true);
$body = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$elapsed = round(microtime(true) - $start, 2);
echo "total_http={$elapsed}s code={$code}\n";
$j = json_decode((string) $body, true);
$d = $j['data'] ?? [];
$t = $d['translation'] ?? [];
$ai = $t['ai_interpretation'] ?? [];
echo 'conditions_provider=' . ($ai['conditions']['provider'] ?? 'n/a') . "\n";
echo 'allergies_provider=' . ($ai['allergies']['provider'] ?? 'n/a') . "\n";
echo 'conditions_groq_skipped=' . json_encode($ai['conditions']['groq_skipped'] ?? null) . "\n";
echo 'allergies_groq_skipped=' . json_encode($ai['allergies']['groq_skipped'] ?? null) . "\n";
