<?php
/**
 * Pipeline diagnostics and warnings for NLP demo debugging.
 */

final class NlpPipelineDiagnostics
{
    /** @return array<string, mixed> */
    public static function collect(array $preprocessing, array $translation, bool $serviceUsed): array
    {
        $dictStats = MedicalDictionary::stats();
        $datasetStats = HiligaynonNlpDataset::stats();
        $aiStatus = MedicalAiInterpreter::providerStatus();
        $serviceStatus = AiServiceClient::connectionStatus();

        $conditionsPrep = is_array($preprocessing['conditions'] ?? null) ? $preprocessing['conditions'] : [];
        $warnings = [];

        if (($dictStats['loaded'] ?? 0) === 0) {
            $warnings[] = 'Medical dictionary not loaded or empty.';
        }
        if (($datasetStats['row_count'] ?? 0) === 0) {
            $warnings[] = 'Hiligaynon NLP dataset not loaded or empty.';
        }
        if (!($aiStatus['groq_configured'] ?? false)) {
            $warnings[] = 'Groq API key missing — set GROQ_API_KEY in .env or system environment.';
        }
        if (!$serviceUsed) {
            $warnings[] = 'Python AI service offline — using PHP validation workflow.';
        }
        if (!($aiStatus['enabled'] ?? true)) {
            $warnings[] = 'AI interpreter disabled via MEDCONNECT_AI_INTERPRETER=0.';
        }

        $aiInterp = is_array($translation['ai_interpretation'] ?? null) ? $translation['ai_interpretation'] : [];
        $groqStatus = (string) ($aiInterp['status'] ?? 'unknown');

        return [
            'dictionary' => [
                'loaded'     => (bool) (($dictStats['loaded'] ?? 0) > 0),
                'term_count' => (int) ($dictStats['loaded'] ?? 0),
                'path'       => MedicalDictionary::csvPath(),
            ],
            'hiligaynon_dataset' => [
                'loaded'    => (bool) (($datasetStats['row_count'] ?? 0) > 0),
                'row_count' => (int) ($datasetStats['row_count'] ?? 0),
                'variant_count' => (int) ($datasetStats['variant_count'] ?? 0),
                'path'      => HiligaynonNlpDataset::csvPath(),
                'wv_symptoms' => is_readable(BASE_PATH . '/data/nlp/hiligaynon_symptoms.csv'),
                'symptom_phrases' => is_readable(BASE_PATH . '/data/nlp/symptom_phrases.csv'),
                'reproductive_expansion' => is_readable(BASE_PATH . '/data/nlp/hiligaynon_reproductive_expansion.csv'),
                'triage_rules' => is_readable(BASE_PATH . '/data/nlp/triage_rules.csv'),
                'emergency_flags' => is_readable(BASE_PATH . '/data/nlp/emergency_flags.csv'),
                'misspellings' => is_readable(BASE_PATH . '/data/nlp/medical_misspellings.csv'),
                'phrase_engine' => is_readable(BASE_PATH . '/data/nlp/phrase_engine/symptom_roots.json'),
                'combinatorial_phrases' => is_readable(BASE_PATH . '/data/nlp/hiligaynon_combinatorial_phrases.csv'),
                'combinatorial_coverage' => PhraseCombinatorialEngine::estimateCombinationCount(),
            ],
            'keyword_extraction' => [
                'conditions_keywords' => $conditionsPrep['keywords'] ?? [],
                'allergies_keywords'  => ($preprocessing['allergies']['keywords'] ?? []),
            ],
            'groq' => [
                'configured'  => (bool) ($aiStatus['groq_configured'] ?? false),
                'enabled'     => (bool) ($aiStatus['enabled'] ?? true),
                'model'       => GROQ_MODEL,
                'status'      => $groqStatus,
                'provider'    => $aiInterp['provider'] ?? null,
                'called'      => in_array($groqStatus, ['complete', 'fallback'], true),
                'connected'   => ($aiInterp['status'] ?? '') === 'complete' && ($aiInterp['provider'] ?? '') === 'groq',
                'error'       => $aiInterp['groq_error'] ?? ($aiInterp['notes'] ?? null),
                'confidence'  => $aiInterp['overall_confidence'] ?? null,
            ],
            'python_service' => [
                'online'  => (bool) ($serviceStatus['online'] ?? false),
                'url'     => (string) ($serviceStatus['url'] ?? AI_SERVICE_BASE_URL),
                'used'    => $serviceUsed,
            ],
            'translation' => [
                'combined_english' => (string) ($translation['combined_english'] ?? ''),
                'overall_status'   => (string) ($translation['overall_status'] ?? ''),
                'queue_conditions' => count($translation['conditions']['validation_queue'] ?? []),
                'queue_allergies'  => count($translation['allergies']['validation_queue'] ?? []),
            ],
            'warnings' => $warnings,
        ];
    }
}
