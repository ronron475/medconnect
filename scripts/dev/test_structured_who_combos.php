<?php
/**
 * Stage 2 Batch 6: track-safe structured WHO/IITT combo shadow (R010–R012, R014).
 *
 * php scripts/dev/test_structured_who_combos.php
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

function comboIds(array $shadow): array
{
    return array_values(array_map('strval', (array) ($shadow['who_rule_ids_candidate'] ?? [])));
}

// --- Same-track R010 ---
$r010 = ClinicalTriageEngine::evaluateStructuredWhoComboShadow(
    ClinicalTriageEngine::normalizeInterviewEvidence([
        'patient_evidence_text' => 'original patient wording unchanged',
        'pregnancy' => true,
        'bleeding_heavy' => true,
    ])
);
ok('R010 same-track pregnancy+heavy bleeding fires',
    in_array('IITT-R010', comboIds($r010), true)
    && ($r010['triage_display_candidate'] ?? '') === 'EMERGENCY'
    && ($r010['authority'] ?? true) === false);

// Pregnancy alone must not fire RED combo
$pregOnly = ClinicalTriageEngine::evaluateStructuredWhoComboShadow(
    ClinicalTriageEngine::normalizeInterviewEvidence(['pregnancy' => true])
);
ok('pregnancy alone does not fire R010–R012',
    !in_array('IITT-R010', comboIds($pregOnly), true)
    && !in_array('IITT-R011', comboIds($pregOnly), true)
    && !in_array('IITT-R012', comboIds($pregOnly), true));

// --- Same-track R012 via vision_change ---
$r012 = ClinicalTriageEngine::evaluateStructuredWhoComboShadow(
    ClinicalTriageEngine::normalizeInterviewEvidence([
        'pregnancy' => true,
        'vision_change' => true,
    ])
);
ok('R012 same-track pregnancy+vision_change fires',
    in_array('IITT-R012', comboIds($r012), true));

// --- Same-track R012 via severe + patient headache ---
$r012h = ClinicalTriageEngine::evaluateStructuredWhoComboShadow(
    ClinicalTriageEngine::normalizeInterviewEvidence([
        'pregnancy' => true,
        'pain_qualifier' => 'severe',
        'symptoms_patient' => ['headache'],
    ])
);
ok('R012 same-track severe+patient headache fires',
    in_array('IITT-R012', comboIds($r012h), true));

// KB headache must not create R012
$r012kb = ClinicalTriageEngine::evaluateStructuredWhoComboShadow(
    ClinicalTriageEngine::normalizeInterviewEvidence([
        'pregnancy' => true,
        'pain_qualifier' => 'severe',
        'symptoms_kb' => ['headache'],
        'symptoms_ai' => ['headache'],
    ])
);
ok('R012 ignores KB/AI headache',
    !in_array('IITT-R012', comboIds($r012kb), true));

// --- R011 patient seizure concept ---
$r011 = ClinicalTriageEngine::evaluateStructuredWhoComboShadow(
    ClinicalTriageEngine::normalizeInterviewEvidence([
        'pregnancy' => true,
        'symptoms_patient' => ['seizure'],
    ])
);
ok('R011 same-track pregnancy+patient seizure fires',
    in_array('IITT-R011', comboIds($r011), true));

// --- R014: fever alone insufficient ---
$feverOnly = ClinicalTriageEngine::evaluateStructuredWhoComboShadow(
    ClinicalTriageEngine::normalizeInterviewEvidence(['fever_confirmed' => true])
);
ok('R014 fever alone does not fire',
    !in_array('IITT-R014', comboIds($feverOnly), true));

// Same-track fever + patient headache
$r014 = ClinicalTriageEngine::evaluateStructuredWhoComboShadow(
    ClinicalTriageEngine::normalizeInterviewEvidence([
        'fever_confirmed' => true,
        'symptoms_patient' => ['headache'],
    ])
);
ok('R014 same-track fever+patient headache fires',
    in_array('IITT-R014', comboIds($r014), true)
    && ($r014['hits'][0]['track_id'] ?? '') === 'single');

// Negative headache suppresses R014
$r014neg = ClinicalTriageEngine::evaluateStructuredWhoComboShadow(
    ClinicalTriageEngine::normalizeInterviewEvidence([
        'fever_confirmed' => true,
        'negative_symptoms' => ['headache'],
    ])
);
ok('R014 negative headache does not fire',
    !in_array('IITT-R014', comboIds($r014neg), true));

// Unknown companion remains unknown (fever + no headache evidence)
ok('R014 unknown companion stays non-hit',
    !in_array('IITT-R014', comboIds($feverOnly), true));

// Missing structured fields documented
ok('R014 missing stiff_neck/AMS fields reported',
    ($r014['missing_structured_fields'] ?? []) !== []);

// --- Cross-track: pregnancy on A, bleeding on B → NOT R010 ---
$cross = ClinicalTriageEngine::evaluateStructuredWhoComboShadow(
    ClinicalTriageEngine::normalizeInterviewEvidence([
        'patient_evidence_text' => 'track a and track b patient words',
        'active_complaint_id' => 'a',
        'active_facts' => [],
        'tracks' => [
            [
                'complaint_id' => 'a',
                'text_span' => 'span-a',
                'family_keys' => [],
                'facts' => ['pregnancy' => true, 'bleeding_heavy' => false],
            ],
            [
                'complaint_id' => 'b',
                'text_span' => 'span-b',
                'family_keys' => [],
                'facts' => ['bleeding_heavy' => true, 'pregnancy' => false],
            ],
        ],
    ])
);
ok('cross-track pregnancy+bleeding does NOT fire R010',
    !in_array('IITT-R010', comboIds($cross), true));
ok('cross-track rejection recorded for R010',
    count(array_filter(
        $cross['cross_track_rejected'] ?? [],
        static fn ($r) => ($r['rule_id'] ?? '') === 'IITT-R010'
    )) >= 1);

// Cross-track fever A + headache B → NOT R014
$cross014 = ClinicalTriageEngine::evaluateStructuredWhoComboShadow(
    ClinicalTriageEngine::normalizeInterviewEvidence([
        'active_complaint_id' => 'a',
        'active_facts' => [],
        'tracks' => [
            [
                'complaint_id' => 'a',
                'text_span' => 'a',
                'family_keys' => [],
                'facts' => ['fever_confirmed' => true],
            ],
            [
                'complaint_id' => 'b',
                'text_span' => 'b',
                'family_keys' => [],
                'facts' => ['symptoms_patient' => ['headache']],
            ],
        ],
    ])
);
ok('cross-track fever+headache does NOT fire R014',
    !in_array('IITT-R014', comboIds($cross014), true));
ok('cross-track rejection recorded for R014',
    count(array_filter(
        $cross014['cross_track_rejected'] ?? [],
        static fn ($r) => ($r['rule_id'] ?? '') === 'IITT-R014'
    )) >= 1);

// Same-track multi pack DOES fire R014
$same014 = ClinicalTriageEngine::evaluateStructuredWhoComboShadow(
    ClinicalTriageEngine::normalizeInterviewEvidence([
        'active_complaint_id' => 'a',
        'active_facts' => [],
        'tracks' => [
            [
                'complaint_id' => 'a',
                'text_span' => 'a',
                'family_keys' => [],
                'facts' => [
                    'fever_confirmed' => true,
                    'symptoms_patient' => ['headache'],
                ],
            ],
            [
                'complaint_id' => 'b',
                'text_span' => 'b',
                'family_keys' => [],
                'facts' => [],
            ],
        ],
    ])
);
ok('same-track multi pack R014 fires on track a only',
    in_array('IITT-R014', comboIds($same014), true)
    && ($same014['hits'][0]['track_id'] ?? '') === 'a');

// --- OLD authority unchanged; NEW shadow attached ---
$hay = 'mild complaint only';
$facts = [
    'patient_evidence_text' => 'mild complaint only',
    'pregnancy' => true,
    'bleeding_heavy' => true,
];
$old = ClinicalTriageEngine::assess($hay, $hay, [], [], 0, true, [], false);
$neu = ClinicalTriageEngine::assess($hay, $hay, [], [], 0, true, $facts, true);
ok('OLD triage_display unchanged with combo facts',
    (string) ($neu['triage_display'] ?? '') === (string) ($old['triage_display'] ?? ''));
ok('NEW authority false on who combo shadow',
    ($neu['structured_who_combo_shadow']['authority'] ?? true) === false);
ok('comparison NEW authority false',
    ($neu['triage_path_comparison']['authority_new'] ?? true) === false);
ok('comparison includes R010 in NEW who candidates',
    in_array('IITT-R010', (array) ($neu['triage_path_comparison']['new']['who_rule_ids_candidate'] ?? []), true));
ok('live display is not replaced by combo EMERGENCY when haystack lacks it',
    (string) ($neu['triage_display'] ?? '') === (string) ($neu['triage_path_comparison']['old']['triage_display'] ?? ''));

// Double-fire: purified text already has pregnant+heavy bleeding; structured also hits R010 once
$bleedText = 'pregnant with heavy bleeding';
$df = ClinicalTriageEngine::assess(
    $bleedText,
    $bleedText,
    [],
    [],
    0,
    true,
    [
        'patient_evidence_text' => $bleedText,
        'pregnancy' => true,
        'bleeding_heavy' => true,
    ],
    true
);
$dfHits = $df['triage_path_comparison']['new']['structured_who_combo_shadow']['hits'] ?? [];
$r010hits = array_values(array_filter($dfHits, static fn ($h) => ($h['rule_id'] ?? '') === 'IITT-R010'));
ok('double-fire: single logical R010 hit', count($r010hits) === 1);
ok('double-fire: sources include structured and purified_text when both match',
    in_array('structured', $r010hits[0]['logical_sources'] ?? [], true)
    && in_array('purified_text', $r010hits[0]['logical_sources'] ?? [], true));
ok('double-fire: who_rule_ids lists R010 once',
    count(array_filter(
        (array) ($df['triage_path_comparison']['new']['who_rule_ids_candidate'] ?? []),
        static fn ($id) => $id === 'IITT-R010'
    )) === 1);

// Provenance: symptoms_patient eligible; patient_evidence_text not rewritten
ok('patient_evidence_text not synthetically rewritten',
    ($neu['triage_path_comparison']['new']['patient_evidence_text'] ?? '') === 'mild complaint only'
    && !str_contains((string) ($neu['triage_path_comparison']['new']['patient_evidence_text'] ?? ''), 'heavy bleeding'));

echo "\nPassed: {$pass}  Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);
