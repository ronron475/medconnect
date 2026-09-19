<?php
require __DIR__ . '/nlp_cli_bootstrap.php';

$cases = [
    // Coverage gaps that previously fell to general_unwell / wrong family
    ['may sipon ako', ['RESPIRATORY'], ['respiratory']],
    ['gahika ko', ['COUGH'], ['cough', 'respiratory']],
    ['ginasuka ko', ['GASTROINTESTINAL'], ['gastrointestinal']],
    ['nagsusuka', ['GASTROINTESTINAL'], ['gastrointestinal']],
    ['diarrhea', ['GASTROINTESTINAL'], ['gastrointestinal']],
    ['pagtatae', ['GASTROINTESTINAL'], ['gastrointestinal']],
    ['sore throat', ['RESPIRATORY'], ['respiratory']],
    ['masakit ang lalamunan', ['RESPIRATORY'], ['respiratory']],
    ['back pain', ['MUSCULOSKELETAL'], ['pain']],
    ['sakit likod', ['MUSCULOSKELETAL'], ['pain']],
    ['ear pain', ['EAR'], ['ear', 'pain']],
    ['nasusuka at may lagnat', ['GASTROINTESTINAL', 'FEVER'], ['gastrointestinal', 'fever']],
    ['palpitations', ['CARDIOVASCULAR'], ['cardiovascular']],
    ['hubag paa', ['SKIN'], ['skin']],

    // Preserve existing families
    ['sakit ulo', ['HEADACHE'], ['headache', 'pain']],
    ['chest pain', ['CHEST_PAIN'], ['chest_pain', 'pain', 'respiratory']],
    ['sakit tiyan', ['ABDOMINAL_PAIN'], ['abdominal_pain', 'pain']],
    ['nagdudugo', ['BLEEDING'], ['bleeding']],
    ['hirap huminga', ['DIFFICULTY_BREATHING'], ['breathing', 'respiratory']],
    ['may lagnat', ['FEVER'], ['fever']],
    ['pamamanhid', ['NEURO'], ['neuro']],
    ['nahilo', ['DIZZINESS'], ['dizziness']],
    ['masakit mata', ['EYE'], ['eye', 'pain']],
    ['sakit ngipon', ['DENTAL_PAIN'], ['dental_pain', 'pain']],

    // Non-health / greeting must not invent medical families
    ['hello how are you', [], []],
    ['i want pizza', [], []],

    // Vague unwell still maps
    ['not feeling well', ['GENERAL_UNWELL'], ['general_unwell']],
];

$fail = 0;
foreach ($cases as [$text, $expectIds, $expectFamilies]) {
    $complaints = ClinicalInterviewContextResolver::deriveComplaints([], $text, []);
    $ids = array_values(array_map(static fn ($r) => (string) ($r['id'] ?? ''), $complaints));
    $facts = ['body_locations' => ClinicalFeatureExtractors::extractBodyLocations($text)];
    $fams = ClinicalInterviewContextResolver::deriveFamilies($complaints, $text, $facts);

    $idOk = true;
    foreach ($expectIds as $need) {
        if (!in_array($need, $ids, true)) {
            $idOk = false;
            break;
        }
    }
    // For empty expect, require no complaints
    if ($expectIds === [] && $ids !== []) {
        $idOk = false;
    }

    $famOk = true;
    foreach ($expectFamilies as $need) {
        if (!in_array($need, $fams, true)) {
            $famOk = false;
            break;
        }
    }
    if ($expectFamilies === [] && $fams !== []) {
        $famOk = false;
    }

    $ok = $idOk && $famOk;
    if (!$ok) {
        $fail++;
    }
    echo ($ok ? 'OK' : 'FAIL') . "\t{$text}\n";
    if (!$ok) {
        echo "  ids=" . implode(',', $ids) . " expect_ids=" . implode(',', $expectIds) . "\n";
        echo "  fams=" . implode(',', $fams) . " expect_fams=" . implode(',', $expectFamilies) . "\n";
    }
}

echo $fail === 0 ? "ALL_PASS\n" : "FAILURES={$fail}\n";
exit($fail === 0 ? 0 : 1);
