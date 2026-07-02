<?php
/**
 * Hiligaynon Medical NLP Pipeline v2 — 10-step telemedicine consultation understanding.
 *
 * 1. Language identification
 * 2. Text normalization
 * 3. Phrase-level understanding
 * 4. Hiligaynon → English translation
 * 5. Medical concept extraction
 * 6. Dataset matching (English only)
 * 7. Fuzzy matching
 * 8. Medical classification
 * 9. Triage detection
 * 10. Structured response
 */

final class HiligaynonMedicalNlpPipeline
{
    public const VERSION = '2.0';

    /**
     * @return array<string, mixed>
     */
    public static function analyze(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return self::emptyResult();
        }

        $language = HiligaynonLanguageDetector::detect($text);
        $normalized = HiligaynonTextNormalizer::normalize($text);
        $phraseVariants = HiligaynonTextNormalizer::phraseVariants($text);

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

        $concepts = $phraseTranslation !== null
            ? MedicalConceptExtractor::enrichFromTranslation($phraseTranslation)
            : [];

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

        $englishTranslation = (string) ($translation['english_text'] ?? ($phraseTranslation['english'] ?? ''));
        $triage = MedicalTriageDetector::detect($text, $englishTranslation, $phraseTranslation ?? [], $concepts);
        $classification = MedicalConceptExtractor::classify($concepts, $phraseTranslation ?? []);
        $matchedDatasetTerms = self::collectMatchedTerms($termResults);
        $confidence = self::computeConfidence($termResults, $phraseTranslation);

        $bodyParts = [];
        foreach ($concepts as $c) {
            if (!empty($c['body_part'])) {
                $bodyParts[] = $c['body_part'];
            }
        }
        $bodyParts = array_values(array_unique($bodyParts));

        $structured = [
            'original_text'          => $text,
            'detected_language'      => $language['primary'],
            'language_tags'          => $language['tags'],
            'normalized_text'        => $normalized,
            'english_translation'    => $englishTranslation,
            'medical_concepts'       => $concepts,
            'body_parts'             => $bodyParts,
            'category'               => $classification['category'],
            'severity'               => $triage['severity'],
            'triage_level'           => $triage['triage_level'],
            'triage_reason'          => $triage['reason'],
            'matched_dataset_terms'  => $matchedDatasetTerms,
            'confidence_score'       => $confidence,
            'phrase_source'          => $phraseTranslation['source'] ?? null,
        ];

        $highlight = MedicalTextAnalysisWorkflow::buildHighlightPublic($englishTranslation, $termResults);
        $validCount = (int) ($datasetValidation['valid_count'] ?? 0);
        $invalidCount = (int) ($datasetValidation['invalid_count'] ?? 0);
        $totalCount = (int) ($datasetValidation['total_count'] ?? 0);

        return [
            'workflow' => [
                'version' => self::VERSION,
                'steps'   => [
                    'language_identification',
                    'text_normalization',
                    'phrase_level_understanding',
                    'hiligaynon_to_english_translation',
                    'medical_concept_extraction',
                    'dataset_matching',
                    'fuzzy_matching',
                    'medical_classification',
                    'triage_detection',
                    'structured_response',
                ],
                'policy'  => 'Phrase-first Hiligaynon medical interpretation. '
                    . 'Full patient message is normalized and translated before any dataset search. '
                    . 'English medical concepts only for official dataset matching.',
            ],
            'nlp_result'             => $structured,
            'original_input'       => $text,
            'normalized_input'     => $normalized,
            'detected_language'    => $language['primary'],
            'language_detection'   => $language,
            'preprocessing'        => $preprocessing,
            'translation'          => $translation,
            'translated_english'   => $englishTranslation,
            'highlighted_english'  => $highlight['html'],
            'highlight_segments'   => $highlight['segments'],
            'fuzzy_matching'       => $fuzzyMatching,
            'dataset_validation'   => $datasetValidation,
            'matched_records'      => $datasetValidation['matched_records'] ?? [],
            'term_results'         => $termResults,
            'valid_count'          => $validCount,
            'invalid_count'        => $invalidCount,
            'total_count'          => $totalCount,
            'validation_status'    => MedicalTextAnalysisWorkflow::validationStatusPublic($validCount, $invalidCount, $totalCount),
            'validation_status_label' => MedicalTextAnalysisWorkflow::validationStatusLabelPublic($validCount, $invalidCount, $totalCount),
            'summary'              => self::buildSummary($structured, $validCount, $totalCount),
            'dictionary'           => MedicalDictionary::stats(),
        ];
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
        $en = (string) ($structured['english_translation'] ?? '');
        $triage = (string) ($structured['triage_level'] ?? 'LOW');
        if ($en === '') {
            return 'Could not interpret the patient message.';
        }

        $matchPart = $validCount > 0
            ? " {$validCount}/{$totalCount} term(s) matched official datasets."
            : ' No official dataset match yet.';

        return "Translated: \"{$en}\". Triage: {$triage}.{$matchPart}";
    }
}
