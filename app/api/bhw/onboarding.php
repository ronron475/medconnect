<?php
/**
 * Public API: BHW invite activation + self-onboarding.
 * URL: /app/api/bhw/onboarding.php
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/core/BhwApplicationService.php';

$service = new BhwApplicationService($pdo);
$action = $_GET['action'] ?? $_POST['action'] ?? 'get';
$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));

try {
    switch ($action) {
        case 'get':
            $app = $service->findByInviteToken($token);
            if (!$app) {
                echo json_encode(['success' => false, 'message' => 'Invalid or expired link.']);
                break;
            }
            unset($app['password_hash'], $app['invite_token']);
            echo json_encode(['success' => true, 'data' => $app]);
            break;

        case 'activate':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
                break;
            }
            echo json_encode($service->activateInvite(
                $token,
                (string) ($_POST['password'] ?? ''),
                (string) ($_POST['confirm_password'] ?? '')
            ));
            break;

        case 'save':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
                break;
            }
            echo json_encode($service->saveOnboarding($token, $_POST));
            break;

        case 'upload_document':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
                break;
            }
            echo json_encode($service->handleBhwDocumentUpload(
                $token,
                (string) ($_POST['document_type'] ?? ''),
                $_FILES['document'] ?? []
            ));
            break;

        case 'submit':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
                break;
            }
            echo json_encode($service->submitForApproval($token));
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Request failed.']);
}
