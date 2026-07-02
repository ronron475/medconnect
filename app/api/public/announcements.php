<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/announcement_service.php';

$action = $_GET['action'] ?? 'list';

try {
    AnnouncementService::ensureSchema($pdo);

    if ($action === 'list') {
        $limit = (int) ($_GET['limit'] ?? 6);
        $offset = (int) ($_GET['offset'] ?? 0);
        $items = AnnouncementService::listPublic($pdo, $limit, $offset);
        echo json_encode([
            'success' => true,
            'data' => [
                'items' => $items,
                'total' => AnnouncementService::countPublic($pdo),
            ],
        ]);
        exit;
    }

    if ($action === 'get') {
        $id = (int) ($_GET['id'] ?? 0);
        $row = AnnouncementService::findPublicById($pdo, $id);
        if (!$row) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Not found.']);
            exit;
        }
        AnnouncementService::incrementViewCount($pdo, $id);
        echo json_encode(['success' => true, 'data' => $row]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
} catch (Throwable $e) {
    error_log('Public announcements API: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error.']);
}
