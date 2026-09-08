<?php
/**
 * Dataset expansion regression probe — 21 coverage areas.
 * Does not claim 100% precision. Architecture unchanged.
 */
require __DIR__ . '/nlp_cli_bootstrap.php';

function ok(bool $c, string $l, string $d = ''): void
{
    echo ($c ? 'PASS  ' : 'FAIL  ') . $l . ($d !== '' ? " — {$d}" : '') . "\n";
    if (!$c) {
        $GLOBALS['fails'] = ($GLOBALS['fails'] ?? 0) + 1;
    }
    $GLOBALS['total'] = ($GLOBALS['total'] ?? 0) + 1;
}
$GLOBALS['fails'] = 0;
$GLOBALS['total'] = 0;

WhoIittTriageRulesLoader::resetCache();
if (method_exists('MedicalMisspellingsLoader', 'map')) {
    // force reload if cached — clear via reflection if needed
}

echo "=== NLP dataset expansion probe ===\n";

// 1 Hiligaynon
$h = ChiefComplaintNlpService::assessInterview('kasakit ulo ko');
ok(empty($h['domain_skipped']), '1 Hiligaynon health', json_encode($h['domain']['domain'] ?? null));

// 2 Tagalog
$t = ChiefComplaintNlpService::assessInterview('sumasakit ang ulo ko');
ok(empty($t['domain_skipped']), '2 Tagalog health');

// 3 English
$e = ChiefComplaintNlpService::assessInterview('my head hurts');
ok(empty($e['domain_skipped']), '3 English health');

// 4 Mixed
$m = ChiefComplaintNlpService::assessInterview('masakit ulo ko since yesterday');
ok(empty($m['domain_skipped']), '4 Mixed language health');

// 5 Slang / colloquial
$s = ClinicalAnswerNormalizer::prepare('saket olo ko');
ok(
    str_contains(mb_strtolower($s['corrected']), 'sakit')
    || str_contains(mb_strtolower($s['corrected']), 'ulo')
    || str_contains(mb_strtolower($s['corrected']), 'head'),
    '5 slang normalize',
    $s['corrected']
);

// 6 Misspellings
$ms = ClinicalAnswerNormalizer::prepare('headech');
ok(str_contains(mb_strtolower($ms['corrected']), 'head'), '6 misspelling headech', $ms['corrected']);

// 7 Abbreviations via misspell map
$map = MedicalMisspellingsLoader::map();
ok(isset($map['sob']) || isset($map['ha']), '7 abbreviation map present');

// 8 Multiple complaints → not auto-emergency
$multi = ClinicalTriageEngine::assess('kasakit ulo ko kag tiyan ko', 'kasakit ulo ko kag tiyan ko');
ok(($multi['triage_display'] ?? '') !== 'EMERGENCY', '8 multi complaint not auto-EMERGENCY', (string) ($multi['triage_display'] ?? ''));

// 9 Duration
$dur = ClinicalFeatureExtractors::extractDuration('tatlo na ka bulan kasakit mata ko');
$durLabel = (string) (($dur['label'] ?? '') ?: ($dur['bucket'] ?? ''));
$durDays = (int) ($dur['days'] ?? 0);
ok($durDays >= 30 || str_contains(mb_strtolower($durLabel), 'month') || str_contains(mb_strtolower($durLabel), 'chronic'), '9 duration 3 months', $durLabel . '/' . $durDays);

// 10 Onset / timing words
$on = ClinicalAnswerNormalizer::prepare('gahapon pa');
ok(str_contains(mb_strtolower($on['corrected']), 'gahapon') || str_contains(mb_strtolower($on['corrected']), 'yesterday'), '10 onset gahapon', $on['corrected']);

// 11 Severity mapped but not triage alone
$sev = ClinicalTriageEngine::assess('sakit gid ulo 5/10 hinay-hinay wala iban', 'sakit gid ulo 5/10 hinay-hinay wala iban');
ok(($sev['triage_display'] ?? '') === 'NON-URGENT', '11 severity alone not EMERGENCY', (string) ($sev['triage_display'] ?? ''));

// 12 Negation
$neg = ClinicalTriageEngine::assess('sakit ulo wala ko hilanat', 'sakit ulo wala ko hilanat');
$negConcepts = $neg['negated_concepts'] ?? [];
$hasFeverNeg = false;
foreach ((array) $negConcepts as $c) {
    if (is_string($c) && str_contains(mb_strtolower($c), 'fever')) {
        $hasFeverNeg = true;
    }
    if (is_array($c) && str_contains(mb_strtolower(json_encode($c)), 'fever')) {
        $hasFeverNeg = true;
    }
}
$rf = implode(' ', array_map('strval', (array) ($neg['red_flags'] ?? [])));
ok($hasFeverNeg || !preg_match('/\bfever\b/i', $rf), '12 negation fever not red-flagged', json_encode($negConcepts));

// 13 Body location
$iv = ChiefComplaintNlpService::assessInterview('kasakit mata ko');
$facts = is_array($iv['interview']['facts'] ?? null) ? $iv['interview']['facts'] : [];
$locs = array_map('strtolower', (array) ($facts['body_locations'] ?? []));
$tx = mb_strtolower((string) ($iv['clinical_transcript'] ?? $iv['interview']['transcript'] ?? ''));
ok(in_array('eye', $locs, true) || str_contains($tx, 'mata') || str_contains($tx, 'eye'), '13 body location eye', json_encode($locs));

// 14 Specific body location phrase recognized as health
$spec = HealthComplaintDomainDetector::detect('kasakit tiyan ko sa tuo nga idalom');
ok(!empty($spec['health_related']), '14 specific abdomen location health', (string) ($spec['domain'] ?? ''));

// 15 Associated multi-symptom still health
$as = HealthComplaintDomainDetector::detect('ubo kag hilanat ko');
ok(!empty($as['health_related']), '15 associated cough+fever health');

// 16 Unrelated OOS
$oos = HealthComplaintDomainDetector::detect('what is the weather');
ok(($oos['domain'] ?? '') === HealthComplaintDomainDetector::DOMAIN_OOS
    || ($oos['routing'] ?? '') === HealthComplaintDomainDetector::ROUTE_OOS
    || empty($oos['health_related']), '16 weather OOS', (string) ($oos['domain'] ?? ''));

// 17 Nonsense not forced health
$ns = HealthComplaintDomainDetector::detect('asdfghjkl qwerty zxcvbn');
ok(empty($ns['health_related']) || ($ns['domain'] ?? '') !== HealthComplaintDomainDetector::DOMAIN_HEALTH, '17 nonsense not HEALTH', (string) ($ns['domain'] ?? ''));

// 18 Previously provided duration should skip re-ask when interview progresses
$a = ChiefComplaintNlpService::assessInterview('tatlo na ka bulan kasakit mata ko');
$qid = strtoupper((string) ($a['interview']['next_question']['question_id'] ?? $a['followup_question']['question_id'] ?? ''));
ok($qid !== 'DURATION' && $qid !== 'ONSET', '18 duration already provided not re-asked first', $qid !== '' ? $qid : 'none');

// 19 Emergency red flag
$em = ClinicalTriageEngine::assess('indi ko makaginhawa', 'indi ko makaginhawa');
ok(($em['triage_display'] ?? '') === 'EMERGENCY', '19 emergency breath', (string) ($em['triage_display'] ?? ''));

// 20 Urgent finding
$ur = ClinicalTriageEngine::assess('masakit dughan', 'masakit dughan');
ok(($ur['triage_display'] ?? '') === 'URGENT', '20 urgent chest', (string) ($ur['triage_display'] ?? ''));

// 21 Non-urgent finding
$nu = ClinicalTriageEngine::assess('may cought ako gamay lang', 'may cought ako gamay lang');
ok(($nu['triage_display'] ?? '') === 'NON-URGENT' || ($nu['triage_display'] ?? '') === 'URGENT', '21 mild cough not invented CRITICAL', (string) ($nu['triage_display'] ?? ''));
ok(($nu['triage_display'] ?? '') !== 'CRITICAL' && ($nu['triage_display'] ?? '') !== 'UNKNOWN', '21 no fourth triage class', (string) ($nu['triage_display'] ?? ''));

// Expression variant file loaded
ok(is_readable(BASE_PATH . '/data/nlp/symptom_expression_variants.csv'), 'variant dataset present');
ok(is_readable(BASE_PATH . '/data/nlp/associated_symptom_hints.csv'), 'assoc hints dataset present');

echo "\nTotal: " . (int) $GLOBALS['total'] . "  Pass: " . ((int) $GLOBALS['total'] - (int) $GLOBALS['fails']) . "  Fail: " . (int) $GLOBALS['fails'] . "\n";
exit(($GLOBALS['fails'] ?? 0) > 0 ? 1 : 0);
