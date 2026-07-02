<?php
/**
 * API: Doctor application approvals (Checker — Super Administrator only).
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/portal_auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/core/DoctorApplicationService.php';

portal_api_require_superadmin();

$superAdminId = (int) ($_SESSION['user_id'] ?? 0);
$service = new DoctorApplicationService($pdo);
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

try {
    switch ($action) {
        case 'list':
            echo json_encode([
                'success' => true,
                'data'    => [
                    'applications'  => $service->listPendingForChecker(),
                    'pending_count' => $service->pendingCount(),
                ],
            ]);
            break;

        case 'get':
            $id = (int) ($_GET['id'] ?? 0);
            $app = $service->getApplication($id);
            if (!$app) {
                echo json_encode(['success' => false, 'message' => 'Application not found.']);
                break;
            }
            echo json_encode(['success' => true, 'data' => $app]);
            break;

        case 'download':
            $docId = (int) ($_GET['document_id'] ?? 0);
            $file = $service->getDocumentFile($docId);
            if (!$file) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Document not found.']);
                exit;
            }
            header('Content-Type: ' . $file['mime']);
            header('Content-Disposition: inline; filename="' . basename($file['name']) . '"');
            header('Content-Length: ' . filesize($file['path']));
            readfile($file['path']);
            exit;

        case 'approve':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
                break;
            }
            $appId = (int) ($_POST['application_id'] ?? 0);
            $checklist = [
                'prc_license_verified'            => !empty($_POST['check_prc_verified']),
                'prc_id_matches_applicant'        => !empty($_POST['check_prc_id']),
                'government_id_matches_applicant' => !empty($_POST['check_gov_id']),
                'license_status_active'           => !empty($_POST['check_license_active']),
                'license_not_expired'             => !empty($_POST['check_license_expiry']),
                'profession_physician'            => !empty($_POST['check_profession']),
                'facility_valid'                  => !empty($_POST['check_facility']),
                'email_correct'                   => !empty($_POST['check_email']),
                'no_duplicate_prc'                => !empty($_POST['check_no_dup_prc']),
                'no_duplicate_doctor'             => !empty($_POST['check_no_dup_doctor']),
            ];
            echo json_encode($service->approve($superAdminId, $appId, $checklist));
            break;

        case 'reject':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
                break;
            }
            echo json_encode($service->reject($superAdminId, (int) ($_POST['application_id'] ?? 0), (string) ($_POST['reason'] ?? '')));
            break;

        case 'request_documents':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
                break;
            }
            echo json_encode($service->requestAdditionalDocuments(
                $superAdminId,
                (int) ($_POST['application_id'] ?? 0),
                (string) ($_POST['note'] ?? '')
            ));
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Request failed.']);
}
