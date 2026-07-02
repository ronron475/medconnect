<?php
$_POST = [
    'allergies'            => 'walay',
    'existing_conditions'  => 'masakit ulo ko',
];
$_SERVER['REQUEST_METHOD'] = 'POST';

ob_start();
include dirname(__DIR__, 2) . '/app/api/ai/analyze_medical_profile.php';
$out = ob_get_clean();

$j = json_decode($out, true);
if (!is_array($j)) {
    fwrite(STDERR, "Invalid JSON:\n" . substr($out, 0, 800) . "\n");
    exit(1);
}

$d = $j['data'] ?? [];
echo 'success=' . (!empty($j['success']) ? 'yes' : 'no') . PHP_EOL;
echo 'engine=' . ($d['engine'] ?? 'n/a') . PHP_EOL;
echo 'service_used=' . (!empty($d['service_used']) ? 'yes' : 'no') . PHP_EOL;
echo 'groq=' . json_encode($d['pipeline_diagnostics']['groq'] ?? null, JSON_UNESCAPED_SLASHES) . PHP_EOL;
echo 'message=' . ($j['message'] ?? '') . PHP_EOL;

exit(!empty($d['service_used']) && ($d['engine'] ?? '') === 'python-medical-profile-nlp' ? 0 : 2);
