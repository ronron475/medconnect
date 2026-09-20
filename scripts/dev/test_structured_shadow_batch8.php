<?php
/**
 * Stage 2 Batch 8: narrow structured shadow parity (negation + WHO parity instrumentation).
 * finding_status mapping deferred — no unambiguous independent triage consumer.
 *
 * php scripts/dev/test_structured_shadow_batch8.php
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

// --- 1. Structured negative suppression (explicit false) ---
$negBleed = ClinicalTriageEngine::evaluateStructuredPolarityShadow(
    ClinicalTriageEngine::normalizeInterviewEvidence([
        'patient_evidence_text' => 'original patient wording only',
        'pregnancy' => true,
        'bleeding_heavy' => false,
    ]),
    '',
    ''
);
ok('negation parity: authority remains false', ($negBleed['authority'] ?? true) === false);
ok('explicit false bleeding_heavy is suppressed',
    array_filter(
        (array) ($negBleed['suppressed'] ?? []),
        static fn ($r) => is_array($r)
            && ($r['key'] ?? '') === 'bleeding_heavy'
            && ($r['reason'] ?? '') === 'explicit_false'
    ) !== []);
ok('false bleeding does not create R010 WHO combo hit',
    !in_array('IITT-R010', (array) ($negBleed['who_rule_ids_candidate'] ?? []), true));
$comboHits = (array) (($negBleed['who_combo_shadow']['hits'] ?? []) ?: []);
ok('R010 absent from combo hits when bleeding false',
    !in_array('IITT-R010', array_map(
        static fn ($h) => is_array($h) ? (string) ($h['rule_id'] ?? '') : '',
        $comboHits
    ), true));

// Direct combo evaluator also refuses false polarity
$comboDirect = ClinicalTriageEngine::evaluateStructuredWhoComboShadow(
    ClinicalTriageEngine::normalizeInterviewEvidence([
        'pregnancy' => true,
        'bleeding_heavy' => false,
    ])
);
ok('combo shadow alone: R010 not fired on false bleeding',
    !in_array('IITT-R010', comboIds($comboDirect), true));

// Same-track positive still fires (control)
$posBleed = ClinicalTriageEngine::evaluateStructuredWhoComboShadow(
    ClinicalTriageEngine::normalizeInterviewEvidence([
        'pregnancy' => true,
        'bleeding_heavy' => true,
    ])
);
ok('control: R010 fires when both true same track',
    in_array('IITT-R010', comboIds($posBleed), true));

// applyNegationParity strips combo hit when suppressed
$stripped = ClinicalTriageEngine::applyNegationParityToWhoComboShadow(
    $posBleed,
    [['track_id' => 'single', 'key' => 'bleeding_heavy', 'reason' => 'explicit_false']]
);
ok('applyNegationParity removes R010 when bleeding suppressed',
    !in_array('IITT-R010', comboIds($stripped), true)
    && (($stripped['negation_removed_hits'] ?? []) !== [])
    && (($stripped['negation_parity_applied'] ?? false) === true));

// --- 2. Uncertain answers are NOT treated as negative ---
$uncertain = ClinicalTriageEngine::evaluateStructuredPolarityShadow(
    ClinicalTriageEngine::normalizeInterviewEvidence([
        'patient_evidence_text' => 'hindi sigurado / not sure',
        // polarity keys unset → unknown
    ]),
    '',
    ''
);
$uncBreath = array_values(array_filter(
    (array) ($uncertain['unknown_keys'] ?? []),
    static fn ($r) => is_array($r) && ($r['key'] ?? '') === 'breathing_difficulty'
));
$supBreath = array_values(array_filter(
    (array) ($uncertain['suppressed'] ?? []),
    static fn ($r) => is_array($r) && ($r['key'] ?? '') === 'breathing_difficulty'
));
ok('unset polarity listed as unknown, not suppressed',
    $uncBreath !== [] && $supBreath === []);
ok('uncertain does not invent WHO hits',
    (array) ($uncertain['who_rule_ids_candidate'] ?? []) === []
    || !in_array('IITT-R003', (array) ($uncertain['who_rule_ids_candidate'] ?? []), true));
ok('negation_parity marks uncertain_not_treated_as_negative',
    (($uncertain['negation_parity']['uncertain_not_treated_as_negative'] ?? false) === true));

// --- 3. Negative evidence does not incorrectly create a WHO hit ---
$negWho = ClinicalTriageEngine::evaluateStructuredPolarityShadow(
    ClinicalTriageEngine::normalizeInterviewEvidence([
        'breathing_difficulty' => false,
        'negative_symptoms' => ['difficulty breathing'],
    ]),
    '',
    ''
);
ok('false breathing has no R003 / RF candidate',
    !in_array('IITT-R003', (array) ($negWho['who_rule_ids_candidate'] ?? []), true)
    && ($negWho['red_flags_candidate'] ?? []) === []);
ok('false breathing does not create R013 combo',
    !in_array('IITT-R013', (array) ($negWho['who_rule_ids_candidate'] ?? []), true));

// --- 4. finding_status deferred (no safe consumer mapping) ---
$fs = ClinicalTriageEngine::findingStatusShadowDeferredReport();
ok('finding_status shadow deferred',
    ($fs['deferred'] ?? false) === true
    && ($fs['implemented'] ?? true) === false
    && ($fs['authority'] ?? true) === false);
ok('polarity shadow exposes deferred finding_status',
    (($negBleed['finding_status_shadow']['deferred'] ?? false) === true));

// finding_status present in facts must not invent WHO hits
$fsFacts = ClinicalTriageEngine::evaluateStructuredPolarityShadow(
    ClinicalTriageEngine::normalizeInterviewEvidence([
        'finding_status' => ['dizziness_with_chest' => true],
        'symptoms_kb' => ['dizziness from kb'],
    ]),
    '',
    ''
);
ok('finding_status alone does not create WHO / RF hits',
    (array) ($fsFacts['hard_hits'] ?? []) === []
    && (array) ($fsFacts['red_flags_candidate'] ?? []) === []);

// --- 5. Provenance remains patient-derived ---
$prov = ClinicalTriageEngine::evaluateStructuredPolarityShadow(
    ClinicalTriageEngine::normalizeInterviewEvidence([
        'symptoms_kb' => ['Difficulty Breathing KB'],
        'symptoms_ai' => ['Heavy Bleeding AI'],
        'symptoms' => ['weakness in one arm or leg'],
        'finding_status' => ['dizziness_with_chest' => true],
    ]),
    'synthetic haystack dizziness difficulty breathing',
    ''
);
ok('KB/AI/legacy/haystack do not create polarity hard hits',
    ($prov['hard_hits'] ?? []) === []);
ok('provenance_blocked notes present', ($prov['provenance_blocked'] ?? []) !== []);
ok('KB cannot invent structured negation suppressions for breathing',
    array_filter(
        (array) ($prov['suppressed'] ?? []),
        static fn ($r) => is_array($r) && ($r['key'] ?? '') === 'breathing_difficulty'
    ) === []);

// --- 6. OLD result unchanged; NEW authority false; who_parity accurate ---
$patientText = 'mild complaint only';
$facts = [
    'patient_evidence_text' => $patientText,
    'pregnancy' => true,
    'bleeding_heavy' => true,
];
$oldAlone = ClinicalTriageEngine::assess($patientText, $patientText, [], [], 0, true, [], false);
$withShadow = ClinicalTriageEngine::assess($patientText, $patientText, [], [], 0, true, $facts, true);
ok('OLD production triage_display unchanged vs text-only assess',
    (string) ($oldAlone['triage_display'] ?? '') === (string) ($withShadow['triage_display'] ?? ''));
$cmp = $withShadow['triage_path_comparison'] ?? [];
ok('comparison authority_new is false', ($cmp['authority_new'] ?? true) === false);
ok('comparison new.authority is false', ($cmp['new']['authority'] ?? true) === false);
ok('who_parity present and measurement-only',
    is_array($cmp['who_parity'] ?? null)
    && (($cmp['who_parity']['measurement_only'] ?? false) === true)
    && (($cmp['who_parity']['alters_triage'] ?? true) === false));
$wp = is_array($cmp['who_parity'] ?? null) ? $cmp['who_parity'] : [];
ok('who_parity has matched_by_both / old_only / new_only / sources',
    isset($wp['matched_by_both'], $wp['old_only'], $wp['new_only'], $wp['new_sources'])
    && isset($wp['structured_shadow_source_ids'], $wp['purified_patient_text_source_ids']));
ok('structured R010 appears in new_only or matched when OLD text misses it',
    in_array('IITT-R010', (array) ($cmp['new']['who_rule_ids_candidate'] ?? []), true));
ok('who_parity new_sources tags R010 as structured',
    in_array('structured_who_combo_shadow', (array) (($wp['new_sources']['IITT-R010'] ?? [])), true)
    || in_array('structured_polarity_shadow', (array) (($wp['new_sources']['IITT-R010'] ?? [])), true));
ok('finding_status still deferred inside who_parity',
    (($wp['finding_status_shadow']['deferred'] ?? false) === true));

// --- 7. Multi-track: negation stays track-local; no cross-track WHO ---
$multi = ClinicalTriageEngine::evaluateStructuredPolarityShadow(
    ClinicalTriageEngine::normalizeInterviewEvidence([
        'patient_evidence_text' => 'Track A pregnant; Track B bleeding heavy only',
        'active_complaint_id' => 'c1',
        'tracks' => [
            [
                'complaint_id' => 'c1',
                'text_span' => 'pregnant',
                'facts' => ['pregnancy' => true, 'bleeding_heavy' => false],
            ],
            [
                'complaint_id' => 'c2',
                'text_span' => 'heavy bleeding',
                'facts' => ['bleeding_heavy' => true, 'pregnancy' => false],
            ],
        ],
    ]),
    '',
    ''
);
ok('multi: R010 not fired across tracks',
    !in_array('IITT-R010', (array) ($multi['who_rule_ids_candidate'] ?? []), true));
$rejected = (array) (($multi['who_combo_shadow']['cross_track_rejected'] ?? []) ?: []);
ok('multi: cross-track R010 rejection logged',
    array_filter(
        $rejected,
        static fn ($r) => is_array($r) && ($r['rule_id'] ?? '') === 'IITT-R010'
    ) !== []);

// --- 8. Language-independence: structured polarity, not literal phrases ---
$hil = ClinicalTriageEngine::evaluateStructuredPolarityShadow(
    ClinicalTriageEngine::normalizeInterviewEvidence([
        'patient_evidence_text' => 'Indi ako makaginhawa sang maayo',
        'breathing_difficulty' => true,
    ]),
    '',
    ''
);
$tag = ClinicalTriageEngine::evaluateStructuredPolarityShadow(
    ClinicalTriageEngine::normalizeInterviewEvidence([
        'patient_evidence_text' => 'Hindi ako makahinga nang maayos',
        'breathing_difficulty' => true,
    ]),
    '',
    ''
);
ok('Hiligaynon patient text + structured breathing → R003 shadow',
    in_array('IITT-R003', (array) ($hil['who_rule_ids_candidate'] ?? []), true));
ok('Tagalog patient text + structured breathing → R003 shadow',
    in_array('IITT-R003', (array) ($tag['who_rule_ids_candidate'] ?? []), true));
ok('language surface does not change authority',
    ($hil['authority'] ?? true) === false && ($tag['authority'] ?? true) === false);

echo "\nBatch 8: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
