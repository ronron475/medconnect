<?php
/**
 * Normalize Hiligaynon/English chat input for NLP.
 *
 * Always case-folds so "How To Book", "HOW TO BOOK", and "how to book" match identically.
 * Use forMatch() for comparison/matching; never overwrite display or stored user text.
 */
final class FaqChatbotTextNormalizer
{
    /**
     * Full normalization for chatbot matching (case-fold + cleanup).
     */
    public static function normalize(string $text): string
    {
        $t = mb_strtolower(trim($text), 'UTF-8');
        $t = preg_replace('/(.)\1{2,}/u', '$1$1', $t) ?? $t;
        $t = preg_replace('/[^\p{L}\p{N}\s\'-]/u', ' ', $t) ?? $t;
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;
        return trim($t);
    }

    /**
     * Central match key for FAQ / intent / synonym / scenario comparison.
     * Use for dataset, fuzzy, synonym, and routing matching — never for display or DB storage.
     */
    public static function forMatch(string $text): string
    {
        return self::normalize($text);
    }

    /**
     * Lightweight case + whitespace fold (no punctuation rewrite).
     * Prefer forMatch() for NLP; use caseFold() for identity / dedup only.
     */
    public static function caseFold(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        if ($text === '') {
            return '';
        }

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /**
     * Case-insensitive (and NLP-normalized) equality for chatbot comparison.
     */
    public static function equals(string $a, string $b): bool
    {
        $left = self::forMatch($a);
        $right = self::forMatch($b);

        return $left !== '' && $left === $right;
    }

    public static function tokenize(string $text): array
    {
        $n = self::forMatch($text);
        if ($n === '') {
            return [];
        }
        return array_values(array_filter(explode(' ', $n), static fn ($w) => $w !== ''));
    }
}
