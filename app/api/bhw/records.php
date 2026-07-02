<?php
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/bhw_workflows.php';

$ctx = bhw_api_bootstrap($pdo, ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST');
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

try {
    if ($action === 'list') {
        $patientId = (int) ($_GET['patient_id'] ?? 0);
        if (!bhw_assert_patient_in_sector($pdo, $ctx, $patientId)) {
            Api::error('Patient not in your barangay.', 403);
        }
        $records = [];
        $s = $pdo->prepare('SELECT id, original_name, status, uploaded_at FROM residency_documents WHERE patient_id = ? ORDER BY uploaded_at DESC');
        $s->execute([$patientId]);
        $records['documents'] = $s->fetchAll(PDO::FETCH_ASSOC);
        $s = $pdo->prepare('SELECT * FROM prescriptions WHERE patient_id = ? ORDER BY created_at DESC LIMIT 20');
        $s->execute([$patientId]);
        $records['prescriptions'] = $s->fetchAll(PDO::FETCH_ASSOC);
        bhw_audit($pdo, $patientId, 'bhw_records_viewed', 'BHW viewed patient records.');
        Api::success(['records' => $records]);
    } elseif ($action === 'upload') {
        $patientId = (int) ($_POST['patient_id'] ?? 0);
        if (!bhw_assert_patient_in_sector($pdo, $ctx, $patientId)) {
            Api::error('Patient not in your barangay.', 403);
        }
        if (empty($_FILES['document']['tmp_name'])) {
            Api::error('No file uploaded.');
        }
        $dir = BASE_PATH . '/storage/residency';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $orig = basename($_FILES['document']['name']);
        $stored = 'bhw_' . $patientId . '_' . time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $orig);
        $dest = $dir . '/' . $stored;
        if (!move_uploaded_file($_FILES['document']['tmp_name'], $dest)) {
            Api::error('Upload failed.');
        }
        $pdo->prepare('INSERT INTO residency_documents (patient_id, file_name, original_name, file_size, mime_type, status, uploaded_at) VALUES (?,?,?,?,?,?,NOW())')
            ->execute([$patientId, $stored, $orig, (int) $_FILES['document']['size'], $_FILES['document']['type'] ?? 'application/octet-stream', 'pending']);
        bhw_audit($pdo, $patientId, 'bhw_document_uploaded', 'BHW uploaded document.', ['file' => $orig]);
        Api::success([], 'Document uploaded.');
    } elseif ($action === 'download') {
        $docId = (int) ($_GET['document_id'] ?? 0);
        if ($docId <= 0) {
            Api::error('Invalid document.', 400);
        }
        $s = $pdo->prepare('SELECT patient_id, file_name, original_name, mime_type FROM residency_documents WHERE id = ? LIMIT 1');
        $s->execute([$docId]);
        $doc = $s->fetch(PDO::FETCH_ASSOC);
        if (!$doc) {
            Api::error('Document not found.', 404);
        }
        if (!bhw_assert_patient_in_sector($pdo, $ctx, (int) $doc['patient_id'])) {
            Api::error('Access denied.', 403);
        }
        $path = BASE_PATH . '/storage/residency/' . $doc['file_name'];
        if (!is_file($path)) {
            Api::error('File missing on server.', 404);
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $mime = $doc['mime_type'] ?: 'application/octet-stream';
        $name = $doc['original_name'] ?: basename((string) $doc['file_name']);
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . str_replace('"', '', $name) . '"');
        header('Content-Length: ' . (string) filesize($path));
        readfile($path);
        exit;
    } else {
        Api::error('Unknown action.', 400);
    }
} catch (Throwable $e) {
    Api::error($e->getMessage(), 500);
}
