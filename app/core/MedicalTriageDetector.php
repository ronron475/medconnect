<?php
/**
 * Step 6: Standards-based clinical urgency detection (delegates to ClinicalTriageEngine).
 */

final class MedicalTriageDetector
{
    /**
     * @param array<string, mixed> $phraseTranslation
     * @param list<array<string, mixed>> $concepts
     * @param list<string> $validatedTerms
     * @return array<string, mixed>
     */
    public static function detect(
        string $original,
        string $english,
        array $phraseTranslation,
        array $concepts,
        array $validatedTerms = [],
        int $confidenceScore = 0,
        string $clinicalEnglish = ''
    ): array {
        $entities = MedicalEntityExtractor::extractEntities($original);
        if ($entities === [] && $concepts !== []) {
            foreach ($concepts as $c) {
                $entities[] = [
                    'english_term' => (string) ($c['canonical_name'] ?? $c['medical_keyword'] ?? ''),
                    'symptom'      => (string) ($c['canonical_name'] ?? ''),
                    'condition'    => '',
                    'body_part'    => (string) ($c['body_part'] ?? ''),
                    'severity'     => '',
                    'category'     => (string) ($c['category'] ?? 'symptom'),
                    'type'         => 'symptom',
                ];
            }
        }

        $englishFull = trim($english);
        if ($englishFull === '' && $phraseTranslation !== []) {
            $englishFull = (string) ($phraseTranslation['literal_english'] ?? ($phraseTranslation['english'] ?? ''));
        }
        if ($clinicalEnglish !== '') {
            $englishFull = trim($englishFull . ' ' . $clinicalEnglish);
        }

        $result = ClinicalTriageEngine::assess(
            $original,
            $englishFull,
            $entities,
            $validatedTerms,
            $confidenceScore
        );

        $symptoms = is_array($result['detected_symptoms'] ?? null) ? $result['detected_symptoms'] : [];
        $result['associated_symptoms'] = count($symptoms) > 1 ? array_values(array_slice($symptoms, 1)) : [];
        $result['entities'] = $entities;

        return $result;
    }
}
