<?php
/**
 * WHO Interagency Integrated Triage Tool (IITT) rules loader.
 *
 * Reusable clinical criteria (not per-complaint hardcoding). Maps to the three
 * MedConnect classifications: EMERGENCY (RED), URGENT (YELLOW), NON-URGENT (GREEN).
 * Does not diagnose disease — only supports triage-level selection.
 */

final class WhoIittTriageRulesLoader
{
    private const CSV_PATH = BASE_PATH . '/data/nlp/who_iitt_triage_rules.csv';

    /** @var list<array<string, mixed>>|null */
    private static ?array $rules = null;

    /** @return list<array<string, mixed>> */
    public static function rules(): array
    {
        if (self::$rules !== null) {
            return self::$rules;
        }

        self::$rules = [];
        if (!is_readable(self::CSV_PATH)) {
            return self::$rules;
        }

        $handle = fopen(self::CSV_PATH, 'r');
        if ($handle === false) {
            return self::$rules;
        }

        $header = fgetcsv($handle);
        $header = array_map(static fn ($h) => strtolower(trim((string) $h)), $header ?: []);
        while (($row = fgetcsv($handle)) !== false) {
            if ($row === [null] || $row === false) {
                continue;
            }
            $data = array_combine($header, array_map(static fn ($v) => trim((string) $v), $row));
            if (!is_array($data)) {
                continue;
            }
            $ruleId = (string) ($data['rule_id'] ?? '');
            $pattern = (string) ($data['clinical_pattern'] ?? '');
            $level = strtoupper(str_replace('_', '-', (string) ($data['triage_level'] ?? '')));
            if ($ruleId === '' || $pattern === '') {
                continue;
            }
            if ($level === 'NON URGENT') {
                $level = 'NON-URGENT';
            }
            if (!in_array($level, ['EMERGENCY', 'URGENT', 'NON-URGENT'], true)) {
                continue;
            }
            self::$rules[] = [
                'rule_id'           => $ruleId,
                'complaint_context' => (string) ($data['complaint_context'] ?? 'any'),
                'clinical_sign'     => (string) ($data['clinical_sign'] ?? ''),
                'red_flag'          => ((string) ($data['red_flag'] ?? '0')) === '1',
                'clinical_pattern'  => $pattern,
                'triage_level'      => $level,
                'priority'          => (int) ($data['priority'] ?? 999),
                'source'            => (string) ($data['source'] ?? 'WHO IITT'),
                'source_reference'  => (string) ($data['source_reference'] ?? ''),
            ];
        }
        fclose($handle);

        usort(
            self::$rules,
            static fn (array $a, array $b): int => ((int) $a['priority']) <=> ((int) $b['priority'])
        );

        return self::$rules;
    }

    /**
     * Evaluate WHO IITT criteria against clinical text.
     * Order: EMERGENCY (RED) first, then URGENT (YELLOW). No match → null (GREEN / defer).
     * Matches against raw + normalized text so Hiligaynon spelling normalization
     * (e.g. kasakit→gasakit) does not drop WHO criteria.
     *
     * @return array{
     *   triage_level:string,
     *   rule_id:string,
     *   clinical_sign:string,
     *   red_flag:bool,
     *   source:string,
     *   source_reference:string,
     *   matched_rules:list<array<string,mixed>>
     * }|null
     */
    public static function evaluate(string $original, string $english = ''): ?array
    {
        $hay = strtolower(trim($original . ' ' . $english));
        if (class_exists('ClinicalAnswerNormalizer')) {
            try {
                $prep = ClinicalAnswerNormalizer::prepare($hay);
                $extra = strtolower(trim((string) ($prep['corrected'] ?? '')));
                if ($extra !== '') {
                    $hay = trim($hay . ' ' . $extra);
                }
            } catch (Throwable) {
                // keep hay
            }
        }
        if (class_exists('HiligaynonTextNormalizer')) {
            $chunks = [strtolower(trim($original)), strtolower(trim($english))];
            $chunks[] = strtolower(trim((string) HiligaynonTextNormalizer::normalize($original)));
            if ($english !== '' && $english !== $original) {
                $chunks[] = strtolower(trim((string) HiligaynonTextNormalizer::normalize($english)));
            }
            $hay = trim(implode(' ', array_values(array_unique(array_filter(array_merge([$hay], $chunks))))));
        }
        if ($hay === '') {
            return null;
        }

        $emergencyHits = [];
        $urgentHits = [];
        foreach (self::rules() as $rule) {
            if (!self::patternMatches($hay, (string) $rule['clinical_pattern'])) {
                continue;
            }
            if (($rule['triage_level'] ?? '') === 'EMERGENCY' || !empty($rule['red_flag'])) {
                $emergencyHits[] = $rule;
            } elseif (($rule['triage_level'] ?? '') === 'URGENT') {
                $urgentHits[] = $rule;
            }
        }

        if ($emergencyHits !== []) {
            $best = $emergencyHits[0];

            return [
                'triage_level'     => 'EMERGENCY',
                'rule_id'          => (string) $best['rule_id'],
                'clinical_sign'    => (string) $best['clinical_sign'],
                'red_flag'         => true,
                'source'           => (string) $best['source'],
                'source_reference' => (string) $best['source_reference'],
                'matched_rules'    => $emergencyHits,
            ];
        }

        if ($urgentHits !== []) {
            $best = $urgentHits[0];

            return [
                'triage_level'     => 'URGENT',
                'rule_id'          => (string) $best['rule_id'],
                'clinical_sign'    => (string) $best['clinical_sign'],
                'red_flag'         => false,
                'source'           => (string) $best['source'],
                'source_reference' => (string) $best['source_reference'],
                'matched_rules'    => $urgentHits,
            ];
        }

        return null;
    }

    private static function patternMatches(string $hay, string $pattern): bool
    {
        $pattern = trim($pattern);
        if ($pattern === '') {
            return false;
        }

        if (str_starts_with(strtoupper($pattern), 'COMBO2:')) {
            $parts = explode(';;', substr($pattern, 7));
            $hits = 0;
            foreach ($parts as $part) {
                $part = trim($part);
                if ($part === '') {
                    continue;
                }
                if (@preg_match('/' . $part . '/u', $hay) === 1) {
                    $hits++;
                }
            }

            return $hits >= 2;
        }

        $ok = @preg_match('/' . $pattern . '/u', $hay);

        return $ok === 1;
    }

    /** Clear cached rows (tests / hot reload). */
    public static function resetCache(): void
    {
        self::$rules = null;
    }
}
