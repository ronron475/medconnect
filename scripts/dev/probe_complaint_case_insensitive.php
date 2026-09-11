<?php
/**
 * Smoke: primary complaint case variants must share the same match key / triage path.
 */
declare(strict_types=1);

require __DIR__ . '/nlp_cli_bootstrap.php';

$variants = [
    'sakit ulo',
    'Sakit Ulo',
    'SAKIT ULO',
    'SaKiT UlO',
];

$keys = [];
$complaints = [];
$displays = [];

foreach ($variants as $v) {
    $key = HiligaynonTextNormalizer::forMatch($v);
    $keys[$v] = $key;
    $a = ChiefComplaintNlpService::assess($v, []);
    $complaints[$v] = (string) ($a['chief_complaint'] ?? '');
    $displays[$v] = strtoupper((string) ($a['triage']['triage_display'] ?? $a['assessment_status'] ?? ''));
    echo sprintf(
        "[%s]\n  forMatch=%s\n  stored=%s\n  triage=%s\n  equals(sakit ulo)=%s\n\n",
        $v,
        $key,
        $complaints[$v],
        $displays[$v],
        HiligaynonTextNormalizer::equals($v, 'sakit ulo') ? 'yes' : 'no'
    );
}

$uniqueKeys = array_values(array_unique(array_values($keys)));
$okKeys = count($uniqueKeys) === 1 && $uniqueKeys[0] === 'sakit ulo';
$okStore = true;
foreach ($variants as $v) {
    if ($complaints[$v] !== $v) {
        $okStore = false;
        echo "FAIL store casing for [$v] got [{$complaints[$v]}]\n";
    }
}
$uniqueDisplay = array_values(array_unique(array_values($displays)));
$okDisplay = count($uniqueDisplay) === 1;

echo $okKeys ? "PASS match keys identical\n" : "FAIL match keys diverge: " . json_encode($uniqueKeys) . "\n";
echo $okStore ? "PASS original casing preserved\n" : "FAIL original casing not preserved\n";
echo $okDisplay ? "PASS triage/status identical ({$uniqueDisplay[0]})\n" : "FAIL triage diverge: " . json_encode($uniqueDisplay) . "\n";

$extra = [
    'headache' => 'HEADACHE',
    'HEADACHE' => 'HeAdAcHe',
    'masakit ang ulo' => 'MASAKIT ANG ULO',
];
foreach ($extra as $a => $b) {
    $same = HiligaynonTextNormalizer::equals($a, $b);
    echo ($same ? 'PASS' : 'FAIL') . " equals($a, $b)\n";
}

exit(($okKeys && $okStore && $okDisplay) ? 0 : 1);
