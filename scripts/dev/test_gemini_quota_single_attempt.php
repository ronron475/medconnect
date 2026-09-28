<?php
/**
 * One demo interview start must make at most one Gemini generateContent attempt
 * when Google returns HTTP 429. No live Gemini call is made.
 *
 * Usage: php scripts/dev/test_gemini_quota_single_attempt.php
 */
require __DIR__ . '/nlp_cli_bootstrap.php';

putenv('MEDCONNECT_PHP_NLP_ONLY=0');
$_ENV['MEDCONNECT_PHP_NLP_ONLY'] = '0';

$fail = 0;
$pass = 0;

function ok(string $label, bool $cond, string $detail = ''): void
{
    global $fail, $pass;
    if ($cond) {
        echo "PASS  $label" . ($detail !== '' ? " [$detail]" : '') . "\n";
        $pass++;
        return;
    }
    echo "FAIL  $label" . ($detail !== '' ? " [$detail]" : '') . "\n";
    $fail++;
}

GeminiClinicalInterviewDemo::beginGeminiQuotaProbeForTest();
try {
    $started = GeminiClinicalInterviewDemo::start('I have a headache');
} finally {
    $calls = GeminiClinicalInterviewDemo::geminiGenerateAttemptsForTest();
    GeminiClinicalInterviewDemo::endGeminiQuotaProbeForTest();
}

echo "Gemini calls observed: $calls\n";
ok('at most one Gemini generate attempt', $calls <= 1, (string) $calls);
ok('exactly one Gemini generate attempt', $calls === 1, (string) $calls);
ok(
    'quota result code',
    ($started['code'] ?? '') === 'gemini_quota_exceeded',
    (string) ($started['code'] ?? '')
);
ok('interview not started', ($started['awaiting_question'] ?? '') === '' && ($started['status'] ?? '') !== 'interviewing');
ok('triage not finalized', !is_array($started['final_triage'] ?? null) && ($started['status'] ?? '') !== 'final_triage');

$ai = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'ai_service';
$aiQuoted = var_export($ai, true);
$python = <<<PY
import sys
sys.path.insert(0, {$aiQuoted})
from io import BytesIO
import urllib.error
from unittest.mock import patch
import gemini_client

calls = {"n": 0}

def fake_post(payload, model, key, timeout):
    calls["n"] += 1
    raise urllib.error.HTTPError(
        "https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash:generateContent",
        429,
        "Too Many Requests",
        hdrs=None,
        fp=BytesIO(b'{"error":{"code":429,"message":"You exceeded your current quota"}}'),
    )

with patch.object(gemini_client, "gemini_api_key", return_value="not-a-real-key"):
    with patch.object(gemini_client, "_post_generate", fake_post):
        try:
            gemini_client.generate_content({"contents": [{"role": "user", "parts": [{"text": "hi"}]}]})
            print("PYTHON_RAISED=0")
        except RuntimeError as exc:
            text = str(exc)
            print("PYTHON_RAISED=1")
            print("PYTHON_HAS_429=" + ("1" if "429" in text else "0"))
print("PYTHON_POSTS=" + str(calls["n"]))
PY;

$tmp = tempnam(sys_get_temp_dir(), 'mc429');
if ($tmp === false) {
    ok('railway 429 posts once', false, 'temp file');
} else {
    $pyFile = $tmp . '.py';
    file_put_contents($pyFile, $python);
    $ai = dirname(__DIR__, 2) . '/ai_service';
    $cmd = 'python ' . escapeshellarg($pyFile);
    $out = [];
    $code = 1;
    $prev = getcwd();
    chdir($ai);
    exec($cmd, $out, $code);
    if ($prev !== false) {
        chdir($prev);
    }
    @unlink($pyFile);
    @unlink($tmp);
    $text = implode("\n", $out);
    $posts = null;
    if (preg_match('/PYTHON_POSTS=(\d+)/', $text, $m)) {
        $posts = (int) $m[1];
    }
    echo "Railway generate_content posts observed: " . ($posts === null ? 'none' : (string) $posts) . "\n";
    ok('railway generateContent not retried on 429', $posts === 1, $text);
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
