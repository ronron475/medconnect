<?php
/**
 * Loads clinical reasoning rule templates from data/nlp/clinical_reasoning_rules.csv.
 * Used for explainable CDS output — does not change classification by itself.
 */

final class ClinicalReasoningRulesLoader
{
    /** @var list<array<string, string>>|null */
    private static ?array $rules = null;

    /** @return list<array<string, string>> */
    public static function rules(): array
    {
        if (self::$rules !== null) {
            return self::$rules;
        }

        $path = BASE_PATH . '/data/nlp/clinical_reasoning_rules.csv';
        self::$rules = [];
        if (!is_readable($path)) {
            return self::$rules;
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            return self::$rules;
        }

        $header = fgetcsv($handle);
        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine(
                array_map(static fn ($h) => strtolower(trim((string) $h)), $header ?: []),
                array_map(static fn ($v) => trim((string) $v), $row)
            ) ?: [];
            if (($data['status'] ?? 'active') !== 'active') {
                continue;
            }
            if (($data['when'] ?? '') === '') {
                continue;
            }
            self::$rules[] = $data;
        }
        fclose($handle);

        return self::$rules;
    }

    /**
     * Pick a reason template key based on triage context.
     *
     * @param list<array<string, mixed>> $redFlags
     * @param array<string, mixed> $factors
     */
    public static function resolveWhenKey(
        string $display,
        array $redFlags,
        array $factors,
        bool $needsReview,
        bool $vague
    ): string {
        if ($needsReview) {
            return 'low_confidence';
        }
        if ($redFlags !== []) {
            return 'red_flag_present';
        }
        if (!empty($factors['clinical_context_rule']) && ($factors['clinical_context_rule'] ?? '') !== 'CTX_NONE') {
            return 'symptom_combination';
        }
        if (!empty($factors['symptom_combination']) || !empty($factors['combination_classification'])) {
            return 'symptom_combination';
        }
        if ($vague) {
            return 'low_confidence';
        }

        return match (strtoupper($display)) {
            'EMERGENCY' => 'score_emergency',
            'URGENT' => 'score_urgent',
            default => 'score_non_urgent',
        };
    }

    /**
     * @param array<string, string|int> $vars
     */
    public static function templateFor(string $whenKey): string
    {
        foreach (self::rules() as $rule) {
            if (($rule['when'] ?? '') === $whenKey) {
                return (string) ($rule['reason_template'] ?? '');
            }
        }

        return '';
    }

    /**
     * @param array<string, string|int> $vars
     */
    public static function render(string $whenKey, array $vars = []): string
    {
        $template = self::templateFor($whenKey);
        if ($template === '') {
            return '';
        }
        $out = $template;
        foreach ($vars as $key => $value) {
            if (is_array($value)) {
                $value = implode(', ', array_map('strval', $value));
            }
            $out = str_replace('{' . $key . '}', (string) $value, $out);
        }

        return trim(preg_replace('/\s+/', ' ', $out) ?? $out);
    }
}
