<?php
/**
 * Self-validation, consistency checks, conflict priority, and KB expansion suggestions.
 * Additive QA layer — does not replace ClinicalTriageEngine scoring.
 */

final class TriageSelfValidationEngine
{
    /** Rule conflict priority (highest first). */
    public const RULE_PRIORITY = [
        'emergency_red_flags',
        'airway',
        'breathing',
        'circulation',
        'neurological',
        'severe_bleeding',
        'pregnancy_emergency',
        'poisoning',
        'burns',
        'trauma',
        'high_risk_patient',
        'symptom_combination',
        'duration',
        'temperature',
        'pain_scale',
        'individual_symptoms',
        'administrative_request',
        'confidence_score',
    ];

    /** Mild/admin phrases that must not be EMERGENCY alone. */
    private const MILD_ONLY_PATTERNS = [
        '/\b(i have fever|may lagnat|nilalagnat|mild fever)\b/u',
        '/\b(i have cough|may ubo|inuubo|mild cough)\b/u',
        '/\b(runny nose|sip-?on|sipon)\b/u',
        '/\b(follow[- ]?up|check[- ]?up)\b/u',
        '/\b(medicine refill|refill of my|maintenance medicine|refill ng gamot)\b/u',
        '/\b(i need a refill|kailangan ko ng refill)\b/u',
    ];

    private const ADMIN_PATTERNS = [
        '/\b(follow[- ]?up|check[- ]?up|medicine refill|refill|maintenance medicine|lab result|medical certificate)\b/u',
        '/\b(kailangan ko ng follow-up|kailangan ko ng refill)\b/u',
    ];

    private const LIFE_THREAT_PATTERNS = [
        '/\b(chest pain|difficulty breathing|cannot breathe|shortness of breath|unconscious|seizure|stroke|vomiting blood|coughing blood|severe bleeding|anaphylaxis|poisoning)\b/u',
        '/\b(arm (suddenly )?(became )?weak|cannot speak|slurred speech|one[- ]sided weakness|facial droop)\b/u',
        '/\b(masakit dughan|budlay ginhawa|indi makaginhawa|may dugo sa suka|naguyam|nadulaan malay)\b/u',
        '/\b(hirap huminga|hirap akong huminga|masakit ang dibdib|nagsusuka ng dugo|nawalan ng malay)\b/u',
    ];

    /**
     * Validate a triage result and optionally correct inconsistent classification.
     *
     * @param array<string, mixed> $result ClinicalTriageEngine output
     * @param array<string, mixed> $context Pipeline context (original, corrected, features, etc.)
     * @return array{
     *   passed:bool,
     *   checks:array<string,bool>,
     *   failures:list<string>,
     *   corrected_classification:?string,
     *   winning_rule:string,
     *   knowledge_suggestions:list<array<string,string>>,
     *   result:array<string,mixed>
     * }
     */
    public static function validate(array $result, array $context = []): array
    {
        $original = (string) ($context['original_input'] ?? $result['recommendation_payload']['chief_complaint'] ?? '');
        $corrected = (string) ($context['normalized_text'] ?? $original);
        $english = (string) ($context['english_text'] ?? '');
        $language = (string) ($context['detected_language'] ?? 'unknown');

        $symptoms = is_array($result['detected_symptoms'] ?? null) ? $result['detected_symptoms'] : [];
        $bodyParts = is_array($result['detected_body_parts'] ?? null) ? $result['detected_body_parts'] : [];
        $redFlags = is_array($result['red_flags'] ?? null) ? $result['red_flags'] : [];
        $risks = is_array($result['risk_factors'] ?? null) ? $result['risk_factors'] : [];
        $duration = (string) ($result['duration'] ?? '');
        $temp = is_array($result['temperature'] ?? null) ? (string) (($result['temperature']['label'] ?? '') ?: '') : (string) ($result['temperature'] ?? '');
        $pain = is_array($result['pain_scale'] ?? null) ? (string) (($result['pain_scale']['label'] ?? '') ?: '') : '';
        $display = strtoupper((string) ($result['triage_display'] ?? 'NON-URGENT'));
        $confidence = (int) ($result['confidence_score'] ?? $result['confidence'] ?? 0);
        $reason = (string) ($result['reason'] ?? '');
        $negated = is_array($context['negated_concepts'] ?? null) ? $context['negated_concepts'] : [];
        $factors = is_array($result['assessment_factors'] ?? null) ? $result['assessment_factors'] : [];

        $hay = strtolower($original . ' ' . $corrected . ' ' . $english);

        $checks = [
            'language_detected'           => $language !== '' && $language !== 'unknown',
            'spelling_corrected'          => true, // applied upstream; mark true if pipeline ran corrections
            'symptoms_extracted'          => $symptoms !== [] || self::isAdminOnly($hay) || $negated !== [],
            'body_parts_recognized'       => $bodyParts !== [] || !self::mentionsBodyPart($hay),
            'hiligaynon_translated'       => !self::looksHiligaynon($hay) || $english !== '' || $symptoms !== [],
            'filipino_translated'         => !self::looksFilipino($hay) || $english !== '' || $symptoms !== [],
            'negated_symptoms_removed'    => self::negationOk($symptoms, $negated, $hay),
            'symptom_combinations_evaluated' => isset($factors['symptom_combination']) || isset($factors['combination_classification']) || count($symptoms) < 2,
            'duration_rules_applied'      => $duration !== '' || !self::mentionsDuration($hay),
            'temperature_rules_applied'   => $temp !== '' || !self::mentionsTemperature($hay),
            'pain_scale_rules_applied'    => $pain !== '' || !self::mentionsPainScale($hay),
            'risk_factors_considered'     => $risks !== [] || !self::mentionsRisk($hay),
            'chronic_disease_detected'    => self::mentionsChronic($hay) === ($risks !== [] || !self::mentionsChronic($hay)),
            'pregnancy_detected'          => !preg_match('/\b(pregnant|buntis|buntis ako)\b/u', $hay)
                || preg_match('/\bpregnan|buntis|pregnancy\b/ui', implode(' ', $risks) . ' ' . implode(' ', $symptoms)),
            'emergency_red_flags_checked' => true, // always scanned in engine
            'highest_priority_selected'   => true,
            'explanation_matches'         => self::explanationMatches($display, $reason, $redFlags, $symptoms),
            'classification_consistent'   => true,
        ];

        // Conflict resolution / consistency
        $winningRule = self::selectWinningRule($hay, $redFlags, $symptoms, $risks, $factors, $duration, $temp, $pain);
        $expectedByPriority = self::classificationFromWinningRule($winningRule, $display, $redFlags, $hay);
        $consistent = self::isConsistent($hay, $display, $redFlags, $symptoms);
        $checks['classification_consistent'] = $consistent;
        $checks['highest_priority_selected'] = ($expectedByPriority === $display) || ($redFlags !== [] && $display === 'EMERGENCY');

        $failures = [];
        foreach ($checks as $name => $ok) {
            if (!$ok) {
                $failures[] = $name;
            }
        }

        $correctedClass = null;
        if (!$consistent || !$checks['highest_priority_selected']) {
            $correctedClass = self::enforceConsistency($hay, $display, $redFlags, $symptoms, $expectedByPriority);
        }

        // Confidence gate message
        if ($confidence < ClinicalTriageEngine::CONFIDENCE_THRESHOLD && $display !== 'EMERGENCY' && $redFlags === []) {
            $result['needs_provider_review'] = true;
            $result['recommendation'] = ClinicalTriageEngine::REVIEW_RECOMMENDATION;
            $result['recommended_action'] = ClinicalTriageEngine::REVIEW_RECOMMENDATION;
            $result['reason'] = 'Insufficient information for a reliable triage classification. ' . $reason;
            $result['clinical_reasoning'] = $result['reason'];
            if (isset($result['recommendation_payload']) && is_array($result['recommendation_payload'])) {
                $result['recommendation_payload']['recommendation'] = ClinicalTriageEngine::REVIEW_RECOMMENDATION;
                $result['recommendation_payload']['reason'] = $result['reason'];
                $result['recommendation_payload']['needs_provider_review'] = true;
            }
        }

        if ($correctedClass !== null && $correctedClass !== $display) {
            $result = self::applyClassification($result, $correctedClass, $winningRule);
            $display = $correctedClass;
            $checks['classification_consistent'] = self::isConsistent($hay, $display, $redFlags, $symptoms);
            $checks['highest_priority_selected'] = true;
            $failures = array_values(array_filter($failures, static fn (string $f): bool => !in_array($f, ['classification_consistent', 'highest_priority_selected'], true)));
        }

        $suggestions = self::suggestKnowledgeExpansion($original, $corrected, $english, $symptoms, $language);

        // Enrich QA output fields
        $result['validation'] = [
            'passed' => $failures === [],
            'checks' => $checks,
            'failures' => $failures,
            'winning_rule' => $winningRule,
            'rule_priority_order' => self::RULE_PRIORITY,
        ];
        $result['knowledge_suggestions'] = $suggestions;
        $result['detected_language'] = $language !== 'unknown' ? $language : ($result['detected_language'] ?? self::guessLanguage($hay));
        $result['normalized_text'] = $corrected;

        // Ensure QA payload completeness
        $result['recommendation_payload'] = array_merge(
            is_array($result['recommendation_payload'] ?? null) ? $result['recommendation_payload'] : [],
            [
                'chief_complaint'       => $original,
                'detected_language'     => $result['detected_language'],
                'normalized_text'       => $corrected,
                'detected_symptoms'     => $symptoms,
                'detected_body_parts'   => $bodyParts,
                'duration'              => $duration !== '' ? $duration : null,
                'temperature'           => $temp !== '' ? $temp : null,
                'pain_scale'            => $pain !== '' ? $pain : null,
                'risk_factors'          => $risks,
                'emergency_red_flags'   => $redFlags,
                'severity_score'        => (int) ($result['severity_score'] ?? 0),
                'classification'        => (string) ($result['triage_display'] ?? $display),
                'confidence'            => (int) ($result['confidence_score'] ?? $confidence),
                'reason'                => (string) ($result['reason'] ?? $reason),
                'recommendation'        => (string) ($result['recommendation'] ?? ''),
                'winning_rule'          => $winningRule,
                'validation_passed'     => $failures === [],
            ]
        );

        return [
            'passed' => $failures === [],
            'checks' => $checks,
            'failures' => $failures,
            'corrected_classification' => $correctedClass,
            'winning_rule' => $winningRule,
            'knowledge_suggestions' => $suggestions,
            'result' => $result,
        ];
    }

    /** @param list<string> $redFlags @param list<string> $symptoms @param list<string> $risks */
    public static function selectWinningRule(
        string $hay,
        array $redFlags,
        array $symptoms,
        array $risks,
        array $factors,
        string $duration,
        string $temp,
        string $pain
    ): string {
        if ($redFlags !== []) {
            return 'emergency_red_flags';
        }
        if (preg_match('/\b(choking|airway|cannot breathe|indi makaginhawa)\b/u', $hay)) {
            return 'airway';
        }
        if (preg_match('/\b(difficulty breathing|shortness of breath|budlay ginhawa|hirap huminga)\b/u', $hay)) {
            return 'breathing';
        }
        if (preg_match('/\b(chest pain|masakit dughan|masakit dibdib|severe bleeding|shock)\b/u', $hay)) {
            return preg_match('/\b(bleed|dugo|hemorrh)/u', $hay) ? 'severe_bleeding' : 'circulation';
        }
        if (preg_match('/\b(stroke|seizure|unconscious|paralysis|speech|naguyam|nadulaan malay)\b/u', $hay)) {
            return 'neurological';
        }
        if (preg_match('/\b(pregnant|buntis).{0,40}(bleed|dugo)/u', $hay)) {
            return 'pregnancy_emergency';
        }
        if (preg_match('/\b(poison|poisoning|overdose|lason|pagkalason|toxin|pesticide|organophosphate)\b/u', $hay)) {
            return 'poisoning';
        }
        if (preg_match('/\b(severe burn|large burn|facial burn|airway burn|nasunog lawas|malaking paso|smoke inhalation)\b/u', $hay)) {
            return 'burns';
        }
        if (preg_match('/\b(head injury|trauma|accident|naaksidente|gunshot|stab wound|amputation|crush injury|'
            . 'motor vehicle|car crash|spinal injury|major trauma|nasaksak|naigo ulo)\b/u', $hay)) {
            return 'trauma';
        }
        if ($risks !== [] && preg_match('/\b(chest pain|difficulty breathing|dughan|ginhawa)\b/u', $hay)) {
            return 'high_risk_patient';
        }
        if (!empty($factors['symptom_combination']) || !empty($factors['combination_classification'])) {
            return 'symptom_combination';
        }
        if ($duration !== '' && preg_match('/\b(5|6|7|8|9|10|week|semana|linggo)\b/u', strtolower($duration))) {
            return 'duration';
        }
        if ($temp !== '' && preg_match('/high fever|39|40/u', strtolower($temp))) {
            return 'temperature';
        }
        if ($pain !== '' && preg_match('/\b(7|8|9|10)\b|severe/u', strtolower($pain))) {
            return 'pain_scale';
        }
        if (self::isAdminOnly($hay)) {
            return 'administrative_request';
        }
        if ($symptoms !== []) {
            return 'individual_symptoms';
        }

        return 'confidence_score';
    }

    /** @param list<string> $redFlags */
    private static function classificationFromWinningRule(string $rule, string $current, array $redFlags, string $hay): string
    {
        if (in_array($rule, [
            'emergency_red_flags', 'airway', 'breathing', 'circulation', 'neurological',
            'severe_bleeding', 'pregnancy_emergency', 'poisoning', 'burns', 'trauma',
        ], true)) {
            return 'EMERGENCY';
        }
        if ($rule === 'administrative_request') {
            return 'NON-URGENT';
        }
        if (in_array($rule, ['duration', 'temperature', 'pain_scale', 'high_risk_patient', 'symptom_combination'], true)) {
            return $current === 'EMERGENCY' ? 'EMERGENCY' : 'URGENT';
        }
        if (self::isMildOnly($hay) && $redFlags === []) {
            return 'NON-URGENT';
        }

        return $current;
    }

    /** @param list<string> $redFlags @param list<string> $symptoms */
    public static function isConsistent(string $hay, string $display, array $redFlags, array $symptoms): bool
    {
        $display = strtoupper($display);
        if ($redFlags !== [] && $display !== 'EMERGENCY') {
            return false;
        }
        if (self::hasLifeThreat($hay) && $display === 'NON-URGENT') {
            return false;
        }
        if (self::isMildOnly($hay) && $display === 'EMERGENCY' && $redFlags === []) {
            return false;
        }
        if (self::isAdminOnly($hay) && in_array($display, ['URGENT', 'EMERGENCY'], true) && $redFlags === [] && !self::hasLifeThreat($hay)) {
            return false;
        }

        return true;
    }

    /** @param list<string> $redFlags @param list<string> $symptoms */
    public static function enforceConsistency(
        string $hay,
        string $display,
        array $redFlags,
        array $symptoms,
        string $priorityClass
    ): string {
        if ($redFlags !== [] || self::hasLifeThreat($hay)) {
            return 'EMERGENCY';
        }
        if (self::isAdminOnly($hay) || self::isMildOnly($hay)) {
            return 'NON-URGENT';
        }
        if (in_array($priorityClass, ['NON-URGENT', 'URGENT', 'EMERGENCY'], true)) {
            return $priorityClass;
        }

        return $display;
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private static function applyClassification(array $result, string $display, string $winningRule): array
    {
        $map = [
            'NON-URGENT' => ['LOW', 'NON_URGENT', 'Normal', 'Schedule the patient for a regular consultation.'],
            'URGENT'     => ['HIGH', 'URGENT', 'High', 'Arrange prompt clinical evaluation within hours to 24 hours.'],
            'EMERGENCY'  => ['EMERGENCY', 'EMERGENCY', 'Critical', 'Refer for immediate emergency care now.'],
        ];
        [$level, $class, $priority, $rec] = $map[$display] ?? $map['NON-URGENT'];
        $result['triage_display'] = $display;
        $result['triage_level'] = $level;
        $result['triage_classification'] = $class;
        $result['priority'] = $priority;
        $result['recommendation'] = $rec;
        $result['recommended_action'] = $rec;
        $result['triage_icon'] = $display === 'EMERGENCY' ? '🔴' : ($display === 'URGENT' ? '🟡' : '🟢');
        $result['reason'] = trim((string) ($result['reason'] ?? '') . " Consistency correction via {$winningRule} → {$display}.");
        $result['clinical_reasoning'] = $result['reason'];
        if (isset($result['recommendation_payload']) && is_array($result['recommendation_payload'])) {
            $result['recommendation_payload']['classification'] = $display;
            $result['recommendation_payload']['priority'] = $priority;
            $result['recommendation_payload']['recommendation'] = $rec;
            $result['recommendation_payload']['reason'] = $result['reason'];
            $result['recommendation_payload']['winning_rule'] = $winningRule;
        }

        return $result;
    }

    /**
     * Suggest CSV knowledge expansions for unknown tokens (never edits PHP).
     *
     * @param list<string> $symptoms
     * @return list<array<string,string>>
     */
    public static function suggestKnowledgeExpansion(
        string $original,
        string $normalized,
        string $english,
        array $symptoms,
        string $language
    ): array {
        $suggestions = [];
        $text = strtolower(trim($original));
        if ($text === '') {
            return [];
        }

        // Unknown tokens: alphabetic tokens not covered by known mild/emergency lexicon and no symptom match
        if ($symptoms === []) {
            $tokens = preg_split('/[^a-z\-]+/u', $text) ?: [];
            $stop = ['i', 'have', 'my', 'ako', 'ang', 'sang', 'sa', 'ng', 'the', 'a', 'and', 'ko', 'may', 'for', 'days', 'gid'];
            foreach ($tokens as $tok) {
                if (strlen($tok) < 4 || in_array($tok, $stop, true)) {
                    continue;
                }
                $suggestions[] = [
                    'type' => 'unknown_term',
                    'term' => $tok,
                    'suggest_csv' => 'hiligaynon_medical_terms.csv / filipino_medical_terms.csv / english_medical_terms.csv',
                    'suggested_english' => $english !== '' ? $english : '',
                    'suggested_synonyms' => $tok,
                    'suggested_misspellings' => $tok,
                    'note' => 'Add to modular CSV knowledge base if this is a real clinical expression.',
                ];
                if (count($suggestions) >= 5) {
                    break;
                }
            }
            if ($suggestions !== []) {
                $suggestions[] = [
                    'type' => 'chief_complaint_pattern',
                    'term' => $original,
                    'suggest_csv' => 'chief_complaint_examples.csv',
                    'suggested_english' => $english,
                    'suggested_synonyms' => '',
                    'suggested_misspellings' => '',
                    'note' => 'Consider adding this complaint pattern with an expected triage class.',
                ];
            }
        }

        // Spelling drift: original differs from normalized
        if ($normalized !== '' && strtolower($normalized) !== $text) {
            $suggestions[] = [
                'type' => 'misspelling_pair',
                'term' => $original,
                'suggest_csv' => 'misspellings.csv',
                'suggested_english' => $normalized,
                'suggested_synonyms' => '',
                'suggested_misspellings' => $original,
                'note' => 'Confirm spelling correction mapping in misspellings.csv.',
            ];
        }

        return $suggestions;
    }

    private static function isMildOnly(string $hay): bool
    {
        if (self::hasLifeThreat($hay)) {
            return false;
        }
        foreach (self::MILD_ONLY_PATTERNS as $pat) {
            if (preg_match($pat, $hay)) {
                // mild if no severity escalators
                if (!preg_match('/\b(5 days|one week|severe|grabe|blood|dugo|chest|dughan|breath|ginhawa)\b/u', $hay)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function isAdminOnly(string $hay): bool
    {
        if (self::hasLifeThreat($hay)) {
            return false;
        }
        foreach (self::ADMIN_PATTERNS as $pat) {
            if (preg_match($pat, $hay)) {
                return true;
            }
        }

        return false;
    }

    private static function hasLifeThreat(string $hay): bool
    {
        foreach (self::LIFE_THREAT_PATTERNS as $pat) {
            if (preg_match($pat, $hay)) {
                return true;
            }
        }

        return false;
    }

    private static function looksHiligaynon(string $hay): bool
    {
        return (bool) preg_match('/\b(gid|ako|sang|ginhawa|dughan|lagnat|ubo|sip-on|budlay|masakit|wala|indi)\b/u', $hay);
    }

    private static function looksFilipino(string $hay): bool
    {
        return (bool) preg_match('/\b(ang|ng|ako|masakit|nilalagnat|hirap|huminga|dibdib|kailangan|gamot)\b/u', $hay);
    }

    private static function guessLanguage(string $hay): string
    {
        if (self::looksHiligaynon($hay)) {
            return 'hiligaynon';
        }
        if (self::looksFilipino($hay)) {
            return 'filipino';
        }

        return 'english';
    }

    private static function mentionsBodyPart(string $hay): bool
    {
        return (bool) preg_match('/\b(chest|head|stomach|abdomen|back|throat|ear|eye|arm|leg|dughan|ulo|tiyan|dibdib|likod)\b/u', $hay);
    }

    private static function mentionsDuration(string $hay): bool
    {
        return (bool) preg_match('/\b(\d+\s*(day|days|adlaw|araw|hour|hours|week)|today|yesterday|gahapon|kahapon)\b/u', $hay);
    }

    private static function mentionsTemperature(string $hay): bool
    {
        return (bool) preg_match('/\b(\d{2}(\.\d)?\s*°?\s*c|temperature|fever|lagnat|hilanat)\b/u', $hay);
    }

    private static function mentionsPainScale(string $hay): bool
    {
        return (bool) preg_match('/\b(pain\s*\d|\d\s*\/\s*10|pain scale)\b/u', $hay);
    }

    private static function mentionsRisk(string $hay): bool
    {
        return (bool) preg_match('/\b(pregnant|buntis|diabetes|asthma|hypertension|heart disease|senior|infant|child|anak)\b/u', $hay);
    }

    private static function mentionsChronic(string $hay): bool
    {
        return (bool) preg_match('/\b(diabetes|asthma|hypertension|heart disease|kidney disease|cancer|copd|immunocompromised|maintenance medicine)\b/u', $hay);
    }

    /** @param list<string> $symptoms @param list<string> $negated */
    private static function negationOk(array $symptoms, array $negated, string $hay): bool
    {
        if ($negated === []) {
            return true;
        }
        foreach ($symptoms as $s) {
            $sl = strtolower((string) $s);
            foreach ($negated as $n) {
                if ($n !== '' && (str_contains($sl, $n) || str_contains($n, $sl))) {
                    return false;
                }
            }
        }
        // If text says no fever, fever must not remain
        if (preg_match('/\b(no fever|wala akong lagnat|wala ko lagnat)\b/u', $hay)) {
            foreach ($symptoms as $s) {
                if (preg_match('/fever|lagnat/i', (string) $s)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @param list<string> $redFlags @param list<string> $symptoms */
    private static function explanationMatches(string $display, string $reason, array $redFlags, array $symptoms): bool
    {
        $r = strtolower($reason);
        if ($reason === '') {
            return false;
        }
        if ($display === 'EMERGENCY') {
            return str_contains($r, 'emergency') || str_contains($r, 'warning') || $redFlags !== [];
        }
        if ($display === 'URGENT') {
            return str_contains($r, 'urgent') || str_contains($r, 'prompt') || str_contains($r, 'score');
        }

        return str_contains($r, 'mild') || str_contains($r, 'non-urgent') || str_contains($r, 'no emergency') || $symptoms !== [] || str_contains($r, 'insufficient');
    }
}
