<?php
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/bhw_workflows.php';

$ctx = bhw_api_bootstrap($pdo);
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    switch ($action) {
        case 'list':
            $search = trim($_GET['q'] ?? '');
            Api::success(['patients' => BhwWorkflows::listPatients($pdo, $ctx, $search)]);
            break;
        case 'get':
            $id = (int) ($_GET['patient_id'] ?? 0);
            bhw_api_require_patient_in_sector($pdo, $ctx, $id);
            $p = BhwWorkflows::getPatient($pdo, $ctx, $id);
            if (!$p) {
                Api::error('ACCESS DENIED', 403);
            }
            bhw_audit($pdo, $id, 'bhw_patient_viewed', 'BHW viewed patient record.', ['patient_name' => trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''))]);
            Api::success(['patient' => $p]);
            break;
        case 'create':
            Api::error('BHWs cannot register new patients. Patients must complete the main registration flow.', 403);
            break;
        case 'update':
            if ($method !== 'POST') {
                Api::error('Method not allowed.', 405);
            }
            $id = (int) ($_POST['patient_id'] ?? 0);
            bhw_api_require_patient_in_sector($pdo, $ctx, $id);
            BhwWorkflows::updatePatient($pdo, $ctx, $id, $_POST);
            Api::success([], 'Patient contact and medical information updated.');
            break;
        default:
            Api::error('Unknown action.', 400);
    }
} catch (InvalidArgumentException $e) {
    $msg = $e->getMessage();
    $denied = stripos($msg, 'barangay') !== false || stripos($msg, 'ACCESS DENIED') !== false;
    Api::error($denied ? 'ACCESS DENIED' : $msg, $denied ? 403 : 400);
} catch (Throwable $e) {
    Api::error('Operation failed: ' . $e->getMessage(), 500);
}
