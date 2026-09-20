<?php
/**
 * Stage 2 Batch 2: universal structured evidence normalization + feature seeding.
 *
 * php scripts/dev/test_structured_evidence_normalization.php
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

// --- Empty interviewFacts ---
$empty = ClinicalTriageEngine::normalizeInterviewEvidence([]);
ok('empty mode', ($empty['mode'] ?? '') === 'empty');
ok('empty polarity keys present as unknown', array_key_exists('breathing_difficulty', $empty['clinical']['polarity'] ?? [])
    && array_key_exists('breathing_difficulty', $empty['clinical']['polarity'])
    && $empty['clinical']['polarity']['breathing_difficulty'] === null);
ok('empty tracks', ($empty['tracks'] ?? null) === []);

// --- Single bag: polarity tri-state + provenance + interview-only exclusion ---
$singleFacts = [
    'pain_score' => 8,
    'pain_qualifier' => 'severe',
    'onset' => 'sudden',
    'duration_label' => '2 days',
    'progression' => 'worsening',
    'body_locations' => ['chest', 'head'],
    'symptoms_patient' => ['cough'],
    'symptoms_kb' => ['Tension Headache KB'],
    'symptoms_ai' => ['Gloss AI'],
    'associated_symptoms' => ['cough'],
    'negative_symptoms' => ['vomiting'],
    'risk_factors' => ['asthma'],
    'vital_signs' => [],
    'medical_history' => [],
    'breathing_difficulty' => true,
    'bleeding_heavy' => false,
    'dizziness' => null,
    'needs_associated_detail' => true,
    'has_other_symptoms' => true,
    'patient_uncertain' => true,
    'denied_associated' => false,
    'finding_status' => ['urinary_burning' => 'positive'],
];
$single = ClinicalTriageEngine::normalizeInterviewEvidence($singleFacts);
ok('single mode', ($single['mode'] ?? '') === 'single');
ok('positive polarity preserved', ($single['clinical']['polarity']['breathing_difficulty'] ?? null) === true);
ok('negative polarity preserved', ($single['clinical']['polarity']['bleeding_heavy'] ?? null) === false);
ok('unknown polarity preserved', array_key_exists('dizziness', $single['clinical']['polarity'])
    && $single['clinical']['polarity']['dizziness'] === null);
ok('unset polarity unknown', array_key_exists('pregnancy', $single['clinical']['polarity'])
    && $single['clinical']['polarity']['pregnancy'] === null);
ok('patient provenance', ($single['clinical']['symptoms_patient'] ?? []) === ['cough']);
ok('kb provenance', ($single['clinical']['symptoms_kb'] ?? []) === ['Tension Headache KB']);
ok('ai provenance', ($single['clinical']['symptoms_ai'] ?? []) === ['Gloss AI']);
ok('negatives preserved', ($single['clinical']['negative_symptoms'] ?? []) === ['vomiting']);
ok('interview-only excluded from clinical top-level',
    !array_key_exists('needs_associated_detail', $single['clinical'])
    && !array_key_exists('has_other_symptoms', $single['clinical'])
    && !array_key_exists('patient_uncertain', $single['clinical']));
ok('interview-only captured separately',
    !empty($single['interview_only']['needs_associated_detail'])
    && !empty($single['interview_only']['patient_uncertain']));
ok('finding_status retained under clinical',
    ($single['clinical']['finding_status']['urinary_burning'] ?? '') === 'positive');

// --- Multi-track: isolation, no flatten ---
$multiPack = [
    'active_complaint_id' => 't1',
    'patient_evidence_text' => 'sakit dughan kag sakit tiyan',
    'active_facts' => [
        'symptoms_patient' => ['cough'],
        'breathing_difficulty' => true,
        'needs_associated_detail' => true,
    ],
    'tracks' => [
        [
            'complaint_id' => 't1',
            'text_span' => 'sakit dughan',
            'family_keys' => ['chest_pain'],
            'facts' => [
                'symptoms_patient' => ['cough'],
                'symptoms_kb' => ['Chest KB'],
                'breathing_difficulty' => true,
                'pain_score' => 6,
            ],
        ],
        [
            'complaint_id' => 't2',
            'text_span' => 'sakit tiyan',
            'family_keys' => ['abdominal'],
            'facts' => [
                'symptoms_patient' => ['pain'],
                'abdominal_associated' => true,
                'breathing_difficulty' => null,
                'pain_score' => 3,
            ],
        ],
    ],
];
$multi = ClinicalTriageEngine::normalizeInterviewEvidence($multiPack);
ok('multi mode', ($multi['mode'] ?? '') === 'multi');
ok('multi preserves patientEvidenceText',
    ($multi['patient_evidence_text'] ?? '') === 'sakit dughan kag sakit tiyan');
ok('multi has 2 tracks', count($multi['tracks'] ?? []) === 2);
ok('multi track0 patient cough isolated',
    ($multi['tracks'][0]['clinical']['symptoms_patient'] ?? []) === ['cough']);
ok('multi track1 patient pain isolated',
    ($multi['tracks'][1]['clinical']['symptoms_patient'] ?? []) === ['pain']);
ok('multi track0 kb not on track1',
    !in_array('Chest KB', $multi['tracks'][1]['clinical']['symptoms_kb'] ?? [], true));
ok('multi track1 polarity not merged into track0 abdominal',
    ($multi['tracks'][0]['clinical']['polarity']['abdominal_associated'] ?? null) === null);
ok('multi track metadata preserved',
    ($multi['tracks'][0]['family_keys'][0] ?? '') === 'chest_pain'
    && ($multi['tracks'][1]['text_span'] ?? '') === 'sakit tiyan');
ok('multi does not invent flat synthetic symptoms list at root',
    !isset($multi['synthetic_text']) && !isset($multi['For']));

// --- Feature seeding: gap-fill only, reuse existing extractors ---
$baseFeatures = ClinicalFeatureExtractors::extractAll('hello', 'hello', []);
$seeded = ClinicalTriageEngine::seedFeaturesFromStructuredEvidence($baseFeatures, $single);
ok('seed pain when missing', ($seeded['pain_scale']['score'] ?? null) === 8);
ok('seed onset when missing', ($seeded['onset'] ?? '') === 'sudden');
ok('seed body locations when missing', in_array('chest', $seeded['body_locations'] ?? [], true));
ok('seed attaches provenance snapshot',
    ($seeded['structured_symptom_provenance']['patient'] ?? []) === ['cough']
    && ($seeded['structured_symptom_provenance']['kb'] ?? []) === ['Tension Headache KB']
    && ($seeded['structured_symptom_provenance']['ai'] ?? []) === ['Gloss AI']);
ok('seed attaches polarity snapshot',
    ($seeded['structured_polarity']['breathing_difficulty'] ?? null) === true
    && ($seeded['structured_polarity']['bleeding_heavy'] ?? null) === false);

// Do not overwrite existing text-extracted pain
$withPain = ClinicalFeatureExtractors::extractAll('pain 4/10', 'pain 4/10', []);
$notOverwritten = ClinicalTriageEngine::seedFeaturesFromStructuredEvidence($withPain, $single);
ok('seed does not overwrite existing pain score',
    ($notOverwritten['pain_scale']['score'] ?? null) === 4);

// --- assess() exposes structured_clinical_evidence; empty facts OK ---
$plain = ClinicalTriageEngine::assess('mild headache for two days', 'mild headache for two days');
ok('assess empty facts has structured_clinical_evidence',
    isset($plain['structured_clinical_evidence'])
    && ($plain['structured_clinical_evidence']['mode'] ?? '') === 'empty');

$withFacts = ClinicalTriageEngine::assess(
    'mild headache for two days',
    'mild headache for two days',
    [],
    [],
    0,
    true,
    $singleFacts
);
ok('assess with facts exposes normalized mode single',
    ($withFacts['structured_clinical_evidence']['mode'] ?? '') === 'single');
ok('assess with facts keeps interview_facts echo',
    ($withFacts['interview_facts']['pain_score'] ?? null) === 8);

// OLD path parity: haystack-equivalent text already contains structured phrases → class unchanged
$hay = 'mild headache for two days. pain 8/10. sudden onset. cough. no vomiting. difficulty breathing. no heavy bleeding';
$oldA = ClinicalTriageEngine::assess($hay, $hay);
$oldB = ClinicalTriageEngine::assess($hay, $hay, [], [], 0, true, $singleFacts);
ok('OLD haystack path: triage_display unchanged with redundant interviewFacts',
    (string) ($oldA['triage_display'] ?? '') === (string) ($oldB['triage_display'] ?? ''),
    ($oldA['triage_display'] ?? '') . ' vs ' . ($oldB['triage_display'] ?? '')
);

// Multilingual patientEvidenceText in multi pack unchanged by normalize
$mixedPack = $multiPack;
$mixedPack['patient_evidence_text'] = 'Budlay ginhawa ko and masakit ang tiyan';
$mixedNorm = ClinicalTriageEngine::normalizeInterviewEvidence($mixedPack);
ok('multilingual patientEvidenceText preserved exactly',
    ($mixedNorm['patient_evidence_text'] ?? '') === 'Budlay ginhawa ko and masakit ang tiyan');

// No complaint-specific hardcoding: arbitrary domain fact keys still normalize polarity list
$arb = ClinicalTriageEngine::normalizeFactBag([
    'vision_change' => true,
    'symptoms_patient' => ['any-domain-label'],
    'needs_associated_detail' => true,
]);
ok('arbitrary domain patient symptom accepted',
    ($arb['clinical']['symptoms_patient'] ?? []) === ['any-domain-label']);
ok('arbitrary domain polarity works without specialty branch',
    ($arb['clinical']['polarity']['vision_change'] ?? null) === true);
ok('interview-only still stripped for arbitrary domain',
    isset($arb['interview_only']['needs_associated_detail']));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
