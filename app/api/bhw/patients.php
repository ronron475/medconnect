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
            $perPage = (int) ($_GET['per_page'] ?? 15);
            if (!empty($_GET['export'])) {
                $perPage = 1000;
            }
            Api::success(BhwWorkflows::listPatientsPage($pdo, $ctx, [
                'q' => trim((string) ($_GET['q'] ?? '')),
                'page' => (int) ($_GET['page'] ?? 1),
                'per_page' => $perPage,
                'status' => trim((string) ($_GET['status'] ?? 'all')),
                'sort' => trim((string) ($_GET['sort'] ?? 'name')),
                'consult_date' => trim((string) ($_GET['consult_date'] ?? '')),
            ]));
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
            if ($method !== 'POST') {
                Api::error('Method not allowed.', 405);
            }
            $created = BhwWorkflows::registerPatient($pdo, $ctx, $_POST);
            Api::success($created, $created['label']);
            break;
        case 'register_status':
            $id = (int) ($_GET['patient_id'] ?? 0);
            Api::success(BhwWorkflows::registrationStatus($pdo, $ctx, $id));
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
    error_log('bhw_patients: ' . $e->getMessage());
    Api::error('Operation failed. Please try again.', 500);
}
