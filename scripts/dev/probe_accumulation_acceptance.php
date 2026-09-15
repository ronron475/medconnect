<?php
/**
 * Final multi-turn accumulation acceptance checks.
 */
require __DIR__ . '/nlp_cli_bootstrap.php';
SymptomKnowledgeBase::clearCache();
NegationDetector::clearCache();
WhoIittTriageRulesLoader::resetCache();

function ok(bool $cond, string $label, string $detail = ''): void
{
    echo ($cond ? 'PASS' : 'FAIL') . " | {$label}" . ($detail !== '' ? " | {$detail}" : '') . "\n";
}

echo "=== single-shot engine ===\n";
$h = ClinicalTriageEngine::assess('Masakit gid ulo ko.', 'Masakit gid ulo ko.');
ok(in_array('Headache', $h['detected_symptoms'] ?? [], true), 'headache detected', json_encode($h['detected_symptoms'] ?? []));

$v = ClinicalTriageEngine::assess('Ga suka ko.', 'Ga suka ko.');
ok(in_array('Vomiting', $v['detected_symptoms'] ?? [], true), 'ga suka → vomiting', json_encode($v['detected_symptoms'] ?? []));

$g = ClinicalTriageEngine::assess('permi galupot akon tiyan', 'permi galupot akon tiyan');
ok(in_array('Diarrhea', $g['detected_symptoms'] ?? [], true), 'galupot → diarrhea', json_encode($g['detected_symptoms'] ?? []));

$n = ClinicalTriageEngine::assess('May ubo ko. wala ko ubo', 'May ubo ko. wala ko ubo');
$nSym = array_map('strtolower', $n['detected_symptoms'] ?? []);
$hasCough = false;
foreach ($nSym as $s) {
    if (str_contains($s, 'cough') || $s === 'ubo') {
        $hasCough = true;
    }
}
ok(!$hasCough, 'negated cough removed', json_encode($n['detected_symptoms'] ?? []) . ' neg=' . json_encode($n['negated_concepts'] ?? []));

$r = ClinicalTriageEngine::assess(
    'sakit ulo ko. wala ko difficulty breathing',
    'sakit ulo ko. wala ko difficulty breathing'
);
ok(($r['triage_display'] ?? '') !== 'EMERGENCY', 'negated dyspnea not EMERGENCY', (string) ($r['triage_display'] ?? '?') . ' flags=' . json_encode($r['red_flags'] ?? []));

$r2 = ClinicalTriageEngine::assess(
    'sakit ulo ko. wala ko budlay ginhawa',
    'sakit ulo ko. wala ko budlay ginhawa'
);
ok(($r2['triage_display'] ?? '') !== 'EMERGENCY', 'negated budlay ginhawa not EMERGENCY', (string) ($r2['triage_display'] ?? '?'));

echo "=== multi-turn accumulation ===\n";
$a = ChiefComplaintNlpService::assessInterview('Masakit gid ulo ko.');
$ctx = $a['interview'] ?? [];
$b = ChiefComplaintNlpService::assessInterview('8/10', $ctx);
$ctx = $b['interview'] ?? [];
$c = ChiefComplaintNlpService::assessInterview('Ga suka ko.', $ctx);
$ctx = $c['interview'] ?? [];
$d = ChiefComplaintNlpService::assessInterview('Nag gulpi kag grabe ang pagsugod.', $ctx);
$facts = $d['interview']['facts'] ?? [];
ok((int) ($facts['pain_score'] ?? 0) === 8, 'pain 8 accumulated', (string) ($facts['pain_score'] ?? '-'));
ok(($facts['onset'] ?? '') === 'sudden', 'sudden onset accumulated', (string) ($facts['onset'] ?? '-'));
$assoc = array_map('strtolower', (array) ($facts['associated_symptoms'] ?? []));
$syms = array_map('strtolower', (array) ($facts['symptoms'] ?? []));
$hasVomit = in_array('vomiting', $assoc, true) || in_array('vomiting', $syms, true);
ok($hasVomit, 'vomiting accumulated from follow-up', 'assoc=' . json_encode($facts['associated_symptoms'] ?? []) . ' syms=' . json_encode($facts['symptoms'] ?? []));
ok(str_contains(strtolower(implode(' ', $syms)), 'headache') || str_contains(strtolower(implode(' ', $syms)), 'head'), 'headache preserved', json_encode($facts['symptoms'] ?? []));

echo "=== uncertain follow-up ===\n";
$u1 = ChiefComplaintNlpService::assessInterview('sakit tiyan ko');
$ctxu = $u1['interview'] ?? [];
$u2 = ChiefComplaintNlpService::assessInterview('Ambot', $ctxu);
$fu = $u2['interview']['facts'] ?? [];
ok(!empty($fu['patient_uncertain']), 'ambot → uncertain', json_encode($fu['patient_uncertain'] ?? null));
$neg = array_map('strtolower', (array) ($fu['negative_symptoms'] ?? []));
ok(!in_array('uncertain', $neg, true), 'ambot not stored as clinical negation', json_encode($fu['negative_symptoms'] ?? []));

echo "=== later red-flag follow-up ===\n";
$e1 = ChiefComplaintNlpService::assessInterview('masakit ulo ko');
$ctxe = $e1['interview'] ?? [];
// answer pain so interview continues
$e2 = ChiefComplaintNlpService::assessInterview('4', $ctxe);
$ctxe = $e2['interview'] ?? [];
$e3 = ChiefComplaintNlpService::assessInterview('indi ko makaginhawa', $ctxe);
$disp = (string) ($e3['triage']['triage_display'] ?? $e3['triage_display'] ?? '');
$status = (string) ($e3['assessment_status'] ?? '');
ok($disp === 'EMERGENCY' || $status === 'COMPLETED' || !empty($e3['interview']['facts']['breathing_difficulty']) || !empty($e3['interview']['facts']['red_flags']), 'later breathing red flag absorbed', "display={$disp} status={$status} flags=" . json_encode($e3['interview']['facts']['red_flags'] ?? []));

echo "=== isolation vs complete case ===\n";
$alone = ClinicalTriageEngine::assess('Nag gulpi kag grabe ang pagsugod.', 'Nag gulpi kag grabe ang pagsugod.');
$full = 'Masakit gid ulo ko. pain 8/10. Ga suka ko. sudden onset. vomiting. headache';
$acc = ClinicalTriageEngine::assess($full, $full);
ok(($alone['triage_display'] ?? '') === 'NON-URGENT', 'last answer alone NON-URGENT', (string) ($alone['triage_display'] ?? '?'));
ok(in_array(($acc['triage_display'] ?? ''), ['URGENT', 'EMERGENCY'], true), 'complete case escalates', (string) ($acc['triage_display'] ?? '?'));
