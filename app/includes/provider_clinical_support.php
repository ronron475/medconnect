<?php

/**
 * Clinical Support Panel data for provider video consultations.
 * Decision support only — final diagnosis and treatment remain with the doctor.
 */

require_once __DIR__ . '/triage_assessment_schema.php';
require_once __DIR__ . '/triage_provider_assignment.php';
require_once __DIR__ . '/patient_chief_complaints.php';

/**
 * @return array{
 *   available: bool,
 *   triage_id: int|null,
 *   chief_complaint: string,
 *   english_complaint: string,
 *   symptoms: list<string>,
 *   risk_level: string,
 *   risk_bucket: string,
 *   triage_level: string,
 *   confidence_display: string,
 *   possible_conditions: list<string>,
 *   suggested_questions: list<string>,
 *   recommended_actions: list<string>,
 *   emergency_warning_signs: list<string>,
 *   assessed_at: string,
 *   assessed_label: string
 * }
 */
function provider_consultation_clinical_support(PDO $pdo, int $consultationId, int $patientId): array
{
    triage_assessment_ensure_schema($pdo);

    $empty = [
        'available' => false,
        'triage_id' => null,
        'chief_complaint' => '',
        'english_complaint' => '',
        'patient_original_complaint' => '',
        'patient_original_english' => '',
        'symptoms' => [],
        'risk_level' => 'Not assessed',
        'risk_bucket' => 'unknown',
        'triage_level' => '',
        'confidence_display' => '',
        'possible_conditions' => [],
        'suggested_questions' => [],
        'recommended_actions' => [],
        'emergency_warning_signs' => [],
        'assessed_at' => '',
        'assessed_label' => '',
        'doctor_override' => false,
        'final_urgency' => '',
        'ai_urgency' => '',
        'ai_urgency_bucket' => '',
        'manual_urgency' => false,
        'manual_override_note' => '',
        'registration_complaint_reference' => '',
        'current_complaint_submitted_at' => '',
    ];

    $original = provider_clinical_support_patient_original($pdo, $consultationId, $patientId);
    $empty['patient_original_complaint'] = $original['complaint'];
    $empty['patient_original_english'] = $original['english'];

    provider_clinical_support_ensure_schema($pdo);

    // Prefer latest doctor-finalized clinical support for this consultation.
    try {
        $override = $pdo->prepare("
            SELECT support_json, created_at, event_type, audit_note, urgency_bucket, urgency_label
            FROM consultation_clinical_support
            WHERE consultation_id = ?
              AND event_type IN ('reassess', 'urgency_override')
            ORDER BY id DESC
            LIMIT 1
        ");
        $override->execute([$consultationId]);
        $overrideRow = $override->fetch(PDO::FETCH_ASSOC);
        if ($overrideRow) {
            $decoded = json_decode((string) ($overrideRow['support_json'] ?? ''), true);
            if (is_array($decoded) && !empty($decoded['available'])) {
                $decoded['doctor_override'] = true;
                $decoded['assessed_at'] = (string) ($overrideRow['created_at'] ?? '');
                $decoded['assessed_label'] = $decoded['assessed_at'] !== ''
                    ? date('M j, Y g:i A', strtotime($decoded['assessed_at']))
                    : '';
                if (empty($decoded['patient_original_complaint'])) {
                    $decoded['patient_original_complaint'] = $original['complaint'];
                }
                if (empty($decoded['patient_original_english'])) {
                    $decoded['patient_original_english'] = $original['english'];
                }
                return array_merge($empty, $decoded);
            }
        }
    } catch (Throwable $e) {
        // Table may not exist yet.
    }

    $triageId = 0;
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM consultations')->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('triage_result_id', $cols, true)) {
            $link = $pdo->prepare('SELECT triage_result_id FROM consultations WHERE id = ? LIMIT 1');
            $link->execute([$consultationId]);
            $triageId = (int) ($link->fetchColumn() ?: 0);
        }
    } catch (Throwable $e) {
        $triageId = 0;
    }

    $row = null;
    if ($triageId > 0) {
        $stmt = $pdo->prepare("
            SELECT id, level, urgency_label, chief_complaint, symptoms,
                   confidence_score, severity, triage_level, triage_classification,
                   english_complaint, detected_symptoms_json, possible_conditions_json,
                   recommendations, assessment_payload, assessed_at
            FROM triage_results
            WHERE id = ? AND patient_id = ?
            LIMIT 1
        ");
        $stmt->execute([$triageId, $patientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if (!$row) {
        $stmt = $pdo->prepare("
            SELECT id, level, urgency_label, chief_complaint, symptoms,
                   confidence_score, severity, triage_level, triage_classification,
                   english_complaint, detected_symptoms_json, possible_conditions_json,
                   recommendations, assessment_payload, assessed_at
            FROM triage_results
            WHERE patient_id = ?
            ORDER BY assessed_at DESC
            LIMIT 1
        ");
        $stmt->execute([$patientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if (!$row) {
        return $empty;
    }

    $payload = json_decode((string) ($row['assessment_payload'] ?? ''), true);
    if (!is_array($payload)) {
        $payload = [];
    }

    $symptoms = provider_clinical_support_labels(
        (string) ($row['detected_symptoms_json'] ?? ''),
        ['term', 'symptom', 'english', 'name', 'english_term']
    );
    if ($symptoms === []) {
        $raw = trim((string) ($row['symptoms'] ?? ''));
        if ($raw !== '') {
            $parts = preg_split('/\s*,\s*/', $raw) ?: [];
            foreach ($parts as $p) {
                $p = trim((string) $p);
                if ($p !== '') {
                    $symptoms[] = $p;
                }
            }
        }
    }
    if ($symptoms === [] && !empty($payload['detected_symptoms']) && is_array($payload['detected_symptoms'])) {
        foreach ($payload['detected_symptoms'] as $item) {
            if (is_string($item) && trim($item) !== '') {
                $symptoms[] = trim($item);
            } elseif (is_array($item)) {
                $label = trim((string) ($item['english_term'] ?? $item['term'] ?? $item['symptom'] ?? $item['name'] ?? ''));
                if ($label !== '') {
                    $symptoms[] = $label;
                }
            }
        }
    }
    $symptoms = array_values(array_unique($symptoms));

    $conditions = provider_clinical_support_labels(
        (string) ($row['possible_conditions_json'] ?? ''),
        ['condition', 'disease', 'name', 'term', 'label']
    );
    if ($conditions === [] && !empty($payload['possible_conditions']) && is_array($payload['possible_conditions'])) {
        foreach ($payload['possible_conditions'] as $item) {
            if (is_string($item) && trim($item) !== '') {
                $conditions[] = trim($item);
            } elseif (is_array($item)) {
                $label = trim((string) ($item['condition'] ?? $item['disease'] ?? $item['name'] ?? ''));
                if ($label !== '') {
                    $conditions[] = $label;
                }
            }
        }
    }
    if ($conditions === [] && !empty($payload['ml_layer']['predictions']) && is_array($payload['ml_layer']['predictions'])) {
        foreach ($payload['ml_layer']['predictions'] as $pred) {
            if (!is_array($pred)) {
                continue;
            }
            $label = trim((string) ($pred['disease'] ?? $pred['condition'] ?? $pred['name'] ?? ''));
            if ($label !== '' && !in_array($label, $conditions, true)) {
                $conditions[] = $label;
            }
        }
    }
    $conditions = array_values(array_unique($conditions));

    $actions = triage_recommendations_to_list((string) ($row['recommendations'] ?? ''));
    foreach (['recommended_action', 'recommendation', 'recommended_actions'] as $key) {
        if ($actions !== []) {
            break;
        }
        $val = $payload[$key] ?? null;
        if (is_string($val) && trim($val) !== '') {
            $actions = triage_recommendations_to_list($val);
        } elseif (is_array($val)) {
            foreach ($val as $item) {
                $text = is_string($item) ? trim($item) : trim((string) ($item['text'] ?? $item['action'] ?? ''));
                if ($text !== '') {
                    $actions[] = $text;
                }
            }
        }
    }
    $actions = array_values(array_unique($actions));

    $questions = triage_assessment_suggested_questions((string) ($row['assessment_payload'] ?? ''));
    if ($questions === []) {
        $questions = [
            'How long have you had these symptoms?',
            'Have your symptoms worsened or improved since onset?',
            'Any fever, chest pain, difficulty breathing, or other red-flag symptoms?',
        ];
    }

    $warnings = provider_clinical_support_emergency_signs($payload, (string) ($row['triage_level'] ?? ''), (string) ($row['urgency_label'] ?? ''));

    $confidenceDisplay = '';
    $confidence = $row['confidence_score'] ?? null;
    if ($confidence !== null && $confidence !== '' && is_numeric($confidence)) {
        $n = (float) $confidence;
        $confidenceDisplay = ($n <= 1 ? (int) round($n * 100) : (int) round($n)) . '%';
    } elseif (isset($payload['confidence_score']) && is_numeric($payload['confidence_score'])) {
        $n = (float) $payload['confidence_score'];
        $confidenceDisplay = ($n <= 1 ? (int) round($n * 100) : (int) round($n)) . '%';
    } elseif (is_array($payload['confidence'] ?? null) && isset($payload['confidence']['score']) && is_numeric($payload['confidence']['score'])) {
        $n = (float) $payload['confidence']['score'];
        $confidenceDisplay = ($n <= 1 ? (int) round($n * 100) : (int) round($n)) . '%';
    }

    $triageLevel = strtoupper(trim((string) ($row['triage_level'] ?? $row['triage_classification'] ?? '')));
    $riskLabel = trim((string) ($row['urgency_label'] ?? ''));
    if ($riskLabel === '') {
        $riskLabel = trim((string) ($row['triage_classification'] ?? $row['triage_level'] ?? ''));
    }
    if ($riskLabel === '') {
        $level = (int) ($row['level'] ?? 3);
        $riskLabel = $level <= 2 ? 'Urgent' : 'Non-Urgent';
    }

    $bucket = 'routine';
    if ($triageLevel === 'EMERGENCY' || stripos($riskLabel, 'emergency') !== false) {
        $bucket = 'emergency';
    } elseif ((int) ($row['level'] ?? 3) <= 2 || $triageLevel === 'URGENT' || stripos($riskLabel, 'urgent') !== false) {
        $bucket = 'urgent';
    } elseif (stripos($riskLabel, 'non') !== false || $triageLevel === 'NON_URGENT' || $triageLevel === 'NON-URGENT') {
        $bucket = 'non_urgent';
    }

    $assessedAt = (string) ($row['assessed_at'] ?? '');
    $assessedLabel = $assessedAt !== '' ? date('M j, Y g:i A', strtotime($assessedAt)) : '';

    $currentComplaint = trim((string) ($row['chief_complaint'] ?? ''));
    $currentComplaintSubmittedAt = $assessedLabel;
    $registrationReference = '';

    $consultComplaint = patient_chief_complaint_for_consultation($pdo, $consultationId);
    if ($consultComplaint !== null && $consultComplaint['complaint'] !== '') {
        $currentComplaint = $consultComplaint['complaint'];
        $currentComplaintSubmittedAt = $consultComplaint['submitted_label'] !== ''
            ? $consultComplaint['submitted_label']
            : $currentComplaintSubmittedAt;
    }

    try {
        patient_chief_complaints_ensure_schema($pdo);
        $refStmt = $pdo->prepare('
            SELECT registration_reference
            FROM patient_chief_complaints
            WHERE consultation_id = ?
            ORDER BY id DESC
            LIMIT 1
        ');
        $refStmt->execute([$consultationId]);
        $registrationReference = trim((string) ($refStmt->fetchColumn() ?: ''));
    } catch (Throwable $e) {
        $registrationReference = '';
    }
    if ($registrationReference === '') {
        $registrationReference = patient_chief_complaint_registration_reference($pdo, $patientId);
    }

    return [
        'available' => true,
        'triage_id' => (int) $row['id'],
        'chief_complaint' => $currentComplaint,
        'english_complaint' => trim((string) ($row['english_complaint'] ?? '')),
        'patient_original_complaint' => $original['complaint'] !== ''
            ? $original['complaint']
            : trim((string) ($row['chief_complaint'] ?? '')),
        'patient_original_english' => $original['english'] !== ''
            ? $original['english']
            : trim((string) ($row['english_complaint'] ?? '')),
        'symptoms' => $symptoms,
        'risk_level' => $riskLabel,
        'risk_bucket' => $bucket,
        'triage_level' => (string) ($row['triage_level'] ?? ''),
        'confidence_display' => $confidenceDisplay,
        'possible_conditions' => $conditions,
        'suggested_questions' => $questions,
        'recommended_actions' => $actions,
        'emergency_warning_signs' => $warnings,
        'assessed_at' => $assessedAt,
        'assessed_label' => $assessedLabel,
        'doctor_override' => false,
        'final_urgency' => match ($bucket) {
            'emergency' => 'Emergency',
            'urgent' => 'Urgent',
            'non_urgent', 'routine' => 'Non-Urgent',
            default => $riskLabel,
        },
        'ai_urgency' => match ($bucket) {
            'emergency' => 'Emergency',
            'urgent' => 'Urgent',
            'non_urgent', 'routine' => 'Non-Urgent',
            default => $riskLabel,
        },
        'ai_urgency_bucket' => $bucket,
        'manual_urgency' => false,
        'manual_override_note' => '',
        'registration_complaint_reference' => $registrationReference,
        'current_complaint_submitted_at' => $currentComplaintSubmittedAt,
    ];
}

/**
 * Resolve linked triage_result_id for a consultation.
 */
function provider_clinical_support_resolve_triage_id(PDO $pdo, int $consultationId, int $patientId): int
{
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM consultations')->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('triage_result_id', $cols, true)) {
            $link = $pdo->prepare('SELECT triage_result_id FROM consultations WHERE id = ? LIMIT 1');
            $link->execute([$consultationId]);
            $triageId = (int) ($link->fetchColumn() ?: 0);
            if ($triageId > 0) {
                return $triageId;
            }
        }
    } catch (Throwable $e) {
        // ignore
    }

    try {
        $stmt = $pdo->prepare('
            SELECT id
            FROM triage_results
            WHERE patient_id = ?
            ORDER BY assessed_at DESC
            LIMIT 1
        ');
        $stmt->execute([$patientId]);
        return (int) ($stmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Map MedicalAssessmentEngine::assess() output into Clinical Support Panel DTO.
 *
 * @param array<string, mixed> $assessment
 * @return array<string, mixed>
 */
function provider_clinical_support_from_assessment(array $assessment): array
{
    $triage = is_array($assessment['triage'] ?? null) ? $assessment['triage'] : [];
    $gis = strtolower(trim((string) ($triage['gis_triage_level'] ?? '')));
    $classification = strtoupper(trim((string) ($triage['triage_classification'] ?? '')));
    $riskLabel = trim((string) ($assessment['urgency_label'] ?? ($triage['urgency_label'] ?? '')));
    if ($riskLabel === '') {
        $riskLabel = trim((string) ($triage['triage_display'] ?? 'Not assessed'));
    }

    $bucket = 'unknown';
    if ($gis === 'emergency' || $classification === 'EMERGENCY') {
        $bucket = 'emergency';
    } elseif ($gis === 'urgent' || $classification === 'URGENT') {
        $bucket = 'urgent';
    } elseif ($gis === 'non_urgent' || $classification === 'NON_URGENT' || $classification === 'NON-URGENT') {
        $bucket = 'non_urgent';
    } elseif (stripos($riskLabel, 'emergency') !== false) {
        $bucket = 'emergency';
    } elseif (stripos($riskLabel, 'urgent') !== false && stripos($riskLabel, 'non') === false) {
        $bucket = 'urgent';
    } elseif (stripos($riskLabel, 'non') !== false || stripos($riskLabel, 'routine') !== false) {
        $bucket = 'non_urgent';
    }

    $symptoms = [];
    foreach ((array) ($assessment['detected_symptoms'] ?? []) as $item) {
        if (is_string($item) && trim($item) !== '') {
            $symptoms[] = trim($item);
        } elseif (is_array($item)) {
            $label = trim((string) ($item['english'] ?? $item['term'] ?? $item['symptom'] ?? $item['name'] ?? ''));
            if ($label !== '') {
                $symptoms[] = $label;
            }
        }
    }
    $symptoms = array_values(array_unique($symptoms));

    $conditions = [];
    foreach ((array) ($assessment['possible_conditions'] ?? []) as $item) {
        if (is_string($item) && trim($item) !== '') {
            $conditions[] = trim($item);
        } elseif (is_array($item)) {
            $label = trim((string) ($item['condition'] ?? $item['disease'] ?? $item['name'] ?? ''));
            if ($label !== '') {
                $conditions[] = $label;
            }
        }
    }
    $conditions = array_values(array_unique($conditions));

    $actions = [];
    $recs = $assessment['recommendations'] ?? null;
    if (is_array($recs)) {
        foreach ($recs as $item) {
            $text = is_string($item) ? trim($item) : trim((string) ($item['text'] ?? $item['action'] ?? ''));
            if ($text !== '') {
                $actions[] = $text;
            }
        }
    } elseif (is_string($recs) && trim($recs) !== '') {
        $actions = triage_recommendations_to_list($recs);
    }
    $recommendedAction = trim((string) ($assessment['recommended_action'] ?? ($triage['recommended_action'] ?? '')));
    if ($recommendedAction !== '' && !in_array($recommendedAction, $actions, true)) {
        array_unshift($actions, $recommendedAction);
    }
    $actions = array_values(array_unique($actions));

    $questions = [];
    $payloadQuestions = [
        'suggested_questions' => $assessment['suggested_questions'] ?? null,
        'clarifying_questions' => $assessment['clarifying_questions'] ?? null,
    ];
    foreach ($payloadQuestions as $list) {
        if (!is_array($list)) {
            continue;
        }
        foreach ($list as $q) {
            $text = is_string($q) ? trim($q) : trim((string) ($q['text'] ?? $q['question'] ?? ''));
            if ($text !== '') {
                $questions[] = $text;
            }
        }
    }
    $ml = is_array($assessment['ml_layer'] ?? null) ? $assessment['ml_layer'] : [];
    if ($questions === [] && !empty($ml['precautions']) && is_array($ml['precautions'])) {
        foreach ($ml['precautions'] as $p) {
            $text = is_string($p) ? trim($p) : trim((string) ($p['text'] ?? ''));
            if ($text !== '') {
                $questions[] = 'Clarify: ' . $text;
            }
        }
    }
    if ($questions === []) {
        $questions = [
            'How long have you had these symptoms?',
            'Have your symptoms worsened or improved since onset?',
            'Any fever, chest pain, difficulty breathing, or other red-flag symptoms?',
        ];
    }
    $questions = array_values(array_unique($questions));

    $warnings = [];
    $severity = is_array($assessment['severity'] ?? null) ? $assessment['severity'] : [];
    if ($bucket === 'emergency') {
        $warnings[] = 'AI final prediction: EMERGENCY — evaluate immediate transfer / ER needs.';
    }
    foreach (['red_flags', 'warning_signs', 'urgent_cues'] as $key) {
        if (empty($severity[$key]) || !is_array($severity[$key])) {
            continue;
        }
        foreach ($severity[$key] as $item) {
            if (is_string($item) && trim($item) !== '') {
                $warnings[] = trim($item);
            }
        }
    }
    $warnings = array_values(array_unique($warnings));

    $confidenceDisplay = '';
    $confidence = $assessment['confidence'] ?? null;
    if (is_array($confidence)) {
        if (isset($confidence['score']) && is_numeric($confidence['score'])) {
            $n = (float) $confidence['score'];
            $confidenceDisplay = ($n <= 1 ? (int) round($n * 100) : (int) round($n)) . '%';
        } elseif (!empty($confidence['score_display'])) {
            $confidenceDisplay = trim((string) $confidence['score_display']);
        }
    } elseif (is_numeric($confidence)) {
        $n = (float) $confidence;
        $confidenceDisplay = ($n <= 1 ? (int) round($n * 100) : (int) round($n)) . '%';
    }

    $finalUrgency = match ($bucket) {
        'emergency' => 'Emergency',
        'urgent' => 'Urgent',
        'non_urgent', 'routine' => 'Non-Urgent',
        default => $riskLabel !== '' ? $riskLabel : 'Not assessed',
    };

    return [
        'available' => true,
        'triage_id' => null,
        'chief_complaint' => trim((string) ($assessment['chief_complaint'] ?? '')),
        'english_complaint' => trim((string) ($assessment['english_translation'] ?? '')),
        'patient_original_complaint' => '',
        'patient_original_english' => '',
        'symptoms' => $symptoms,
        'risk_level' => $riskLabel,
        'risk_bucket' => $bucket,
        'triage_level' => (string) ($triage['gis_triage_level'] ?? strtolower($classification)),
        'confidence_display' => $confidenceDisplay,
        'possible_conditions' => $conditions,
        'suggested_questions' => $questions,
        'recommended_actions' => $actions,
        'emergency_warning_signs' => $warnings,
        'assessed_at' => date('Y-m-d H:i:s'),
        'assessed_label' => date('M j, Y g:i A'),
        'doctor_override' => true,
        'final_urgency' => $finalUrgency,
        'ai_urgency' => $finalUrgency,
        'ai_urgency_bucket' => $bucket,
        'manual_urgency' => false,
        'manual_override_note' => '',
    ];
}

/**
 * Ensure clinical support audit table/columns exist.
 */
function provider_clinical_support_ensure_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS consultation_clinical_support (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            consultation_id INT UNSIGNED NOT NULL,
            provider_id INT UNSIGNED NOT NULL,
            patient_id INT UNSIGNED NOT NULL,
            event_type VARCHAR(40) NOT NULL DEFAULT 'reassess',
            chief_complaint TEXT NOT NULL,
            urgency_bucket VARCHAR(32) NOT NULL DEFAULT 'unknown',
            urgency_label VARCHAR(120) NOT NULL DEFAULT '',
            ai_urgency_bucket VARCHAR(32) NULL,
            doctor_urgency_bucket VARCHAR(32) NULL,
            audit_note TEXT NULL,
            provider_name VARCHAR(160) NULL,
            support_json JSON NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_ccs_consult (consultation_id),
            KEY idx_ccs_provider (provider_id),
            KEY idx_ccs_event (event_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $cols = $pdo->query('SHOW COLUMNS FROM consultation_clinical_support')->fetchAll(PDO::FETCH_COLUMN);
    $alters = [
        'event_type' => "ALTER TABLE consultation_clinical_support ADD COLUMN event_type VARCHAR(40) NOT NULL DEFAULT 'reassess' AFTER patient_id",
        'ai_urgency_bucket' => 'ALTER TABLE consultation_clinical_support ADD COLUMN ai_urgency_bucket VARCHAR(32) NULL AFTER urgency_label',
        'doctor_urgency_bucket' => 'ALTER TABLE consultation_clinical_support ADD COLUMN doctor_urgency_bucket VARCHAR(32) NULL AFTER ai_urgency_bucket',
        'audit_note' => 'ALTER TABLE consultation_clinical_support ADD COLUMN audit_note TEXT NULL AFTER doctor_urgency_bucket',
        'provider_name' => 'ALTER TABLE consultation_clinical_support ADD COLUMN provider_name VARCHAR(160) NULL AFTER audit_note',
    ];
    foreach ($alters as $col => $sql) {
        if (!in_array($col, $cols, true)) {
            try {
                $pdo->exec($sql);
            } catch (Throwable $e) {
                // Ignore race / already-exists.
            }
        }
    }
}

/**
 * @return array{complaint:string,english:string}
 */
function provider_clinical_support_patient_original(PDO $pdo, int $consultationId, int $patientId): array
{
    $out = ['complaint' => '', 'english' => ''];
    try {
        $triageId = 0;
        $cols = $pdo->query('SHOW COLUMNS FROM consultations')->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('triage_result_id', $cols, true)) {
            $link = $pdo->prepare('SELECT triage_result_id FROM consultations WHERE id = ? LIMIT 1');
            $link->execute([$consultationId]);
            $triageId = (int) ($link->fetchColumn() ?: 0);
        }
        if ($triageId > 0) {
            $stmt = $pdo->prepare('SELECT chief_complaint, english_complaint FROM triage_results WHERE id = ? AND patient_id = ? LIMIT 1');
            $stmt->execute([$triageId, $patientId]);
        } else {
            $stmt = $pdo->prepare('SELECT chief_complaint, english_complaint FROM triage_results WHERE patient_id = ? ORDER BY assessed_at ASC LIMIT 1');
            $stmt->execute([$patientId]);
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $out['complaint'] = trim((string) ($row['chief_complaint'] ?? ''));
            $out['english'] = trim((string) ($row['english_complaint'] ?? ''));
        }
    } catch (Throwable $e) {
        return $out;
    }
    return $out;
}

function provider_clinical_support_urgency_label(string $bucket): string
{
    return match (provider_clinical_support_normalize_bucket($bucket)) {
        'emergency' => 'Emergency',
        'urgent' => 'Urgent',
        'non_urgent' => 'Non-Urgent',
        default => 'Not assessed',
    };
}

function provider_clinical_support_normalize_bucket(string $bucket): string
{
    $b = strtolower(trim($bucket));
    $b = str_replace(['-', ' '], '_', $b);
    if (in_array($b, ['emergency', 'urgent', 'non_urgent'], true)) {
        return $b;
    }
    if ($b === 'routine') {
        return 'non_urgent';
    }
    if (str_contains($b, 'emergency')) {
        return 'emergency';
    }
    if (str_contains($b, 'non')) {
        return 'non_urgent';
    }
    if (str_contains($b, 'urgent')) {
        return 'urgent';
    }
    return 'unknown';
}

/**
 * @param array<string, mixed> $support
 * @return array<string, mixed>
 */
function provider_clinical_support_save_event(
    PDO $pdo,
    int $consultationId,
    int $providerId,
    int $patientId,
    string $eventType,
    array $support,
    string $auditNote = '',
    string $providerName = ''
): array {
    provider_clinical_support_ensure_schema($pdo);

    $bucket = provider_clinical_support_normalize_bucket((string) ($support['risk_bucket'] ?? 'unknown'));
    $label = trim((string) ($support['final_urgency'] ?? $support['risk_level'] ?? ''));
    if ($label === '') {
        $label = provider_clinical_support_urgency_label($bucket);
    }
    $aiBucket = provider_clinical_support_normalize_bucket((string) ($support['ai_urgency_bucket'] ?? $bucket));
    $doctorBucket = !empty($support['manual_urgency'])
        ? $bucket
        : null;

    $ins = $pdo->prepare("
        INSERT INTO consultation_clinical_support
            (consultation_id, provider_id, patient_id, event_type, chief_complaint,
             urgency_bucket, urgency_label, ai_urgency_bucket, doctor_urgency_bucket,
             audit_note, provider_name, support_json)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $ins->execute([
        $consultationId,
        $providerId,
        $patientId,
        $eventType,
        (string) ($support['chief_complaint'] ?? ''),
        $bucket,
        $label,
        $aiBucket !== 'unknown' ? $aiBucket : null,
        $doctorBucket,
        $auditNote !== '' ? $auditNote : null,
        $providerName !== '' ? $providerName : null,
        json_encode($support, JSON_UNESCAPED_UNICODE),
    ]);

    return $support;
}

/**
 * @return list<array<string, mixed>>
 */
function provider_clinical_support_audit_trail(PDO $pdo, int $consultationId): array
{
    provider_clinical_support_ensure_schema($pdo);
    try {
        $stmt = $pdo->prepare("
            SELECT id, event_type, chief_complaint, urgency_bucket, urgency_label,
                   ai_urgency_bucket, doctor_urgency_bucket, audit_note, provider_name, created_at
            FROM consultation_clinical_support
            WHERE consultation_id = ?
            ORDER BY id DESC
            LIMIT 20
        ");
        $stmt->execute([$consultationId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $out = [];
    foreach ($rows as $row) {
        $event = (string) ($row['event_type'] ?? 'reassess');
        $label = match ($event) {
            'urgency_override' => 'Manual urgency override',
            'reassess' => 'AI re-assessment',
            default => ucfirst(str_replace('_', ' ', $event)),
        };
        $created = (string) ($row['created_at'] ?? '');
        $out[] = [
            'id' => (int) ($row['id'] ?? 0),
            'event_type' => $event,
            'event_label' => $label,
            'provider_name' => trim((string) ($row['provider_name'] ?? '')) ?: 'Provider',
            'chief_complaint' => trim((string) ($row['chief_complaint'] ?? '')),
            'urgency_label' => trim((string) ($row['urgency_label'] ?? '')),
            'urgency_bucket' => (string) ($row['urgency_bucket'] ?? ''),
            'ai_urgency' => provider_clinical_support_urgency_label((string) ($row['ai_urgency_bucket'] ?? '')),
            'doctor_urgency' => provider_clinical_support_urgency_label((string) ($row['doctor_urgency_bucket'] ?? ($row['urgency_bucket'] ?? ''))),
            'audit_note' => trim((string) ($row['audit_note'] ?? '')),
            'created_at' => $created,
            'created_label' => $created !== '' ? date('M j, Y g:i A', strtotime($created)) : '',
        ];
    }
    return $out;
}

/**
 * @param list<string> $keys
 * @return list<string>
 */
function provider_clinical_support_labels(string $json, array $keys): array
{
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return [];
    }
    $out = [];
    foreach ($decoded as $item) {
        if (is_string($item) && trim($item) !== '') {
            $out[] = trim($item);
            continue;
        }
        if (!is_array($item)) {
            continue;
        }
        foreach ($keys as $key) {
            $label = trim((string) ($item[$key] ?? ''));
            if ($label !== '') {
                $out[] = $label;
                break;
            }
        }
    }
    return array_values(array_unique($out));
}

/**
 * @return list<string>
 */
function provider_clinical_support_emergency_signs(array $payload, string $triageLevel, string $urgencyLabel): array
{
    $out = [];

    foreach (['emergency_flags', 'warning_signs', 'red_flags', 'urgent_flags'] as $key) {
        $val = $payload[$key] ?? null;
        if (!is_array($val)) {
            continue;
        }
        foreach ($val as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            } elseif (is_array($item)) {
                $label = trim((string) (
                    $item['flag_name']
                    ?? $item['english_pattern']
                    ?? $item['name']
                    ?? $item['text']
                    ?? $item['sign']
                    ?? ''
                ));
                if ($label !== '') {
                    $out[] = $label;
                }
            }
        }
    }

    if (!empty($payload['red_flags_triggered']) && is_array($payload['red_flags_triggered'])) {
        foreach ($payload['red_flags_triggered'] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $label = trim((string) ($item['flag_name'] ?? $item['english_pattern'] ?? $item['name'] ?? ''));
            if ($label !== '') {
                $out[] = $label;
            }
        }
    }

    foreach (['clinical_triage', 'nlp_pipeline', 'triage'] as $nest) {
        $nested = $payload[$nest] ?? null;
        if (!is_array($nested)) {
            continue;
        }
        foreach (['emergency_flags', 'red_flags_triggered', 'warning_signs'] as $key) {
            if (empty($nested[$key]) || !is_array($nested[$key])) {
                continue;
            }
            foreach ($nested[$key] as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $out[] = trim($item);
                } elseif (is_array($item)) {
                    $label = trim((string) ($item['flag_name'] ?? $item['english_pattern'] ?? $item['name'] ?? ''));
                    if ($label !== '') {
                        $out[] = $label;
                    }
                }
            }
        }
    }

    $out = array_values(array_unique($out));

    $isEmergency = strtoupper(trim($triageLevel)) === 'EMERGENCY'
        || stripos($urgencyLabel, 'emergency') !== false;

    if ($out === [] && $isEmergency) {
        $out[] = 'AI classified this case as emergency — evaluate airway, breathing, circulation, and immediate transfer needs.';
    }

    return $out;
}
