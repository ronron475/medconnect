<?php
/**
 * Synonym expansion for FAQ / intent matching (MySQL + built-in).
 */
final class FaqChatbotSynonymEngine
{
    /** @var array<string, list<string>>|null */
    private static ?array $cache = null;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return list<string> expanded unique terms
     */
    public function expand(string $text, string $lang = 'en'): array
    {
        $norm = FaqChatbotTextNormalizer::normalize($text);
        $tokens = FaqChatbotTextNormalizer::tokenize($norm);
        $out = $tokens;
        $map = $this->loadMap($lang);
        foreach ($tokens as $tok) {
            foreach ($map[$tok] ?? [] as $syn) {
                $out[] = $syn;
            }
        }
        return array_values(array_unique($out));
    }

    public function expandToString(string $text, string $lang = 'en'): string
    {
        return implode(' ', $this->expand($text, $lang));
    }

    /** @return array<string, list<string>> */
    private function loadMap(string $lang): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        self::$cache = [
            'appointment' => ['book', 'schedule', 'consultation'],
            'login' => ['signin', 'sign in', 'password'],
            'register' => ['signup', 'sign up', 'account'],
            'doctor' => ['physician', 'provider', 'doktor'],
            'medicine' => ['medication', 'drug', 'prescription', 'bulong'],
            'fever' => ['hilanat', 'lagnat', 'temperature'],
            'cough' => ['ubo'],
            'pain' => ['masakit', 'sakit', 'gasakit'],
            'emergency' => ['911', 'urgent', 'critical'],
        ];

        try {
            $stmt = $this->pdo->query('SELECT term, synonym FROM synonyms WHERE lang IN (\'en\', \'hil\')');
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $term = FaqChatbotTextNormalizer::normalize((string) $row['term']);
                $syn = FaqChatbotTextNormalizer::normalize((string) $row['synonym']);
                if ($term === '' || $syn === '') {
                    continue;
                }
                self::$cache[$term] = array_values(array_unique([...(self::$cache[$term] ?? []), $syn]));
            }
        } catch (Throwable) {
            // table may not exist yet
        }

        return self::$cache;
    }
}
