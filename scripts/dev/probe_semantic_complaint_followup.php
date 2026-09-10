<?php
/**
 * Probe: primary complaint health/prank gate + follow-up answer classes.
 * Uses Gemini overrides for deterministic complaint cases; follow-up is PHP-local.
 *
 * Usage: php scripts/dev/probe_semantic_complaint_followup.php
 */
declare(strict_types=1);

// Keep this probe offline / deterministic (overrides + local follow-up rules).
putenv('MEDCONNECT_SKIP_GEMINI_VALIDATION=1');
$_ENV['MEDCONNECT_SKIP_GEMINI_VALIDATION'] = '1';

require __DIR__ . '/nlp_cli_bootstrap.php';

$fails = 0;
function ok(bool $c, string $l, string $d = ''): void
{
    global $fails;
    echo ($c ? 'PASS  ' : 'FAIL  ') . $l . ($d !== '' ? " — {$d}" : '') . "\n";
    if (!$c) {
        $fails++;
    }
}

/** @return array<string, mixed> */
function geminiHealth(?string $concept = null, float $conf = 0.9): array
{
    return [
        'available' => true,
        'is_medical_complaint' => true,
        'classification' => 'HEALTH_RELATED',
        'confidence' => $conf,
        'error' => '',
        'corrected_text' => $concept ? 'abdominal discomfort' : '',
        'medical_concept' => $concept ?? '',
    ];
}

/** @return array<string, mixed> */
function geminiUnclear(float $conf = 0.4): array
{
    return [
        'available' => true,
        'is_medical_complaint' => null,
        'classification' => 'UNCLEAR',
        'confidence' => $conf,
        'error' => '',
        'corrected_text' => '',
        'medical_concept' => '',
    ];
}

/** @return array<string, mixed> */
function geminiPrank(float $conf = 0.95): array
{
    return [
        'available' => true,
        'is_medical_complaint' => false,
        'classification' => 'PRANK_OR_NON_MEDICAL',
        'confidence' => $conf,
        'error' => '',
        'corrected_text' => '',
        'medical_concept' => '',
    ];
}

/** @return array<string, mixed> */
function geminiNonHealth(float $conf = 0.95): array
{
    return [
        'available' => true,
        'is_medical_complaint' => false,
        'classification' => 'NON_HEALTH_RELATED',
        'confidence' => $conf,
        'error' => '',
        'corrected_text' => '',
        'medical_concept' => '',
    ];
}

/** @return array<string, mixed> */
function geminiDown(): array
{
    return [
        'available' => false,
        'is_medical_complaint' => null,
        'classification' => null,
        'confidence' => null,
        'error' => 'unavailable',
        'corrected_text' => '',
        'medical_concept' => '',
    ];
}

echo "=== Primary complaint semantic gate ===\n";

$cases = [
    ['masakit akon ulo', null, true, 'known Hiligaynon headache (PHP NLP)'],
    ['I have a fever', null, true, 'English fever'],
    ['masakit ang ulo ko', null, true, 'Tagalog headache'],
    ['feve akon lawas', null, true, 'misspelled fever'],
    ['sakit ulo at fever', null, true, 'mixed language'],
    ['permi galupot akon tiyan', geminiHealth('abdominal discomfort'), true, 'unknown Hiligaynon + Gemini HEALTH'],
    ['permi galupot akon tiyan', geminiUnclear(), true, 'unknown Hiligaynon + Gemini UNCLEAR fail-open'],
    ['galupot tiyan ko', geminiDown(), true, 'unknown local + body part, Gemini down'],
    ['hello kumusta', geminiNonHealth(), false, 'greeting NON_HEALTH'],
    ['minecraft banana hahaha', geminiPrank(), false, 'prank PRANK_OR_NON_MEDICAL'],
    ['asdfghjkl', geminiPrank(), false, 'keyboard smash prank'],
];

foreach ($cases as [$text, $override, $expectValid, $label]) {
    $r = ComplaintSemanticValidator::validateOpeningComplaint($text, $override);
    $valid = !empty($r['is_valid']);
    ok($valid === $expectValid, $label, ($r['combine_reason'] ?? '') . ' | class=' . ($r['classification'] ?? ''));
}

echo "\n=== Follow-up answer classes ===\n";

$ctxBase = [
    'chief_complaint' => 'masakit akon ulo',
    'question_language' => 'hiligaynon',
    'facts' => [],
    'patient_turns' => ['masakit akon ulo'],
    'questions_asked' => ['ONSET'],
    'last_followup_question' => [
        'question_id' => 'ONSET',
        'text' => 'San-o ini nagsugod, kag gulpi bala ukon hinay-hinay?',
        'language' => 'HILIGAYNON',
    ],
];

$fu = [
    ['hinay2', 'ONSET', true, 'VALID_PARTIAL', 'onset reduplication'],
    ['depende', 'ONSET', true, 'VALID_PARTIAL', 'conditional depende'],
    ['diko sure', 'ONSET', true, 'VALID_UNCERTAIN', 'uncertainty'],
    ['wala man', 'VISION_CHANGE', true, 'VALID_NEGATIVE', 'negative vision'],
    ['oo, daw mainit gid lawas ko', 'FEVER_CONFIRM', true, 'VALID_POSITIVE', 'positive fever'],
    ['oo', 'FEVER_CONFIRM', true, 'VALID_POSITIVE', 'short oo'],
    ['minecraft banana hahaha', 'FEVER_CONFIRM', false, 'PRANK_OR_NON_MEDICAL', 'prank follow-up'],
    ['kumusta ang presyo sang sapatos?', 'ONSET', false, 'UNRELATED', 'unrelated shopping'],
    ['blue', 'ONSET', false, null, 'off-topic color'],
];

foreach ($fu as [$answer, $qid, $expectAccept, $expectClass, $label]) {
    $ctx = $ctxBase;
    $ctx['awaiting_question_id'] = $qid;
    $ctx['questions_asked'] = [$qid];
    $ctx['last_followup_question']['question_id'] = $qid;
    $r = ClinicalFollowUpAnswerValidator::validate($answer, $qid, $ctx);
    $accept = !empty($r['accept']);
    $class = strtoupper((string) ($r['answer_class'] ?? ''));
    $classOk = $expectClass === null || $class === $expectClass
        || ($expectClass === 'VALID_PARTIAL' && in_array($class, ['VALID_PARTIAL', 'VALID_POSITIVE'], true))
        || ($expectClass === 'VALID_POSITIVE' && in_array($class, ['VALID_POSITIVE', 'VALID_PARTIAL'], true));
    ok($accept === $expectAccept && ($expectAccept ? $classOk : true), $label, "accept=" . ($accept ? '1' : '0') . " class={$class}");
}

echo "\n=== Interview engine wiring (opening) ===\n";
$open = ClinicalInterviewEngine::assess('masakit akon ulo');
ok(
    empty($open['needs_valid_complaint']) && ($open['assessment_status'] ?? '') !== '',
    'known complaint enters interview',
    (string) ($open['assessment_status'] ?? '')
);

$prank = ClinicalInterviewEngine::assess('minecraft banana hahaha');
ok(
    !empty($prank['needs_valid_complaint']) || ($prank['assessment_status'] ?? '') === ClinicalInterviewEngine::STATUS_NEEDS_VALID_COMPLAINT
        || !empty($prank['needs_clarification']),
    'prank blocked at opening gate',
    json_encode([
        'status' => $prank['assessment_status'] ?? null,
        'needs' => $prank['needs_valid_complaint'] ?? null,
    ])
);

echo "\n" . ($fails === 0 ? "ALL PASS\n" : "FAILURES: {$fails}\n");
exit($fails === 0 ? 0 : 1);
