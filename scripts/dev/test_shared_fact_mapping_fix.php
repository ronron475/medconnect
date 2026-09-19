<?php
/**
 * Focused regression: bleeding_with_abdomen / dizziness_with_chest shared-fact fixes.
 */
require __DIR__ . '/nlp_cli_bootstrap.php';

$fail = 0;
$pass = 0;

function ok(string $label, bool $cond): void
{
    global $fail, $pass;
    if ($cond) {
        echo "PASS  $label\n";
        $pass++;
    } else {
        echo "FAIL  $label\n";
        $fail++;
    }
}

$engine = new ReflectionClass('ClinicalInterviewEngine');
$polarity = $engine->getMethod('applyTargetFindingPolarity');
$polarity->setAccessible(true);
$af = $engine->getMethod('applyFollowUpValidationFacts');
$af->setAccessible(true);

$policy = new ReflectionClass('ClinicalInterviewAdaptivePolicy');
$already = $policy->getMethod('alreadyAnswered');
$already->setAccessible(true);
$known = $policy->getMethod('findingAlreadyKnown');
$known->setAccessible(true);
$purpose = $policy->getMethod('purposeAlreadyKnown');
$purpose->setAccessible(true);

function baseFacts(array $extra = []): array
{
    return array_merge([
        'body_locations' => [],
        'pain_score' => null,
        'onset' => '',
        'duration_label' => '',
        'symptoms' => [],
        'associated_symptoms' => [],
        'negative_symptoms' => [],
        'has_other_symptoms' => null,
        'denied_associated' => false,
        'needs_associated_detail' => false,
        'weakness' => null,
        'speech_difficulty' => null,
        'vision_change' => null,
        'breathing_difficulty' => null,
        'bleeding_continuing' => null,
        'bleeding_heavy' => null,
        'dizziness' => null,
        'chest_radiation' => null,
        'sweating' => null,
        'abdominal_associated' => null,
        'finding_status' => [],
        'patient_uncertain' => false,
        'fever_confirmed' => null,
    ], $extra);
}

// --- BUG 1: bleeding_with_abdomen must not set bleeding_continuing ---
$f = $polarity->invoke(null, baseFacts(), 'bleeding_with_abdomen', true);
ok('bleed atom YES → finding_status positive', ($f['finding_status']['bleeding_with_abdomen'] ?? '') === 'positive');
ok('bleed atom YES → bleeding_continuing NOT set', ($f['bleeding_continuing'] ?? null) === null);
ok('bleed atom YES → BLEEDING_CONTINUING unanswered',
    !$already->invoke(null, 'BLEEDING_CONTINUING', $f, '', ['bleeding'], ''));

$fNo = $polarity->invoke(null, baseFacts(), 'bleeding_with_abdomen', false);
ok('bleed atom NO → finding_status negative', ($fNo['finding_status']['bleeding_with_abdomen'] ?? '') === 'negative');
ok('bleed atom NO → bleeding_continuing untouched', ($fNo['bleeding_continuing'] ?? null) === null);

$fLegit = $polarity->invoke(null, baseFacts(), 'bleeding_continuing', true);
ok('legitimate bleeding_continuing YES still works', ($fLegit['bleeding_continuing'] ?? null) === true);
ok('BLEEDING_CONTINUING answered after legitimate producer',
    $already->invoke(null, 'BLEEDING_CONTINUING', $fLegit, '', ['bleeding'], ''));

// --- BUG 2: dizziness_with_chest must not close DIZZINESS_TYPE ---
$dYes = $polarity->invoke(null, baseFacts(), 'dizziness_with_chest', true);
ok('chest dizzy YES → finding_status positive', ($dYes['finding_status']['dizziness_with_chest'] ?? '') === 'positive');
ok('chest dizzy YES → shared dizziness NOT set', ($dYes['dizziness'] ?? null) === null);
ok('chest dizzy YES → DIZZINESS_TYPE NOT answered',
    !$already->invoke(null, 'DIZZINESS_TYPE', $dYes, 'chest pain', ['chest_pain', 'dizziness'], ''));
$hayYes = ClinicalInterviewEngine::factsHaystack($dYes);
ok('chest dizzy YES → WHO haystack still has dizziness', str_contains($hayYes, 'dizziness'));

$dNo = $polarity->invoke(null, baseFacts(), 'dizziness_with_chest', false);
ok('chest dizzy NO → finding_status negative', ($dNo['finding_status']['dizziness_with_chest'] ?? '') === 'negative');
ok('chest dizzy NO → does not create dizziness', ($dNo['dizziness'] ?? null) === null);

// Character answer still closes DIZZINESS_TYPE
ok('DIZZINESS_TYPE answered by character (spinning)',
    $already->invoke(null, 'DIZZINESS_TYPE', baseFacts(['dizziness' => true]), 'the room is spinning', ['dizziness'], 'the room is spinning'));
ok('DIZZINESS_TYPE NOT answered by presence alone',
    !$already->invoke(null, 'DIZZINESS_TYPE', baseFacts(['dizziness' => true]), 'nahilo ko', ['dizziness'], 'nahilo ko'));

$bankQ = [
    'question_id' => 'DIZZINESS_TYPE',
    'clinical_purpose' => 'Clarify dizziness character',
];
ok('purposeAlreadyKnown does not close DIZZINESS_TYPE on presence',
    !$purpose->invoke(null, $bankQ, 'nahilo ko', baseFacts(['dizziness' => true])));

// Shared dizziness from free text still OK for WHO / BLEEDING_DIZZY
ok('free-text dizziness still closes BLEEDING_DIZZY',
    $already->invoke(null, 'BLEEDING_DIZZY', baseFacts(['dizziness' => true]), '', ['bleeding'], ''));
ok('chest atom status alone does not mark findingAlreadyKnown via shared dizziness',
    !$known->invoke(null, 'dizziness_with_chest', baseFacts(['dizziness' => true]), '', 'dizz|faint|lipong|hilo|punaw'));
ok('chest atom known via finding_status',
    $known->invoke(null, 'dizziness_with_chest', $dYes, '', 'dizz|faint|lipong|hilo|punaw'));

// --- applyFollowUpValidationFacts path ---
$ctx = [
    'facts' => baseFacts(),
    'awaiting_question_id' => 'ABDOMINAL_ASSOCIATED__BLEEDING_WITH_ABDOMEN',
    'awaiting_target_finding' => 'bleeding_with_abdomen',
];
$out = $af->invoke(null, $ctx, [
    'answer_class' => 'VALID_POSITIVE',
    'polarity' => 'positive',
    'corrected_answer' => 'oo',
    'extracted' => ['yes_no' => true],
], 'ABDOMINAL_ASSOCIATED__BLEEDING_WITH_ABDOMEN');
$of = $out['facts'] ?? [];
ok('follow-up bleed atom YES → status positive', ($of['finding_status']['bleeding_with_abdomen'] ?? '') === 'positive');
ok('follow-up bleed atom YES → no bleeding_continuing', ($of['bleeding_continuing'] ?? null) === null);

$ctx2 = [
    'facts' => baseFacts(),
    'awaiting_question_id' => 'CHEST_SWEATING__DIZZINESS_WITH_CHEST',
    'awaiting_target_finding' => 'dizziness_with_chest',
];
$out2 = $af->invoke(null, $ctx2, [
    'answer_class' => 'VALID_POSITIVE',
    'polarity' => 'positive',
    'corrected_answer' => 'oo',
    'extracted' => ['yes_no' => true],
], 'CHEST_SWEATING__DIZZINESS_WITH_CHEST');
$of2 = $out2['facts'] ?? [];
ok('follow-up chest dizzy YES → status positive', ($of2['finding_status']['dizziness_with_chest'] ?? '') === 'positive');
ok('follow-up chest dizzy YES → no shared dizziness', ($of2['dizziness'] ?? null) === null);
ok('follow-up chest dizzy YES → DIZZINESS_TYPE open',
    !$already->invoke(null, 'DIZZINESS_TYPE', $of2, 'chest pain', ['chest_pain', 'dizziness'], ''));

$out3 = $af->invoke(null, $ctx2, [
    'answer_class' => 'VALID_NEGATIVE',
    'polarity' => 'negative',
    'corrected_answer' => 'indi',
    'extracted' => ['yes_no' => false],
], 'CHEST_SWEATING__DIZZINESS_WITH_CHEST');
$of3 = $out3['facts'] ?? [];
ok('follow-up chest dizzy NO → no dizziness key', ($of3['dizziness'] ?? null) === null);

// --- Regression: other atoms / families ---
foreach (['vomiting', 'fever_with_abdomen', 'sweating_with_chest', 'urinary_burning'] as $atom) {
    $r = $polarity->invoke(null, baseFacts(), $atom, true);
    ok("atom $atom YES sets finding_status", ($r['finding_status'][$atom] ?? '') === 'positive');
}
$vomNo = $polarity->invoke(null, baseFacts(), 'vomiting', false);
ok('vomiting NO does not set denied_associated', empty($vomNo['denied_associated']));

$unc = $af->invoke(null, [
    'facts' => baseFacts(),
    'awaiting_question_id' => 'CHEST_SWEATING__DIZZINESS_WITH_CHEST',
    'awaiting_target_finding' => 'dizziness_with_chest',
], [
    'answer_class' => 'VALID_UNCERTAIN',
    'polarity' => 'uncertain',
    'corrected_answer' => 'ambot',
    'extracted' => ['patient_uncertain' => true],
], 'CHEST_SWEATING__DIZZINESS_WITH_CHEST');
$uf = $unc['facts'] ?? [];
ok('uncertain chest dizzy → finding_status uncertain', ($uf['finding_status']['dizziness_with_chest'] ?? '') === 'uncertain');
ok('uncertain chest dizzy → no shared dizziness', ($uf['dizziness'] ?? null) === null);

// Neuro / respiratory polarity still maps
foreach (['weakness' => 'weakness', 'breathing_difficulty' => 'breathing_difficulty'] as $find => $key) {
    $r = $polarity->invoke(null, baseFacts(), $find, true);
    ok("direct $find still maps", ($r[$key] ?? null) === true);
}

// Candidate slot: bleeding required + abdominal bleed atom answered → BLEEDING_CONTINUING still eligible
$ctxBleed = [
    'chief_complaint' => 'sakit tiyan kag nagadugo',
    'chief_complaints' => [
        ['id' => 'ABDOMINAL_PAIN', 'name' => 'abdominal pain', 'family_key' => 'abdominal_pain'],
        ['id' => 'BLEEDING', 'name' => 'bleeding', 'family_key' => 'bleeding'],
    ],
    'facts' => $f,
    'questions_asked' => [],
    'findings_asked' => ['bleeding_with_abdomen'],
    'question_language' => 'english',
];
$slots = ClinicalInterviewAdaptivePolicy::listCandidateSlots($ctxBleed, 'sakit tiyan kag nagadugo');
$ids = array_map(static fn ($s) => strtoupper((string) ($s['question_id'] ?? '')), $slots);
ok('BLEEDING_CONTINUING still candidate after abdomen bleed presence', in_array('BLEEDING_CONTINUING', $ids, true));

$ctxDizz = [
    'chief_complaint' => 'chest pain and dizziness',
    'chief_complaints' => [
        ['id' => 'CHEST_PAIN', 'name' => 'chest pain', 'family_key' => 'chest_pain'],
        ['id' => 'DIZZINESS', 'name' => 'dizziness', 'family_key' => 'dizziness'],
    ],
    // Enough core/RF facts so clarify slot is eligible (not blocked by open gaps).
    'facts' => array_merge($dYes, [
        'pain_score' => 5,
        'onset' => 'gradual',
        'duration_label' => '2 days',
        'body_locations' => ['chest'],
        'chest_radiation' => false,
        'sweating' => false,
        'breathing_difficulty' => false,
        'finding_status' => [
            'dizziness_with_chest' => 'positive',
            'sweating_with_chest' => 'negative',
        ],
        'denied_associated' => true,
        'has_other_symptoms' => false,
    ]),
    'questions_asked' => ['CHEST_RADIATION', 'BREATHING_SEVERITY', 'CHEST_SWEATING__SWEATING_WITH_CHEST', 'CHEST_SWEATING__DIZZINESS_WITH_CHEST'],
    'findings_asked' => ['dizziness_with_chest', 'sweating_with_chest'],
    'question_language' => 'english',
];
$slotsD = ClinicalInterviewAdaptivePolicy::listCandidateSlots($ctxDizz, 'chest pain and dizziness for 2 days');
$idsD = array_map(static fn ($s) => strtoupper((string) ($s['question_id'] ?? '')), $slotsD);
ok('DIZZINESS_TYPE still candidate after chest dizzy presence', in_array('DIZZINESS_TYPE', $idsD, true));
ok('DIZZINESS_TYPE not auto-answered by chest presence facts',
    !$already->invoke(null, 'DIZZINESS_TYPE', $ctxDizz['facts'], 'chest pain and dizziness', ['chest_pain', 'dizziness'], 'chest pain and dizziness'));

// Multi-complaint question independence (policy only — no merge changes)
$chestFacts = $dYes;
$bleedFacts = baseFacts(['dizziness' => null]);
ok('multi: chest dizzy status does not answer BLEEDING_DIZZY on other facts bag',
    !$already->invoke(null, 'BLEEDING_DIZZY', $chestFacts, '', ['bleeding'], ''));
ok('multi: bleed track without dizziness still needs BLEEDING_DIZZY',
    !$already->invoke(null, 'BLEEDING_DIZZY', $bleedFacts, '', ['bleeding'], ''));

echo "\n$pass PASS, $fail FAIL\n";
echo $fail === 0 ? "ALL_PASS\n" : "FAILURES\n";
exit($fail === 0 ? 0 : 1);
