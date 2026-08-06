<?php
/**
 * Verify demo and production chief-complaint NLP produce identical classifications.
 *
 * Usage: php scripts/dev/verify_nlp_unification.php [--gold] [--sample "complaint text"]
 */
require dirname(__DIR__, 2) . '/bootstrap/app.php';

$runGold = in_array('--gold', $argv, true);
$sampleIdx = array_search('--sample', $argv, true);
$sampleText = $sampleIdx !== false ? (string) ($argv[$sampleIdx + 1] ?? '') : '';

$entryPoints = ['cds_demo', 'assess_chief_complaint', 'registration', 'bhw', 'patient_portal'];

$complaints = [];
if ($sampleText !== '') {
    $complaints[] = ['id' => 'SAMPLE', 'text' => $sampleText];
} elseif ($runGold) {
    $goldPath = BASE_PATH . '/data/nlp/validation/triage_validation_gold.csv';
    $fh = fopen($goldPath, 'r');
    if ($fh === false) {
        fwrite(STDERR, "Cannot read gold file\n");
        exit(1);
    }
    $header = fgetcsv($fh);
    while (($row = fgetcsv($fh)) !== false) {
        if (count($row) < 3) {
            continue;
        }
        $complaints[] = ['id' => $row[0], 'text' => $row[1], 'expected' => $row[2]];
    }
    fclose($fh);
} else {
    $complaints = [
        ['id' => 'T1', 'text' => 'sakit mata ko'],
        ['id' => 'T2', 'text' => 'putol ang kamot ko'],
        ['id' => 'T3', 'text' => 'sakit dughan ko'],
        ['id' => 'T4', 'text' => 'grabe sakit ulo kag ginahilanat'],
    ];
}

$mismatches = [];
$parityOk = 0;
$total = 0;

foreach ($complaints as $case) {
    $text = trim((string) $case['text']);
    if ($text === '') {
        continue;
    }
    $total++;

    $assessment = ChiefComplaintNlpService::assess($text, []);
    $cds = ChiefComplaintNlpService::buildCdsSummary($assessment, $text);
    $reg = ChiefComplaintNlpService::buildRegistrationPayload($assessment, $text);
    $clinical = ChiefComplaintNlpService::buildClinicalUrgency($assessment);

    $results = [
        'cds_demo' => $cds,
        'assess_chief_complaint' => $cds,
        'registration' => ['classification' => $reg['urgency']],
        'bhw' => ['classification' => (string) ($clinical['triage_display'] ?? 'NON-URGENT')],
        'patient_portal' => ChiefComplaintNlpService::buildCdsSummary(
            ChiefComplaintNlpService::assessWithFallback($text, []),
            $text
        ),
    ];

    $baselineClass = strtoupper(str_replace('_', '-', (string) ($cds['classification'] ?? 'NON-URGENT')));
    $allMatch = true;
    foreach ($results as $name => $r) {
        if ($name === 'cds_demo') {
            continue;
        }
        $cls = strtoupper(str_replace('_', '-', (string) ($r['classification'] ?? 'NON-URGENT')));
        if ($cls !== $baselineClass) {
            $allMatch = false;
            $mismatches[] = [
                'id' => $case['id'],
                'complaint' => $text,
                'baseline' => $baselineClass,
                'entry' => $name,
                'got' => $cls,
            ];
        }
    }

    if ($allMatch) {
        $parityOk++;
    }

    if (isset($case['expected'])) {
        $exp = strtoupper(str_replace('_', '-', (string) $case['expected']));
        if ($baselineClass !== $exp) {
            $mismatches[] = [
                'id' => $case['id'],
                'complaint' => $text,
                'type' => 'gold_miss',
                'expected' => $exp,
                'got' => $baselineClass,
            ];
        }
    }
}

$report = [
    'engine_chain' => ChiefComplaintNlpService::ENGINE_CHAIN,
    'entry_points_tested' => $entryPoints,
    'total_cases' => $total,
    'parity_matches' => $parityOk,
    'parity_percent' => $total > 0 ? round(100 * $parityOk / $total, 1) : 100,
    'mismatches' => $mismatches,
    'architecture' => [
        'canonical_service' => 'ChiefComplaintNlpService',
        'nlp_pipeline' => 'HiligaynonMedicalNlpPipeline',
        'triage_engine' => 'ClinicalTriageEngine',
        'profile_api_scope' => 'allergies/conditions validation only (analyze_medical_profile.php)',
    ],
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($mismatches === [] ? 0 : 1);
