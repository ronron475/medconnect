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
        $active[] = [
            'id'            => (int) $row['id'],
            'status'        => (string) ($row['status'] ?? ''),
            'provider_name' => trim((string) ($row['provider_name'] ?? ''))
                ?: trim('Dr. ' . ($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')),
            'consult_date'  => (string) ($row['consult_date'] ?? ''),
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

    ob_end_clean();
    echo json_encode([
        'success'   => true,
        'active'    => $active,
        'completed' => $completed,
        'server_ts' => time(),
    ]);
} catch (PDOException $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not load record updates.']);
}
