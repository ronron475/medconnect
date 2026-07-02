<?php
/**
 * API: NLP translation and medical term recognition for free text.
 * Accepts Hiligaynon, Ilonggo, or English; returns translated text, detected terms,
 * matched dataset records, and validation status.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';

Api::startJson();
Api::requirePost();

$text = trim((string) ($_POST['text'] ?? $_POST['input'] ?? $_POST['medical_text'] ?? ''));

if ($text === '') {
    Api::error('Enter medical text in Hiligaynon, Ilonggo, or English.');
}

$serviceData = AiServiceClient::analyzeMedicalText($text);

if ($serviceData) {
    $pipeline = array_merge($serviceData, [
        'engine'       => (string) ($serviceData['engine'] ?? 'python-medical-text-nlp'),
        'service_used' => true,
    ]);
} else {
    $pipeline = MedicalTextAnalysisWorkflow::analyze($text);
    $pipeline['engine'] = 'php-medical-text-analysis';
    $pipeline['service_used'] = false;
}

$responseData = [
    'workflow'                => $pipeline['workflow'] ?? [],
    'original_input'          => $pipeline['original_input'] ?? $text,
    'normalized_input'        => $pipeline['normalized_input'] ?? '',
    'detected_language'       => $pipeline['detected_language'] ?? 'unknown',
    'preprocessing'           => $pipeline['preprocessing'] ?? [],
    'translation'             => $pipeline['translation'] ?? [],
    'translated_english'      => $pipeline['translated_english'] ?? '',
    'highlighted_english'     => $pipeline['highlighted_english'] ?? '',
    'highlight_segments'      => $pipeline['highlight_segments'] ?? [],
    'detected_keywords'       => $pipeline['detected_keywords'] ?? [],
    'fuzzy_matching'          => $pipeline['fuzzy_matching'] ?? [],
    'dataset_validation'      => $pipeline['dataset_validation'] ?? [],
    'matched_records'         => $pipeline['matched_records'] ?? [],
    'term_results'            => $pipeline['term_results'] ?? [],
    'valid_count'             => (int) ($pipeline['valid_count'] ?? 0),
    'invalid_count'           => (int) ($pipeline['invalid_count'] ?? 0),
    'total_count'             => (int) ($pipeline['total_count'] ?? 0),
    'validation_status'       => $pipeline['validation_status'] ?? 'empty',
    'validation_status_label' => $pipeline['validation_status_label'] ?? '',
    'summary'                 => $pipeline['summary'] ?? '',
    'nlp_result'              => $pipeline['nlp_result'] ?? [],
    'engine'                  => (string) ($pipeline['engine'] ?? 'php-medical-text-analysis'),
    'service_used'            => (bool) ($pipeline['service_used'] ?? false),
    'service_online'          => AiServiceClient::isHealthy(),
    'ai_service'              => AiServiceClient::connectionStatus(),
    'dictionary'              => MedicalDictionary::stats(),
];

$message = (string) ($pipeline['summary'] ?? 'Medical text analysis complete.');
Api::success(['data' => $responseData], $message);
