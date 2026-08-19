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
        'doctor_urgency' => '',
        'doctor_urgency_bucket' => '',
        'finalized_by' => '',
        'consultation_id' => $consultationId,
        'patient_id' => $patientId,
    ];

    $original = provider_clinical_support_patient_original($pdo, $consultationId, $patientId);
    $empty['patient_original_complaint'] = $original['complaint'];
    $empty['patient_original_english'] = $original['english'];

    provider_clinical_support_ensure_schema($pdo);

    // Prefer latest clinical-support snapshot for this consultation (symptoms / suggestions).
    // Authoritative AI vs doctor-final overlay is applied before every return.
    try {
        $latest = $pdo->prepare("
            SELECT support_json, created_at, event_type, audit_note, urgency_bucket, urgency_label
            FROM consultation_clinical_support
            WHERE consultation_id = ?
              AND event_type IN ('reassess', 'urgency_override')
            ORDER BY id DESC
            LIMIT 1
        ");
        $latest->execute([$consultationId]);
        $latestRow = $latest->fetch(PDO::FETCH_ASSOC);
        if ($latestRow) {
            $decoded = json_decode((string) ($latestRow['support_json'] ?? ''), true);
            if (is_array($decoded) && !empty($decoded['available'])) {
                $decoded['assessed_at'] = (string) ($latestRow['created_at'] ?? '');
                $decoded['assessed_label'] = $decoded['assessed_at'] !== ''
                    ? date('M j, Y g:i A', strtotime($decoded['assessed_at']))
                    : '';
                if (empty($decoded['patient_original_complaint'])) {
                    $decoded['patient_original_complaint'] = $original['complaint'];
                }
                if (empty($decoded['patient_original_english'])) {
                    $decoded['patient_original_english'] = $original['english'];
                }

                return provider_clinical_support_apply_authoritative_final(
                    $pdo,
                    $consultationId,
                    $patientId,
                    array_merge($empty, $decoded)
                );
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
        return provider_clinical_support_apply_authoritative_final(
            $pdo,
            $consultationId,
            $patientId,
            $empty
        );
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

    $aiKey = triage_ai_preliminary_key($row);
    if ($aiKey === 'unknown') {
        $aiKey = provider_clinical_support_normalize_bucket($bucket);
    }
    $aiCaps = $aiKey !== 'unknown' ? provider_clinical_support_caps_label($aiKey) : '';

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

    $fromTriage = [
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
            'emergency' => 'EMERGENCY',
            'urgent' => 'URGENT',
            'non_urgent', 'routine' => 'NON-URGENT',
            default => $riskLabel,
        },
        'ai_urgency' => $aiCaps !== '' ? $aiCaps : match ($bucket) {
            'emergency' => 'EMERGENCY',
            'urgent' => 'URGENT',
            'non_urgent', 'routine' => 'NON-URGENT',
            default => $riskLabel,
        },
        'ai_urgency_bucket' => $aiKey !== 'unknown' ? $aiKey : $bucket,
        'manual_urgency' => false,
        'manual_override_note' => '',
        'registration_complaint_reference' => $registrationReference,
        'current_complaint_submitted_at' => $currentComplaintSubmittedAt,
    ];

    return provider_clinical_support_apply_authoritative_final(
        $pdo,
        $consultationId,
        $patientId,
        array_merge($empty, $fromTriage)
    );
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
    static $ready = false;
    if ($ready) {
        return;
    }

    $exists = false;
    try {
        $exists = $pdo->query("SHOW TABLES LIKE 'consultation_clinical_support'")->rowCount() > 0;
    } catch (Throwable $e) {
        $exists = false;
    }

    if (!$exists) {
        $pdo->exec("
            CREATE TABLE consultation_clinical_support (
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
    }

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

    $ready = true;
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

function provider_clinical_support_caps_label(string $bucket): string
{
    return match (provider_clinical_support_normalize_bucket($bucket)) {
        'emergency' => 'EMERGENCY',
        'urgent' => 'URGENT',
        'non_urgent' => 'NON-URGENT',
        default => 'Not assessed',
    };
}

/**
 * Linked triage_results.id for this consultation only. Never another visit.
 */
function provider_clinical_support_linked_triage_id(PDO $pdo, int $consultationId, int $patientId): int
{
    if ($consultationId <= 0 || $patientId <= 0) {
        return 0;
    }
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM consultations')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (!in_array('triage_result_id', $cols, true)) {
            return 0;
        }
        $stmt = $pdo->prepare('SELECT triage_result_id, patient_id FROM consultations WHERE id = ? LIMIT 1');
        $stmt->execute([$consultationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int) ($row['patient_id'] ?? 0) !== $patientId) {
            return 0;
        }
        $triageId = (int) ($row['triage_result_id'] ?? 0);
        if ($triageId <= 0) {
            return 0;
        }
        $check = $pdo->prepare('SELECT id FROM triage_results WHERE id = ? AND patient_id = ? LIMIT 1');
        $check->execute([$triageId, $patientId]);

        return (int) ($check->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * @return array{bucket:string,label:string,triage_id:int,row:array<string,mixed>}
 */
function provider_clinical_support_original_ai(PDO $pdo, int $consultationId, int $patientId): array
{
    $out = ['bucket' => 'unknown', 'label' => '', 'triage_id' => 0, 'row' => []];
    $triageId = provider_clinical_support_linked_triage_id($pdo, $consultationId, $patientId);
    if ($triageId <= 0) {
        return $out;
    }
    try {
        $stmt = $pdo->prepare("
            SELECT id, triage_classification, triage_level, urgency_label, level, assessment_payload
            FROM triage_results
            WHERE id = ? AND patient_id = ?
            LIMIT 1
        ");
        $stmt->execute([$triageId, $patientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return $out;
        }
        $bucket = triage_ai_preliminary_key($row);
        $out['triage_id'] = $triageId;
        $out['row'] = $row;
        $out['bucket'] = $bucket !== 'unknown' ? $bucket : 'unknown';
        $out['label'] = $bucket !== 'unknown'
            ? provider_clinical_support_caps_label($bucket)
            : '';
    } catch (Throwable $e) {
        return $out;
    }

    return $out;
}

/**
 * @return array<string, mixed>|null
 */
function provider_clinical_support_latest_override_row(PDO $pdo, int $consultationId): ?array
{
    if ($consultationId <= 0) {
        return null;
    }
    try {
        provider_clinical_support_ensure_schema($pdo);
        $stmt = $pdo->prepare("
            SELECT id, consultation_id, patient_id, urgency_bucket, urgency_label,
                   ai_urgency_bucket, doctor_urgency_bucket, audit_note, support_json, created_at
            FROM consultation_clinical_support
            WHERE consultation_id = ?
              AND event_type = 'urgency_override'
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([$consultationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * IF doctor override is saved → FINAL = doctor override, else FINAL = original AI.
 * Original AI is never overwritten.
 *
 * @param array<string, mixed> $support
 * @return array<string, mixed>
 */
function provider_clinical_support_apply_authoritative_final(
    PDO $pdo,
    int $consultationId,
    int $patientId,
    array $support
): array {
    $support['consultation_id'] = $consultationId;
    $support['patient_id'] = $patientId;

    $ai = provider_clinical_support_original_ai($pdo, $consultationId, $patientId);
    if ($ai['triage_id'] > 0 && empty($support['triage_id'])) {
        $support['triage_id'] = $ai['triage_id'];
    }
    if ($ai['bucket'] !== 'unknown') {
        $support['ai_urgency_bucket'] = $ai['bucket'];
        $support['ai_urgency'] = $ai['label'];
    } else {
        $existingAi = provider_clinical_support_normalize_bucket((string) ($support['ai_urgency_bucket'] ?? ''));
        if ($existingAi !== 'unknown') {
            $support['ai_urgency_bucket'] = $existingAi;
            $support['ai_urgency'] = provider_clinical_support_caps_label($existingAi);
        }
    }

    $override = provider_clinical_support_latest_override_row($pdo, $consultationId);
    if ($override) {
        $doctorBucket = provider_clinical_support_normalize_bucket(
            (string) ($override['doctor_urgency_bucket'] ?? $override['urgency_bucket'] ?? '')
        );
        if ($doctorBucket === 'unknown') {
            $decoded = json_decode((string) ($override['support_json'] ?? ''), true);
            if (is_array($decoded)) {
                $doctorBucket = provider_clinical_support_normalize_bucket(
                    (string) ($decoded['risk_bucket'] ?? $decoded['doctor_urgency_bucket'] ?? '')
                );
            }
        }
        if ($doctorBucket !== 'unknown') {
            $caps = provider_clinical_support_caps_label($doctorBucket);
            $support['available'] = true;
            $support['risk_bucket'] = $doctorBucket;
            $support['final_urgency'] = $caps;
            $support['doctor_urgency'] = $caps;
            $support['doctor_urgency_bucket'] = $doctorBucket;
            $support['manual_urgency'] = true;
            $support['doctor_override'] = true;
            $support['finalized_by'] = 'Doctor';
            $note = trim((string) ($override['audit_note'] ?? ''));
            if ($note !== '') {
                $support['manual_override_note'] = $note;
            }
            $support['risk_level'] = $caps . ' (doctor override)';
            $created = (string) ($override['created_at'] ?? '');
            if ($created !== '') {
                $support['assessed_at'] = $created;
                $support['assessed_label'] = date('M j, Y g:i A', strtotime($created));
            }

            return $support;
        }
    }

    // No saved clinical-support override: use linked triage doctor fields if they differ from AI
    // (legacy update_triage.php path), otherwise AI preliminary is final.
    $aiBucket = provider_clinical_support_normalize_bucket((string) ($support['ai_urgency_bucket'] ?? 'unknown'));
    $triageRow = $ai['row'];
    $doctorFromTriage = 'unknown';
    if ($triageRow !== []) {
        $doctorFromTriage = triage_doctor_final_key($triageRow);
    }
    if ($doctorFromTriage !== 'unknown' && $doctorFromTriage !== $aiBucket) {
        $caps = provider_clinical_support_caps_label($doctorFromTriage);
        $support['risk_bucket'] = $doctorFromTriage;
        $support['final_urgency'] = $caps;
        $support['doctor_urgency'] = $caps;
        $support['doctor_urgency_bucket'] = $doctorFromTriage;
        $support['manual_urgency'] = true;
        $support['doctor_override'] = true;
        $support['finalized_by'] = 'Doctor';
        $support['risk_level'] = $caps . ' (doctor override)';

        return $support;
    }

    if ($aiBucket !== 'unknown') {
        $caps = provider_clinical_support_caps_label($aiBucket);
        $support['risk_bucket'] = $aiBucket;
        $support['final_urgency'] = $caps;
        $support['doctor_urgency'] = '';
        $support['doctor_urgency_bucket'] = '';
        $support['manual_urgency'] = false;
        $support['doctor_override'] = false;
        $support['finalized_by'] = '';
        $support['risk_level'] = $caps;
    }

    return $support;
}

/**
 * @return array<string, string>|null
 */
function provider_clinical_support_patient_location_snapshot(PDO $pdo, int $patientId): ?array
{
    if ($patientId <= 0) {
        return null;
    }
    try {
        $stmt = $pdo->prepare('
            SELECT latitude, longitude, barangay, city_municipality, province
            FROM patient_locations
            WHERE patient_id = ?
            LIMIT 1
        ');
        $stmt->execute([$patientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * @param array<string, mixed>|null $before
 * @param array<string, mixed>|null $after
 */
function provider_clinical_support_location_unchanged(?array $before, ?array $after): bool
{
    if ($before === null && $after === null) {
        return true;
    }
    if ($before === null || $after === null) {
        return false;
    }
    foreach (['latitude', 'longitude', 'barangay', 'city_municipality', 'province'] as $key) {
        if (trim((string) ($before[$key] ?? '')) !== trim((string) ($after[$key] ?? ''))) {
            return false;
        }
    }

    return true;
}

/**
 * Persist doctor override for THIS consultation, sync linked triage_results and GIS status, return DB values.
 *
 * @return array{
 *   support: array<string, mixed>,
 *   persisted: array<string, mixed>,
 *   workflow: array<string, mixed>
 * }
 */
function provider_clinical_support_persist_doctor_override(
    PDO $pdo,
    int $consultationId,
    int $providerId,
    int $patientId,
    string $urgencyBucket,
    string $note,
    string $providerName
): array {
    $urgencyBucket = provider_clinical_support_normalize_bucket($urgencyBucket);
    if (!in_array($urgencyBucket, ['emergency', 'urgent', 'non_urgent'], true)) {
        throw new InvalidArgumentException('Invalid urgency.');
    }

    provider_clinical_support_ensure_schema($pdo);
    triage_assessment_ensure_schema($pdo);

    $support = provider_consultation_clinical_support($pdo, $consultationId, $patientId);
    $linkedTriageId = provider_clinical_support_linked_triage_id($pdo, $consultationId, $patientId);
    if (empty($support['available']) && $linkedTriageId <= 0) {
        throw new RuntimeException('Run AI re-assessment before overriding urgency.');
    }

    $ai = provider_clinical_support_original_ai($pdo, $consultationId, $patientId);
    if ($ai['bucket'] !== 'unknown') {
        $support['ai_urgency_bucket'] = $ai['bucket'];
        $support['ai_urgency'] = $ai['label'];
    } else {
        $existingAi = provider_clinical_support_normalize_bucket((string) ($support['ai_urgency_bucket'] ?? $support['risk_bucket'] ?? ''));
        if ($existingAi !== 'unknown') {
            $support['ai_urgency_bucket'] = $existingAi;
            $support['ai_urgency'] = provider_clinical_support_caps_label($existingAi);
        }
    }

    $caps = provider_clinical_support_caps_label($urgencyBucket);
    $support['risk_bucket'] = $urgencyBucket;
    $support['final_urgency'] = $caps;
    $support['doctor_urgency'] = $caps;
    $support['doctor_urgency_bucket'] = $urgencyBucket;
    $support['risk_level'] = $caps . ' (doctor override)';
    $support['manual_urgency'] = true;
    $support['manual_override_note'] = $note;
    $support['doctor_override'] = true;
    $support['finalized_by'] = 'Doctor';
    $support['available'] = true;
    $support['assessed_at'] = date('Y-m-d H:i:s');
    $support['assessed_label'] = date('M j, Y g:i A');

    $triageId = $linkedTriageId;
    if ($triageId > 0) {
        $support['triage_id'] = $triageId;
    }

    $workflow = [
        'bucket' => $urgencyBucket,
        'emergency_triggered' => false,
        'urgent_triggered' => false,
        'referral_id' => 0,
        'facility' => null,
    ];

    require_once dirname(__DIR__) . '/core/GisDashboardService.php';
    $gisService = new GisDashboardService($pdo);
    $locationBefore = provider_clinical_support_patient_location_snapshot($pdo, $patientId);

    $nestedTxn = $pdo->inTransaction();
    if ($nestedTxn) {
        $pdo->exec('SAVEPOINT mc_doctor_override_save');
    } else {
        $pdo->beginTransaction();
    }
    try {
        provider_clinical_support_save_event(
            $pdo,
            $consultationId,
            $providerId,
            $patientId,
            'urgency_override',
            $support,
            $note,
            $providerName
        );

        if ($triageId > 0) {
            provider_clinical_support_sync_linked_triage(
                $pdo,
                $triageId,
                $patientId,
                $urgencyBucket
            );
        }

        $gisStatus = $gisService->patientGisStatus($patientId);
        if (($gisStatus['bucket'] ?? '') !== $urgencyBucket) {
            throw new RuntimeException(
                'GIS status did not update to the saved doctor result ('
                . provider_clinical_support_caps_label($urgencyBucket)
                . ').'
            );
        }

        $locationAfter = provider_clinical_support_patient_location_snapshot($pdo, $patientId);
        if (!provider_clinical_support_location_unchanged($locationBefore, $locationAfter)) {
            throw new RuntimeException('Urgency override must not change the patient GIS location.');
        }

        if ($nestedTxn) {
            $pdo->exec('RELEASE SAVEPOINT mc_doctor_override_save');
        } else {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($nestedTxn) {
            try {
                $pdo->exec('ROLLBACK TO SAVEPOINT mc_doctor_override_save');
            } catch (Throwable $ignored) {
            }
        } elseif ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $persistedRow = provider_clinical_support_latest_override_row($pdo, $consultationId);
    $persistedBucket = provider_clinical_support_normalize_bucket(
        (string) (($persistedRow['doctor_urgency_bucket'] ?? '') !== ''
            ? $persistedRow['doctor_urgency_bucket']
            : ($persistedRow['urgency_bucket'] ?? $urgencyBucket))
    );
    $persistedNote = trim((string) ($persistedRow['audit_note'] ?? $note));
    $persistedFinal = provider_clinical_support_caps_label($persistedBucket);
    $persistedAi = $ai['bucket'] !== 'unknown'
        ? $ai['label']
        : provider_clinical_support_caps_label((string) ($support['ai_urgency_bucket'] ?? ''));

    if ($triageId > 0) {
        $verify = $pdo->prepare('
            SELECT triage_classification, level, urgency_label, triage_level
            FROM triage_results
            WHERE id = ? AND patient_id = ?
            LIMIT 1
        ');
        $verify->execute([$triageId, $patientId]);
        $triageAfter = $verify->fetch(PDO::FETCH_ASSOC) ?: [];
        $dbFinal = triage_doctor_final_key($triageAfter);
        if ($dbFinal !== 'unknown') {
            $persistedBucket = $dbFinal;
            $persistedFinal = provider_clinical_support_caps_label($dbFinal);
        }
        if (trim((string) ($triageAfter['triage_classification'] ?? '')) !== '') {
            $persistedAi = triage_ai_preliminary_label($triageAfter);
        }
    }

    if ($persistedBucket === 'emergency') {
        $complaint = trim((string) ($support['chief_complaint'] ?? $support['patient_original_complaint'] ?? ''));
        if ($complaint === '') {
            $complaint = $note;
        }
        $emergency = ['triggered' => false, 'referral_id' => 0];
        if ($triageId > 0) {
            $emergency = triage_apply_doctor_emergency_referral(
                $pdo,
                $triageId,
                $patientId,
                $providerId,
                $complaint
            );
        }
        $workflow['emergency_triggered'] = true;
        $workflow['referral_id'] = (int) ($emergency['referral_id'] ?? 0);
        $workflow['facility'] = provider_emergency_nearest_facility($pdo, $patientId);
    } elseif ($persistedBucket === 'urgent') {
        $workflow['urgent_triggered'] = true;
    }

    $reloaded = provider_consultation_clinical_support($pdo, $consultationId, $patientId);
    $gisAfterCommit = $gisService->patientGisStatus($patientId);
    if (($gisAfterCommit['bucket'] ?? '') !== $persistedBucket) {
        throw new RuntimeException(
            'GIS status did not match the saved doctor result ('
            . provider_clinical_support_caps_label($persistedBucket)
            . ').'
        );
    }

    return [
        'support' => $reloaded,
        'persisted' => [
            'consultation_id' => $consultationId,
            'patient_id' => $patientId,
            'triage_id' => $triageId,
            'override_id' => (int) ($persistedRow['id'] ?? 0),
            'ai_bucket' => provider_clinical_support_normalize_bucket((string) ($reloaded['ai_urgency_bucket'] ?? $ai['bucket'])),
            'ai_label' => (string) ($reloaded['ai_urgency'] ?? $persistedAi),
            'doctor_bucket' => $persistedBucket,
            'doctor_label' => $persistedFinal,
            'final_bucket' => $persistedBucket,
            'final_label' => $persistedFinal,
            'clinical_reason' => $persistedNote,
            'finalized_by' => 'Doctor',
            'finalized_at' => (string) ($persistedRow['created_at'] ?? $reloaded['assessed_at'] ?? ''),
            'gis' => $gisAfterCommit,
        ],
        'workflow' => $workflow,
    ];
}

function provider_clinical_support_sync_linked_triage(
    PDO $pdo,
    int $triageId,
    int $patientId,
    string $urgencyBucket
): void {
    $urgencyBucket = provider_clinical_support_normalize_bucket($urgencyBucket);
    if ($triageId <= 0 || $patientId <= 0 || !in_array($urgencyBucket, ['emergency', 'urgent', 'non_urgent'], true)) {
        return;
    }

    require_once dirname(__DIR__) . '/core/TriageLevelService.php';

    $level = match ($urgencyBucket) {
        'emergency' => '1',
        'urgent' => '2',
        default => '3',
    };
    $label = match ($urgencyBucket) {
        'emergency' => 'Emergency',
        'urgent' => 'Urgent',
        default => 'Non-Urgent',
    };
    $gis = $urgencyBucket;

    $meta = $pdo->prepare('SELECT chief_complaint, recommendations, triage_classification FROM triage_results WHERE id = ? AND patient_id = ? LIMIT 1');
    $meta->execute([$triageId, $patientId]);
    $metaRow = $meta->fetch(PDO::FETCH_ASSOC);
    if (!$metaRow) {
        throw new RuntimeException('Linked triage record was not found for this consultation.');
    }

    $pdo->prepare('
        UPDATE triage_results
        SET level = ?, urgency_label = ?, triage_level = ?, assessed_at = NOW()
        WHERE id = ? AND patient_id = ?
    ')->execute([$level, $label, $gis, $triageId, $patientId]);

    $verify = $pdo->prepare('SELECT triage_level FROM triage_results WHERE id = ? AND patient_id = ? LIMIT 1');
    $verify->execute([$triageId, $patientId]);
    $storedGis = provider_clinical_support_normalize_bucket((string) ($verify->fetchColumn() ?: ''));
    if ($storedGis !== $urgencyBucket) {
        throw new RuntimeException('Could not update the linked GIS triage status for this consultation.');
    }

    if ($urgencyBucket !== 'emergency') {
        try {
            $pdo->prepare("
                UPDATE triage_results
                SET outcome = NULL
                WHERE id = ? AND patient_id = ? AND outcome = 'emergency_referral'
            ")->execute([$triageId, $patientId]);
        } catch (PDOException $e) {
            // optional column
        }
    }

    $recStatus = triage_recommendation_status_for_insert(
        $gis,
        (string) ($metaRow['chief_complaint'] ?? ''),
        (string) ($metaRow['recommendations'] ?? ''),
        (string) ($metaRow['triage_classification'] ?? '')
    );
    try {
        $pdo->prepare("
            UPDATE triage_results
            SET recommendation_status = ?,
                recommendation_approved_by = NULL,
                recommendation_approved_at = NULL,
                recommendation_patient_ack_at = NULL
            WHERE id = ? AND patient_id = ?
        ")->execute([$recStatus, $triageId, $patientId]);
    } catch (PDOException $e) {
        // optional columns
    }
}

function provider_clinical_support_haversine_km(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $earth = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

    return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

/**
 * Nearest registered emergency-capable facility using existing GIS / facilities data.
 *
 * @return array<string, mixed>
 */
function provider_emergency_nearest_facility(PDO $pdo, int $patientId): array
{
    $empty = [
        'available' => false,
        'location_available' => false,
        'claimed_nearest' => false,
        'message' => 'Location unavailable',
        'facility' => null,
        'directory' => [],
        'patient' => [
            'latitude' => null,
            'longitude' => null,
            'source' => 'unavailable',
            'address' => '',
        ],
    ];

    if ($patientId <= 0) {
        return $empty;
    }

    $row = [
        'latitude' => null,
        'longitude' => null,
        'location_source' => '',
        'barangay' => '',
        'pr_barangay' => '',
        'pl_barangay' => '',
        'city_municipality' => '',
        'address' => '',
        'full_address' => '',
    ];
    try {
        $locSql = '
            SELECT
                u.id AS patient_id,
                pl.latitude,
                pl.longitude,
                pl.location_source,
                pl.barangay AS pl_barangay,
                pr.barangay AS pr_barangay,
                pr.barangay,
                pr.city_municipality,
                COALESCE(pr.full_address, pr.address, \'\') AS full_address,
                COALESCE(pr.full_address, pr.address, \'\') AS address
            FROM users u
            LEFT JOIN patient_locations pl ON pl.patient_id = u.id
            LEFT JOIN patient_registrations pr ON pr.user_id = u.id
            WHERE u.id = ?
            LIMIT 1
        ';
        $hasPl = $pdo->query("SHOW TABLES LIKE 'patient_locations'")->rowCount() > 0;
        $hasPr = $pdo->query("SHOW TABLES LIKE 'patient_registrations'")->rowCount() > 0;
        if (!$hasPl) {
            $locSql = '
                SELECT
                    u.id AS patient_id,
                    NULL AS latitude,
                    NULL AS longitude,
                    \'\' AS location_source,
                    \'\' AS pl_barangay,
                    pr.barangay AS pr_barangay,
                    pr.barangay,
                    pr.city_municipality,
                    COALESCE(pr.full_address, pr.address, \'\') AS full_address,
                    COALESCE(pr.full_address, pr.address, \'\') AS address
                FROM users u
                LEFT JOIN patient_registrations pr ON pr.user_id = u.id
                WHERE u.id = ?
                LIMIT 1
            ';
        }
        if (!$hasPr && !$hasPl) {
            $locStmt = $pdo->prepare('SELECT id AS patient_id FROM users WHERE id = ? LIMIT 1');
            $locStmt->execute([$patientId]);
        } else {
            $locStmt = $pdo->prepare($locSql);
            $locStmt->execute([$patientId]);
        }
        $fetched = $locStmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($fetched)) {
            $row = array_merge($row, $fetched);
        }
    } catch (Throwable $e) {
        // keep empty location
    }

    $resolved = [
        'latitude' => null,
        'longitude' => null,
        'location_source' => 'unavailable',
        'display_address' => trim((string) ($row['full_address'] ?? $row['address'] ?? '')),
        'has_map_marker' => false,
    ];
    try {
        require_once dirname(__DIR__) . '/core/PatientLocationResolver.php';
        $resolver = new PatientLocationResolver($pdo);
        $resolved = array_merge($resolved, $resolver->resolve($row, false));
    } catch (Throwable $e) {
        // resolver optional
    }

    $pLat = isset($resolved['latitude']) && is_numeric($resolved['latitude']) ? (float) $resolved['latitude'] : null;
    $pLng = isset($resolved['longitude']) && is_numeric($resolved['longitude']) ? (float) $resolved['longitude'] : null;
    $hasPatientCoords = $pLat !== null && $pLng !== null && !($pLat == 0.0 && $pLng == 0.0);

    $facilities = [];
    try {
        $table = $pdo->query("SHOW TABLES LIKE 'facilities'");
        if ($table && $table->rowCount() > 0) {
            $facilities = $pdo->query("
                SELECT id, facility_name, facility_type, address, contact_number, latitude, longitude, status
                FROM facilities
                WHERE status = 'active'
                ORDER BY facility_name
            ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    } catch (Throwable $e) {
        $facilities = [];
    }

    $scored = [];
    foreach ($facilities as $facility) {
        $fLat = isset($facility['latitude']) && $facility['latitude'] !== '' && $facility['latitude'] !== null
            ? (float) $facility['latitude'] : null;
        $fLng = isset($facility['longitude']) && $facility['longitude'] !== '' && $facility['longitude'] !== null
            ? (float) $facility['longitude'] : null;
        $hasCoords = $fLat !== null && $fLng !== null && !($fLat == 0.0 && $fLng == 0.0);
        $type = strtolower(trim((string) ($facility['facility_type'] ?? '')));
        $priority = match (true) {
            $type === 'hospital' => 0,
            $type === 'clinic' => 1,
            default => 2,
        };
        $km = null;
        if ($hasPatientCoords && $hasCoords) {
            $km = provider_clinical_support_haversine_km($pLat, $pLng, $fLat, $fLng);
        }
        $scored[] = [
            'id' => (int) ($facility['id'] ?? 0),
            'name' => trim((string) ($facility['facility_name'] ?? '')),
            'type' => trim((string) ($facility['facility_type'] ?? '')),
            'address' => trim((string) ($facility['address'] ?? '')),
            'contact' => trim((string) ($facility['contact_number'] ?? '')),
            'status' => trim((string) ($facility['status'] ?? 'active')),
            'latitude' => $hasCoords ? $fLat : null,
            'longitude' => $hasCoords ? $fLng : null,
            'distance_km' => $km,
            'distance_label' => $km !== null ? (round($km, 1) . ' km') : '',
            'emergency_capable' => $priority <= 1,
            'priority' => $priority,
            'maps_url' => $hasCoords
                ? ('https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode($fLat . ',' . $fLng))
                : (trim((string) ($facility['address'] ?? '')) !== ''
                    ? ('https://www.google.com/maps/search/?api=1&query=' . rawurlencode((string) $facility['address']))
                    : ''),
        ];
    }

    $empty['patient'] = [
        'latitude' => $hasPatientCoords ? $pLat : null,
        'longitude' => $hasPatientCoords ? $pLng : null,
        'source' => (string) ($resolved['location_source'] ?? 'unavailable'),
        'address' => (string) ($resolved['display_address'] ?? $resolved['address'] ?? ''),
    ];
    $empty['directory'] = array_values(array_filter(
        $scored,
        static fn (array $f): bool => $f['name'] !== '' && $f['emergency_capable']
    ));

    if (!$hasPatientCoords) {
        $empty['message'] = 'Location unavailable';

        return $empty;
    }

    $withDistance = array_values(array_filter(
        $scored,
        static fn (array $f): bool => $f['emergency_capable'] && $f['distance_km'] !== null
    ));
    if ($withDistance === []) {
        $empty['location_available'] = true;
        $empty['message'] = 'No registered emergency-capable facility with map coordinates was found.';

        return $empty;
    }

    usort($withDistance, static function (array $a, array $b): int {
        $p = ((int) $a['priority']) <=> ((int) $b['priority']);
        if ($p !== 0) {
            return $p;
        }

        return ((float) $a['distance_km']) <=> ((float) $b['distance_km']);
    });

    $hospitals = array_values(array_filter($withDistance, static fn (array $f): bool => $f['priority'] === 0));
    $nearest = $hospitals !== [] ? $hospitals[0] : $withDistance[0];

    return [
        'available' => true,
        'location_available' => true,
        'claimed_nearest' => true,
        'message' => '',
        'facility' => $nearest,
        'directory' => $empty['directory'],
        'patient' => $empty['patient'],
    ];
}

/**
 * True when an active video session exists for this consultation (room started).
 * Does not claim the patient is connected — that requires live WebRTC state.
 */
function provider_clinical_support_video_session_active(PDO $pdo, int $consultationId): bool
{
    if ($consultationId <= 0) {
        return false;
    }
    try {
        $stmt = $pdo->prepare("SELECT id FROM video_sessions WHERE consultation_id = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$consultationId]);

        return (int) ($stmt->fetchColumn() ?: 0) > 0;
    } catch (Throwable $e) {
        return false;
    }
}
