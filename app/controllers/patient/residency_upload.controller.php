<?php
/**
 * residency_upload.controller.php
 * Handles residency document replacement for existing patients.
 *
 * Flow:
 *   1. Auth + CSRF guard
 *   2. File presence check
 *   3. PHP upload error check
 *   4. File size validation
 *   5. MIME type validation (finfo — server-side, not client header)
 *   6. Extension whitelist check
 *   7. Double-extension / null-byte attack prevention
 *   8. Secure rename + move to non-public storage
 *   9. Session flash + redirect
 *
 * TODO — OCR / re-verification hook points are marked with [OCR_HOOK].
 * TODO — Database persistence points are marked with [DB_HOOK].
 */

require_once dirname(__DIR__, 3) . '/bootstrap/app.php';
require_once BASE_PATH . '/config/db.php';
require_once BASE_PATH . '/app/includes/auth_guard.php';

$patientProfileUrl = ASSET_BASE . '/views/patient/profile.php';

// ── Constants ────────────────────────────────────────────────────────────────

/** Maximum allowed file size: 5 MB */
const RU_MAX_BYTES = 5 * 1024 * 1024;

/** Allowed MIME types (verified server-side via finfo) */
const RU_ALLOWED_MIMES = [
    'image/jpeg',
    'image/jpg',
    'image/png',
    'application/pdf',
];

/** Allowed file extensions (lowercase) */
const RU_ALLOWED_EXTS = ['jpg', 'jpeg', 'png', 'pdf'];

/** Upload directory — outside webroot or protected by .htaccess */
define('RU_UPLOAD_DIR', dirname(__DIR__, 3) . '/storage/uploads/ids/');

// ── Guards ───────────────────────────────────────────────────────────────────

// Auth guard — patients only
if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'patient') {
    header('Location: ' . auth_signin_required_url());
    exit;
}

// Method guard
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $patientProfileUrl);
    exit;
}

// CSRF guard
if (
    empty($_POST['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])
) {
    _fail('Invalid request token. Please refresh the page and try again.');
}

// ── Helper: redirect with flash message ──────────────────────────────────────

function _fail(string $message): never
{
    global $patientProfileUrl;
    $_SESSION['residency_error'] = $message;
    header('Location: ' . $patientProfileUrl);
    exit;
}

function _success(string $message): never
{
    global $patientProfileUrl;
    $_SESSION['residency_success'] = $message;
    header('Location: ' . $patientProfileUrl);
    exit;
}

// ── Step 1: File presence ─────────────────────────────────────────────────────

if (
    empty($_FILES['residency_file']) ||
    ($_FILES['residency_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE
) {
    _fail('No file was selected. Please choose a document to upload.');
}

$file = $_FILES['residency_file'];

// ── Step 2: PHP upload error codes ───────────────────────────────────────────

$upload_error_messages = [
    UPLOAD_ERR_INI_SIZE   => 'The file exceeds the server\'s maximum upload size.',
    UPLOAD_ERR_FORM_SIZE  => 'The file exceeds the allowed form upload size.',
    UPLOAD_ERR_PARTIAL    => 'The file was only partially uploaded. Please try again.',
    UPLOAD_ERR_NO_TMP_DIR => 'Server configuration error: missing temporary folder.',
    UPLOAD_ERR_CANT_WRITE => 'Server error: could not write file to disk.',
    UPLOAD_ERR_EXTENSION  => 'The upload was blocked by a server extension.',
];

if ($file['error'] !== UPLOAD_ERR_OK) {
    $msg = $upload_error_messages[$file['error']]
        ?? 'An unknown upload error occurred (code ' . $file['error'] . ').';
    _fail($msg);
}

// ── Step 3: File size ─────────────────────────────────────────────────────────

if ($file['size'] > RU_MAX_BYTES) {
    $max_mb = RU_MAX_BYTES / 1024 / 1024;
    _fail("File is too large. Maximum allowed size is {$max_mb} MB.");
}

if ($file['size'] === 0) {
    _fail('The uploaded file is empty. Please select a valid document.');
}

// ── Step 4: MIME type — server-side finfo check ───────────────────────────────
// Never trust $_FILES['type'] — it comes from the client and can be spoofed.

$finfo    = new finfo(FILEINFO_MIME_TYPE);
$detected = $finfo->file($file['tmp_name']);

if ($detected === false || !in_array($detected, RU_ALLOWED_MIMES, true)) {
    _fail(
        'Invalid file type detected (' . htmlspecialchars((string)$detected) . '). ' .
        'Allowed formats: JPG, JPEG, PNG, PDF.'
    );
}

// ── Step 5: Extension whitelist ───────────────────────────────────────────────

$original_name = $file['name'];
$ext           = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

if (!in_array($ext, RU_ALLOWED_EXTS, true)) {
    _fail('Invalid file extension. Allowed: JPG, JPEG, PNG, PDF.');
}

// ── Step 6: Double-extension + null-byte attack prevention ───────────────────
// e.g. "shell.php.jpg" or "file\0.jpg"

if (substr_count($original_name, '.') > 1) {
    // Allow "my.document.pdf" style names but reject anything with a dangerous
    // secondary extension like .php, .phtml, .phar, .exe, .sh, etc.
    $dangerous_exts = [
        'php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'phar',
        'asp', 'aspx', 'jsp', 'cgi', 'pl', 'py', 'rb',
        'exe', 'sh', 'bat', 'cmd', 'ps1',
    ];
    $parts = explode('.', strtolower($original_name));
    // Remove the last (valid) extension, check the rest
    array_pop($parts);
    foreach ($parts as $part) {
        if (in_array($part, $dangerous_exts, true)) {
            _fail('The file name contains a disallowed extension. Please rename the file and try again.');
        }
    }
}

// Null-byte check
if (strpos($original_name, "\0") !== false) {
    _fail('Invalid file name detected.');
}

// ── Step 7: Ensure upload directory exists and is protected ──────────────────

if (!is_dir(RU_UPLOAD_DIR)) {
    if (!mkdir(RU_UPLOAD_DIR, 0750, true)) {
        _fail('Server error: could not create upload directory. Please contact support.');
    }
}

// Write .htaccess protection if missing
$htaccess_path = RU_UPLOAD_DIR . '.htaccess';
if (!file_exists($htaccess_path)) {
    file_put_contents($htaccess_path, "Deny from all\n");
}

// ── Step 8: Secure rename + move ─────────────────────────────────────────────
// File is renamed to a random hex string — original name is never used on disk.
// Format: {user_id}_{timestamp}_{random}.{ext}
// This prevents:
//   - Path traversal via crafted filenames
//   - Enumeration of other patients' files
//   - Overwriting existing files

$user_id   = (int)$_SESSION['user_id'];
$timestamp = time();
$random    = bin2hex(random_bytes(16));   // 32-char hex, cryptographically random
$safe_name = "{$user_id}_{$timestamp}_{$random}.{$ext}";
$dest_path = RU_UPLOAD_DIR . $safe_name;

if (!move_uploaded_file($file['tmp_name'], $dest_path)) {
    _fail('Failed to save the uploaded file. Please try again.');
}

// Restrict file permissions — readable by web server only, not world-readable
@chmod($dest_path, 0640);

// Audit log — record the residency document upload
require_once dirname(__DIR__, 2) . '/includes/audit_log.php';
audit_log($pdo, [
    'patient_id'  => $user_id,
    'action_type' => AuditAction::RESIDENCY_DOC_UPLOADED,
    'description' => 'Patient uploaded a new residency document. Status set to pending review.',
    'meta'        => [
        'stored_file'   => $safe_name,
        'original_name' => $original_name,
        'mime_type'     => $detected,
        'file_size'     => $file['size'],
        'new_status'    => $new_status,
    ],
]);

// ── [OCR_HOOK] ────────────────────────────────────────────────────────────────
// At this point the file is safely stored at $dest_path.
// To trigger OCR / residency re-verification, integrate here:
//
//   require_once __DIR__ . '/../core/ocr_verify.php';
//   $ocr_result = runResidencyOCR($dest_path, $detected, $user_id);
//
//   if ($ocr_result['bago_city_confirmed']) {
//       $new_status = 'verified';
//   } elseif ($ocr_result['city_found'] && $ocr_result['city_found'] !== 'bago city') {
//       $new_status = 'mismatch';   // DO NOT delete account — flag for manual review
//   } else {
//       $new_status = 'needs_review';
//   }
//
// Important: a mismatch must NOT delete or deactivate the patient account.
// Set status to 'mismatch' or 'needs_review' and allow manual review by CHO staff.
// ─────────────────────────────────────────────────────────────────────────────

$new_status = 'pending'; // Default until OCR is integrated

// ── Persistence: Save to residency_documents ──────────────────
try {
    $stmt = $pdo->prepare("
        INSERT INTO residency_documents
            (patient_id, file_name, original_name, file_size, mime_type, status, uploaded_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $user_id,
        $safe_name,
        $original_name,
        $file['size'],
        $detected,
        $new_status,
    ]);

    // Also update the patient_registrations row if possible
    $pdo->prepare("
        UPDATE patient_registrations
        SET status = 'pending_verification'
        WHERE email = (SELECT email FROM users WHERE id = ? LIMIT 1)
    ")->execute([$user_id]);

} catch (Exception $e) {
    // Log error but don't fail the upload since file is already moved
    error_log("Failed to persist residency document: " . $e->getMessage());
}

// ── Step 9: Flash + redirect ──────────────────────────────────────────────────

_success(
    'Your residency document has been uploaded successfully. ' .
    'It is now pending review by the City Health Office of Bago City. ' .
    'Some services may be temporarily restricted until verification is complete.'
);
