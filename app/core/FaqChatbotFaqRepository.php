<?php
/**
 * FAQ search: FULLTEXT when available, keyword LIKE, and PHP similarity scoring.
 */
final class FaqChatbotFaqRepository
{
    private ?FaqChatbotSynonymEngine $synonyms = null;

    public function __construct(private PDO $pdo)
    {
    }

    private function synonymEngine(): FaqChatbotSynonymEngine
    {
        return $this->synonyms ??= new FaqChatbotSynonymEngine($this->pdo);
    }

    /**
     * @return list<array{id: int, category: string, question: string, answer: string, score: float}>
     */
    public function search(string $query, int $limit = 5): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $limit = max(1, min(10, $limit));
        $norm = FaqEmotionEngine::normalizeText($query);
        $expanded = $this->synonymEngine()->expandToString($norm);
        $searchBlob = trim($norm . ' ' . $expanded);
        $tokens = array_values(array_filter(preg_split('/\s+/u', $searchBlob) ?: [], static fn ($t) => mb_strlen($t) >= 3));

        $candidates = $this->fulltextSearch($searchBlob, $limit * 3);
        if ($candidates === []) {
            $candidates = $this->likeSearch($tokens, $limit * 3);
        }
        if ($candidates === []) {
            $candidates = $this->fetchActive($limit * 3);
        }

        $scored = [];
        foreach ($candidates as $row) {
            $score = $this->scoreRow($searchBlob, $tokens, $row);
            if ($score <= 0) {
                continue;
            }
            $row['score'] = $score;
            $scored[] = $row;
        }

        usort($scored, static fn ($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($scored, 0, $limit);
    }

    /**
     * @return list<array{id: int, category: string, question: string, answer: string, keywords: string}>
     */
    private function fulltextSearch(string $norm, int $limit): array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT id, category, question, answer, keywords
                 FROM faq
                 WHERE is_active = 1
                   AND MATCH(question, answer, keywords) AGAINST (:q IN NATURAL LANGUAGE MODE)
                 LIMIT ' . $limit
            );
            $stmt->bindValue(':q', $norm);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param list<string> $tokens
     * @return list<array<string, mixed>>
     */
    private function likeSearch(array $tokens, int $limit): array
    {
        if ($tokens === []) {
            return [];
        }
        $limit = max(1, min(30, $limit));
        $wheres = [];
        $params = [];
        foreach (array_slice($tokens, 0, 6) as $i => $tok) {
            $key = ':t' . $i;
            $wheres[] = "(question LIKE $key OR answer LIKE $key OR keywords LIKE $key)";
            $params[$key] = '%' . $tok . '%';
        }
        $sql = 'SELECT id, category, question, answer, keywords FROM faq WHERE is_active = 1 AND (' . implode(' OR ', $wheres) . ') ORDER BY sort_order ASC LIMIT ' . $limit;
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            $wheres = [];
            $params = [];
            foreach (array_slice($tokens, 0, 6) as $i => $tok) {
                $key = ':t' . $i;
                $wheres[] = "(question LIKE $key OR answer LIKE $key)";
                $params[$key] = '%' . $tok . '%';
            }
            try {
                $sql = 'SELECT id, category, question, answer FROM faq WHERE is_active = 1 AND (' . implode(' OR ', $wheres) . ') ORDER BY id ASC LIMIT ' . $limit;
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                foreach ($rows as &$row) {
                    $row['keywords'] = $row['keywords'] ?? '';
                }
                unset($row);
                return $rows;
            } catch (Throwable) {
                return [];
            }
        }
    }

    /** @return list<array<string, mixed>> */
    private function fetchActive(int $limit): array
    {
        $limit = max(1, min(30, $limit));
        try {
            $stmt = $this->pdo->query(
                'SELECT id, category, question, answer, keywords FROM faq WHERE is_active = 1 ORDER BY sort_order ASC LIMIT ' . $limit
            );
            return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param list<string> $tokens
     * @param array<string, mixed> $row
     */
    private function scoreRow(string $norm, array $tokens, array $row): float
    {
        $hay = FaqEmotionEngine::normalizeText(
            ($row['question'] ?? '') . ' ' . ($row['keywords'] ?? '') . ' ' . mb_substr((string) ($row['answer'] ?? ''), 0, 200)
        );
        $score = 0.0;

        similar_text($norm, FaqEmotionEngine::normalizeText((string) ($row['question'] ?? '')), $pct);
        $score += ($pct / 100) * 2.5;

        foreach ($tokens as $tok) {
            if (str_contains($hay, $tok)) {
                $score += 0.45;
            }
            if (mb_strlen($tok) >= 4) {
                foreach (preg_split('/\s+/u', $hay) ?: [] as $word) {
                    if ($word === '' || mb_strlen($word) < 4) {
                        continue;
                    }
                    $lev = levenshtein(mb_substr($tok, 0, 255), mb_substr($word, 0, 255));
                    if ($lev <= 2) {
                        $score += 0.35 - ($lev * 0.1);
                    }
                }
            }
        }

        if (str_contains($hay, $norm) || str_contains($norm, FaqEmotionEngine::normalizeText((string) ($row['question'] ?? '')))) {
            $score += 1.2;
        }

        return round($score, 3);
    }

  /**
   * @return list<array{id: int, question: string, category: string}>
   */
    public function suggestionsForCategory(?string $category, int $limit = 3): array
    {
        $limit = max(1, min(6, $limit));
        try {
            if ($category) {
                $stmt = $this->pdo->prepare(
                    'SELECT id, question, category FROM faq WHERE is_active = 1 AND category = :cat ORDER BY sort_order ASC LIMIT ' . $limit
                );
                $stmt->execute([':cat' => $category]);
                return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }

            $stmt = $this->pdo->query(
                'SELECT id, question, category FROM faq WHERE is_active = 1 ORDER BY sort_order ASC LIMIT ' . $limit
            );
            return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        } catch (Throwable) {
            return [];
        }
    }
}
