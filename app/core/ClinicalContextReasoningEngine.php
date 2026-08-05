<?php
/**
 * Contextual clinical reasoning for triage — evaluates the complete clinical picture.
 *
 * Never assigns urgency from a single keyword/symptom alone. Primary complaints are
 * assessed with associated symptoms, red flags, duration, severity, temperature,
 * and risk factors before classification.
 */

final class ClinicalContextReasoningEngine
{
    private const RULES_PATH = BASE_PATH . '/data/nlp/clinical_context_rules.json';

    /** @var array<string, mixed>|null */
    private static ?array $config = null;

    /** @return array<string, mixed> */
    private static function loadConfig(): array
    {
        if (self::$config !== null) {
            return self::$config;
        }
        if (!is_readable(self::RULES_PATH)) {
            self::$config = ['rules' => [], 'fallback' => [], 'global' => []];

            return self::$config;
        }
        $decoded = json_decode((string) file_get_contents(self::RULES_PATH), true);

        self::$config = is_array($decoded) ? $decoded : ['rules' => [], 'fallback' => [], 'global' => []];

        return self::$config;
    }

    /**
     * Remove context-gated red flags when only the primary symptom phrase matched (no associated emergency signs).
     *
     * @param list<array<string, mixed>> $redFlags
     * @param list<array<string, mixed>> $kbSymptoms
     * @return list<array<string, mixed>>
     */
    public static function filterContextGatedRedFlags(
        array $redFlags,
        string $original,
        string $english,
        array $kbSymptoms
    ): array {
        if ($redFlags === []) {
            return $redFlags;
        }

        $cfg = self::loadConfig();
        $rules = is_array($cfg['rules'] ?? null) ? $cfg['rules'] : [];
        $hay = strtolower(trim($original . ' ' . $english));
        $symptomIds = self::symptomIds($kbSymptoms);
        $contextFactors = self::buildFeatureKeys([], $kbSymptoms, []);

        $filtered = [];
        foreach ($redFlags as $flag) {
            $flagName = strtolower((string) (($flag['flag_name'] ?? '') ?: ($flag['english_pattern'] ?? '')));
            $flagId = strtoupper((string) ($flag['flag_id'] ?? ''));
            $isGated = $flagId === 'RF001' || str_contains($flagName, 'chest pain');

            if (!$isGated) {
                $filtered[] = $flag;
                continue;
            }

            $keep = false;
            foreach ($rules as $rule) {
                if (!is_array($rule)) {
                    continue;
                }
                $primary = array_map('strtolower', (array) ($rule['primary_symptoms'] ?? []));
                if ($primary === [] || array_intersect($primary, $symptomIds) === []) {
                    continue;
                }
                $emergency = is_array($rule['emergency'] ?? null) ? $rule['emergency'] : [];
                if (self::matchIndicators($hay, $symptomIds, $contextFactors, $emergency) !== []) {
                    $keep = true;
                    break;
                }
            }

            if ($keep) {
                $filtered[] = $flag;
            }
        }

        return $filtered;
    }

    /**
     * Apply contextual reasoning after base scoring/combinations.
     *
     * @param list<array<string, mixed>> $kbSymptoms
     * @param array<string, mixed> $features
     * @param list<array<string, mixed>> $redFlags
     * @return array{
     *   display:string,
     *   score:int,
     *   needs_provider_review:bool,
     *   reason:string,
     *   rule_id:string,
     *   rule_name:string,
     *   evaluated_context:list<string>,
     *   sufficient_context:bool,
     *   factors:array<string,mixed>
     * }
     */
    public static function apply(
        string $original,
        string $english,
        array $kbSymptoms,
        array $features,
        array $redFlags,
        int $score,
        string $preliminaryDisplay
    ): array {
        $cfg = self::loadConfig();
        $global = is_array($cfg['global'] ?? null) ? $cfg['global'] : [];
        $fallback = is_array($cfg['fallback'] ?? null) ? $cfg['fallback'] : [];
        $rules = is_array($cfg['rules'] ?? null) ? $cfg['rules'] : [];

        $hay = strtolower(trim($original . ' ' . $english));
        $symptomIds = self::symptomIds($kbSymptoms);
        $contextFactors = self::buildFeatureKeys($features, $kbSymptoms, $redFlags);

        // Confirmed emergency red flags always win — no downgrade.
        if ($redFlags !== []) {
            return self::result(
                'EMERGENCY',
                max($score, 12),
                false,
                self::redFlagReason($redFlags),
                'CTX_RED_FLAG',
                'Emergency red flags',
                ['Emergency red flags present'],
                true,
                ['clinical_context_resolved' => true, 'context_source' => 'red_flags']
            );
        }

        $bestMatch = null;
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $primary = array_map('strtolower', (array) ($rule['primary_symptoms'] ?? []));
            if ($primary === [] || array_intersect($primary, $symptomIds) === []) {
                continue;
            }
            $matched = self::evaluateRule($rule, $hay, $symptomIds, $contextFactors);
            if ($bestMatch === null || self::priority($matched['display']) > self::priority($bestMatch['display'])) {
                $bestMatch = $matched;
                $bestMatch['rule'] = $rule;
            }
        }

        if ($bestMatch !== null) {
            $rule = $bestMatch['rule'];
            $display = $bestMatch['display'];
            $adjustedScore = self::scoreForDisplay($display, $score);
            $needsReview = (bool) ($bestMatch['needs_provider_review'] ?? false);
            $reason = (string) ($bestMatch['reason'] ?? '');

            return self::result(
                $display,
                $adjustedScore,
                $needsReview,
                $reason,
                (string) ($rule['id'] ?? 'CTX'),
                (string) ($rule['name'] ?? 'Clinical context'),
                (array) ($rule['evaluate_for'] ?? []),
                !$needsReview,
                [
                    'clinical_context_resolved' => true,
                    'clinical_context_rule'     => (string) ($rule['id'] ?? ''),
                    'context_classification'    => $display,
                    'context_emergency_hits'    => $bestMatch['emergency_hits'] ?? [],
                    'context_urgent_hits'       => $bestMatch['urgent_hits'] ?? [],
                ]
            );
        }

        // Global fallback: single-symptom or danger-sign without sufficient context.
        return self::applyFallback(
            $hay,
            $symptomIds,
            $kbSymptoms,
            $features,
            $score,
            $preliminaryDisplay,
            $fallback,
            $global
        );
    }

    /**
     * @param list<string> $symptomIds
     * @param array<string, bool> $contextFactors
     * @return array{display:string,reason:string,needs_provider_review:bool,emergency_hits:list<string>,urgent_hits:list<string>}
     */
    private static function evaluateRule(
        array $rule,
        string $hay,
        array $symptomIds,
        array $contextFactors
    ): array {
        $emergency = is_array($rule['emergency'] ?? null) ? $rule['emergency'] : [];
        $urgent = is_array($rule['urgent'] ?? null) ? $rule['urgent'] : [];

        $emergencyHits = self::matchIndicators($hay, $symptomIds, $contextFactors, $emergency);
        if ($emergencyHits !== []) {
            return [
                'display'               => 'EMERGENCY',
                'reason'                => (string) ($rule['emergency_reason'] ?? 'Associated emergency warning signs detected with primary complaint.'),
                'needs_provider_review' => false,
                'emergency_hits'        => $emergencyHits,
                'urgent_hits'           => [],
            ];
        }

        $urgentHits = self::matchIndicators($hay, $symptomIds, $contextFactors, $urgent);
        if ($urgentHits !== []) {
            return [
                'display'               => 'URGENT',
                'reason'                => (string) ($rule['urgent_reason'] ?? 'Moderate-risk features present with primary complaint.'),
                'needs_provider_review' => false,
                'emergency_hits'        => [],
                'urgent_hits'           => $urgentHits,
            ];
        }

        $isolated = strtoupper((string) ($rule['isolated_classification'] ?? 'NON-URGENT'));
        if (!in_array($isolated, ['NON-URGENT', 'URGENT', 'EMERGENCY'], true)) {
            $isolated = 'NON-URGENT';
        }

        $insufficientWhenIsolated = (bool) ($rule['insufficient_when_isolated'] ?? false);
        if ($insufficientWhenIsolated) {
            return [
                'display'               => $isolated,
                'reason'                => (string) ($rule['isolated_reason'] ?? 'Primary complaint requires contextual evaluation.'),
                'needs_provider_review' => false,
                'emergency_hits'        => [],
                'urgent_hits'           => [],
            ];
        }

        return [
            'display'               => $isolated,
            'reason'                => (string) ($rule['isolated_reason'] ?? 'Primary complaint without danger signs — evaluated using full clinical context.'),
            'needs_provider_review' => false,
            'emergency_hits'        => [],
            'urgent_hits'           => [],
        ];
    }

    /**
     * @param list<string> $symptomIds
     * @param array<string, bool> $contextFactors
     * @param array<string, mixed> $indicatorSet
     * @return list<string>
     */
    private static function matchIndicators(
        string $hay,
        array $symptomIds,
        array $contextFactors,
        array $indicatorSet
    ): array {
        $hits = [];
        foreach ((array) ($indicatorSet['patterns'] ?? []) as $pattern) {
            $p = strtolower(trim((string) $pattern));
            if ($p !== '' && str_contains($hay, $p)) {
                $hits[] = $p;
            }
        }
        foreach ((array) ($indicatorSet['symptom_ids'] ?? []) as $id) {
            $sid = strtolower(trim((string) $id));
            if ($sid !== '' && in_array($sid, $symptomIds, true)) {
                $hits[] = 'symptom:' . $sid;
            }
        }
        foreach ((array) ($indicatorSet['feature_keys'] ?? []) as $key) {
            $k = trim((string) $key);
            if ($k !== '' && !empty($contextFactors[$k])) {
                $hits[] = 'feature:' . $k;
            }
        }

        return array_values(array_unique($hits));
    }

    /**
     * @param list<string> $symptomIds
     * @param list<array<string, mixed>> $kbSymptoms
     * @param array<string, mixed> $features
     * @param array<string, mixed> $fallback
     * @param array<string, mixed> $global
     * @return array{display:string,score:int,needs_provider_review:bool,reason:string,rule_id:string,rule_name:string,evaluated_context:list<string>,sufficient_context:bool,factors:array<string,mixed>}
     */
    private static function applyFallback(
        string $hay,
        array $symptomIds,
        array $kbSymptoms,
        array $features,
        int $score,
        string $preliminaryDisplay,
        array $fallback,
        array $global
    ): array {
        $requiresContext = array_map('strtolower', (array) ($fallback['requires_context_symptoms'] ?? []));
        $hasContextSymptom = array_intersect($requiresContext, $symptomIds) !== [];
        $symptomCount = count($kbSymptoms);
        $hasModifiers = self::hasClinicalModifiers($features);
        $dangerRequiresContext = (bool) ($fallback['danger_sign_requires_context'] ?? true);
        $hasDangerAlone = false;

        if ($dangerRequiresContext) {
            foreach ($kbSymptoms as $sym) {
                if (!empty($sym['danger_sign']) && $symptomCount <= 2 && !$hasModifiers) {
                    $hasDangerAlone = true;
                    break;
                }
            }
        }

        $singleSymptomReview = (bool) ($fallback['single_symptom_review'] ?? true);
        $insufficient = false;
        $reason = '';

        if ($singleSymptomReview && $symptomCount === 1 && !$hasModifiers && $hasContextSymptom) {
            $insufficient = true;
            $reason = (string) ($fallback['single_symptom_review_reason'] ?? ($global['insufficient_review_reason'] ?? ''));
        } elseif ($hasDangerAlone && $symptomCount <= 1) {
            // Danger-sign symptom without associated context — review, not auto-emergency.
            $insufficient = true;
            $reason = (string) ($global['insufficient_review_reason'] ?? 'Insufficient clinical information to determine urgency safely.');
        }

        if ($insufficient) {
            return self::result(
                $preliminaryDisplay,
                min($score, 5),
                true,
                $reason,
                'CTX_FALLBACK',
                'Insufficient context',
                ['Primary symptom', 'Associated symptoms', 'Duration', 'Severity', 'Risk factors'],
                false,
                [
                    'clinical_context_resolved' => true,
                    'clinical_context_rule'     => 'CTX_FALLBACK',
                    'insufficient_context'      => true,
                ]
            );
        }

        return self::result(
            $preliminaryDisplay,
            $score,
            false,
            'Triage based on combined symptom profile, modifiers, and clinical scoring.',
            'CTX_NONE',
            'Combined assessment',
            [],
            true,
            ['clinical_context_resolved' => false]
        );
    }

    /**
     * @param list<array<string, mixed>> $kbSymptoms
     * @return list<string>
     */
    private static function symptomIds(array $kbSymptoms): array
    {
        $ids = [];
        foreach ($kbSymptoms as $sym) {
            $id = strtolower(trim((string) ($sym['id'] ?? '')));
            if ($id !== '') {
                $ids[] = $id;
            }
            $name = strtolower(str_replace(' ', '_', trim((string) ($sym['symptom_name'] ?? ''))));
            if ($name !== '') {
                $ids[] = $name;
            }
        }

        return array_values(array_unique($ids));
    }

    /** @param array<string, mixed> $features */
    private static function hasClinicalModifiers(array $features): bool
    {
        if ((string) (($features['duration']['label'] ?? '') ?: '') !== '') {
            return true;
        }
        if ((string) (($features['pain_scale']['label'] ?? '') ?: '') !== '') {
            return true;
        }
        if ((string) (($features['temperature']['label'] ?? '') ?: '') !== '') {
            return true;
        }
        if (($features['risk_factors'] ?? []) !== []) {
            return true;
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $kbSymptoms
     * @param list<array<string, mixed>> $redFlags
     * @return array<string, bool>
     */
    private static function buildFeatureKeys(array $features, array $kbSymptoms, array $redFlags): array
    {
        $painKey = (string) (($features['pain_scale']['modifier_key'] ?? '') ?: '');
        $tempKey = (string) (($features['temperature']['modifier_key'] ?? '') ?: '');
        $bucket = (string) (($features['duration']['bucket'] ?? '') ?: '');
        $risks = $features['risk_factors'] ?? [];
        $riskIds = [];
        foreach ($risks as $risk) {
            if (is_array($risk)) {
                $riskIds[] = (string) ($risk['id'] ?? '');
            }
        }

        $hasFever = false;
        foreach ($kbSymptoms as $sym) {
            if (($sym['id'] ?? '') === 'fever') {
                $hasFever = true;
                break;
            }
        }
        if (!$hasFever && preg_match('/\b(fever|lagnat|hilanat|nilalagnat)\b/u', strtolower((string) ($features['raw_text'] ?? '')))) {
            $hasFever = true;
        }

        return [
            'has_fever'                 => $hasFever || $tempKey !== '',
            'has_high_fever'            => $tempKey === 'high_fever' || preg_match('/\b(39|40|high fever|mataas na lagnat)\b/u', (string) ($features['duration']['raw'] ?? '')),
            'pain_moderate_or_severe'   => in_array($painKey, ['moderate', 'severe'], true),
            'duration_1_to_2_days'      => in_array($bucket, ['1_to_2_days', '3_to_4_days'], true),
            'duration_3_plus_days'      => in_array($bucket, ['3_to_4_days', '5_plus_days'], true),
            'has_pediatric_risk'        => in_array('pediatric', $riskIds, true) || in_array('child', $riskIds, true),
            'has_chronic_risk'          => array_intersect($riskIds, ['diabetes', 'heart_disease', 'hypertension', 'chronic_disease', 'immunocompromised']) !== [],
        ];
    }

    /** @param list<array<string, mixed>> $redFlags */
    private static function redFlagReason(array $redFlags): string
    {
        $names = [];
        foreach (array_slice($redFlags, 0, 3) as $flag) {
            $names[] = (string) (($flag['flag_name'] ?? '') ?: ($flag['english_pattern'] ?? 'warning sign'));
        }

        return 'Emergency warning sign(s) detected (' . implode(', ', $names) . '). Immediate emergency evaluation is recommended.';
    }

    private static function priority(string $display): int
    {
        return match (strtoupper($display)) {
            'EMERGENCY' => 3,
            'URGENT' => 2,
            default => 1,
        };
    }

    private static function scoreForDisplay(string $display, int $score): int
    {
        return match (strtoupper($display)) {
            'EMERGENCY' => max($score, 12),
            'URGENT' => max(min($score, 11), 6),
            default => min($score, 5),
        };
    }

    /**
     * @param list<string> $evaluatedContext
     * @param array<string, mixed> $extraFactors
     * @return array{display:string,score:int,needs_provider_review:bool,reason:string,rule_id:string,rule_name:string,evaluated_context:list<string>,sufficient_context:bool,factors:array<string,mixed>}
     */
    private static function result(
        string $display,
        int $score,
        bool $needsReview,
        string $reason,
        string $ruleId,
        string $ruleName,
        array $evaluatedContext,
        bool $sufficientContext,
        array $extraFactors
    ): array {
        return [
            'display'               => strtoupper($display),
            'score'                 => $score,
            'needs_provider_review' => $needsReview,
            'reason'                => $reason,
            'rule_id'               => $ruleId,
            'rule_name'             => $ruleName,
            'evaluated_context'     => $evaluatedContext,
            'sufficient_context'    => $sufficientContext,
            'factors'               => array_merge([
                'clinical_context_rule'      => $ruleId,
                'clinical_context_name'        => $ruleName,
                'clinical_context_reason'      => $reason,
                'clinical_context_sufficient'  => $sufficientContext,
            ], $extraFactors),
        ];
    }
}
