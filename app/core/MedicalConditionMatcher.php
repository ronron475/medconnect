<?php
/**
 * Symptom and possible-condition matching from NLP pipeline output.
 */

final class MedicalConditionMatcher
{
    /** @var array<string, list<string>> */
    private const SYMPTOM_RULES = [
        'sakit ulo'            => 'Headache',
        'ginahilanat'          => 'Fever',
        'ginakapos ginhawa'    => 'Shortness of Breath',
        'ginakapos'            => 'Shortness of Breath',
        'nagasuka'             => 'Vomiting',
        'gakalipong'           => 'Dizziness',
        'ubo'                  => 'Cough',
        'sip-on'               => 'Runny Nose',
        'sakit tiyan'          => 'Abdominal Pain',
        'sakit dughan'         => 'Chest Pain',
    ];

    /** @var array<string, string> */
    private const CONDITION_RULES = [
        'fever|cough|headache'           => 'Viral Infection',
        'fever|body ache|fatigue'        => 'Influenza',
        'fever|vomiting|diarrhea'      => 'Gastroenteritis',
        'chest pain|shortness of breath' => 'Cardiopulmonary Concern',
        'headache|fever|stiff neck'      => 'Meningitis Concern',
        'rash|itching'                   => 'Allergic Reaction',
        'sore throat|cough|fever'        => 'Upper Respiratory Infection',
        'abdominal pain|vomiting'        => 'Gastrointestinal Distress',
        'dizziness|weakness'             => 'Dehydration or Anemia Concern',
    ];

    /**
     * @param array<string, mixed> $nlpPipeline
     * @param list<string> $checkboxSymptoms
     * @return array{
     *   detected_symptoms:list<string>,
     *   possible_conditions:list<string>,
     *   match_methods:list<string>
     * }
     */
    public static function match(array $nlpPipeline, array $checkboxSymptoms = []): array
    {
        $nlpResult = is_array($nlpPipeline['nlp_result'] ?? null) ? $nlpPipeline['nlp_result'] : [];
        $original = mb_strtolower((string) ($nlpResult['original_text'] ?? ($nlpPipeline['original_input'] ?? '')));
        $english = mb_strtolower((string) ($nlpResult['english_translation'] ?? ($nlpPipeline['translated_english'] ?? '')));
        $haystack = $original . ' ' . $english;

        $symptoms = [];
        $methods = [];

        foreach (self::SYMPTOM_RULES as $phrase => $label) {
            if (mb_strpos($haystack, $phrase) !== false) {
                $symptoms[] = $label;
                $methods[] = 'phrase_rule';
            }
        }

        $concepts = is_array($nlpResult['medical_concepts'] ?? null) ? $nlpResult['medical_concepts'] : [];
        foreach ($concepts as $concept) {
            $term = trim((string) ($concept['english'] ?? ''));
            if ($term !== '') {
                $symptoms[] = self::titleCase($term);
                $methods[] = 'concept_extraction';
            }
        }

        $termResults = is_array($nlpPipeline['term_results'] ?? null) ? $nlpPipeline['term_results'] : [];
        foreach ($termResults as $row) {
            if (($row['validation_status'] ?? '') !== 'valid') {
                continue;
            }
            $term = trim((string) ($row['standardized_term'] ?? $row['english_term'] ?? ''));
            if ($term === '') {
                continue;
            }
            $type = mb_strtolower((string) ($row['term_type'] ?? $row['category'] ?? 'symptom'));
            if (in_array($type, ['symptom', 'complaint', 'sign'], true)) {
                $symptoms[] = self::titleCase($term);
                $methods[] = (string) ($row['match_method'] ?? 'dataset_match');
            }
        }

        $matchedTerms = is_array($nlpResult['matched_dataset_terms'] ?? null) ? $nlpResult['matched_dataset_terms'] : [];
        foreach ($matchedTerms as $term) {
            $symptoms[] = self::titleCase((string) $term);
            $methods[] = 'dataset_term';
        }

        foreach ($checkboxSymptoms as $symptom) {
            $symptom = trim((string) $symptom);
            if ($symptom !== '') {
                $symptoms[] = self::titleCase($symptom);
                $methods[] = 'patient_selected';
            }
        }

        $symptoms = self::uniqueTerms($symptoms);
        $conditions = self::inferConditions($symptoms, $nlpPipeline, $haystack);

        return [
            'detected_symptoms'    => $symptoms,
            'possible_conditions'  => $conditions,
            'match_methods'        => array_values(array_unique($methods)),
        ];
    }

    /**
     * @param list<string> $symptoms
     * @return list<string>
     */
    private static function inferConditions(array $symptoms, array $nlpPipeline, string $haystack): array
    {
        $conditions = [];

        $termResults = is_array($nlpPipeline['term_results'] ?? null) ? $nlpPipeline['term_results'] : [];
        foreach ($termResults as $row) {
            if (($row['validation_status'] ?? '') !== 'valid') {
                continue;
            }
            $type = mb_strtolower((string) ($row['term_type'] ?? $row['category'] ?? ''));
            if ($type !== 'condition') {
                continue;
            }
            $term = trim((string) ($row['standardized_term'] ?? $row['english_term'] ?? ''));
            if ($term !== '') {
                $conditions[] = self::titleCase($term);
            }
        }

        $ml = is_array($nlpPipeline['ml_predictions'] ?? null) ? $nlpPipeline['ml_predictions'] : [];
        foreach ($ml as $row) {
            $name = trim((string) ($row['disease'] ?? $row['name'] ?? ''));
            if ($name !== '') {
                $conditions[] = self::titleCase($name);
            }
        }

        $symptomKeys = array_map(static fn (string $s): string => mb_strtolower($s), $symptoms);
        foreach (self::CONDITION_RULES as $pattern => $condition) {
            $parts = explode('|', $pattern);
            $hits = 0;
            foreach ($parts as $part) {
                foreach ($symptomKeys as $symptom) {
                    if (mb_strpos($symptom, $part) !== false || mb_strpos($haystack, $part) !== false) {
                        $hits++;
                        break;
                    }
                }
            }
            if ($hits >= count($parts)) {
                $conditions[] = $condition;
            }
        }

        return self::uniqueTerms($conditions);
    }

    private static function titleCase(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        return mb_convert_case($text, MB_CASE_TITLE, 'UTF-8');
    }

    /** @param list<string> $terms */
    private static function uniqueTerms(array $terms): array
    {
        $seen = [];
        $out = [];
        foreach ($terms as $term) {
            $term = trim($term);
            if ($term === '') {
                continue;
            }
            $key = mb_strtolower($term);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $term;
        }

        return $out;
    }
}
