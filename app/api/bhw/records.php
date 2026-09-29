<?php
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/bhw_workflows.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/bhw_nav_inbox.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/upload_security.php';

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
    $join = bhw_pr_user_join('pr', 'u');
    $sql = "
        SELECT
            SUM(CASE WHEN rd.status IN ('pending', 'needs_review') THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN rd.status IN ('approved', 'verified') THEN 1 ELSE 0 END) AS verified,
            SUM(CASE WHEN rd.status = 'rejected' THEN 1 ELSE 0 END) AS rejected,
            SUM(CASE WHEN DATE(rd.uploaded_at) = CURDATE() THEN 1 ELSE 0 END) AS today
        FROM residency_documents rd
        INNER JOIN users u ON u.id = rd.patient_id
        INNER JOIN patient_registrations pr ON {$join}
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

function bhw_records_text(array $row, string $key): string
{
    return trim((string) ($row[$key] ?? ''));
}

/**
 * Fields the BHW patient profile already returns. Identity documents,
 * consent, and OCR payloads stay off this view.
 */
function bhw_records_patient_summary(?array $patient): array
{
    if (!$patient) {
        return [];
    }
    $blood = bhw_records_text($patient, 'blood_type');
    if (strcasecmp($blood, 'unknown') === 0) {
        $blood = '';
    }

    return [
        'id' => (int) ($patient['id'] ?? 0),
        'first_name' => bhw_records_text($patient, 'first_name'),
        'middle_name' => bhw_records_text($patient, 'middle_name'),
        'last_name' => bhw_records_text($patient, 'last_name'),
        'suffix' => bhw_records_text($patient, 'suffix'),
        'age' => bhw_records_text($patient, 'age'),
        'gender' => bhw_records_text($patient, 'gender'),
        'date_of_birth' => bhw_records_text($patient, 'date_of_birth'),
        'contact_number' => bhw_records_text($patient, 'contact_number'),
        'email' => bhw_records_text($patient, 'email'),
        'barangay' => bhw_records_text($patient, 'barangay'),
        'purok' => bhw_records_text($patient, 'purok'),
        'city_municipality' => bhw_records_text($patient, 'city_municipality'),
        'province' => bhw_records_text($patient, 'province'),
        'address' => bhw_records_text($patient, 'address'),
        'full_address' => bhw_records_text($patient, 'full_address'),
        'allergies' => bhw_records_text($patient, 'allergies'),
        'existing_conditions' => bhw_records_text($patient, 'existing_conditions'),
        'current_medications' => bhw_records_text($patient, 'current_medications'),
        'blood_type' => $blood,
    ];
}

/**
 * Consultation history already visible to BHW: schedule, provider, chief
 * complaint, and the diagnosis/recommendation stored on the consultation.
 * Doctor SOAP notes stay on clinical_notes and are not included.
 *
 * @return list<array<string, mixed>>
 */
function bhw_records_consultations(PDO $pdo, int $patientId): array
{
    $classSelect = "'' AS triage_classification";
    try {
        $triageCols = $pdo->query('SHOW COLUMNS FROM triage_results')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (in_array('triage_classification', $triageCols, true)) {
            $classSelect = 'tr.triage_classification';
        }
    } catch (Throwable $e) {
        $classSelect = "'' AS triage_classification";
    }

    $complaintFallback = "''";
    try {
        $hasRecorded = (bool) $pdo->query("SHOW TABLES LIKE 'consultation_recorded_data'")->rowCount();
        if ($hasRecorded) {
            $complaintFallback = "(SELECT crd.chief_complaint
                FROM consultation_recorded_data crd
                WHERE crd.consultation_id = c.id
                  AND crd.patient_id = c.patient_id
                  AND crd.chief_complaint IS NOT NULL
                  AND TRIM(crd.chief_complaint) <> ''
                ORDER BY crd.recorded_at DESC
                LIMIT 1)";
        }
    } catch (Throwable $e) {
        $complaintFallback = "''";
    }

    $sql = "
        SELECT c.id, c.consult_date, c.consult_time, c.status,
               c.diagnosis, c.recommendation,
               TRIM(CONCAT(COALESCE(prv.first_name, ''), ' ', COALESCE(prv.last_name, ''))) AS provider_user_name,
               COALESCE(c.provider_name, '') AS provider_name_stored,
               COALESCE(NULLIF(TRIM(tr.chief_complaint), ''), {$complaintFallback}) AS chief_complaint,
               tr.urgency_label,
               {$classSelect}
        FROM consultations c
        LEFT JOIN users prv ON prv.id = c.provider_id
        LEFT JOIN triage_results tr ON tr.id = c.triage_result_id AND tr.patient_id = c.patient_id
        WHERE c.patient_id = ?
        ORDER BY c.consult_date DESC, c.consult_time DESC, c.id DESC
        LIMIT 100
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$patientId]);
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $provider = trim((string) ($row['provider_user_name'] ?? ''));
        if ($provider === '') {
            $provider = trim((string) ($row['provider_name_stored'] ?? ''));
        }
        $rows[] = [
            'id' => (int) ($row['id'] ?? 0),
            'consult_date' => trim((string) ($row['consult_date'] ?? '')),
            'consult_time' => trim((string) ($row['consult_time'] ?? '')),
            'status' => trim((string) ($row['status'] ?? '')),
            'provider_name' => $provider,
            'chief_complaint' => trim((string) ($row['chief_complaint'] ?? '')),
            'urgency_label' => trim((string) ($row['urgency_label'] ?? '')),
            'triage_classification' => trim((string) ($row['triage_classification'] ?? '')),
            'diagnosis' => trim((string) ($row['diagnosis'] ?? '')),
            'recommendation' => trim((string) ($row['recommendation'] ?? '')),
        ];
    }

    return $rows;
}

const BHW_UPLOAD_MAX_BYTES = 10485760; // 10 MB

$ctx = bhw_api_bootstrap($pdo, ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST');
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

try {
    if ($action === 'list') {
        $patientId = (int) ($_GET['patient_id'] ?? 0);
        bhw_api_require_patient_in_sector($pdo, $ctx, $patientId);
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
        // Dispensing fields only — the prescriber's private notes stay doctor-only.
        $s = $pdo->prepare('SELECT id, medication_name, dosage, frequency, duration, created_at FROM prescriptions WHERE patient_id = ? ORDER BY created_at DESC LIMIT 50');
        $s->execute([$patientId]);
        $records['prescriptions'] = $s->fetchAll(PDO::FETCH_ASSOC);
        try {
            $records['consultations'] = bhw_records_consultations($pdo, $patientId);
        } catch (Throwable $e) {
            $records['consultations'] = [];
        }
        $patient = bhw_records_patient_summary(BhwWorkflows::getPatient($pdo, $ctx, $patientId));
        $bhwId = (int) ($_SESSION['user_id'] ?? 0);
        $isRefresh = (($_GET['refresh'] ?? '') === '1');
        if (!$isRefresh) {
            bhw_audit($pdo, $patientId, 'bhw_records_viewed', 'BHW viewed patient records.');
            if ($bhwId > 0) {
                bhw_nav_mark_records_read($pdo, $bhwId, $patientId);
            }
        }
        Api::success([
            'records' => $records,
            'patient' => $patient,
            'bhw_records' => bhw_nav_records_unread_count($pdo, $bhwId, $ctx),
        ]);
    } elseif ($action === 'upload_stats') {
        Api::success(['stats' => bhw_residency_upload_stats($pdo, $ctx)]);
    } elseif ($action === 'upload') {
        bhw_residency_doc_ensure_metadata($pdo);
        $patientId = (int) ($_POST['patient_id'] ?? 0);
        bhw_api_require_patient_in_sector($pdo, $ctx, $patientId);
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
        $validated = upload_security_validate($file, [
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
        ], BHW_UPLOAD_MAX_BYTES);
        if (!$validated['ok']) {
            Api::error($validated['message']);
        }
        $dir = BASE_PATH . '/storage/residency';
        if (!upload_security_ensure_dir($dir, 0750)) {
            Api::error('Upload directory unavailable.');
        }
        upload_security_write_deny_htaccess($dir);
        $stored = upload_security_safe_filename('bhw_' . $patientId, $validated['ext']);
        $dest = $dir . DIRECTORY_SEPARATOR . $stored;
        if (!move_uploaded_file($validated['tmp'], $dest)) {
            Api::error('Upload failed.');
        }
        @chmod($dest, 0640);
        $detectedMime = $validated['mime'];
        $size = $validated['size'];
        $orig = $validated['original_basename'];
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
        bhw_api_require_patient_in_sector($pdo, $ctx, (int) $doc['patient_id']);
        $dir = BASE_PATH . '/storage/residency';
        $path = upload_security_confine_path($dir, (string) $doc['file_name']);
        if ($path === null) {
            Api::error('File missing on server.', 404);
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $mime = $doc['mime_type'] ?: 'application/octet-stream';
        $name = upload_security_original_basename((string) ($doc['original_name'] ?: $doc['file_name']));
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . str_replace('"', '', $name) . '"');
        header('Content-Length: ' . (string) filesize($path));
        readfile($path);
        exit;
    } else {
        Api::error('Unknown action.', 400);
    }
} catch (Throwable $e) {
    Api::error('Request failed. Please try again.', 500);
}
