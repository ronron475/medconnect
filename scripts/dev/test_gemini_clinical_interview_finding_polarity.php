<?php
/**
 * Focused tests: Gemini Clinical Interview Demo answer → finding_status mapping.
 * Covers positive / negative / uncertain across EN, Tagalog, Hiligaynon, and multi-finding questions.
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

function statusOf(array $facts, string $key): string
{
    return (string) (($facts['finding_status'][$key] ?? '') ?: '');
}

$targetsSingle = ['fever_confirmed'];
$targetsMulti = ['fever_confirmed', 'vomiting', 'dizziness'];

// --- Single finding: positive across languages ---
$casesPos = [
    ['yes', 'english yes'],
    ['oo', 'hiligaynon/tagalog oo'],
    ['opo', 'tagalog opo'],
    ['oo gid', 'hiligaynon oo gid'],
    ['meron', 'tagalog meron'],
    ['positive', 'english positive'],
];
foreach ($casesPos as [$ans, $label]) {
    $f = GeminiClinicalInterviewDemo::mapAnswerFindingStatusForTest([], $targetsSingle, $ans);
    ok("single POS ($label)", statusOf($f, 'fever_confirmed') === 'positive');
}

// --- Single finding: negative across languages ---
$casesNeg = [
    ['no', 'english no'],
    ['indi', 'hiligaynon indi'],
    ['hindi', 'tagalog hindi'],
    ['wala', 'hiligaynon/tagalog wala'],
    ['wala man', 'hiligaynon wala man'],
    ['negative', 'english negative'],
    ['nope', 'english nope'],
];
foreach ($casesNeg as [$ans, $label]) {
    $f = GeminiClinicalInterviewDemo::mapAnswerFindingStatusForTest([], $targetsSingle, $ans);
    ok("single NEG ($label)", statusOf($f, 'fever_confirmed') === 'negative');
}

// --- Single finding: uncertain ---
$casesUnc = [
    ['di ko sure', 'tagalog di ko sure'],
    ['ambot', 'hiligaynon ambot'],
    ['maybe', 'english maybe'],
    ['siguro', 'tagalog siguro'],
    ['i am not sure', 'english not sure'],
];
foreach ($casesUnc as [$ans, $label]) {
    $f = GeminiClinicalInterviewDemo::mapAnswerFindingStatusForTest([], $targetsSingle, $ans);
    ok("single UNC ($label)", statusOf($f, 'fever_confirmed') === 'uncertain');
    ok("single UNC sets patient_uncertain ($label)", !empty($f['patient_uncertain']));
}

// --- Multi-finding: global negation denies all probed findings ---
$f = GeminiClinicalInterviewDemo::mapAnswerFindingStatusForTest([], $targetsMulti, 'wala');
ok('multi NEG fever', statusOf($f, 'fever_confirmed') === 'negative');
ok('multi NEG vomiting', statusOf($f, 'vomiting') === 'negative');
ok('multi NEG dizziness', statusOf($f, 'dizziness') === 'negative');

$f = GeminiClinicalInterviewDemo::mapAnswerFindingStatusForTest([], $targetsMulti, 'no');
ok('multi NEG english all fever', statusOf($f, 'fever_confirmed') === 'negative');
ok('multi NEG english all vomiting', statusOf($f, 'vomiting') === 'negative');

// --- Multi-finding: bare yes is ambiguous → uncertain (not blind positive) ---
$f = GeminiClinicalInterviewDemo::mapAnswerFindingStatusForTest([], $targetsMulti, 'oo');
ok('multi bare OO fever uncertain', statusOf($f, 'fever_confirmed') === 'uncertain');
ok('multi bare OO vomiting uncertain', statusOf($f, 'vomiting') === 'uncertain');
ok('multi bare OO dizziness uncertain', statusOf($f, 'dizziness') === 'uncertain');

$f = GeminiClinicalInterviewDemo::mapAnswerFindingStatusForTest([], $targetsMulti, 'yes');
ok('multi bare YES fever uncertain', statusOf($f, 'fever_confirmed') === 'uncertain');

// --- Multi-finding: named findings only (mixed language) ---
$f = GeminiClinicalInterviewDemo::mapAnswerFindingStatusForTest(
    [],
    $targetsMulti,
    'may lagnat pero wala suka'
);
ok('named fever positive', statusOf($f, 'fever_confirmed') === 'positive');
ok('named vomiting negative', statusOf($f, 'vomiting') === 'negative');
ok('unnamed dizziness not_assessed', statusOf($f, 'dizziness') === 'not_assessed');

$f = GeminiClinicalInterviewDemo::mapAnswerFindingStatusForTest(
    [],
    $targetsMulti,
    'nahilo ko lang'
);
ok('hiligaynon named dizziness positive', statusOf($f, 'dizziness') === 'positive');
ok('unmentioned fever not_assessed', statusOf($f, 'fever_confirmed') === 'not_assessed');
ok('unmentioned vomiting not_assessed', statusOf($f, 'vomiting') === 'not_assessed');

// --- Do not apply answer to unrelated findings outside targets ---
$f = GeminiClinicalInterviewDemo::mapAnswerFindingStatusForTest(
    ['finding_status' => ['cough' => 'not_assessed']],
    ['fever_confirmed'],
    'oo'
);
ok('target fever positive', statusOf($f, 'fever_confirmed') === 'positive');
ok('unrelated cough untouched', statusOf($f, 'cough') === 'not_assessed');
ok('did not invent vomiting key', !array_key_exists('vomiting', $f['finding_status'] ?? []));

// --- Empty targets: no invented finding_status ---
$f = GeminiClinicalInterviewDemo::mapAnswerFindingStatusForTest([], [], 'oo');
ok('no targets → empty finding_status', ($f['finding_status'] ?? []) === []);

// --- Provenance / side effects for POS and NEG ---
$f = GeminiClinicalInterviewDemo::mapAnswerFindingStatusForTest([], ['rash'], 'gakatol');
ok('rash named positive', statusOf($f, 'rash') === 'positive');
ok('rash in symptoms_patient provenance', in_array('rash', $f['symptoms_patient'] ?? [], true)
    || in_array('rash', array_map('strtolower', $f['associated_symptoms'] ?? []), true));

$f = GeminiClinicalInterviewDemo::mapAnswerFindingStatusForTest([], ['vomiting'], 'indi');
ok('vomiting negative', statusOf($f, 'vomiting') === 'negative');
ok('vomiting in relevant_negatives', in_array('vomiting', array_map('strtolower', $f['relevant_negatives'] ?? []), true)
    || in_array('vomiting', array_map('strtolower', $f['negative_symptoms'] ?? []), true));

// --- Engine mapping passes finding_status through (no triage from Gemini) ---
$ref = new ReflectionClass('GeminiClinicalInterviewDemo');
$mapEngine = $ref->getMethod('mapFactsForEngine');
$mapEngine->setAccessible(true);
$mapped = $mapEngine->invoke(null, [
    'symptom' => 'itching',
    'finding_status' => [
        'fever_confirmed' => 'negative',
        'rash' => 'positive',
    ],
    'associated_symptoms' => ['rash'],
    'relevant_negatives' => ['fever confirmed'],
]);
ok('engine mapped finding_status fever negative', ($mapped['finding_status']['fever_confirmed'] ?? '') === 'negative');
ok('engine mapped finding_status rash positive', ($mapped['finding_status']['rash'] ?? '') === 'positive');
ok('engine boolean fever_confirmed false', ($mapped['fever_confirmed'] ?? null) === false);

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
