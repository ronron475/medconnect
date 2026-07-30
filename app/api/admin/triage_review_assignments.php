<?php
/**
 * Admin API: list and reassign AI self-care review providers on triage cases.
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/api/admin/_auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/triage_assessment_schema.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/patient_symptoms_review_submit.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/auth_guard.php';

triage_assessment_ensure_schema($pdo);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $status = trim($_GET['status'] ?? 'active');
    $where = "tr.recommendation_status IN ('pending_approval', 'approved')";
    if ($status === 'pending') {
        $where = "tr.recommendation_status = 'pending_approval'";
    } elseif ($status === 'approved') {
        $where = "tr.recommendation_status = 'approved'";
    }

    try {
        $stmt = $pdo->query("
            SELECT
                tr.id,
                tr.patient_id,
                tr.chief_complaint,
                tr.recommendation_status,
                tr.triage_level,
                tr.urgency_label,
                tr.assigned_provider_id,
                tr.assigned_at,
                tr.assessed_at,
                tr.recommendation_approved_at,
                CONCAT(pt.first_name, ' ', pt.last_name) AS patient_name,
                CONCAT(rv.first_name, ' ', rv.last_name) AS reviewer_name
            FROM triage_results tr
            JOIN users pt ON pt.id = tr.patient_id
            LEFT JOIN users rv ON rv.id = tr.assigned_provider_id
            WHERE {$where}
              AND tr.assessed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
              AND TRIM(COALESCE(tr.chief_complaint, '')) <> ''
            ORDER BY
              CASE WHEN tr.recommendation_status = 'pending_approval' THEN 0 ELSE 1 END,
              tr.assessed_at DESC
            LIMIT 250
        ");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        $providers = $pdo->query("
            SELECT id, CONCAT(first_name, ' ', last_name) AS name
            FROM users
            WHERE role = 'provider' AND is_active = 1
            ORDER BY first_name, last_name
        ")->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'rows' => $rows,
            'providers' => $providers,
            'timestamp' => date('c'),
        ]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'Could not load AI review assignments.']);
    }
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

auth_csrf_require();

$action = $_POST['action'] ?? '';
if ($action !== 'reassign') {
    echo json_encode(['success' => false, 'message' => 'Invalid action.']);
    exit;
}

$triageId = (int) ($_POST['triage_id'] ?? 0);
$providerId = (int) ($_POST['provider_id'] ?? 0);
$adminId = (int) ($_SESSION['user_id'] ?? 0);

$result = triage_admin_reassign_reviewer($pdo, $triageId, $providerId, $adminId);
if (!$result['ok']) {
    echo json_encode(['success' => false, 'message' => $result['message']]);
    exit;
}

echo json_encode(['success' => true, 'message' => $result['message']]);
