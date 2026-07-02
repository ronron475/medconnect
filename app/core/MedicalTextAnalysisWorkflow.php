<?php
/**
 * NLP translation and medical term recognition for Hiligaynon/Ilonggo/English free text.
 *
 * Pipeline: normalize → dictionary translate → fuzzy match (conditions, allergies, symptoms)
 * → dataset validation → highlight only official dataset matches.
 */

final class MedicalTextAnalysisWorkflow
{
    public const WORKFLOW_VERSION = '1.2';

    public static function analyze(string $text): array
    {
        return HiligaynonMedicalNlpPipeline::analyze($text);
    }

    /**
     * @param array<string, mixed> $preprocessing
     * @return array<string, mixed>
     */
    public static function translateTextLegacy(array $preprocessing): array
    {
        return self::translateText($preprocessing);
    }

    /**
     * @param array<string, mixed> $translation
     * @param array<string, mixed> $fuzzyMatching
     * @param array<string, mixed> $datasetValidation
     * @return list<array<string, mixed>>
     */
    public static function buildTermResultsPublic(
        array $translation,
        array $fuzzyMatching,
        array $datasetValidation
    ): array {
        return self::buildTermResults($translation, $fuzzyMatching, $datasetValidation);
    }

    /**
     * @param list<array<string, mixed>> $termResults
     * @return array{html:string, segments:list<array<string, mixed>>}
     */
    public static function buildHighlightPublic(string $translatedEnglish, array $termResults): array
    {
        return self::buildHighlight($translatedEnglish, $termResults);
    }

    public static function validationStatusPublic(int $valid, int $invalid, int $total): string
    {
        return self::validationStatus($valid, $invalid, $total);
    }

    public static function validationStatusLabelPublic(int $valid, int $invalid, int $total): string
    {
        return self::validationStatusLabel($valid, $invalid, $total);
    }

    /**
     * @deprecated Use HiligaynonMedicalNlpPipeline::analyze()
     * @return array<string, mixed>
     */
    private static function analyzeLegacy(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return self::emptyResult();
        }

        $preprocessing = NlpPreprocessor::preprocessField($text, 'medical_text');
        $translation = self::translateText($preprocessing);
        $fuzzyMatching = MedicalFuzzyMatcher::matchTextQueue($translation['validation_queue']);
        $datasetValidation = MedicalDatasetValidator::validateTextAnalysis($fuzzyMatching);
        $termResults = self::buildTermResults($translation, $fuzzyMatching, $datasetValidation);
        $detectedKeywords = self::buildDetectedKeywords($preprocessing, $translation);
        $translatedEnglish = (string) ($translation['english_text'] ?? '');
        $highlight = self::buildHighlight($translatedEnglish, $termResults);

        $validCount = (int) ($datasetValidation['valid_count'] ?? 0);
        $invalidCount = (int) ($datasetValidation['invalid_count'] ?? 0);
        $totalCount = (int) ($datasetValidation['total_count'] ?? 0);

        return [
            'workflow' => [
                'version' => self::WORKFLOW_VERSION,
                'steps'   => [
                    'detect_language',
                    'translate_full_phrase',
                    'extract_english_concepts',
                    'fuzzy_match_datasets',
                    'dataset_validate',
                    'highlight_valid_terms',
                ],
                'policy'  => 'Hiligaynon input is translated as a full phrase before dataset lookup. '
                    . 'Medical datasets are searched using English translations and keywords only — '
                    . 'never raw Hiligaynon tokens. Phrase-level meaning takes priority over single words.',
            ],
            'original_input'       => $text,
            'normalized_input'     => (string) ($preprocessing['normalized'] ?? ''),
            'detected_language'    => self::detectLanguage($preprocessing, $translation),
            'preprocessing'        => $preprocessing,
            'translation'          => $translation,
            'translated_english'   => $translatedEnglish,
            'highlighted_english'  => $highlight['html'],
            'highlight_segments'   => $highlight['segments'],
            'detected_keywords'    => $detectedKeywords,
            'fuzzy_matching'       => $fuzzyMatching,
            'dataset_validation'   => $datasetValidation,
            'matched_records'      => $datasetValidation['matched_records'] ?? [],
            'term_results'         => $termResults,
            'valid_count'          => $validCount,
            'invalid_count'        => $invalidCount,
            'total_count'          => $totalCount,
            'validation_status'    => self::validationStatus($validCount, $invalidCount, $totalCount),
            'validation_status_label' => self::validationStatusLabel($validCount, $invalidCount, $totalCount),
            'summary'              => self::buildSummary($termResults, $validCount, $invalidCount, $totalCount),
            'dictionary'           => MedicalDictionary::stats(),
        ];
    }

    /**
     * @param array<string, mixed> $preprocessing
     * @return array<string, mixed>
     */
    private static function translateText(array $preprocessing): array
    {
        $keywords = $preprocessing['keywords'] ?? [];
        $normalized = (string) ($preprocessing['normalized'] ?? '');
        $cleaned = (string) ($preprocessing['cleaned'] ?? '');
        $englishPreview = (string) ($preprocessing['english_preview'] ?? '');
        $original = (string) ($preprocessing['original'] ?? $normalized);

        $phraseInput = $normalized !== '' ? $normalized : ($cleaned !== '' ? $cleaned : $original);
        if ($phraseInput !== '' && HiligaynonPhraseTranslator::isHiligaynonInput($phraseInput)) {
            $fieldResult = MedicalTranslator::translateField($preprocessing, 'auto');
            $fieldResult['pipeline'] = 'phrase_first';

            return $fieldResult;
        }

        $items = [];
        $validationQueue = [];
        $seenEnglish = [];
        $matched = 0;
        $unmatched = 0;

        foreach ($keywords as $keyword) {
            $item = MedicalTranslator::translateTerm($keyword, 'auto');
            $items[] = $item;
            if (($item['status'] ?? '') === 'matched') {
                $matched++;
            } else {
                $unmatched++;
            }
            self::appendQueueItem($validationQueue, $item, $seenEnglish);
        }

        if ($items === [] && ($normalized !== '' || $cleaned !== '')) {
            $fullItem = self::translatePhrase($normalized ?: $cleaned);
            $items[] = $fullItem;
            if (($fullItem['status'] ?? '') === 'matched') {
                $matched++;
            } else {
                $unmatched++;
            }
            self::appendQueueItem($validationQueue, $fullItem, $seenEnglish);
        }

        if ($validationQueue === [] && $englishPreview !== '') {
            foreach (self::splitPhrases($englishPreview) as $phrase) {
                $item = MedicalTranslator::translateTerm($phrase, 'auto');
                $items[] = $item;
                self::appendQueueItem($validationQueue, $item, $seenEnglish);
            }
        }

        $englishText = MedicalDictionary::translateText($normalized);
        if ($englishText === '' && $englishPreview !== '') {
            $englishText = $englishPreview;
        }
        if ($englishText === '' && $cleaned !== '') {
            $englishText = $cleaned;
        }
        $englishText = NlpPreprocessor::removeFillers($englishText);

        $total = count($validationQueue);
        $status = self::translationStatus($matched, $unmatched, max(count($keywords), $total));

        return [
            'status'             => $status,
            'status_label'       => self::translationStatusLabel($status, $matched, max(1, count($keywords))),
            'english_text'       => $englishText,
            'english_preview'    => $englishPreview ?: $englishText,
            'matched_count'      => $matched,
            'unmatched_count'    => $unmatched,
            'total_count'        => $total,
            'items'              => $items,
            'validation_queue'   => $validationQueue,
            'translate_first'    => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function translatePhrase(string $text): array
    {
        $english = MedicalDictionary::translateText($text);
        if ($english === '' || mb_strtolower($english) === mb_strtolower($text)) {
            return MedicalTranslator::translateTerm($text, 'auto');
        }

        return MedicalTranslator::translateTerm($english, 'auto');
    }

    /**
     * @param list<array<string, mixed>> $queue
     * @param array<string, mixed> $item
     * @param array<string, bool> $seenEnglish
     */
    private static function appendQueueItem(array &$queue, array $item, array &$seenEnglish): void
    {
        $matchTerm = trim((string) ($item['match_term'] ?? $item['english_term'] ?? ''));
        if ($matchTerm === '') {
            return;
        }
        $key = mb_strtolower($matchTerm);
        if (isset($seenEnglish[$key])) {
            return;
        }
        $seenEnglish[$key] = true;
        $queue[] = [
            'local_term'     => $item['local_term'] ?? '',
            'english_term'   => $item['english_term'] ?? $matchTerm,
            'match_term'     => $matchTerm,
            'category'       => $item['category'] ?? '',
            'status'         => $item['status'] ?? '',
            'input_language' => $item['input_language'] ?? 'unknown',
            'was_translated' => (bool) ($item['was_translated'] ?? false),
        ];
    }

    /** @return list<string> */
    private static function splitPhrases(string $english): array
    {
        $parts = preg_split('/\s*,\s*/', $english);
        $out = [];
        foreach ($parts ?: [] as $part) {
            $part = trim((string) $part);
            if ($part !== '') {
                $out[] = $part;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $preprocessing
     * @param array<string, mixed> $translation
     * @return list<array<string, mixed>>
     */
    private static function buildDetectedKeywords(array $preprocessing, array $translation): array
    {
        $keywords = [];
        foreach ($translation['items'] ?? [] as $item) {
            $keywords[] = [
                'local_term'     => (string) ($item['local_term'] ?? ''),
                'english_term'   => (string) ($item['english_term'] ?? ''),
                'dictionary_category' => (string) ($item['category'] ?? ''),
                'was_translated' => (bool) ($item['was_translated'] ?? false),
                'input_language' => (string) ($item['input_language'] ?? 'unknown'),
                'translation_status' => (string) ($item['status'] ?? ''),
            ];
        }

        if ($keywords === []) {
            foreach ($preprocessing['keywords'] ?? [] as $kw) {
                $keywords[] = [
                    'local_term'     => (string) $kw,
                    'english_term'   => MedicalDictionary::translateLocal((string) $kw),
                    'dictionary_category' => '',
                    'was_translated' => false,
                    'input_language' => 'unknown',
                    'translation_status' => 'extracted',
                ];
            }
        }

        return $keywords;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function buildTermResults(
        array $translation,
        array $fuzzyMatching,
        array $datasetValidation
    ): array {
        $fuzzyByEnglish = [];
        foreach ($fuzzyMatching['results'] ?? [] as $row) {
            $key = mb_strtolower((string) ($row['match_term'] ?? $row['english_term'] ?? ''));
            if ($key !== '') {
                $fuzzyByEnglish[$key] = $row;
            }
        }

        $datasetByEnglish = [];
        foreach (['conditions', 'allergies', 'symptoms'] as $field) {
            foreach (($datasetValidation[$field]['results'] ?? []) as $row) {
                $key = mb_strtolower((string) ($row['english_term'] ?? ''));
                if ($key !== '') {
                    $datasetByEnglish[$key] = $row;
                }
            }
        }

        $terms = [];
        foreach ($translation['validation_queue'] ?? [] as $item) {
            $english = (string) ($item['english_term'] ?? '');
            $key = mb_strtolower($english);
            $fuzzy = $fuzzyByEnglish[$key] ?? null;
            $dataset = $datasetByEnglish[$key] ?? null;

            $datasetValid = ($dataset['final_status'] ?? '') === 'valid';
            $fuzzyAccepted = ($fuzzy['validation_status'] ?? '') === 'accepted';
            $displayValid = $datasetValid && $fuzzyAccepted;
            $termType = self::termTypeLabel((string) ($dataset['category'] ?? $fuzzy['category'] ?? ''));

            $terms[] = [
                'term_type'          => $termType,
                'field'              => $termType,
                'original_local'     => (string) ($item['local_term'] ?? ''),
                'input_language'     => (string) ($item['input_language'] ?? 'unknown'),
                'was_translated'     => (bool) ($item['was_translated'] ?? false),
                'english_term'       => $english,
                'standardized_term'  => $displayValid
                    ? (string) ($dataset['record']['name'] ?? $fuzzy['standardized_term'] ?? $english)
                    : null,
                'dataset_record_id'  => $displayValid ? ($dataset['record']['record_id'] ?? null) : null,
                'dataset_table'      => $displayValid ? (string) ($dataset['dataset_table'] ?? '') : null,
                'dataset_source'     => $displayValid ? (string) ($dataset['dataset_source'] ?? '') : null,
                'matched_record'     => $displayValid ? ($dataset['record'] ?? null) : null,
                'fuzzy_score'        => (int) ($fuzzy['similarity_score'] ?? 0),
                'translation_status' => (string) ($item['status'] ?? ''),
                'match_language'     => 'english',
                'dataset_valid'      => $datasetValid,
                'display_status'     => $displayValid ? 'valid' : 'invalid',
                'validation_status'  => $displayValid ? 'valid' : 'invalid',
                'highlight'          => $displayValid,
                'user_message'       => $displayValid
                    ? 'Found in official ' . $termType . ' dataset.'
                    : self::invalidMessage($item, $fuzzy, $dataset, $termType),
            ];
        }

        return $terms;
    }

    private static function termTypeLabel(string $category): string
    {
        return match (strtolower($category)) {
            'allergy', 'allergies' => 'allergy',
            'symptom', 'symptoms' => 'symptom',
            default => 'condition',
        };
    }

    /**
     * @param array<string, mixed>|null $fuzzy
     * @param array<string, mixed>|null $dataset
     */
    private static function invalidMessage(
        array $item,
        ?array $fuzzy,
        ?array $dataset,
        string $termType
    ): string {
        $local = (string) ($item['local_term'] ?? '');
        $english = (string) ($item['english_term'] ?? '');

        if (($item['was_translated'] ?? false) && $english !== '') {
            $base = "Translated “{$local}” → “{$english}”, but no matching official {$termType} record was found.";
        } else {
            $base = "“{$english}” is not listed in the official {$termType} dataset.";
        }

        if (($fuzzy['validation_status'] ?? '') === 'unrecognized') {
            return $base . ' Only terms from the official Medical Conditions, Allergies, or Symptoms datasets are valid.';
        }

        return (string) ($dataset['validation_message'] ?? $base);
    }

    /**
     * @param list<array<string, mixed>> $termResults
     * @return array{html:string, segments:list<array<string, mixed>>}
     */
    private static function buildHighlight(string $translatedEnglish, array $termResults): array
    {
        if ($translatedEnglish === '') {
            return ['html' => '', 'segments' => []];
        }

        $validTerms = [];
        foreach ($termResults as $term) {
            if (empty($term['highlight']) || empty($term['standardized_term'])) {
                continue;
            }
            $validTerms[] = [
                'phrase'    => (string) $term['standardized_term'],
                'term_type' => (string) ($term['term_type'] ?? ''),
                'record_id' => $term['dataset_record_id'] ?? null,
            ];
            if (($term['english_term'] ?? '') !== ($term['standardized_term'] ?? '')) {
                $validTerms[] = [
                    'phrase'    => (string) $term['english_term'],
                    'term_type' => (string) ($term['term_type'] ?? ''),
                    'record_id' => $term['dataset_record_id'] ?? null,
                ];
            }
        }

        usort($validTerms, static fn ($a, $b) => mb_strlen($b['phrase']) <=> mb_strlen($a['phrase']));

        $text = $translatedEnglish;
        $markers = [];
        foreach ($validTerms as $term) {
            $phrase = $term['phrase'];
            if ($phrase === '') {
                continue;
            }
            $pattern = '/(?<!\w)' . preg_quote($phrase, '/') . '(?!\w)/iu';
            if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $markers[] = [
                        'start'     => $match[1],
                        'end'       => $match[1] + strlen($match[0]),
                        'phrase'    => $match[0],
                        'term_type' => $term['term_type'],
                        'record_id' => $term['record_id'],
                    ];
                }
            }
        }

        if ($markers === []) {
            return [
                'html' => htmlspecialchars($translatedEnglish, ENT_QUOTES, 'UTF-8'),
                'segments' => [
                    ['text' => $translatedEnglish, 'valid' => false],
                ],
            ];
        }

        usort($markers, static fn ($a, $b) => $a['start'] <=> $b['start']);
        $merged = [];
        foreach ($markers as $marker) {
            if ($merged === []) {
                $merged[] = $marker;
                continue;
            }
            $last = $merged[count($merged) - 1];
            if ($marker['start'] < $last['end']) {
                continue;
            }
            $merged[] = $marker;
        }

        $segments = [];
        $html = '';
        $cursor = 0;
        $len = strlen($text);

        foreach ($merged as $marker) {
            if ($marker['start'] > $cursor) {
                $plain = substr($text, $cursor, $marker['start'] - $cursor);
                $segments[] = ['text' => $plain, 'valid' => false];
                $html .= htmlspecialchars($plain, ENT_QUOTES, 'UTF-8');
            }
            $highlightText = substr($text, $marker['start'], $marker['end'] - $marker['start']);
            $segments[] = [
                'text'      => $highlightText,
                'valid'     => true,
                'term_type' => $marker['term_type'],
                'record_id' => $marker['record_id'],
            ];
            $html .= '<mark class="nlp-valid-term" data-term-type="'
                . htmlspecialchars($marker['term_type'], ENT_QUOTES, 'UTF-8')
                . '" data-record-id="'
                . htmlspecialchars((string) ($marker['record_id'] ?? ''), ENT_QUOTES, 'UTF-8')
                . '">'
                . htmlspecialchars($highlightText, ENT_QUOTES, 'UTF-8')
                . '</mark>';
            $cursor = $marker['end'];
        }

        if ($cursor < $len) {
            $plain = substr($text, $cursor);
            $segments[] = ['text' => $plain, 'valid' => false];
            $html .= htmlspecialchars($plain, ENT_QUOTES, 'UTF-8');
        }

        return ['html' => $html, 'segments' => $segments];
    }

    /**
     * @param array<string, mixed> $preprocessing
     * @param array<string, mixed> $translation
     */
    private static function detectLanguage(array $preprocessing, array $translation): string
    {
        $hasTranslation = false;
        $hasEnglishOnly = true;
        foreach ($translation['items'] ?? [] as $item) {
            if (!empty($item['was_translated'])) {
                $hasTranslation = true;
            }
            if (($item['input_language'] ?? '') !== 'english') {
                $hasEnglishOnly = false;
            }
        }

        $original = (string) ($preprocessing['original'] ?? '');
        $hasNonAscii = preg_match('/[^\x00-\x7F]/u', $original) === 1;

        if (!$hasNonAscii && !$hasTranslation) {
            return 'english';
        }
        if ($hasEnglishOnly && !$hasTranslation && !$hasNonAscii) {
            return 'english';
        }
        if ($hasTranslation && $hasNonAscii) {
            return 'hiligaynon_mixed';
        }
        if ($hasTranslation || $hasNonAscii) {
            return 'hiligaynon';
        }

        return 'english';
    }

    /**
     * @param list<array<string, mixed>> $termResults
     */
    private static function buildSummary(
        array $termResults,
        int $validCount,
        int $invalidCount,
        int $totalCount
    ): string {
        if ($totalCount === 0) {
            return 'No medical terms were extracted from your input.';
        }

        $parts = [];
        foreach ($termResults as $term) {
            if (empty($term['highlight'])) {
                continue;
            }
            $label = ucfirst((string) ($term['term_type'] ?? 'term'));
            $input = (string) ($term['original_local'] ?: $term['english_term']);
            $standard = (string) ($term['standardized_term'] ?? '');
            $parts[] = "{$label}: {$input} → {$standard} (verified)";
        }

        if ($parts === []) {
            return "Extracted {$totalCount} term(s); none matched the official Medical Conditions, Allergies, or Symptoms datasets.";
        }

        $summary = implode('. ', $parts) . '.';
        if ($invalidCount > 0) {
            $summary .= " {$invalidCount} term(s) were not found in official datasets and are not highlighted.";
        }

        return $summary;
    }

    private static function validationStatus(int $valid, int $invalid, int $total): string
    {
        if ($total === 0) {
            return 'empty';
        }
        if ($invalid === 0 && $valid > 0) {
            return 'complete';
        }
        if ($valid > 0) {
            return 'partial';
        }

        return 'none';
    }

    private static function validationStatusLabel(int $valid, int $invalid, int $total): string
    {
        if ($total === 0) {
            return 'No medical terms detected';
        }
        if ($invalid === 0) {
            return "All {$valid} detected term(s) verified in official datasets";
        }
        if ($valid > 0) {
            return "{$valid}/{$total} term(s) verified; {$invalid} not in official datasets";
        }

        return "0/{$total} term(s) matched official datasets";
    }

    private static function translationStatus(int $matched, int $unmatched, int $total): string
    {
        if ($total === 0) {
            return 'empty';
        }
        if ($unmatched === 0) {
            return 'complete';
        }
        if ($matched === 0) {
            return 'unmatched';
        }

        return 'partial';
    }

    private static function translationStatusLabel(string $status, int $matched, int $total): string
    {
        return match ($status) {
            'complete'  => "All terms translated to English ({$matched}/{$total})",
            'partial'   => "Partial translation to English ({$matched}/{$total})",
            'unmatched' => 'Could not map all terms to English via dictionary',
            'empty'     => 'No keywords to translate',
            default     => $status,
        };
    }

    /** @return array<string, mixed> */
    private static function emptyResult(): array
    {
        return [
            'workflow' => ['version' => self::WORKFLOW_VERSION, 'steps' => [], 'policy' => ''],
            'original_input' => '',
            'normalized_input' => '',
            'detected_language' => 'unknown',
            'translated_english' => '',
            'highlighted_english' => '',
            'highlight_segments' => [],
            'detected_keywords' => [],
            'matched_records' => [],
            'term_results' => [],
            'valid_count' => 0,
            'invalid_count' => 0,
            'total_count' => 0,
            'validation_status' => 'empty',
            'validation_status_label' => 'No input provided',
            'summary' => 'Enter Hiligaynon, Ilonggo, or English text to analyze.',
        ];
    }
}
