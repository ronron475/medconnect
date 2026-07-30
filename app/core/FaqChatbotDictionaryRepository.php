<?php
/**
 * translation_dictionary + medical_terms persistence and cache.
 */
final class FaqChatbotDictionaryRepository
{
    /** @var list<array{source_text: string, target_text: string, category: string, is_phrase: int, priority: int}>|null */
    private static ?array $phraseCache = null;

    /** @var array<string, string>|null */
    private static ?array $tokenCache = null;

    public function __construct(private PDO $pdo)
    {
    }

    public function ensureSeeded(): void
    {
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM translation_dictionary')->fetchColumn();
        if ($count > 0) {
            return;
        }
        $this->importSeed();
    }

    public function importSeed(): int
    {
        $jsonPath = BASE_PATH . '/data/nlp/faq_chatbot_translation_dictionary.json';
        $entries = FaqChatbotDictionarySeed::entries();
        if (is_readable($jsonPath)) {
            $json = json_decode((string) file_get_contents($jsonPath), true);
            if (is_array($json)) {
                foreach ($json as $row) {
                    if (!is_array($row) || empty($row['source']) || empty($row['target'])) {
                        continue;
                    }
                    $entries[] = [
                        'source'   => (string) $row['source'],
                        'target'   => (string) $row['target'],
                        'category' => (string) ($row['category'] ?? 'general'),
                        'phrase'   => (bool) ($row['phrase'] ?? false),
                        'priority' => (int) ($row['priority'] ?? 0),
                    ];
                }
            }
        }

        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO translation_dictionary
             (source_lang, source_text, target_lang, target_text, category, is_phrase, priority)
             VALUES (\'hil\', :src, \'en\', :tgt, :cat, :phrase, :pri)'
        );

        $n = 0;
        foreach ($entries as $e) {
            $src = FaqChatbotTextNormalizer::normalize((string) $e['source']);
            if ($src === '') {
                continue;
            }
            $stmt->execute([
                ':src'    => $src,
                ':tgt'    => trim((string) $e['target']),
                ':cat'    => (string) ($e['category'] ?? 'general'),
                ':phrase' => !empty($e['phrase']) ? 1 : 0,
                ':pri'    => (int) ($e['priority'] ?? 0),
            ]);
            if ($stmt->rowCount() > 0) {
                $n++;
            }
            if (($e['category'] ?? '') === 'symptom' || ($e['category'] ?? '') === 'body') {
                $this->upsertMedicalTerm($src, (string) $e['target']);
            }
        }

        self::$phraseCache = null;
        self::$tokenCache = null;
        return $n;
    }

    private function upsertMedicalTerm(string $hil, string $en): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO medical_terms (term_hil, term_en) VALUES (:h, :e)'
        );
        $stmt->execute([':h' => $hil, ':e' => $en]);
    }

    public function loadCaches(): void
    {
        if (self::$phraseCache !== null) {
            return;
        }
        $stmt = $this->pdo->query(
            'SELECT source_text, target_text, category, is_phrase, priority
             FROM translation_dictionary WHERE is_active = 1 AND source_lang = \'hil\'
             ORDER BY is_phrase DESC, priority DESC, CHAR_LENGTH(source_text) DESC'
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        self::$phraseCache = $rows;
        self::$tokenCache = [];
        foreach ($rows as $row) {
            if ((int) ($row['is_phrase'] ?? 0) === 1) {
                continue;
            }
            $k = (string) $row['source_text'];
            self::$tokenCache[$k] = (string) $row['target_text'];
        }
    }

    /** @return list<array{source_text: string, target_text: string, category: string, is_phrase: int, priority: int}> */
    public function phrases(): array
    {
        $this->loadCaches();
        return self::$phraseCache ?? [];
    }

    /** @return array<string, string> */
    public function tokens(): array
    {
        $this->loadCaches();
        return self::$tokenCache ?? [];
    }

    /** @return list<string> */
    public function allSourceTerms(): array
    {
        $this->loadCaches();
        $keys = [];
        foreach (self::$phraseCache ?? [] as $row) {
            $keys[] = (string) $row['source_text'];
        }
        return array_values(array_unique($keys));
    }
}
