<?php
ob_start(); // buffer all output — prevents PHP notices from corrupting JSON
session_start();
header('Content-Type: application/json');
require_once dirname(__DIR__, 3) . '/config/db.php';
require_once dirname(__DIR__, 3) . '/config/ocr_config.php';
require_once dirname(__DIR__, 3) . '/app/core/PhilSysOcrParser.php';
require_once dirname(__DIR__, 3) . '/app/core/OcrFastApiClient.php';

// ── Session clear endpoint — called by JS when address fields change after verify ──
if (isset($_GET['clear_session'])) {
    unset($_SESSION['ocr_verified'], $_SESSION['ocr_national_id'],
          $_SESSION['ocr_final_state'], $_SESSION['ocr_bago_city'],
          $_SESSION['ocr_extract_cache']);
    ob_clean(); echo json_encode(['success' => true]);
    exit;
}

// ── Extract mode — OCR auto-fill (no verification against entered fields) ──
$ocr_mode = strtolower(trim($_POST['ocr_mode'] ?? $_GET['ocr_mode'] ?? ''));
if ($ocr_mode === 'extract' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_FILES['national_id_image']) || $_FILES['national_id_image']['error'] === UPLOAD_ERR_NO_FILE) {
        ob_clean(); echo json_encode(['success' => false, 'message' => 'No ID image uploaded.']);
        exit;
    }

    $file = $_FILES['national_id_image'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        ob_clean(); echo json_encode(['success' => false, 'message' => 'Upload failed. Please try again.']);
        exit;
    }
    if ($file['size'] > OCR_MAX_FILE_SIZE) {
        ob_clean(); echo json_encode(['success' => false, 'message' => 'File is too large. Maximum allowed size is 5 MB.']);
        exit;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    $ext   = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($mime, OCR_ALLOWED_TYPES, true) || !in_array($ext, OCR_ALLOWED_EXTS, true)) {
        ob_clean(); echo json_encode(['success' => false, 'message' => 'Invalid file type. Allowed: JPG, PNG, PDF.']);
        exit;
    }

    $file_hash = hash_file('sha256', $file['tmp_name']);
    if (!empty($_SESSION['ocr_extract_cache'][$file_hash])) {
        $cached = $_SESSION['ocr_extract_cache'][$file_hash];
        $cached['cached'] = true;
        ob_clean(); echo json_encode($cached);
        exit;
    }

    // ── Try FastAPI OCR service first (port 8766) ──
    if (OcrFastApiClient::isEnabled()) {
        $fastapi = OcrFastApiClient::extract($file['tmp_name'], $mime, $file['name']);
        if (is_array($fastapi) && !empty($fastapi['success']) && !empty($fastapi['confidence_ok'])) {
            $response = $fastapi;
            $response['cached'] = false;
            if (!isset($_SESSION['ocr_extract_cache']) || !is_array($_SESSION['ocr_extract_cache'])) {
                $_SESSION['ocr_extract_cache'] = [];
            }
            $_SESSION['ocr_extract_cache'][$file_hash] = $response;
            if (count($_SESSION['ocr_extract_cache']) > 5) {
                $_SESSION['ocr_extract_cache'] = array_slice($_SESSION['ocr_extract_cache'], -5, null, true);
            }
            ob_clean(); echo json_encode($response);
            exit;
        }
        if (is_array($fastapi) && isset($fastapi['success']) && $fastapi['success'] === false && !empty($fastapi['message'])) {
            // FastAPI failed — fall through to PHP OCR.Space multi-pass pipeline below.
        }
        // FastAPI unavailable — fall through to PHP OCR pipeline
    }

    $is_pdf = ($mime === 'application/pdf');
    $extract_result = runBestOcrExtract($file['tmp_name'], $mime, $is_pdf);

    if ($extract_result['error'] !== null) {
        ob_clean(); echo json_encode([
            'success' => false,
            'message' => $extract_result['error'],
        ]);
        exit;
    }

    $parsed_text = $extract_result['text'];
    $processed   = ['stage' => $extract_result['stage'] ?? 'none'];

    if ($parsed_text === '') {
        ob_clean(); echo json_encode([
            'success' => false,
            'message' => "We couldn't accurately read your National ID. Please upload a clearer photo taken in good lighting.",
        ]);
        exit;
    }

    $extraction = $extract_result['extraction'] ?? PhilSysOcrParser::extractAll($parsed_text);
    $low_confidence = $extraction['low_confidence'];

    $response = [
        'success' => true,
        'mode' => 'extract',
        'extracted' => $extraction['fields'],
        'overall_confidence' => $extraction['overall_confidence'],
        'low_confidence' => $low_confidence,
        'confidence_ok' => !$low_confidence,
        'preprocessing_used' => $processed['stage'] ?? 'none',
        'parsed_text' => OCR_DEBUG ? $parsed_text : null,
        'cached' => false,
    ];

    if ($low_confidence) {
        $response['message'] = 'We could not read your National ID with enough confidence. Please upload a clearer photo taken in good lighting.';
        $response['extracted'] = array_map(static function (array $field): array {
            return ['value' => '', 'confidence' => $field['confidence'], 'source' => $field['source']];
        }, $extraction['fields']);
    } else {
        $response['message'] = 'National ID information extracted successfully. Please review the auto-filled fields.';
    }

    if (!isset($_SESSION['ocr_extract_cache']) || !is_array($_SESSION['ocr_extract_cache'])) {
        $_SESSION['ocr_extract_cache'] = [];
    }
    $_SESSION['ocr_extract_cache'][$file_hash] = $response;
    if (count($_SESSION['ocr_extract_cache']) > 5) {
        $_SESSION['ocr_extract_cache'] = array_slice($_SESSION['ocr_extract_cache'], -5, null, true);
    }

    ob_clean(); echo json_encode($response);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    ob_clean(); echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$entered_first    = strtolower(trim($_POST['first_name']    ?? ''));
$entered_middle   = strtolower(trim($_POST['middle_name']   ?? ''));
$entered_last     = strtolower(trim($_POST['last_name']     ?? ''));
$entered_dob      = trim($_POST['date_of_birth']            ?? '');
$entered_id       = preg_replace('/[^0-9]/', '', trim($_POST['national_id'] ?? ''));
$entered_barangay = strtolower(trim($_POST['barangay']      ?? ''));

if (!$entered_first || !$entered_last || !$entered_dob || !$entered_id) {
    ob_clean(); echo json_encode(['success' => false, 'message' => 'Please fill in all required fields before verifying your ID.']);
    exit;
}

if (empty($_FILES['national_id_image']) || $_FILES['national_id_image']['error'] === UPLOAD_ERR_NO_FILE) {
    ob_clean(); echo json_encode(['success' => false, 'message' => 'No ID image uploaded.']);
    exit;
}

$file = $_FILES['national_id_image'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    $upload_errors = [
        UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit.',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds form size limit.',
        UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
        UPLOAD_ERR_EXTENSION  => 'Upload blocked by server extension.',
    ];
    ob_clean(); echo json_encode(['success' => false, 'message' => $upload_errors[$file['error']] ?? 'Unknown upload error.']);
    exit;
}

if ($file['size'] > OCR_MAX_FILE_SIZE) {
    ob_clean(); echo json_encode(['success' => false, 'message' => 'File is too large. Maximum allowed size is 5 MB.']);
    exit;
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($file['tmp_name']);
$ext   = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

if (!in_array($mime, OCR_ALLOWED_TYPES, true) || !in_array($ext, OCR_ALLOWED_EXTS, true)) {
    ob_clean(); echo json_encode(['success' => false, 'message' => 'Invalid file type. Allowed: JPG, PNG, PDF.']);
    exit;
}

$ocr_debug = [
    'preprocessing_used' => 'none',
    'retry_triggered'    => false,
    'parsed_fields'      => [],
];

// ── Helper: clean up temp file ───────────────────────────────
function cleanTemp(array $processed): void {
    if (!empty($processed['temp']) && !empty($processed['path']) && file_exists($processed['path'])) {
        @unlink($processed['path']);
    }
}

// ── Helper: check if OCR response is a real error ────────────
// OCR.Space sometimes returns IsErroredOnProcessing=false but still has
// E500 / resource exhaustion codes in OCRExitCode or ErrorMessage.
function ocrResponseFailed(array $ocr): bool {
    if (!empty($ocr['IsErroredOnProcessing'])) return true;
    $exit_code = $ocr['OCRExitCode'] ?? 0;
    // Exit codes: 1=parsed, 2=parsed with warnings, 3=parsed with fatal warnings, 4+=error
    if (is_numeric($exit_code) && (int)$exit_code >= 4) return true;
    // Check for E500 / binary failure in error messages
    $msgs = $ocr['ErrorMessage'] ?? [];
    if (is_string($msgs)) $msgs = [$msgs];
    foreach ((array)$msgs as $msg) {
        $ml = strtolower((string)$msg);
        if (strpos($ml, 'e500') !== false
            || strpos($ml, 'binary') !== false
            || strpos($ml, 'resource') !== false
            || strpos($ml, 'exhaustion') !== false
            || strpos($ml, 'timeout') !== false) {
            return true;
        }
    }
    return false;
}

// ── Helper: extract user-friendly message from OCR error ─────
function ocrFriendlyError(array $ocr): string {
    $msgs = $ocr['ErrorMessage'] ?? [];
    if (is_string($msgs)) $msgs = [$msgs];
    $raw = trim(implode(' ', (array)$msgs));
    $rl  = strtolower($raw);
    if (strpos($rl, 'e500') !== false
        || strpos($rl, 'binary') !== false
        || strpos($rl, 'resource') !== false
        || strpos($rl, 'exhaustion') !== false) {
        return 'The OCR service could not process the image right now due to a resource issue. Please try again with a smaller or clearer JPG file.';
    }
    if (strpos($rl, 'timeout') !== false) {
        return 'The OCR service timed out. Please try again with a smaller file.';
    }
    if (strpos($rl, 'size') !== false || strpos($rl, '1024') !== false) {
        return 'The image file is too large for the OCR service. Please use a JPG under 1 MB.';
    }
    return 'The OCR service could not read the uploaded ID. Please try again with a clearer photo.';
}

// ── EXIF orientation + multi-pass extract (handles rotated ID photos) ──

function applyExifOrientationGd($img, string $src_path) {
    if (!function_exists('exif_read_data') || !function_exists('imagerotate')) {
        return $img;
    }
    $exif = @exif_read_data($src_path);
    if (!is_array($exif)) {
        return $img;
    }
    $orientation = (int)($exif['Orientation'] ?? 1);
    switch ($orientation) {
        case 3:
            $rotated = imagerotate($img, 180, 0);
            break;
        case 6:
            $rotated = imagerotate($img, -90, 0);
            break;
        case 8:
            $rotated = imagerotate($img, 90, 0);
            break;
        default:
            return $img;
    }
    if ($rotated) {
        imagedestroy($img);
        return $rotated;
    }
    return $img;
}

function loadGdImageFromFile(string $src_path, string $mime_type) {
    $img = null;
    if ($mime_type === 'image/jpeg') {
        $img = @imagecreatefromjpeg($src_path);
    } elseif ($mime_type === 'image/png') {
        $img = @imagecreatefrompng($src_path);
    }
    if (!$img) {
        return null;
    }
    if ($mime_type === 'image/jpeg') {
        $img = applyExifOrientationGd($img, $src_path);
    }
    return $img;
}

function saveGdOcrJpeg($img, string $stage_label): ?array {
    $orig_w = imagesx($img);
    $orig_h = imagesy($img);
    if ($orig_w < 1 || $orig_h < 1) {
        return null;
    }

    $new_w = $orig_w;
    $new_h = $orig_h;
    $scale_stage = '';
    if ($orig_w < 1000) {
        $new_w = 1000;
        $new_h = (int)round($orig_h * (1000 / $orig_w));
        $scale_stage = 'upscaled';
    } elseif ($orig_w > 1800) {
        $new_w = 1800;
        $new_h = (int)round($orig_h * (1800 / $orig_w));
        $scale_stage = 'downscaled';
    }

    $base = imagecreatetruecolor($new_w, $new_h);
    imagefill($base, 0, 0, imagecolorallocate($base, 255, 255, 255));
    imagecopyresampled($base, $img, 0, 0, 0, 0, $new_w, $new_h, $orig_w, $orig_h);

    $out = imagecreatetruecolor($new_w, $new_h);
    imagecopy($out, $base, 0, 0, 0, 0, $new_w, $new_h);
    imagedestroy($base);
    imagefilter($out, IMG_FILTER_GRAYSCALE);
    imagefilter($out, IMG_FILTER_CONTRAST, -30);
    imagefilter($out, IMG_FILTER_BRIGHTNESS, 4);

    $path = tempnam(sys_get_temp_dir(), 'ocr_') . '.jpg';
    $saved = false;
    foreach ([88, 75, 60] as $q) {
        if (imagejpeg($out, $path, $q) && filesize($path) <= 900 * 1024) {
            $saved = true;
            break;
        }
    }
    imagedestroy($out);

    if (!$saved) {
        @unlink($path);
        return null;
    }

    $stage = implode('+', array_filter([$scale_stage, $stage_label, 'gray-contrast']));
    return ['path' => $path, 'mime' => 'image/jpeg', 'stage' => $stage, 'temp' => true];
}

function buildOcrExtractVariants(string $src_path, string $mime_type): array {
    $fallback = [['path' => $src_path, 'mime' => $mime_type, 'stage' => 'original', 'temp' => false]];
    if (!extension_loaded('gd')) {
        return $fallback;
    }

    $img = loadGdImageFromFile($src_path, $mime_type);
    if (!$img) {
        return $fallback;
    }

    $w = imagesx($img);
    $h = imagesy($img);
    $angles = [0];
    // Portrait photos of a landscape ID card — try 90° / 270° rotations.
    if ($h > (int)round($w * 1.05)) {
        $angles[] = 90;
        $angles[] = 270;
    }

    $variants = [];
    foreach ($angles as $angle) {
        $working = $img;
        $rotated = false;
        if ($angle !== 0) {
            $rot = imagerotate($img, -$angle, 0);
            if (!$rot) {
                continue;
            }
            $working = $rot;
            $rotated = true;
        }

        $variant = saveGdOcrJpeg($working, $angle === 0 ? 'exif' : ('rot' . $angle));
        if ($variant) {
            $variants[] = $variant;
        }
        if ($rotated) {
            imagedestroy($working);
        }
    }
    imagedestroy($img);

    return !empty($variants) ? $variants : $fallback;
}

function scorePhilSysExtraction(array $extraction): float {
    $fields = $extraction['fields'] ?? [];
    $required = ['first_name', 'last_name', 'date_of_birth', 'national_id'];
    $filled = 0;
    foreach ($required as $key) {
        if (($fields[$key]['value'] ?? '') !== '') {
            $filled++;
        }
    }
    $base = (float)($extraction['overall_confidence'] ?? 0.0);
    return $base + ($filled * 0.12);
}

function runBestOcrExtract(string $src_path, string $mime, bool $is_pdf): array {
    $result = [
        'text'       => '',
        'extraction' => null,
        'stage'      => 'none',
        'error'      => null,
    ];

    if ($is_pdf) {
        foreach ([2, 1] as $engine) {
            $ocr = callOCRSpace($src_path, $mime, $engine);
            if ($ocr === null) {
                $result['error'] = 'Could not reach the OCR service. Please check your connection and try again.';
                return $result;
            }
            if (ocrResponseFailed($ocr)) {
                if ($engine === 1) {
                    $result['error'] = ocrFriendlyError($ocr);
                }
                continue;
            }
            $text = trim($ocr['ParsedResults'][0]['ParsedText'] ?? '');
            if ($text !== '') {
                $extraction = PhilSysOcrParser::extractAll($text);
                $result['text'] = $text;
                $result['extraction'] = $extraction;
                $result['stage'] = 'pdf+e' . $engine;
                return $result;
            }
        }
        if ($result['error'] === null) {
            $result['error'] = "We couldn't accurately read your National ID. Please upload a clearer photo taken in good lighting.";
        }
        return $result;
    }

    $variants = buildOcrExtractVariants($src_path, $mime);
    $temp_files = [];
    $best_score = -1.0;
    $had_ocr_response = false;

    foreach ($variants as $variant) {
        if (!empty($variant['temp'])) {
            $temp_files[] = $variant['path'];
        }
        foreach ([2, 1] as $engine) {
            $ocr = callOCRSpace($variant['path'], $variant['mime'], $engine);
            if ($ocr === null) {
                continue;
            }
            $had_ocr_response = true;
            if (ocrResponseFailed($ocr)) {
                continue;
            }
            $text = trim($ocr['ParsedResults'][0]['ParsedText'] ?? '');
            if ($text === '') {
                continue;
            }
            $extraction = PhilSysOcrParser::extractAll($text);
            $score = scorePhilSysExtraction($extraction);
            if ($score > $best_score) {
                $best_score = $score;
                $result['text'] = $text;
                $result['extraction'] = $extraction;
                $result['stage'] = ($variant['stage'] ?? 'variant') . '+e' . $engine;
            }
            if ($score >= 0.95 && empty($extraction['low_confidence'])) {
                break 2;
            }
        }
    }

    foreach ($temp_files as $path) {
        if (is_string($path) && file_exists($path)) {
            @unlink($path);
        }
    }

    if ($result['text'] !== '') {
        return $result;
    }

    if (!$had_ocr_response) {
        $result['error'] = 'Could not reach the OCR service. Please check your connection and try again.';
    } else {
        $result['error'] = "We couldn't accurately read your National ID. Please upload a clearer photo taken in good lighting.";
    }
    return $result;
}

// ── GD image preprocessing ───────────────────────────────────
// Variant A (standard): grayscale + mild contrast, scaled to 1000–1800px wide.
// Variant B (sharp):    same scale, then stronger contrast + sharpen kernel.
// Returns array of processed variants, each with path/mime/stage/temp keys.
function preprocessImageForOCR(string $src_path, string $mime_type): array {
    $fallback = ['path' => $src_path, 'mime' => $mime_type, 'stage' => 'none', 'temp' => false];
    if (!extension_loaded('gd')) return $fallback;

    $img = null;
    if ($mime_type === 'image/jpeg') {
        $img = @imagecreatefromjpeg($src_path);
    } elseif ($mime_type === 'image/png') {
        $img = @imagecreatefrompng($src_path);
    }
    if (!$img) return $fallback;

    $orig_w = imagesx($img);
    $orig_h = imagesy($img);

    // Scale to target width: upscale if < 1000px, downscale if > 1800px
    $new_w = $orig_w;
    $new_h = $orig_h;
    $scale_stage = '';
    if ($orig_w < 1000) {
        $new_w = 1000;
        $new_h = (int)round($orig_h * (1000 / $orig_w));
        $scale_stage = 'upscaled';
    } elseif ($orig_w > 1800) {
        $new_w = 1800;
        $new_h = (int)round($orig_h * (1800 / $orig_w));
        $scale_stage = 'downscaled';
    }

    // Build scaled base canvas
    $base = imagecreatetruecolor($new_w, $new_h);
    imagefill($base, 0, 0, imagecolorallocate($base, 255, 255, 255));
    imagecopyresampled($base, $img, 0, 0, 0, 0, $new_w, $new_h, $orig_w, $orig_h);
    unset($img);

    $results = [];

    // ── Variant A: grayscale + mild contrast (original behaviour) ──
    $varA = imagecreatetruecolor($new_w, $new_h);
    imagecopy($varA, $base, 0, 0, 0, 0, $new_w, $new_h);
    imagefilter($varA, IMG_FILTER_GRAYSCALE);
    imagefilter($varA, IMG_FILTER_CONTRAST, -20);
    $stageA = array_filter([$scale_stage, 'grayscale', 'contrast-mild']);
    $pathA  = tempnam(sys_get_temp_dir(), 'ocr_') . '.jpg';
    $savedA = false;
    foreach ([85, 70, 55] as $q) {
        if (imagejpeg($varA, $pathA, $q) && filesize($pathA) <= 900 * 1024) { $savedA = true; break; }
    }
    imagedestroy($varA);
    if ($savedA) {
        $results[] = ['path' => $pathA, 'mime' => 'image/jpeg',
                      'stage' => implode('+', $stageA), 'temp' => true];
    } else {
        @unlink($pathA);
    }

    // ── Variant B: grayscale + strong contrast + sharpen ──────
    // Stronger contrast helps separate thin strokes (1 vs 4 vs 7).
    // Sharpen kernel improves edge definition on digit serifs.
    $varB = imagecreatetruecolor($new_w, $new_h);
    imagecopy($varB, $base, 0, 0, 0, 0, $new_w, $new_h);
    imagefilter($varB, IMG_FILTER_GRAYSCALE);
    imagefilter($varB, IMG_FILTER_CONTRAST, -45);   // stronger contrast
    imagefilter($varB, IMG_FILTER_BRIGHTNESS, 5);   // slight brightness lift
    // Sharpen via convolution matrix
    $sharpen = [[0,-1,0],[-1,5,-1],[0,-1,0]];
    imageconvolution($varB, $sharpen, 1, 0);
    $stageB = array_filter([$scale_stage, 'grayscale', 'contrast-strong', 'sharpen']);
    $pathB  = tempnam(sys_get_temp_dir(), 'ocr_') . '.jpg';
    $savedB = false;
    foreach ([85, 70, 55] as $q) {
        if (imagejpeg($varB, $pathB, $q) && filesize($pathB) <= 900 * 1024) { $savedB = true; break; }
    }
    imagedestroy($varB);
    if ($savedB) {
        $results[] = ['path' => $pathB, 'mime' => 'image/jpeg',
                      'stage' => implode('+', $stageB), 'temp' => true];
    } else {
        @unlink($pathB);
    }

    imagedestroy($base);

    // Return first variant as primary (for name/DOB/residency OCR),
    // full list available via preprocessImageVariants().
    return !empty($results) ? $results[0] : $fallback;
}

// Returns ALL preprocessing variants (A + B) for multi-pass ID extraction.
function preprocessImageVariants(string $src_path, string $mime_type): array {
    $fallback = [['path' => $src_path, 'mime' => $mime_type, 'stage' => 'none', 'temp' => false]];
    if (!extension_loaded('gd')) return $fallback;

    $img = null;
    if ($mime_type === 'image/jpeg') {
        $img = @imagecreatefromjpeg($src_path);
    } elseif ($mime_type === 'image/png') {
        $img = @imagecreatefrompng($src_path);
    }
    if (!$img) return $fallback;

    $orig_w = imagesx($img);
    $orig_h = imagesy($img);

    $new_w = $orig_w; $new_h = $orig_h; $scale_stage = '';
    if ($orig_w < 1000) {
        $new_w = 1000; $new_h = (int)round($orig_h * (1000 / $orig_w)); $scale_stage = 'upscaled';
    } elseif ($orig_w > 1800) {
        $new_w = 1800; $new_h = (int)round($orig_h * (1800 / $orig_w)); $scale_stage = 'downscaled';
    }

    $base = imagecreatetruecolor($new_w, $new_h);
    imagefill($base, 0, 0, imagecolorallocate($base, 255, 255, 255));
    imagecopyresampled($base, $img, 0, 0, 0, 0, $new_w, $new_h, $orig_w, $orig_h);
    unset($img);

    $variants = [];

    // Variant A — mild contrast
    $vA = imagecreatetruecolor($new_w, $new_h);
    imagecopy($vA, $base, 0, 0, 0, 0, $new_w, $new_h);
    imagefilter($vA, IMG_FILTER_GRAYSCALE);
    imagefilter($vA, IMG_FILTER_CONTRAST, -20);
    $pA = tempnam(sys_get_temp_dir(), 'ocr_') . '.jpg';
    $ok = false;
    foreach ([85, 70] as $q) { if (imagejpeg($vA, $pA, $q) && filesize($pA) <= 900*1024) { $ok=true; break; } }
    imagedestroy($vA);
    if ($ok) $variants[] = ['path'=>$pA,'mime'=>'image/jpeg','stage'=>implode('+',(array_filter([$scale_stage,'gray-mild']))),'temp'=>true];
    else @unlink($pA);

    // Variant B — strong contrast + sharpen
    $vB = imagecreatetruecolor($new_w, $new_h);
    imagecopy($vB, $base, 0, 0, 0, 0, $new_w, $new_h);
    imagefilter($vB, IMG_FILTER_GRAYSCALE);
    imagefilter($vB, IMG_FILTER_CONTRAST, -45);
    imagefilter($vB, IMG_FILTER_BRIGHTNESS, 5);
    imageconvolution($vB, [[0,-1,0],[-1,5,-1],[0,-1,0]], 1, 0);
    $pB = tempnam(sys_get_temp_dir(), 'ocr_') . '.jpg';
    $ok = false;
    foreach ([85, 70] as $q) { if (imagejpeg($vB, $pB, $q) && filesize($pB) <= 900*1024) { $ok=true; break; } }
    imagedestroy($vB);
    if ($ok) $variants[] = ['path'=>$pB,'mime'=>'image/jpeg','stage'=>implode('+',(array_filter([$scale_stage,'gray-sharp']))),'temp'=>true];
    else @unlink($pB);

    // Variant C — high contrast + edge-enhance (helps thin strokes like "1")
    $vC = imagecreatetruecolor($new_w, $new_h);
    imagecopy($vC, $base, 0, 0, 0, 0, $new_w, $new_h);
    imagefilter($vC, IMG_FILTER_GRAYSCALE);
    imagefilter($vC, IMG_FILTER_CONTRAST, -60);
    imagefilter($vC, IMG_FILTER_EDGEDETECT);   // edge-enhance pass
    imagefilter($vC, IMG_FILTER_GRAYSCALE);    // re-grayscale after edge
    imagefilter($vC, IMG_FILTER_CONTRAST, -30);
    $pC = tempnam(sys_get_temp_dir(), 'ocr_') . '.jpg';
    $ok = false;
    foreach ([85, 70] as $q) { if (imagejpeg($vC, $pC, $q) && filesize($pC) <= 900*1024) { $ok=true; break; } }
    imagedestroy($vC);
    if ($ok) $variants[] = ['path'=>$pC,'mime'=>'image/jpeg','stage'=>implode('+',(array_filter([$scale_stage,'gray-edge']))),'temp'=>true];
    else @unlink($pC);

    imagedestroy($base);
    return !empty($variants) ? $variants : $fallback;
}

// ── Confusion-pair correction for National ID digits ─────────
// Given a 16-digit OCR candidate and the 16-digit entered ID,
// find positions where they differ and try swapping known confusion pairs.
// If any combination of swaps produces an exact match → return it as 'exact'.
// Only applies when candidate length == entered length == 16.
// Confusion pairs (bidirectional): 1↔4, 1↔7, 0↔8, 5↔6, 3↔8
function applyConfusionPairCorrection(string $ocr_cand, string $entered): array {
    // Returns ['corrected' => string, 'method' => string, 'exact' => bool]
    $result = ['corrected' => $ocr_cand, 'method' => 'none', 'exact' => false];

    if (strlen($ocr_cand) !== 16 || strlen($entered) !== 16) return $result;
    if ($ocr_cand === $entered) {
        $result['exact'] = true; $result['method'] = 'already_exact';
        return $result;
    }

    // Map each digit to its confusion partners
    $confusion = [
        '1' => ['4', '7'],
        '4' => ['1', '7', '9'],
        '7' => ['1', '4'],
        '0' => ['8', '9'],
        '8' => ['0', '3', '9'],
        '5' => ['6'],
        '6' => ['5'],
        '3' => ['8'],
        '9' => ['4', '0', '8'],
        '2' => ['7'],
    ];

    // Find differing positions
    $diff_positions = [];
    for ($i = 0; $i < 16; $i++) {
        if ($ocr_cand[$i] !== $entered[$i]) {
            $diff_positions[] = $i;
        }
    }

    // Only attempt correction if 1–4 digits differ
    if (count($diff_positions) === 0) {
        $result['exact'] = true; $result['method'] = 'already_exact';
        return $result;
    }
    if (count($diff_positions) > 4) return $result;

    // Check that every differing position involves a known confusion pair
    foreach ($diff_positions as $pos) {
        $ocr_digit     = $ocr_cand[$pos];
        $entered_digit = $entered[$pos];
        $partners      = $confusion[$ocr_digit] ?? [];
        if (!in_array($entered_digit, $partners, true)) {
            // This position's difference is NOT a known confusion pair — skip
            return $result;
        }
    }

    // All differing positions are confusion pairs — build corrected candidate
    $corrected = $ocr_cand;
    $swaps     = [];
    foreach ($diff_positions as $pos) {
        $swaps[]     = $ocr_cand[$pos] . '→' . $entered[$pos];
        $corrected[$pos] = $entered[$pos];
    }

    $result['corrected'] = $corrected;
    $result['method']    = 'confusion_swap:' . implode(',', $swaps);
    $result['exact']     = ($corrected === $entered);
    return $result;
}

// ── Extract all 16-digit ID candidates from OCR text ─────────
// Runs on both raw text AND sanitized text for maximum coverage.
// Priority: grouped 4×4 > continuous 16-digit > label-based > sliding window
function extractIdCandidates(string $text, string $entered_digits): array {
    $candidates = [];

    // Pre-compute sanitized version of the text
    $sanitized = sanitizeOcrId($text);

    // P1: grouped 4×4 pattern — hyphen, space, or dot separator
    foreach ([$text, $sanitized] as $src) {
        preg_match_all(
            '/(\d{4})[\s\-\.](\d{4})[\s\-\.](\d{4})[\s\-\.](\d{4})/',
            $src, $m, PREG_SET_ORDER
        );
        foreach ($m as $match) {
            $c = $match[1].$match[2].$match[3].$match[4];
            if (!isset($candidates[$c])) $candidates[$c] = 'grouped_4x4';
        }
    }

    // P2: continuous 16-digit run
    foreach ([$text, $sanitized] as $src) {
        preg_match_all('/\d{16}/', $src, $m);
        foreach ($m[0] as $c) {
            if (!isset($candidates[$c])) $candidates[$c] = 'continuous_16';
        }
    }

    // P3: label-based — digits after PCN/PhilSys/National ID labels
    $id_labels = ['PCN', 'PhilSys', 'PHILSYS', 'National ID', 'NATIONAL ID', 'ID No', 'ID NO', 'Card Number'];
    $by_label  = extractFieldByLabel($text, $id_labels);
    if ($by_label !== '') {
        $c = stripToDigits($by_label);
        if (strlen($c) >= 12 && !isset($candidates[$c])) $candidates[$c] = 'label_based';
    }

    // P4: sliding window over ALL digits (catches split/wrapped numbers)
    foreach ([$text, $sanitized] as $src) {
        $all_digits = stripToDigits($src);
        for ($i = 0; $i <= strlen($all_digits) - 16; $i++) {
            $c = substr($all_digits, $i, 16);
            if (!isset($candidates[$c])) $candidates[$c] = 'sliding_window';
        }
    }

    $out = [];
    foreach ($candidates as $digits => $method) {
        $out[] = ['digits' => (string)$digits, 'method' => $method];
    }
    return $out;
}

// ── OCR.Space API call ────────────────────────────────────────
// Engine 2 is best for patterned backgrounds and numeric strings on Philippine IDs.
// scale=true, detectOrientation=true, filetype=JPG all improve digit accuracy.
function callOCRSpace(string $file_path, string $mime, int $engine): ?array {
    $raw = @file_get_contents($file_path);
    if ($raw === false) return null;

    $b64      = base64_encode($raw);
    $data_url = 'data:' . $mime . ';base64,' . $b64;
    unset($raw, $b64);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => OCR_SPACE_ENDPOINT,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'apikey'            => OCR_SPACE_API_KEY,
            'language'          => 'eng',
            'OCREngine'         => (string)$engine,
            'scale'             => 'true',          // critical for small text on IDs
            'isOverlayRequired' => 'false',
            'detectOrientation' => 'true',
            'isTable'           => 'false',
            'base64Image'       => $data_url,
        ]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $curl_err  = curl_error($ch);
    curl_close($ch);
    if ($response === false || !empty($curl_err)) return null;
    $ocr = json_decode($response, true);
    return (json_last_error() === JSON_ERROR_NONE) ? $ocr : null;
}

// ── Name helpers ─────────────────────────────────────────────
function normalizeName(string $s): string {
    if (function_exists('iconv')) {
        $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
    }
    $s = preg_replace('/[^\x20-\x7E]/', ' ', $s);
    $s = strtolower($s);
    $s = preg_replace('/[^a-z\s]/', '', $s);
    $s = preg_replace('/\s+/', ' ', $s);
    return trim($s);
}

function normalizeOCR(string $text): string {
    return strtolower(preg_replace('/\s+/', ' ', $text));
}

// ── Sanitize a name string: uppercase, strip non-alpha, collapse spaces ──
function sanitizeName(string $s): string {
    if (function_exists('iconv')) {
        $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
    }
    $s = preg_replace('/[^A-Za-z\s]/', ' ', $s);
    $s = strtoupper(preg_replace('/\s+/', ' ', trim($s)));
    return $s;
}

// ── Tokenize OCR text into individual words (uppercase, alpha only) ──
function tokenizeOCR(string $ocr_text): array {
    $clean = sanitizeName($ocr_text);
    $words = preg_split('/\s+/', $clean, -1, PREG_SPLIT_NO_EMPTY);
    // Filter out very short noise tokens (1-2 chars) unless they are the query
    return array_values(array_filter($words, fn($w) => strlen($w) >= 2));
}

// ── Similarity score between two strings (0.0 – 1.0) ──
// Uses Levenshtein distance normalized by the longer string length.
function stringSimilarity(string $a, string $b): float {
    if ($a === $b) return 1.0;
    $maxLen = max(strlen($a), strlen($b));
    if ($maxLen === 0) return 1.0;
    $lev = levenshtein($a, $b);
    return 1.0 - ($lev / $maxLen);
}

// ── Universal strict name verifier ───────────────────────────
// User input must EXACTLY match the OCR-extracted field value.
// No tolerance on user input — they must type what's on the card.
// Only OCR noise tolerance: if OCR misread 1 char on a long word (>6 chars),
// we still accept the correct user input against the noisy OCR value.
function verifyExactTextMatch(string $userInput, string $ocrText): bool {
    $normalize = function(string $s): string {
        if (function_exists('iconv')) {
            $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
        }
        $s = preg_replace('/[^A-Za-z\s]/', ' ', $s);
        return strtoupper(trim(preg_replace('/\s+/', ' ', $s)));
    };

    $input = $normalize($userInput);
    $ocr   = $normalize($ocrText);

    // Reject empty or single-letter inputs
    if (strlen($input) <= 1) return false;
    if ($ocr === '')          return false;

    // Exact match — always accepted
    if ($input === $ocr) return true;

    // Word-by-word — word count must match exactly
    $inputWords = preg_split('/\s+/', $input, -1, PREG_SPLIT_NO_EMPTY);
    $ocrWords   = preg_split('/\s+/', $ocr,   -1, PREG_SPLIT_NO_EMPTY);

    if (count($inputWords) !== count($ocrWords)) return false;

    foreach ($inputWords as $i => $word) {
        $ocrWord = $ocrWords[$i];

        if (strlen($word) <= 1) return false;

        // Exact word match — perfect
        if ($word === $ocrWord) continue;

        // OCR noise tolerance ONLY:
        // The user typed the CORRECT word but OCR misread it.
        // e.g. user types "ALCAZAREN", OCR read "ALC4ZAREN" → accept
        // e.g. user types "ALCAZARENN", OCR read "ALCAZAREN" → REJECT
        //   because user input is LONGER than OCR — user added extra chars
        // Rule: user input must be <= OCR length (user can't add chars)
        //       AND levenshtein == 1 AND word length > 6
        if (strlen($word) > strlen($ocrWord)) return false; // user added chars → reject
        if (strlen($word) < strlen($ocrWord) - 1) return false; // too short
        if (strlen($word) <= 6) return false; // short words must be exact
        if (levenshtein($word, $ocrWord) === 1) continue; // OCR noise only

        return false;
    }

    return true;
}

// ── Extract name fields from PhilSys card OCR output ─────────
// PhilSys card layout (top to bottom):
//   Line: "LAST NAME" or "Surname"  → next non-empty line = last name value
//   Line: "GIVEN NAMES" or "First Name" → next non-empty line = first name value
//   Line: "MIDDLE NAME" → next non-empty line = middle name value
// Also handles inline format: "Last Name: YUMA"
function extractNameFields(string $raw_text): array {
    $fields = ['first' => '', 'last' => '', 'middle' => ''];

    $normalize = function(string $s): string {
        if (function_exists('iconv')) {
            $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
        }
        $s = preg_replace('/[^A-Za-z\s]/', ' ', $s);
        return strtoupper(trim(preg_replace('/\s+/', ' ', $s)));
    };

    $lines = preg_split('/\r?\n/', $raw_text);
    $total = count($lines);

    // Label patterns for each field
    $labelMap = [
        'last'   => ['LAST NAME', 'SURNAME', 'FAMILY NAME', 'APELLIDO'],
        'first'  => ['GIVEN NAMES', 'GIVEN NAME', 'FIRST NAME', 'PANGALAN'],
        'middle' => ['MIDDLE NAME', 'MIDDLE INITIAL', 'GITNANG PANGALAN'],
    ];

    for ($i = 0; $i < $total; $i++) {
        $lineUp = strtoupper(trim($lines[$i]));

        foreach ($labelMap as $field => $labels) {
            if ($fields[$field] !== '') continue; // already found

            foreach ($labels as $label) {
                if (strpos($lineUp, $label) === false) continue;

                // Check for inline value: "LAST NAME: YUMA"
                $after = trim(substr($lineUp, strpos($lineUp, $label) + strlen($label)));
                $after = ltrim($after, ':- ');
                if ($after !== '') {
                    $fields[$field] = $normalize($after);
                    break;
                }

                // Value is on the next non-empty line
                for ($j = $i + 1; $j <= $i + 3 && $j < $total; $j++) {
                    $next = trim($lines[$j]);
                    if ($next === '') continue;
                    $nextUp = strtoupper($next);
                    // Skip if next line is another label
                    $isLabel = false;
                    foreach ($labelMap as $otherLabels) {
                        foreach ($otherLabels as $ol) {
                            if (strpos($nextUp, $ol) !== false) {
                                $isLabel = true; break;
                            }
                        }
                        if ($isLabel) break;
                    }
                    if (!$isLabel) {
                        $fields[$field] = $normalize($next);
                        break;
                    }
                }
                break;
            }
        }
    }

    return $fields;
}

// ── Apply verifyExactTextMatch to all name fields ─────────────
function nameWordsFound(string $entered, string $ocr_text): bool {
    // Split entered name into words and verify each against OCR tokens
    $normalize = function(string $s): string {
        if (function_exists('iconv')) {
            $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
        }
        return strtoupper(trim(preg_replace('/\s+/', ' ',
               preg_replace('/[^A-Za-z\s]/', ' ', $s))));
    };

    $input      = $normalize($entered);
    $ocrClean   = $normalize($ocr_text);
    $ocrTokens  = preg_split('/\s+/', $ocrClean, -1, PREG_SPLIT_NO_EMPTY);

    if (empty($input) || strlen($input) <= 1) return false;

    $words = preg_split('/\s+/', $input, -1, PREG_SPLIT_NO_EMPTY);
    foreach ($words as $word) {
        if (strlen($word) <= 1) return false;
        // Each word must exactly match (or 1-char OCR diff) an OCR token
        $wordMatched = false;
        foreach ($ocrTokens as $token) {
            if ($word === $token) { $wordMatched = true; break; }
            // Allow max 1 char OCR noise, but lengths must be close
            if (abs(strlen($word) - strlen($token)) <= 1
                && levenshtein($word, $token) <= 1) {
                $wordMatched = true; break;
            }
        }
        if (!$wordMatched) return false;
    }
    return true;
}

function middleNameMatches(string $entered, string $candidate): bool {
    if ($entered === '' || $candidate === '') return false;
    // Use the same universal verifier
    return verifyExactTextMatch($entered, $candidate);
}

function extractFieldByLabel(string $raw_text, array $labels): string {
    $lines = preg_split('/\r?\n/', $raw_text);
    foreach ($lines as $i => $line) {
        $ll = strtolower(trim($line));
        foreach ($labels as $label) {
            if (strpos($ll, strtolower($label)) !== false) {
                $pos   = stripos($line, $label);
                $after = ltrim(trim(substr($line, $pos + strlen($label)), "\r\n\t "), ':- ');
                if ($after !== '') return normalizeName($after);
                for ($j = $i + 1; $j <= $i + 2 && $j < count($lines); $j++) {
                    $next = trim($lines[$j], "\r\n\t ");
                    if ($next !== '') return normalizeName($next);
                }
            }
        }
    }
    return '';
}

// ── Step 1: Strip to digits only ─────────────────────────────
function stripToDigits(string $raw): string {
    return preg_replace('/[^0-9]/', '', $raw);
}

// ── Step 2: OCR alphanumeric sanitizer ───────────────────────
// Converts common OCR letter-to-digit misreads on patterned ID backgrounds.
function sanitizeOcrId(string $raw): string {
    $result = '';
    $len = strlen($raw);
    for ($i = 0; $i < $len; $i++) {
        $c = $raw[$i];
        switch ($c) {
            case 'O': case 'o': case 'D': case 'Q': $result .= '0'; break;
            case 'I': case 'l': case 'i': case '!': $result .= '1'; break;
            case 'Z': case 'z':                     $result .= '2'; break;
            case 'S': case 's':                     $result .= '5'; break;
            case 'G':                               $result .= '6'; break;
            case 'B': case '&':                     $result .= '8'; break;
            case 'g': case 'q':                     $result .= '9'; break;
            default:                                $result .= $c;  break;
        }
    }
    return $result;
}

// ── Legacy alias ─────────────────────────────────────────────
function normalizeIdForComparison(string $raw): string {
    return stripToDigits(sanitizeOcrId($raw));
}

$is_pdf    = ($mime === 'application/pdf');
$processed = ['path' => $file['tmp_name'], 'mime' => $mime, 'stage' => 'none', 'temp' => false];
$all_variants = []; // all preprocessing variants for multi-pass ID extraction

// ── Save uploaded ID to secure non-public directory ─────────
// Path is stored in session for admin review; never exposed to client.
$upload_dir = (defined('STORAGE_PATH') ? STORAGE_PATH : dirname(__DIR__, 3) . '/storage') . '/uploads/ids/';
if (!is_dir($upload_dir)) {
    @mkdir($upload_dir, 0750, true);
    // Write .htaccess to block direct web access
    @file_put_contents($upload_dir . '.htaccess', "Deny from all\n");
}
$safe_ext      = in_array($ext, ['jpg','jpeg','png','pdf'], true) ? $ext : 'jpg';
$stored_name   = bin2hex(random_bytes(16)) . '.' . $safe_ext;
$stored_path   = $upload_dir . $stored_name;
$stored_ok     = @copy($file['tmp_name'], $stored_path);
if ($stored_ok) {
    $_SESSION['ocr_id_document_path'] = 'storage/uploads/ids/' . $stored_name;
} else {
    $_SESSION['ocr_id_document_path'] = null;
}

if (!$is_pdf) {
    $all_variants = preprocessImageVariants($file['tmp_name'], $mime);
} else {
    $all_variants = [$processed];
}

$verify_extract = runBestOcrExtract($file['tmp_name'], $mime, $is_pdf);
if ($verify_extract['error'] !== null) {
  foreach ($all_variants as $v) {
      cleanTemp($v);
  }
  ob_clean(); echo json_encode(['success' => false, 'message' => $verify_extract['error']]);
  exit;
}

$parsed_text_e2 = $verify_extract['text'];
$ocr_debug['preprocessing_used'] = $verify_extract['stage'] ?? 'none';
$processed = ['path' => $file['tmp_name'], 'mime' => $mime, 'stage' => $verify_extract['stage'] ?? 'none', 'temp' => false];

if ($parsed_text_e2 === '') {
    foreach ($all_variants as $v) {
        cleanTemp($v);
    }
    ob_clean(); echo json_encode(['success' => false, 'message' => 'No text could be extracted from the uploaded ID. Please upload a clearer photo.']);
    exit;
}

$ocr_lower_e2 = normalizeOCR($parsed_text_e2);
$ocr_clean_e2 = preg_replace('/[^a-z0-9]/', '', $ocr_lower_e2);

$middle_labels = [
    'Middle Name', 'MIDDLE NAME', 'Middle name',
    'Middle Initial', 'MIDDLE INITIAL', 'MN:', 'M.N.',
];
$candidate_middle_e2 = extractFieldByLabel($parsed_text_e2, $middle_labels);

// ── Middle name check — Engine 2 ─────────────────────────────
$entered_middle_norm = normalizeName($entered_middle);
$middle_found        = false;

if ($entered_middle !== '') {
    if ($candidate_middle_e2 !== '') {
        if (middleNameMatches($entered_middle, $candidate_middle_e2)) {
            $middle_found = true;
        }
    }
    if (!$middle_found && middleNameMatches($entered_middle, $ocr_lower_e2)) {
        $middle_found = true;
    }
}

// ── OCR retry — Engine 1 (only if middle name not yet found) ─
$parsed_text      = $parsed_text_e2;
$ocr_lower        = $ocr_lower_e2;
$ocr_clean        = $ocr_clean_e2;
$candidate_middle = $candidate_middle_e2;

if ($entered_middle !== '' && !$middle_found) {
    $ocr_e1 = callOCRSpace($processed['path'], $processed['mime'], 2);
    // Only use Engine 1 result if it succeeded — do not abort on E1 failure
    if ($ocr_e1 !== null && !ocrResponseFailed($ocr_e1)) {
        $parsed_text_e1 = trim($ocr_e1['ParsedResults'][0]['ParsedText'] ?? '');
        if ($parsed_text_e1 !== '') {
            $ocr_debug['retry_triggered'] = true;
            $ocr_lower_e1 = normalizeOCR($parsed_text_e1);
            $ocr_clean_e1 = preg_replace('/[^a-z0-9]/', '', $ocr_lower_e1);
            $candidate_middle_e1 = extractFieldByLabel($parsed_text_e1, $middle_labels);

            $middle_found_e1 = false;
            if ($candidate_middle_e1 !== '') {
                $cn1 = normalizeName($candidate_middle_e1);
                if ($entered_middle_norm === $cn1
                    || strpos($cn1, $entered_middle_norm) !== false
                    || middleNameMatches($entered_middle, $candidate_middle_e1)) {
                    $middle_found_e1 = true;
                }
            }
            if (!$middle_found_e1 && (
                strpos(normalizeName($ocr_lower_e1), $entered_middle_norm) !== false
                || middleNameMatches($entered_middle, $ocr_lower_e1)
            )) {
                $middle_found_e1 = true;
            }

            if ($middle_found_e1) {
                // Engine 1 found the middle name — use its full text for all checks
                $parsed_text      = $parsed_text_e1;
                $ocr_lower        = $ocr_lower_e1;
                $ocr_clean        = $ocr_clean_e1;
                $candidate_middle = $candidate_middle_e1;
                $middle_found     = true;
            } else {
                // Merge both texts so other fields (DOB, ID, residency) benefit from both passes
                $parsed_text = $parsed_text_e2 . "\n" . $parsed_text_e1;
                $ocr_lower   = normalizeOCR($parsed_text);
                $ocr_clean   = preg_replace('/[^a-z0-9]/', '', $ocr_lower);
            }
        }
    }
}

cleanTemp($processed);
// Clean up all variant temp files
foreach ($all_variants as $v) {
    if (!empty($v['temp']) && !empty($v['path']) && $v['path'] !== $processed['path'] && file_exists($v['path'])) {
        @unlink($v['path']);
    }
}

// ── DOB helper ───────────────────────────────────────────────
// PhilSys card prints date as: JUN 30, 2004 (Month Day, Year)
// OCR may read it as: "jun 30, 2004" or "30 jun 2004" or "06 30 2004"
// All three components (year + month + day) must match.
function dobMatchesOCR(string $entered_dob, string $ocr_raw, string &$method = ''): bool {
    $dob_obj = DateTime::createFromFormat('Y-m-d', $entered_dob);
    if (!$dob_obj) return false;

    $entered_ymd = $dob_obj->format('Y-m-d');
    $entered_y   = (int)$dob_obj->format('Y');
    $entered_m   = (int)$dob_obj->format('m');
    $entered_d   = (int)$dob_obj->format('d');

    $ocr_norm     = strtolower(preg_replace('/\s+/', ' ', $ocr_raw));
    $ocr_stripped = preg_replace('/[^a-z0-9]/', '', $ocr_norm);

    // ── Strategy 0: extract value after DATE OF BIRTH label ──
    // PhilSys card has "DATE OF BIRTH" label — grab the value on same/next line
    $dob_labels = ['date of birth', 'birth date', 'birthdate', 'petsa ng kapanganakan'];
    foreach ($dob_labels as $lbl) {
        $lpos = stripos($ocr_norm, $lbl);
        if ($lpos === false) continue;
        // Grab text after the label (same line + next 2 lines worth)
        $after = substr($ocr_norm, $lpos + strlen($lbl), 60);
        $after = ltrim($after, ":- \t\r\n");
        if (trim($after) !== '') {
            // Try to parse this extracted date string
            $parsed = parseDateString($after);
            if ($parsed && $parsed === $entered_ymd) {
                $method = 'label_extracted'; return true;
            }
        }
        // Also check next line
        $lines = preg_split('/\r?\n/', $ocr_raw);
        foreach ($lines as $li => $line) {
            if (stripos($line, $lbl) !== false) {
                for ($nxt = $li+1; $nxt <= $li+2 && $nxt < count($lines); $nxt++) {
                    $nl = trim($lines[$nxt]);
                    if ($nl !== '') {
                        $parsed = parseDateString(strtolower($nl));
                        if ($parsed && $parsed === $entered_ymd) {
                            $method = 'label_nextline'; return true;
                        }
                        break;
                    }
                }
            }
        }
    }

    // ── Strategy 1: direct format variants ───────────────────
    $variants = [
        $dob_obj->format('Y-m-d'),
        $dob_obj->format('m/d/Y'),
        $dob_obj->format('d/m/Y'),
        $dob_obj->format('n/j/Y'),
        $dob_obj->format('F j, Y'),
        $dob_obj->format('F d, Y'),
        $dob_obj->format('j F Y'),
        $dob_obj->format('d F Y'),
        $dob_obj->format('M j, Y'),
        $dob_obj->format('M d, Y'),
        $dob_obj->format('d M Y'),
        $dob_obj->format('j M Y'),
        $dob_obj->format('d-m-Y'),
        $dob_obj->format('Ymd'),
        $dob_obj->format('Y/m/d'),
        strtoupper($dob_obj->format('M')) . ' ' . $dob_obj->format('j')  . ', ' . $dob_obj->format('Y'),
        strtoupper($dob_obj->format('M')) . ' ' . $dob_obj->format('d')  . ', ' . $dob_obj->format('Y'),
        strtoupper($dob_obj->format('F')) . ' ' . $dob_obj->format('j')  . ', ' . $dob_obj->format('Y'),
        strtoupper($dob_obj->format('F')) . ' ' . $dob_obj->format('d')  . ', ' . $dob_obj->format('Y'),
        $dob_obj->format('d') . ' ' . strtoupper($dob_obj->format('M'))  . ' '  . $dob_obj->format('Y'),
        $dob_obj->format('j') . ' ' . strtoupper($dob_obj->format('M'))  . ' '  . $dob_obj->format('Y'),
    ];

    foreach ($variants as $v) {
        if (stripos($ocr_norm, $v) !== false) {
            $method = 'variant_norm'; return true;
        }
    }
    foreach ($variants as $v) {
        $vs = preg_replace('/[^a-z0-9]/', '', strtolower($v));
        if ($vs !== '' && strpos($ocr_stripped, $vs) !== false) {
            $method = 'variant_stripped'; return true;
        }
    }

    // ── Strategy 2: replace month names → numbers, try ALL orderings ─
    $month_map = [
        'january'=>'01','february'=>'02','march'=>'03','april'=>'04',
        'may'=>'05','june'=>'06','july'=>'07','august'=>'08',
        'september'=>'09','october'=>'10','november'=>'11','december'=>'12',
        'jan'=>'01','feb'=>'02','mar'=>'03','apr'=>'04',
        'jun'=>'06','jul'=>'07','aug'=>'08','sep'=>'09',
        'oct'=>'10','nov'=>'11','dec'=>'12',
    ];
    $ocr_numeric = $ocr_norm;
    uksort($month_map, fn($a,$b) => strlen($b) - strlen($a));
    foreach ($month_map as $name => $num) {
        $ocr_numeric = preg_replace('/\b' . preg_quote($name, '/') . '\b/', $num, $ocr_numeric);
    }

    preg_match_all('/(\d{1,4})[\/\-\s,\.]+(\d{1,2})[\/\-\s,\.]+(\d{2,4})/', $ocr_numeric, $triples, PREG_SET_ORDER);
    foreach ($triples as $m) {
        $a = (int)$m[1]; $b = (int)$m[2]; $c = (int)$m[3];
        $orderings = [
            [$a,$b,$c],[$a,$c,$b],[$c,$a,$b],[$c,$b,$a],[$b,$a,$c],[$b,$c,$a],
        ];
        foreach ($orderings as [$y,$mo,$d]) {
            if ($y < 1900 || $y > 2100) continue;
            if ($mo < 1   || $mo > 12)  continue;
            if ($d  < 1   || $d  > 31)  continue;
            $cand = sprintf('%04d-%02d-%02d', $y, $mo, $d);
            $p = DateTime::createFromFormat('Y-m-d', $cand);
            if ($p && $p->format('Y-m-d') === $entered_ymd) {
                $method = 'token_parse'; return true;
            }
        }
    }

    // ── Strategy 3: month name + year + day in window ────────
    $month_names_entered = [
        strtolower($dob_obj->format('F')),
        strtolower($dob_obj->format('M')),
    ];
    $year_str  = $dob_obj->format('Y');
    $day_pad   = $dob_obj->format('d');
    $day_nopad = (string)$entered_d;

    foreach ($month_names_entered as $mname) {
        $offset = 0;
        while (($pos = stripos($ocr_norm, $mname, $offset)) !== false) {
            $start  = max(0, $pos - 40);
            $window = substr($ocr_norm, $start, 90);
            $wdig   = preg_replace('/[^0-9]/', '', $window);
            if (strpos($window, $year_str) !== false
                && (strpos($wdig, $day_pad) !== false || strpos($wdig, $day_nopad) !== false)) {
                $method = 'month_name_window'; return true;
            }
            $offset = $pos + 1;
        }
    }

    // ── Strategy 4: sliding window over ALL digits in OCR ────
    // Extracts every 8-digit sequence and tries YYYYMMDD, MMDDYYYY, DDMMYYYY
    // This catches cases where OCR strips all separators
    $all_digits = preg_replace('/[^0-9]/', '', $ocr_norm);
    $y4 = sprintf('%04d', $entered_y);
    $m2 = sprintf('%02d', $entered_m);
    $d2 = sprintf('%02d', $entered_d);

    for ($i = 0; $i <= strlen($all_digits) - 8; $i++) {
        $chunk = substr($all_digits, $i, 8);
        // YYYYMMDD
        if (substr($chunk,0,4)===$y4 && substr($chunk,4,2)===$m2 && substr($chunk,6,2)===$d2) {
            $method = 'digits_YYYYMMDD'; return true;
        }
        // MMDDYYYY
        if (substr($chunk,0,2)===$m2 && substr($chunk,2,2)===$d2 && substr($chunk,4,4)===$y4) {
            $method = 'digits_MMDDYYYY'; return true;
        }
        // DDMMYYYY
        if (substr($chunk,0,2)===$d2 && substr($chunk,2,2)===$m2 && substr($chunk,4,4)===$y4) {
            $method = 'digits_DDMMYYYY'; return true;
        }
    }

    // ── Strategy 5: DATE OF BIRTH label → extract day+year, trust entered month ──
    // OCR often garbles the month name (e.g. "JUNE" → "_JV E", "JUN" → "JV")
    // but day and year digits are usually intact.
    // Find the DATE OF BIRTH label line, extract digits from it,
    // check that the entered day AND year are both present.
    // Since we already verified names match, trusting the entered month is safe.
    $dob_label_patterns = [
        'date of birth', 'petsa ng kapanganakan', 'birth date', 'birthdate'
    ];
    $lines = preg_split('/\r?\n/', $ocr_raw);
    foreach ($lines as $li => $line) {
        $ll = strtolower(trim($line));
        $is_dob_line = false;
        foreach ($dob_label_patterns as $pat) {
            if (strpos($ll, $pat) !== false) { $is_dob_line = true; break; }
        }
        if (!$is_dob_line) continue;

        // Check this line and the next 2 lines for day+year digits
        for ($nxt = $li; $nxt <= $li + 2 && $nxt < count($lines); $nxt++) {
            $digits = preg_replace('/[^0-9]/', '', $lines[$nxt]);
            // Day and year must both appear in the digit string
            if (strpos($digits, $d2) !== false && strpos($digits, $y4) !== false) {
                $method = 'label_day_year'; return true;
            }
        }
    }

    return false;
}

// ── Parse a raw date string into Y-m-d ───────────────────────
// Tries multiple formats to handle OCR noise on PhilSys dates.
function parseDateString(string $raw): ?string {
    $raw = trim($raw);
    if ($raw === '') return null;

    $month_map = [
        'january'=>1,'february'=>2,'march'=>3,'april'=>4,'may'=>5,'june'=>6,
        'july'=>7,'august'=>8,'september'=>9,'october'=>10,'november'=>11,'december'=>12,
        'jan'=>1,'feb'=>2,'mar'=>3,'apr'=>4,'jun'=>6,'jul'=>7,'aug'=>8,
        'sep'=>9,'oct'=>10,'nov'=>11,'dec'=>12,
    ];

    // Replace month names with numbers
    $numeric = strtolower($raw);
    uksort($month_map, fn($a,$b) => strlen($b) - strlen($a));
    foreach ($month_map as $name => $num) {
        $numeric = preg_replace('/\b' . preg_quote($name, '/') . '\b/', (string)$num, $numeric);
    }

    // Extract all number groups
    preg_match_all('/\d+/', $numeric, $nums);
    $parts = array_map('intval', $nums[0]);
    if (count($parts) < 3) return null;

    // Try all orderings of first 3 numbers
    $a = $parts[0]; $b = $parts[1]; $c = $parts[2];
    $orderings = [[$a,$b,$c],[$a,$c,$b],[$c,$a,$b],[$c,$b,$a],[$b,$a,$c],[$b,$c,$a]];
    foreach ($orderings as [$y,$mo,$d]) {
        if ($y < 1900 || $y > 2100) continue;
        if ($mo < 1   || $mo > 12)  continue;
        if ($d  < 1   || $d  > 31)  continue;
        $cand = sprintf('%04d-%02d-%02d', $y, $mo, $d);
        $p = DateTime::createFromFormat('Y-m-d', $cand);
        if ($p && $p->format('Y-m-d') === $cand) return $cand;
    }
    return null;
}

// ── Validation ───────────────────────────────────────────────
$errors             = [];
$middle_name_status = 'skipped';

// Extract structured name fields from PhilSys card OCR output
// This compares against the SPECIFIC field value, not the whole blob
$nameFields = extractNameFields($parsed_text);

// Also try Engine 2 text if fields not found
if ($nameFields['first'] === '' && $nameFields['last'] === '') {
    $nameFields = extractNameFields($parsed_text_e2);
}

$ocr_first  = $nameFields['first'];
$ocr_last   = $nameFields['last'];
$ocr_middle = $nameFields['middle'];

// 1. First name
if ($ocr_first !== '') {
    if (!verifyExactTextMatch($entered_first, $ocr_first)) {
        $errors[] = 'First name does not match the National ID.';
    }
} else {
    // Fallback: blob search if label extraction failed
    if (!nameWordsFound($entered_first, $ocr_lower)) {
        $errors[] = 'First name does not match the National ID.';
    }
}

// 2. Last name
if ($ocr_last !== '') {
    if (!verifyExactTextMatch($entered_last, $ocr_last)) {
        $errors[] = 'Last name does not match the National ID.';
    }
} else {
    if (!nameWordsFound($entered_last, $ocr_lower)) {
        $errors[] = 'Last name does not match the National ID.';
    }
}

// 3. Middle name
if ($entered_middle !== '') {
    $ocr_mid_check = $ocr_middle !== '' ? $ocr_middle : $candidate_middle;
    if ($ocr_mid_check !== '') {
        if (verifyExactTextMatch($entered_middle, $ocr_mid_check)) {
            $middle_name_status = 'pass';
        } else {
            $middle_name_status = 'mismatch';
            $errors[] = 'Middle name does not match the National ID.';
        }
    } elseif ($middle_found) {
        $middle_name_status = 'pass';
    } else {
        $middle_name_status = 'not_detected';
        $errors[] = 'Middle name could not be detected from the National ID. Please retry with a clearer photo.';
    }
}

// 4. Date of birth — 4-strategy matching (variant → stripped → token-parse → year+day fuzzy)
$dob_match_method = '';
$dob_matched = dobMatchesOCR($entered_dob, $parsed_text, $dob_match_method);
if (!$dob_matched) {
    $errors[] = 'Date of birth does not match the National ID.';
}

// 5. National ID number — PhilSys is always 16 digits (4×4 groups)
// Sanitize BOTH sides to digits-only before any comparison.
// Three extraction methods in order of confidence:
//   M1 — grouped pattern  : "NNNN-NNNN-NNNN-NNNN" or space-separated
//   M2 — continuous run   : any 16-digit sequence in OCR text
//   M3 — label-based      : digits after a PCN/PhilSys/National ID label
$entered_id_digits  = preg_replace('/[^0-9]/', '', $entered_id); // digits only, no hyphens/spaces
$id_matched         = false;
$id_state           = 'fail';   // exact | fuzzy | fail
$id_lev_distance    = -1;
$extracted_id_candidate = '';
$extraction_method  = 'none';

// M1: grouped pattern — 4 groups of 4 digits with optional hyphen/space separator
preg_match_all('/(\d{4})[\s\-](\d{4})[\s\-](\d{4})[\s\-](\d{4})/', $parsed_text, $id_m1, PREG_SET_ORDER);
foreach ($id_m1 as $match) {
    $cand = $match[1] . $match[2] . $match[3] . $match[4];
    if ($extracted_id_candidate === '') $extracted_id_candidate = $cand;
    if ($cand === $entered_id_digits) {
        $id_matched = true; $id_state = 'exact';
        $extracted_id_candidate = $cand;
        $extraction_method = 'grouped_pattern';
        break;
    }
}

// 5. National ID number — multi-pass extraction with confusion-pair correction
// ─────────────────────────────────────────────────────────────────────────────
// Pipeline:
//   Pass 1 — extract candidates from the primary OCR text (already obtained above)
//   Pass 2 — run OCR on each preprocessing variant and extract candidates from each
//   For each candidate: try exact match → letter-correction → confusion-pair correction
//   Only fall back to Levenshtein fuzzy if all exact/correction attempts fail.
// ─────────────────────────────────────────────────────────────────────────────
$entered_id_digits      = preg_replace('/[^0-9]/', '', $entered_id);
$id_matched             = false;
$id_state               = 'fail';
$id_lev_distance        = -1;
$extracted_id_candidate = '';
$extraction_method      = 'none';
$id_confusion_applied   = false;
$id_debug_passes        = []; // for debug output

// ── Helper: try one candidate against the entered ID ─────────
// Returns updated state array or null if no improvement.
function tryIdCandidate(
    string $cand, string $entered_digits, string $method,
    bool &$matched, string &$state, string &$best_cand,
    string &$best_method, int &$best_lev, bool &$confusion_applied
): void {
    if ($cand === '') return;

    // Step 1: exact match
    if ($cand === $entered_digits) {
        $matched = true; $state = 'exact';
        $best_cand = $cand; $best_method = $method;
        return;
    }

    // Step 2: sanitize OCR letters → digits, then strip to digits only
    $sanitized = stripToDigits(sanitizeOcrId($cand));
    if ($sanitized === $entered_digits) {
        $matched = true; $state = 'exact';
        $best_cand = $sanitized; $best_method = $method . '+sanitized';
        return;
    }

    // Step 3: confusion-pair correction on raw candidate
    if (strlen($cand) === 16 && strlen($entered_digits) === 16) {
        $cp = applyConfusionPairCorrection($cand, $entered_digits);
        if ($cp['exact']) {
            $matched = true; $state = 'exact';
            $best_cand = $cp['corrected']; $best_method = $method . '+' . $cp['method'];
            $confusion_applied = true;
            return;
        }
    }

    // Step 3b: confusion-pair correction on sanitized candidate
    if (strlen($sanitized) === 16 && strlen($entered_digits) === 16 && $sanitized !== $cand) {
        $cp2 = applyConfusionPairCorrection($sanitized, $entered_digits);
        if ($cp2['exact']) {
            $matched = true; $state = 'exact';
            $best_cand = $cp2['corrected']; $best_method = $method . '+sanitized+' . $cp2['method'];
            $confusion_applied = true;
            return;
        }
    }

    // Step 4: track best fuzzy candidate (lowest Levenshtein)
    if (!$matched) {
        $lev = levenshtein($cand, $entered_digits);
        if ($best_cand === '' || $lev < $best_lev) {
            $best_cand = $cand; $best_method = $method; $best_lev = $lev;
        }
        $lev_s = levenshtein($sanitized, $entered_digits);
        if ($lev_s < $best_lev) {
            $best_cand = $sanitized; $best_method = $method . '+sanitized'; $best_lev = $lev_s;
        }
    }
}

// ── Pass 1: candidates from primary OCR text ─────────────────
$pass1_candidates = extractIdCandidates($parsed_text, $entered_id_digits);
$id_debug_passes[] = ['pass' => 'primary_ocr', 'candidate_count' => count($pass1_candidates)];

foreach ($pass1_candidates as $item) {
    if ($id_matched) break;
    tryIdCandidate(
        $item['digits'], $entered_id_digits, 'pass1_' . $item['method'],
        $id_matched, $id_state, $extracted_id_candidate,
        $extraction_method, $id_lev_distance, $id_confusion_applied
    );
}

// ── Pass 2: run OCR on each preprocessing variant ────────────
// Only run additional passes if exact match not yet found.
// Limit to 2 extra API calls to stay within free-tier rate limits.
if (!$id_matched && !$is_pdf) {
    $variant_texts = [];
    $pass_num = 2;
    foreach (array_slice($all_variants, 0, 3) as $variant) {
        if ($id_matched) break;
        // Try Engine 1 on this variant
        $v_ocr = callOCRSpace($variant['path'], $variant['mime'], 1);
        if ($v_ocr === null || ocrResponseFailed($v_ocr)) {
            // Try Engine 2 as fallback for this variant
            $v_ocr = callOCRSpace($variant['path'], $variant['mime'], 2);
        }
        if ($v_ocr === null || ocrResponseFailed($v_ocr)) continue;
        $v_text = trim($v_ocr['ParsedResults'][0]['ParsedText'] ?? '');
        if ($v_text === '') continue;

        $variant_texts[] = $v_text;
        $v_candidates = extractIdCandidates($v_text, $entered_id_digits);
        $id_debug_passes[] = [
            'pass'            => 'variant_' . $pass_num . '_' . $variant['stage'],
            'candidate_count' => count($v_candidates),
        ];

        foreach ($v_candidates as $item) {
            if ($id_matched) break;
            tryIdCandidate(
                $item['digits'], $entered_id_digits,
                'pass' . $pass_num . '_' . $variant['stage'] . '_' . $item['method'],
                $id_matched, $id_state, $extracted_id_candidate,
                $extraction_method, $id_lev_distance, $id_confusion_applied
            );
        }
        $pass_num++;
    }

    // Also merge all variant texts and try extraction on the combined blob
    if (!$id_matched && !empty($variant_texts)) {
        $merged_text = $parsed_text . "\n" . implode("\n", $variant_texts);
        $merged_candidates = extractIdCandidates($merged_text, $entered_id_digits);
        foreach ($merged_candidates as $item) {
            if ($id_matched) break;
            tryIdCandidate(
                $item['digits'], $entered_id_digits, 'merged_' . $item['method'],
                $id_matched, $id_state, $extracted_id_candidate,
                $extraction_method, $id_lev_distance, $id_confusion_applied
            );
        }
    }
}

// ── Final fuzzy decision ──────────────────────────────────────
// Only reach here if no exact/correction match was found across all passes.
if (!$id_matched && $extracted_id_candidate !== '' && $id_lev_distance >= 0) {
    if ($id_lev_distance <= 3) {
        $id_state = 'fuzzy';
    }
    // else id_state stays 'fail'
}

// Hard-fail only when no candidate at all, or Levenshtein > 3
// ID number mismatch is treated as a soft flag (not a hard error) because:
// - OCR frequently misreads digits on National IDs
// - The ID image is stored server-side for admin review
// - All other checks (name, DOB, Bago City) are stronger identity signals
if ($id_state === 'fail') {
    // Only hard-fail if we found zero candidates at all (completely unreadable ID)
    if ($extracted_id_candidate === '') {
        $errors[] = 'National ID number could not be read from the image. Please upload a clearer photo.';
    }
    // If we found a candidate but it didn't match, treat as soft flag (handled below)
}


// 6. Bago City residency — 5-pass tolerant matching
// Pass 1: strong keyword phrases in punctuation-stripped OCR
// Pass 2: "bago" alone + address context word
// Pass 3: raw text direct check
// Pass 4: fuzzy fallback — OCR missed "bago" but local address clues present
// Pass 5: entered barangay name in OCR
$ocr_addr  = strtolower(preg_replace('/[^a-z0-9\s]/', ' ', $parsed_text));
$ocr_addr  = preg_replace('/\s+/', ' ', trim($ocr_addr));
$ocr_raw_l = strtolower($parsed_text);

$bago_found      = false;
$bago_state      = 'fail';   // direct | fallback | fail
$bago_match_pass = '';

// Pass 1 — strong keyword phrases
foreach (['city of bago', 'bago city', 'bago negros occidental', 'city of bago negros'] as $kw) {
    if (strpos($ocr_addr, $kw) !== false) {
        $bago_found = true; $bago_state = 'direct'; $bago_match_pass = 'pass1_phrase'; break;
    }
}

// Pass 2 — "bago" + address context word
if (!$bago_found && strpos($ocr_addr, 'bago') !== false) {
    foreach (['negros', 'occidental', 'city', 'municipality', 'province', 'barangay', 'purok', 'address'] as $ctx) {
        if (strpos($ocr_addr, $ctx) !== false) {
            $bago_found = true; $bago_state = 'direct'; $bago_match_pass = 'pass2_bago_context'; break;
        }
    }
}

// Pass 3 — raw text direct check (before punctuation stripping)
if (!$bago_found) {
    foreach (['city of bago', 'bago city', 'bago, negros', 'go, negros'] as $kw) {
        if (stripos($ocr_raw_l, $kw) !== false) {
            $bago_found = true; $bago_state = 'direct'; $bago_match_pass = 'pass3_raw'; break;
        }
    }
}

// Pass 4 — fuzzy fallback: OCR missed "bago" but local address clues are present.
// Each clue is a known Bago City address fragment. Require ≥ 2 independent hits.
// Covers: "PUROK BALATONG, IL JAN, CITY OCCIDENTAL ... GO, NEGROS" (real OCR output)
// Note: count each clue group as ONE hit max to avoid inflating from overlapping terms.
if (!$bago_found) {
    // Clue groups — only the first match in each group counts as 1 hit
    $clue_groups = [
        // Province clues (any one = 1 hit)
        ['negros occidental', 'negros occ', 'occidental', 'negros'],
        // Barangay name variants (any one = 1 hit)
        ['ilijan', 'il jan', 'il jn', 'il jsn', 'ilian'],
        // Purok name (1 hit)
        ['balatong', 'purok balatong'],
        // Corrupted "city of bago" patterns (1 hit)
        ['city occidental', 'go negros', 'go, negros'],
        // "bago" split — OCR reads "BA" lost + "GO" remaining (1 hit)
        [' go '],
    ];
    $clue_hits = 0;
    $matched_clues = [];
    foreach ($clue_groups as $group) {
        foreach ($group as $clue) {
            if (strpos($ocr_addr, $clue) !== false) {
                $clue_hits++;
                $matched_clues[] = $clue;
                break; // only count once per group
            }
        }
    }
    if ($clue_hits >= 2) {
        $bago_found = true; $bago_state = 'fallback'; $bago_match_pass = 'pass4_fuzzy_clues:' . implode('+', $matched_clues);
    } elseif ($clue_hits === 1) {
        if ($entered_barangay !== '') {
            $bago_found = true; $bago_state = 'fallback'; $bago_match_pass = 'pass4_single_clue_with_barangay:' . implode('+', $matched_clues);
        }
    }
}

// Pass 5 — entered barangay name appears in OCR
if (!$bago_found && $entered_barangay !== '' && strpos($ocr_lower, $entered_barangay) !== false) {
    $bago_found = true; $bago_state = 'fallback'; $bago_match_pass = 'pass5_barangay';
}

// Only hard-fail residency when there is zero evidence.
// fallback state is handled in the final decision block — do NOT push to $errors here.
if (!$bago_found) {
    $errors[] = 'Could not verify Bago City residency from the National ID. Only residents of Bago City may register.';
}

// ── Final decision: verified | failed ────────────────────────
// ALL fields must pass. No manual_review fallback.
// - Names must match (no errors containing name fields)
// - DOB must match
// - National ID must be exact match (id_state === 'exact')
// - Bago City must be directly detected (bago_state !== 'fail')
$names_dob_pass = empty(array_filter($errors, fn($e) =>
    str_contains($e, 'First name') ||
    str_contains($e, 'Last name')  ||
    str_contains($e, 'Middle name') ||
    str_contains($e, 'Date of birth')
));

// ID must be exact — fuzzy/fail both count as failure
if ($id_state !== 'exact') {
    $errors[] = 'National ID number could not be confirmed with full confidence. Please ensure the number you entered exactly matches your uploaded ID.';
}

// Bago City must be directly detected — fallback inference is not accepted
if ($bago_state === 'fallback') {
    $errors[] = 'Bago City residency could not be directly confirmed from your ID. Only verified Bago City residents may register.';
}

// Determine final state — only two outcomes: verified or failed
if (empty($errors) && $names_dob_pass && $id_state === 'exact' && $bago_found) {
    $final_state = 'verified';
} else {
    $final_state = 'failed';
}

$verified = ($final_state === 'verified');
// Only grant session verification when:
// - final state is exactly 'verified' (not manual_review)
// - AND Bago City residency was confirmed by OCR
// manual_review, failed, or non-Bago results must NOT set ocr_verified.
if ($verified && $bago_found) {
    $_SESSION['ocr_verified']    = true;
    $_SESSION['ocr_national_id'] = $entered_id;
    $_SESSION['ocr_final_state'] = $final_state;
    $_SESSION['ocr_bago_city']   = true;
} else {
    // Explicitly clear any previous session so a stale pass cannot carry over
    unset($_SESSION['ocr_verified'], $_SESSION['ocr_national_id'],
          $_SESSION['ocr_final_state'], $_SESSION['ocr_bago_city']);
}

if (OCR_DEBUG) {
    $ocr_debug['parsed_fields'] = [
        // Names
        'first_name_in_ocr'     => nameWordsFound($entered_first, $ocr_lower),
        'last_name_in_ocr'      => nameWordsFound($entered_last,  $ocr_lower),
        'middle_candidate'      => $candidate_middle ?: '(none)',
        'middle_candidate_norm' => normalizeName($candidate_middle),
        'entered_middle_norm'   => $entered_middle_norm,
        'middle_found'          => $middle_found,
        // DOB
        'entered_dob_raw'       => $entered_dob,
        'dob_match_method'      => $dob_match_method ?: 'none',
        'dob_matched'           => $dob_matched,
        'dob_ocr_snippet'       => (function() use ($parsed_text, $entered_dob) {
            // Extract a snippet of OCR text around where the date might be
            $dob_obj = DateTime::createFromFormat('Y-m-d', $entered_dob);
            if (!$dob_obj) return 'invalid_dob';
            $year = $dob_obj->format('Y');
            $pos  = strpos(strtolower($parsed_text), $year);
            if ($pos === false) return 'year_not_found_in_ocr';
            return substr($parsed_text, max(0, $pos-30), 80);
        })(),
        // ID number
        'entered_id_digits'      => $entered_id_digits,
        'extracted_id_candidate' => $extracted_id_candidate ?: '(none found)',
        'extraction_method'      => $extraction_method,
        'id_state'               => $id_state,
        'id_lev_distance'        => $id_lev_distance,
        'id_matched'             => ($id_state !== 'fail'),
        'id_confusion_applied'   => $id_confusion_applied,
        'id_ocr_passes'          => $id_debug_passes,
        // Residency
        'ocr_addr_normalized'   => substr($ocr_addr, 0, 200),
        'bago_match_pass'       => $bago_match_pass ?: 'none',
        'bago_state'            => $bago_state,
        'bago_found'            => $bago_found,        // Final decision
        'final_state'           => $final_state,
        'manual_review_reasons' => [],
        // Raw OCR (first 500 chars)
        'raw_ocr_sample'        => substr($parsed_text, 0, 500),
    ];
}

// ── Persistence: Save activity log ───────────────────────────
try {
    $id_hash = hash('sha256', $entered_id_digits);
    $ip      = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    $ua      = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
    $detail  = json_encode([
        'final_state' => $final_state,
        'bago_found'  => $bago_found,
        'id_state'    => $id_state,
        'errors'      => $errors,
        'method'      => $bago_match_pass
    ]);

    $stmt = $pdo->prepare("
        INSERT INTO registration_activity_logs 
            (action, result, detail, national_id_hash, ip_address, user_agent, created_at)
        VALUES ('ocr_verification', ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$final_state, $detail, $id_hash, $ip, $ua]);
} catch (Exception $e) { /* non-fatal */ }

ob_clean(); echo json_encode([
    'success'              => true,
    'verified'             => $verified,
    'final_state'          => $final_state,
    'manual_review'        => false,
    'manual_review_reasons'=> [],
    'bago_city'            => $bago_found,
    'bago_state'           => $bago_state,
    'id_state'             => $id_state,
    'middle_name_status'   => $middle_name_status,
    'errors'               => $errors,
    'parsed_text'          => $parsed_text,
    'extracted_id'         => $extracted_id_candidate,
    'ocr_debug'            => OCR_DEBUG ? $ocr_debug : null,
]);

