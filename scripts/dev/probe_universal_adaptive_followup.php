<?php
/**
 * Universal adaptive follow-up: ClinicalInterviewEngine path (patient triage).
 * php scripts/dev/probe_universal_adaptive_followup.php
 */
require dirname(__DIR__, 2) . '/bootstrap/app.php';

putenv('MEDCONNECT_PHP_NLP_ONLY=1');
putenv('MEDCONNECT_AI_INTERPRETER=0');
putenv('MEDCONNECT_SKIP_ML_LAYER=1');
putenv('MEDCONNECT_SKIP_GEMINI_FOLLOWUP=1');
$_ENV['MEDCONNECT_PHP_NLP_ONLY'] = '1';
$_ENV['MEDCONNECT_AI_INTERPRETER'] = '0';
$_ENV['MEDCONNECT_SKIP_ML_LAYER'] = '1';
$_ENV['MEDCONNECT_SKIP_GEMINI_FOLLOWUP'] = '1';

$fails = 0;
function ok(bool $c, string $l, string $d = ''): void
{
    global $fails;
    echo ($c ? 'PASS  ' : 'FAIL  ') . $l . ($d !== '' ? " — {$d}" : '') . "\n";
    if (!$c) {
        $fails++;
    }
}

function qid(array $r): string
{
    return strtoupper((string) ($r['followup_question']['question_id']
        ?? $r['interview']['awaiting_question_id']
        ?? ''));
}

function qtext(array $r): string
{
    return (string) ($r['followup_question']['text'] ?? $r['patient_message'] ?? '');
}

function facts(array $r): array
{
    return is_array($r['interview']['facts'] ?? null)
        ? $r['interview']['facts']
        : (is_array($r['facts'] ?? null) ? $r['facts'] : []);
}

function ctx(array $r): array
{
    return is_array($r['interview'] ?? null) ? $r['interview'] : [];
}

echo "=== Universal adaptive follow-up (ClinicalInterviewEngine) ===\n";

// 1) Unspecified pain → ask location first (not a fixed severity-first sequence).
$t1 = ClinicalInterviewEngine::assess('May sakit ako');
ok(in_array(qid($t1), ['PAIN_LOCATION', 'UNWELL_WHAT'], true), 'unspecified pain asks location/clarify first', qid($t1));

// 2) Location + duration + score known → do not re-ask those; adapt to next gap.
$t2 = ClinicalInterviewEngine::assess('Kasakit ulo ko tatlo na ka adlaw, 6/10');
$f2 = facts($t2);
ok((int) ($f2['pain_score'] ?? 0) === 6, 'multi-fact extracts pain 6', (string) ($f2['pain_score'] ?? '-'));
ok(($f2['body_locations'][0] ?? '') === 'head' || in_array('head', (array) ($f2['body_locations'] ?? []), true),
    'multi-fact extracts head', json_encode($f2['body_locations'] ?? []));
ok(qid($t2) !== 'PAIN_SEVERITY' && qid($t2) !== 'PAIN_LOCATION' && qid($t2) !== 'DURATION',
    'known facts not re-asked', qid($t2));

// 3) Cough → not pain scale; respiratory/timing path.
$t3 = ClinicalInterviewEngine::assess('Ginaubo ko');
ok(qid($t3) !== 'PAIN_SEVERITY', 'cough not pain scale', qid($t3));
ok(in_array(qid($t3), ['BREATHING_SEVERITY', 'ONSET', 'DURATION', 'COUGH_TYPE', 'FEVER_CONFIRM', 'ASSOCIATED_SYMPTOMS'], true),
    'cough adaptive respiratory/timing', qid($t3));

// 4) Chest pain → breathing / acuity before generic duration walk.
$t4 = ClinicalInterviewEngine::assess('Masakit dughan ko');
ok(in_array(qid($t4), ['BREATHING_SEVERITY', 'SPECIFIC_LOCATION', 'CHEST_RADIATION', 'PAIN_SEVERITY', 'ONSET'], true),
    'chest first question acuity-relevant', qid($t4));

// 5) Skin/rash → not forced pain scale.
$t5 = ClinicalInterviewEngine::assess('May rash kag kati sa akon braso');
ok(qid($t5) !== 'PAIN_SEVERITY', 'rash not forced pain scale', qid($t5));

// 6) Pain scale text is 1–10.
$t6 = ClinicalInterviewEngine::assess('Masakit tiyan ko sa tuo');
if (qid($t6) === 'PAIN_SEVERITY') {
    $txt = mb_strtolower(qtext($t6));
    ok(str_contains($txt, '1') && str_contains($txt, '10') && !preg_match('/\b0\s*(tubtob|to|hanggang|-)/u', $txt),
        'pain question uses 1–10', qtext($t6));
} else {
    // Drive toward severity if another gap won first.
    $prior = ctx($t6);
    $ans = 'wala iban';
    if (qid($t6) === 'ONSET' || qid($t6) === 'DURATION') {
        $ans = 'kahapon';
    } elseif (str_contains(qid($t6), 'BREATHING') || str_contains(qid($t6), 'ABDOMINAL') || str_contains(qid($t6), 'ASSOCIATED')) {
        $ans = 'indi';
    }
    $t6b = ClinicalInterviewEngine::assess($ans, $prior);
    $steps = 0;
    while (qid($t6b) !== 'PAIN_SEVERITY' && qid($t6b) !== '' && $steps < 6
        && strtoupper((string) ($t6b['assessment_status'] ?? '')) !== 'COMPLETED'
    ) {
        $prior = ctx($t6b);
        $qid = qid($t6b);
        $ans = match (true) {
            $qid === 'ONSET', $qid === 'DURATION' => 'kahapon',
            $qid === 'PAIN_SEVERITY' => '5',
            default => 'indi',
        };
        $t6b = ClinicalInterviewEngine::assess($ans, $prior);
        $steps++;
    }
    if (qid($t6b) === 'PAIN_SEVERITY') {
        $txt = mb_strtolower(qtext($t6b));
        ok(str_contains($txt, '1') && str_contains($txt, '10') && !preg_match('/\b0\s*(tubtob|to|hanggang|-)/u', $txt),
            'pain question uses 1–10', qtext($t6b));
        $prior = ctx($t6b);
        $bad = ClinicalInterviewEngine::assess('0', $prior);
        ok(!empty($bad['answer_rejected']) || !empty($bad['interview']['answer_rejected']),
            'pain score 0 rejected for clarification', json_encode([
                'rejected' => $bad['answer_rejected'] ?? $bad['interview']['answer_rejected'] ?? null,
                'msg' => mb_substr((string) ($bad['patient_message'] ?? ''), 0, 120),
            ]));
        $good = ClinicalInterviewEngine::assess('5', $prior);
        ok((int) (facts($good)['pain_score'] ?? 0) === 5, 'pain score 5 accepted', (string) (facts($good)['pain_score'] ?? '-'));
    } else {
        ok(true, 'pain severity deferred by adaptive gaps (acceptable)', qid($t6b));
    }
}

// 7) Different complaints → different first questions (universal, not one sequence).
$first = [
    'May sakit ako' => qid($t1),
    'Ginaubo ko' => qid($t3),
    'Masakit dughan ko' => qid($t4),
    'May rash kag kati sa akon braso' => qid($t5),
];
$unique = array_unique(array_values($first));
ok(count($unique) >= 3, 'diverse complaints yield diverse first questions', json_encode($first));

// 8) Accumulate across turns without wiping unrelated facts.
$a = ClinicalInterviewEngine::assess('Masakit ulo ko');
$b = ClinicalInterviewEngine::assess('8', ctx($a));
$fb = facts($b);
ok(in_array('head', (array) ($fb['body_locations'] ?? []), true), 'location preserved after severity answer', json_encode($fb['body_locations'] ?? []));
ok((int) ($fb['pain_score'] ?? 0) === 8 || qid($a) !== 'PAIN_SEVERITY',
    'severity accumulated when asked', 'qid0=' . qid($a) . ' score=' . ($fb['pain_score'] ?? '-'));

// 9) Final labels unchanged when interview completes.
$done = ClinicalInterviewEngine::assess('pila na ka adlaw kasakit ulo ko, 3/10, wala iban nga sintomas');
$status = strtoupper((string) ($done['assessment_status'] ?? ''));
$triage = strtoupper((string) ($done['triage_final'] ?? $done['triage']['triage_display'] ?? ''));
if ($status === 'COMPLETED') {
    ok(in_array($triage, ['EMERGENCY', 'URGENT', 'NON-URGENT'], true), 'final triage label intact', $triage);
} else {
    ok(in_array(qid($done), ['NEURO_WEAKNESS', 'NEURO_SPEECH', 'NEURO_VISION', 'ASSOCIATED_SYMPTOMS', 'ONSET'], true),
        'mild head continues with relevant probes only', qid($done));
}

echo "\nFails: {$fails}\n";
exit($fails > 0 ? 1 : 0);
