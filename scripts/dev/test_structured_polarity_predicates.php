<?php
/**
 * Stage 2 Batch 3: universal structured clinical polarity predicates (shadow mode).
 *
 * php scripts/dev/test_structured_polarity_predicates.php
 */
declare(strict_types=1);

require __DIR__ . '/nlp_cli_bootstrap.php';

$fail = 0;
$pass = 0;

function ok(string $label, bool $cond, string $detail = ''): void
{
    global $fail, $pass;
    if ($cond) {
        echo "PASS  {$label}\n";
        $pass++;
        return;
    }
    $fail++;
    echo "FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

$keys = ClinicalTriageEngine::clinicalPolarityKeys();
$bridge = ClinicalTriageEngine::polarityConsumerBridge();

ok('bridge covers every polarity key', count(array_diff($keys, array_keys($bridge))) === 0,
    'missing: ' . implode(',', array_diff($keys, array_keys($bridge))));
ok('bridge has no extra keys beyond polarity registry', count(array_diff(array_keys($bridge), $keys)) === 0);

// --- Data-driven tri-state for every key ---
foreach ($keys as $key) {
    $meta = $bridge[$key];
    $strength = (string) ($meta['strength'] ?? '');
    $level = (string) ($meta['triage_level'] ?? '');

    $trueBag = [$key => true];
    $falseBag = [$key => false];
    $unsetEv = ClinicalTriageEngine::normalizeInterviewEvidence([]);
    $trueEv = ClinicalTriageEngine::normalizeInterviewEvidence($trueBag);
    $falseEv = ClinicalTriageEngine::normalizeInterviewEvidence($falseBag);

    $shadowTrue = ClinicalTriageEngine::evaluateStructuredPolarityShadow($trueEv, '', '');
    $shadowFalse = ClinicalTriageEngine::evaluateStructuredPolarityShadow($falseEv, '', '');
    $shadowUnset = ClinicalTriageEngine::evaluateStructuredPolarityShadow($unsetEv, '', '');

    $hardTrue = array_values(array_filter(
        $shadowTrue['hard_hits'] ?? [],
        static fn (array $h): bool => ($h['key'] ?? '') === $key
    ));
    $softTrue = array_values(array_filter(
        $shadowTrue['soft_hits'] ?? [],
        static fn (array $h): bool => ($h['key'] ?? '') === $key
    ));
    $supFalse = array_values(array_filter(
        $shadowFalse['suppressed'] ?? [],
        static fn (array $h): bool => ($h['key'] ?? '') === $key && ($h['reason'] ?? '') === 'explicit_false'
    ));
    $unk = array_values(array_filter(
        $shadowUnset['unknown_keys'] ?? [],
        static fn (array $h): bool => ($h['key'] ?? '') === $key
    ));

    if ($strength === 'hard') {
        ok("{$key}: true reaches hard consumer", $hardTrue !== [], json_encode($shadowTrue['hard_hits'] ?? []));
        ok("{$key}: true hard level matches bridge", ($hardTrue[0]['triage_level'] ?? '') === $level);
        if (($meta['consumer'] ?? '') === 'who_iitt') {
            $expectedRules = (array) ($meta['who_rule_ids'] ?? []);
            $got = (array) ($shadowTrue['who_rule_ids_candidate'] ?? []);
            ok("{$key}: true maps existing WHO ids", array_diff($expectedRules, $got) === []);
        }
        if (($meta['consumer'] ?? '') === 'red_flag_breathing') {
            ok("{$key}: true reaches red-flag candidate", ($shadowTrue['red_flags_candidate'] ?? []) !== []);
        }
    } else {
        ok("{$key}: true is soft only (no hard hit)", $hardTrue === [] && $softTrue !== []);
        ok("{$key}: soft true does not escalate candidate alone",
            ($shadowTrue['triage_display_candidate'] ?? '') === 'NON-URGENT');
    }

    ok("{$key}: false suppresses", $supFalse !== []);
    ok("{$key}: false produces no hard hit", ($shadowFalse['hard_hits'] ?? []) === []);
    ok("{$key}: unset unknown (no evidence)", $unk !== []
        && ($shadowUnset['hard_hits'] ?? []) === []
        && ($shadowUnset['soft_hits'] ?? []) === []);
}

// --- Soft facts must not invent new E/U ---
$softOnly = ClinicalTriageEngine::evaluateStructuredPolarityShadow(
    ClinicalTriageEngine::normalizeInterviewEvidence([
        'dizziness' => true,
        'fever_confirmed' => true,
        'blood_in_stool' => true,
        'abdominal_associated' => true,
        'sweating' => true,
        'pregnancy' => true,
    ]),
    '',
    'NON-URGENT'
);
ok('soft-only bundle stays NON-URGENT candidate', ($softOnly['triage_display_candidate'] ?? '') === 'NON-URGENT');
ok('soft-only has no who hard rules', ($softOnly['who_rule_ids_candidate'] ?? []) === []);
ok('soft-only has no red-flag candidates', ($softOnly['red_flags_candidate'] ?? []) === []);

// --- Provenance: KB/AI/legacy cannot create patient-equivalent polarity ---
$provEv = ClinicalTriageEngine::normalizeInterviewEvidence([
    'symptoms_kb' => ['Difficulty Breathing KB'],
    'symptoms_ai' => ['Heavy Bleeding AI'],
    'symptoms' => ['weakness in one arm or leg'],
    // polarity keys intentionally unset
]);
$provShadow = ClinicalTriageEngine::evaluateStructuredPolarityShadow($provEv, '', '');
ok('KB/AI/legacy do not create hard polarity hits', ($provShadow['hard_hits'] ?? []) === []);
ok('provenance blocked notes recorded', ($provShadow['provenance_blocked'] ?? []) !== []);

// --- negative_symptoms suppress when polarity unset ---
$negEv = ClinicalTriageEngine::normalizeInterviewEvidence([
    'negative_symptoms' => ['difficulty breathing'],
]);
$negShadow = ClinicalTriageEngine::evaluateStructuredPolarityShadow($negEv, '', '');
$negSup = array_values(array_filter(
    $negShadow['suppressed'] ?? [],
    static fn (array $r): bool => ($r['key'] ?? '') === 'breathing_difficulty'
        && ($r['reason'] ?? '') === 'negative_symptoms'
));
ok('negative_symptoms suppress breathing when unset', $negSup !== []);

// --- Single-track patient evidence preserved ---
$singlePack = [
    'patient_evidence_text' => 'Masakit ang dibdib ko at indi ko makaginhawa',
    'breathing_difficulty' => true,
];
$singleEv = ClinicalTriageEngine::normalizeInterviewEvidence($singlePack);
ok('single preserves multilingual patientEvidenceText',
    ($singleEv['patient_evidence_text'] ?? '') === 'Masakit ang dibdib ko at indi ko makaginhawa');
$singleShadow = ClinicalTriageEngine::evaluateStructuredPolarityShadow($singleEv, '', '');
ok('single shadow echoes patient evidence text',
    ($singleShadow['patient_evidence_text'] ?? '') === 'Masakit ang dibdib ko at indi ko makaginhawa');
ok('single hard breathing → EMERGENCY candidate',
    ($singleShadow['triage_display_candidate'] ?? '') === 'EMERGENCY');
ok('shadow is non-authoritative', ($singleShadow['authority'] ?? true) === false);

// --- Multi-track isolation (no cross-track combo / no flatten) ---
$multiEv = ClinicalTriageEngine::normalizeInterviewEvidence([
    'patient_evidence_text' => 'Sakit ulo; may dugo pa rin',
    'active_complaint_id' => 'c1',
    'active_facts' => [],
    'tracks' => [
        [
            'complaint_id' => 'c1',
            'text_span' => 'Sakit ulo',
            'family_keys' => ['headache'],
            'facts' => ['breathing_difficulty' => true],
        ],
        [
            'complaint_id' => 'c2',
            'text_span' => 'may dugo pa rin',
            'family_keys' => ['bleeding'],
            'facts' => ['bleeding_continuing' => true, 'bleeding_heavy' => false],
        ],
    ],
]);
$multiShadow = ClinicalTriageEngine::evaluateStructuredPolarityShadow($multiEv, '', '');
ok('multi preserves patient evidence text',
    ($multiShadow['patient_evidence_text'] ?? '') === 'Sakit ulo; may dugo pa rin');
ok('multi has two track summaries', count($multiShadow['track_summaries'] ?? []) === 2);
$t1 = $multiShadow['track_summaries'][0] ?? [];
$t2 = $multiShadow['track_summaries'][1] ?? [];
ok('track1 isolated breathing hit',
    count($t1['hard_hits'] ?? []) === 1
    && ($t1['hard_hits'][0]['key'] ?? '') === 'breathing_difficulty'
    && ($t1['track_candidate_display'] ?? '') === 'EMERGENCY');
ok('track2 isolated bleeding continuing + suppressed heavy',
    count($t2['hard_hits'] ?? []) === 1
    && ($t2['hard_hits'][0]['key'] ?? '') === 'bleeding_continuing'
    && ($t2['track_candidate_display'] ?? '') === 'URGENT'
    && count(array_filter($t2['suppressed'] ?? [], static fn ($r) => ($r['key'] ?? '') === 'bleeding_heavy')) === 1);
ok('no cross-track synthetic combo field',
    !isset($multiShadow['cross_track_combo'])
    && !isset($multiShadow['max_of_tracks']));
// Complete-case candidate is one authority reading units (not a separate MAX engine).
ok('complete-case candidate uses highest hard meaning only once',
    ($multiShadow['triage_display_candidate'] ?? '') === 'EMERGENCY');

// --- Double-fire control: haystack + structured same meaning tagged ---
$hay = 'difficulty breathing. patient report';
$dupEv = ClinicalTriageEngine::normalizeInterviewEvidence(['breathing_difficulty' => true]);
$dupShadow = ClinicalTriageEngine::evaluateStructuredPolarityShadow($dupEv, $hay, 'EMERGENCY');
ok('double-fire noted when haystack already has phrase',
    ($dupShadow['dedupe_notes'] ?? []) !== []
    && ($dupShadow['hard_hits'][0]['also_in_haystack'] ?? false) === true);
ok('structured evidence_source distinct from haystack',
    ($dupShadow['hard_hits'][0]['evidence_source'] ?? '') === 'structured_polarity');

// --- OLD production classification unchanged ---
$haystackEmergency = 'difficulty breathing';
$oldOnly = ClinicalTriageEngine::assess($haystackEmergency, $haystackEmergency);
$withShadowFacts = ClinicalTriageEngine::assess(
    $haystackEmergency,
    $haystackEmergency,
    [],
    [],
    0,
    true,
    ['breathing_difficulty' => true, 'patient_evidence_text' => 'indi ko makaginhawa']
);
ok('OLD production display unchanged with structured facts present',
    ($oldOnly['triage_display'] ?? '') === ($withShadowFacts['triage_display'] ?? '')
    && ($oldOnly['triage_display'] ?? '') === 'EMERGENCY');
ok('assess attaches structured_polarity_shadow',
    is_array($withShadowFacts['structured_polarity_shadow'] ?? null)
    && ($withShadowFacts['structured_polarity_shadow']['authority'] ?? true) === false);
ok('shadow candidate available for parity',
    ($withShadowFacts['structured_polarity_shadow']['triage_display_candidate'] ?? '') === 'EMERGENCY');

// Structured-only (no haystack phrase) → OLD may stay non-urgent; NEW candidate escalates.
$plain = 'masakit ang ulo';
$oldPlain = ClinicalTriageEngine::assess($plain, $plain);
$newPlain = ClinicalTriageEngine::assess($plain, $plain, [], [], 0, true, [
    'breathing_difficulty' => true,
    'patient_evidence_text' => 'masakit ang ulo',
]);
ok('OLD stays production authority without haystack phrase',
    ($oldPlain['triage_display'] ?? '') === ($newPlain['triage_display'] ?? ''));
ok('NEW candidate escalates from structured while live display stays production path',
    ($newPlain['structured_polarity_shadow']['triage_display_candidate'] ?? '') === 'EMERGENCY');
// Stronger: authority flag false always
ok('live authority not switched',
    ($newPlain['structured_polarity_shadow']['authority'] ?? true) === false
    && ($newPlain['structured_polarity_shadow']['production_display'] ?? '') === ($newPlain['triage_display'] ?? ''));

// Tagalog / Hiligaynon / mixed patient wording preserved through assess echo
$mixed = ClinicalTriageEngine::assess(
    'May lagnat at mahirap huminga',
    'May lagnat at mahirap huminga',
    [],
    [],
    0,
    true,
    [
        'patient_evidence_text' => 'May lagnat at mahirap huminga / budlay ginhawa',
        'fever_confirmed' => true,
        'breathing_difficulty' => true,
    ]
);
ok('mixed-language patientEvidenceText preserved in shadow',
    ($mixed['structured_polarity_shadow']['patient_evidence_text'] ?? '')
        === 'May lagnat at mahirap huminga / budlay ginhawa');

// Universal: same mechanism for any domain — only polarity keys matter
$giDomain = ClinicalTriageEngine::evaluateStructuredPolarityShadow(
    ClinicalTriageEngine::normalizeInterviewEvidence([
        'bleeding_heavy' => true,
        'blood_in_stool' => true,
    ]),
    '',
    ''
);
ok('GI-like domain uses same bridge (heavy bleeding hard + stool soft)',
    ($giDomain['triage_display_candidate'] ?? '') === 'EMERGENCY'
    && count($giDomain['soft_hits'] ?? []) === 1
    && ($giDomain['soft_hits'][0]['key'] ?? '') === 'blood_in_stool');

echo "\nPassed: {$pass}  Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);
