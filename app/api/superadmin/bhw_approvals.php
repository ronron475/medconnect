<?php
/**
 * API: BHW application approvals (Checker — Super Administrator only).
 */
session_start();

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/portal_auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/core/BhwApplicationService.php';

portal_api_require_superadmin();

$superAdminId = (int) ($_SESSION['user_id'] ?? 0);
$service = new BhwApplicationService($pdo);
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

/**
 * Stream a stored BHW document for preview (inline) or download (attachment).
 */
function bhw_approvals_stream_document(BhwApplicationService $service, int $docId, bool $asAttachment): void
{
    $file = $service->getDocumentFile($docId);
    if (!$file) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Document not found.']);
        exit;
    }

    $mime = (string) ($file['mime'] ?: 'application/octet-stream');
    $name = basename((string) $file['name']);
    $safeName = str_replace(['"', "\r", "\n"], '', $name);
    $disposition = $asAttachment ? 'attachment' : 'inline';

    header('Content-Type: ' . $mime);
    header('Content-Disposition: ' . $disposition . '; filename="' . $safeName . '"');
    header('Content-Length: ' . (string) filesize($file['path']));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($file['path']);
    exit;
}

try {
    switch ($action) {
        case 'list':
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => true,
                'data'    => [
                    'applications'  => $service->listPendingForChecker(),
                    'pending_count' => $service->pendingCount(),
                ],
            ]);
            break;

        case 'get':
            header('Content-Type: application/json; charset=utf-8');
            $id = (int) ($_GET['id'] ?? 0);
            $app = $service->getApplication($id);
            if (!$app) {
                echo json_encode(['success' => false, 'message' => 'Application not found.']);
                break;
            }
            echo json_encode(['success' => true, 'data' => $app]);
            break;

        case 'view':
            bhw_approvals_stream_document($service, (int) ($_GET['document_id'] ?? 0), false);
            break;

        case 'download':
            bhw_approvals_stream_document($service, (int) ($_GET['document_id'] ?? 0), true);
            break;

        case 'approve':
            header('Content-Type: application/json; charset=utf-8');
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
                break;
            }
            $appId = (int) ($_POST['application_id'] ?? 0);
            $checklist = [
                'identity_verified'             => !empty($_POST['check_identity']),
                'barangay_assignment_confirmed' => !empty($_POST['check_barangay']),
                'appointment_letter_verified'   => !empty($_POST['check_appointment']),
                'government_id_verified'        => !empty($_POST['check_government_id']),
                'cho_endorsement_verified'      => !empty($_POST['check_cho']),
                'no_duplicate_record'           => !empty($_POST['check_no_duplicate']),
            ];
            echo json_encode($service->approve($superAdminId, $appId, $checklist));
            break;

        case 'reject':
            header('Content-Type: application/json; charset=utf-8');
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
                break;
            }
            echo json_encode($service->reject($superAdminId, (int) ($_POST['application_id'] ?? 0), (string) ($_POST['reason'] ?? '')));
            break;

        case 'request_documents':
            header('Content-Type: application/json; charset=utf-8');
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
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    }
} catch (Throwable $e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Request failed.']);
}
