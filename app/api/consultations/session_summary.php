<?php
/**
 * Patient/provider read-only session metadata after a video call.
 * Uses the authenticated session identity — never a URL patient_id.
 */
ob_start();
session_start();

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/clinical_tables.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/patient_consultation_records.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$uid = (int) ($_SESSION['user_id'] ?? 0);
$role = (string) ($_SESSION['user_role'] ?? '');
if ($uid <= 0 || $role === '') {
    ob_end_clean();
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}

$consultationId = (int) ($_GET['consultation_id'] ?? 0);
if ($consultationId <= 0) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Consultation ID required.']);
    exit;
}

try {
    clinical_tables_ensure($pdo);
    patient_consultation_records_schema_ensure($pdo);

    $stmt = $pdo->prepare("
        SELECT c.id, c.patient_id, c.provider_id, c.provider_name, c.consult_date, c.consult_time,
               c.consult_type, c.status,
               COALESCE(NULLIF(TRIM(c.provider_name), ''), CONCAT(d.first_name, ' ', d.last_name)) AS provider_display
        FROM consultations c
        LEFT JOIN users d ON d.id = c.provider_id
        WHERE c.id = ?
        LIMIT 1
    ");
    $stmt->execute([$consultationId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        ob_end_clean();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Consultation not found.']);
        exit;
    }

    $patientId = (int) ($row['patient_id'] ?? 0);
    $providerId = (int) ($row['provider_id'] ?? 0);
    $allowed = ($role === 'patient' && $uid === $patientId)
        || ($role === 'provider' && $uid === $providerId);
    if (!$allowed) {
        ob_end_clean();
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied.']);
        exit;
    }

    $video = null;
    $vStmt = $pdo->prepare("
        SELECT started_at, ended_at, status
        FROM video_sessions
        WHERE consultation_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $vStmt->execute([$consultationId]);
    $video = $vStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $startedAt = trim((string) ($video['started_at'] ?? ''));
    $endedAt = trim((string) ($video['ended_at'] ?? ''));
    $duration = patient_format_call_duration($startedAt, $endedAt);
    $providerName = patient_provider_display_name((string) ($row['provider_display'] ?? ''));
    $chiefComplaint = $role === 'patient'
        ? patient_session_chief_complaint($pdo, $patientId, $row)
        : trim((string) ($row['consult_type'] ?? ''));

    $consultDate = (string) ($row['consult_date'] ?? '');
    $dateLabel = $consultDate !== '' ? date('F j, Y', strtotime($consultDate)) : '';
    $startLabel = $startedAt !== '' ? date('g:i A', strtotime($startedAt)) : '';
    $endLabel = $endedAt !== '' ? date('g:i A', strtotime($endedAt)) : '';

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'consultation_id' => $consultationId,
        'provider_name' => $providerName,
        'consult_date' => $consultDate,
        'date_label' => $dateLabel,
        'start_label' => $startLabel,
        'end_label' => $endLabel,
        'duration_label' => $duration,
        'status' => (string) ($row['status'] ?? ''),
        'chief_complaint' => $chiefComplaint,
        'detail_url' => patient_consultation_detail_url($consultationId) . '&from=sessions',
    ]);
} catch (PDOException $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not load session summary.']);
}
