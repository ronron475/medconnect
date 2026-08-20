<?php
/**
 * Dataset-driven complaint and follow-up family resolution for the universal interview gate.
 * Signals live in data/nlp/clinical_interview_families.json — not hard-coded per example complaint.
 */

final class ClinicalInterviewContextResolver
{
    private const PATH = BASE_PATH . '/data/nlp/clinical_interview_families.json';

    /** @var array<string, mixed>|null */
    private static ?array $config = null;

    /**
     * @param array<string, mixed> $assessment
     * @param array<string, mixed> $facts
     * @return list<array{id:string,name:string,family_key:string}>
     */
    public static function deriveComplaints(array $assessment, string $transcript, array $facts): array
    {
        $config = self::config();
        $hay = self::buildHaystack($assessment, $transcript);
        $found = [];

        foreach ((array) ($config['complaints'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (!self::matchesComplaint($row, $hay, $assessment, $facts, $transcript)) {
                continue;
            }
            $found[] = [
                'id'          => strtoupper((string) ($row['complaint_id'] ?? '')),
                'name'        => (string) ($row['label'] ?? ''),
                'family_key'  => strtolower((string) ($row['family_key'] ?? '')),
            ];
        }

        $hasPainToken = (bool) preg_match('/\b(sakit|masakit|pain|hurts|hapdi|discomfort)\b/u', mb_strtolower($transcript));
        $hasLocation = ($facts['body_locations'] ?? []) !== []
            || (bool) preg_match(
                '/\b(ulo|head|dughan|dibdib|chest|tiyan|ilong|nose|kamot|hand|tiil|leg|likod|back|liog|neck)\b/u',
                mb_strtolower($transcript)
            );

        foreach ((array) ($config['fallback_complaints'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (!self::matchesFallback($row, $found, $transcript, $hasPainToken, $hasLocation)) {
                continue;
            }
            $id = strtoupper((string) ($row['complaint_id'] ?? ''));
            if (self::hasComplaintId($found, $id)) {
                continue;
            }
            $found[] = [
                'id'         => $id,
                'name'       => (string) ($row['label'] ?? ''),
                'family_key' => strtolower((string) ($row['family_key'] ?? '')),
            ];
        }

        return $found;
    }

    /**
     * @param list<array{id?:string,name?:string,family_key?:string}> $complaints
     * @param array<string, mixed> $facts
     * @return list<string>
     */
    public static function deriveFamilies(array $complaints, string $transcript, array $facts): array
    {
        $keys = [];
        foreach ($complaints as $row) {
            $family = strtolower((string) ($row['family_key'] ?? ''));
            if ($family === '') {
                $family = strtolower(str_replace(' ', '_', (string) ($row['id'] ?? '')));
            }
            if ($family !== '') {
                $keys[] = $family;
            }
        }

        foreach ((array) (self::config()['derived_families'] ?? []) as $derived) {
            if (!is_array($derived)) {
                continue;
            }
            $key = strtolower((string) ($derived['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $whenAny = array_map('strtolower', (array) ($derived['add_when_family_any'] ?? []));
            if ($whenAny !== [] && array_intersect($whenAny, $keys) !== []) {
                $keys[] = $key;
            }
            if (!empty($derived['add_when_no_body_location'])
                && ($facts['body_locations'] ?? []) === []
                && array_intersect($whenAny, $keys) !== []
            ) {
                $keys[] = $key;
            }
            if (!empty($derived['add_when_vague_or_unlocated_pain'])
                && (ClinicalFeatureExtractors::isVagueComplaint($transcript)
                    || (in_array('pain_unspecified', $keys, true) && ($facts['body_locations'] ?? []) === []))
            ) {
                $keys[] = $key;
            }
        }

        if ($keys === []) {
            $keys[] = 'pain_unspecified';
        }

        if (($facts['body_locations'] ?? []) === []
            && (ClinicalFeatureExtractors::isVagueComplaint($transcript) || in_array('pain_unspecified', $keys, true))
        ) {
            $keys[] = 'pain_no_location';
        }

        return array_values(array_unique($keys));
    }

    public static function lowPriorityThreshold(): int
    {
        return max(1, (int) (self::config()['low_priority_threshold'] ?? 40));
    }

    /**
     * @param array<string, mixed> $assessment
     */
    private static function buildHaystack(array $assessment, string $transcript): string
    {
        $parts = [mb_strtolower($transcript)];
        foreach ((array) ($assessment['detected_symptoms'] ?? []) as $item) {
            if (is_string($item) && trim($item) !== '') {
                $parts[] = mb_strtolower(trim($item));
            }
        }
        $triage = is_array($assessment['triage'] ?? null) ? $assessment['triage'] : [];
        foreach ((array) ($triage['detected_body_parts'] ?? []) as $part) {
            if (is_string($part) && trim($part) !== '') {
                $parts[] = mb_strtolower(trim($part));
            }
        }
        foreach ((array) ($triage['kb_matched_symptoms'] ?? []) as $sym) {
            if (!is_array($sym)) {
                continue;
            }
            foreach (['symptom_name', 'matched_term', 'id'] as $field) {
                $v = trim((string) ($sym[$field] ?? ''));
                if ($v !== '') {
                    $parts[] = mb_strtolower($v);
                }
            }
        }

        return implode(' ', $parts);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $assessment
     * @param array<string, mixed> $facts
     */
    private static function matchesComplaint(array $row, string $hay, array $assessment, array $facts, string $transcript): bool
    {
        foreach ((array) ($row['text_regex_exclude'] ?? []) as $pattern) {
            if (is_string($pattern) && $pattern !== '' && preg_match('/' . $pattern . '/iu', $hay)) {
                return false;
            }
        }

        foreach ((array) ($row['text_regex'] ?? []) as $pattern) {
            if (is_string($pattern) && $pattern !== '' && preg_match('/' . $pattern . '/iu', $hay)) {
                return true;
            }
        }

        foreach ((array) ($row['symptom_name_contains'] ?? []) as $needle) {
            $needle = mb_strtolower(trim((string) $needle));
            if ($needle !== '' && str_contains($hay, $needle)) {
                return true;
            }
        }

        $triage = is_array($assessment['triage'] ?? null) ? $assessment['triage'] : [];
        $symptomIds = [];
        foreach ((array) ($triage['kb_matched_symptoms'] ?? []) as $sym) {
            if (is_array($sym)) {
                $symptomIds[] = mb_strtolower((string) ($sym['id'] ?? ''));
            }
        }
        foreach ((array) ($row['symptom_ids'] ?? []) as $sid) {
            $sid = mb_strtolower(trim((string) $sid));
            if ($sid !== '' && in_array($sid, $symptomIds, true)) {
                return true;
            }
        }

        foreach ((array) ($row['body_parts'] ?? []) as $part) {
            $part = mb_strtolower(trim((string) $part));
            if ($part === '') {
                continue;
            }
            foreach ((array) ($facts['body_locations'] ?? []) as $loc) {
                if ($part === mb_strtolower((string) $loc) || str_contains(mb_strtolower((string) $loc), $part)) {
                    return true;
                }
            }
            foreach ((array) ($triage['detected_body_parts'] ?? []) as $loc) {
                if ($part === mb_strtolower((string) $loc) || str_contains(mb_strtolower((string) $loc), $part)) {
                    return true;
                }
            }
        }

        if (!empty($row['requires_nose_pain_context'])
            && preg_match('/sakit|masakit|pain|hurts|ilong|nose/u', $transcript)
        ) {
            return preg_match('/' . implode('|', (array) ($row['text_regex'] ?? ['\\bilong\\b'])) . '/iu', $hay) === 1;
        }

        return false;
    }

    /**
     * @param list<array{id?:string}> $found
     * @param list<array{id?:string}> $found
     */
    private static function matchesFallback(array $row, array $found, string $transcript, bool $hasPainToken, bool $hasLocation): bool
    {
        if (!empty($row['requires_no_matched_complaint']) && $found !== []) {
            return false;
        }
        if (!empty($row['requires_pain_token']) && !$hasPainToken) {
            return false;
        }
        if (!empty($row['requires_no_pain_token']) && $hasPainToken) {
            return false;
        }
        if (!empty($row['requires_body_location']) && !$hasLocation) {
            return false;
        }
        if (!empty($row['requires_no_body_location']) && $hasLocation) {
            return false;
        }
        if (!empty($row['requires_vague_complaint']) && !ClinicalFeatureExtractors::isVagueComplaint($transcript)) {
            return false;
        }

        return true;
    }

    /**
     * @param list<array{id?:string}> $found
     */
    private static function hasComplaintId(array $found, string $id): bool
    {
        foreach ($found as $row) {
            if (strtoupper((string) ($row['id'] ?? '')) === $id) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private static function config(): array
    {
        if (self::$config !== null) {
            return self::$config;
        }
        self::$config = [];
        if (!is_readable(self::PATH)) {
            return self::$config;
        }
        $decoded = json_decode((string) file_get_contents(self::PATH), true);
        self::$config = is_array($decoded) ? $decoded : [];

        return self::$config;
    }
}
