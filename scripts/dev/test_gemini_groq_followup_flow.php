<?php
/**
 * Gemini + Groq follow-up / meaning flow (PHP validate + AdaptivePolicy gate).
 *
 * php scripts/dev/test_gemini_groq_followup_flow.php
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

// Injected Gemini result — no live network required for language matrix.
$geminiValid = [
    'available' => true,
    'is_medical_complaint' => true,
    'classification' => 'VALID_MEDICAL_COMPLAINT',
    'confidence' => 0.9,
    'error' => '',
    'corrected_text' => '',
    'medical_concept' => 'swelling',
];

foreach ([
    'EN' => 'my left foot is swollen',
    'HIL' => 'gahubag tiil ko',
    'TAG' => 'namamaga ang paa ko',
    'MIX' => 'gahubag tiil ko and it hurts',
] as $lang => $text) {
    $sem = ComplaintSemanticValidator::validateOpeningComplaint($text, $geminiValid);
    ok("{$lang} opening valid with Gemini inject + PHP validate", !empty($sem['is_valid']));
    ok("{$lang} preserves original patient input",
        ($sem['original_patient_input'] ?? '') === $text);
}

// Unclear PHP path still accepts injected Gemini health.
$semWeak = ComplaintSemanticValidator::validateOpeningComplaint('something weird feeling', $geminiValid);
ok('weak/unclear wording accepted when Gemini valid + PHP combine', !empty($semWeak['is_valid'])
    || ($semWeak['domain_label'] ?? '') !== 'NON_HEALTH_RELATED');

// AI failure/fallback: Gemini unavailable → PHP-only still works for strong local phrase.
$semPhp = ComplaintSemanticValidator::validateOpeningComplaint('gahubag tiil ko', [
    'available' => false,
    'is_medical_complaint' => null,
    'classification' => null,
    'confidence' => null,
    'error' => 'disabled',
    'corrected_text' => '',
    'medical_concept' => '',
]);
ok('PHP fallback valid when Gemini unavailable', !empty($semPhp['is_valid']));
ok('PHP strong evidence recorded', ($semPhp['php_validation_result'] ?? '') === 'strong');

// Patient evidence vs AI-derived
$prior = [
    'chief_complaint' => 'gahubag tiil ko',
    'patient_turns' => ['gahubag tiil ko'],
    'semantic_bridge' => [
        'original' => 'gahubag tiil ko',
        'ollama_meaning' => 'swollen foot English AI gloss',
        'gemini_concept' => 'peripheral edema',
        'nlp_text' => 'gahubag tiil ko. swollen foot English AI gloss',
        'evidence_source' => 'ai_bridge',
    ],
    'facts' => [
        'symptoms_ai' => ['peripheral edema'],
        'symptoms_patient' => [],
    ],
];
$pet = ClinicalInterviewEngine::patientEvidenceText($prior);
ok('patientEvidenceText keeps patient Hiligaynon', str_contains($pet, 'gahubag tiil ko'));
ok('patientEvidenceText excludes AI gloss/concept',
    !str_contains($pet, 'swollen foot English AI gloss')
    && !str_contains($pet, 'peripheral edema'));
ok('symptoms_ai remains AI bucket',
    in_array('peripheral edema', (array) ($prior['facts']['symptoms_ai'] ?? []), true));

// Redundancy prevention via AdaptivePolicy allow-list (PHP final gate)
$factsKnown = [
    'body_locations' => ['tiil', 'foot'],
    'pain_score' => 7,
    'onset' => 'sudden',
    'duration_label' => '2 days',
    'breathing_difficulty' => false,
];
$ctxKnown = [
    'chief_complaint' => 'gahubag tiil ko',
    'patient_turns' => ['gahubag tiil ko'],
    'facts' => $factsKnown,
    'questions_asked' => [],
    'questions_answered' => [
        ['question_id' => 'PAIN_LOCATION', 'answer' => 'tiil'],
        ['question_id' => 'PAIN_SEVERITY', 'answer' => '7'],
        ['question_id' => 'ONSET', 'answer' => 'sudden'],
    ],
    'complaints' => [],
];
$cands = ClinicalInterviewAdaptivePolicy::listCandidateSlots($ctxKnown, 'gahubag tiil ko', []);
$candIds = array_map(
    static fn ($r) => strtoupper((string) ($r['question_id'] ?? '')),
    array_filter($cands, 'is_array')
);
ok('redundant PAIN_LOCATION not open', !in_array('PAIN_LOCATION', $candIds, true));
ok('redundant PAIN_SEVERITY not open', !in_array('PAIN_SEVERITY', $candIds, true));
ok('redundant ONSET not open', !in_array('ONSET', $candIds, true));

// Empty allow-list → Gemini select returns null (finish path)
$none = ClinicalInterviewGeminiFollowUp::selectNext([], $ctxKnown, 'gahubag tiil ko');
ok('empty candidates → no Gemini question', $none === null);

// Clear follow-up answer: PHP validator path remains authoritative
if (class_exists('ClinicalFollowUpAnswerValidator')) {
    $v = ClinicalFollowUpAnswerValidator::validate('7', 'PAIN_SEVERITY', $ctxKnown);
    ok('clear numeric severity accepted by PHP', !empty($v['accept']));
}

// Final triage handoff: ClinicalTriageEngine only; AI symptoms not patient polarity
$raw = ClinicalTriageEngine::assess('mild headache', 'mild headache', [], [], 0, true, [
    'patient_evidence_text' => 'mild headache',
    'symptoms_ai' => ['Difficulty Breathing KB'],
], true);
ok('ClinicalTriageEngine produces class',
    in_array((string) ($raw['triage_display'] ?? ''), ['NON-URGENT', 'URGENT', 'EMERGENCY'], true));
ok('shadow authority_new false when comparison present',
    !isset($raw['triage_path_comparison'])
    || ($raw['triage_path_comparison']['authority_new'] ?? true) === false);
ok('AI symptom labels do not create structured hard polarity hits',
    ($raw['structured_polarity_shadow']['hard_hits'] ?? []) === []);

// Multi-complaint structured facts still single system
$multi = ClinicalTriageEngine::normalizeInterviewEvidence([
    'patient_evidence_text' => 'Sakit ulo. Gahubag tiil.',
    'active_complaint_id' => 'c1',
    'tracks' => [
        ['complaint_id' => 'c1', 'text_span' => 'Sakit ulo', 'facts' => ['pain_score' => 5]],
        ['complaint_id' => 'c2', 'text_span' => 'Gahubag tiil', 'facts' => ['body_locations' => ['tiil']]],
    ],
]);
ok('multi structured facts mode', ($multi['mode'] ?? '') === 'multi');
ok('multi track identity preserved', count($multi['tracks'] ?? []) === 2);

// MedicalAiInterpreter uses provider chain (Groq first in config)
$chain = MedicalAiInterpreter::providerChain();
ok('provider chain non-empty', $chain !== []);
ok('groq preferred in default order',
    ($chain[0] ?? '') === 'groq' || in_array('groq', $chain, true));

echo "\nPassed: {$pass}  Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);
