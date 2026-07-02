<?php
/**
 * Hiligaynon symptom recognition — phrase + fuzzy matching (PHP fallback).
 */
require_once __DIR__ . '/SymptomLexicon.php';
require_once __DIR__ . '/NlpPreprocessor.php';

final class HiligaynonSymptomMatcher
{
    public static function collapseRepeatedCharacters(string $text, int $maxRepeat = 2): string
    {
        if ($text === '') {
            return '';
        }

        return preg_replace_callback(
            '/(.)\1{2,}/u',
            static fn ($m) => str_repeat($m[1], $maxRepeat),
            $text
        ) ?? $text;
    }

    public static function normalizeSymptomText(string $text): string
    {
        if (trim($text) === '') {
            return '';
        }
        $lowered = self::collapseRepeatedCharacters(mb_strtolower(trim($text)));
        $cleaned = preg_replace('/[^a-z0-9\s\-]/u', ' ', $lowered) ?? $lowered;
        $cleaned = preg_replace('/\s+/u', ' ', $cleaned) ?? $cleaned;

        return trim($cleaned);
    }

    /**
     * @return array<string, mixed>
     */
    public static function recognize(string $text, ?int $threshold = null, bool $phraseOnly = false): array
    {
        $original = $text;
        $normalized = self::normalizeSymptomText($text);
        $cleaned = NlpPreprocessor::removeFillers($normalized);
        $working = $cleaned !== '' ? $cleaned : $normalized;
        $thresh = $threshold ?? SymptomLexicon::fuzzyThreshold();
        $index = SymptomLexicon::variantIndex();

        $detections = [];
        $seenKeys = [];
        $occupied = array_fill(0, max(mb_strlen($working), 1), false);

        $add = static function (
            string $detected,
            string $canonical,
            array $meta,
            int $confidence,
            string $method
        ) use (&$detections, &$seenKeys): void {
            $key = $meta['symptom_key'] ?? '';
            if ($key === '' || isset($seenKeys[$key])) {
                return;
            }
            $seenKeys[$key] = true;
            $detections[] = [
                'detected_symptom' => $detected,
                'normalized_symptom' => $canonical,
                'english_translation' => $meta['english'] ?? '',
                'medical_term' => $meta['medical_term'] ?? $key,
                'category' => $meta['category'] ?? 'general',
                'symptom_key' => $key,
                'confidence' => $confidence,
                'match_method' => $method,
            ];
        };

        foreach (SymptomLexicon::variantsByLength() as $variant) {
            $pattern = '/(?<!\w)' . preg_quote($variant, '/') . '(?!\w)/iu';
            if (!preg_match_all($pattern, $working, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $meta = $index[$variant] ?? null;
            if (!$meta) {
                continue;
            }
            foreach ($matches[0] as $match) {
                $snippet = (string) ($match[0] ?? '');
                $add($snippet, $variant, $meta, 100, 'exact_phrase');
            }
        }

        $tokens = preg_split('/\s+/u', $working, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $candidates = [];
        for ($size = 4; $size >= 1; $size--) {
            for ($i = 0; $i <= count($tokens) - $size; $i++) {
                $phrase = implode(' ', array_slice($tokens, $i, $size));
                if (mb_strlen($phrase) >= 3) {
                    $candidates[] = $phrase;
                }
            }
        }

        foreach ($candidates as $candidate) {
            [$meta, $score] = self::fuzzyMatchVariant($candidate, $thresh);
            if ($meta && !isset($seenKeys[$meta['symptom_key']])) {
                $add(
                    $candidate,
                    $meta['canonical_variant'] ?? $candidate,
                    $meta,
                    $score,
                    'fuzzy'
                );
            }
        }

        foreach ($tokens as $token) {
            if ($phraseOnly) {
                break;
            }
            if (mb_strlen($token) < 3) {
                continue;
            }
            [$meta, $score] = self::fuzzyMatchVariant($token, $thresh);
            if ($meta && $score >= $thresh && !isset($seenKeys[$meta['symptom_key']])) {
                $add($token, $meta['canonical_variant'] ?? $token, $meta, $score, 'fuzzy_token');
            }
        }

        usort($detections, static fn ($a, $b) => ($b['confidence'] <=> $a['confidence']));

        $englishSymptoms = [];
        $seenEn = [];
        foreach ($detections as $d) {
            $en = mb_strtolower((string) ($d['english_translation'] ?? ''));
            if ($en !== '' && !isset($seenEn[$en])) {
                $seenEn[$en] = true;
                $englishSymptoms[] = $d['english_translation'];
            }
        }

        return [
            'original_text' => $original,
            'normalized_text' => $normalized,
            'cleaned_text' => $cleaned,
            'fuzzy_threshold' => $thresh,
            'detections' => $detections,
            'detection_count' => count($detections),
            'english_symptoms' => $englishSymptoms,
            'lexicon' => SymptomLexicon::stats(),
        ];
    }

    /**
     * @return array{0:?array,1:int}
     */
    private static function fuzzyMatchVariant(string $candidate, int $threshold): array
    {
        $index = SymptomLexicon::variantIndex();
        $key = SymptomLexicon::normalizeVariant($candidate);
        if ($key === '' || !$index) {
            return [null, 0];
        }
        if (isset($index[$key])) {
            return [$index[$key], 100];
        }

        $bestMeta = null;
        $bestScore = 0;
        foreach ($index as $variant => $meta) {
            similar_text($key, $variant, $percent);
            $score = (int) round($percent);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMeta = $meta;
            }
        }

        if ($bestScore < $threshold) {
            return [null, $bestScore];
        }

        return [$bestMeta, $bestScore];
    }
}
