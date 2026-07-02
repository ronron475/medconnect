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
        int $confidenceScore = 0
    ): array {
        $entities = MedicalEntityExtractor::extractEntities($original);
        if ($entities === [] && $concepts !== []) {
            foreach ($concepts as $c) {
                $entities[] = [
                    'english_term' => (string) ($c['english'] ?? $c['medical_keyword'] ?? ''),
                    'symptom'      => (string) ($c['symptom'] ?? ''),
                    'condition'    => (string) ($c['condition'] ?? ''),
                    'body_part'    => (string) ($c['body_part'] ?? ''),
                    'severity'     => (string) ($c['severity'] ?? ''),
                    'category'     => (string) ($c['category'] ?? 'symptom'),
                    'type'         => (string) ($c['classification'] ?? 'symptom'),
                ];
            }
        }

        $englishFull = trim($english);
        if ($englishFull === '' && $phraseTranslation !== []) {
            $englishFull = (string) ($phraseTranslation['english'] ?? '');
        }

        return ClinicalTriageEngine::assess(
            $original,
            $englishFull,
            $entities,
            $validatedTerms,
            $confidenceScore
        );
    }
}
