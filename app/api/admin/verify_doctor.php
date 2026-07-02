<?php
/**
 * API: Verify or reject doctor PRC credentials (Super Administrator only).
 * URL: /app/api/admin/verify_doctor.php
 */
session_start();
header('Content-Type: application/json');

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/provider_verification.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/portal_auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/user_account_status.php';

portal_api_require_superadmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$user_id = (int) ($_POST['user_id'] ?? 0);
$action = strtolower(trim($_POST['action'] ?? ''));
$note = trim($_POST['note'] ?? $_POST['reason'] ?? '');

if ($user_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'User ID is required.']);
    exit;
}

if (!in_array($action, ['verify', 'reject', 'approve'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid action. Use verify or reject.']);
    exit;
}

if ($note === '') {
    echo json_encode(['success' => false, 'message' => 'Please provide a reason for this action.']);
    exit;
}

try {
    provider_verification_ensure_schema($pdo);

    $user_stmt = $pdo->prepare("SELECT id, role, email, first_name, last_name FROM users WHERE id = ? LIMIT 1");
    $user_stmt->execute([$user_id]);
    $user = $user_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || $user['role'] !== 'provider') {
        echo json_encode(['success' => false, 'message' => 'Doctor account not found.']);
        exit;
    }

    $profile_stmt = $pdo->prepare('SELECT id, prc_license_number FROM provider_profiles WHERE user_id = ? LIMIT 1');
    $profile_stmt->execute([$user_id]);
    $profile = $profile_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$profile) {
        echo json_encode(['success' => false, 'message' => 'No PRC license on file for this doctor.']);
        exit;
    }

    $admin_id = (int) $_SESSION['user_id'];
    $statusAction = in_array($action, ['verify', 'approve'], true) ? 'approve' : 'reject';

    $result = user_account_status_change($pdo, $user_id, $statusAction, $note, $admin_id);
    if (!$result['success']) {
        http_response_code(400);
        echo json_encode($result);
        exit;
    }

    $name = trim($user['first_name'] . ' ' . $user['last_name']);
    if ($statusAction === 'approve') {
        $message = "Doctor {$name} verified (PRC {$profile['prc_license_number']}).";
    } else {
        $message = "Doctor {$name} rejected.";
    }

    require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/notification_events.php';
    NotificationEvents::providerVerified($pdo, $user_id, $statusAction === 'approve', $admin_id);

    echo json_encode(['success' => true, 'message' => $message]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
