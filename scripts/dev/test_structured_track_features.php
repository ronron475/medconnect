<?php
/**
 * Stage 2 Batch 5: multi-track structured feature coverage (track-isolated).
 *
 * php scripts/dev/test_structured_track_features.php
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

// --- Single-complaint feature parity (Batch 2 behavior preserved) ---
$singleFacts = [
    'patient_evidence_text' => 'masakit ang dibdib',
    'pain_score' => 8,
    'pain_qualifier' => 'severe',
    'onset' => 'sudden',
    'duration_label' => '2 days',
    'progression' => 'worsening',
    'body_locations' => ['chest'],
    'pregnancy' => true,
    'fever_confirmed' => true,
    'symptoms_patient' => ['cough'],
    'symptoms_kb' => ['KB Label'],
    'symptoms_ai' => ['AI Label'],
    'associated_symptoms' => ['nausea'],
    'vital_signs' => ['hr elevated'],
    'medical_history' => ['asthma'],
    'finding_status' => ['urinary_burning' => 'positive'],
];
$singleEv = ClinicalTriageEngine::normalizeInterviewEvidence($singleFacts);
$singleSeeded = ClinicalTriageEngine::seedFeaturesFromStructuredEvidence([], $singleEv);

ok('single pain seeded', ($singleSeeded['pain_scale']['score'] ?? null) === 8);
ok('single qualifier seeded', ($singleSeeded['pain_qualifier'] ?? '') === 'severe');
ok('single onset seeded', ($singleSeeded['onset'] ?? '') === 'sudden');
ok('single duration seeded', ($singleSeeded['duration']['label'] ?? '') !== '');
ok('single locations seeded', ($singleSeeded['body_locations'] ?? []) === ['chest']);
ok('single pregnancy→risk seeded', ($singleSeeded['risk_factors'] ?? []) !== []);
ok('single fever→temp seeded', trim((string) ($singleSeeded['temperature']['modifier_key'] ?? '')) !== ''
    || trim((string) ($singleSeeded['temperature']['label'] ?? '')) !== '');
ok('single track_features has one record', count($singleSeeded['structured_track_features'] ?? []) === 1);
ok('single track provenance patient vs kb',
    ($singleSeeded['structured_track_features'][0]['provenance']['patient'] ?? []) === ['cough']
    && ($singleSeeded['structured_track_features'][0]['provenance']['kb'] ?? []) === ['KB Label']
    && ($singleSeeded['structured_track_features'][0]['provenance']['ai'] ?? []) === ['AI Label']);
ok('single available structured fields present without new triage rule',
    ($singleSeeded['structured_track_features'][0]['available_structured_fields']['progression'] ?? '') === 'worsening'
    && ($singleSeeded['structured_track_features'][0]['available_structured_fields']['vital_signs'] ?? []) === ['hr elevated']
    && ($singleSeeded['structured_track_features'][0]['field_consumer_status']['progression'] ?? '')
        === 'stored_no_independent_triage_consumer');

// --- Multi: non-active track features available; isolation preserved ---
$multiPack = [
    'patient_evidence_text' => 'Sakit ulo. May dugo.',
    'active_complaint_id' => 'c1',
    'active_facts' => [
        'pain_score' => 3,
        'onset' => 'gradual',
        'body_locations' => ['head'],
        'breathing_difficulty' => true,
        'symptoms_patient' => ['headache'],
        'symptoms_kb' => ['KB Head'],
    ],
    'tracks' => [
        [
            'complaint_id' => 'c1',
            'text_span' => 'Sakit ulo',
            'family_keys' => ['headache'],
            'facts' => [
                'pain_score' => 3,
                'onset' => 'gradual',
                'body_locations' => ['head'],
                'breathing_difficulty' => true,
                'symptoms_patient' => ['headache'],
                'symptoms_kb' => ['KB Head'],
                'progression' => 'stable',
            ],
        ],
        [
            'complaint_id' => 'c2',
            'text_span' => 'May dugo',
            'family_keys' => ['bleeding'],
            'facts' => [
                // Non-active track: distinct features that must NOT appear on flat active bag
                // and must remain track-c2 only.
                'pain_score' => 9,
                'pain_qualifier' => 'severe',
                'onset' => 'sudden',
                'duration_label' => '1 hour',
                'body_locations' => ['abdomen'],
                'bleeding_continuing' => true,
                'bleeding_heavy' => false,
                'fever_confirmed' => true,
                'pregnancy' => false,
                'symptoms_patient' => ['bleeding'],
                'symptoms_ai' => ['AI Bleed'],
                'associated_symptoms' => ['dizziness'],
                'vital_signs' => ['bp low'],
                'medical_history' => ['anemia'],
                'finding_status' => ['dizziness_with_chest' => 'negative'],
                'progression' => 'worsening',
            ],
        ],
    ],
];

$multiEv = ClinicalTriageEngine::normalizeInterviewEvidence($multiPack);
$multiSeeded = ClinicalTriageEngine::seedFeaturesFromStructuredEvidence([], $multiEv);

ok('multi flat bag uses active pain only (no flatten)',
    ($multiSeeded['pain_scale']['score'] ?? null) === 3);
ok('multi flat bag does not absorb non-active abdomen location',
    !in_array('abdomen', $multiSeeded['body_locations'] ?? [], true)
    && ($multiSeeded['body_locations'] ?? []) === ['head']);
ok('multi flat bag does not absorb non-active sudden onset',
    ($multiSeeded['onset'] ?? '') === 'gradual');

$tfs = $multiSeeded['structured_track_features'] ?? [];
ok('multi track_features count is 2', count($tfs) === 2);

$t1 = $tfs[0] ?? [];
$t2 = $tfs[1] ?? [];
ok('track1 identity', ($t1['track_id'] ?? '') === 'c1' && ($t1['is_active'] ?? false) === true);
ok('track2 identity', ($t2['track_id'] ?? '') === 'c2' && ($t2['is_active'] ?? true) === false);

ok('non-active track2 pain seeded in track snapshot',
    ($t2['seeded_features']['pain_scale']['score'] ?? null) === 9);
ok('non-active track2 onset/qualifier/duration/locations seeded',
    ($t2['seeded_features']['onset'] ?? '') === 'sudden'
    && ($t2['seeded_features']['pain_qualifier'] ?? '') === 'severe'
    && trim((string) ($t2['seeded_features']['duration']['label'] ?? '')) !== ''
    && ($t2['seeded_features']['body_locations'] ?? []) === ['abdomen']);
ok('non-active track2 fever seeds temperature in track snapshot',
    trim((string) ($t2['seeded_features']['temperature']['modifier_key'] ?? '')) !== ''
    || trim((string) ($t2['seeded_features']['temperature']['label'] ?? '')) !== '');

ok('track1 does not receive track2 pain',
    ($t1['seeded_features']['pain_scale']['score'] ?? null) === 3);
ok('track1 does not receive track2 abdomen',
    ($t1['seeded_features']['body_locations'] ?? []) === ['head']);
ok('track2 does not receive track1 head location',
    ($t2['seeded_features']['body_locations'] ?? []) === ['abdomen']);

ok('track polarity isolated',
    ($t1['structured_polarity']['breathing_difficulty'] ?? null) === true
    && ($t1['structured_polarity']['bleeding_continuing'] ?? null) === null
    && ($t2['structured_polarity']['bleeding_continuing'] ?? null) === true
    && ($t2['structured_polarity']['breathing_difficulty'] ?? null) === null);

ok('provenance isolated per track',
    ($t1['provenance']['patient'] ?? []) === ['headache']
    && ($t1['provenance']['kb'] ?? []) === ['KB Head']
    && ($t2['provenance']['patient'] ?? []) === ['bleeding']
    && ($t2['provenance']['ai'] ?? []) === ['AI Bleed']
    && ($t2['provenance']['kb'] ?? []) === []);

ok('structured fields available on non-active track without new rules',
    ($t2['available_structured_fields']['progression'] ?? '') === 'worsening'
    && ($t2['available_structured_fields']['vital_signs'] ?? []) === ['bp low']
    && ($t2['available_structured_fields']['medical_history'] ?? []) === ['anemia']
    && ($t2['available_structured_fields']['associated_symptoms'] ?? []) === ['dizziness']
    && ($t2['available_structured_fields']['finding_status']['dizziness_with_chest'] ?? '') === 'negative'
    && ($t2['field_consumer_status']['vital_signs'] ?? '') === 'stored_no_independent_triage_consumer');

ok('no cross-track merge flags',
    ($t1['cross_track_merged'] ?? true) === false
    && ($t2['cross_track_merged'] ?? true) === false);

// Cross-track combination protection: seeding two tracks must not create a combined
// flat feature bag with both abdomen + head from different tracks.
ok('flat features are not a union of all track locations',
    !((in_array('head', $multiSeeded['body_locations'] ?? [], true)
        && in_array('abdomen', $multiSeeded['body_locations'] ?? [], true))));

// --- Path comparison exposes track features; OLD authority unchanged ---
$hay = 'Sakit ulo. May dugo. For "Sakit ulo": difficulty breathing';
$oldPlain = ClinicalTriageEngine::assess($hay, $hay, [], [], 0, true, [], false);
$with = ClinicalTriageEngine::assess($hay, $hay, [], [], 0, true, $multiPack, true);
$cmp = $with['triage_path_comparison'] ?? [];

ok('OLD display unchanged',
    (string) ($with['triage_display'] ?? '') === (string) ($oldPlain['triage_display'] ?? ''));
ok('NEW authority false', ($cmp['authority_new'] ?? true) === false);
ok('comparison has all track features',
    (int) ($cmp['multi_track']['track_feature_count'] ?? 0) === 2
    && ($cmp['multi_track']['uses_all_tracks_for_features'] ?? false) === true);
ok('non-active seeded features counted',
    (int) ($cmp['multi_track']['non_active_tracks_with_seeded_features'] ?? 0) >= 1);
ok('cross_track_feature_merge false',
    ($cmp['multi_track']['cross_track_feature_merge'] ?? true) === false);
ok('NEW patient text purified',
    ($cmp['new']['patient_evidence_text'] ?? '') === 'Sakit ulo. May dugo.'
    && !str_contains((string) ($cmp['new']['patient_evidence_text'] ?? ''), 'For "'));
ok('case-level patient text limitation documented',
    ($cmp['case_level_patient_text']['is_case_level'] ?? false) === true
    && ($cmp['case_level_patient_text']['may_combine_narratives_in_text_path'] ?? false) === true
    && ($cmp['case_level_patient_text']['structured_tracks_remain_isolated'] ?? false) === true);

// Case-level patient text: WHO/text path can see both narratives in one string (existing limitation).
$caseText = 'headache and stiff neck with fever for two days';
$casePack = [
    'patient_evidence_text' => $caseText,
    'active_complaint_id' => 'a',
    'active_facts' => [],
    'tracks' => [
        ['complaint_id' => 'a', 'text_span' => 'headache', 'family_keys' => [], 'facts' => []],
        ['complaint_id' => 'b', 'text_span' => 'fever', 'family_keys' => [], 'facts' => ['fever_confirmed' => true]],
    ],
];
$caseNew = ClinicalTriageEngine::assess($caseText, $caseText, [], [], 0, false, $casePack, false);
ok('case-level purified assess receives full patientEvidenceText string',
    str_contains($caseText, 'headache') && str_contains($caseText, 'fever')
    && ($caseNew['structured_clinical_evidence']['patient_evidence_text'] ?? '') === $caseText);
ok('structured tracks still isolated while text is case-level',
    count($caseNew['structured_track_features'] ?? []) === 2
    && ($caseNew['structured_track_features'][1]['structured_polarity']['fever_confirmed'] ?? null) === true
    && ($caseNew['structured_track_features'][0]['structured_polarity']['fever_confirmed'] ?? null) === null);

// symptoms_patient not injected into patient_evidence_text
ok('canonical symptoms_patient not written into patientEvidenceText',
    ($cmp['new']['patient_evidence_text'] ?? '') === 'Sakit ulo. May dugo.'
    && !str_contains((string) ($cmp['new']['patient_evidence_text'] ?? ''), 'KB Head'));

echo "\nPassed: {$pass}  Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);
