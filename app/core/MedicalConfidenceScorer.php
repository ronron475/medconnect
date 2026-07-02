<?php
/**
 * Multi-factor medical assessment confidence scoring (0–100).
 */

final class MedicalConfidenceScorer
{
    /**
     * @param array<string, mixed> $nlpPipeline
     * @param list<string> $detectedSymptoms
     * @param list<string> $possibleConditions
     * @return array{
     *   score:int,
     *   score_display:string,
     *   level:string,
     *   level_label:string,
     *   factors:array<string, float>
     * }
     */
    public static function score(array $nlpPipeline, array $detectedSymptoms, array $possibleConditions): array
    {
        $nlpResult = is_array($nlpPipeline['nlp_result'] ?? null) ? $nlpPipeline['nlp_result'] : [];
        $termResults = is_array($nlpPipeline['term_results'] ?? null) ? $nlpPipeline['term_results'] : [];
        $phraseTranslation = $nlpPipeline['translation']['phrase_translation'] ?? null;

        $factors = [
            'symptom_match'       => self::symptomMatchFactor($detectedSymptoms),
            'symptom_relevance'   => self::symptomRelevanceFactor($termResults),
            'translation_quality' => self::translationFactor($nlpResult, $phraseTranslation),
            'dictionary_match'    => self::dictionaryFactor($nlpPipeline),
            'fuzzy_match'         => self::fuzzyFactor($termResults),
            'condition_similarity'=> self::conditionFactor($possibleConditions),
        ];

        $raw = (int) round(array_sum($factors));
        $raw = max(0, min(100, $raw));

        $level = self::levelFromScore($raw);

        return [
            'score'         => $raw,
            'score_display' => $raw . '%',
            'level'         => $level['level'],
            'level_label'   => $level['label'],
            'factors'       => $factors,
        ];
    }

    /** @param list<string> $symptoms */
    private static function symptomMatchFactor(array $symptoms): float
    {
        $count = count($symptoms);
        if ($count === 0) {
            return 5.0;
        }
        if ($count === 1) {
            return 14.0;
        }
        if ($count === 2) {
            return 20.0;
        }
        if ($count <= 4) {
            return 24.0;
        }

        return 25.0;
    }

    /** @param list<array<string, mixed>> $termResults */
    private static function symptomRelevanceFactor(array $termResults): float
    {
        $scores = [];
        foreach ($termResults as $row) {
            if (($row['validation_status'] ?? '') === 'valid') {
                $scores[] = (int) ($row['fuzzy_score'] ?? 0);
            }
        }
        if ($scores === []) {
            return 8.0;
        }

        $avg = array_sum($scores) / count($scores);

        return min(30.0, round($avg * 0.30, 1));
    }

    /**
     * @param array<string, mixed> $nlpResult
     * @param array<string, mixed>|null $phraseTranslation
     */
    private static function translationFactor(array $nlpResult, ?array $phraseTranslation): float
    {
        $english = trim((string) ($nlpResult['english_translation'] ?? ''));
        if ($english === '') {
            return 0.0;
        }

        $score = 8.0;
        if ($phraseTranslation !== null) {
            $score += 7.0;
        }
        if (mb_strlen($english) >= 12) {
            $score += 2.0;
        }

        return min(15.0, $score);
    }

    /** @param array<string, mixed> $nlpPipeline */
    private static function dictionaryFactor(array $nlpPipeline): float
    {
        $valid = (int) ($nlpPipeline['valid_count'] ?? 0);
        $total = (int) ($nlpPipeline['total_count'] ?? 0);
        if ($total <= 0) {
            return $valid > 0 ? 10.0 : 4.0;
        }

        $ratio = $valid / $total;

        return min(15.0, round($ratio * 15.0, 1));
    }

    /** @param list<array<string, mixed>> $termResults */
    private static function fuzzyFactor(array $termResults): float
    {
        if ($termResults === []) {
            return 3.0;
        }

        $high = 0;
        foreach ($termResults as $row) {
            if (($row['confidence_level'] ?? '') === 'high') {
                $high++;
            }
        }

        return min(10.0, 4.0 + ($high * 2.5));
    }

    /** @param list<string> $conditions */
    private static function conditionFactor(array $conditions): float
    {
        $count = count($conditions);
        if ($count === 0) {
            return 2.0;
        }
        if ($count === 1) {
            return 8.0;
        }
        if ($count === 2) {
            return 12.0;
        }

        return 15.0;
    }

    /** @return array{level:string, label:string} */
    private static function levelFromScore(int $score): array
    {
        if ($score >= 95) {
            return ['level' => 'very_high', 'label' => 'Very High Confidence'];
        }
        if ($score >= 80) {
            return ['level' => 'high', 'label' => 'High Confidence'];
        }
        if ($score >= 60) {
            return ['level' => 'moderate', 'label' => 'Moderate Confidence'];
        }
        if ($score >= 40) {
            return ['level' => 'low', 'label' => 'Low Confidence'];
        }

        return ['level' => 'insufficient', 'label' => 'Insufficient Data'];
    }
}
