<?php
/**
 * Accuracy edge-case probe — language robustness + WHO coverage.
 * Does not claim 100% precision; tracks regressions on hard cases.
 */
require __DIR__ . '/nlp_cli_bootstrap.php';

function ok(bool $c, string $l, string $d = ''): void
{
    echo ($c ? 'PASS  ' : 'FAIL  ') . $l . ($d !== '' ? " — {$d}" : '') . "\n";
    if (!$c) {
        $GLOBALS['fails'] = ($GLOBALS['fails'] ?? 0) + 1;
    }
}
$GLOBALS['fails'] = 0;

WhoIittTriageRulesLoader::resetCache();

echo "=== NLP accuracy edge cases ===\n";

// Fuzzy / slang normalization
$prep = ClinicalAnswerNormalizer::prepare('saket olo ko');
ok(str_contains(mb_strtolower($prep['corrected']), 'sakit') || str_contains(mb_strtolower($prep['corrected']), 'ulo'), 'normalize saket olo', $prep['corrected']);

$iv = ChiefComplaintNlpService::assessInterview('saket olo ko');
ok(empty($iv['domain_skipped']), 'slang headache not OOS');

// Tagalog meningism pair
$men = ClinicalTriageEngine::assess('sumasakit ang ulo ko at nilalagnat', 'sumasakit ang ulo ko at nilalagnat');
ok(($men['triage_display'] ?? '') === 'EMERGENCY', 'Tagalog headache+fever EMERGENCY', (string) ($men['triage_display'] ?? ''));

// Mixed language chest emergency
$mix = ClinicalTriageEngine::assess('masakit dibdib ko and I cannot breathe', 'masakit dibdib ko and I cannot breathe');
ok(($mix['triage_display'] ?? '') === 'EMERGENCY', 'mixed chest+breath EMERGENCY', (string) ($mix['triage_display'] ?? ''));

// Misspelled cough not emergency alone
$c = ClinicalTriageEngine::assess('may cought ako', 'may cought ako');
ok(($c['triage_display'] ?? '') !== 'EMERGENCY', 'misspelled cough not EMERGENCY', (string) ($c['triage_display'] ?? ''));

// Diarrhoea urgent
$d = ClinicalTriageEngine::assess('permi ako nagtatae', 'permi ako nagtatae');
ok(($d['triage_display'] ?? '') === 'URGENT', 'diarrhoea URGENT', (string) ($d['triage_display'] ?? ''));

// Burns urgent
$b = ClinicalTriageEngine::assess('nasunog ang akon kamot', 'nasunog ang akon kamot');
ok(($b['triage_display'] ?? '') === 'URGENT', 'burn URGENT', (string) ($b['triage_display'] ?? ''));

// Non-health still gated
$h = ChiefComplaintNlpService::assess('tell me a joke');
ok(!empty($h['domain_skipped']), 'joke one-shot OOS');

// Severity alone still not emergency
$s = ClinicalTriageEngine::assess('kasakit ulo 5/10 hinay-hinay wala iban', 'kasakit ulo 5/10 hinay-hinay wala iban');
ok(($s['triage_display'] ?? '') === 'NON-URGENT', '5/10 gradual NON-URGENT', (string) ($s['triage_display'] ?? ''));

// Interview recovers misspelled onset
$a = ChiefComplaintNlpService::assessInterview('sakit');
$a = ChiefComplaintNlpService::assessInterview('7', $a['interview'] ?? []);
$a = ChiefComplaintNlpService::assessInterview('ulo', $a['interview'] ?? []);
$a = ChiefComplaintNlpService::assessInterview('kagapong', $a['interview'] ?? []);
$facts = is_array($a['interview']['facts'] ?? null) ? $a['interview']['facts'] : [];
$onsetOk = ($facts['onset'] ?? '') !== '' || ($facts['duration_label'] ?? '') !== ''
    || str_contains(mb_strtolower((string) ($a['clinical_transcript'] ?? '')), 'gahapon');
ok($onsetOk, 'kagapong maps to onset/duration', json_encode([
    'onset' => $facts['onset'] ?? null,
    'duration' => $facts['duration_label'] ?? null,
]));

echo "\nFails: " . (int) ($GLOBALS['fails'] ?? 0) . "\n";
exit(($GLOBALS['fails'] ?? 0) > 0 ? 1 : 0);
