<?php
/**
 * Normalize Hiligaynon/English chat input for NLP.
 */
final class FaqChatbotTextNormalizer
{
    public static function normalize(string $text): string
    {
        $t = mb_strtolower(trim($text), 'UTF-8');
        $t = preg_replace('/(.)\1{2,}/u', '$1$1', $t) ?? $t;
        $t = preg_replace('/[^\p{L}\p{N}\s\'-]/u', ' ', $t) ?? $t;
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;
        return trim($t);
    }

    public static function tokenize(string $text): array
    {
        $n = self::normalize($text);
        if ($n === '') {
            return [];
        }
        return array_values(array_filter(explode(' ', $n), static fn ($w) => $w !== ''));
    }
}
