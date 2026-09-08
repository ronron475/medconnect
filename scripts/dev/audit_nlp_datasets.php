<?php
/**
 * Read-only NLP dataset audit for expansion planning.
 * Does not modify any files.
 */
require __DIR__ . '/nlp_cli_bootstrap.php';

$root = BASE_PATH . '/data/nlp';
$priority = [
    'who_iitt_triage_rules.csv',
    'emergency_red_flags.csv',
    'emergency_flags.csv',
    'medical_misspellings.csv',
    'misspellings.csv',
    'hiligaynon_chat_shorthand.csv',
    'patient_typing_dictionary_2026.csv',
    'canonical_symptom_aliases.csv',
    'literal_translation_phrases.csv',
    'negation_words.csv',
    'duration_patterns.csv',
    'pain_scale.csv',
    'body_parts.csv',
    'body_parts_cds.csv',
    'symptom_synonyms.csv',
    'symptom_synonyms_expanded.csv',
    'medical_dictionary.csv',
    'translation_dictionary.csv',
    'filipino_medical_terms.csv',
    'hiligaynon_medical_terms.csv',
    'english_medical_terms.csv',
    'clinical_interview_families.json',
    'clinical_followup_questions.json',
    'hiligaynon_symptom_lexicon.json',
    'chief_complaint_examples.csv',
    'common_patient_sentences.csv',
    'medical_abbreviations.csv',
    'temperature_patterns.csv',
    'symptom_combinations.csv',
];

function auditCsv(string $path): array
{
    if (!is_readable($path)) {
        return ['rows' => 0, 'header' => [], 'dupes' => 0, 'empty' => true];
    }
    $h = fopen($path, 'r');
    $header = fgetcsv($h) ?: [];
    $header = array_map(static fn ($x) => strtolower(trim((string) $x)), $header);
    $rows = 0;
    $seen = [];
    $dupes = 0;
    $sample = [];
    while (($row = fgetcsv($h)) !== false) {
        if ($row === [null] || $row === false) {
            continue;
        }
        $rows++;
        $key = strtolower(implode('|', array_map(static fn ($v) => trim((string) $v), $row)));
        if (isset($seen[$key])) {
            $dupes++;
        } else {
            $seen[$key] = true;
        }
        if (count($sample) < 2) {
            $sample[] = $row;
        }
    }
    fclose($h);

    return [
        'rows' => $rows,
        'header' => $header,
        'dupes' => $dupes,
        'empty' => $rows === 0,
        'sample' => $sample,
    ];
}

function auditJson(string $path): array
{
    if (!is_readable($path)) {
        return ['rows' => 0, 'keys' => [], 'empty' => true];
    }
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data)) {
        return ['rows' => 0, 'keys' => [], 'empty' => true, 'invalid' => true];
    }
    if (isset($data['complaints']) && is_array($data['complaints'])) {
        return [
            'rows' => count($data['complaints']),
            'keys' => array_keys($data),
            'empty' => $data['complaints'] === [],
            'shape' => 'clinical_interview_families',
        ];
    }
    if (isset($data['questions']) && is_array($data['questions'])) {
        return [
            'rows' => count($data['questions']),
            'keys' => array_keys($data),
            'empty' => $data['questions'] === [],
            'shape' => 'clinical_followup_questions',
        ];
    }
    if (array_is_list($data)) {
        return ['rows' => count($data), 'keys' => ['list'], 'empty' => $data === []];
    }
    $total = 0;
    foreach ($data as $v) {
        if (is_array($v)) {
            $total += count($v);
        }
    }

    return [
        'rows' => $total > 0 ? $total : count($data),
        'keys' => array_slice(array_keys($data), 0, 12),
        'empty' => $data === [],
        'shape' => 'object',
    ];
}

echo "=== PRIORITY DATASET AUDIT ===\n";
$totalRows = 0;
$files = 0;
foreach ($priority as $rel) {
    $path = $root . '/' . $rel;
    $files++;
    if (str_ends_with($rel, '.json')) {
        $a = auditJson($path);
        echo sprintf(
            "%-42s rows=%-6d keys=%s%s\n",
            $rel,
            $a['rows'],
            implode(',', $a['keys'] ?? []),
            !empty($a['empty']) ? ' [EMPTY]' : ''
        );
        $totalRows += (int) $a['rows'];
        continue;
    }
    $a = auditCsv($path);
    echo sprintf(
        "%-42s rows=%-6d cols=%s dupes=%d%s\n",
        $rel,
        $a['rows'],
        implode('|', $a['header']),
        $a['dupes'],
        !empty($a['empty']) ? ' [EMPTY]' : ''
    );
    $totalRows += (int) $a['rows'];
}

// Full tree count
$allCsv = 0;
$allFiles = 0;
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
foreach ($rii as $file) {
    if (!$file->isFile()) {
        continue;
    }
    $ext = strtolower($file->getExtension());
    if (!in_array($ext, ['csv', 'json'], true)) {
        continue;
    }
    $allFiles++;
    if ($ext === 'csv') {
        $a = auditCsv($file->getPathname());
        $allCsv += (int) $a['rows'];
    }
}

echo "\n=== TREE SUMMARY ===\n";
echo "Priority files audited: {$files}\n";
echo "Priority row/entry total: {$totalRows}\n";
echo "All csv/json files under data/nlp: {$allFiles}\n";
echo "All CSV data rows (approx): {$allCsv}\n";

// Spot-check key loaders
echo "\n=== RUNTIME LOADER CHECK ===\n";
echo 'WHO rules: ' . count(WhoIittTriageRulesLoader::rules()) . "\n";
echo 'Misspell map keys: ' . count(MedicalMisspellingsLoader::map()) . "\n";
if (class_exists('ClinicalFollowUpQuestionBank')) {
    echo 'Follow-up questions: ' . count(ClinicalFollowUpQuestionBank::questions()) . "\n";
}
