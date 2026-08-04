<?php
/**
 * Evaluate ClinicalTriageEngine against labeled validation CSVs.
 *
 * Usage:
 *   php scripts/dev/evaluate_triage_validation.php
 *   php scripts/dev/evaluate_triage_validation.php --gold
 *   php scripts/dev/evaluate_triage_validation.php --file=data/nlp/validation/triage_validation_gold.csv --limit=100
 */

require dirname(__DIR__, 2) . '/bootstrap/app.php';

$opts = [
    'gold' => false,
    'file' => BASE_PATH . '/data/nlp/validation/triage_validation_gold.csv',
    'limit' => 0,
];
foreach ($argv as $arg) {
    if ($arg === '--gold') {
        $opts['gold'] = true;
        $opts['file'] = BASE_PATH . '/data/nlp/validation/triage_validation_gold.csv';
    }
    if (str_starts_with($arg, '--file=')) {
        $opts['file'] = BASE_PATH . '/' . ltrim(substr($arg, 7), '/');
    }
    if (str_starts_with($arg, '--limit=')) {
        $opts['limit'] = max(0, (int) substr($arg, 8));
    }
}

$path = $opts['file'];
if (!is_readable($path)) {
    fwrite(STDERR, "Validation file missing. Generate with:\n");
    fwrite(STDERR, "  python scripts/data/build_triage_validation_dataset.py\n");
    exit(1);
}

$handle = fopen($path, 'r');
$header = fgetcsv($handle);
$total = 0;
$correct = 0;
$byClass = [];
$failures = [];

while (($row = fgetcsv($handle)) !== false) {
    $data = array_combine(
        array_map(static fn ($h) => strtolower(trim((string) $h)), $header ?: []),
        array_map(static fn ($v) => trim((string) $v), $row)
    ) ?: [];
    $complaint = (string) ($data['chief_complaint'] ?? '');
    $expected = strtoupper((string) ($data['expected_classification'] ?? ''));
    if ($complaint === '' || !in_array($expected, ['NON-URGENT', 'URGENT', 'EMERGENCY'], true)) {
        continue;
    }
    // Strip generator padding " [ID]" / " #n"
    $complaint = trim(preg_replace('/\s*[\[#][A-Z0-9]+\]?\s*$/u', '', $complaint) ?? $complaint);

    $total++;
    if ($opts['limit'] > 0 && $total > $opts['limit']) {
        $total--;
        break;
    }

    $result = ClinicalTriageEngine::assess($complaint, $complaint, [['english_term' => '']], [], 80);
    $got = strtoupper((string) ($result['triage_display'] ?? ''));
    // Provider-review on low confidence is acceptable for sparse/admin cases when expected NON-URGENT
    if (!empty($result['needs_provider_review']) && $expected === 'NON-URGENT' && $got === 'NON-URGENT') {
        $got = 'NON-URGENT';
    }

    $byClass[$expected]['total'] = ($byClass[$expected]['total'] ?? 0) + 1;
    if ($got === $expected) {
        $correct++;
        $byClass[$expected]['correct'] = ($byClass[$expected]['correct'] ?? 0) + 1;
    } else {
        $byClass[$expected]['wrong'] = ($byClass[$expected]['wrong'] ?? 0) + 1;
        if (count($failures) < 25) {
            $failures[] = [
                'complaint' => $complaint,
                'expected' => $expected,
                'got' => $got,
                'score' => $result['severity_score'] ?? 0,
                'symptoms' => $result['detected_symptoms'] ?? [],
                'red_flags' => $result['red_flags'] ?? [],
                'validation' => $result['validation']['failures'] ?? [],
            ];
        }
    }
}
fclose($handle);

$acc = $total > 0 ? round(100 * $correct / $total, 2) : 0;
echo json_encode([
    'file' => $path,
    'total' => $total,
    'correct' => $correct,
    'accuracy_percent' => $acc,
    'by_class' => $byClass,
    'sample_failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
