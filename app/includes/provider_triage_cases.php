<?php

require_once __DIR__ . '/triage_assessment_schema.php';
require_once __DIR__ . '/triage_provider_assignment.php';
require_once __DIR__ . '/complaint_evidence.php';

/**
 * Load triage cases visible to a provider
 * (consultations, booked slots, or recent digital referrals — including emergency-only).
 *
 * @return list<array<string, mixed>>
 */
function provider_triage_cases_load(PDO $pdo, int $providerId): array
{
    triage_assessment_ensure_schema($pdo);

    $stmt = $pdo->prepare("
        SELECT
            tr.id, tr.patient_id, tr.level AS triage, tr.symptoms, tr.chief_complaint, tr.urgency_label,
            tr.status, tr.assessed_at,
            tr.confidence_score, tr.severity, tr.triage_level, tr.triage_classification,
            tr.english_complaint, tr.detected_symptoms_json, tr.possible_conditions_json,
            tr.recommendations, tr.assessment_payload, tr.engine,
            tr.recommendation_status, tr.recommendation_approved_at, tr.recommendation_patient_ack_at,
            tr.assigned_provider_id, tr.assigned_at,
            tr.recommendation_approved_by,
            u.first_name, u.last_name
        FROM triage_results tr
        JOIN users u ON tr.patient_id = u.id
        WHERE
          EXISTS (
            SELECT 1 FROM consultations c
            WHERE c.patient_id = tr.patient_id AND c.provider_id = ?
            ORDER BY c.id DESC LIMIT 1
          )
          OR EXISTS (
            SELECT 1 FROM appointment_slots s
            WHERE s.patient_id = tr.patient_id AND s.provider_id = ? AND s.status = 'booked'
              AND s.slot_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            ORDER BY s.id DESC LIMIT 1
          )
          OR EXISTS (
            SELECT 1 FROM digital_referrals dr
            WHERE dr.patient_id = tr.patient_id
              AND dr.provider_id = ?
              AND dr.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
          )
          OR (
            tr.assigned_provider_id = ?
            AND tr.recommendation_status IN ('pending_approval', 'approved', 'rejected')
            AND tr.assessed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
          )
        ORDER BY
          CASE
            WHEN LOWER(COALESCE(tr.triage_level, '')) = 'emergency'
              OR LOWER(COALESCE(tr.urgency_label, '')) LIKE '%emergency%'
            THEN 0
            WHEN LOWER(COALESCE(tr.triage_level, '')) = 'urgent' OR CAST(tr.level AS UNSIGNED) <= 2 THEN 1
            ELSE 2
          END ASC,
          tr.assessed_at DESC
    ");
    $stmt->execute([$providerId, $providerId, $providerId, $providerId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $cases = [];
    foreach ($rows as $t) {
        $symptom_list = [];
        $decoded_symptoms = json_decode((string) ($t['symptoms'] ?? ''), true);
        if (is_array($decoded_symptoms)) {
            foreach ($decoded_symptoms as $symptom) {
                $symptom = trim((string) $symptom);
                if ($symptom !== '') {
                    $symptom_list[] = ucwords(str_replace('_', ' ', $symptom));
                }
            }
        } elseif (!empty($t['symptoms'])) {
            $symptom_list[] = (string) $t['symptoms'];
        }

        $detected_ai = [];
        $decoded_detected = json_decode((string) ($t['detected_symptoms_json'] ?? ''), true);
        if (is_array($decoded_detected)) {
            foreach ($decoded_detected as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $detected_ai[] = trim($item);
                } elseif (is_array($item)) {
                    $label = trim((string) ($item['term'] ?? $item['symptom'] ?? $item['english'] ?? $item['name'] ?? ''));
                    if ($label !== '') {
                        $detected_ai[] = $label;
                    }
                }
            }
        }

        $conditions_ai = [];
        $decoded_conditions = json_decode((string) ($t['possible_conditions_json'] ?? ''), true);
        if (is_array($decoded_conditions)) {
            foreach ($decoded_conditions as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $conditions_ai[] = trim($item);
                } elseif (is_array($item)) {
                    $label = trim((string) (
                        $item['condition']
                        ?? $item['disease']
                        ?? $item['name']
                        ?? $item['term']
                        ?? $item['label']
                        ?? ''
                    ));
                    if ($label !== '') {
                        $conditions_ai[] = $label;
                    }
                }
            }
        }

        $payload = json_decode((string) ($t['assessment_payload'] ?? ''), true);
        if (!is_array($payload)) {
            $payload = [];
        }

        // Fallback: recover interpretation / confidence from full assessment payload.
        if ($conditions_ai === [] && !empty($payload['possible_conditions']) && is_array($payload['possible_conditions'])) {
            foreach ($payload['possible_conditions'] as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $conditions_ai[] = trim($item);
                } elseif (is_array($item)) {
                    $label = trim((string) (
                        $item['condition'] ?? $item['disease'] ?? $item['name'] ?? $item['term'] ?? ''
                    ));
                    if ($label !== '') {
                        $conditions_ai[] = $label;
                    }
                }
            }
        }
        if ($conditions_ai === [] && !empty($payload['ml_layer']['predictions']) && is_array($payload['ml_layer']['predictions'])) {
            foreach ($payload['ml_layer']['predictions'] as $pred) {
                if (!is_array($pred)) {
                    continue;
                }
                $label = trim((string) ($pred['disease'] ?? $pred['condition'] ?? $pred['name'] ?? ''));
                if ($label !== '' && !in_array($label, $conditions_ai, true)) {
                    $conditions_ai[] = $label;
                }
            }
        }
        if ($conditions_ai === [] && !empty($payload['nlp_pipeline']['ml_predictions']) && is_array($payload['nlp_pipeline']['ml_predictions'])) {
            foreach ($payload['nlp_pipeline']['ml_predictions'] as $pred) {
                if (!is_array($pred)) {
                    continue;
                }
                $label = trim((string) ($pred['disease'] ?? $pred['condition'] ?? ''));
                if ($label !== '' && !in_array($label, $conditions_ai, true)) {
                    $conditions_ai[] = $label;
                }
            }
        }
        foreach (['predicted_disease', 'top_disease', 'disease', 'condition'] as $payloadKey) {
            if ($conditions_ai !== []) {
                break;
            }
            $candidate = trim((string) ($payload[$payloadKey] ?? ''));
            if ($candidate !== '') {
                $conditions_ai[] = $candidate;
            }
        }
        if ($conditions_ai === [] && !empty($payload['diseases']) && is_array($payload['diseases'])) {
            foreach ($payload['diseases'] as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $conditions_ai[] = trim($item);
                } elseif (is_array($item)) {
                    $label = trim((string) ($item['disease'] ?? $item['name'] ?? $item['condition'] ?? ''));
                    if ($label !== '') {
                        $conditions_ai[] = $label;
                    }
                }
            }
        }

        $conditions_ai = array_values(array_unique($conditions_ai));

        $confidence = $t['confidence_score'];
        $confidence_display = '';
        if ($confidence !== null && $confidence !== '' && is_numeric($confidence)) {
            $n = (float) $confidence;
            $confidence_display = ($n <= 1 ? (int) round($n * 100) : (int) round($n)) . '%';
        }
        if ($confidence_display === '' && is_array($payload['confidence'] ?? null)) {
            $conf = $payload['confidence'];
            $score = $conf['score'] ?? $conf['score_display'] ?? null;
            if (is_numeric($score)) {
                $n = (float) $score;
                $confidence_display = ($n <= 1 ? (int) round($n * 100) : (int) round($n)) . '%';
                $confidence = $n <= 1 ? (int) round($n * 100) : (int) round($n);
            } elseif (is_string($score) && trim($score) !== '') {
                $confidence_display = trim($score);
                if (!str_contains($confidence_display, '%')) {
                    $confidence_display .= '%';
                }
            }
        }
        if ($confidence_display === '' && isset($payload['confidence_score']) && is_numeric($payload['confidence_score'])) {
            $n = (float) $payload['confidence_score'];
            $confidence_display = ($n <= 1 ? (int) round($n * 100) : (int) round($n)) . '%';
            $confidence = $n <= 1 ? (int) round($n * 100) : (int) round($n);
        }
        // Clinical fallback when score was never persisted.
        if ($confidence_display === '') {
            $base = 40;
            if ($detected_ai !== []) {
                $base += min(25, count($detected_ai) * 8);
            }
            if ($conditions_ai !== []) {
                $base += min(20, count($conditions_ai) * 7);
            }
            if (trim((string) ($t['english_complaint'] ?? '')) !== '' || trim((string) ($t['chief_complaint'] ?? '')) !== '') {
                $base += 10;
            }
            $confidence = max(35, min(92, $base));
            $confidence_display = $confidence . '%';
        }

        if ($conditions_ai === []) {
            $eng = trim((string) ($t['english_complaint'] ?? ''));
            $complaint = trim((string) ($t['chief_complaint'] ?? ''));
            $hint = $eng !== '' ? $eng : $complaint;
            if ($hint !== '') {
                $conditions_ai[] = 'Differential pending clinical review for: ' . $hint;
            } else {
                $conditions_ai[] = 'Insufficient data for differential — clinical review required';
            }
        }

        $triageLevel = strtoupper(trim((string) ($t['triage_level'] ?? $t['triage_classification'] ?? '')));
        $urgencyBucket = ((int) $t['triage'] <= 2) ? 'Urgent' : 'Non-Urgent';
        if ($triageLevel === 'EMERGENCY' || stripos((string) ($t['urgency_label'] ?? ''), 'emergency') !== false) {
            $urgencyBucket = 'Urgent';
        }

        $status  = (string) ($t['status'] ?? 'pending');
        $expired = triage_case_is_expired((string) ($t['assessed_at'] ?? ''));

        $canApprove = (
            trim((string) ($t['chief_complaint'] ?? '')) !== ''
            && trim((string) ($t['recommendations'] ?? '')) !== ''
            && (
                in_array((string) ($t['recommendation_status'] ?? 'hidden'), ['pending_approval', 'rejected'], true)
                || (
                    (string) ($t['recommendation_status'] ?? 'hidden') === 'hidden'
                    && (
                        strtolower((string) ($t['triage_level'] ?? '')) === 'non_urgent'
                        || stripos((string) ($t['urgency_label'] ?? ''), 'non-urgent') !== false
                        || stripos((string) ($t['urgency_label'] ?? ''), 'routine') !== false
                    )
                    && (string) ($t['triage_level'] ?? '') !== 'urgent'
                    && (string) ($t['triage_level'] ?? '') !== 'emergency'
                    && (int) ($t['triage'] ?? 3) >= 3
                )
            )
        );

        $cases[] = [
            'id'                    => (int) $t['id'],
            'patient_id'            => (int) $t['patient_id'],
            'name'                  => trim($t['first_name'] . ' ' . $t['last_name']),
            'symptoms'              => $t['symptoms'],
            'symptoms_list'         => $symptom_list,
            'symptoms_display'      => $symptom_list ? implode(', ', $symptom_list) : '—',
            'complaint'             => trim((string) ($t['chief_complaint'] ?? '')),
            'english_complaint'     => trim((string) ($t['english_complaint'] ?? '')),
            'detected_symptoms_ai'  => $detected_ai,
            'possible_conditions'   => $conditions_ai,
            'suggested_questions'   => triage_assessment_suggested_questions((string) ($t['assessment_payload'] ?? '')),
            'recommendations'       => trim((string) ($t['recommendations'] ?? '')),
            'recommendations_list'  => triage_recommendations_to_list((string) ($t['recommendations'] ?? '')),
            'recommendation_status' => (string) ($t['recommendation_status'] ?? 'hidden'),
            'recommendation_approved_at' => (string) ($t['recommendation_approved_at'] ?? ''),
            'recommendation_patient_ack_at' => (string) ($t['recommendation_patient_ack_at'] ?? ''),
            'can_approve_recommendations' => $canApprove,
            'needs_tips_approval'   => $canApprove,
            'confidence_score'      => $confidence,
            'confidence_display'    => $confidence_display,
            'severity'              => (string) ($t['severity'] ?? ''),
            'triage_level'          => (string) ($t['triage_level'] ?? ''),
            'triage_classification' => (string) ($t['triage_classification'] ?? ''),
            'engine'                => (string) ($t['engine'] ?? ''),
            'level'                 => (string) ($t['triage'] ?? '3'),
            'urgency'               => $urgencyBucket,
            'label'                 => (string) ($t['urgency_label'] ?? ''),
            'assessed_at'           => (string) ($t['assessed_at'] ?? ''),
            'time'                  => date('g:i A', strtotime($t['assessed_at'])),
            'date'                  => date('M j, Y', strtotime($t['assessed_at'])),
            'reviewed'              => ($status !== 'pending'),
            'expired'               => $expired,
            'can_accept'            => triage_case_can_accept((string) ($t['assessed_at'] ?? ''), $status),
            'supporting_evidence'   => complaint_evidence_provider_case_meta(
                $pdo,
                (int) $t['id'],
                (int) $t['patient_id'],
                (string) ($t['assessed_at'] ?? '')
            ),
        ];
    }

    return $cases;
}

/**
 * Active queue: unreviewed cases OR cases still waiting for care-tip approval.
 */
function provider_triage_case_is_active(array $t): bool
{
    return empty($t['reviewed']) || !empty($t['needs_tips_approval']) || !empty($t['can_approve_recommendations']);
}

/**
 * @param list<array<string, mixed>> $cases
 * @return array{total: int, urgent: int, non_urgent: int, reviewed: int, pending: int, tips_pending: int}
 */
function provider_triage_cases_stats(array $cases): array
{
    $urgent     = count(array_filter($cases, fn($t) => ($t['urgency'] ?? '') === 'Urgent'));
    $reviewed   = count(array_filter($cases, fn($t) => !empty($t['reviewed']) && empty($t['needs_tips_approval'])));
    $total      = count($cases);
    $non_urgent = count(array_filter($cases, fn($t) => ($t['urgency'] ?? '') === 'Non-Urgent'));
    $tipsPending = count(array_filter($cases, fn($t) => !empty($t['needs_tips_approval'])));

    return [
        'total'        => $total,
        'urgent'       => $urgent,
        'non_urgent'   => $non_urgent,
        'reviewed'     => $reviewed,
        'pending'      => count(array_filter($cases, fn($t) => empty($t['reviewed']))),
        'tips_pending' => $tipsPending,
    ];
}
