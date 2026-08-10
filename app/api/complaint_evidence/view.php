<?php
/**
 * Auth-gated stream for patient-submitted supporting evidence.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/complaint_evidence.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/auth_guard.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

auth_require_login();

$evidenceId = (int) ($_GET['id'] ?? 0);
if ($evidenceId <= 0) {
    http_response_code(400);
    exit('Invalid request.');
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$role = (string) ($_SESSION['user_role'] ?? '');

$row = complaint_evidence_find_by_id($pdo, $evidenceId);
if ($row === null) {
    http_response_code(404);
    exit('Not found.');
}

if (!complaint_evidence_can_view($pdo, $row, $userId, $role)) {
    http_response_code(403);
    exit('Access denied.');
}

$stored = basename((string) ($row['stored_filename'] ?? ''));
if (!preg_match('/^evidence_\d+_\d+_[a-f0-9]{16}\.(jpe?g|png|webp|mp4|webm)$/i', $stored)) {
    http_response_code(404);
    exit('Not found.');
}

$path = complaint_evidence_storage_dir() . '/' . $stored;
if (!is_file($path) || !complaint_evidence_validate_stored_file($path, $row)) {
    http_response_code(404);
    exit('File missing.');
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

$mime = (string) ($row['mime_type'] ?? 'application/octet-stream');
$name = (string) ($row['original_filename'] ?? $stored);
$name = str_replace(['"', "\r", "\n"], '', $name);

header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . $name . '"');
header('Content-Length: ' . (string) filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');

readfile($path);
exit;
