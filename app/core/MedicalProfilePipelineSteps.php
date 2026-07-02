<?php
/**
 * Steps 5–7 for registration Step 3 NLP demo: confidence, triage, registration decision.
 */

final class MedicalProfilePipelineSteps
{
    public const CONFIDENCE_THRESHOLD = 85;

    /**
     * @param array<string, mixed> $pipeline
     * @return array<string, mixed>
     */
    public static function enrich(array $pipeline): array
    {
        $translation = is_array($pipeline['translation'] ?? null) ? $pipeline['translation'] : [];
        $datasetValidation = is_array($pipeline['dataset_validation'] ?? null) ? $pipeline['dataset_validation'] : [];
        $invalidDetection = is_array($pipeline['invalid_entry_detection'] ?? null) ? $pipeline['invalid_entry_detection'] : [];
        $termResults = is_array($pipeline['term_results'] ?? null) ? $pipeline['term_results'] : [];

        $conditionsText = trim((string) ($pipeline['medications_text'] ?? ''));
        $allergiesText = trim((string) ($pipeline['allergies_text'] ?? ''));

        $confidenceAssessment = self::buildConfidenceAssessment($termResults, $datasetValidation);
        $clinicalUrgency = self::buildClinicalUrgency(
            $conditionsText,
            $allergiesText,
            $translation,
            $datasetValidation,
            $confidenceAssessment
        );
        $registrationDecision = self::buildRegistrationDecision(
            $invalidDetection,
            $datasetValidation,
            $confidenceAssessment,
            $clinicalUrgency
        );

        $pipeline['confidence_assessment'] = $confidenceAssessment;
        $pipeline['clinical_urgency'] = $clinicalUrgency;
        $pipeline['registration_decision'] = $registrationDecision;

        $pipeline['save_allowed'] = (bool) ($registrationDecision['save_allowed'] ?? false);
        $pipeline['submission_rejected'] = (bool) ($registrationDecision['submission_rejected'] ?? false);
        $pipeline['submission_accepted'] = (bool) ($registrationDecision['submission_accepted'] ?? false);

        if (isset($pipeline['invalid_entry_detection']) && is_array($pipeline['invalid_entry_detection'])) {
            $pipeline['invalid_entry_detection']['save_allowed'] = $pipeline['save_allowed'];
            $pipeline['invalid_entry_detection']['submission_rejected'] = $pipeline['submission_rejected'];
            $pipeline['invalid_entry_detection']['submission_accepted'] = $pipeline['submission_accepted'];
        }

        $pipeline['workflow'] = self::workflowMeta();

        return $pipeline;
    }

    /** @return array<string, mixed> */
    public static function workflowMeta(): array
    {
        return [
            'version' => '2.0',
            'steps'   => [
                'preprocess',
                'medical_translation',
                'fuzzy_matching',
                'dataset_validation',
                'confidence_assessment',
                'clinical_urgency_classification',
                'registration_decision',
            ],
            'policy' => 'Hiligaynon/Ilonggo terms are translated before matching. Only official dataset terms are accepted. '
                . 'Confidence ≥85% required. Emergency symptoms trigger priority triage while allowing validated save.',
        ];
    }

    /**
     * @param list<array<string, mixed>> $termResults
     * @param array<string, mixed> $datasetValidation
     * @return array<string, mixed>
     */
    private static function buildConfidenceAssessment(array $termResults, array $datasetValidation): array
    {
        $items = [];
        $scores = [];

        foreach (self::collectValidatedRows($datasetValidation) as $row) {
            $score = (int) ($row['fuzzy_score'] ?? 0);
            $term = (string) ($row['standardized_term'] ?? $row['english_term'] ?? $row['local_term'] ?? 'Unknown');
            $level = self::confidenceLevelFromScore($score);

            $items[] = [
                'term'               => $term,
                'term_type'          => (string) ($row['category'] ?? 'condition'),
                'confidence_score'   => $score,
                'confidence_display' => $score . '%',
                'confidence_level'   => $level['level'],
                'confidence_label'   => $level['label'],
                'accepted'           => $score >= self::CONFIDENCE_THRESHOLD,
                'record_id'          => (int) (($row['record']['record_id'] ?? $row['matched_record']['record_id'] ?? 0)),
            ];

            if ($score > 0) {
                $scores[] = $score;
            }
        }

        if ($items === [] && $termResults !== []) {
            foreach ($termResults as $term) {
                if (($term['display_status'] ?? '') !== 'valid') {
                    continue;
                }
                $score = (int) ($term['fuzzy_score'] ?? 0);
                $level = self::confidenceLevelFromScore($score);
                $items[] = [
                    'term'               => (string) ($term['standardized_term'] ?? $term['english_term'] ?? ''),
                    'term_type'          => (string) ($term['term_type'] ?? 'condition'),
                    'confidence_score'   => $score,
                    'confidence_display' => $score . '%',
                    'confidence_level'   => $level['level'],
                    'confidence_label'   => $level['label'],
                    'accepted'           => $score >= self::CONFIDENCE_THRESHOLD,
                    'record_id'          => (int) ($term['dataset_record_id'] ?? 0),
                ];
                if ($score > 0) {
                    $scores[] = $score;
                }
            }
        }

        $overall = $scores !== [] ? (int) round(array_sum($scores) / count($scores)) : 0;
        $overallLevel = self::confidenceLevelFromScore($overall);

        return [
            'overall_score'         => $overall,
            'overall_score_display' => $overall > 0 ? $overall . '%' : '—',
            'overall_level'         => $overallLevel['level'],
            'overall_level_label'   => $overallLevel['label'],
            'threshold'             => self::CONFIDENCE_THRESHOLD,
            'level_guide'           => self::confidenceLevelGuide(),
            'items'                 => $items,
            'accepted_count'        => count(array_filter($items, static fn (array $i): bool => ($i['accepted'] ?? false) === true)),
            'rejected_count'        => count(array_filter($items, static fn (array $i): bool => ($i['accepted'] ?? false) === false)),
        ];
    }

    /**
     * @param array<string, mixed> $translation
     * @param array<string, mixed> $datasetValidation
     * @param array<string, mixed> $confidenceAssessment
     * @return array<string, mixed>
     */
    private static function buildClinicalUrgency(
        string $conditionsText,
        string $allergiesText,
        array $translation,
        array $datasetValidation,
        array $confidenceAssessment
    ): array {
        $original = trim($conditionsText . ' ' . $allergiesText);
        $english = trim(
            (string) ($translation['combined_english'] ?? '')
            ?: ((string) ($translation['conditions']['english_text'] ?? '') . ' '
                . (string) ($translation['allergies']['english_text'] ?? ''))
        );

        $detectedSymptoms = self::detectedSymptomLabels($datasetValidation);
        $detectedConditions = self::detectedConditionLabels($datasetValidation);
        $validatedTerms = array_values(array_unique(array_merge($detectedSymptoms, $detectedConditions)));

        $overallScore = (int) ($confidenceAssessment['overall_score'] ?? 0);

        $rawTriage = MedicalTriageDetector::detect(
            $original,
            $english,
            [],
            [],
            $validatedTerms,
            $overallScore
        );

        $triageMeta = MedicalRecommendationEngine::classify([
            'nlp_triage_level' => (string) ($rawTriage['triage_level'] ?? 'LOW'),
            'severity'         => (string) ($rawTriage['severity'] ?? 'mild'),
        ]);

        $display = (string) ($rawTriage['triage_display'] ?? $triageMeta['triage_display'] ?? 'NON-URGENT');
        $priority = (string) ($rawTriage['priority'] ?? match ($display) {
            'EMERGENCY'  => 'Critical',
            'URGENT'     => 'Medium',
            default      => 'Low',
        });

        $recommendation = (string) ($rawTriage['recommendation'] ?? match ($display) {
            'EMERGENCY'  => 'Seek emergency medical care immediately.',
            'URGENT'     => 'Consult a healthcare provider as soon as possible.',
            default      => 'Routine consultation.',
        });

        return [
            'triage_level'          => (string) ($rawTriage['triage_level'] ?? $triageMeta['triage_level'] ?? 'LOW'),
            'triage_display'        => $display,
            'triage_classification' => (string) ($rawTriage['triage_classification'] ?? $triageMeta['triage_classification'] ?? 'NON_URGENT'),
            'triage_icon'           => (string) ($rawTriage['triage_icon'] ?? $triageMeta['triage_icon'] ?? '🟢'),
            'priority'              => $priority,
            'recommendation'        => $recommendation,
            'recommended_action'    => (string) ($rawTriage['recommended_action'] ?? $triageMeta['recommended_action'] ?? ''),
            'detected_symptoms'     => $rawTriage['detected_symptoms'] ?? $detectedSymptoms,
            'detected_conditions'   => $rawTriage['detected_conditions'] ?? $detectedConditions,
            'detected_body_parts'   => $rawTriage['detected_body_parts'] ?? [],
            'severity_score'        => (int) ($rawTriage['severity_score'] ?? 0),
            'severity'              => (string) ($rawTriage['severity'] ?? 'mild'),
            'confidence_score'      => $overallScore,
            'confidence_display'    => $overallScore > 0 ? $overallScore . '%' : '—',
            'confidence_level'      => (string) ($rawTriage['confidence_level'] ?? ''),
            'confidence_level_label'=> (string) ($rawTriage['confidence_level_label'] ?? ''),
            'confidence_accepted'   => (bool) ($rawTriage['confidence_accepted'] ?? ($overallScore >= self::CONFIDENCE_THRESHOLD)),
            'clinical_reasoning'    => (string) ($rawTriage['clinical_reasoning'] ?? ''),
            'reason'                => (string) ($rawTriage['clinical_reasoning'] ?? $rawTriage['reason'] ?? ''),
            'emergency_flags'       => $rawTriage['emergency_flags'] ?? [],
            'assessment_factors'    => $rawTriage['assessment_factors'] ?? [],
            'emergency_alert'       => $display === 'EMERGENCY',
            'final_decision'        => $display === 'EMERGENCY' ? 'Emergency Referral Required' : null,
            'engine_version'        => (string) ($rawTriage['engine_version'] ?? '2.0'),
            'source'                => (string) ($rawTriage['source'] ?? 'clinical_triage_engine_v2'),
            'examples'              => self::urgencyExamples(),
        ];
    }

    /** @param array<string, mixed> $datasetValidation
     * @return list<string>
     */
    private static function detectedConditionLabels(array $datasetValidation): array
    {
        $labels = [];
        $registration = is_array($datasetValidation['registration'] ?? null) ? $datasetValidation['registration'] : [];
        foreach ($registration['conditions'] ?? [] as $item) {
            $label = trim((string) ($item['standardized_term'] ?? ''));
            if ($label !== '') {
                $labels[] = $label;
            }
        }

        return array_values(array_unique($labels));
    }

    /**
     * @param array<string, mixed> $invalidDetection
     * @param array<string, mixed> $datasetValidation
     * @param array<string, mixed> $confidenceAssessment
     * @param array<string, mixed> $clinicalUrgency
     * @return array<string, mixed>
     */
    private static function buildRegistrationDecision(
        array $invalidDetection,
        array $datasetValidation,
        array $confidenceAssessment,
        array $clinicalUrgency
    ): array {
        $hasInvalid = (bool) ($invalidDetection['submission_rejected'] ?? false)
            || ((int) ($invalidDetection['invalid_count'] ?? 0) > 0);
        $eligible = (bool) ($datasetValidation['registration_eligible'] ?? false);
        $isEmergency = (string) ($clinicalUrgency['triage_display'] ?? '') === 'EMERGENCY';
        $allConfidenceOk = ((int) ($confidenceAssessment['rejected_count'] ?? 0)) === 0
            && ((int) ($confidenceAssessment['accepted_count'] ?? 0) > 0
                || (int) ($confidenceAssessment['overall_score'] ?? 0) >= self::CONFIDENCE_THRESHOLD);

        $saveAllowed = false;
        $finalStatus = 'REJECTED';
        $submissionRejected = true;
        $submissionAccepted = false;

        if ($isEmergency && !$hasInvalid) {
            $saveAllowed = true;
            $finalStatus = 'EMERGENCY PRIORITY';
            $submissionRejected = false;
            $submissionAccepted = true;
        } elseif (!$hasInvalid && $eligible && $allConfidenceOk) {
            $saveAllowed = true;
            $finalStatus = 'ACCEPTED';
            $submissionRejected = false;
            $submissionAccepted = true;
        } elseif ($hasInvalid) {
            $finalStatus = 'REJECTED';
        } elseif (!$allConfidenceOk) {
            $finalStatus = 'REJECTED';
        }

        return [
            'save_allowed'          => $saveAllowed,
            'submission_rejected'   => $submissionRejected,
            'submission_accepted'   => $submissionAccepted,
            'final_status'          => $finalStatus,
            'triage_level'          => (string) ($clinicalUrgency['triage_level'] ?? 'LOW'),
            'emergency_alert'       => $isEmergency && $saveAllowed,
            'priority_queue'        => $isEmergency && $saveAllowed ? 'highest' : ($saveAllowed ? 'normal' : null),
            'rules'                 => self::registrationRules(),
            'message'               => self::registrationMessage($finalStatus, $hasInvalid, $isEmergency, $allConfidenceOk),
        ];
    }

    /** @return array{level:string, label:string} */
    private static function confidenceLevelFromScore(int $score): array
    {
        if ($score >= 95) {
            return ['level' => 'very_high', 'label' => 'Very High Confidence'];
        }
        if ($score >= 90) {
            return ['level' => 'high', 'label' => 'High Confidence'];
        }
        if ($score >= self::CONFIDENCE_THRESHOLD) {
            return ['level' => 'moderate', 'label' => 'Moderate Confidence'];
        }

        return ['level' => 'rejected', 'label' => 'Rejected'];
    }

    /** @return list<array<string, string>> */
    private static function confidenceLevelGuide(): array
    {
        return [
            ['range' => '95–100%', 'label' => 'Very High Confidence'],
            ['range' => '90–94%', 'label' => 'High Confidence'],
            ['range' => '85–89%', 'label' => 'Moderate Confidence'],
            ['range' => 'Below 85%', 'label' => 'Rejected'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function collectValidatedRows(array $datasetValidation): array
    {
        $rows = [];
        foreach (['conditions', 'symptoms', 'allergies'] as $field) {
            $block = $datasetValidation[$field] ?? [];
            foreach ($block['results'] ?? [] as $row) {
                if (($row['final_status'] ?? '') !== 'valid') {
                    continue;
                }
                $row['category'] = $row['category'] ?? match ($field) {
                    'allergies' => 'allergy',
                    'symptoms'  => 'symptom',
                    default     => 'condition',
                };
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /** @param array<string, mixed> $datasetValidation
     * @return list<string>
     */
    private static function detectedSymptomLabels(array $datasetValidation): array
    {
        $labels = [];
        $registration = is_array($datasetValidation['registration'] ?? null) ? $datasetValidation['registration'] : [];

        foreach (['symptoms', 'conditions'] as $key) {
            foreach ($registration[$key] ?? [] as $item) {
                $label = trim((string) ($item['standardized_term'] ?? ''));
                if ($label !== '') {
                    $labels[] = $label;
                }
            }
        }

        return array_values(array_unique($labels));
    }

    /** @return array<string, list<string>> */
    private static function urgencyExamples(): array
    {
        return [
            'NON-URGENT' => [
                'Mild headache',
                'Controlled hypertension',
                'Mild cough',
                'Allergic rhinitis',
                'Skin itching',
            ],
            'URGENT' => [
                'High fever',
                'Severe abdominal pain',
                'Persistent vomiting',
                'Moderate breathing difficulty',
                'Uncontrolled hypertension',
            ],
            'EMERGENCY' => [
                'Chest pain',
                'Difficulty breathing',
                'Loss of consciousness',
                'Seizure',
                'Stroke symptoms',
                'Severe bleeding',
                'Suicidal behavior',
            ],
        ];
    }

    /** @return list<array<string, string>> */
    private static function registrationRules(): array
    {
        return [
            [
                'condition' => 'All terms validated AND confidence ≥ 85%',
                'result'    => 'save_allowed = true',
            ],
            [
                'condition' => 'Invalid medical terms exist',
                'result'    => 'save_allowed = false',
            ],
            [
                'condition' => 'Emergency symptoms detected (validated)',
                'result'    => 'save_allowed = true, triage_level = EMERGENCY, emergency_alert = true, priority_queue = highest',
            ],
        ];
    }

    private static function registrationMessage(
        string $finalStatus,
        bool $hasInvalid,
        bool $isEmergency,
        bool $allConfidenceOk
    ): string {
        return match ($finalStatus) {
            'EMERGENCY PRIORITY' => 'Validated profile saved with EMERGENCY priority triage. Immediate clinical follow-up required.',
            'ACCEPTED'           => 'All terms validated with sufficient confidence. Registration may proceed.',
            'REJECTED'           => $hasInvalid
                ? 'Registration rejected — invalid or unverified medical terms detected.'
                : (!$allConfidenceOk
                    ? 'Registration rejected — one or more terms are below the 85% confidence threshold.'
                    : 'Registration rejected — validation incomplete.'),
            default              => 'Registration decision pending.',
        };
    }
}
