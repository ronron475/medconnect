<?php
/**
 * Lightweight poll for patient consultation completion / record availability.
 *
 * GET ?since=unix_ts  → completed consultations finalized after timestamp
 * GET (no since)      → active in-consultation + recently completed (24h)
 */
ob_start();
session_start();

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/patient_settings.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/patient_consultation_records.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/triage_assessment_schema.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

patient_settings_require_patient_ready($pdo);
patient_consultation_records_schema_ensure($pdo);

$uid = (int) $_SESSION['user_id'];
$since = (int) ($_GET['since'] ?? 0);

try {
    $active = [];
    $stmt = $pdo->prepare("
        SELECT c.id, c.status, c.consult_date, c.consult_time, c.provider_name,
               u.first_name, u.last_name
        FROM consultations c
        JOIN users u ON u.id = c.provider_id
        WHERE c.patient_id = ?
          AND c.status IN ('pending', 'scheduled', 'in_consultation')
          AND c.status NOT IN ('cancelled', 'canceled')
        ORDER BY c.consult_date DESC, c.consult_time DESC
        LIMIT 5
    ");
    $stmt->execute([$uid]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $cid = (int) $row['id'];
        $outcome = patient_consultation_clinical_outcome($pdo, $cid, $uid, false);
        $active[] = [
            'id'            => $cid,
            'status'        => (string) ($row['status'] ?? ''),
            'provider_name' => trim((string) ($row['provider_name'] ?? ''))
                ?: trim('Dr. ' . ($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')),
            'consult_date'  => (string) ($row['consult_date'] ?? ''),
            'final_case_bucket' => (string) ($outcome['final_case_bucket'] ?? ''),
            'final_case_level'  => (string) ($outcome['final_case_level'] ?? ''),
            'ai_case_level'     => (string) ($outcome['ai_case_level'] ?? ''),
            'finalized_by'      => !empty($outcome['is_doctor_override']) ? 'Doctor' : '',
        ];
    }

    $completedSql = "
        SELECT c.id, c.status, c.consult_date, c.consult_time, c.completed_at,
               c.consult_type, c.diagnosis,
               u.first_name, u.last_name,
               COALESCE(NULLIF(TRIM(c.provider_name), ''), CONCAT(u.first_name, ' ', u.last_name)) AS provider_display,
               cn.signature_data, cn.finalized_at
        FROM consultations c
        JOIN users u ON u.id = c.provider_id
        LEFT JOIN clinical_notes cn ON cn.consultation_id = c.id
        WHERE c.patient_id = ?
          AND c.status = 'completed'
    ";
    $params = [$uid];

    if ($since > 0) {
        $completedSql .= ' AND COALESCE(c.completed_at, cn.finalized_at, cn.created_at) >= FROM_UNIXTIME(?)';
        $params[] = $since;
    } else {
        $completedSql .= ' AND COALESCE(c.completed_at, cn.finalized_at, cn.created_at) >= DATE_SUB(NOW(), INTERVAL 1 DAY)';
    }

    $completedSql .= ' ORDER BY COALESCE(c.completed_at, cn.finalized_at, cn.created_at) DESC LIMIT 10';

    $cStmt = $pdo->prepare($completedSql);
    $cStmt->execute($params);

    $completed = [];
    while ($row = $cStmt->fetch(PDO::FETCH_ASSOC)) {
        if (!patient_consultation_is_finalized((string) ($row['status'] ?? ''), $row['signature_data'] ?? '', $row['finalized_at'] ?? null)) {
            continue;
        }
        $cid = (int) $row['id'];
        $provider = trim((string) ($row['provider_display'] ?? ''));
        if ($provider !== '' && stripos($provider, 'dr.') !== 0) {
            $provider = 'Dr. ' . $provider;
        }
        $outcome = null;
        if (patient_consultation_is_finalized((string) ($row['status'] ?? ''), $row['signature_data'] ?? '', $row['finalized_at'] ?? null)) {
            $outcome = patient_consultation_clinical_outcome($pdo, $cid, $uid, false);
        }
        $completed[] = [
            'id'              => $cid,
            'status'          => 'completed',
            'provider_name'   => $provider,
            'consult_date'    => (string) ($row['consult_date'] ?? ''),
            'consult_type'    => (string) ($row['consult_type'] ?? ''),
            'record_available'=> true,
            'detail_url'      => patient_health_files_url($cid),
            'completed_at'    => (string) ($row['completed_at'] ?? ''),
            'final_case_bucket' => (string) ($outcome['final_case_bucket'] ?? ''),
            'final_case_level'  => (string) ($outcome['final_case_level'] ?? ''),
            'ai_case_level'     => (string) ($outcome['ai_case_level'] ?? ''),
        ];
    }

    $triageUpdates = [];
    try {
        require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/provider_clinical_support.php';
        provider_clinical_support_ensure_schema($pdo);
        $ovStmt = $pdo->prepare("
            SELECT ccs.id AS override_id, ccs.consultation_id, ccs.patient_id, ccs.urgency_bucket,
                   ccs.doctor_urgency_bucket, ccs.ai_urgency_bucket, ccs.audit_note, ccs.created_at,
                   c.id AS consult_id, c.triage_result_id, c.status AS consult_status
            FROM consultation_clinical_support ccs
            INNER JOIN consultations c ON c.id = ccs.consultation_id AND c.patient_id = ccs.patient_id
            WHERE ccs.patient_id = ?
              AND ccs.event_type = 'urgency_override'
              AND ccs.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            ORDER BY ccs.id DESC
            LIMIT 30
        ");
        $ovStmt->execute([$uid]);
        $seenConsult = [];
        while ($row = $ovStmt->fetch(PDO::FETCH_ASSOC)) {
            $cid = (int) ($row['consultation_id'] ?? $row['consult_id'] ?? 0);
            if ($cid <= 0 || isset($seenConsult[$cid])) {
                continue;
            }
            $seenConsult[$cid] = true;
            $outcome = patient_consultation_clinical_outcome($pdo, $cid, $uid, false);
            $finalBucket = provider_clinical_support_normalize_bucket(
                (string) ($outcome['final_case_bucket'] ?? $row['doctor_urgency_bucket'] ?? $row['urgency_bucket'] ?? '')
            );
            if ($finalBucket === 'unknown') {
                continue;
            }
            $aiLabel = (string) ($outcome['ai_case_level'] ?? '');
            if ($aiLabel === '') {
                $aiLabel = provider_clinical_support_caps_label((string) ($row['ai_urgency_bucket'] ?? ''));
            }
            $finalLabel = (string) ($outcome['final_case_level'] ?? provider_clinical_support_caps_label($finalBucket));
            $item = [
                'id' => (int) ($row['override_id'] ?? 0),
                'consultation_id' => $cid,
                'triage_id' => (int) ($row['triage_result_id'] ?? ($outcome['consultation_id'] ?? 0)),
                'ai_label' => $aiLabel,
                'doctor_label' => $finalLabel,
                'final_label' => $finalLabel,
                'final_bucket' => $finalBucket,
                'clinical_reason' => (string) ($row['audit_note'] ?? ''),
                'finalized_by' => 'Doctor',
                'assessed_at' => (string) ($row['created_at'] ?? ''),
                'source' => 'provider_override',
                'emergency' => $finalBucket === 'emergency',
                'urgent' => $finalBucket === 'urgent',
                'facility' => null,
                'message' => $finalBucket === 'emergency'
                    ? 'Your doctor has classified your condition as an EMERGENCY. Please seek immediate in-person medical attention.'
                    : ('Your doctor finalized this consultation as ' . $finalLabel . '.'),
            ];
            if ($finalBucket === 'emergency') {
                $item['facility'] = provider_emergency_nearest_facility($pdo, $uid);
            }
            $triageUpdates[] = $item;
        }
    } catch (Throwable $e) {
        $triageUpdates = [];
    }

    $emergencyOverrides = [];
    foreach ($triageUpdates as $item) {
        if (!empty($item['emergency'])) {
            $emergencyOverrides[] = $item;
        }
    }

    ob_end_clean();
    echo json_encode([
        'success'   => true,
        'active'    => $active,
        'completed' => $completed,
        'triage_updates' => $triageUpdates,
        'emergency_overrides' => $emergencyOverrides,
        'server_ts' => time(),
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not load record updates.']);
}
