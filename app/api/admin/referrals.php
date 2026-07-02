<?php
/**
 * Digital referrals — list and status updates (Admin + Super Admin).
 */
session_start();
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
        $destExpr = in_array('facility_name', $cols, true)
            ? 'COALESCE(dr.facility_name, dr.destination_facility, "")'
            : (in_array('destination_facility', $cols, true) ? 'COALESCE(dr.destination_facility, "")' : '""');
        $stmt = $pdo->prepare("
            SELECT dr.id, dr.referral_type, dr.reason, dr.status, dr.created_at,
                   {$destExpr} AS facility_name,
                   CONCAT(p.first_name,' ',p.last_name) AS patient_name,
                   CONCAT(pr.first_name,' ',pr.last_name) AS provider_name
            FROM digital_referrals dr
            JOIN users p ON p.id = dr.patient_id
            JOIN users pr ON pr.id = dr.provider_id
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
