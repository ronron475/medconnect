<?php
/**
 * Stage 2 Batch 1: symptom provenance buckets (patient / kb / ai).
 *
 * php scripts/dev/test_symptom_provenance.php
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

$engine = new ReflectionClass('ClinicalInterviewEngine');

function invokePrivate(string $method, array $args): mixed
{
    global $engine;
    $m = $engine->getMethod($method);
    $m->setAccessible(true);

    return $m->invoke(null, ...$args);
}

// --- blankFacts includes provenance buckets ---
$blank = ClinicalInterviewEngine::normalizeContext(['facts' => []])['facts'];
ok('blank has symptoms_patient', array_key_exists('symptoms_patient', $blank) && $blank['symptoms_patient'] === []);
ok('blank has symptoms_kb', array_key_exists('symptoms_kb', $blank) && $blank['symptoms_kb'] === []);
ok('blank has symptoms_ai', array_key_exists('symptoms_ai', $blank) && $blank['symptoms_ai'] === []);

// --- Patient-derived via recordSymptomName ---
$f = ClinicalInterviewEngine::normalizeContext(['facts' => []])['facts'];
$f = invokePrivate('recordSymptomName', [$f, 'pain', 'patient']);
ok('patient → symptoms_patient', in_array('pain', $f['symptoms_patient'], true));
ok('patient also in legacy symptoms', in_array('pain', $f['symptoms'], true));
ok('patient not forced into kb', !in_array('pain', $f['symptoms_kb'], true));
ok('patient not forced into ai', !in_array('pain', $f['symptoms_ai'], true));

// --- KB-derived ---
$f = invokePrivate('recordSymptomName', [$f, 'Migraine', 'kb']);
ok('kb → symptoms_kb', in_array('Migraine', $f['symptoms_kb'], true));
ok('kb in legacy symptoms', in_array('Migraine', $f['symptoms'], true));
ok('kb not in symptoms_patient', !in_array('Migraine', $f['symptoms_patient'], true));

// --- AI-derived ---
$f = invokePrivate('recordSymptomName', [$f, 'Photophobia Gloss', 'ai']);
ok('ai → symptoms_ai', in_array('Photophobia Gloss', $f['symptoms_ai'], true));
ok('ai in legacy symptoms', in_array('Photophobia Gloss', $f['symptoms'], true));
ok('ai not in symptoms_patient', !in_array('Photophobia Gloss', $f['symptoms_patient'], true));

// --- absorbAssessmentIntoCase puts detected_symptoms in kb ---
$ctx = ClinicalInterviewEngine::normalizeContext([
    'facts' => [
        'symptoms' => ['pain'],
        'symptoms_patient' => ['pain'],
    ],
]);
$assessment = [
    'detected_symptoms' => ['Tension Headache KB'],
    'triage' => ['red_flags' => []],
];
$ctx = invokePrivate('absorbAssessmentIntoCase', [$ctx, $assessment, []]);
$af = $ctx['facts'];
ok('absorb → symptoms_kb', in_array('Tension Headache KB', $af['symptoms_kb'] ?? [], true));
ok('absorb keeps legacy symptoms', in_array('Tension Headache KB', $af['symptoms'] ?? [], true));
ok('absorb does not put KB into symptoms_patient', !in_array('Tension Headache KB', $af['symptoms_patient'] ?? [], true));
ok('absorb preserves prior patient bucket', in_array('pain', $af['symptoms_patient'] ?? [], true));

// --- Negatives clear provenance buckets ---
$ctx2 = ClinicalInterviewEngine::normalizeContext([
    'facts' => [
        'symptoms' => ['Vomiting', 'Headache'],
        'symptoms_patient' => ['Vomiting'],
        'symptoms_kb' => ['Headache'],
        'negative_symptoms' => [],
    ],
]);
$ctx2 = invokePrivate('absorbAssessmentIntoCase', [
    $ctx2,
    ['detected_symptoms' => [], 'triage' => ['red_flags' => []]],
    ['negated_concepts' => ['vomiting']],
]);
$nf = $ctx2['facts'];
ok('negative_symptoms records vomiting', in_array('vomiting', $nf['negative_symptoms'] ?? [], true)
    || in_array('Vomiting', $nf['negative_symptoms'] ?? [], true)
    || !empty($nf['negative_symptoms']));
ok('negation drops patient Vomiting from symptoms_patient',
    !in_array('Vomiting', $nf['symptoms_patient'] ?? [], true));
ok('negation drops Vomiting from legacy symptoms',
    !in_array('Vomiting', $nf['symptoms'] ?? [], true));
ok('unrelated kb Headache retained', in_array('Headache', $nf['symptoms_kb'] ?? [], true)
    || in_array('Headache', $nf['symptoms'] ?? [], true));

// --- applyFollowUpValidationFacts named symptoms → patient ---
$vctx = ClinicalInterviewEngine::normalizeContext([
    'facts' => [],
    'awaiting_question_id' => 'ASSOCIATED_DETAIL',
]);
$validation = [
    'accept' => true,
    'answer_class' => 'VALID_POSITIVE',
    'polarity' => 'positive',
    'extracted' => [
        'named_symptoms' => ['cough'],
    ],
    'corrected_answer' => 'may ubo ko',
];
$vctx = invokePrivate('applyFollowUpValidationFacts', [$vctx, $validation, 'ASSOCIATED_DETAIL']);
$vf = $vctx['facts'];
ok('validator named → symptoms_patient', in_array('cough', $vf['symptoms_patient'] ?? [], true));
ok('validator named in associated_symptoms', in_array('cough', $vf['associated_symptoms'] ?? [], true));
ok('validator named in legacy symptoms', in_array('cough', $vf['symptoms'] ?? [], true));

// --- Python enrichment AI path ---
$py = ClinicalInterviewPythonEnrichment::applyToContext(
    ClinicalInterviewEngine::normalizeContext(['facts' => []]),
    [
        'used' => true,
        'english_symptoms' => ['Dyspnea AI'],
        'english_gloss' => 'short of breath',
        'body_locations' => [],
        'matched_terms' => [],
        'source' => 'test',
        'reason' => 'test',
    ]
);
$pf = $py['facts'];
ok('python → symptoms_ai', in_array('Dyspnea AI', $pf['symptoms_ai'] ?? [], true));
ok('python in legacy symptoms', in_array('Dyspnea AI', $pf['symptoms'] ?? [], true));
ok('python not in symptoms_patient', !in_array('Dyspnea AI', $pf['symptoms_patient'] ?? [], true));

// --- Stage 2A plumbing still echoes facts including provenance ---
$pack = [
    'symptoms' => ['pain'],
    'symptoms_patient' => ['pain'],
    'symptoms_kb' => ['Migraine'],
    'symptoms_ai' => [],
];
$raw = ClinicalTriageEngine::assess('masakit ulo', 'masakit ulo', [], [], 0, true, $pack);
ok('2A plumbing echoes symptoms_patient',
    ($raw['interview_facts']['symptoms_patient'] ?? null) === ['pain']);
ok('2A plumbing echoes symptoms_kb',
    ($raw['interview_facts']['symptoms_kb'] ?? null) === ['Migraine']);

// --- UNIVERSAL LANGUAGE: patient answers → symptoms_patient (source-based, not language packs) ---
function assertPatientLang(string $label, string $corrected, string $named): void
{
    $ctx = ClinicalInterviewEngine::normalizeContext([
        'facts' => [],
        'awaiting_question_id' => 'ASSOCIATED_DETAIL',
    ]);
    $validation = [
        'accept' => true,
        'answer_class' => 'VALID_POSITIVE',
        'polarity' => 'positive',
        'extracted' => ['named_symptoms' => [$named]],
        'corrected_answer' => $corrected,
    ];
    $ctx = invokePrivate('applyFollowUpValidationFacts', [$ctx, $validation, 'ASSOCIATED_DETAIL']);
    $facts = $ctx['facts'];
    ok("{$label}: named → symptoms_patient", in_array($named, $facts['symptoms_patient'] ?? [], true), json_encode($facts['symptoms_patient'] ?? []));
    ok("{$label}: named in legacy symptoms", in_array($named, $facts['symptoms'] ?? [], true));
    ok("{$label}: named NOT in symptoms_kb", !in_array($named, $facts['symptoms_kb'] ?? [], true));
    ok("{$label}: named NOT in symptoms_ai", !in_array($named, $facts['symptoms_ai'] ?? [], true));
}

assertPatientLang('Hiligaynon', 'may ubo ko kag ga suka', 'cough');
assertPatientLang('Tagalog', 'nagsusuka ako at may ubo', 'vomiting');
assertPatientLang('mixed EN+HIL', 'I have fever kag masakit ulo ko', 'fever');

// freeTextAssociatedLabel from local wording still records as patient provenance
foreach ([
    'Hiligaynon free-text' => 'Ga suka ko subong',
    'Tagalog free-text' => 'Nagsusuka ako lagi',
    'mixed free-text' => 'may dizziness and nahilo ko',
] as $label => $turn) {
    $ftLabel = invokePrivate('freeTextAssociatedLabel', [$turn]);
    ok("{$label}: freeTextAssociatedLabel non-empty", $ftLabel !== '', "turn={$turn}");
    if ($ftLabel === '') {
        continue;
    }
    $facts = ClinicalInterviewEngine::normalizeContext(['facts' => []])['facts'];
    $facts = invokePrivate('recordSymptomName', [$facts, $ftLabel, 'patient']);
    ok("{$label}: free-text label → symptoms_patient", in_array($ftLabel, $facts['symptoms_patient'], true));
    ok("{$label}: free-text label NOT symptoms_kb", !in_array($ftLabel, $facts['symptoms_kb'], true));
    ok("{$label}: free-text label NOT symptoms_ai", !in_array($ftLabel, $facts['symptoms_ai'], true));
}

// --- Stage 1: original wording preserved (HIL / TAG / mixed) ---
foreach ([
    'Hiligaynon' => 'Budlay gid magginhawa kag sakit ulo ko',
    'Tagalog' => 'Masakit ang ulo ko at nahihilo ako',
    'mixed' => 'Masakit akon ulo and I feel dizzy',
] as $lang => $complaint) {
    $open = ClinicalInterviewEngine::assess($complaint);
    $turns = $open['interview']['patient_turns'] ?? [];
    $evidence = (string) ($open['interview']['patient_evidence_text']
        ?? ClinicalInterviewEngine::patientEvidenceText($open['interview'] ?? []));
    ok("{$lang}: patient_turns keep original", ($turns[0] ?? null) === $complaint, json_encode($turns));
    ok("{$lang}: patientEvidenceText contains original", str_contains($evidence, $complaint), $evidence);
}

// --- KB absorb cannot enter symptoms_patient (with prior local patient bucket) ---
$hilCtx = ClinicalInterviewEngine::normalizeContext([
    'facts' => [
        'symptoms' => ['cough'],
        'symptoms_patient' => ['cough'],
    ],
    'chief_complaint' => 'may ubo ko',
    'patient_turns' => ['may ubo ko'],
]);
$hilCtx = invokePrivate('absorbAssessmentIntoCase', [
    $hilCtx,
    ['detected_symptoms' => ['Acute Bronchitis KB'], 'triage' => ['red_flags' => []]],
    [],
]);
ok('KB after HIL patient: kb bucket has KB name',
    in_array('Acute Bronchitis KB', $hilCtx['facts']['symptoms_kb'] ?? [], true));
ok('KB after HIL patient: NOT in symptoms_patient',
    !in_array('Acute Bronchitis KB', $hilCtx['facts']['symptoms_patient'] ?? [], true));
ok('KB after HIL patient: patient cough retained',
    in_array('cough', $hilCtx['facts']['symptoms_patient'] ?? [], true));
ok('HIL patientEvidenceText still original after absorb',
    str_contains(ClinicalInterviewEngine::patientEvidenceText($hilCtx), 'may ubo ko'));

// --- AI cannot enter symptoms_patient ---
$aiCtx = ClinicalInterviewPythonEnrichment::applyToContext(
    ClinicalInterviewEngine::normalizeContext([
        'facts' => [
            'symptoms_patient' => ['fever'],
            'symptoms' => ['fever'],
        ],
        'chief_complaint' => 'may lagnat ako',
        'patient_turns' => ['may lagnat ako'],
    ]),
    [
        'used' => true,
        'english_symptoms' => ['Pyrexia AI Gloss'],
        'english_gloss' => 'fever gloss',
        'body_locations' => [],
        'matched_terms' => [],
        'source' => 'test',
        'reason' => 'test',
    ]
);
ok('AI after TAG-like patient: symptoms_ai has gloss',
    in_array('Pyrexia AI Gloss', $aiCtx['facts']['symptoms_ai'] ?? [], true));
ok('AI after TAG-like patient: NOT in symptoms_patient',
    !in_array('Pyrexia AI Gloss', $aiCtx['facts']['symptoms_patient'] ?? [], true));
ok('AI does not remove patient fever bucket',
    in_array('fever', $aiCtx['facts']['symptoms_patient'] ?? [], true));

// --- MULTI-COMPLAINT: provenance isolated per track on switch ---
function emptyFacts(): array
{
    return ClinicalInterviewEngine::normalizeContext(['facts' => []])['facts'];
}

$trackA = emptyFacts();
$trackA['symptoms'] = ['cough', 'Chest Pain KB'];
$trackA['symptoms_patient'] = ['cough'];
$trackA['symptoms_kb'] = ['Chest Pain KB'];
$trackA['symptoms_ai'] = ['Dyspnea AI'];
$trackA['body_locations'] = ['chest'];

$trackB = emptyFacts();
$trackB['symptoms'] = ['pain'];
$trackB['symptoms_patient'] = ['pain'];
$trackB['symptoms_kb'] = [];
$trackB['symptoms_ai'] = [];
$trackB['body_locations'] = ['abdomen'];

$multi = [
    'chief_complaint' => 'sakit dughan kag sakit tiyan',
    'patient_turns' => ['sakit dughan kag sakit tiyan'],
    'active_complaint_id' => 'c_chest',
    'facts' => $trackA,
    'complaints' => [
        [
            'id' => 'c_chest',
            'text_span' => 'sakit dughan',
            'family_keys' => ['chest_pain'],
            'facts' => $trackA,
            'questions_asked' => [],
            'questions_answered' => [],
        ],
        [
            'id' => 'c_abd',
            'text_span' => 'sakit tiyan',
            'family_keys' => ['abdominal'],
            'facts' => $trackB,
            'questions_asked' => [],
            'questions_answered' => [],
        ],
    ],
];
$multi = ClinicalInterviewMultiComplaint::persistActiveFacts($multi);
$multi['active_complaint_id'] = 'c_abd';
$hydrate = (new ReflectionClass('ClinicalInterviewMultiComplaint'))->getMethod('hydrateActiveFacts');
$hydrate->setAccessible(true);
$multi = $hydrate->invoke(null, $multi, true);
$live = $multi['facts'];
ok('multi switch: live symptoms_patient is abdomen pain only',
    ($live['symptoms_patient'] ?? []) === ['pain']
    || (in_array('pain', $live['symptoms_patient'] ?? [], true)
        && !in_array('cough', $live['symptoms_patient'] ?? [], true)),
    json_encode($live['symptoms_patient'] ?? [])
);
ok('multi switch: chest cough NOT in live symptoms_patient',
    !in_array('cough', $live['symptoms_patient'] ?? [], true));
ok('multi switch: chest KB NOT in live symptoms_kb',
    !in_array('Chest Pain KB', $live['symptoms_kb'] ?? [], true));
ok('multi switch: chest AI NOT in live symptoms_ai',
    !in_array('Dyspnea AI', $live['symptoms_ai'] ?? [], true));

// Stored track A still has its provenance after switch
$storedA = null;
foreach ($multi['complaints'] as $t) {
    if (($t['id'] ?? '') === 'c_chest') {
        $storedA = $t['facts'] ?? null;
        break;
    }
}
ok('multi: track A still has cough in symptoms_patient',
    is_array($storedA) && in_array('cough', $storedA['symptoms_patient'] ?? [], true));
ok('multi: track A still has KB in symptoms_kb',
    is_array($storedA) && in_array('Chest Pain KB', $storedA['symptoms_kb'] ?? [], true));
ok('multi: track A still has AI in symptoms_ai',
    is_array($storedA) && in_array('Dyspnea AI', $storedA['symptoms_ai'] ?? [], true));

$evidenceMulti = ClinicalInterviewEngine::patientEvidenceText($multi);
ok('multi: patientEvidenceText keeps original mixed complaint wording',
    str_contains($evidenceMulti, 'sakit dughan kag sakit tiyan'),
    $evidenceMulti
);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
