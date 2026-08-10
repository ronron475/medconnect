<?php
/**
 * Provider API: supporting evidence for a triage case (View Details modal).
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/complaint_evidence.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/provider_patient_access.php';

if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'provider') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$providerId = (int) $_SESSION['user_id'];
$triageId = (int) ($_GET['triage_id'] ?? 0);

if ($triageId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid triage case.']);
    exit;
}

try {
    $stmt = $pdo->prepare('
        SELECT patient_id, assessed_at
        FROM triage_results
        WHERE id = ?
        LIMIT 1
    ');
    $stmt->execute([$triageId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Triage case not found.']);
        exit;
    }

    $patientId = (int) ($row['patient_id'] ?? 0);
    $access = provider_patient_assert_access($pdo, $providerId, $patientId);
    if (empty($access['allowed'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied.']);
        exit;
    }

    $assessedAt = (string) ($row['assessed_at'] ?? '');
    $supportingEvidence = complaint_evidence_provider_case_meta(
        $pdo,
        $triageId,
        $patientId,
        $assessedAt
    );

    echo json_encode([
        'success'            => true,
        'triage_id'          => $triageId,
        'supporting_evidence' => $supportingEvidence,
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not load supporting evidence.']);
}
