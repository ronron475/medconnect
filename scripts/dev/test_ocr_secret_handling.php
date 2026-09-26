<?php
/**
 * Focused checks: OCR secret handling (no network calls to OCR.Space).
 *
 * Run: php scripts/dev/test_ocr_secret_handling.php
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$failures = 0;

function pass(string $label): void
{
    echo "PASS  {$label}\n";
}

function fail(string $label, string $detail = ''): void
{
    global $failures;
    $failures++;
    echo "FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

// ── 1) .env is gitignored ─────────────────────────────────────
$gi = file_get_contents($root . '/.gitignore') ?: '';
if (preg_match('/^\.env\s*$/m', $gi)) {
    pass('.env is listed in .gitignore');
} else {
    fail('.env is listed in .gitignore');
}

// ── 2) No hardcoded OCR.Space key literals in tracked sources ─
$scanPaths = [
    $root . '/config/ocr_config.php',
    $root . '/ai_service/ocr/ocr_space_client.py',
    $root . '/app/controllers/patient/process_id_ocr.php',
    $root . '/.env.example',
];
$literalKeyPattern = "/OCR_SPACE_API_KEY['\"]\\s*,\\s*['\"][^'\"]+['\"]/";
$assignKeyPattern = '/OCR_SPACE_API_KEY\\s*=\\s*[\'"][^\'"]+[\'"]/';
$hardcodedFound = false;
foreach ($scanPaths as $path) {
    if (!is_readable($path)) {
        continue;
    }
    $rel = str_replace($root . DIRECTORY_SEPARATOR, '', $path);
    $text = file_get_contents($path) ?: '';
    if (preg_match($literalKeyPattern, $text) || preg_match($assignKeyPattern, $text)) {
        $hardcodedFound = true;
        fail('no hardcoded OCR_SPACE_API_KEY value in ' . $rel);
    }
}
if (!$hardcodedFound) {
    pass('no hardcoded OCR_SPACE_API_KEY values in OCR config/client paths');
}

$configSrc = file_get_contents($root . '/config/ocr_config.php') ?: '';
if (preg_match("/define\\(\\s*'OCR_SPACE_API_KEY'\\s*,\\s*'[^']+'/", $configSrc)
    || preg_match('/define\\(\\s*"OCR_SPACE_API_KEY"\\s*,\\s*"[^"]+"/', $configSrc)
) {
    fail('ocr_config.php must not define OCR_SPACE_API_KEY with a string literal');
} else {
    pass('ocr_config.php loads OCR_SPACE_API_KEY from environment');
}

if (!str_contains($configSrc, 'getenv') || !str_contains($configSrc, 'OCR_SPACE_API_KEY')) {
    fail('ocr_config.php uses getenv for OCR_SPACE_API_KEY');
} else {
    pass('ocr_config.php references getenv for OCR_SPACE_API_KEY');
}

$pySrc = file_get_contents($root . '/ai_service/ocr/ocr_space_client.py') ?: '';
if (str_contains($pySrc, 'ocr_config.php') || str_contains($pySrc, 're.search')) {
    fail('Python OCR client must not scrape PHP config for the API key');
} else {
    pass('Python OCR client uses environment only (no PHP scrape)');
}

// ── 3) OCR_DEBUG defaults false ───────────────────────────────
putenv('OCR_DEBUG');
unset($_ENV['OCR_DEBUG']);
// Isolate: load config in a subprocess with clean env for default
$php = PHP_BINARY;
$probeDefault = <<<'PHP'
<?php
putenv('OCR_DEBUG');
unset($_ENV['OCR_DEBUG']);
putenv('OCR_SPACE_API_KEY');
unset($_ENV['OCR_SPACE_API_KEY']);
require $argv[1];
echo OCR_DEBUG ? '1' : '0';
echo "\n";
echo OCR_SPACE_API_KEY === '' ? 'empty' : 'set';
PHP;
$tmp = tempnam(sys_get_temp_dir(), 'ocrprobe');
file_put_contents($tmp, $probeDefault);
$cmd = escapeshellarg($php) . ' ' . escapeshellarg($tmp) . ' ' . escapeshellarg($root . '/config/ocr_config.php');
$out = [];
exec($cmd . ' 2>&1', $out, $code);
@unlink($tmp);
$lines = array_values(array_filter(array_map('trim', $out), static fn ($l) => $l !== ''));
$debugDefault = $lines[0] ?? '';
$keyDefault = $lines[1] ?? '';
if ($code === 0 && $debugDefault === '0') {
    pass('OCR_DEBUG defaults to false');
} else {
    fail('OCR_DEBUG defaults to false', 'got debug=' . $debugDefault . ' exit=' . $code . ' out=' . implode('|', $lines));
}
if ($code === 0 && $keyDefault === 'empty') {
    pass('OCR_SPACE_API_KEY empty when unset in environment');
} else {
    fail('OCR_SPACE_API_KEY empty when unset', 'got=' . $keyDefault);
}

// ── 4) Env override works ─────────────────────────────────────
$probeEnv = <<<'PHP'
<?php
putenv('OCR_DEBUG=1');
$_ENV['OCR_DEBUG'] = '1';
putenv('OCR_SPACE_API_KEY=test-secret-not-real');
$_ENV['OCR_SPACE_API_KEY'] = 'test-secret-not-real';
require $argv[1];
echo OCR_DEBUG ? '1' : '0';
echo "\n";
echo OCR_SPACE_API_KEY;
PHP;
$tmp = tempnam(sys_get_temp_dir(), 'ocrprobe');
file_put_contents($tmp, $probeEnv);
$cmd = escapeshellarg($php) . ' ' . escapeshellarg($tmp) . ' ' . escapeshellarg($root . '/config/ocr_config.php');
$out = [];
exec($cmd . ' 2>&1', $out, $code);
@unlink($tmp);
$lines = array_values(array_filter(array_map('trim', $out), static fn ($l) => $l !== ''));
if ($code === 0 && ($lines[0] ?? '') === '1' && ($lines[1] ?? '') === 'test-secret-not-real') {
    pass('OCR_DEBUG and OCR_SPACE_API_KEY honor environment overrides');
} else {
    fail('env overrides', implode('|', $lines));
}

// ── 5) Verify response gates parsed_text / ocr_debug ──────────
$ctrl = file_get_contents($root . '/app/controllers/patient/process_id_ocr.php') ?: '';
if (preg_match("/'parsed_text'\\s*=>\\s*OCR_DEBUG\\s*\\?\\s*\\\$parsed_text/", $ctrl)
    && preg_match("/'ocr_debug'\\s*=>\\s*OCR_DEBUG\\s*\\?\\s*\\\$ocr_debug/", $ctrl)
) {
    pass('verify JSON gates parsed_text and ocr_debug behind OCR_DEBUG');
} else {
    fail('verify JSON must gate parsed_text and ocr_debug behind OCR_DEBUG');
}
// Ensure no ungated parsed_text assignment in final json_encode block
if (preg_match("/'parsed_text'\\s*=>\\s*\\\$parsed_text\\s*,/", $ctrl)) {
    fail('ungated parsed_text still present in process_id_ocr.php response');
} else {
    pass('no ungated parsed_text in process_id_ocr.php JSON responses');
}

// ── 6) Repo-wide: no other hardcoded OCR key define ───────────
$rg = null;
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/config', FilesystemIterator::SKIP_DOTS)
);
$extraHardcode = false;
foreach ($iterator as $file) {
    /** @var SplFileInfo $file */
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $c = file_get_contents($file->getPathname()) ?: '';
    if (preg_match("/OCR_SPACE_API_KEY['\"]\\s*,\\s*['\"][^'\"]+['\"]/", $c)) {
        $extraHardcode = true;
        fail('no string-literal OCR_SPACE_API_KEY in ' . $file->getFilename());
    }
}
if (!$extraHardcode) {
    pass('no OCR_SPACE_API_KEY string literals under config/');
}

echo "\n";
if ($failures === 0) {
    echo "PASS — all OCR secret handling checks passed\n";
    exit(0);
}
echo "FAIL — {$failures} check(s) failed\n";
exit(1);
