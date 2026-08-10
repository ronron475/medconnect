<?php
/**
 * Loads and searches the unified chatbot scenario index (20k+ phrase → intent variations).
 * Architecture: phrase variation → shared intent → kb_key → response template.
 */
final class FaqChatbotScenarioIndex
{
    private const INDEX_PATH = 'data/nlp/chatbot_scenario_index.json';
    private const MIN_SCORE = 2.0;

    /** @var array{count: int, scenarios: list<array<string, mixed>>}|null */
    private static ?array $data = null;

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $exactIndex = null;

    /** @var array<string, list<int>>|null */
    private static ?array $tokenIndex = null;

    /**
     * @param array{intent?: string, context_boost?: string} $ctx
     * @return array{phrase: string, intent: string, category: string, kb_key: string, score: float, language: string, emotion: ?string}|null
     */
    public static function match(string $rawText, string $nlpText, array $ctx = []): ?array
    {
        self::ensureLoaded();
        if (self::$data === null || self::$exactIndex === null) {
            return null;
        }

        $hay = FaqEmotionEngine::normalizeText(trim($rawText . ' ' . $nlpText . ' ' . (string) ($ctx['context_boost'] ?? '')));
        if ($hay === '') {
            return null;
        }

        if (isset(self::$exactIndex[$hay])) {
            $row = self::$exactIndex[$hay];
            return self::formatHit($row, 4.5);
        }

        $tokens = self::tokens($hay);
        if ($tokens === []) {
            return null;
        }

        /** @var array<int, float> */
        $candidates = [];
        foreach ($tokens as $tok) {
            if (mb_strlen($tok) < 3) {
                continue;
            }
            foreach (self::$tokenIndex[$tok] ?? [] as $idx) {
                $candidates[$idx] = ($candidates[$idx] ?? 0) + 1.0;
            }
            if (count($candidates) > 400) {
                arsort($candidates);
                $candidates = array_slice($candidates, 0, 250, true);
            }
        }

        arsort($candidates);
        $candidates = array_slice($candidates, 0, 80, true);

        $best = null;
        $bestScore = 0.0;
        $intentHint = (string) ($ctx['intent'] ?? '');

        foreach ($candidates as $idx => $tokenScore) {
            $row = self::$data['scenarios'][$idx] ?? null;
            if (!is_array($row)) {
                continue;
            }
            $phrase = FaqEmotionEngine::normalizeText((string) ($row['phrase'] ?? ''));
            if ($phrase === '') {
                continue;
            }

            $score = $tokenScore;
            if (mb_strpos($hay, $phrase) !== false || mb_strpos($phrase, $hay) !== false) {
                $score += 2.8;
            } else {
                $overlap = count(array_intersect($tokens, self::tokens($phrase)));
                $score += $overlap * 0.65;
                $fuzzy = self::fuzzyContains($hay, $phrase);
                if ($fuzzy >= 0.82) {
                    $score += 1.4 * $fuzzy;
                }
            }

            $score += ((int) ($row['priority'] ?? 5)) * 0.08;
            if ($intentHint !== '' && ($row['intent'] ?? '') === $intentHint) {
                $score += 0.55;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $row;
            }
        }

        if ($best === null || $bestScore < self::MIN_SCORE) {
            return null;
        }

        return self::formatHit($best, $bestScore);
    }

    public static function count(): int
    {
        self::ensureLoaded();
        return (int) (self::$data['count'] ?? 0);
    }

    public static function isAvailable(): bool
    {
        return is_readable(BASE_PATH . '/' . self::INDEX_PATH);
    }

    private static function ensureLoaded(): void
    {
        if (self::$data !== null) {
            return;
        }
        $path = BASE_PATH . '/' . self::INDEX_PATH;
        if (!is_readable($path)) {
            self::$data = ['count' => 0, 'scenarios' => []];
            self::$exactIndex = [];
            self::$tokenIndex = [];
            return;
        }

        $raw = json_decode((string) file_get_contents($path), true);
        if (!is_array($raw) || !isset($raw['scenarios']) || !is_array($raw['scenarios'])) {
            self::$data = ['count' => 0, 'scenarios' => []];
            self::$exactIndex = [];
            self::$tokenIndex = [];
            return;
        }

        self::$data = $raw;
        self::$exactIndex = [];
        self::$tokenIndex = [];

        foreach ($raw['scenarios'] as $idx => $row) {
            if (!is_array($row)) {
                continue;
            }
            $norm = FaqEmotionEngine::normalizeText((string) ($row['phrase'] ?? ''));
            if ($norm !== '') {
                self::$exactIndex[$norm] = $row;
            }
            $tokenSet = [];
            foreach ((array) ($row['keywords'] ?? []) as $kw) {
                $kw = FaqEmotionEngine::normalizeText((string) $kw);
                if (mb_strlen($kw) >= 3) {
                    $tokenSet[$kw] = true;
                }
            }
            foreach (self::tokens($norm) as $tok) {
                $tokenSet[$tok] = true;
            }
            foreach (array_keys($tokenSet) as $tok) {
                self::$tokenIndex[$tok][] = (int) $idx;
            }
        }

        // Deduplicate index lists (keeps memory + match time bounded)
        foreach (self::$tokenIndex as $tok => $ids) {
            self::$tokenIndex[$tok] = array_values(array_unique($ids));
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array{phrase: string, intent: string, category: string, kb_key: string, score: float, language: string, emotion: ?string}
     */
    private static function formatHit(array $row, float $score): array
    {
        return [
            'phrase'   => (string) ($row['phrase'] ?? ''),
            'intent'   => (string) ($row['intent'] ?? 'faq'),
            'category' => (string) ($row['category'] ?? 'general'),
            'kb_key'   => (string) ($row['kb_key'] ?? ''),
            'score'    => round($score, 3),
            'language' => (string) ($row['language'] ?? 'en'),
            'emotion'  => isset($row['emotion']) ? (string) $row['emotion'] : null,
        ];
    }

    /** @return list<string> */
    private static function tokens(string $text): array
    {
        $text = preg_replace('/[^a-z0-9\s]/u', ' ', $text) ?? '';
        $parts = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_unique($parts));
    }

    private static function fuzzyContains(string $hay, string $needle): float
    {
        if ($needle === '' || $hay === '') {
            return 0.0;
        }
        if (mb_strpos($hay, $needle) !== false) {
            return 1.0;
        }
        $words = self::tokens($needle);
        if ($words === []) {
            return 0.0;
        }
        $hits = 0;
        foreach ($words as $w) {
            if (mb_strlen($w) < 4) {
                if (mb_strpos($hay, $w) !== false) {
                    $hits++;
                }
                continue;
            }
            foreach (self::tokens($hay) as $hw) {
                if ($hw === $w) {
                    $hits++;
                    break;
                }
                similar_text($w, $hw, $pct);
                if ($pct >= 86.0) {
                    $hits++;
                    break;
                }
            }
        }
        return $hits / count($words);
    }
}
