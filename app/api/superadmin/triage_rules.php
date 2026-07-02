<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/portal_auth.php';

portal_api_require_admin_portal();
require_once BASE_PATH . '/app/includes/superadmin/security.php';
require_once BASE_PATH . '/app/includes/audit_log.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_POST['action'] ?? $_GET['action'] ?? 'list';
$uid = (int) ($_SESSION['user_id'] ?? 0);

if ($method === 'GET') {
    $rows = $pdo->query('SELECT * FROM triage_rules ORDER BY base_level ASC, symptom_name ASC')
        ->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'rows' => $rows]);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if ($action === 'create') {
    $name = trim($_POST['symptom_name'] ?? '');
    $level = (int) ($_POST['base_level'] ?? 0);
    $weight = (float) ($_POST['weight'] ?? 1);
    $emergency = !empty($_POST['is_emergency']) ? 1 : 0;

    if ($name === '' || $level < 1 || $level > 5) {
        echo json_encode(['success' => false, 'message' => 'Valid symptom name and base level (1–5) are required.']);
        exit;
    }

    $pdo->prepare('INSERT INTO triage_rules (symptom_name, base_level, weight, is_emergency) VALUES (?, ?, ?, ?)')
        ->execute([$name, $level, $weight, $emergency]);

    superadmin_security_log($pdo, 'triage_rule_created', 'ai', 'success', "Created rule: {$name}", $uid);
    echo json_encode(['success' => true, 'message' => 'Triage rule created.']);
    exit;
}

if ($action === 'update') {
    $id = (int) ($_POST['id'] ?? 0);
    $name = trim($_POST['symptom_name'] ?? '');
    $level = (int) ($_POST['base_level'] ?? 0);
    $weight = (float) ($_POST['weight'] ?? 1);
    $emergency = !empty($_POST['is_emergency']) ? 1 : 0;

    if ($id <= 0 || $name === '' || $level < 1 || $level > 5) {
        echo json_encode(['success' => false, 'message' => 'Invalid rule data.']);
        exit;
    }

    $stmt = $pdo->prepare('UPDATE triage_rules SET symptom_name = ?, base_level = ?, weight = ?, is_emergency = ? WHERE id = ?');
    $stmt->execute([$name, $level, $weight, $emergency, $id]);

    if ($stmt->rowCount() === 0) {
        echo json_encode(['success' => false, 'message' => 'Rule not found.']);
        exit;
    }

    superadmin_security_log($pdo, 'triage_rule_updated', 'ai', 'success', "Updated rule #{$id}", $uid);
    echo json_encode(['success' => true, 'message' => 'Triage rule updated.']);
    exit;
}

if ($action === 'delete') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Rule ID required.']);
        exit;
    }
    $pdo->prepare('DELETE FROM triage_rules WHERE id = ?')->execute([$id]);
    superadmin_security_log($pdo, 'triage_rule_deleted', 'ai', 'warning', "Deleted rule #{$id}", $uid);
    echo json_encode(['success' => true, 'message' => 'Triage rule deleted.']);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid request.']);
