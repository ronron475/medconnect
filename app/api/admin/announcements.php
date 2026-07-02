<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/auth_guard.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/announcement_service.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/api/admin/_auth.php';

$adminId = (int) $_SESSION['user_id'];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? $_POST['action'] ?? ($method === 'GET' ? 'list' : 'create');

if ($method !== 'GET' && !auth_csrf_validate($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
    exit;
}

try {
    AnnouncementService::ensureSchema($pdo);

    if ($action === 'list' && $method === 'GET') {
        $result = AnnouncementService::listAdmin($pdo, [
            'status'    => $_GET['status'] ?? '',
            'category'  => $_GET['category'] ?? '',
            'author_id' => $_GET['author_id'] ?? '',
            'is_pinned' => $_GET['is_pinned'] ?? '',
            'audience'  => $_GET['audience'] ?? '',
            'search'    => trim($_GET['search'] ?? ''),
            'date_from' => $_GET['date_from'] ?? '',
            'date_to'   => $_GET['date_to'] ?? '',
            'limit'     => $_GET['limit'] ?? 50,
            'offset'    => $_GET['offset'] ?? 0,
        ]);
        echo json_encode(['success' => true, 'data' => $result]);
        exit;
    }

    if ($action === 'get' && $method === 'GET') {
        $id = (int) ($_GET['id'] ?? 0);
        $row = AnnouncementService::findById($pdo, $id);
        if (!$row) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Not found.']);
            exit;
        }
        echo json_encode(['success' => true, 'data' => $row]);
        exit;
    }

    if ($action === 'meta' && $method === 'GET') {
        echo json_encode([
            'success' => true,
            'data' => [
                'categories' => AnnouncementService::CATEGORIES,
                'audiences'  => AnnouncementService::AUDIENCES,
                'statuses'   => AnnouncementService::STATUSES,
                'barangays'  => AnnouncementService::listBarangays($pdo),
            ],
        ]);
        exit;
    }

    if ($action === 'upload_banner' || $action === 'upload_attachment') {
        $type = $action === 'upload_banner' ? 'banner' : 'attachment';
        $upload = AnnouncementService::handleUpload($_FILES['file'] ?? [], $type);
        if (!$upload['success']) {
            echo json_encode($upload);
            exit;
        }
        echo json_encode($upload);
        exit;
    }

    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'create') {
        $targets = $_POST['target_audience'] ?? $_POST['targets'] ?? [];
        if (!is_array($targets)) {
            $targets = [$targets];
        }
        if (empty($targets)) {
            $targets = ['all'];
        }
        $_POST['target_audience'] = $targets;
        $_POST['barangay_ids'] = $_POST['barangay_ids'] ?? [];
        $result = AnnouncementService::create($pdo, $_POST, $adminId);
        echo json_encode($result);
        exit;
    }

    if ($action === 'update') {
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid ID.']);
            exit;
        }
        $targets = $_POST['target_audience'] ?? $_POST['targets'] ?? null;
        if ($targets !== null && !is_array($targets)) {
            $targets = [$targets];
        }
        if ($targets !== null) {
            $_POST['target_audience'] = $targets;
        }
        $result = AnnouncementService::update($pdo, $id, $_POST, $adminId);
        echo json_encode($result);
        exit;
    }

    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid ID.']);
        exit;
    }

    $result = match ($action) {
        'publish'    => AnnouncementService::publish($pdo, $id, $adminId),
        'unpublish'  => AnnouncementService::unpublish($pdo, $id, $adminId),
        'archive'    => AnnouncementService::archive($pdo, $id, $adminId),
        'restore'    => AnnouncementService::restore($pdo, $id, $adminId),
        'delete'     => AnnouncementService::delete($pdo, $id, $adminId),
        'toggle_pin' => AnnouncementService::togglePin($pdo, $id, $adminId),
        default      => ['success' => false, 'message' => 'Unknown action.'],
    };
    echo json_encode($result);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('Announcements API: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error.']);
}
