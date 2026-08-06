<?php
/**
 * Audit, synchronize, and report on medConnect NLP dataset consistency.
 *
 * Usage:
 *   php scripts/dev/sync_nlp_knowledge.php           # audit only
 *   php scripts/dev/sync_nlp_knowledge.php --sync    # audit + write registry/aliases
 *   php scripts/dev/sync_nlp_knowledge.php --report  # audit + JSON/MD report
 *   php scripts/dev/sync_nlp_knowledge.php --skip-gold  # skip slow gold validation (run evaluate_triage_validation.php separately)
 *
 * Canonical source of truth: symptom_knowledge_base.json
 */

require dirname(__DIR__, 2) . '/bootstrap/app.php';

$opts = ['sync' => false, 'report' => true, 'skip_gold' => false];
foreach ($argv as $arg) {
    if ($arg === '--sync') {
        $opts['sync'] = true;
    }
    if ($arg === '--report') {
        $opts['report'] = true;
    }
    if ($arg === '--skip-gold') {
        $opts['skip_gold'] = true;
    }
}

$reportDir = BASE_PATH . '/data/nlp/reports';
if (!is_dir($reportDir)) {
    mkdir($reportDir, 0755, true);
}

/** @var list<string> */
$auditFiles = [
    'medical_symptoms.csv',
    'medical_conditions.csv',
    'symptom_synonyms.csv',
    'symptom_synonyms_expanded.csv',
    'hiligaynon_medical_terms.csv',
    'filipino_medical_terms.csv',
    'english_medical_terms.csv',
    'body_parts.csv',
    'body_parts_cds.csv',
    'pain_scale.csv',
    'duration_patterns.csv',
    'temperature_patterns.csv',
    'risk_factors.csv',
    'chronic_conditions.csv',
    'emergency_red_flags.csv',
    'emergency_flags.csv',
    'urgent_conditions.csv',
    'non_urgent_conditions.csv',
    'negation_words.csv',
    'medical_abbreviations.csv',
    'medical_misspellings.csv',
    'misspellings.csv',
    'symptom_combinations.csv',
    'triage_rules.csv',
    'triage_rules_cds.csv',
    'medical_entities.csv',
    'confidence_rules.csv',
    'clinical_reasoning_rules.csv',
    'symptom_weights.csv',
    'severity_scores.csv',
    'chief_complaint_examples.csv',
    'translation_dictionary.csv',
    'medical_phrases.csv',
    'common_patient_sentences.csv',
    'canonical_symptom_aliases.csv',
    'hiligaynon_chat_shorthand.csv',
    'symptom_phrases.csv',
    'symptom_phrases_seed.csv',
    'medical_phrases.csv',
    'condition_triage_severity.csv',
    'symptom_knowledge_base.json',
    'red_flags_library.json',
    'clinical_context_rules.json',
];

$jsonFiles = [
    'symptom_knowledge_base.json',
    'red_flags_library.json',
    'clinical_context_rules.json',
    'hiligaynon_symptom_lexicon.json',
    'hiligaynon_symptom_phrases.json',
];

function normKey(string $s): string
{
    return strtolower(trim(preg_replace('/\s+/u', ' ', $s) ?? ''));
}

function readCsv(string $path): array
{
    if (!is_readable($path)) {
        return [];
    }
    $handle = fopen($path, 'r');
    if ($handle === false) {
        return [];
    }
    $header = fgetcsv($handle);
    $rows = [];
    while (($row = fgetcsv($handle)) !== false) {
        $rows[] = array_combine(
            array_map(static fn ($h) => strtolower(trim((string) $h)), $header ?: []),
            array_map(static fn ($v) => trim((string) $v), $row)
        ) ?: [];
    }
    fclose($handle);

    return $rows;
}

// ── Load canonical concepts from KB ──────────────────────────────────────────
MedicalConceptRegistry::clearCache();
SymptomKnowledgeBase::clearCache();
$concepts = MedicalConceptRegistry::concepts();
$conceptIds = array_keys($concepts);

$stats = [
    'datasets_analyzed'        => 0,
    'datasets_missing'         => 0,
    'datasets_loaded_runtime'  => 0,
    'canonical_concepts'       => count($concepts),
    'aliases_registered'       => 0,
    'duplicate_aliases'        => [],
    'orphan_csv_terms'         => [],
    'missing_hiligaynon'       => [],
    'missing_filipino'           => [],
    'missing_synonyms'         => [],
    'combination_conflicts'    => [],
    'translation_inconsistencies' => [],
    'broken_references'        => [],
    'sync_actions'             => [],
];

foreach ($concepts as $c) {
    $stats['aliases_registered'] += count($c['aliases'] ?? []);
}

// ── Audit each dataset ───────────────────────────────────────────────────────
$datasetCatalog = [];
foreach ($auditFiles as $file) {
    $path = BASE_PATH . '/data/nlp/' . $file;
    $exists = is_readable($path);
    $stats['datasets_analyzed']++;
    if (!$exists) {
        $stats['datasets_missing']++;
        $datasetCatalog[$file] = ['status' => 'missing', 'rows' => 0];
        continue;
    }

    $rows = str_ends_with($file, '.json')
        ? (json_decode((string) file_get_contents($path), true) ?: [])
        : readCsv($path);
    $rowCount = is_array($rows) ? (is_array($rows[0] ?? null) ? count($rows) : count(array_filter(array_keys($rows), 'is_int'))) : 0;
    if (str_ends_with($file, '.json') && isset($rows['symptoms'])) {
        $rowCount = count($rows['symptoms']);
    } elseif (str_ends_with($file, '.json') && isset($rows['red_flags'])) {
        $rowCount = count($rows['red_flags']);
    }

    $wired = match ($file) {
        'symptom_knowledge_base.json' => 'SymptomKnowledgeBase',
        'red_flags_library.json' => 'SymptomKnowledgeBase',
        'clinical_context_rules.json' => 'ClinicalContextReasoningEngine',
        'symptom_combinations.csv' => 'ClinicalTriageEngine',
        'emergency_red_flags.csv', 'emergency_flags.csv' => 'ClinicalTriageEngine / EmergencyFlagsLoader',
        'negation_words.csv' => 'NegationDetector',
        'medical_misspellings.csv', 'misspellings.csv', 'medical_abbreviations.csv', 'hiligaynon_chat_shorthand.csv' => 'MedicalMisspellingsLoader',
        'duration_patterns.csv', 'temperature_patterns.csv', 'pain_scale.csv', 'risk_factors.csv', 'chronic_conditions.csv' => 'NlpFeaturePatternsLoader',
        'clinical_reasoning_rules.csv' => 'ClinicalReasoningRulesLoader',
        'triage_rules.csv', 'triage_rules_cds.csv' => 'TriageRulesLoader',
        'canonical_symptom_aliases.csv' => 'MedicalConceptRegistry',
        'translation_dictionary.csv', 'medical_phrases.csv' => 'MedicalDictionary / MedicalTranslator',
        'hiligaynon_medical_terms.csv', 'filipino_medical_terms.csv', 'medical_phrases.csv' => 'SymptomKnowledgeBase CSV boosts',
        default => 'reference',
    };

    $datasetCatalog[$file] = [
        'status' => $wired === 'reference' ? 'reference' : 'loaded',
        'rows'   => $rowCount,
        'loader' => $wired,
    ];
    if ($wired !== 'reference') {
        $stats['datasets_loaded_runtime']++;
    }
}

// Missing multilingual coverage in KB
$kb = SymptomKnowledgeBase::load();
$kbById = [];
foreach (($kb['symptoms'] ?? []) as $sym) {
    if (!is_array($sym)) {
        continue;
    }
    $sid = strtolower((string) ($sym['id'] ?? ''));
    if ($sid !== '') {
        $kbById[$sid] = $sym;
    }
}
foreach ($concepts as $id => $c) {
    $sym = $kbById[$id] ?? null;
    if ($sym === null) {
        continue;
    }
    if (empty($sym['hiligaynon_terms'])) {
        $stats['missing_hiligaynon'][] = $id;
    }
    if (empty($sym['filipino_terms'])) {
        $stats['missing_filipino'][] = $id;
    }
}
$stats['missing_hiligaynon'] = array_slice($stats['missing_hiligaynon'], 0, 50);
$stats['missing_filipino'] = array_slice($stats['missing_filipino'], 0, 50);

// Translation inconsistencies: english_medical_terms with different canonical targets for same concept
$engTerms = readCsv(BASE_PATH . '/data/nlp/english_medical_terms.csv');
$engByNorm = [];
foreach ($engTerms as $row) {
    $term = normKey((string) ($row['term'] ?? ''));
    $norm = normKey((string) ($row['normalized'] ?? $term));
    if ($term === '') {
        continue;
    }
    $canonical = MedicalConceptRegistry::canonicalize($term);
    if (!isset($engByNorm[$norm])) {
        $engByNorm[$norm] = $canonical;
    } elseif ($engByNorm[$norm] !== $canonical && $canonical !== $term) {
        $stats['translation_inconsistencies'][] = "{$term} → {$canonical} (expected {$engByNorm[$norm]})";
    }
}
$stats['translation_inconsistencies'] = array_slice($stats['translation_inconsistencies'], 0, 30);

// Symptom combination conflicts
$combos = readCsv(BASE_PATH . '/data/nlp/symptom_combinations.csv');
$pairClasses = [];
foreach ($combos as $row) {
    $a = normKey((string) ($row['symptom_a'] ?? ''));
    $b = normKey((string) ($row['symptom_b'] ?? ''));
    if ($a === '' || $b === '') {
        continue;
    }
    $key = implode('|', array_sort([$a, $b]));
    $pairClasses[$key][strtoupper((string) ($row['classification'] ?? ''))] = true;
}
foreach ($pairClasses as $pair => $classes) {
    if (count($classes) > 1) {
        $stats['combination_conflicts'][] = $pair . ': ' . implode(' vs ', array_keys($classes));
    }
}
$stats['combination_conflicts'] = array_slice($stats['combination_conflicts'], 0, 25);

// Orphan terms in symptom_weights not in KB
$weights = readCsv(BASE_PATH . '/data/nlp/symptom_weights.csv');
foreach ($weights as $row) {
    $cid = strtolower((string) ($row['concept'] ?? ''));
    if ($cid !== '' && !isset($concepts[$cid])) {
        $stats['broken_references'][] = "symptom_weights.csv: unknown concept '{$cid}'";
    }
}
$stats['broken_references'] = array_slice($stats['broken_references'], 0, 40);

// ── Sync: export medical_concepts_registry.csv ─────────────────────────────
if ($opts['sync']) {
    $registryPath = BASE_PATH . '/data/nlp/medical_concepts_registry.csv';
    $handle = fopen($registryPath, 'w');
    fputcsv($handle, [
        'concept_id', 'canonical_name', 'medical_category', 'severity_weight',
        'emergency_weight', 'urgent_weight', 'danger_sign', 'alias', 'alias_type', 'status',
    ]);
    foreach ($concepts as $id => $c) {
        $written = [];
        foreach ($c['aliases'] as $alias) {
            $k = normKey($alias);
            if ($k === '' || isset($written[$k])) {
                continue;
            }
            $written[$k] = true;
            $type = 'synonym';
            if (normKey($c['canonical_name']) === $k) {
                $type = 'canonical';
            } elseif ($k === $id || $k === str_replace('_', ' ', $id)) {
                $type = 'concept_id';
            }
            fputcsv($handle, [
                $id,
                $c['canonical_name'],
                $c['medical_category'],
                $c['severity_weight'],
                $c['emergency_weight'],
                $c['urgent_weight'],
                !empty($c['danger_sign']) ? 'yes' : 'no',
                $alias,
                $type,
                'active',
            ]);
        }
    }
    fclose($handle);
    $stats['sync_actions'][] = 'Exported medical_concepts_registry.csv (' . count($concepts) . ' concepts)';

    // Append missing Hiligaynon terms from KB to hiligaynon_medical_terms.csv
    $hilPath = BASE_PATH . '/data/nlp/hiligaynon_medical_terms.csv';
    $existingHil = [];
    foreach (readCsv($hilPath) as $row) {
        $existingHil[normKey((string) ($row['term'] ?? ''))] = true;
    }
    $hilAdded = 0;
    $hilHandle = fopen($hilPath, 'a');
    foreach (SymptomKnowledgeBase::load()['symptoms'] ?? [] as $sym) {
        if (!is_array($sym)) {
            continue;
        }
        $eng = (string) ($sym['symptom_name'] ?? '');
        $cid = (string) ($sym['id'] ?? '');
        foreach (($sym['hiligaynon_terms'] ?? []) as $term) {
            $term = trim((string) $term);
            if ($term === '' || isset($existingHil[normKey($term)])) {
                continue;
            }
            fputcsv($hilHandle, ['', $term, $eng, $cid, 'hiligaynon', 'active']);
            $existingHil[normKey($term)] = true;
            $hilAdded++;
        }
    }
    fclose($hilHandle);
    if ($hilAdded > 0) {
        $stats['sync_actions'][] = "Added {$hilAdded} Hiligaynon terms to hiligaynon_medical_terms.csv";
    }

    // Same for Filipino
    $filPath = BASE_PATH . '/data/nlp/filipino_medical_terms.csv';
    $existingFil = [];
    foreach (readCsv($filPath) as $row) {
        $existingFil[normKey((string) ($row['term'] ?? ''))] = true;
    }
    $filAdded = 0;
    $filHandle = fopen($filPath, 'a');
    foreach (SymptomKnowledgeBase::load()['symptoms'] ?? [] as $sym) {
        if (!is_array($sym)) {
            continue;
        }
        $eng = (string) ($sym['symptom_name'] ?? '');
        $cid = (string) ($sym['id'] ?? '');
        foreach (($sym['filipino_terms'] ?? []) as $term) {
            $term = trim((string) $term);
            if ($term === '' || isset($existingFil[normKey($term)])) {
                continue;
            }
            fputcsv($filHandle, ['', $term, $eng, $cid, 'filipino', 'active']);
            $existingFil[normKey($term)] = true;
            $filAdded++;
        }
    }
    fclose($filHandle);
    if ($filAdded > 0) {
        $stats['sync_actions'][] = "Added {$filAdded} Filipino terms to filipino_medical_terms.csv";
    }

    MedicalConceptRegistry::clearCache();
    SymptomKnowledgeBase::clearCache();
}

// ── Gold validation check ────────────────────────────────────────────────────
$goldPath = BASE_PATH . '/data/nlp/validation/triage_validation_gold.csv';
$goldTotal = 0;
$goldCorrect = 0;
if (!$opts['skip_gold'] && is_readable($goldPath)) {
    foreach (readCsv($goldPath) as $row) {
        $complaint = trim((string) ($row['chief_complaint'] ?? ''));
        $expected = strtoupper((string) ($row['expected_classification'] ?? ''));
        if ($complaint === '' || !in_array($expected, ['NON-URGENT', 'URGENT', 'EMERGENCY'], true)) {
            continue;
        }
        $goldTotal++;
        $r = ClinicalTriageEngine::assess($complaint, $complaint, [], [], 80);
        if (strtoupper((string) ($r['triage_display'] ?? '')) === $expected) {
            $goldCorrect++;
        }
    }
}

$report = [
    'generated_at'              => date('c'),
    'engine_version'            => '3.2',
    'canonical_source'          => 'symptom_knowledge_base.json',
    'registry_class'            => 'MedicalConceptRegistry',
    'summary'                   => $stats,
    'dataset_catalog'           => $datasetCatalog,
    'gold_validation'           => [
        'total'    => $goldTotal,
        'correct'  => $goldCorrect,
        'accuracy' => $goldTotal > 0 ? round(100 * $goldCorrect / $goldTotal, 2) : 0,
    ],
    'synchronization_status'    => [
        'unified_vocabulary'      => true,
        'canonical_aliases_file'  => 'canonical_symptom_aliases.csv',
        'registry_export'         => 'medical_concepts_registry.csv',
        'runtime_resolution'      => 'MedicalConceptRegistry::resolve()',
        'workflow'                => 'complaint → language → normalize → translate → canonical concepts → entities → reasoning → classify',
    ],
    'recommendations'           => array_values(array_filter([
        count($stats['missing_hiligaynon']) > 0 ? 'Add Hiligaynon terms for ' . count($stats['missing_hiligaynon']) . '+ KB symptoms (run --sync)' : null,
        count($stats['combination_conflicts']) > 0 ? 'Resolve ' . count($stats['combination_conflicts']) . ' symptom combination classification conflicts' : null,
        count($stats['broken_references']) > 0 ? 'Fix broken concept references in CSV datasets' : null,
    ])),
];

if ($opts['report']) {
    $jsonPath = $reportDir . '/dataset_sync_report.json';
    $mdPath = $reportDir . '/dataset_sync_report.md';
    file_put_contents($jsonPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    $md = "# NLP Dataset Synchronization Report\n\n";
    $md .= "Generated: {$report['generated_at']}\n\n";
    $md .= "## Summary\n\n";
    $md .= "| Metric | Value |\n|--------|-------|\n";
    $md .= "| Datasets analyzed | {$stats['datasets_analyzed']} |\n";
    $md .= "| Datasets missing | {$stats['datasets_missing']} |\n";
    $md .= "| Runtime-loaded datasets | {$stats['datasets_loaded_runtime']} |\n";
    $md .= "| Canonical concepts | {$stats['canonical_concepts']} |\n";
    $md .= "| Registered aliases | {$stats['aliases_registered']} |\n";
    $md .= "| Gold validation | {$goldCorrect}/{$goldTotal} ({$report['gold_validation']['accuracy']}%) |\n\n";
    if ($stats['sync_actions'] !== []) {
        $md .= "## Sync Actions\n\n";
        foreach ($stats['sync_actions'] as $a) {
            $md .= "- {$a}\n";
        }
        $md .= "\n";
    }
    if ($stats['combination_conflicts'] !== []) {
        $md .= "## Combination Conflicts\n\n";
        foreach ($stats['combination_conflicts'] as $c) {
            $md .= "- {$c}\n";
        }
        $md .= "\n";
    }
    if ($stats['broken_references'] !== []) {
        $md .= "## Broken References\n\n";
        foreach ($stats['broken_references'] as $b) {
            $md .= "- {$b}\n";
        }
        $md .= "\n";
    }
    $md .= "## Dataset Catalog\n\n";
    $md .= "| File | Status | Rows | Loader |\n|------|--------|------|--------|\n";
    foreach ($datasetCatalog as $file => $info) {
        $md .= "| `{$file}` | {$info['status']} | {$info['rows']} | {$info['loader']} |\n";
    }
    file_put_contents($mdPath, $md);

    echo "Report written:\n  {$jsonPath}\n  {$mdPath}\n";
}

echo json_encode([
    'canonical_concepts' => $stats['canonical_concepts'],
    'datasets_analyzed' => $stats['datasets_analyzed'],
    'gold_validation' => $report['gold_validation'],
    'sync_actions' => $stats['sync_actions'],
], JSON_PRETTY_PRINT) . PHP_EOL;

function array_sort(array $a): array
{
    sort($a);

    return $a;
}
