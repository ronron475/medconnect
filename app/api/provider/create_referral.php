<?php
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/auth_guard.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/provider_patient_access.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'provider') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

if (!auth_csrf_validate($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
    exit;
}

$patient_id      = (int)($_POST['patient_id']      ?? 0);
$consultation_id = (int)($_POST['consultation_id'] ?? 0);
$referral_type   = trim($_POST['referral_type']    ?? '');
$reason          = trim($_POST['reason']           ?? '');
$facility        = trim($_POST['facility_name']    ?? '');
$facility_id     = (int)($_POST['facility_id']    ?? 0);
$provider_id     = (int)$_SESSION['user_id'];

if (!$patient_id || !$referral_type || !$reason) {
    echo json_encode(['success' => false, 'message' => 'Required fields missing.']);
    exit;
}

// IDOR protection: provider must be assigned to consultation/patient.
$access = provider_patient_assert_access($pdo, $provider_id, $patient_id, $consultation_id);
if (!$access['allowed']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => $access['message']]);
    exit;
}

if ($facility_id > 0) {
    try {
        $fs = $pdo->prepare('SELECT facility_name FROM facilities WHERE id = ? AND status = \'active\' LIMIT 1');
        $fs->execute([$facility_id]);
        $facility = (string)($fs->fetchColumn() ?: $facility);
    } catch (PDOException $e) {}
}

try {
    $has_facility_name = (bool)$pdo->query("SHOW COLUMNS FROM digital_referrals LIKE 'facility_name'")->fetch();
    $dest_col = $has_facility_name ? 'facility_name' : 'destination_facility';
    $has_facility_id = (bool)$pdo->query("SHOW COLUMNS FROM digital_referrals LIKE 'facility_id'")->fetch();

    $cols = ['consultation_id', 'patient_id', 'provider_id', 'referral_type', 'reason', $dest_col];
    $vals = ['?', '?', '?', '?', '?', '?'];
    $params = [
        $consultation_id ?: null,
        $patient_id,
        $provider_id,
        $referral_type,
        $reason,
        $facility ?: null,
    ];
    if ($has_facility_id) {
        $cols[] = 'facility_id';
        $vals[] = '?';
        $params[] = $facility_id ?: null;
    }
    $cols[] = 'status';
    $vals[] = "'pending'";

    $sql = 'INSERT INTO digital_referrals (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ')';
    if (!str_contains($sql, 'created_at')) {
        // some schemas use default timestamp only
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $referralId = (int) $pdo->lastInsertId();

    require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/notification_events.php';
    NotificationEvents::referralCreated($pdo, $referralId, $patient_id, $provider_id, $provider_id);
    require_once BASE_PATH . '/app/includes/audit_log.php';
    audit_log($pdo, [
        'patient_id' => $patient_id,
        'action_type' => 'provider_referral_created',
        'description' => 'Provider created referral during consultation.',
        'meta' => ['referral_id' => $referralId, 'provider_id' => $provider_id, 'type' => $referral_type],
    ]);

    echo json_encode(['success' => true, 'message' => 'Referral created successfully.', 'referral_id' => $referralId]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
