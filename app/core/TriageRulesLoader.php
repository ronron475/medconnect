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
        foreach ([
            BASE_PATH . '/data/nlp/triage_rules_cds.csv',
            BASE_PATH . '/data/nlp/triage_rules.csv',
        ] as $path) {
            self::loadRulesFromCsv($path);
        }

        usort(self::$rules, static fn (array $a, array $b): int => strlen($b['hiligaynon_pattern']) <=> strlen($a['hiligaynon_pattern']));

        return self::$rules;
    }

    private static function loadRulesFromCsv(string $path): void
    {
        if (!is_readable($path)) {
            return;
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            return;
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
            $status = strtolower((string) ($data['status'] ?? 'active'));
            if ($status !== '' && $status !== 'active') {
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
                'source'             => basename($path),
            ];
        }
        fclose($handle);
    }

    /** @return array{triage_level:string,severity:string,reason:string,source:string,pattern:string}|null */
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
                    'reason'       => $rule['reason'] !== '' ? $rule['reason'] : 'Matched CDS rule: ' . $hil,
                    'source'       => (string) ($rule['source'] ?? 'triage_rules.csv'),
                    'pattern'      => $hil,
                ];
            }
            if ($eng !== '' && str_contains($hayEng, $eng)) {
                return [
                    'triage_level' => $rule['triage_level'],
                    'severity'     => $rule['severity'],
                    'reason'       => $rule['reason'] !== '' ? $rule['reason'] : 'Matched CDS rule: ' . $eng,
                    'source'       => (string) ($rule['source'] ?? 'triage_rules.csv'),
                    'pattern'      => $eng,
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
