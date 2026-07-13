<?php
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/bhw_workflows.php';

function bhw_residency_doc_columns(PDO $pdo): array
{
    static $cols = null;
    if ($cols !== null) {
        return $cols;
    }
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM residency_documents')->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        $cols = [];
    }
    return $cols;
}

function bhw_residency_doc_ensure_metadata(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $cols = bhw_residency_doc_columns($pdo);
    if (!in_array('document_type', $cols, true)) {
        try {
            $pdo->exec('ALTER TABLE residency_documents
                ADD COLUMN document_type VARCHAR(64) NULL AFTER original_name,
                ADD COLUMN document_title VARCHAR(255) NULL AFTER document_type,
                ADD COLUMN description TEXT NULL AFTER document_title');
        } catch (PDOException $e) {
            // Migration may already be applied or table missing optional columns.
        }
    }
    $done = true;
}

function bhw_residency_doc_display_name(array $doc): string
{
    $title = trim((string) ($doc['document_title'] ?? ''));
    if ($title !== '') {
        return $title;
    }
    return (string) ($doc['original_name'] ?? 'Document');
}

function bhw_residency_upload_stats(PDO $pdo, array $ctx): array
{
    [$clause, $params] = bhw_patient_sector_clause($pdo, $ctx, 'pr');
    $sql = "
        SELECT
            SUM(CASE WHEN rd.status IN ('pending', 'needs_review') THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN rd.status IN ('approved', 'verified') THEN 1 ELSE 0 END) AS verified,
            SUM(CASE WHEN rd.status = 'rejected' THEN 1 ELSE 0 END) AS rejected,
            SUM(CASE WHEN DATE(rd.uploaded_at) = CURDATE() THEN 1 ELSE 0 END) AS today
        FROM residency_documents rd
        INNER JOIN users u ON u.id = rd.patient_id
        INNER JOIN patient_registrations pr ON pr.email = u.email
        WHERE u.role = 'patient' AND {$clause}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'pending'  => (int) ($row['pending'] ?? 0),
        'verified' => (int) ($row['verified'] ?? 0),
        'rejected' => (int) ($row['rejected'] ?? 0),
        'today'    => (int) ($row['today'] ?? 0),
    ];
}

const BHW_UPLOAD_MAX_BYTES = 10485760; // 10 MB

$ctx = bhw_api_bootstrap($pdo, ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST');
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

try {
    if ($action === 'list') {
        $patientId = (int) ($_GET['patient_id'] ?? 0);
        if (!bhw_assert_patient_in_sector($pdo, $ctx, $patientId)) {
            Api::error('Patient not in your barangay.', 403);
        }
        $records = [];
        $metaCols = bhw_residency_doc_columns($pdo);
        $select = 'id, original_name, status, uploaded_at, file_size';
        if (in_array('document_type', $metaCols, true)) {
            $select .= ', document_type, document_title, description';
        }
        $s = $pdo->prepare("SELECT {$select} FROM residency_documents WHERE patient_id = ? ORDER BY uploaded_at DESC");
        $s->execute([$patientId]);
        $docs = $s->fetchAll(PDO::FETCH_ASSOC);
        foreach ($docs as &$doc) {
            $doc['display_name'] = bhw_residency_doc_display_name($doc);
        }
        unset($doc);
        $records['documents'] = $docs;
        $s = $pdo->prepare('SELECT * FROM prescriptions WHERE patient_id = ? ORDER BY created_at DESC LIMIT 20');
        $s->execute([$patientId]);
        $records['prescriptions'] = $s->fetchAll(PDO::FETCH_ASSOC);
        bhw_audit($pdo, $patientId, 'bhw_records_viewed', 'BHW viewed patient records.');
        Api::success(['records' => $records]);
    } elseif ($action === 'upload_stats') {
        Api::success(['stats' => bhw_residency_upload_stats($pdo, $ctx)]);
    } elseif ($action === 'upload') {
        bhw_residency_doc_ensure_metadata($pdo);
        $patientId = (int) ($_POST['patient_id'] ?? 0);
        if (!bhw_assert_patient_in_sector($pdo, $ctx, $patientId)) {
            Api::error('Patient not in your barangay.', 403);
        }
        $documentType = trim((string) ($_POST['document_type'] ?? ''));
        $documentTitle = trim((string) ($_POST['document_title'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        if ($documentType === '') {
            Api::error('Please select a document type.');
        }
        if ($documentTitle === '') {
            Api::error('Please enter a document title.');
        }
        if (empty($_FILES['document']['tmp_name'])) {
            Api::error('No file uploaded.');
        }
        $file = $_FILES['document'];
        if (!empty($file['error']) && (int) $file['error'] !== UPLOAD_ERR_OK) {
            Api::error('File upload failed. Please try again.');
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) {
            Api::error('Uploaded file is empty.');
        }
        if ($size > BHW_UPLOAD_MAX_BYTES) {
            Api::error('File exceeds the 10 MB limit.');
        }
        $orig = basename((string) ($file['name'] ?? 'document'));
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        $allowedExt = ['pdf', 'jpg', 'jpeg', 'png'];
        if (!in_array($ext, $allowedExt, true)) {
            Api::error('Invalid file type. Accepted formats: PDF, JPG, PNG.');
        }
        $mimeMap = [
            'pdf'  => 'application/pdf',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
        ];
        $detectedMime = $mimeMap[$ext];
        $dir = BASE_PATH . '/storage/residency';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $stored = 'bhw_' . $patientId . '_' . time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $orig);
        $dest = $dir . '/' . $stored;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            Api::error('Upload failed.');
        }
        $metaCols = bhw_residency_doc_columns($pdo);
        $hasMeta = in_array('document_type', $metaCols, true);
        if ($hasMeta) {
            $pdo->prepare('INSERT INTO residency_documents (patient_id, file_name, original_name, document_type, document_title, description, file_size, mime_type, status, uploaded_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())')
                ->execute([$patientId, $stored, $orig, $documentType, $documentTitle, $description !== '' ? $description : null, $size, $detectedMime, 'pending']);
        } else {
            $pdo->prepare('INSERT INTO residency_documents (patient_id, file_name, original_name, file_size, mime_type, status, uploaded_at) VALUES (?,?,?,?,?,?,NOW())')
                ->execute([$patientId, $stored, $documentTitle, $size, $detectedMime, 'pending']);
        }
        bhw_audit($pdo, $patientId, 'bhw_document_uploaded', 'BHW uploaded document.', [
            'file'            => $orig,
            'document_type'   => $documentType,
            'document_title'  => $documentTitle,
            'description'     => $description,
        ]);
        Api::success(['stats' => bhw_residency_upload_stats($pdo, $ctx)], 'Document uploaded successfully.');
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
