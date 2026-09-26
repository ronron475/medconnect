<?php
/**
 * Focused upload-security helper tests.
 * Run: php scripts/dev/test_upload_security.php
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$failures = 0;

function pass(string $m): void
{
    echo "PASS  {$m}\n";
}

function fail(string $m, string $d = ''): void
{
    global $failures;
    $failures++;
    echo "FAIL  {$m}" . ($d !== '' ? " — {$d}" : '') . "\n";
}

require_once $root . '/app/includes/upload_security.php';

$tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mc_upload_sec_' . bin2hex(random_bytes(4));
mkdir($tmpDir, 0777, true);

$jpegPath = $tmpDir . DIRECTORY_SEPARATOR . 'sample.jpg';
// Minimal JPEG
$jpegB64 = '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAn/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAGfAP/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAQUCf//EABQRAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQMBAT8Bf//EABQRAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQIBAT8Bf//Z';
file_put_contents($jpegPath, base64_decode($jpegB64));

$phpPath = $tmpDir . DIRECTORY_SEPARATOR . 'evil.php';
file_put_contents($phpPath, "<?php echo 1;");

$pdfPath = $tmpDir . DIRECTORY_SEPARATOR . 'doc.pdf';
file_put_contents($pdfPath, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");

$mimeJpeg = upload_security_detect_mime($jpegPath);
if (str_contains($mimeJpeg, 'jpeg') || str_contains($mimeJpeg, 'image/')) {
    pass('finfo detects JPEG mime');
} else {
    fail('finfo detects JPEG mime', $mimeJpeg);
}

$mimePhp = upload_security_detect_mime($phpPath);
if ($mimePhp !== 'image/jpeg' && $mimePhp !== 'application/pdf') {
    pass('PHP script is not detected as image/pdf');
} else {
    fail('PHP script is not detected as image/pdf', $mimePhp);
}

if (upload_security_filename_is_dangerous('shell.php')) {
    pass('rejects .php filename');
} else {
    fail('rejects .php filename');
}
if (upload_security_filename_is_dangerous('photo.php.jpg')) {
    pass('rejects multi-ext .php.jpg');
} else {
    fail('rejects multi-ext .php.jpg');
}
if (!upload_security_filename_is_dangerous('report.pdf')) {
    pass('allows normal .pdf filename');
} else {
    fail('allows normal .pdf filename');
}

// validate() with requireUploaded=false for local temp fixtures
$okJpeg = upload_security_validate([
    'error' => UPLOAD_ERR_OK,
    'tmp_name' => $jpegPath,
    'size' => filesize($jpegPath),
    'name' => 'id.jpg',
    'type' => 'application/octet-stream', // spoofed client type ignored
], [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'application/pdf' => 'pdf',
], 5 * 1024 * 1024, false);
if ($okJpeg['ok'] && $okJpeg['ext'] === 'jpg') {
    pass('validate accepts JPEG via finfo despite spoofed client MIME');
} else {
    fail('validate accepts JPEG via finfo despite spoofed client MIME', $okJpeg['message']);
}

$badPhp = upload_security_validate([
    'error' => UPLOAD_ERR_OK,
    'tmp_name' => $phpPath,
    'size' => filesize($phpPath),
    'name' => 'shell.php',
    'type' => 'image/jpeg',
], [
    'image/jpeg' => 'jpg',
    'application/pdf' => 'pdf',
], 5 * 1024 * 1024, false);
if (!$badPhp['ok']) {
    pass('validate rejects PHP script upload');
} else {
    fail('validate rejects PHP script upload');
}

$badExtName = upload_security_validate([
    'error' => UPLOAD_ERR_OK,
    'tmp_name' => $jpegPath,
    'size' => filesize($jpegPath),
    'name' => 'photo.php.jpg',
    'type' => 'image/jpeg',
], [
    'image/jpeg' => 'jpg',
], 5 * 1024 * 1024, false);
if (!$badExtName['ok']) {
    pass('validate rejects dangerous multi-extension name');
} else {
    fail('validate rejects dangerous multi-extension name');
}

$tooBig = upload_security_validate([
    'error' => UPLOAD_ERR_OK,
    'tmp_name' => $jpegPath,
    'size' => 50,
    'name' => 'id.jpg',
    'type' => 'image/jpeg',
], [
    'image/jpeg' => 'jpg',
], 10, false);
// size from $_FILES is used; we pass size 50 > 10
if (!$tooBig['ok']) {
    pass('validate enforces max size');
} else {
    fail('validate enforces max size');
}

$safe = upload_security_safe_filename('bhw_12', 'pdf');
if (preg_match('/^bhw_12_[a-f0-9]{16}\.pdf$/', $safe) && !str_contains($safe, '..') && !str_contains($safe, '/')) {
    pass('safe filename is opaque and extension-bound');
} else {
    fail('safe filename is opaque and extension-bound', $safe);
}

$store = $tmpDir . DIRECTORY_SEPARATOR . 'store';
upload_security_ensure_dir($store, 0750);
file_put_contents($store . DIRECTORY_SEPARATOR . 'ok.pdf', "%PDF-1.4\n");
$confined = upload_security_confine_path($store, 'ok.pdf');
if ($confined !== null && is_file($confined)) {
    pass('confine_path accepts file inside dir');
} else {
    fail('confine_path accepts file inside dir');
}
if (upload_security_confine_path($store, '../outside.pdf') === null
    && upload_security_confine_path($store, '..\\..\\windows\\system.ini') === null
    && upload_security_confine_path($store, 'subdir/../../evil.pdf') === null
) {
    pass('confine_path rejects traversal names');
} else {
    fail('confine_path rejects traversal names');
}

// Endpoint wiring source checks
$wired = [
    'app/api/bhw/records.php' => ['upload_security_validate', 'upload_security_safe_filename', 'upload_security_confine_path'],
    'app/api/consultations/upload_recording.php' => ['upload_security_validate', 'upload_security_ensure_dir'],
    'app/api/faq_chatbot_voice.php' => ['upload_security_validate'],
    'app/api/ai/transcribe_chunk.php' => ['upload_security_validate'],
];
foreach ($wired as $rel => $needles) {
    $src = file_get_contents($root . '/' . $rel) ?: '';
    $missing = [];
    foreach ($needles as $n) {
        if (!str_contains($src, $n)) {
            $missing[] = $n;
        }
    }
    if ($missing === []) {
        pass("{$rel} uses upload_security helpers");
    } else {
        fail("{$rel} uses upload_security helpers", implode(',', $missing));
    }
}
if (!str_contains(file_get_contents($root . '/app/api/consultations/upload_recording.php') ?: '', '0777')) {
    pass('recording upload no longer uses mkdir 0777');
} else {
    fail('recording upload no longer uses mkdir 0777');
}

// cleanup
@unlink($jpegPath);
@unlink($phpPath);
@unlink($pdfPath);
@unlink($store . DIRECTORY_SEPARATOR . 'ok.pdf');
@rmdir($store);
@rmdir($tmpDir);

echo "\n";
if ($failures === 0) {
    echo "PASS — all upload security checks passed\n";
    exit(0);
}
echo "FAIL — {$failures} check(s) failed\n";
exit(1);
