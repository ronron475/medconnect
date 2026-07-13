<?php
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/auth_guard.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/provider_patient_access.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/triage_assessment_schema.php';
require_once BASE_PATH . '/app/core/TriageLevelService.php';

if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'provider') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

auth_csrf_require();

$id     = (int)($_POST['id'] ?? 0);
$action = $_POST['action'] ?? '';
$level  = $_POST['level'] ?? '';

if (!$id) {
    echo json_encode(['success' => false, 'message' => 'Triage ID is required.']);
    exit;
}

try {
    // IDOR protection: triage must belong to a patient this provider is allowed to act on.
    $t = $pdo->prepare('SELECT patient_id, assessed_at, status FROM triage_results WHERE id = ? LIMIT 1');
    $t->execute([$id]);
    $triageRow = $t->fetch(PDO::FETCH_ASSOC);
    if (!$triageRow) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Triage record not found.']);
        exit;
    }
    $patientId = (int) ($triageRow['patient_id'] ?? 0);
    if ($patientId <= 0) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Triage record not found.']);
        exit;
    }
    $access = provider_patient_assert_access($pdo, (int) $_SESSION['user_id'], $patientId, 0);
    if (!$access['allowed']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied.']);
        exit;
    }

    triage_assessment_ensure_schema($pdo);

    if ($action === 'accept') {
        if (!triage_case_can_accept((string) ($triageRow['assessed_at'] ?? ''), (string) ($triageRow['status'] ?? ''))) {
            $msg = triage_case_is_expired((string) ($triageRow['assessed_at'] ?? ''))
                ? 'This triage case has expired. Only same-day submissions can be accepted.'
                : 'This triage case cannot be accepted.';
            echo json_encode(['success' => false, 'message' => $msg]);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE triage_results SET status = 'accepted' WHERE id = ? AND status = 'pending'");
        $stmt->execute([$id]);
        
        audit_log($pdo, [
            'patient_id'  => $patientId,
            'action_type' => 'TRIAGE_ACCEPTED',
            'description' => "Provider accepted triage case ID: $id"
        ]);
        
        echo json_encode(['success' => true, 'message' => 'Triage case accepted.']);
    } 
    else if ($action === 'override') {
        if (!in_array((string) $level, ['1', '2', '3', '4', '5'], true)) {
            echo json_encode(['success' => false, 'message' => 'Invalid level.']);
            exit;
        }
        $label = match($level) {
            '1' => 'Urgent (Priority 1)',
            '2' => 'Urgent (Priority 2)',
            '3' => 'Non-Urgent (Priority 3)',
            '4' => 'Routine (Priority 4)',
            '5' => 'Routine (Priority 5)',
            default => 'Routine'
        };
        $triageLevel = TriageLevelService::fromDbLevel((string) $level);

        $stmt = $pdo->prepare("UPDATE triage_results SET level = ?, urgency_label = ?, triage_level = ?, assessed_at = NOW() WHERE id = ?");
        $stmt->execute([$level, $label, $triageLevel, $id]);

        audit_log($pdo, [
            'patient_id'  => $patientId,
            'action_type' => 'TRIAGE_OVERRIDE',
            'description' => "Provider manually overrode triage ID: $id to Level $level ($label)"
        ]);

        echo json_encode(['success' => true, 'message' => 'Priority level updated.']);
    }
    else {
        echo json_encode(['success' => false, 'message' => 'Invalid action.']);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
