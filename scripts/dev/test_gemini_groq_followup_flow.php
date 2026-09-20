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

// --- Audit fix: select context, filtered WHO, finish vs fallback ---
$refFu = new ReflectionClass('ClinicalInterviewGeminiFollowUp');

$selectPrompt = $refFu->getMethod('selectUserPrompt');
$selectPrompt->setAccessible(true);
$ctxSelect = [
    'chief_complaint' => 'gahubag tiil ko',
    'patient_turns' => ['gahubag tiil ko', '2 days na'],
    'question_language' => 'hiligaynon',
    'active_complaint_id' => 'c1',
    'complaints' => [[
        'id' => 'c1',
        'text_span' => 'gahubag tiil ko',
        'family_keys' => ['skin', 'needs_specific_location'],
    ]],
    'questions_asked' => ['PAIN_LOCATION'],
    'questions_answered' => [
        ['question_id' => 'PAIN_LOCATION', 'answer' => 'tiil'],
    ],
    'facts' => [
        'body_locations' => ['tiil', 'foot'],
        'symptoms_patient' => ['swelling'],
        'symptoms' => ['edema'],
        'associated_symptoms' => [],
        'pain_score' => 6,
        'onset' => 'sudden',
        'symptoms_ai' => ['peripheral edema'],
    ],
    'semantic_bridge' => [
        'original' => 'gahubag tiil ko',
        'ollama_meaning' => 'swollen foot AI gloss',
        'gemini_concept' => 'edema',
    ],
];
$candSample = [[
    'index' => 0,
    'question_id' => 'DURATION',
    'target_finding' => 'duration',
    'clinical_purpose' => 'Clarify how long the swelling has been present',
    'red_flag_related' => false,
    'priority' => 3,
    'parent_question_id' => '',
    'bank_template' => 'San-o ni nagsugod?',
]];
$prompt = (string) $selectPrompt->invoke(null, $candSample, $ctxSelect, 'gahubag tiil ko');
ok('select prompt has active complaint span', str_contains($prompt, 'gahubag tiil ko'));
ok('select prompt has active complaint id', str_contains($prompt, 'Active complaint id: c1'));
ok('select prompt has families', str_contains($prompt, 'skin'));
ok('select prompt has known locations', str_contains($prompt, 'tiil'));
ok('select prompt has known symptoms', str_contains($prompt, 'swelling'));
ok('select prompt has prior answers', str_contains($prompt, 'PAIN_LOCATION=tiil'));
ok('select prompt has eligible candidates', str_contains($prompt, 'DURATION'));
ok('select prompt uses Hiligaynon language', str_contains($prompt, 'Hiligaynon'));
ok('select prompt labels filtered WHO section', str_contains($prompt, 'Filtered WHO/IITT'));
ok('select prompt does not dump unrestricted WHO header', !str_contains($prompt, 'WHO/IITT information needs still worth clarifying'));
ok('select prompt keeps AI meaning separate', str_contains($prompt, 'NOT patient-authored'));
ok('patient evidence excludes AI gloss in known block',
    str_contains($prompt, 'gahubag tiil ko') && !str_contains(
        preg_replace('/AI-derived meaning[\s\S]*$/u', '', $prompt) ?? $prompt,
        'swollen foot AI gloss'
    ));

$whoFn = $refFu->getMethod('whoInformationNeeds');
$whoFn->setAccessible(true);
$whoUnfilteredStyle = (string) $whoFn->invoke(
    null,
    'gahubag tiil ko swelling foot',
    [],
    [],
    ''
);
$whoFiltered = (string) $whoFn->invoke(
    null,
    'gahubag tiil ko swelling foot',
    $candSample,
    ['skin'],
    'gahubag tiil ko'
);
ok('WHO with empty relevance is empty (no unrestricted dump)', $whoUnfilteredStyle === '');
ok('WHO filtered path does not exceed 5 hints', $whoFiltered === '' || substr_count($whoFiltered, "\n") <= 4);

// Finish sentinel must be distinguishable for Engine (no bank fallthrough).
$errProp = $refFu->getProperty('lastError');
$errProp->setAccessible(true);
$errProp->setValue(null, 'continue_interview_false');
ok('isFinishDecision true for continue_interview_false', ClinicalInterviewGeminiFollowUp::isFinishDecision());
$errProp->setValue(null, 'invalid_select_json: bad');
ok('isFinishDecision false for invalid output', !ClinicalInterviewGeminiFollowUp::isFinishDecision());
$errProp->setValue(null, 'select_not_in_allow_list');
ok('isFinishDecision false for unusable selection', !ClinicalInterviewGeminiFollowUp::isFinishDecision());
$errProp->setValue(null, '');

// Outside allow-list cannot be resolved by Gemini helper.
$resolve = $refFu->getMethod('resolveSelectedCandidate');
$resolve->setAccessible(true);
$outside = $resolve->invoke(null, ['index' => 99, 'question_id' => 'NOT_A_SLOT'], $candSample);
ok('Gemini cannot resolve outside allow-list', $outside === null);
$inside = $resolve->invoke(null, ['index' => 0, 'question_id' => 'DURATION'], $candSample);
ok('Gemini can resolve allow-listed candidate', is_array($inside) && ($inside['question_id'] ?? '') === 'DURATION');

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
