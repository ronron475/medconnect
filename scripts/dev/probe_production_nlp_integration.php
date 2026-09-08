<?php
/**
 * Production NLP integration smoke tests (ChiefComplaintNlpService path).
 * Covers domain gate, adaptive interview, WHO triage classes.
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

function interview(string $text, array $prior = []): array
{
    return ChiefComplaintNlpService::assessInterview($text, $prior, []);
}

function oneshot(string $text): array
{
    // Use ClinicalTriageEngine directly (same CDS core as MedicalAssessmentEngine)
    // to avoid optional Python service latency in smoke tests.
    $raw = ClinicalTriageEngine::assess($text, $text);
    $display = (string) ($raw['triage_display'] ?? 'NON-URGENT');

    return [
        'triage' => [
            'triage_display' => $display,
            'triage_classification' => (string) ($raw['triage_classification'] ?? ''),
            'assessment_factors' => $raw['assessment_factors'] ?? [],
        ],
        'assessment_status' => 'COMPLETED',
    ];
}

function display(array $a): string
{
    $d = strtoupper(str_replace('_', '-', (string) ($a['triage']['triage_display'] ?? '')));
    if ($d === 'NON URGENT') {
        $d = 'NON-URGENT';
    }

    return $d;
}

echo "=== Production NLP integration (15 scenarios) ===\n";

// 1 Hiligaynon medical
$h = interview('kasakit ulo ko kag may lagnat');
ok(empty($h['domain_skipped']), '1 hiligaynon not OOS');
ok(display($h) === 'EMERGENCY' || ($h['assessment_status'] ?? '') === 'IN_PROGRESS', '1 hiligaynon emergency or follow-up', display($h) . '/' . ($h['assessment_status'] ?? ''));

// 2 Tagalog
$t = interview('sumasakit ang tiyan ko');
ok(empty($t['domain_skipped']), '2 tagalog health');
ok(($t['assessment_status'] ?? '') === 'IN_PROGRESS' || in_array(display($t), ['NON-URGENT', 'URGENT', 'EMERGENCY'], true), '2 tagalog progresses');

// 3 English
$e = interview('I have a mild headache for two days, 4/10, gradual, no other symptoms');
ok(empty($e['domain_skipped']), '3 english health');
$ed = display($e);
ok($ed === 'NON-URGENT' || ($e['assessment_status'] ?? '') === 'IN_PROGRESS', '3 english not auto-emergency', $ed);

// 4 Mixed
$m = interview('masakit gid akon eye for three days');
ok(empty($m['domain_skipped']), '4 mixed health');

// 5 Misspell/slang
$s = interview('saket olo ko');
ok(empty($s['domain_skipped']), '5 slang health');

// 6 Non-health
$o = interview('hello');
ok(!empty($o['domain_skipped']) || display($o) === '', '6 hello skipped triage', (string) ($o['domain_skipped'] ?? '0') . '/' . display($o));
$o2 = interview('what is the weather');
ok(!empty($o2['domain_skipped']) || ($o2['domain_detection']['health_related'] ?? true) === false, '6 weather not health');
$o3 = ChiefComplaintNlpService::assess('hello', []);
ok(!empty($o3['domain_skipped']) || !empty($o3['needs_valid_complaint']), '6 one-shot assess domain gate', (string) ($o3['domain_skipped'] ?? '0'));
ok(
    display($o3) === '' && !empty($o3['needs_valid_complaint']),
    '6 one-shot non-health has no triage class',
    display($o3) . '/' . (string) ($o3['assessment_status'] ?? '')
);

// Fast PHP path: MedicalAssessmentEngine must not hang on ML
$t0 = microtime(true);
$fast = ChiefComplaintNlpService::assess('may ubo ko kag sip-on', []);
$elapsed = microtime(true) - $t0;
ok($elapsed < 10.0, 'PHP assess finishes quickly', round($elapsed, 2) . 's');
ok(!empty($fast['cds_fast_path']) || ($fast['engine'] ?? '') === 'clinical-triage-engine-fast-cds', 'uses fast CDS path', (string) ($fast['engine'] ?? ''));
ok(in_array(display($fast), ['NON-URGENT', 'URGENT', 'EMERGENCY'], true), 'fast assess class ok', display($fast));

// 7 Duration present
$d = interview('kasakit ulo ko pila na ka adlaw');
$qid = (string) (($d['followup_question']['question_id'] ?? '') ?: ($d['interview']['awaiting_question_id'] ?? ''));
ok($qid !== 'ONSET' && $qid !== 'DURATION', '7 duration not re-ask timing', $qid);

// 8 Severity present
$sev = interview('sakit');
ok(($sev['followup_question']['question_id'] ?? '') === 'PAIN_SEVERITY', '8 vague asks severity', (string) ($sev['followup_question']['question_id'] ?? ''));
$sev2 = interview('7', $sev['interview'] ?? []);
ok(($sev2['followup_question']['question_id'] ?? '') !== 'PAIN_SEVERITY', '8 severity not re-asked', (string) ($sev2['followup_question']['question_id'] ?? ''));

// 9 Already-provided clinical info
$rich = interview('Masakit akon ulo 5/10 halin gahapon, daw nagapulsar, may hilo pero wala pagsuka kag wala hilanat.');
ok(
    ($rich['assessment_status'] ?? '') === 'COMPLETED'
    || !in_array(($rich['followup_question']['question_id'] ?? ''), ['PAIN_SEVERITY', 'PAIN_LOCATION', 'ONSET'], true),
    '9 skips answered cores',
    ($rich['assessment_status'] ?? '') . '/' . ($rich['followup_question']['question_id'] ?? '')
);

// 10 Needs additional question
$need = interview('sakit akon tiyan');
ok(($need['assessment_status'] ?? '') === 'IN_PROGRESS', '10 needs follow-up');
ok(($need['followup_question']['question_id'] ?? '') !== '', '10 has question');

// 11 Emergency red flag
$er = oneshot('Masakit dughan ko kag budlay magginhawa');
ok(display($er) === 'EMERGENCY', '11 emergency chest+dyspnea', display($er));

// 12 Urgent
$ur = oneshot('Masakit dughan ko');
ok(display($ur) === 'URGENT', '12 isolated chest urgent', display($ur));

// 13 Non-urgent
$nu = oneshot('kasakit ulo ko, pila na ka adlaw, 5/10, hinay-hinay, wala iban nga sintomas');
ok(display($nu) === 'NON-URGENT', '13 mild head non-urgent', display($nu));

// 14 Gemini fallback — interview still works with PHP path (phrasing may skip Gemini)
$g = interview('sakit', [], []);
ok(($g['followup_question']['text'] ?? '') !== '', '14 follow-up text present without requiring Gemini');

// 15 Gemini unavailable — force PHP NLP only path via one-shot
$php = oneshot('may ubo ko kag sip-on');
$pd = display($php);
ok(in_array($pd, ['NON-URGENT', 'URGENT', 'EMERGENCY'], true), '15 one-shot still classifies', $pd);
ok(!in_array($pd, ['CRITICAL', 'ROUTINE', 'HIGH', 'LOW'], true), '15 no fourth display class', $pd);

// WHO / class invariant on one-shot
ok(class_exists('WhoIittTriageRulesLoader'), 'WHO loader present');
ok(class_exists('ClinicalInterviewAdaptivePolicy'), 'adaptive policy present');
ok(class_exists('HealthComplaintDomainDetector'), 'domain detector present');

echo "\nFails: " . (int) ($GLOBALS['fails'] ?? 0) . "\n";
exit(($GLOBALS['fails'] ?? 0) > 0 ? 1 : 0);
