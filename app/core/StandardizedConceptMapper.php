<?php
/**
 * Map complaints to standardized internal medical concepts (CDS vocabulary).
 *
 * Concepts are for internal reasoning — never substitute for literal translation.
 */

final class StandardizedConceptMapper
{
    /**
     * @param array{english?:string, medical_keyword?:string, category?:string, body_part?:string, source?:string}|null $phraseTranslation
     * @param list<array<string, mixed>> $termResults
     * @param list<string> $validatedTerms
     * @param list<array<string, mixed>> $kbSymptoms
     * @return list<array<string, mixed>>
     */
    public static function map(
        string $normalizedText,
        array $literalTranslation,
        ?array $phraseTranslation,
        array $termResults,
        array $validatedTerms = [],
        array $kbSymptoms = []
    ): array {
        $concepts = [];
        $seen = [];

        $add = static function (array $concept) use (&$concepts, &$seen): void {
            $id = strtolower((string) ($concept['concept_id'] ?? ''));
            $name = trim((string) ($concept['canonical_name'] ?? ''));
            $key = $id !== '' ? $id : mb_strtolower($name);
            if ($key === '' || isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $concepts[] = $concept;
        };

        if ($phraseTranslation !== null) {
            foreach (MedicalConceptExtractor::enrichFromTranslation($phraseTranslation) as $c) {
                $resolved = MedicalConceptRegistry::resolve((string) ($c['medical_keyword'] ?? $c['english'] ?? ''));
                $add([
                    'concept_id'      => $resolved['concept_id'] ?? '',
                    'canonical_name'  => $resolved['canonical_name'] ?? (string) ($c['english'] ?? ''),
                    'medical_keyword' => (string) ($c['medical_keyword'] ?? ''),
                    'category'        => (string) ($c['category'] ?? 'symptom'),
                    'body_part'       => (string) ($c['body_part'] ?? ''),
                    'source'          => 'phrase_translation',
                ]);
            }
        }

        self::inferFromPatterns($normalizedText, $add);

        foreach ($validatedTerms as $term) {
            $resolved = MedicalConceptRegistry::resolve($term);
            if ($resolved !== null) {
                $add([
                    'concept_id'      => $resolved['concept_id'],
                    'canonical_name'  => $resolved['canonical_name'],
                    'medical_keyword' => $term,
                    'category'        => $resolved['medical_category'] ?: 'symptom',
                    'body_part'       => '',
                    'source'          => 'validated_term',
                ]);
            }
        }

        foreach ($termResults as $row) {
            if (($row['validation_status'] ?? '') !== 'valid') {
                continue;
            }
            $term = (string) ($row['standardized_term'] ?? '');
            $resolved = MedicalConceptRegistry::resolve($term);
            if ($resolved !== null) {
                $add([
                    'concept_id'      => $resolved['concept_id'],
                    'canonical_name'  => $resolved['canonical_name'],
                    'medical_keyword' => $term,
                    'category'        => $resolved['medical_category'] ?: 'symptom',
                    'body_part'       => '',
                    'source'          => 'dataset_validation',
                ]);
            }
        }

        foreach ($kbSymptoms as $sym) {
            if (!is_array($sym)) {
                continue;
            }
            $add([
                'concept_id'      => (string) ($sym['id'] ?? ''),
                'canonical_name'  => (string) ($sym['symptom_name'] ?? ''),
                'medical_keyword' => (string) ($sym['symptom_name'] ?? ''),
                'category'        => (string) ($sym['medical_category'] ?? 'symptom'),
                'body_part'       => '',
                'source'          => 'symptom_kb',
            ]);
        }

        $literal = trim((string) ($literalTranslation['english'] ?? ''));
        if ($literal !== '' && $concepts === []) {
            $resolved = MedicalConceptRegistry::resolve($literal);
            if ($resolved !== null) {
                $add([
                    'concept_id'      => $resolved['concept_id'],
                    'canonical_name'  => $resolved['canonical_name'],
                    'medical_keyword' => $literal,
                    'category'        => $resolved['medical_category'] ?: 'symptom',
                    'body_part'       => '',
                    'source'          => 'literal_fallback',
                ]);
            }
        }

        return $concepts;
    }

    /**
     * Clinical haystack for KB / triage matching (not shown as patient translation).
     *
     * @param list<array<string, mixed>> $concepts
     */
    public static function clinicalHaystack(array $concepts, ?array $phraseTranslation = null, array $validatedTerms = []): string
    {
        $parts = [];
        foreach ($concepts as $c) {
            foreach (['canonical_name', 'medical_keyword'] as $field) {
                $v = trim((string) ($c[$field] ?? ''));
                if ($v !== '') {
                    $parts[] = $v;
                }
            }
        }
        if ($phraseTranslation !== null) {
            $mk = trim((string) ($phraseTranslation['medical_keyword'] ?? ''));
            if ($mk !== '') {
                $parts[] = MedicalConceptRegistry::canonicalize($mk);
            }
        }
        foreach ($validatedTerms as $term) {
            $t = trim($term);
            if ($t !== '') {
                $parts[] = $t;
            }
        }

        return implode(' ', array_values(array_unique($parts)));
    }

    /**
     * @param callable(array<string, mixed>): void $add
     */
    private static function inferFromPatterns(string $normalizedText, callable $add): void
    {
        $t = strtolower(trim($normalizedText));
        if ($t === '') {
            return;
        }

        if (preg_match('/\b(?:nag\s*)?dugo(?:\s+ang)?\s+ulo\b/u', $t)) {
            $add([
                'concept_id'      => 'bleeding',
                'canonical_name'  => 'Head Bleeding',
                'medical_keyword' => 'head bleeding',
                'category'        => 'symptom',
                'body_part'       => 'head',
                'source'          => 'pattern_inference',
            ]);
            $add([
                'concept_id'      => 'laceration',
                'canonical_name'  => 'Scalp Laceration',
                'medical_keyword' => 'scalp laceration',
                'category'        => 'symptom',
                'body_part'       => 'head',
                'source'          => 'pattern_inference',
            ]);
            $add([
                'concept_id'      => 'head_injury',
                'canonical_name'  => 'Possible Head Injury',
                'medical_keyword' => 'head injury',
                'category'        => 'trauma',
                'body_part'       => 'head',
                'source'          => 'pattern_inference',
            ]);
        }

        if (preg_match('/\bbudlay\b/u', $t) && preg_match('/\bginhawa\b/u', $t)) {
            $add([
                'concept_id'      => 'difficulty_breathing',
                'canonical_name'  => 'Difficulty Breathing',
                'medical_keyword' => 'difficulty breathing',
                'category'        => 'respiratory',
                'body_part'       => '',
                'source'          => 'pattern_inference',
            ]);
        }

        if (preg_match('/\bputol\b/u', $t) && preg_match('/\bkamot\b/u', $t)) {
            $add([
                'concept_id'      => 'amputation',
                'canonical_name'  => 'Amputation',
                'medical_keyword' => 'hand amputation',
                'category'        => 'trauma',
                'body_part'       => 'hands',
                'source'          => 'pattern_inference',
            ]);
        }
    }
}
