<?php
/**
 * Emergency red-flag patterns — auto-elevate triage to EMERGENCY.
 */

final class EmergencyFlagsLoader
{
    /** @var list<array<string, string>>|null */
    private static ?array $flags = null;

    /** @return list<array<string, string>> */
    public static function flags(): array
    {
        if (self::$flags !== null) {
            return self::$flags;
        }

        self::$flags = [];
        $path = BASE_PATH . '/data/nlp/emergency_flags.csv';
        if (!is_readable($path)) {
            return self::$flags;
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            return self::$flags;
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
            self::$flags[] = [
                'flag_id'             => (string) ($data['flag_id'] ?? ''),
                'flag_name'           => (string) ($data['flag_name'] ?? ''),
                'hiligaynon_pattern'  => $hil,
                'english_pattern'     => $eng,
                'body_system'         => (string) ($data['body_system'] ?? ''),
                'category'            => (string) ($data['category'] ?? ''),
                'auto_triage'         => strtoupper((string) ($data['auto_triage'] ?? 'EMERGENCY')),
                'severity'            => (string) ($data['severity'] ?? 'critical'),
                'clinical_rationale'  => (string) ($data['clinical_rationale'] ?? ''),
            ];
        }
        fclose($handle);

        usort(self::$flags, static fn (array $a, array $b): int => strlen($b['hiligaynon_pattern']) <=> strlen($a['hiligaynon_pattern']));

        return self::$flags;
    }

    /**
     * @return list<array<string, string>>
     */
    public static function scanEmergencyFlags(string $original, string $english = ''): array
    {
        $hayHil = strtolower($original);
        $hayEng = strtolower($english);
        $matched = [];
        $seen = [];
        foreach (self::flags() as $flag) {
            $fid = $flag['flag_id'] !== '' ? $flag['flag_id'] : $flag['flag_name'];
            if (isset($seen[$fid])) {
                continue;
            }
            if ($flag['hiligaynon_pattern'] !== '' && str_contains($hayHil, $flag['hiligaynon_pattern'])) {
                $matched[] = array_merge($flag, ['matched_on' => 'hiligaynon']);
                $seen[$fid] = true;
            } elseif ($flag['english_pattern'] !== '' && str_contains($hayEng, $flag['english_pattern'])) {
                $matched[] = array_merge($flag, ['matched_on' => 'english']);
                $seen[$fid] = true;
            }
        }

        return $matched;
    }
}
