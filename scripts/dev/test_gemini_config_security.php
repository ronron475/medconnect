<?php
/**
 * Focused Gemini configuration/security checks (no live Google API calls).
 *
 * Verifies:
 * - Canonical key priority: AI_API_KEY → GEMINI_API_KEY → GOOGLE_API_KEY
 * - Model ignores non-gemini AI_MODEL values
 * - PHP clients + ai_providers resolve the same key/model
 * - Python gemini_client + faq_chatbot share the same resolution
 * - Keys are not embedded in public JS / browser assets
 *
 * Run: php scripts/dev/test_gemini_config_security.php
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

function clearGeminiEnv(): void
{
    foreach (['AI_API_KEY', 'GEMINI_API_KEY', 'GOOGLE_API_KEY', 'AI_MODEL'] as $k) {
        putenv($k);
        unset($_ENV[$k]);
    }
}

function setEnv(string $k, string $v): void
{
    putenv("{$k}={$v}");
    $_ENV[$k] = $v;
}

require_once $root . '/app/includes/gemini_config.php';
require_once $root . '/app/includes/ai_providers.php';

// ── 1) Key priority: AI_API_KEY wins over aliases ─────────────
clearGeminiEnv();
setEnv('AI_API_KEY', 'primary-ai-key');
setEnv('GEMINI_API_KEY', 'alias-gemini-key');
setEnv('GOOGLE_API_KEY', 'alias-google-key');
if (medconnect_gemini_api_key() === 'primary-ai-key'
    && ai_providers_gemini_api_key() === 'primary-ai-key'
) {
    pass('AI_API_KEY takes priority over GEMINI_API_KEY / GOOGLE_API_KEY');
} else {
    fail('AI_API_KEY takes priority', medconnect_gemini_api_key());
}

// ── 2) Alias fallback when AI_API_KEY empty ───────────────────
clearGeminiEnv();
setEnv('GEMINI_API_KEY', 'alias-gemini-key');
setEnv('GOOGLE_API_KEY', 'alias-google-key');
if (medconnect_gemini_api_key() === 'alias-gemini-key') {
    pass('GEMINI_API_KEY used when AI_API_KEY unset');
} else {
    fail('GEMINI_API_KEY fallback', medconnect_gemini_api_key());
}

clearGeminiEnv();
setEnv('GOOGLE_API_KEY', 'alias-google-key');
if (medconnect_gemini_api_key() === 'alias-google-key') {
    pass('GOOGLE_API_KEY used when other keys unset');
} else {
    fail('GOOGLE_API_KEY fallback', medconnect_gemini_api_key());
}

clearGeminiEnv();
if (medconnect_gemini_api_key() === '' && !medconnect_gemini_key_configured()) {
    pass('empty key reports not configured');
} else {
    fail('empty key reports not configured');
}

// ── 3) Model: gemini AI_MODEL honored; non-gemini ignored ─────
clearGeminiEnv();
setEnv('AI_MODEL', 'gemini-3.5-flash');
if (medconnect_gemini_model() === 'gemini-3.5-flash'
    && ai_providers_gemini_model() === 'gemini-3.5-flash'
) {
    pass('AI_MODEL gemini-* is used');
} else {
    fail('AI_MODEL gemini-* is used', medconnect_gemini_model());
}

clearGeminiEnv();
setEnv('AI_MODEL', 'llama-3.1-8b-instant');
if (medconnect_gemini_model() === MEDCONNECT_GEMINI_DEFAULT_MODEL) {
    pass('non-gemini AI_MODEL falls back to default Gemini model');
} else {
    fail('non-gemini AI_MODEL ignored', medconnect_gemini_model());
}

clearGeminiEnv();
if (medconnect_gemini_model() === MEDCONNECT_GEMINI_DEFAULT_MODEL) {
    pass('unset AI_MODEL uses default Gemini model');
} else {
    fail('unset AI_MODEL default', medconnect_gemini_model());
}

// ── 4) PHP production clients resolve the same key + model ────
$clientFiles = [
    'ClinicalInterviewGeminiFollowUp.php',
    'GeminiComplaintInputValidator.php',
    'GeminiBodyLocationVerifier.php',
    'NlpStep3DemoGeminiAnswerInterpreter.php',
    'GeminiClinicalInterviewDemo.php',
    'FaqChatbotAiFallback.php',
];
foreach ($clientFiles as $file) {
    $path = $root . '/app/core/' . $file;
    if (!is_file($path)) {
        fail("client file exists: {$file}");
        continue;
    }
    require_once $path;
}

clearGeminiEnv();
setEnv('AI_API_KEY', 'shared-prod-key');
setEnv('GEMINI_API_KEY', 'should-not-win');
setEnv('AI_MODEL', 'gemini-3.5-flash');

$refCalls = [
    'ClinicalInterviewGeminiFollowUp' => ['apiKey', 'model'],
    'GeminiComplaintInputValidator'   => ['apiKey', 'model'],
    'GeminiBodyLocationVerifier'      => ['apiKey', 'model'],
    'NlpStep3DemoGeminiAnswerInterpreter' => ['apiKey', 'model'],
    'GeminiClinicalInterviewDemo'     => ['apiKey'],
    'FaqChatbotAiFallback'            => ['geminiKey'],
];

$allMatch = true;
$details = [];
foreach ($refCalls as $class => $methods) {
    if (!class_exists($class)) {
        $allMatch = false;
        $details[] = "{$class} missing";
        continue;
    }
    foreach ($methods as $method) {
        $ref = new ReflectionMethod($class, $method);
        $ref->setAccessible(true);
        $got = (string) $ref->invoke(null);
        $expected = $method === 'model' ? 'gemini-3.5-flash' : 'shared-prod-key';
        if ($got !== $expected) {
            $allMatch = false;
            $details[] = "{$class}::{$method}={$got}";
        }
    }
}

// FaqChatbotAiFallback::model() when provider=gemini
putenv('AI_PROVIDER=gemini');
$_ENV['AI_PROVIDER'] = 'gemini';
$faqModel = FaqChatbotAiFallback::model();
if ($faqModel !== 'gemini-3.5-flash') {
    $allMatch = false;
    $details[] = "FaqChatbotAiFallback::model={$faqModel}";
}

if ($allMatch) {
    pass('PHP Gemini clients share canonical key/model resolution');
} else {
    fail('PHP Gemini clients share canonical key/model', implode('; ', $details));
}

// Prefer AI_API_KEY even when clients previously preferred GEMINI_API_KEY
clearGeminiEnv();
setEnv('AI_API_KEY', 'from-ai-api-key');
setEnv('GEMINI_API_KEY', 'from-gemini-api-key');
$ref = new ReflectionMethod('ClinicalInterviewGeminiFollowUp', 'apiKey');
$ref->setAccessible(true);
if ($ref->invoke(null) === 'from-ai-api-key') {
    pass('follow-up client prefers AI_API_KEY over GEMINI_API_KEY');
} else {
    fail('follow-up client prefers AI_API_KEY', (string) $ref->invoke(null));
}

// Non-gemini AI_MODEL must not leak into Gemini clients
clearGeminiEnv();
setEnv('AI_API_KEY', 'k');
setEnv('AI_MODEL', 'openai/gpt-oss-120b');
$ref = new ReflectionMethod('GeminiComplaintInputValidator', 'model');
$ref->setAccessible(true);
if ($ref->invoke(null) === MEDCONNECT_GEMINI_DEFAULT_MODEL) {
    pass('complaint validator ignores non-gemini AI_MODEL');
} else {
    fail('complaint validator ignores non-gemini AI_MODEL', (string) $ref->invoke(null));
}

// ── 5) No Gemini secrets in public assets ─────────────────────
$publicJsRoot = $root . '/public/assets/js';
$leaks = [];
$patterns = [
    '/GEMINI_API_KEY\s*[:=]/',
    '/AI_API_KEY\s*[:=]/',
    '/GOOGLE_API_KEY\s*[:=]/',
    '/x-goog-api-key/i',
    '/generativelanguage\.googleapis\.com/',
    '/AIza[0-9A-Za-z_-]{20,}/',
];
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($publicJsRoot));
foreach ($rii as $fileInfo) {
    if (!$fileInfo->isFile()) {
        continue;
    }
    $ext = strtolower($fileInfo->getExtension());
    if (!in_array($ext, ['js', 'mjs', 'css', 'html'], true)) {
        continue;
    }
    $contents = (string) file_get_contents($fileInfo->getPathname());
    foreach ($patterns as $pat) {
        if (preg_match($pat, $contents)) {
            $leaks[] = str_replace('\\', '/', substr($fileInfo->getPathname(), strlen($root) + 1))
                . ' matches ' . $pat;
        }
    }
}
if ($leaks === []) {
    pass('public assets do not embed Gemini keys or direct Gemini API URLs');
} else {
    fail('public assets Gemini leak', implode('; ', array_slice($leaks, 0, 5)));
}

// gemini_config / ai_providers never return key material in catalog status
clearGeminiEnv();
setEnv('AI_API_KEY', 'super-secret-gemini-key-value');
$snapshot = ai_providers_live_snapshot(false);
$encoded = json_encode($snapshot);
$gemRow = null;
foreach (($snapshot['providers'] ?? []) as $row) {
    if (($row['id'] ?? '') === 'gemini') {
        $gemRow = $row;
        break;
    }
}
if (is_string($encoded)
    && !str_contains($encoded, 'super-secret-gemini-key-value')
    && is_array($gemRow)
    && !empty($gemRow['api_key_set'])
) {
    pass('ai_providers status exposes key presence only, not the secret');
} else {
    fail('ai_providers status must not leak API key');
}

// ── 6) Python clients share gemini_client resolution ──────────
$pyScript = <<<'PY'
import os, sys
sys.path.insert(0, os.path.join(os.environ["MC_ROOT"], "ai_service"))
os.environ.pop("AI_API_KEY", None)
os.environ.pop("GEMINI_API_KEY", None)
os.environ.pop("GOOGLE_API_KEY", None)
os.environ.pop("AI_MODEL", None)
os.environ["AI_API_KEY"] = "py-primary"
os.environ["GEMINI_API_KEY"] = "py-alias"
os.environ["AI_MODEL"] = "llama-3.1-8b-instant"
from gemini_client import gemini_api_key, gemini_model_name
from faq_chatbot import gemini_key, gemini_model
assert gemini_api_key() == "py-primary", gemini_api_key()
assert gemini_key() == "py-primary", gemini_key()
assert gemini_model_name() == "gemini-3.5-flash", gemini_model_name()
assert gemini_model() == "gemini-3.5-flash", gemini_model()
print("OK")
PY;

$tmp = tempnam(sys_get_temp_dir(), 'mc_gem');
if ($tmp === false) {
    fail('python temp script create');
} else {
    $pyFile = $tmp . '.py';
    @rename($tmp, $pyFile);
    file_put_contents($pyFile, $pyScript);
    $cmd = 'python "' . $pyFile . '"';
    $env = ['MC_ROOT' => $root];
    // Merge into process env for Windows.
    putenv('MC_ROOT=' . $root);
    $out = [];
    $code = 0;
    exec($cmd . ' 2>&1', $out, $code);
    @unlink($pyFile);
    $joined = trim(implode("\n", $out));
    if ($code === 0 && $joined === 'OK') {
        pass('Python gemini_client and faq_chatbot share key/model resolution');
    } else {
        fail('Python gemini_client and faq_chatbot share resolution', "exit={$code} out={$joined}");
    }
}

// ── 7) Source audit: no reversed key priority left ────────────
$scanFiles = array_merge(
    glob($root . '/app/core/Gemini*.php') ?: [],
    glob($root . '/app/core/*Gemini*.php') ?: [],
    [
        $root . '/app/core/FaqChatbotAiFallback.php',
        $root . '/app/core/ClinicalInterviewGeminiFollowUp.php',
        $root . '/app/includes/ai_providers.php',
        $root . '/app/includes/gemini_config.php',
        $root . '/ai_service/gemini_client.py',
        $root . '/ai_service/faq_chatbot.py',
    ]
);
$reversed = [];
// Only flag the old envString/call order on a single logical call (not file-wide).
$reversedPats = [
    "/envString\(\s*['\"]GEMINI_API_KEY['\"]\s*,\s*self::envString\(\s*['\"]GOOGLE_API_KEY['\"]\s*,\s*self::envString\(\s*['\"]AI_API_KEY['\"]/",
    "/_env\(\s*[\"']GEMINI_API_KEY[\"']\s*,\s*[\"']GOOGLE_API_KEY[\"']\s*,\s*[\"']AI_API_KEY[\"']/",
    "/foreach\s*\(\s*\[\s*['\"]GEMINI_API_KEY['\"]\s*,\s*['\"]GOOGLE_API_KEY['\"]\s*,\s*['\"]AI_API_KEY['\"]/",
];
foreach (array_unique($scanFiles) as $path) {
    if (!is_file($path)) {
        continue;
    }
    $src = (string) file_get_contents($path);
    foreach ($reversedPats as $pat) {
        if (preg_match($pat, $src)) {
            $reversed[] = basename($path);
            break;
        }
    }
}
if ($reversed === []) {
    pass('no reversed Gemini key priority (GEMINI before AI_API_KEY) in clients');
} else {
    fail('reversed Gemini key priority found', implode(', ', $reversed));
}

echo "\n" . ($failures === 0 ? 'ALL PASS' : "FAILED ({$failures})") . "\n";
exit($failures === 0 ? 0 : 1);
