<?php
/**
 * Digital referrals — list and status updates (Admin + Super Admin).
 */
header('Content-Type: application/json; charset=utf-8');

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/api/admin/_auth.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $status = trim($_GET['status'] ?? 'all');
    $where = '1=1';
    $params = [];
    if ($status !== '' && $status !== 'all') {
        $where = 'dr.status = ?';
        $params[] = $status;
    }

    try {
        if (!$pdo->query("SHOW TABLES LIKE 'digital_referrals'")->rowCount()) {
            echo json_encode(['success' => true, 'rows' => []]);
            exit;
        }
        $cols = $pdo->query('SHOW COLUMNS FROM digital_referrals')->fetchAll(PDO::FETCH_COLUMN);
        $hasFacilityName = in_array('facility_name', $cols, true);
        $hasDestination = in_array('destination_facility', $cols, true);
        if ($hasFacilityName && $hasDestination) {
            $destExpr = 'COALESCE(NULLIF(TRIM(dr.facility_name), ""), NULLIF(TRIM(dr.destination_facility), ""), "")';
        } elseif ($hasFacilityName) {
            $destExpr = 'COALESCE(dr.facility_name, "")';
        } elseif ($hasDestination) {
            $destExpr = 'COALESCE(dr.destination_facility, "")';
        } else {
            $destExpr = '""';
        }
        $stmt = $pdo->prepare("
            SELECT dr.id, dr.referral_type, dr.reason, dr.status, dr.created_at,
                   {$destExpr} AS facility_name,
                   TRIM(CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(p.last_name, ''))) AS patient_name,
                   TRIM(CONCAT(COALESCE(pr.first_name, ''), ' ', COALESCE(pr.last_name, ''))) AS provider_name
            FROM digital_referrals dr
            LEFT JOIN users p ON p.id = dr.patient_id
            LEFT JOIN users pr ON pr.id = dr.provider_id
            WHERE {$where}
            ORDER BY dr.created_at DESC
            LIMIT 200
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'rows' => $rows, 'timestamp' => date('c')]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'Could not load referrals.']);
    }
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$id = (int) ($_POST['id'] ?? 0);
$action = $_POST['action'] ?? 'update_status';
$status = trim($_POST['status'] ?? '');

if ($id <= 0 || $status === '') {
    echo json_encode(['success' => false, 'message' => 'Referral ID and status required.']);
    exit;
}

$allowed = ['pending', 'accepted', 'completed', 'cancelled', 'rejected'];
if (!in_array($status, $allowed, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid status.']);
    exit;
}

try {
    $stmt = $pdo->prepare('UPDATE digital_referrals SET status = ? WHERE id = ?');
    $stmt->execute([$status, $id]);
    echo json_encode(['success' => true, 'message' => 'Referral updated.']);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Update failed.']);
}
