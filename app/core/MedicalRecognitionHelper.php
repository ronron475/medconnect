<?php
/**
 * Shared helpers for field-level medical term recognition UI (Step 3 + text analysis).
 */

final class MedicalRecognitionHelper
{
    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    public static function detectedKeywordsFromItems(array $items): array
    {
        $keywords = [];
        foreach ($items as $item) {
            $keywords[] = [
                'local_term'          => (string) ($item['local_term'] ?? ''),
                'english_term'        => (string) ($item['english_term'] ?? ''),
                'dictionary_category' => (string) ($item['category'] ?? ''),
                'was_translated'      => (bool) ($item['was_translated'] ?? false),
                'input_language'      => (string) ($item['input_language'] ?? 'unknown'),
                'translation_status'  => (string) ($item['status'] ?? ''),
            ];
        }

        return $keywords;
    }

    /**
     * @param list<array<string, mixed>> $termResults
     * @return array{html:string, segments:list<array<string, mixed>>}
     */
    public static function buildHighlight(string $translatedEnglish, array $termResults): array
    {
        if ($translatedEnglish === '') {
            return ['html' => '', 'segments' => []];
        }

        $validTerms = [];
        foreach ($termResults as $term) {
            $isValid = ($term['display_status'] ?? '') === 'valid' || !empty($term['highlight']);
            if (!$isValid) {
                continue;
            }
            if (empty($term['standardized_term'])) {
                continue;
            }
            $validTerms[] = [
                'phrase'    => (string) $term['standardized_term'],
                'term_type' => (string) ($term['term_type'] ?? $term['field'] ?? ''),
                'record_id' => $term['dataset_record_id'] ?? null,
            ];
            $english = (string) ($term['english_term'] ?? '');
            if ($english !== '' && $english !== ($term['standardized_term'] ?? '')) {
                $validTerms[] = [
                    'phrase'    => $english,
                    'term_type' => (string) ($term['term_type'] ?? $term['field'] ?? ''),
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
                'segments' => [['text' => $translatedEnglish, 'valid' => false]],
            ];
        }

        usort($markers, static fn ($a, $b) => $a['start'] <=> $b['start']);
        $merged = [];
        foreach ($markers as $marker) {
            if ($merged !== [] && $marker['start'] < $merged[count($merged) - 1]['end']) {
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
     * @param list<array<string, mixed>> $termResults
     * @return array<string, mixed>
     */
    public static function buildFieldRecognition(
        string $originalInput,
        array $preprocessingBlock,
        array $translationBlock,
        array $termResults
    ): array {
        $translatedEnglish = (string) ($translationBlock['english_text'] ?? '');
        if ($translatedEnglish === '') {
            $translatedEnglish = (string) ($preprocessingBlock['english_preview'] ?? '');
        }
        $translatedEnglish = NlpPreprocessor::removeFillers($translatedEnglish);
        $highlight = self::buildHighlight($translatedEnglish, $termResults);

        $valid = 0;
        $invalid = 0;
        foreach ($termResults as $term) {
            if (($term['display_status'] ?? '') === 'valid') {
                $valid++;
            } else {
                $invalid++;
            }
        }

        return [
            'original_input'      => $originalInput,
            'normalized_input'    => (string) ($preprocessingBlock['normalized'] ?? ''),
            'translated_english'  => $translatedEnglish,
            'highlighted_english' => $highlight['html'],
            'highlight_segments'  => $highlight['segments'],
            'detected_keywords'   => self::detectedKeywordsFromItems($translationBlock['items'] ?? []),
            'valid_count'         => $valid,
            'invalid_count'       => $invalid,
            'total_count'         => count($termResults),
        ];
    }

    public static function detectFieldLanguage(array $preprocessingBlock, array $translationBlock): string
    {
        $hasTranslation = false;
        foreach ($translationBlock['items'] ?? [] as $item) {
            if (!empty($item['was_translated'])) {
                $hasTranslation = true;
                break;
            }
        }
        $original = (string) ($preprocessingBlock['original'] ?? '');
        $hasNonAscii = preg_match('/[^\x00-\x7F]/u', $original) === 1;

        if (!$hasNonAscii && !$hasTranslation) {
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

    public static function termTypeLabel(string $field, string $category = ''): string
    {
        $cat = strtolower($category);
        if ($cat === 'symptom' || $cat === 'symptoms') {
            return 'symptom';
        }
        if ($cat === 'allergy' || $cat === 'allergies' || $field === 'allergies') {
            return 'allergy';
        }

        return 'condition';
    }
}
