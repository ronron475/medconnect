<?php
/**
 * Acceptance probes: Gemini semantic validation layer + PHP NLP combine.
 * Runs with MEDCONNECT_PHP_NLP_ONLY (bootstrap) — Gemini unavailable path + injected Gemini.
 *
 * Usage: php scripts/dev/probe_gemini_complaint_validation.php
 */
declare(strict_types=1);

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

function invalidGemini(float $conf = 0.95): array
{
    return [
        'available' => true,
        'is_medical_complaint' => false,
        'classification' => GeminiComplaintInputValidator::CLASS_INVALID,
        'confidence' => $conf,
        'error' => '',
    ];
}

function validGemini(float $conf = 0.95): array
{
    return [
        'available' => true,
        'is_medical_complaint' => true,
        'classification' => GeminiComplaintInputValidator::CLASS_VALID,
        'confidence' => $conf,
        'error' => '',
    ];
}

function unavailableGemini(): array
{
    return [
        'available' => false,
        'is_medical_complaint' => null,
        'classification' => null,
        'confidence' => null,
        'error' => 'timeout',
    ];
}

echo "=== Semantic validator (injected Gemini) ===\n";

$cases = [
    ['hh', invalidGemini(), false, 'hh'],
    ['asdfgh', invalidGemini(), false, 'asdfgh'],
    ['hello', invalidGemini(), false, 'hello'],
    ['this is a test', invalidGemini(), false, 'test phrase'],
    ['headache', validGemini(), true, 'headache'],
    ['my head hurts', validGemini(), true, 'my head hurts'],
    ['Masakit akon ulo.', validGemini(), true, 'HIL headache'],
    ['Masakit ang ulo ko.', validGemini(), true, 'TL headache'],
    ['my ulo hurts kag nahihilo ko', validGemini(), true, 'mixed'],
];

foreach ($cases as [$text, $gem, $expectValid, $label]) {
    $r = ComplaintSemanticValidator::validateOpeningComplaint($text, $gem);
    ok($r['is_valid'] === $expectValid, "combine {$label}", ($r['classification'] ?? '') . ' / ' . ($r['combine_reason'] ?? ''));
    if (!$expectValid) {
        ok(!empty($r['needs_valid_complaint']), "needs_valid {$label}");
        ok($r['patient_message'] !== '', "message {$label}");
    }
}

// Conflict: strong PHP + Gemini uncertain invalid → still allow
$strongAllow = ComplaintSemanticValidator::validateOpeningComplaint('my head hurts for 2 weeks', [
    'available' => true,
    'is_medical_complaint' => false,
    'classification' => GeminiComplaintInputValidator::CLASS_INVALID,
    'confidence' => 0.40,
    'error' => '',
]);
ok($strongAllow['is_valid'] === true, 'PHP strong + Gemini uncertain → allow');

// Gemini timeout → PHP-only for nonsense
$timeoutHh = ComplaintSemanticValidator::validateOpeningComplaint('hh', unavailableGemini());
ok($timeoutHh['is_valid'] === false, 'Gemini timeout + hh → invalid via PHP');

$timeoutHead = ComplaintSemanticValidator::validateOpeningComplaint('my head hurts', unavailableGemini());
ok($timeoutHead['is_valid'] === true, 'Gemini timeout + headache → valid via PHP');

echo "\n=== Non-essential tokens ===\n";
$feveFever = ComplaintTriageTextCleaner::prepare('feve fever');
ok(
    $feveFever['has_usable_clinical_text'] === true
        && str_contains(mb_strtolower($feveFever['cleaned']), 'fever')
        && !preg_match('/\bfeve\b/u', mb_strtolower($feveFever['cleaned'])),
    'feve fever keeps fever, drops leftover typo',
    $feveFever['cleaned']
);
$feveOnly = ComplaintTriageTextCleaner::prepare('feve');
ok($feveOnly['has_usable_clinical_text'] === false, 'feve alone is not triage-ready');
$helloFever = ComplaintTriageTextCleaner::prepare('hello fever');
ok(
    $helloFever['has_usable_clinical_text'] === true
        && !str_contains(mb_strtolower($helloFever['cleaned']), 'hello'),
    'hello fever drops greeting',
    $helloFever['cleaned']
);

$feveInterview = ClinicalInterviewEngine::assess('feve fever', [], []);
ok(empty($feveInterview['needs_valid_complaint']), 'feve fever continues NLP');
ok(
    str_contains(mb_strtolower((string) ($feveInterview['clinical_transcript'] ?? $feveInterview['chief_complaint'] ?? '')), 'fever'),
    'feve fever NLP sees fever'
);
$feveBlocked = ClinicalInterviewEngine::assess('feve', [], []);
ok(!empty($feveBlocked['needs_valid_complaint']) || !empty($feveBlocked['domain_skipped']), 'feve asks for a real complaint');

echo "\n=== Interview engine gate ===\n";

$hh = ClinicalInterviewEngine::assess('hh', [], []);
ok(
    ($hh['needs_valid_complaint'] ?? false) === true
        || ($hh['domain_skipped'] ?? false) === true,
    'hh needs_valid_complaint',
    (string) ($hh['assessment_status'] ?? '')
);
ok(
    (string) ($hh['triage']['triage_display'] ?? '') === ''
        && (string) ($hh['triage']['triage_classification'] ?? '') === '',
    'hh no triage class'
);
ok(empty($hh['followup_question']), 'hh no clinical follow-up');
ok(TriageLevelService::fromAssessment($hh) === '', 'hh triage level empty');

$hello = ClinicalInterviewEngine::assess('hello', [], []);
ok(!empty($hello['needs_valid_complaint']) || !empty($hello['domain_skipped']), 'hello blocked');

$head = ClinicalInterviewEngine::assess('my head hurts', [], []);
ok(empty($head['needs_valid_complaint']), 'headache not blocked');
ok(
    strtoupper((string) ($head['assessment_status'] ?? '')) === 'IN_PROGRESS'
        || !empty($head['followup_question']),
    'headache continues NLP',
    (string) ($head['assessment_status'] ?? '')
);

$hil = ClinicalInterviewEngine::assess('Masakit akon ulo', [], []);
ok(empty($hil['needs_valid_complaint']), 'HIL not blocked');

$tl = ClinicalInterviewEngine::assess('Masakit ang ulo ko', [], []);
ok(empty($tl['needs_valid_complaint']), 'TL not blocked');

$mixed = ClinicalInterviewEngine::assess('my ulo hurts kag nahihilo ko', [], []);
ok(empty($mixed['needs_valid_complaint']), 'mixed not blocked');

$oneShot = ChiefComplaintNlpService::assess('asdfgh', []);
ok(!empty($oneShot['needs_valid_complaint']), 'one-shot asdfgh blocked');
ok((string) ($oneShot['triage']['triage_display'] ?? '') === '', 'one-shot no NON-URGENT invent');

echo "\nFails: {$fails}\n";
exit($fails > 0 ? 1 : 0);
