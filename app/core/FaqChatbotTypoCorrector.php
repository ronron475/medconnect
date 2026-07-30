<?php
/**
 * Fuzzy typo correction against dictionary terms (Levenshtein + similar_text).
 */
final class FaqChatbotTypoCorrector
{
    public function __construct(private FaqChatbotDictionaryRepository $dict)
    {
    }

    public function correctToken(string $token): string
    {
        $t = FaqChatbotTextNormalizer::normalize($token);
        if ($t === '' || mb_strlen($t) < 3) {
            return $token;
        }

        $map = $this->dict->tokens();
        if (isset($map[$t])) {
            return $t;
        }

        $best = $t;
        $bestScore = 0.0;
        foreach (array_keys($map) as $candidate) {
            if (abs(mb_strlen($candidate) - mb_strlen($t)) > 2) {
                continue;
            }
            $lev = levenshtein($t, $candidate);
            if ($lev > 2) {
                continue;
            }
            similar_text($t, $candidate, $pct);
            $score = $pct - ($lev * 8);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $candidate;
            }
        }

        return $bestScore >= 72 ? $best : $t;
    }

    public function correctText(string $text): string
    {
        $parts = FaqChatbotTextNormalizer::tokenize($text);
        if ($parts === []) {
            return $text;
        }
        $fixed = array_map(fn ($w) => $this->correctToken($w), $parts);
        return implode(' ', $fixed);
    }
}
