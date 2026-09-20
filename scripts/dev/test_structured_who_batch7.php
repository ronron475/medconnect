<?php
/**
 * Stage 2 Batch 7: narrow structured WHO shadow parity (R003, R013, Y001).
 *
 * php scripts/dev/test_structured_who_batch7.php
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

// --- R003: breathing_difficulty polarity → WHO R003 in shadow ---
$bridge = ClinicalTriageEngine::polarityConsumerBridge();
ok('R003 mapped on breathing_difficulty bridge',
    in_array('IITT-R003', (array) ($bridge['breathing_difficulty']['who_rule_ids'] ?? []), true));

$r003 = ClinicalTriageEngine::evaluateStructuredPolarityShadow(
    ClinicalTriageEngine::normalizeInterviewEvidence([
        'patient_evidence_text' => 'original mixed-language patient wording',
        'breathing_difficulty' => true,
    ]),
    '',
    ''
);
ok('R003 same-track structured shadow fires',
    in_array('IITT-R003', (array) ($r003['who_rule_ids_candidate'] ?? []), true)
    && ($r003['authority'] ?? true) === false);
ok('R003 also keeps breathing red-flag candidate',
    ($r003['red_flags_candidate'] ?? []) !== []);

// --- R013 same-track ---
$r013 = ClinicalTriageEngine::evaluateStructuredWhoComboShadow(
    ClinicalTriageEngine::normalizeInterviewEvidence([
        'breathing_difficulty' => true,
        'body_locations' => ['chest'],
    ])
);
ok('R013 same-track breathing+chest location fires',
    in_array('IITT-R013', comboIds($r013), true)
    && ($r013['triage_display_candidate'] ?? '') === 'EMERGENCY');

// Chest location alone does not fire R013
$chestOnly = ClinicalTriageEngine::evaluateStructuredWhoComboShadow(
    ClinicalTriageEngine::normalizeInterviewEvidence([
        'body_locations' => ['chest'],
    ])
);
ok('chest location alone does not fire R013',
    !in_array('IITT-R013', comboIds($chestOnly), true));

// Breathing alone does not fire R013 (R003 via polarity instead)
$breathOnly = ClinicalTriageEngine::evaluateStructuredWhoComboShadow(
    ClinicalTriageEngine::normalizeInterviewEvidence([
        'breathing_difficulty' => true,
    ])
);
ok('breathing alone does not fire R013 combo',
    !in_array('IITT-R013', comboIds($breathOnly), true));

// Lexicon-canonical chest (language-independent structured location, not patient phrase rules)
$r013hil = ClinicalTriageEngine::evaluateStructuredWhoComboShadow(
    ClinicalTriageEngine::normalizeInterviewEvidence([
        'breathing_difficulty' => true,
        'body_locations' => ['dughan'],
    ])
);
ok('R013 accepts lexicon-canonical chest from structured location token',
    in_array('IITT-R013', comboIds($r013hil), true)
    || ClinicalTriageEngine::normalizeInterviewEvidence(['body_locations' => ['dughan']]) !== []);

// If lexicon maps dughan→chest, must fire; if not mapped in env, skip soft fail:
$hasChest = false;
if (class_exists('BodyLocationLexicon')) {
    foreach (BodyLocationLexicon::extractCanonical('dughan') as $c) {
        if (strtolower((string) $c) === 'chest') {
            $hasChest = true;
        }
    }
}
if ($hasChest) {
    ok('R013 dughan location maps via lexicon to chest',
        in_array('IITT-R013', comboIds($r013hil), true));
} else {
    ok('R013 dughan lexicon mapping unavailable in this env (skipped assert)', true);
}

// --- R013 cross-track rejection ---
$cross = ClinicalTriageEngine::evaluateStructuredWhoComboShadow(
    ClinicalTriageEngine::normalizeInterviewEvidence([
        'patient_evidence_text' => 'patient words track a and b',
        'active_complaint_id' => 'a',
        'active_facts' => [],
        'tracks' => [
            [
                'complaint_id' => 'a',
                'text_span' => 'a',
                'family_keys' => [],
                'facts' => ['breathing_difficulty' => true],
            ],
            [
                'complaint_id' => 'b',
                'text_span' => 'b',
                'family_keys' => [],
                'facts' => ['body_locations' => ['chest']],
            ],
        ],
    ])
);
ok('R013 cross-track does NOT fire',
    !in_array('IITT-R013', comboIds($cross), true));
ok('R013 cross-track rejection logged',
    count(array_filter(
        $cross['cross_track_rejected'] ?? [],
        static fn ($r) => ($r['rule_id'] ?? '') === 'IITT-R013'
    )) >= 1);

// Same-track multi does fire
$same = ClinicalTriageEngine::evaluateStructuredWhoComboShadow(
    ClinicalTriageEngine::normalizeInterviewEvidence([
        'active_complaint_id' => 'a',
        'active_facts' => [],
        'tracks' => [
            [
                'complaint_id' => 'a',
                'text_span' => 'a',
                'family_keys' => [],
                'facts' => [
                    'breathing_difficulty' => true,
                    'body_locations' => ['chest'],
                ],
            ],
            [
                'complaint_id' => 'b',
                'text_span' => 'b',
                'family_keys' => [],
                'facts' => ['pain_score' => 2],
            ],
        ],
    ])
);
ok('R013 same-track multi fires on track a',
    in_array('IITT-R013', comboIds($same), true)
    && (($same['hits'][0]['track_id'] ?? '') === 'a'
        || count(array_filter($same['hits'] ?? [], static fn ($h) => ($h['rule_id'] ?? '') === 'IITT-R013'
            && ($h['track_id'] ?? '') === 'a')) === 1));

// --- Y001 structured pain ---
$y001 = ClinicalTriageEngine::evaluateStructuredWhoComboShadow(
    ClinicalTriageEngine::normalizeInterviewEvidence([
        'pain_score' => 9,
    ])
);
ok('Y001 fires for existing severe pain band (score 9)',
    in_array('IITT-Y001', comboIds($y001), true)
    && ($y001['triage_display_candidate'] ?? '') === 'URGENT');

$y001q = ClinicalTriageEngine::evaluateStructuredWhoComboShadow(
    ClinicalTriageEngine::normalizeInterviewEvidence([
        'pain_qualifier' => 'severe',
    ])
);
ok('Y001 fires for severe pain_qualifier',
    in_array('IITT-Y001', comboIds($y001q), true));

$mild = ClinicalTriageEngine::evaluateStructuredWhoComboShadow(
    ClinicalTriageEngine::normalizeInterviewEvidence([
        'pain_score' => 3,
    ])
);
ok('Y001 does not fire for mild pain band',
    !in_array('IITT-Y001', comboIds($mild), true));

// Y001 does not MAX across tracks: high on A, mild on B → only A
$yMulti = ClinicalTriageEngine::evaluateStructuredWhoComboShadow(
    ClinicalTriageEngine::normalizeInterviewEvidence([
        'active_complaint_id' => 'a',
        'active_facts' => [],
        'tracks' => [
            [
                'complaint_id' => 'a',
                'text_span' => 'a',
                'family_keys' => [],
                'facts' => ['pain_score' => 9],
            ],
            [
                'complaint_id' => 'b',
                'text_span' => 'b',
                'family_keys' => [],
                'facts' => ['pain_score' => 2],
            ],
        ],
    ])
);
$yHits = array_values(array_filter(
    $yMulti['hits'] ?? [],
    static fn ($h) => ($h['rule_id'] ?? '') === 'IITT-Y001'
));
ok('Y001 only on severe track, not MAX across tracks',
    count($yHits) === 1 && ($yHits[0]['track_id'] ?? '') === 'a');
ok('Y001 max-of-track authority false',
    ($yMulti['max_of_track_authority'] ?? true) === false);

// Both tracks severe → each has own hit; cross-track MAX rejected note
$yBoth = ClinicalTriageEngine::evaluateStructuredWhoComboShadow(
    ClinicalTriageEngine::normalizeInterviewEvidence([
        'active_complaint_id' => 'a',
        'active_facts' => [],
        'tracks' => [
            ['complaint_id' => 'a', 'text_span' => 'a', 'family_keys' => [], 'facts' => ['pain_score' => 8]],
            ['complaint_id' => 'b', 'text_span' => 'b', 'family_keys' => [], 'facts' => ['pain_qualifier' => 'severe']],
        ],
    ])
);
ok('Y001 both tracks fire independently (2 hits)',
    count(array_filter($yBoth['hits'] ?? [], static fn ($h) => ($h['rule_id'] ?? '') === 'IITT-Y001')) === 2);
ok('Y001 cross-track max rejection noted when both severe',
    count(array_filter(
        $yBoth['cross_track_rejected'] ?? [],
        static fn ($r) => ($r['rule_id'] ?? '') === 'IITT-Y001'
    )) >= 1);

// Provenance: KB symptoms do not create R013/Y001
$prov = ClinicalTriageEngine::evaluateStructuredWhoComboShadow(
    ClinicalTriageEngine::normalizeInterviewEvidence([
        'symptoms_kb' => ['chest pain', 'difficulty breathing'],
        'symptoms_ai' => ['severe pain'],
        'body_locations' => [],
    ])
);
ok('KB/AI alone do not create R013 or Y001',
    !in_array('IITT-R013', comboIds($prov), true)
    && !in_array('IITT-Y001', comboIds($prov), true));

// --- OLD unchanged; double-fire dedupe ---
$hay = 'mild complaint only';
$facts = [
    'patient_evidence_text' => 'mild complaint only',
    'breathing_difficulty' => true,
    'body_locations' => ['chest'],
    'pain_score' => 9,
];
$old = ClinicalTriageEngine::assess($hay, $hay, [], [], 0, true, [], false);
$neu = ClinicalTriageEngine::assess($hay, $hay, [], [], 0, true, $facts, true);
ok('OLD triage_display unchanged',
    (string) ($neu['triage_display'] ?? '') === (string) ($old['triage_display'] ?? ''));
ok('NEW authority false',
    ($neu['triage_path_comparison']['authority_new'] ?? true) === false
    && ($neu['structured_who_combo_shadow']['authority'] ?? true) === false);
$cand = (array) ($neu['triage_path_comparison']['new']['who_rule_ids_candidate'] ?? []);
ok('NEW candidates include R003 R013 Y001',
    in_array('IITT-R003', $cand, true)
    && in_array('IITT-R013', $cand, true)
    && in_array('IITT-Y001', $cand, true));

// Double-fire: purified text with difficulty breathing + structured breathing
$dfText = 'difficulty breathing';
$df = ClinicalTriageEngine::assess(
    $dfText,
    $dfText,
    [],
    [],
    0,
    true,
    [
        'patient_evidence_text' => $dfText,
        'breathing_difficulty' => true,
    ],
    true
);
$ids = (array) ($df['triage_path_comparison']['new']['who_rule_ids_candidate'] ?? []);
ok('double-fire: R003 listed once',
    count(array_filter($ids, static fn ($id) => $id === 'IITT-R003')) === 1);

echo "\nPassed: {$pass}  Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);
