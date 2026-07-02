<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/auth_guard.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/announcement_service.php';

require_once dirname(dirname(dirname(__DIR__))) . '/app/api/admin/_auth.php';

$adminId = (int) $_SESSION['user_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && !auth_csrf_validate($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
    exit;
}

AnnouncementService::ensureSchema($pdo);

if ($action === 'list') {
    $type = $_GET['type'] ?? null;
    echo json_encode(['success' => true, 'data' => AnnouncementService::listMedia($pdo, $type)]);
    exit;
}

if ($action === 'upload') {
    $upload = AnnouncementService::handleUpload($_FILES['file'] ?? [], 'media');
    if (!$upload['success']) {
        echo json_encode($upload);
        exit;
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file(STORAGE_PATH . '/' . $upload['path']) ?: 'application/octet-stream';
    $size = (int) filesize(STORAGE_PATH . '/' . $upload['path']);
    $mediaId = AnnouncementService::saveMediaRecord($pdo, $upload['path'], $mime, $size, $adminId, trim($_POST['alt_text'] ?? ''));
    $upload['media_id'] = $mediaId;
    echo json_encode($upload);
    exit;
}

if ($action === 'delete') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id < 1) {
        echo json_encode(['success' => false, 'message' => 'Invalid media id.']);
        exit;
    }
    echo json_encode(AnnouncementService::deleteMedia($pdo, $id));
    exit;
}

if ($action === 'update_alt') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id < 1) {
        echo json_encode(['success' => false, 'message' => 'Invalid media id.']);
        exit;
    }
    echo json_encode(AnnouncementService::updateMediaAlt($pdo, $id, trim($_POST['alt_text'] ?? '')));
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action.']);
