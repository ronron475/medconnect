<?php
/**
 * API: Record a manual PRC verification decision (Super Administrator only).
 * Does not change account status and does not call the PRC.
 * URL: /app/api/admin/verify_doctor.php
 */
header('Content-Type: application/json');

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/provider_verification.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/portal_auth.php';

portal_api_require_superadmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$user_id = (int) ($_POST['user_id'] ?? 0);
$action = strtolower(trim((string) ($_POST['action'] ?? '')));
$note = trim((string) ($_POST['note'] ?? $_POST['reason'] ?? ''));

if ($user_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'User ID is required.']);
    exit;
}

$status = match ($action) {
    'verify', 'approve' => 'verified',
    'reject' => 'rejected',
    'needs_review' => 'needs_review',
    'pending' => 'pending',
    default => '',
};

if ($status === '') {
    echo json_encode(['success' => false, 'message' => 'Invalid action. Use verify, reject, or needs_review.']);
    exit;
}

try {
    provider_verification_ensure_schema($pdo);
    $result = prc_record_decision($pdo, $user_id, $status, (int) ($_SESSION['user_id'] ?? 0), $note);
    if (!$result['success']) {
        http_response_code(400);
        echo json_encode($result);
        exit;
    }

    require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/audit_log.php';
    audit_log($pdo, [
        'patient_id' => (int) ($_SESSION['user_id'] ?? 0),
        'action_type' => 'prc_verification_decision',
        'description' => (string) $result['message'],
        'meta' => [
            'provider_user_id' => $user_id,
            'status' => $status,
            'verifier_id' => (int) ($_SESSION['user_id'] ?? 0),
        ],
    ]);

    if ($status === 'verified' || $status === 'rejected') {
        require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/notification_events.php';
        NotificationEvents::providerVerified($pdo, $user_id, $status === 'verified', (int) $_SESSION['user_id']);
    }

    echo json_encode($result);
} catch (PDOException $e) {
    error_log('verify_doctor: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not record the PRC decision.']);
}
