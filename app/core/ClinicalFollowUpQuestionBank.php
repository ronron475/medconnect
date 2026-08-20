<?php
/**
 * Language-aware follow-up question templates for preliminary clinical triage.
 * Clinical rules remain in ClinicalTriageEngine and existing datasets.
 */

final class ClinicalFollowUpQuestionBank
{
    private const PATH = BASE_PATH . '/data/nlp/clinical_followup_questions.json';

    /** @var list<array<string, mixed>>|null */
    private static ?array $questions = null;

    /** @return list<array<string, mixed>> */
    public static function questions(): array
    {
        if (self::$questions !== null) {
            return self::$questions;
        }
        self::$questions = [];
        if (!is_readable(self::PATH)) {
            return self::$questions;
        }
        $decoded = json_decode((string) file_get_contents(self::PATH), true);
        $rows = is_array($decoded['questions'] ?? null) ? $decoded['questions'] : [];
        foreach ($rows as $row) {
            if (!is_array($row) || trim((string) ($row['question_id'] ?? '')) === '') {
                continue;
            }
            self::$questions[] = $row;
        }
        usort(
            self::$questions,
            static fn (array $a, array $b): int => ((int) ($a['priority'] ?? 99)) <=> ((int) ($b['priority'] ?? 99))
        );

        return self::$questions;
    }

    /** @return array<string, mixed>|null */
    public static function byId(string $questionId): ?array
    {
        $id = strtoupper(trim($questionId));
        foreach (self::questions() as $row) {
            if (strtoupper((string) ($row['question_id'] ?? '')) === $id) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $question
     */
    public static function textForLanguage(array $question, string $language): string
    {
        $lang = ClinicalInterviewEngine::normalizeQuestionLanguage($language);
        $key = match ($lang) {
            'tagalog' => 'tagalog',
            'english' => 'english',
            default => 'hiligaynon',
        };
        $text = trim((string) ($question[$key] ?? ''));
        if ($text !== '') {
            return $text;
        }

        return trim((string) ($question['english'] ?? $question['hiligaynon'] ?? $question['tagalog'] ?? ''));
    }
}
