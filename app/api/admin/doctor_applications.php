<?php
/**
 * API: Doctor application workflow (Maker — Administrator).
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/portal_auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/core/DoctorApplicationService.php';

portal_api_require_admin_portal();

$adminId = (int) ($_SESSION['user_id'] ?? 0);
$isSuper = portal_is_superadmin();
$service = new DoctorApplicationService($pdo);
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

try {
    switch ($action) {
        case 'list':
            echo json_encode([
                'success' => true,
                'data'    => [
                    'applications' => $service->listForAdmin($adminId, $isSuper),
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
            if (!$isSuper && (int) $app['created_by'] !== $adminId && (int) ($app['submitted_by'] ?? 0) !== $adminId) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Access denied.']);
                break;
            }
            echo json_encode(['success' => true, 'data' => $app]);
            break;

        case 'save_draft':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
                break;
            }
            $appId = (int) ($_POST['application_id'] ?? 0) ?: null;
            $result = $service->saveDraft($adminId, $_POST, $appId);
            echo json_encode($result);
            break;

        case 'submit':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
                break;
            }
            $appId = (int) ($_POST['application_id'] ?? 0);
            if ($appId <= 0) {
                echo json_encode(['success' => false, 'message' => 'Application ID is required.']);
                break;
            }
            echo json_encode($service->submit($adminId, $appId));
            break;

        case 'upload_document':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
                break;
            }
            $appId = (int) ($_POST['application_id'] ?? 0);
            $docType = (string) ($_POST['document_type'] ?? '');
            echo json_encode($service->handleDocumentUpload($adminId, $appId, $docType, $_FILES['document'] ?? []));
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Request failed.']);
}
