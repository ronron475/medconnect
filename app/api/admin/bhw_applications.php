<?php
/**
 * API: BHW application workflow (Administrator — invite + institutional docs).
 */

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/portal_auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/core/BhwApplicationService.php';

portal_api_require_admin_portal();

$adminId = (int) ($_SESSION['user_id'] ?? 0);
$isSuper = portal_is_superadmin();
$service = new BhwApplicationService($pdo);
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

/**
 * Stream a stored BHW document for preview (inline) or download (attachment).
 */
function bhw_admin_stream_document(BhwApplicationService $service, int $adminId, bool $isSuper, int $docId, bool $asAttachment): void
{
    if ($docId <= 0) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Document ID is required.']);
        exit;
    }

    $file = $service->getDocumentFile($docId);
    if (!$file) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Document not found.']);
        exit;
    }

    if (!$service->canAccessDocument($adminId, $isSuper, $docId)) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied.']);
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
        case 'view':
            bhw_admin_stream_document($service, $adminId, $isSuper, (int) ($_GET['document_id'] ?? 0), false);
            break;

        case 'download':
            bhw_admin_stream_document($service, $adminId, $isSuper, (int) ($_GET['document_id'] ?? 0), true);
            break;

        case 'list':
            header('Content-Type: application/json; charset=utf-8');
            $applications = $service->listForAdmin($adminId, $isSuper);
            $stats = $service->statsFromRows($applications);
            echo json_encode([
                'success' => true,
                'data'    => [
                    'applications'  => $applications,
                    'barangays'     => $service->getBarangays(),
                    // Stats derived from the same scoped application rows as the table (single source of truth).
                    'stats'         => $stats,
                    'pending_count' => (int) ($stats['pending'] ?? 0),
                ],
            ]);
            break;

        case 'barangays':
            header('Content-Type: application/json; charset=utf-8');
            $barangays = $service->getBarangays();
            echo json_encode([
                'success' => true,
                'data'    => [
                    'barangays' => $barangays,
                    'count'     => count($barangays),
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
            if (!$isSuper && (int) $app['created_by'] !== $adminId && (int) ($app['submitted_by'] ?? 0) !== $adminId) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Access denied.']);
                break;
            }
            echo json_encode(['success' => true, 'data' => $app]);
            break;

        case 'save_draft':
            header('Content-Type: application/json; charset=utf-8');
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
                break;
            }
            $appId = (int) ($_POST['application_id'] ?? 0) ?: null;
            $result = $service->saveDraft($adminId, $_POST, $appId);
            echo json_encode($result);
            break;

        case 'send_invite':
        case 'submit':
            header('Content-Type: application/json; charset=utf-8');
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
            echo json_encode($service->sendInvite($adminId, $appId));
            break;

        case 'resend_invite':
            header('Content-Type: application/json; charset=utf-8');
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
                break;
            }
            echo json_encode($service->resendInvite($adminId, (int) ($_POST['application_id'] ?? 0)));
            break;

        case 'barangay_summary':
            header('Content-Type: application/json; charset=utf-8');
            $rows = $service->barangaySummary();
            $totals = [
                'total'            => 0,
                'online'           => 0,
                'offline'          => 0,
                'deactivated'      => 0,
                'pending_approval' => 0,
            ];
            foreach ($rows as $row) {
                foreach ($totals as $k => $_) {
                    $totals[$k] += (int) ($row[$k] ?? 0);
                }
            }
            echo json_encode([
                'success' => true,
                'data'    => [
                    'barangays'             => $rows,
                    'totals'                => $totals,
                    'online_window_minutes' => BhwApplicationService::ONLINE_WINDOW_MINUTES,
                    'can_manage_accounts'   => $isSuper,
                    'generated_at'          => date('c'),
                ],
            ]);
            break;

        case 'barangay_detail':
            header('Content-Type: application/json; charset=utf-8');
            $barangayId = (int) ($_GET['barangay_id'] ?? $_POST['barangay_id'] ?? 0);
            if ($barangayId <= 0) {
                echo json_encode(['success' => false, 'message' => 'Barangay ID is required.']);
                break;
            }
            $detail = $service->barangayDetail($barangayId);
            $detail['can_manage_accounts'] = $isSuper;
            $detail['generated_at'] = date('c');
            echo json_encode(['success' => true, 'data' => $detail]);
            break;

        case 'upload_document':
            header('Content-Type: application/json; charset=utf-8');
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
                break;
            }
            $appId = (int) ($_POST['application_id'] ?? 0);
            $docType = (string) ($_POST['document_type'] ?? '');
            echo json_encode($service->handleDocumentUpload($adminId, $appId, $docType, $_FILES['document'] ?? [], 'admin'));
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
