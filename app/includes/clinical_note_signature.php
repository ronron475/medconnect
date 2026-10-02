<?php
/**
 * SOAP electronic signature helpers.
 * Signature identity always comes from the authenticated provider Full Name.
 * The signature itself is a provider-uploaded signed PDF of the completed SOAP note.
 */

const CLINICAL_NOTE_SIGNED_PDF_MAX_BYTES = 10485760;
const CLINICAL_NOTE_SIGNED_PDF_SUBDIR = 'uploads/soap_signed';

function clinical_note_signature_schema_ensure(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    try {
        $cols = $pdo->query('SHOW COLUMNS FROM clinical_notes')->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $done = true;
        return;
    }

    $byName = [];
    foreach ($cols as $col) {
        $byName[(string) ($col['Field'] ?? '')] = $col;
    }

    $alters = [];
    if (!isset($byName['signature_method'])) {
        $alters[] = "ADD COLUMN signature_method VARCHAR(20) NULL DEFAULT NULL AFTER signature_data";
    }
    if (!isset($byName['signature_name'])) {
        $alters[] = "ADD COLUMN signature_name VARCHAR(255) NULL DEFAULT NULL AFTER signature_method";
    }
    if (!isset($byName['signed_at'])) {
        $alters[] = "ADD COLUMN signed_at DATETIME NULL DEFAULT NULL AFTER signature_name";
    }
    if (!isset($byName['finalized_at'])) {
        $alters[] = "ADD COLUMN finalized_at DATETIME NULL DEFAULT NULL AFTER signed_at";
    }
    if (!isset($byName['signed_pdf_path'])) {
        $alters[] = "ADD COLUMN signed_pdf_path VARCHAR(255) NULL DEFAULT NULL AFTER finalized_at";
    }
    if (!isset($byName['signed_pdf_name'])) {
        $alters[] = "ADD COLUMN signed_pdf_name VARCHAR(255) NULL DEFAULT NULL AFTER signed_pdf_path";
    }
    if (!isset($byName['signed_pdf_size'])) {
        $alters[] = "ADD COLUMN signed_pdf_size INT UNSIGNED NULL DEFAULT NULL AFTER signed_pdf_name";
    }
    if (!isset($byName['signed_pdf_sha256'])) {
        $alters[] = "ADD COLUMN signed_pdf_sha256 CHAR(64) NULL DEFAULT NULL AFTER signed_pdf_size";
    }

    if ($alters) {
        try {
            $pdo->exec('ALTER TABLE clinical_notes ' . implode(', ', $alters));
        } catch (PDOException $e) { /* non-fatal */ }
    }

    $sigType = strtolower((string) ($byName['signature_data']['Type'] ?? 'text'));
    if ($sigType !== '' && strpos($sigType, 'mediumtext') === false && strpos($sigType, 'longtext') === false) {
        try {
            $pdo->exec('ALTER TABLE clinical_notes MODIFY signature_data MEDIUMTEXT NULL');
        } catch (PDOException $e) { /* non-fatal */ }
    }

    $done = true;
}

/**
 * @return array{first_name:string,middle_name:string,last_name:string,full_name:string,legal_name:string,display_name:string}
 */
function clinical_note_provider_identity(PDO $pdo, int $providerId): array
{
    $empty = [
        'first_name' => '',
        'middle_name' => '',
        'last_name' => '',
        'full_name' => '',
        'legal_name' => '',
        'display_name' => '',
    ];
    if ($providerId <= 0) {
        return $empty;
    }

    $row = false;
    try {
        $stmt = $pdo->prepare('
            SELECT u.first_name, u.last_name,
                   COALESCE(pp.middle_name, \'\') AS middle_name
            FROM users u
            LEFT JOIN provider_profiles pp ON pp.user_id = u.id
            WHERE u.id = ?
            LIMIT 1
        ');
        $stmt->execute([$providerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $stmt = $pdo->prepare('SELECT first_name, last_name FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$providerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $row['middle_name'] = '';
        }
    }
    if (!$row) {
        return $empty;
    }

    $first = trim((string) ($row['first_name'] ?? ''));
    $middle = trim((string) ($row['middle_name'] ?? ''));
    $last = trim((string) ($row['last_name'] ?? ''));
    $legal = trim(preg_replace('/\s+/', ' ', $first . ' ' . ($middle !== '' ? $middle . ' ' : '') . $last));
    $full = trim(preg_replace('/\s+/', ' ', $first . ' ' . $last));
    $display = $full !== '' ? (preg_match('/^dr\.?\s+/i', $full) ? $full : 'Dr. ' . $full) : 'Healthcare Provider';

    return [
        'first_name' => $first,
        'middle_name' => $middle,
        'last_name' => $last,
        'full_name' => $full,
        'legal_name' => $legal !== '' ? $legal : $full,
        'display_name' => $display,
    ];
}

function clinical_note_normalize_person_name(string $name): string
{
    $n = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    $n = preg_replace('/^(dr\.?|dra\.?|doctor)\s+/iu', '', $n) ?? $n;
    $n = str_replace(['Ñ', 'ñ'], 'n', $n);
    $n = mb_strtolower($n, 'UTF-8');
    $n = preg_replace('/[^\p{L}\s]/u', '', $n) ?? $n;
    return trim(preg_replace('/\s+/u', ' ', $n) ?? '');
}

function clinical_note_typed_name_candidates(array $identity): array
{
    $first = trim((string) ($identity['first_name'] ?? ''));
    $middle = trim((string) ($identity['middle_name'] ?? ''));
    $last = trim((string) ($identity['last_name'] ?? ''));
    $mi = $middle !== '' ? mb_substr($middle, 0, 1, 'UTF-8') : '';

    $candidates = [
        $first . ' ' . $last,
        $first . ' ' . $middle . ' ' . $last,
        $first . ' ' . $mi . ' ' . $last,
        $first . ' ' . $mi . '. ' . $last,
        (string) ($identity['full_name'] ?? ''),
        (string) ($identity['legal_name'] ?? ''),
        (string) ($identity['display_name'] ?? ''),
    ];

    $out = [];
    foreach ($candidates as $candidate) {
        $norm = clinical_note_normalize_person_name($candidate);
        if ($norm !== '') {
            $out[$norm] = $norm;
        }
    }
    return array_values($out);
}

function clinical_note_typed_name_matches(string $typed, array $identity): bool
{
    $typedN = clinical_note_normalize_person_name($typed);
    if ($typedN === '' || mb_strlen($typedN) < 3) {
        return false;
    }
    return in_array($typedN, clinical_note_typed_name_candidates($identity), true);
}

function clinical_note_signed_pdf_dir(): string
{
    $base = defined('STORAGE_PATH') ? (string) STORAGE_PATH : dirname(__DIR__, 2) . '/storage';
    return rtrim($base, '/\\') . '/' . CLINICAL_NOTE_SIGNED_PDF_SUBDIR;
}

/**
 * @param mixed $file One entry from $_FILES
 * @return array{ok:bool,message:string}
 */
function clinical_note_signed_pdf_validate_upload($file): array
{
    $missing = 'Please upload the signed SOAP note PDF before finalizing.';
    $maxMb = (int) round(CLINICAL_NOTE_SIGNED_PDF_MAX_BYTES / 1048576);
    $tooLarge = 'The signed PDF is too large. Maximum size is ' . $maxMb . ' MB.';

    if (!is_array($file) || !isset($file['error']) || is_array($file['error'])) {
        return ['ok' => false, 'message' => $missing];
    }

    $err = (int) $file['error'];
    if ($err === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'message' => $missing];
    }
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        return ['ok' => false, 'message' => $tooLarge];
    }
    if ($err !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'message' => 'The signed PDF could not be uploaded. Please try again.'];
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'message' => $missing];
    }

    $size = (int) @filesize($tmp);
    if ($size <= 0) {
        return ['ok' => false, 'message' => 'The signed PDF is empty. Please choose another file.'];
    }
    if ($size > CLINICAL_NOTE_SIGNED_PDF_MAX_BYTES) {
        return ['ok' => false, 'message' => $tooLarge];
    }

    $ext = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if ($ext !== 'pdf') {
        return ['ok' => false, 'message' => 'Only PDF files are accepted for the signed SOAP note.'];
    }

    if (class_exists('finfo')) {
        $mime = (string) ((new finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '');
        if (!in_array($mime, ['application/pdf', 'application/x-pdf'], true)) {
            return ['ok' => false, 'message' => 'Only PDF files are accepted for the signed SOAP note.'];
        }
    }

    $fh = @fopen($tmp, 'rb');
    $head = $fh ? (string) fread($fh, 5) : '';
    if ($fh) {
        fclose($fh);
    }
    if ($head !== '%PDF-') {
        return ['ok' => false, 'message' => 'The uploaded file is not a valid PDF.'];
    }

    return ['ok' => true, 'message' => ''];
}

/**
 * Move a validated upload into private storage.
 *
 * @return array{ok:bool,message:string,path?:string,abs?:string,name?:string,size?:int,sha256?:string}
 */
function clinical_note_signed_pdf_store(array $file, int $consultationId): array
{
    $fail = ['ok' => false, 'message' => 'Could not store the signed PDF. Please try again.'];

    $dir = clinical_note_signed_pdf_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        return $fail;
    }
    $htaccess = $dir . '/.htaccess';
    if (!is_file($htaccess)) {
        @file_put_contents($htaccess, "Deny from all\n");
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    $sha = (string) (@hash_file('sha256', $tmp) ?: '');
    if ($sha === '') {
        return $fail;
    }

    try {
        $basename = 'consult_' . $consultationId . '_' . bin2hex(random_bytes(12)) . '.pdf';
    } catch (Throwable $e) {
        return $fail;
    }
    $abs = $dir . '/' . $basename;
    if (!@move_uploaded_file($tmp, $abs)) {
        return $fail;
    }
    @chmod($abs, 0640);

    $original = basename(str_replace('\\', '/', (string) ($file['name'] ?? '')));
    $original = preg_replace('/[\x00-\x1F\x7F]+/u', '', $original) ?? '';
    $original = trim(mb_substr($original, 0, 200, 'UTF-8'));
    if ($original === '') {
        $original = 'signed-soap-note.pdf';
    }

    return [
        'ok' => true,
        'message' => '',
        'path' => CLINICAL_NOTE_SIGNED_PDF_SUBDIR . '/' . $basename,
        'abs' => $abs,
        'name' => $original,
        'size' => (int) filesize($abs),
        'sha256' => $sha,
    ];
}

/** Absolute path of a stored signed PDF, or null when missing / outside the private directory. */
function clinical_note_signed_pdf_abs_path(?string $relPath): ?string
{
    $rel = trim((string) $relPath);
    if ($rel === '' || !str_starts_with($rel, CLINICAL_NOTE_SIGNED_PDF_SUBDIR . '/')) {
        return null;
    }
    $dir = realpath(clinical_note_signed_pdf_dir());
    $base = defined('STORAGE_PATH') ? (string) STORAGE_PATH : dirname(__DIR__, 2) . '/storage';
    $abs = realpath(rtrim($base, '/\\') . '/' . $rel);
    if ($dir === false || $abs === false || !is_file($abs)) {
        return null;
    }
    if (!str_starts_with($abs, $dir . DIRECTORY_SEPARATOR)) {
        return null;
    }
    return $abs;
}

function clinical_note_has_signed_pdf(?array $note): bool
{
    return is_array($note) && trim((string) ($note['signed_pdf_path'] ?? '')) !== '';
}

function clinical_note_signed_pdf_url(int $consultationId): string
{
    $base = defined('ASSET_BASE') ? (string) ASSET_BASE : '';
    return $base . '/app/api/consultations/view_signed_soap_pdf.php?consultation_id=' . $consultationId;
}

function clinical_note_is_image_payload(?string $value): bool
{
    return strncmp(trim((string) $value), 'data:image/', 11) === 0;
}

function clinical_note_signed_by_label(array $note, string $fallbackName = ''): string
{
    $name = trim((string) ($note['signature_name'] ?? ''));
    if ($name === '') {
        $raw = trim((string) ($note['signature_data'] ?? ''));
        if ($raw !== '' && !clinical_note_is_image_payload($raw)) {
            $name = $raw;
        }
    }
    if ($name === '') {
        $name = trim($fallbackName);
    }
    if ($name === '') {
        return '';
    }
    if (!preg_match('/^dr\.?\s+/i', $name)) {
        $name = 'Dr. ' . $name;
    }
    return 'Electronically signed by ' . $name;
}

function clinical_note_signed_at_label(array $note): string
{
    $at = trim((string) ($note['signed_at'] ?? $note['finalized_at'] ?? ''));
    if ($at === '' || strtotime($at) === false) {
        return '';
    }
    return 'Signed: ' . date('F j, Y', strtotime($at));
}
