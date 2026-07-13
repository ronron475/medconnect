<?php
/**
 * Standards-based multi-factor clinical urgency classification (Step 6).
 */

final class ClinicalTriageEngine
{
    public const CONFIDENCE_THRESHOLD = 85;

    /**
     * @param list<array<string, mixed>> $entities
     * @param list<string> $validatedTerms
     * @return array<string, mixed>
     */
    public static function assess(
        string $originalText = '',
        string $englishText = '',
        array $entities = [],
        array $validatedTerms = [],
        int $confidenceScore = 0
    ): array {
        $original = trim($originalText);
        $english = trim($englishText);

        if ($entities === [] && $original !== '') {
            $entities = MedicalEntityExtractor::extractEntities($original);
        }

        [$symptoms, $conditions, $bodyParts] = self::collectFromEntities($entities);
        foreach ($validatedTerms as $term) {
            $t = trim($term);
            if ($t === '') {
                continue;
            }
            if (str_contains(strtolower($t), 'infection') || str_contains(strtolower($t), 'fracture')) {
                $conditions[] = $t;
            } else {
                $symptoms[] = $t;
            }
        }
        $symptoms = array_values(array_unique($symptoms));
        $conditions = array_values(array_unique($conditions));
        $bodyParts = array_values(array_unique($bodyParts));

        $redFlags = EmergencyFlagsLoader::scanEmergencyFlags($original, $english);
        [$urgencyScore, $factors] = self::scoreFactors($original, $english, $entities, $validatedTerms);

        // CSV condition/phrase severity classifications constrain the composite score (not LLM).
        $csvSeverity = self::lookupCsvConditionSeverity($entities, $conditions, $symptoms);
        if ($csvSeverity !== null) {
            $factors['csv_condition_severity'] = $csvSeverity;
            $urgencyScore = self::applyRuleScoreBounds(
                $urgencyScore,
                (string) ($csvSeverity['severity_level'] ?? '')
            );
            $factors['matched_condition_severity'] = (string) ($csvSeverity['medical_condition'] ?? '');
        }

        if ($redFlags !== []) {
            $display = 'EMERGENCY';
            $priority = 'Critical';
            $triageLevel = 'EMERGENCY';
            $classification = 'EMERGENCY';
            $recommendation = 'Seek emergency medical care immediately.';
        } elseif ($urgencyScore >= 75) {
            $display = 'EMERGENCY';
            $priority = 'Critical';
            $triageLevel = 'EMERGENCY';
            $classification = 'EMERGENCY';
            $recommendation = (string) (($csvSeverity['recommended_action'] ?? '')
                ?: 'Seek emergency medical care immediately.');
        } elseif ($urgencyScore >= 38) {
            $display = 'URGENT';
            $priority = 'Medium';
            $triageLevel = 'HIGH';
            $classification = 'URGENT';
            $recommendation = (string) (($csvSeverity['recommended_action'] ?? '')
                ?: 'Consult a healthcare provider as soon as possible.');
        } else {
            $display = 'NON-URGENT';
            $priority = 'Low';
            $triageLevel = 'LOW';
            $classification = 'NON_URGENT';
            $recommendation = (string) (($csvSeverity['recommended_action'] ?? '')
                ?: 'Routine consultation.');
        }

        $conf = self::confidenceLevel($confidenceScore);
        $clinicalReasoning = self::buildReasoning($display, $symptoms, $conditions, $bodyParts, $factors, $redFlags);
        $emergencyFlagNames = array_values(array_unique(array_map(
            static fn (array $f): string => $f['flag_name'] !== '' ? $f['flag_name'] : $f['english_pattern'],
            $redFlags
        )));
        $icons = ['NON-URGENT' => '🟢', 'URGENT' => '🟡', 'EMERGENCY' => '🔴'];

        return [
            'triage_display'         => $display,
            'triage_classification'=> $classification,
            'triage_level'         => $triageLevel,
            'triage_icon'          => $icons[$display] ?? '🟢',
            'priority'               => $priority,
            'severity_score'         => $urgencyScore,
            'severity'               => (string) ($factors['symptom_severity'] ?? 'mild'),
            'confidence_score'       => $confidenceScore,
            'confidence_display'     => $confidenceScore > 0 ? $confidenceScore . '%' : '—',
            'confidence_level'       => $conf['level'],
            'confidence_level_label' => $conf['label'],
            'confidence_accepted'    => $conf['accepted'],
            'confidence_threshold'   => self::CONFIDENCE_THRESHOLD,
            'detected_symptoms'      => $symptoms,
            'detected_conditions'    => $conditions,
            'detected_body_parts'    => $bodyParts,
            'emergency_flags'        => $emergencyFlagNames,
            'red_flags_triggered'    => $redFlags,
            'assessment_factors'     => $factors,
            'clinical_reasoning'     => $clinicalReasoning,
            'reason'                 => $clinicalReasoning,
            'recommendation'         => $recommendation,
            'recommended_action'     => $recommendation,
            'source'                 => 'clinical_triage_engine_v2',
            'engine_version'         => '2.0',
        ];
    }

    /** @param list<array<string, mixed>> $entities
     * @return array{0:list<string>,1:list<string>,2:list<string>}
     */
    private static function collectFromEntities(array $entities): array
    {
        $symptoms = [];
        $conditions = [];
        $bodyParts = [];
        foreach ($entities as $e) {
            $eng = trim((string) ($e['english_term'] ?? ''));
            if ($eng === '') {
                continue;
            }
            $sym = trim((string) ($e['symptom'] ?? ''));
            $cond = trim((string) ($e['condition'] ?? ''));
            $bp = trim((string) ($e['body_part'] ?? ''));
            if ($sym !== '' && $sym !== 'symptom') {
                $symptoms[] = str_replace('_', ' ', $sym);
            }
            if ($cond !== '' || str_contains(strtolower($eng), 'infection') || ($e['type'] ?? '') === 'condition') {
                $conditions[] = $eng;
            } else {
                $symptoms[] = $eng;
            }
            if ($bp !== '') {
                $bodyParts[] = $bp;
            }
        }

        return [array_values(array_unique($symptoms)), array_values(array_unique($conditions)), array_values(array_unique($bodyParts))];
    }

    /** @return array{level:string,label:string,accepted:bool} */
    private static function confidenceLevel(int $score): array
    {
        if ($score >= 95) {
            return ['level' => 'very_high', 'label' => 'Very High', 'accepted' => true];
        }
        if ($score >= 90) {
            return ['level' => 'high', 'label' => 'High', 'accepted' => true];
        }
        if ($score >= self::CONFIDENCE_THRESHOLD) {
            return ['level' => 'moderate', 'label' => 'Moderate', 'accepted' => true];
        }

        return ['level' => 'review_needed', 'label' => 'Review Needed', 'accepted' => false];
    }

    /**
     * @param list<array<string, mixed>> $entities
     * @param list<string> $validatedTerms
     * @return array{0:int,1:array<string,mixed>}
     */
    private static function scoreFactors(string $original, string $english, array $entities, array $validatedTerms): array
    {
        $text = strtolower($original . ' ' . $english);
        $factors = [
            'primary_symptom'        => $entities[0]['english_term'] ?? ($validatedTerms[0] ?? ''),
            'symptom_severity'       => self::effectiveSymptomSeverity($entities, $text),
            'symptom_duration'       => self::extractDuration($original),
            'symptom_count'          => max(count($validatedTerms), count($entities)),
            'body_system'            => $entities[0]['category'] ?? 'general',
            'bleeding_status'        => self::bleedingStatus($text, $entities),
            'breathing_status'       => self::breathingStatus($text),
            'consciousness_status'   => self::consciousnessStatus($text),
            'pain_intensity'         => self::effectiveSymptomSeverity($entities, $text),
            'infection_indicators'   => [],
            'neurological_indicators'=> [],
            'injury_mechanism'       => null,
        ];

        $score = match ($factors['symptom_severity']) {
            'severe'   => 72,
            'moderate' => 42,
            default    => 12,
        };

        if ($factors['breathing_status'] === 'severe_distress') {
            $score += 95;
        } elseif ($factors['breathing_status'] === 'moderate_difficulty') {
            $score += 45;
        }

        if ($factors['bleeding_status'] === 'severe_uncontrolled') {
            $score += 90;
        } elseif ($factors['bleeding_status'] === 'moderate') {
            $score += 50;
        } elseif ($factors['bleeding_status'] === 'minor') {
            $score += 28;
        }

        if ($factors['consciousness_status'] === 'altered') {
            $score += 95;
        }

        foreach ($entities as $e) {
            $eng = strtolower((string) ($e['english_term'] ?? ''));
            if (str_contains($eng, 'infection') || str_contains($text, 'nana')) {
                $factors['infection_indicators'][] = $eng ?: 'infection';
                $score += 35;
            }
            if (preg_match('/stroke|seizure|weakness|speech|confusion|vision loss/u', $eng)) {
                $factors['neurological_indicators'][] = $eng;
                $score += 75;
            }
            if (preg_match('/fracture|amputation|trauma|collision|fall injury/u', $eng)) {
                $factors['injury_mechanism'] = $eng;
                $score += 55;
            }
        }

        if ($factors['symptom_count'] >= 2) {
            $score += 12;
        }
        if ((str_contains($text, 'fever') || str_contains($text, 'hilanat')) && $factors['symptom_count'] >= 2) {
            $score += 22;
        }

        $ruled = TriageRulesLoader::matchTriage($original, $english);
        if ($ruled !== null) {
            $tri = strtoupper((string) ($ruled['triage_level'] ?? ''));
            $score = self::applyRuleScoreBounds($score, $tri);
            $pattern = (string) (($ruled['hiligaynon_pattern'] ?? '') ?: ($ruled['english_pattern'] ?? ''));
            if ($pattern !== '') {
                $factors['matched_triage_rule'] = $pattern;
            }
        }

        return [min($score, 100), $factors];
    }

    /** @param list<array<string, mixed>> $entities */
    private static function bleedingStatus(string $text, array $entities): string
    {
        if (str_contains($text, 'indi mapunggan') || str_contains($text, 'grabe gid nagadugo')) {
            return 'severe_uncontrolled';
        }
        foreach ($entities as $e) {
            if (str_contains(strtolower((string) ($e['english_term'] ?? '')), 'bleed')) {
                return str_contains($text, 'grabe') || str_contains($text, 'severe') ? 'moderate' : 'minor';
            }
        }
        if (preg_match('/nagdugo|gadugo|nagadugo|dugo/u', $text)) {
            return 'minor';
        }

        return 'none';
    }

    private static function breathingStatus(string $text): string
    {
        if (preg_match('/indi ko makaginhawa|cannot breathe|respiratory distress|choking/u', $text)) {
            return 'severe_distress';
        }
        if (preg_match('/budlay magginhawa|dula ginhawa|difficulty breathing|shortness of breath/u', $text)) {
            return 'moderate_difficulty';
        }

        return 'normal';
    }

    private static function consciousnessStatus(string $text): string
    {
        if (preg_match('/loss of consciousness|unconscious|nadulaan ko malay|nagpunaw/u', $text)) {
            return 'altered';
        }

        return 'normal';
    }

    /** @param list<array<string, mixed>> $entities */
    private static function painIntensity(array $entities, string $text): string
    {
        if (str_contains($text, 'gid') || str_contains($text, 'grabe') || str_contains($text, 'severe')) {
            return 'severe';
        }
        foreach ($entities as $e) {
            $sev = strtolower((string) ($e['severity'] ?? ''));
            if (in_array($sev, ['severe', 'high', 'critical'], true)) {
                return 'severe';
            }
            if (in_array($sev, ['moderate', 'medium'], true)) {
                return 'moderate';
            }
        }

        return 'mild';
    }

    /** @param list<array<string, mixed>> $entities */
    private static function effectiveSymptomSeverity(array $entities, string $text): string
    {
        $raw = self::painIntensity($entities, $text);
        foreach ($entities as $e) {
            $tri = strtolower((string) ($e['triage_level'] ?? ''));
            if (in_array($tri, ['non_urgent', 'non-urgent', 'routine', 'low'], true)) {
                return 'mild';
            }
            if (in_array($tri, ['urgent', 'high'], true) && in_array($raw, ['severe', 'critical'], true)) {
                return 'moderate';
            }
        }

        return $raw;
    }

    /**
     * @param list<array<string, mixed>> $entities
     * @param list<string> $conditions
     * @param list<string> $symptoms
     * @return array<string, mixed>|null
     */
    private static function lookupCsvConditionSeverity(array $entities, array $conditions, array $symptoms): ?array
    {
        if (!class_exists('ConditionSeverityLoader')) {
            return null;
        }

        $terms = [];
        foreach ($entities as $e) {
            foreach (['english_term', 'condition', 'symptom', 'hiligaynon_term'] as $key) {
                $val = trim((string) ($e[$key] ?? ''));
                if ($val !== '') {
                    $terms[] = $val;
                }
            }
        }
        foreach (array_merge($conditions, $symptoms) as $t) {
            $terms[] = (string) $t;
        }

        $hit = ConditionSeverityLoader::lookup($terms);
        if ($hit !== null) {
            return $hit;
        }

        // Fallback: highest entity phrase triage_level from CSVs
        $rank = ['NON_URGENT' => 0, 'URGENT' => 1, 'EMERGENCY' => 2];
        $best = '';
        foreach ($entities as $e) {
            $tri = strtolower(str_replace('-', '_', trim((string) ($e['triage_level'] ?? ''))));
            $level = match (true) {
                in_array($tri, ['non_urgent', 'routine', 'low'], true) => 'NON_URGENT',
                in_array($tri, ['urgent', 'high'], true) => 'URGENT',
                in_array($tri, ['emergency', 'critical'], true) => 'EMERGENCY',
                default => '',
            };
            if ($level !== '' && ($best === '' || $rank[$level] > $rank[$best])) {
                $best = $level;
            }
        }
        if ($best === '') {
            return null;
        }

        return [
            'medical_condition'  => 'phrase_triage_level',
            'severity_level'      => $best,
            'urgency_score'      => $best === 'EMERGENCY' ? 90 : ($best === 'URGENT' ? 55 : 20),
            'emergency_flag'     => $best === 'EMERGENCY',
            'recommended_action' => '',
            'source'             => 'entity.triage_level',
        ];
    }

    private static function applyRuleScoreBounds(int $score, string $tri): int
    {
        $tri = strtoupper(str_replace('-', '_', $tri));
        if ($tri === 'EMERGENCY') {
            return max($score, 75);
        }
        if (in_array($tri, ['HIGH', 'URGENT'], true)) {
            return max(min($score, 74), 45);
        }
        if (in_array($tri, ['LOW', 'NON_URGENT', 'ROUTINE'], true)) {
            return min($score, 37);
        }

        return $score;
    }

    private static function extractDuration(string $text): string
    {
        if (preg_match('/\d+\s*ka\s*adlaw|dugay\s+na|bag-o\s+lang|semana\s+na|gahapon/u', strtolower($text), $m)) {
            return $m[0];
        }

        return '';
    }

    /**
     * @param list<string> $symptoms
     * @param list<string> $conditions
     * @param list<string> $bodyParts
     * @param array<string, mixed> $factors
     * @param list<array<string, string>> $redFlags
     */
    private static function buildReasoning(
        string $display,
        array $symptoms,
        array $conditions,
        array $bodyParts,
        array $factors,
        array $redFlags
    ): string {
        if ($display === 'EMERGENCY') {
            $names = array_map(static fn (array $f): string => $f['flag_name'] ?: $f['english_pattern'], $redFlags);
            $flagTxt = $names !== [] ? implode(', ', array_slice($names, 0, 3)) : 'established emergency warning signs';

            return "Symptoms match established emergency warning criteria ({$flagTxt}) and may pose an immediate threat to life or function.";
        }

        if ($display === 'URGENT') {
            $lead = $conditions !== [] ? 'The presence of ' . strtolower($conditions[0]) : (
                $symptoms !== [] ? 'The presence of ' . strtolower(implode(', ', array_slice($symptoms, 0, 2))) : 'Clinical findings'
            );
            if ($bodyParts !== []) {
                $lead .= ' affecting the ' . implode(', ', array_slice($bodyParts, 0, 2));
            }

            return "{$lead} suggests a potentially serious condition that should be evaluated by a healthcare provider within hours.";
        }

        return 'Symptoms are mild, stable, and do not currently indicate a serious medical condition requiring immediate intervention.';
    }
}
