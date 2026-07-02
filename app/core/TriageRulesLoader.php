<?php
/**
 * Clinical triage rules from triage_rules.csv
 */

final class TriageRulesLoader
{
    /** @var list<array<string, string>>|null */
    private static ?array $rules = null;

    /** @return list<array<string, string>> */
    public static function rules(): array
    {
        if (self::$rules !== null) {
            return self::$rules;
        }

        self::$rules = [];
        $path = BASE_PATH . '/data/nlp/triage_rules.csv';
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
            $hil = strtolower((string) ($data['hiligaynon_pattern'] ?? ''));
            $eng = strtolower((string) ($data['english_pattern'] ?? ''));
            if ($hil === '' && $eng === '') {
                continue;
            }
            $tri = strtolower((string) ($data['triage_level'] ?? 'routine'));
            self::$rules[] = [
                'hiligaynon_pattern' => $hil,
                'english_pattern'    => $eng,
                'triage_level'       => self::mapLevel($tri),
                'severity'           => (string) ($data['severity'] ?? 'moderate'),
                'medical_category'   => (string) ($data['medical_category'] ?? ''),
                'reason'             => (string) ($data['reason'] ?? ''),
            ];
        }
        fclose($handle);

        usort(self::$rules, static fn (array $a, array $b): int => strlen($b['hiligaynon_pattern']) <=> strlen($a['hiligaynon_pattern']));

        return self::$rules;
    }

    /** @return array{triage_level:string,severity:string,reason:string,source:string}|null */
    public static function matchTriage(string $original, string $english = ''): ?array
    {
        $hayHil = strtolower($original);
        $hayEng = strtolower($english);
        foreach (self::rules() as $rule) {
            $hil = $rule['hiligaynon_pattern'];
            $eng = $rule['english_pattern'];
            if ($hil !== '' && str_contains($hayHil, $hil)) {
                return [
                    'triage_level' => $rule['triage_level'],
                    'severity'     => $rule['severity'],
                    'reason'       => $rule['reason'] !== '' ? $rule['reason'] : 'Matched triage rule: ' . $hil,
                    'source'       => 'triage_rules.csv',
                ];
            }
            if ($eng !== '' && str_contains($hayEng, $eng)) {
                return [
                    'triage_level' => $rule['triage_level'],
                    'severity'     => $rule['severity'],
                    'reason'       => $rule['reason'] !== '' ? $rule['reason'] : 'Matched triage rule: ' . $eng,
                    'source'       => 'triage_rules.csv',
                ];
            }
        }

        return null;
    }

    private static function mapLevel(string $tri): string
    {
        return match ($tri) {
            'non_urgent', 'routine' => 'LOW',
            'urgent'                => 'HIGH',
            'emergency', 'critical' => 'EMERGENCY',
            default                 => strtoupper($tri),
        };
    }
}
