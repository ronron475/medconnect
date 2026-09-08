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
ok(str_contains(mb_strtolower($prep['corrected']), 'sakit') || str_contains(mb_strtolower($prep['corrected']), 'ulo') || str_contains(mb_strtolower($prep['corrected']), 'head'), 'normalize saket olo', $prep['corrected']);

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

// Round-2 training: Tagalog / SMS variants of existing WHO criteria
$breath = ClinicalTriageEngine::assess('hirap na ako huminga', 'hirap na ako huminga');
ok(($breath['triage_display'] ?? '') === 'EMERGENCY', 'Tagalog breath distress EMERGENCY', (string) ($breath['triage_display'] ?? ''));

$breath2 = ClinicalTriageEngine::assess('di ako makahinga', 'di ako makahinga');
ok(($breath2['triage_display'] ?? '') === 'EMERGENCY', 'di makahinga EMERGENCY', (string) ($breath2['triage_display'] ?? ''));

$snake = ClinicalTriageEngine::assess('nakagat ako ng ahas', 'nakagat ako ng ahas');
ok(($snake['triage_display'] ?? '') === 'EMERGENCY', 'Tagalog snake bite EMERGENCY', (string) ($snake['triage_display'] ?? ''));

$focal = ClinicalTriageEngine::assess('nanlalata ang kaliwang kamot ko', 'nanlalata ang kaliwang kamot ko');
ok(($focal['triage_display'] ?? '') === 'URGENT', 'Tagalog focal weakness URGENT', (string) ($focal['triage_display'] ?? ''));

$vom = ClinicalTriageEngine::assess('nagsusuka ako lagi lahat', 'nagsusuka ako lagi lahat');
ok(($vom['triage_display'] ?? '') === 'URGENT', 'Tagalog persistent vomit URGENT', (string) ($vom['triage_display'] ?? ''));

$sms = ClinicalTriageEngine::assess('msk dughan', 'msk dughan');
ok(($sms['triage_display'] ?? '') === 'URGENT', 'SMS msk dughan URGENT', (string) ($sms['triage_display'] ?? ''));

$unresp = ClinicalTriageEngine::assess('walang malay', 'walang malay');
ok(($unresp['triage_display'] ?? '') === 'EMERGENCY', 'walang malay EMERGENCY', (string) ($unresp['triage_display'] ?? ''));

$prep2 = ClinicalAnswerNormalizer::prepare('msk tyan kagapong');
ok(
    str_contains(mb_strtolower($prep2['corrected']), 'tiyan')
    || str_contains(mb_strtolower($prep2['corrected']), 'masakit')
    || str_contains(mb_strtolower($prep2['corrected']), 'gahapon'),
    'normalize SMS msk tyan kagapong',
    $prep2['corrected']
);

// Round-3 training: severe pain score, unable to eat, SMS meningism, burn synonym
$sev = ClinicalTriageEngine::assess('gasakit olo 9/10', 'gasakit olo 9/10');
ok(($sev['triage_display'] ?? '') === 'URGENT', '9/10 severe pain URGENT', (string) ($sev['triage_display'] ?? ''));

$eat = ClinicalTriageEngine::assess('hindi ako makakain', 'hindi ako makakain');
ok(($eat['triage_display'] ?? '') === 'URGENT', 'unable to eat URGENT', (string) ($eat['triage_display'] ?? ''));

$sev2 = ClinicalTriageEngine::assess('sobrang sakit ng tiyan ko', 'sobrang sakit ng tiyan ko');
ok(($sev2['triage_display'] ?? '') === 'URGENT', 'sobrang sakit URGENT', (string) ($sev2['triage_display'] ?? ''));

$men2 = ClinicalTriageEngine::assess('msk ulo kag lgnt', 'msk ulo kag lgnt');
ok(($men2['triage_display'] ?? '') === 'EMERGENCY', 'SMS head+fever EMERGENCY', (string) ($men2['triage_display'] ?? ''));

$burn2 = ClinicalTriageEngine::assess('napaso ang kamay ko', 'napaso ang kamay ko');
ok(($burn2['triage_display'] ?? '') === 'URGENT', 'napaso burn URGENT', (string) ($burn2['triage_display'] ?? ''));

$bleed = ClinicalTriageEngine::assess('tuloy pa ang dugo', 'tuloy pa ang dugo');
ok(($bleed['triage_display'] ?? '') === 'URGENT', 'ongoing bleed URGENT', (string) ($bleed['triage_display'] ?? ''));

echo "\nFails: " . (int) ($GLOBALS['fails'] ?? 0) . "\n";
exit(($GLOBALS['fails'] ?? 0) > 0 ? 1 : 0);
