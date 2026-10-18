<?php
/**
 * Provider-only: create or resume the live video room for a consultation.
 *
 * The provider starts the room. The patient joins only after that room is active.
 * Ending the room and finalizing the SOAP note stay separate steps.
 */
ob_start();

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/auth_guard.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/clinical_tables.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/consultation_video_lifecycle.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id'])) {
    ob_end_clean();
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

if ((string) ($_SESSION['user_role'] ?? '') !== 'provider') {
    ob_end_clean();
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Only the healthcare provider can start the video consultation. Patients should wait for the Join Call button.',
        'code' => 'provider_start_required',
    ]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    ob_end_clean();
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$csrf = (string) ($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!auth_csrf_validate($csrf)) {
    ob_end_clean();
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid request token.']);
    exit;
}

$consultationId = (int) ($_POST['consultation_id'] ?? 0);
$providerId = (int) $_SESSION['user_id'];

try {
    clinical_tables_ensure($pdo);
    $result = consultation_provider_start_video_session($pdo, $consultationId, $providerId);
    if (!$result['success']) {
        $status = (int) ($result['http_status'] ?? 200);
        if ($status < 400) {
            $status = 200;
        }
        ob_end_clean();
        http_response_code($status);
        echo json_encode([
            'success' => false,
            'message' => (string) $result['message'],
            'code' => (string) ($result['code'] ?? ''),
        ]);
        exit;
    }

    if (!empty($result['created'])) {
        try {
            require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/notification_events.php';
            NotificationEvents::consultationStarting(
                $pdo,
                (int) $result['consultation_id'],
                (int) $result['patient_id'],
                $providerId
            );
        } catch (Throwable $e) {
            error_log('start_video notification: ' . $e->getMessage());
        }
    }

    $token = (string) $result['token'];
    $base = defined('BASE_URL') ? rtrim((string) BASE_URL, '/') : '';
    ob_end_clean();
    echo json_encode([
        'success' => true,
        'token' => $token,
        'url' => $base . '/views/consultation/video_room.php?token=' . $token,
        'message' => (string) $result['message'],
    ]);
} catch (Throwable $e) {
    error_log('open_scheduled_video.php: ' . $e->getMessage());
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not start video session.']);
}
