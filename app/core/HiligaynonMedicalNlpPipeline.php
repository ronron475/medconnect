<?php
/**
 * Hiligaynon Medical NLP Pipeline v3 — evidence-based clinical triage CDS.
 *
 * Pipeline: normalize → spell-correct → detect language → translate → tokenize →
 * extract symptoms/duration/pain/temperature/risks/age → red flags → severity →
 * priority → explanation → recommendation.
 *
 * Does not diagnose disease or prescribe medication.
 */

final class HiligaynonMedicalNlpPipeline
{
    public const VERSION = '3.0';

    public const PIPELINE_STEPS = [
        'detect_language',
        'normalize_text',
        'correct_misspellings',
        'translate_hiligaynon_to_english',
        'tokenize',
        'remove_unnecessary_words',
        'medical_entity_recognition',
        'body_part_recognition',
        'extract_symptoms',
        'negation_detection',
        'extract_duration',
        'extract_temperature',
        'extract_pain_scale',
        'extract_age_group',
        'pregnancy_detection',
        'extract_risk_factors',
        'detect_emergency_red_flags',
        'symptom_combination_analysis',
        'calculate_severity_score',
        'clinical_reasoning',
        'confidence_calculation',
        'priority_classification',
        'return_recommendation',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function analyze(string $text): array
    {
        NlpPipelineDebug::enable();
        NlpPipelineDebug::reset();
        $text = trim($text);
        if ($text === '') {
            return self::emptyResult();
        }

        NlpPipelineDebug::step('detect_language', ['input' => $text]);
        $language = HiligaynonLanguageDetector::detect($text);
        $normalized = HiligaynonTextNormalizer::normalize($text);
        $correctionLog = MedicalMisspellingsLoader::applyCorrectionsWithLog($normalized);
        $correctedText = (string) ($correctionLog['text'] ?? $normalized);
        $correctedWords = is_array($correctionLog['corrections'] ?? null) ? $correctionLog['corrections'] : [];
        $phraseVariants = HiligaynonTextNormalizer::phraseVariants($correctedText !== '' ? $correctedText : $text);
        NlpPipelineDebug::step('normalize_text', [
            'normalized'      => $normalized,
            'corrected'       => $correctedText,
            'corrected_words' => $correctedWords,
            'variants'        => $phraseVariants,
        ]);

        $literalTranslation = ChiefComplaintLiteralTranslator::translate($text, $correctedText, $language);
        NlpPipelineDebug::step('literal_translation', $literalTranslation);

        $phraseTranslation = null;
        foreach ($phraseVariants as $variant) {
            $phraseTranslation = HiligaynonPhraseTranslator::translateFullPhrase($variant);
            if ($phraseTranslation !== null) {
                break;
            }
        }

        if ($phraseTranslation === null && HiligaynonLanguageDetector::isLocalLanguage($text)) {
            $phraseTranslation = HiligaynonPhraseTranslator::translateFullPhrase($normalized);
        }

        $concepts = [];

        $preprocessing = NlpPreprocessor::preprocessField($text, 'medical_text');
        $preprocessing['normalized'] = $normalized;
        $preprocessing['language_detection'] = $language;

        if (HiligaynonLanguageDetector::isLocalLanguage($text)) {
            $translation = MedicalTranslator::translateField($preprocessing, 'auto');
        } else {
            $translation = MedicalTextAnalysisWorkflow::translateTextLegacy($preprocessing);
        }

        if ($phraseTranslation !== null) {
            $translation['english_text'] = $phraseTranslation['english'];
            $translation['phrase_translation'] = $phraseTranslation;
        }

        $fuzzyMatching = MedicalFuzzyMatcher::matchTextQueue($translation['validation_queue'] ?? []);
        $datasetValidation = MedicalDatasetValidator::validateTextAnalysis($fuzzyMatching);
        $termResults = MedicalTextAnalysisWorkflow::buildTermResultsPublic($translation, $fuzzyMatching, $datasetValidation);

        $englishTranslation = trim((string) ($literalTranslation['english'] ?? ''));
        if ($englishTranslation === '') {
            $englishTranslation = (string) ($translation['english_text'] ?? ($phraseTranslation['english'] ?? ''));
        }
        $clinicalEnglish = StandardizedConceptMapper::clinicalHaystack(
            [],
            $phraseTranslation,
            self::collectMatchedTerms($termResults)
        );
        if ($clinicalEnglish === '') {
            $clinicalEnglish = (string) ($phraseTranslation['medical_keyword'] ?? ($phraseTranslation['english'] ?? ''));
        }

        $matchedDatasetTerms = self::collectMatchedTerms($termResults);
        $concepts = StandardizedConceptMapper::map(
            $correctedText !== '' ? $correctedText : $normalized,
            $literalTranslation,
            $phraseTranslation,
            $termResults,
            $matchedDatasetTerms
        );
        NlpPipelineDebug::step('standardized_concepts', ['concepts' => $concepts]);

        $confidence = self::computeConfidence($termResults, $phraseTranslation);
        $confidencePct = (int) round($confidence * 100);

        $triage = MedicalTriageDetector::detect(
            $text,
            $englishTranslation,
            $phraseTranslation ?? [],
            $concepts,
            $matchedDatasetTerms,
            $confidencePct,
            $clinicalEnglish
        );
        $classification = MedicalConceptExtractor::classify($concepts, $phraseTranslation ?? []);

        $bodyParts = [];
        foreach ($concepts as $c) {
            if (!empty($c['body_part'])) {
                $bodyParts[] = $c['body_part'];
            }
        }
        $bodyParts = array_values(array_unique($bodyParts));

        $structured = [
            'original_text'                  => $text,
            'original_chief_complaint'       => $text,
            'detected_language'              => $language['primary'],
            'language_tags'                  => $language['tags'],
            'normalized_text'                => $normalized,
            'corrected_text'                 => $correctedText,
            'corrected_words'                => $correctedWords,
            'english_translation'            => $englishTranslation,
            'literal_translation'            => $literalTranslation,
            'clinical_english'               => $clinicalEnglish,
            'standardized_medical_concepts'  => $concepts,
            'medical_concepts'               => $concepts,
            'associated_symptoms'            => $triage['associated_symptoms'] ?? [],
            'body_parts'             => $bodyParts,
            'category'               => $classification['category'],
            'severity'               => $triage['severity'] ?? 'mild',
            'triage_level'           => $triage['triage_level'] ?? 'LOW',
            'triage_display'         => $triage['triage_display'] ?? 'NON-URGENT',
            'triage_reason'          => $triage['reason'] ?? '',
            'matched_dataset_terms'  => $matchedDatasetTerms,
            'confidence_score'       => $confidence,
            'phrase_source'          => $phraseTranslation['source'] ?? null,
            'detected_symptoms'      => $triage['detected_symptoms'] ?? [],
            'duration'               => $triage['duration'] ?? '',
            'pain_scale'             => $triage['pain_scale'] ?? [],
            'temperature'            => $triage['temperature'] ?? [],
            'risk_factors'           => $triage['risk_factors'] ?? [],
            'age_group'              => $triage['age_group'] ?? 'Unknown',
            'red_flags'              => $triage['red_flags'] ?? [],
            'severity_score'         => $triage['severity_score'] ?? 0,
            'classification'         => $triage['triage_display'] ?? 'NON-URGENT',
            'priority'               => $triage['priority'] ?? 'Normal',
            'confidence'              => $triage['confidence_score'] ?? $confidencePct,
            'reason'                 => $triage['reason'] ?? '',
            'recommendation'         => $triage['recommendation'] ?? '',
            'pipeline_stages'                => [
                'original'              => $text,
                'language'              => $language,
                'normalized'            => $normalized,
                'corrected'             => $correctedText,
                'corrected_words'       => $correctedWords,
                'literal_translation'   => $literalTranslation,
                'standardized_concepts' => $concepts,
                'entities'              => $triage['entities'] ?? [],
                'clinical_reasoning'    => (string) ($triage['clinical_reasoning'] ?? ($triage['reason'] ?? '')),
                'classification'        => (string) ($triage['triage_display'] ?? 'NON-URGENT'),
            ],
            'needs_provider_review'  => (bool) ($triage['needs_provider_review'] ?? false),
        ];

        $highlight = MedicalTextAnalysisWorkflow::buildHighlightPublic($englishTranslation, $termResults);
        $validCount = (int) ($datasetValidation['valid_count'] ?? 0);
        $invalidCount = (int) ($datasetValidation['invalid_count'] ?? 0);
        $totalCount = (int) ($datasetValidation['total_count'] ?? 0);

        $output = [
            'workflow' => [
                'version'  => self::VERSION,
                'steps'    => self::PIPELINE_STEPS,
                'purpose'  => 'clinical_triage_decision_support',
                'does_not' => ['diagnose_disease', 'prescribe_medication'],
                'policy'   => 'Evidence-based rule triage CDS. '
                    . 'Phrase-first Hiligaynon/Filipino/English interpretation with KB-driven severity scoring. '
                    . 'Never diagnoses disease and never prescribes medication.',
            ],
            'nlp_result'              => $structured,
            'clinical_recommendation'=> $triage['recommendation_payload'] ?? [],
            'triage'                  => $triage,
            'original_input'          => $text,
            'normalized_input'        => $normalized,
            'corrected_input'         => $correctedText,
            'corrected_words'         => $correctedWords,
            'detected_language'       => $language['primary'],
            'language_detection'      => $language,
            'preprocessing'           => $preprocessing,
            'translation'             => $translation,
            'translated_english'      => $englishTranslation,
            'highlighted_english'     => $highlight['html'],
            'highlight_segments'      => $highlight['segments'],
            'fuzzy_matching'          => $fuzzyMatching,
            'dataset_validation'      => $datasetValidation,
            'matched_records'         => $datasetValidation['matched_records'] ?? [],
            'term_results'            => $termResults,
            'valid_count'             => $validCount,
            'invalid_count'           => $invalidCount,
            'total_count'             => $totalCount,
            'validation_status'       => MedicalTextAnalysisWorkflow::validationStatusPublic($validCount, $invalidCount, $totalCount),
            'validation_status_label' => MedicalTextAnalysisWorkflow::validationStatusLabelPublic($validCount, $invalidCount, $totalCount),
            'summary'                 => self::buildSummary($structured, $validCount, $totalCount),
            'dictionary'              => MedicalDictionary::stats(),
            'engine'                  => 'php-hiligaynon-nlp-v3',
        ];
        NlpPipelineDebug::attach($output);

        return $output;
    }

    /**
     * @return array<string, mixed>
     */
    private static function emptyResult(): array
    {
        return [
            'workflow' => ['version' => self::VERSION, 'steps' => [], 'policy' => ''],
            'nlp_result' => [
                'original_text' => '',
                'detected_language' => 'unknown',
                'normalized_text' => '',
                'english_translation' => '',
                'medical_concepts' => [],
                'body_parts' => [],
                'category' => '',
                'severity' => '',
                'triage_level' => '',
                'matched_dataset_terms' => [],
                'confidence_score' => 0.0,
            ],
            'original_input' => '',
            'validation_status' => 'empty',
            'summary' => 'No input provided.',
        ];
    }

    /**
     * @param list<array<string, mixed>> $termResults
     * @return list<string>
     */
    private static function collectMatchedTerms(array $termResults): array
    {
        $terms = [];
        foreach ($termResults as $row) {
            if (($row['validation_status'] ?? '') === 'valid' && !empty($row['standardized_term'])) {
                $terms[] = (string) $row['standardized_term'];
            }
        }

        return array_values(array_unique($terms));
    }

    /**
     * @param list<array<string, mixed>> $termResults
     * @param array<string, mixed>|null $phraseTranslation
     */
    private static function computeConfidence(array $termResults, ?array $phraseTranslation): float
    {
        if ($termResults === []) {
            return $phraseTranslation !== null ? 0.65 : 0.0;
        }

        $scores = [];
        foreach ($termResults as $row) {
            if (($row['validation_status'] ?? '') === 'valid') {
                $scores[] = (int) ($row['fuzzy_score'] ?? 0);
            }
        }

        if ($scores === []) {
            return 0.4;
        }

        return round(min(1.0, array_sum($scores) / (count($scores) * 100)), 2);
    }

    /**
     * @param array<string, mixed> $structured
     */
    private static function buildSummary(array $structured, int $validCount, int $totalCount): string
    {
        $display = (string) ($structured['triage_display'] ?? $structured['triage_level'] ?? 'NON-URGENT');
        $score = (int) ($structured['severity_score'] ?? 0);
        $conf = (int) ($structured['confidence'] ?? 0);
        if ($display === '') {
            return 'Could not interpret the patient message.';
        }

        return "Triage: {$display} (score={$score}, confidence={$conf}%).";
    }
}
