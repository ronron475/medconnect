<?php
/**
 * Comprehensive triage QA report across validation datasets.
 *
 * Usage:
 *   php scripts/dev/triage_qa_report.php
 *   php scripts/dev/triage_qa_report.php --gold
 *   php scripts/dev/triage_qa_report.php --limit=500
 *   php scripts/dev/triage_qa_report.php --file=data/nlp/validation/emergency_scenarios_validation.csv
 */

require dirname(__DIR__, 2) . '/bootstrap/app.php';

$opts = [
    'limit' => 0,
    'output' => BASE_PATH . '/data/nlp/reports/triage_qa_report.json',
    'files' => [
        BASE_PATH . '/data/nlp/validation/triage_validation_gold.csv',
        BASE_PATH . '/data/nlp/validation/english_chief_complaints_validation.csv',
        BASE_PATH . '/data/nlp/validation/filipino_chief_complaints_validation.csv',
        BASE_PATH . '/data/nlp/validation/hiligaynon_chief_complaints_validation.csv',
        BASE_PATH . '/data/nlp/validation/mixed_language_complaints_validation.csv',
        BASE_PATH . '/data/nlp/validation/misspelled_complaints_validation.csv',
        BASE_PATH . '/data/nlp/validation/emergency_scenarios_validation.csv',
        BASE_PATH . '/data/nlp/validation/urgent_scenarios_validation.csv',
        BASE_PATH . '/data/nlp/validation/non_urgent_scenarios_validation.csv',
    ],
];

foreach ($argv as $arg) {
    if ($arg === '--gold') {
        $opts['files'] = [BASE_PATH . '/data/nlp/validation/triage_validation_gold.csv'];
    }
    if (str_starts_with($arg, '--limit=')) {
        $opts['limit'] = max(0, (int) substr($arg, 8));
    }
    if (str_starts_with($arg, '--file=')) {
        $opts['files'] = [BASE_PATH . '/' . ltrim(substr($arg, 7), '/')];
    }
    if (str_starts_with($arg, '--output=')) {
        $opts['output'] = BASE_PATH . '/' . ltrim(substr($arg, 9), '/');
    }
}

/**
 * @return array{total:int,correct:int,accuracy:float,by_language:array,by_class:array,by_scenario:array,failures:list,low_confidence:list}
 */
function qa_evaluate_file(string $path, int $limit): array
{
    $handle = fopen($path, 'r');
    if ($handle === false) {
        return ['error' => 'unreadable', 'path' => $path];
    }
    $header = fgetcsv($handle);
    $total = 0;
    $correct = 0;
    $byLang = [];
    $byClass = [];
    $byScenario = [];
    $failures = [];
    $lowConfidence = [];
    $falsePosEmergency = 0;
    $falseNegEmergency = 0;

    while (($row = fgetcsv($handle)) !== false) {
        $data = array_combine(
            array_map(static fn ($h) => strtolower(trim((string) $h)), $header ?: []),
            array_map(static fn ($v) => trim((string) $v), $row)
        ) ?: [];
        $complaint = (string) ($data['chief_complaint'] ?? '');
        $expected = strtoupper((string) ($data['expected_classification'] ?? ''));
        $language = strtolower((string) ($data['language'] ?? 'unknown'));
        $scenario = strtolower((string) ($data['scenario_type'] ?? 'unknown'));
        if ($complaint === '' || !in_array($expected, ['NON-URGENT', 'URGENT', 'EMERGENCY'], true)) {
            continue;
        }
        $complaint = trim(preg_replace('/\s*[\[#][A-Z0-9]+\]?\s*$/u', '', $complaint) ?? $complaint);

        $total++;
        if ($limit > 0 && $total > $limit) {
            $total--;
            break;
        }

        $result = ClinicalTriageEngine::assess($complaint, $complaint, [['english_term' => '']], [], 80);
        $got = strtoupper((string) ($result['triage_display'] ?? ''));
        $confidence = (int) ($result['confidence_score'] ?? $result['confidence'] ?? 0);
        if (!empty($result['needs_provider_review']) && $expected === 'NON-URGENT' && $got === 'NON-URGENT') {
            $got = 'NON-URGENT';
        }

        $byLang[$language]['total'] = ($byLang[$language]['total'] ?? 0) + 1;
        $byClass[$expected]['total'] = ($byClass[$expected]['total'] ?? 0) + 1;
        $byScenario[$scenario]['total'] = ($byScenario[$scenario]['total'] ?? 0) + 1;

        $match = ($got === $expected);
        if ($match) {
            $correct++;
            $byLang[$language]['correct'] = ($byLang[$language]['correct'] ?? 0) + 1;
            $byClass[$expected]['correct'] = ($byClass[$expected]['correct'] ?? 0) + 1;
            $byScenario[$scenario]['correct'] = ($byScenario[$scenario]['correct'] ?? 0) + 1;
        } else {
            $byLang[$language]['wrong'] = ($byLang[$language]['wrong'] ?? 0) + 1;
            $byClass[$expected]['wrong'] = ($byClass[$expected]['wrong'] ?? 0) + 1;
            $byScenario[$scenario]['wrong'] = ($byScenario[$scenario]['wrong'] ?? 0) + 1;
            if ($expected === 'EMERGENCY' && $got !== 'EMERGENCY') {
                $falseNegEmergency++;
            }
            if ($expected !== 'EMERGENCY' && $got === 'EMERGENCY') {
                $falsePosEmergency++;
            }
            if (count($failures) < 50) {
                $failures[] = [
                    'complaint' => $complaint,
                    'expected' => $expected,
                    'got' => $got,
                    'language' => $language,
                    'scenario' => $scenario,
                    'confidence' => $confidence,
                    'reason' => (string) ($result['reason'] ?? ''),
                ];
            }
        }

        if ($confidence < ClinicalTriageEngine::CONFIDENCE_THRESHOLD && count($lowConfidence) < 30) {
            $lowConfidence[] = [
                'complaint' => $complaint,
                'classification' => $got,
                'confidence' => $confidence,
                'language' => $language,
            ];
        }
    }
    fclose($handle);

    $accuracy = $total > 0 ? round(100 * $correct / $total, 2) : 0.0;
    foreach ($byLang as $lang => $stats) {
        $byLang[$lang]['accuracy_percent'] = ($stats['total'] ?? 0) > 0
            ? round(100 * ($stats['correct'] ?? 0) / $stats['total'], 2) : 0.0;
    }
    foreach ($byClass as $cls => $stats) {
        $byClass[$cls]['accuracy_percent'] = ($stats['total'] ?? 0) > 0
            ? round(100 * ($stats['correct'] ?? 0) / $stats['total'], 2) : 0.0;
    }
    foreach ($byScenario as $sc => $stats) {
        $byScenario[$sc]['accuracy_percent'] = ($stats['total'] ?? 0) > 0
            ? round(100 * ($stats['correct'] ?? 0) / $stats['total'], 2) : 0.0;
    }

    return [
        'file' => $path,
        'total' => $total,
        'correct' => $correct,
        'accuracy_percent' => $accuracy,
        'false_positive_emergency' => $falsePosEmergency,
        'false_negative_emergency' => $falseNegEmergency,
        'by_language' => $byLang,
        'by_class' => $byClass,
        'by_scenario' => $byScenario,
        'sample_failures' => $failures,
        'low_confidence_cases' => $lowConfidence,
    ];
}

$report = [
    'generated_at' => gmdate('c'),
    'limit_per_file' => $opts['limit'],
    'datasets' => [],
    'overall' => ['total' => 0, 'correct' => 0, 'accuracy_percent' => 0.0],
];

foreach ($opts['files'] as $file) {
    if (!is_readable($file)) {
        $report['datasets'][] = ['file' => $file, 'error' => 'missing'];
        continue;
    }
    $result = qa_evaluate_file($file, $opts['limit']);
    $report['datasets'][] = $result;
    if (!isset($result['error'])) {
        $report['overall']['total'] += (int) $result['total'];
        $report['overall']['correct'] += (int) $result['correct'];
    }
}

if ($report['overall']['total'] > 0) {
    $report['overall']['accuracy_percent'] = round(
        100 * $report['overall']['correct'] / $report['overall']['total'],
        2
    );
}

$outDir = dirname($opts['output']);
if (!is_dir($outDir)) {
    mkdir($outDir, 0755, true);
}
file_put_contents($opts['output'], json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");

echo json_encode([
    'output' => $opts['output'],
    'overall' => $report['overall'],
    'datasets' => array_map(static fn ($d) => [
        'file' => basename((string) ($d['file'] ?? '')),
        'total' => $d['total'] ?? 0,
        'accuracy_percent' => $d['accuracy_percent'] ?? null,
        'error' => $d['error'] ?? null,
    ], $report['datasets']),
], JSON_PRETTY_PRINT) . "\n";
