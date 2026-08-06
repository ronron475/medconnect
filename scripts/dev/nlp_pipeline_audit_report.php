<?php
/**
 * Generate NLP pipeline audit report after CDSS improvements.
 *
 * Usage: php scripts/dev/nlp_pipeline_audit_report.php
 */
require dirname(__DIR__, 2) . '/bootstrap/app.php';

echo "Generating NLP pipeline audit report...\n";

$reportDir = BASE_PATH . '/data/nlp/reports';
if (!is_dir($reportDir)) {
    mkdir($reportDir, 0755, true);
}

$testCases = [
    ['before' => 'sakit kag d nko kaginhawa', 'after_expected' => 'EMERGENCY', 'note' => 'Chat shorthand breathing emergency'],
    ['before' => "I can't breathe", 'after_expected' => 'EMERGENCY', 'note' => 'English airway emergency'],
    ['before' => 'medicine refill', 'after_expected' => 'NON-URGENT', 'note' => 'Administrative request'],
    ['before' => 'chest pain with difficulty breathing', 'after_expected' => 'EMERGENCY', 'note' => 'Combined circulation + breathing'],
    ['before' => 'Budlay ginhwa ko.', 'after_expected' => 'EMERGENCY', 'note' => 'Misspelled dyspnea'],
];

$results = [];
foreach ($testCases as $case) {
    $r = ClinicalTriageEngine::assess($case['before'], $case['before'], [], [], 80);
    $results[] = [
        'input' => $case['before'],
        'classification' => (string) ($r['triage_display'] ?? ''),
        'expected' => $case['after_expected'],
        'pass' => strtoupper((string) ($r['triage_display'] ?? '')) === $case['after_expected'],
        'normalized' => (string) ($r['normalized_text'] ?? ''),
        'winning_rule' => (string) ($r['validation']['winning_rule'] ?? ''),
        'note' => $case['note'],
    ];
}

$fixes = [
    'Hiligaynon chat shorthand normalization (d nko → indi ko, kaginahawa → kaginhawa)',
    'ClinicalTriageEngine preprocess chain: normalize → misspellings',
    'Breathing emergency pattern scan before classification',
    'TriageSelfValidationEngine chronic_disease_detected logic fix',
    'Expanded life-threat and breathing priority patterns',
    'NlpFeaturePatternsLoader wires duration/temperature/pain/risk CSVs',
    'NlpPipelineDebug step trace (MEDCONNECT_NLP_DEBUG=1 or ?debug=1)',
    'hiligaynon_chat_shorthand.csv dataset added',
    'ClinicalReasoningRulesLoader integrated into emergency reason text',
    'Gold validation cases GOLD0026–GOLD0029 added',
];

$issues = [
    'Chat shorthand (d nko) not expanded before entity extraction',
    'chronic_disease_detected validation always failed when chronic not mentioned',
    'Orphaned CDS CSVs (duration_patterns, pain_scale, risk_factors) not used at runtime',
    'No pipeline debug trace for misclassification diagnosis',
    'Breathing emergencies missed when only partial phrase matched (kaginhawa without indi ko expanded)',
    'clinical_reasoning_rules.csv loaded but not used in reason generation',
];

$datasets = [
    'hiligaynon_chat_shorthand.csv' => 'MedicalMisspellingsLoader',
    'duration_patterns.csv' => 'NlpFeaturePatternsLoader → ClinicalFeatureExtractors',
    'temperature_patterns.csv' => 'NlpFeaturePatternsLoader → ClinicalFeatureExtractors',
    'pain_scale.csv' => 'NlpFeaturePatternsLoader → ClinicalFeatureExtractors',
    'risk_factors.csv' => 'NlpFeaturePatternsLoader → ClinicalFeatureExtractors',
    'clinical_reasoning_rules.csv' => 'ClinicalReasoningRulesLoader → ClinicalTriageEngine::buildReason',
    'misspellings.csv' => 'MedicalMisspellingsLoader',
    'symptom_knowledge_base.json' => 'SymptomKnowledgeBase',
    'red_flags_library.json' => 'SymptomKnowledgeBase',
    'clinical_context_rules.json' => 'ClinicalContextReasoningEngine',
    'symptom_combinations.csv' => 'ClinicalTriageEngine',
    'emergency_red_flags.csv' => 'ClinicalTriageEngine',
    'negation_words.csv' => 'NegationDetector',
];

$report = [
    'generated_at' => date('c'),
    'engine_version' => '3.2',
    'issues_found' => $issues,
    'fixes_applied' => $fixes,
    'datasets_wired' => $datasets,
    'rules_added_or_corrected' => [
        'Breathing emergency scan (scanBreathingEmergencyPatterns)',
        'Chat shorthand normalization patterns in HiligaynonTextNormalizer',
        'Life-threat regex: indi ko kaginhawa, indi|dili|wala + ginhawa context',
        'Rule priority: emergency_red_flags overrides individual_symptoms',
        'Self-validation chronic_disease_detected boolean fix',
    ],
    'test_cases' => $results,
    'debug_mode' => [
        'env' => 'MEDCONNECT_NLP_DEBUG=1',
        'demo_api' => 'POST debug=1 to cds_triage_demo.php',
        'response_key' => 'pipeline_debug',
    ],
];

$jsonPath = $reportDir . '/nlp_pipeline_improvement_report.json';
$mdPath = $reportDir . '/nlp_pipeline_improvement_report.md';
file_put_contents($jsonPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

$md = "# NLP Pipeline Improvement Report\n\n";
$md .= "Generated: {$report['generated_at']}\n\n";
$md .= "## Issues Found\n";
foreach ($issues as $i) {
    $md .= "- {$i}\n";
}
$md .= "\n## Fixes Applied\n";
foreach ($fixes as $f) {
    $md .= "- {$f}\n";
}
$md .= "\n## Datasets Wired\n";
foreach ($datasets as $file => $loader) {
    $md .= "- `{$file}` → {$loader}\n";
}
$md .= "\n## Test Cases (Before → After)\n\n";
$md .= "| Input | Normalized | Result | Expected | Pass |\n";
$md .= "|-------|------------|--------|----------|------|\n";
foreach ($results as $r) {
    $pass = $r['pass'] ? '✓' : '✗';
    $md .= "| {$r['input']} | {$r['normalized']} | {$r['classification']} | {$r['expected']} | {$pass} |\n";
}
$md .= "\n## Debug Mode\n";
$md .= "- Set `MEDCONNECT_NLP_DEBUG=1` or pass `debug=1` to the CDS demo API.\n";
$md .= "- Response includes `pipeline_debug` with normalization → extraction → classification steps.\n";

file_put_contents($mdPath, $md);

echo "Report written:\n  {$jsonPath}\n  {$mdPath}\n";
$passed = count(array_filter($results, static fn (array $r): bool => $r['pass']));
echo "Spot checks: {$passed}/" . count($results) . " passed\n";
