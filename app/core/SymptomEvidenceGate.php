<?php
/**
 * Ensures every detected symptom is traceable to the current chief complaint only.
 * Prevents inferred, unrelated, or carry-over symptoms from entering triage output.
 */

final class SymptomEvidenceGate
{
    /** @var list<string> */
    private const GENERIC_TOKENS = [
        'pain', 'ache', 'fever', 'cough', 'bleeding', 'weakness', 'fatigue', 'rash',
        'swelling', 'symptom', 'symptoms', 'severe', 'acute', 'chronic', 'mild',
    ];

    /** @var array<string, string> symptom id/name fragment → required body/context pattern */
    private const BODY_REQUIREMENTS = [
        'chest_pain' => '/\b(chest|dughan|dibdib|heart)\b/u',
        'chest pain' => '/\b(chest|dughan|dibdib|heart)\b/u',
        'eye_pain' => '/\b(eye|mata|ocular)\b/u',
        'eye pain' => '/\b(eye|mata|ocular)\b/u',
        'headache' => '/\b(head|ulo)\b/u',
        'abdominal_pain' => '/\b(abdomen|abdominal|stomach|belly|tiyan)\b/u',
        'abdominal pain' => '/\b(abdomen|abdominal|stomach|belly|tiyan)\b/u',
        'back_pain' => '/\b(back|likod)\b/u',
        'difficulty_breathing' => '/\b(breath|breathing|ginhawa|huminga|kaginhawa)\b/u',
        'shortness of breath' => '/\b(breath|breathing|ginhawa|huminga|kaginhawa)\b/u',
    ];

    /**
     * Reset per-request NLP scratch state (debug trace only — KB caches stay warm).
     */
    public static function resetPipelineState(): void
    {
        NlpPipelineDebug::reset();
    }

    /**
     * @param list<array<string, mixed>> $kbSymptoms
     * @return list<array<string, mixed>>
     */
    public static function filterKbSymptoms(
        array $kbSymptoms,
        string $original,
        string $normalized,
        string $english
    ): array {
        $hay = self::haystack($original, $normalized, $english);
        if ($hay === '') {
            return [];
        }

        $filtered = [];
        foreach ($kbSymptoms as $sym) {
            if (!is_array($sym)) {
                continue;
            }
            $evidence = self::evidenceForSymptom($sym, $hay);
            if ($evidence === null) {
                continue;
            }
            $sym['evidence'] = $evidence;
            $filtered[] = $sym;
        }

        return $filtered;
    }

    /**
     * @param list<string> $names
     * @return list<string>
     */
    public static function filterSymptomNames(
        array $names,
        string $original,
        string $normalized,
        string $english
    ): array {
        $hay = self::haystack($original, $normalized, $english);
        $out = [];
        foreach ($names as $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            if (self::nameHasEvidence($name, $hay)) {
                $out[] = $name;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param list<array<string, mixed>> $concepts
     * @return list<array<string, mixed>>
     */
    public static function filterConcepts(
        array $concepts,
        string $original,
        string $normalized,
        string $english
    ): array {
        $hay = self::haystack($original, $normalized, $english);
        $filtered = [];
        foreach ($concepts as $concept) {
            if (!is_array($concept)) {
                continue;
            }
            $label = trim((string) ($concept['canonical_name'] ?? $concept['medical_keyword'] ?? $concept['english'] ?? ''));
            if ($label === '') {
                continue;
            }
            $source = (string) ($concept['source'] ?? '');
            if ($source === 'pattern_inference' && !self::nameHasEvidence($label, $hay)) {
                continue;
            }
            if ($source !== 'pattern_inference' && !self::conceptHasEvidence($concept, $hay)) {
                continue;
            }
            $concept['evidence'] = (string) ($concept['evidence'] ?? self::bestEvidenceLabel($concept, $hay));
            $filtered[] = $concept;
        }

        return $filtered;
    }

    /**
     * @param list<array<string, mixed>> $kbSymptoms
     * @return array<string, string> symptom_name → evidence
     */
    public static function evidenceMap(
        array $kbSymptoms,
        string $original,
        string $normalized,
        string $english
    ): array {
        $map = [];
        foreach (self::filterKbSymptoms($kbSymptoms, $original, $normalized, $english) as $sym) {
            $name = (string) ($sym['symptom_name'] ?? '');
            $evidence = (string) ($sym['evidence'] ?? '');
            if ($name !== '' && $evidence !== '') {
                $map[$name] = $evidence;
            }
        }

        return $map;
    }

    private static function haystack(string $original, string $normalized, string $english): string
    {
        return strtolower(trim(implode(' ', array_filter([$original, $normalized, $english]))));
    }

    /**
     * @param array<string, mixed> $sym
     */
    private static function evidenceForSymptom(array $sym, string $hay): ?string
    {
        $matched = strtolower(trim((string) ($sym['matched_term'] ?? '')));
        if ($matched !== '' && self::termInHaystack($hay, $matched)) {
            return 'matched_term:' . $matched;
        }

        $name = trim((string) ($sym['symptom_name'] ?? ''));
        if ($name !== '' && self::nameHasEvidence($name, $hay)) {
            return 'symptom_name:' . strtolower($name);
        }

        return null;
    }

  /**
     * @param array<string, mixed> $concept
     */
    private static function conceptHasEvidence(array $concept, string $hay): bool
    {
        $keyword = strtolower(trim((string) ($concept['medical_keyword'] ?? '')));
        if ($keyword !== '' && self::termInHaystack($hay, $keyword)) {
            return true;
        }

        $name = trim((string) ($concept['canonical_name'] ?? $concept['english'] ?? ''));

        return $name !== '' && self::nameHasEvidence($name, $hay);
    }

    private static function bestEvidenceLabel(array $concept, string $hay): string
    {
        $matched = strtolower(trim((string) ($concept['matched_term'] ?? $concept['medical_keyword'] ?? '')));
        if ($matched !== '' && self::termInHaystack($hay, $matched)) {
            return 'matched_term:' . $matched;
        }

        return 'concept:' . strtolower((string) ($concept['canonical_name'] ?? ''));
    }

    private static function nameHasEvidence(string $name, string $hay): bool
    {
        $key = strtolower(str_replace(' ', '_', trim($name)));
        if (isset(self::BODY_REQUIREMENTS[$key]) && !preg_match(self::BODY_REQUIREMENTS[$key], $hay)) {
            return false;
        }
        $plain = strtolower(trim($name));
        if (isset(self::BODY_REQUIREMENTS[$plain]) && !preg_match(self::BODY_REQUIREMENTS[$plain], $hay)) {
            return false;
        }

        if (self::termInHaystack($hay, $plain)) {
            return true;
        }

        $words = preg_split('/\s+/u', $plain) ?: [];
        $significant = array_values(array_filter(
            $words,
            static fn (string $w): bool => strlen($w) >= 4 && !in_array($w, self::GENERIC_TOKENS, true)
        ));
        if ($significant === []) {
            return false;
        }
        foreach ($significant as $word) {
            if (!self::termInHaystack($hay, $word)) {
                return false;
            }
        }

        return true;
    }

    private static function termInHaystack(string $hay, string $term): bool
    {
        $term = strtolower(trim($term));
        if ($term === '') {
            return false;
        }
        if (str_contains($hay, $term)) {
            return true;
        }
        if (str_contains($term, ' ')) {
            return (bool) preg_match('/(?<!\w)' . preg_quote($term, '/') . '(?!\w)/u', $hay);
        }

        return (bool) preg_match('/(?<!\w)' . preg_quote($term, '/') . '(?!\w)/u', $hay);
    }
}
