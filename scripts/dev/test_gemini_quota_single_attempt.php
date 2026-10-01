<?php
/**
 * Demo interview start: Gemini 3.5 then Gemini 3.8 once on HTTP 429, then stop.
 * No live Gemini call is made.
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
ok('no Gemini 3.8 retry loop (at most primary + one fallback)', $calls <= 2, (string) $calls);
ok(
    'php client posts once on Railway wrapper, or twice on local 3.5 then 3.8',
    $calls === 1 || $calls === 2,
    (string) $calls
);
ok(
    'interview continues from NLP when Gemini quota is exceeded',
    ($started['awaiting_question'] ?? '') !== ''
    || ($started['status'] ?? '') === 'interviewing'
    || ($started['status'] ?? '') === 'final_triage',
    (string) ($started['status'] ?? '') . ' q=' . (string) ($started['awaiting_question'] ?? '')
);
ok(
    'provider is the NLP question bank after quota',
    str_contains((string) ($started['ai_provider_used'] ?? ''), 'Question bank')
    || ($started['status'] ?? '') === 'final_triage',
    (string) ($started['ai_provider_used'] ?? '')
);
ok('triage not copied from a model label', !is_array($started['final_triage'] ?? null) || ($started['final_triage']['final_authority'] ?? '') === 'ClinicalTriageEngine');

$ai = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'ai_service';
$aiQuoted = var_export($ai, true);
$python = <<<PY
import sys
sys.path.insert(0, {$aiQuoted})
from io import BytesIO
import urllib.error
from unittest.mock import patch
import gemini_client

calls = {"n": 0, "models": []}

def fake_post(payload, model, key, timeout):
    calls["n"] += 1
    calls["models"].append(model)
    raise urllib.error.HTTPError(
        "https://generativelanguage.googleapis.com/v1beta/models/" + model + ":generateContent",
        429,
        "Too Many Requests",
        hdrs=None,
        fp=BytesIO(b'{"error":{"code":429,"message":"You exceeded your current quota"}}'),
    )

with patch.object(gemini_client, "gemini_api_key", return_value="not-a-real-key"):
    with patch.object(gemini_client, "_post_generate", fake_post):
        with patch.object(gemini_client, "_openrouter_http_complete", return_value=None):
            with patch.object(gemini_client, "_groq_http_complete", return_value=None):
                try:
                    gemini_client.generate_content({"contents": [{"role": "user", "parts": [{"text": "hi"}]}]})
                    print("PYTHON_RAISED=0")
                except RuntimeError as exc:
                    text = str(exc)
                    print("PYTHON_RAISED=1")
                    print("PYTHON_HAS_429=" + ("1" if "429" in text else "0"))
print("PYTHON_POSTS=" + str(calls["n"]))
print("PYTHON_MODELS=" + ",".join(calls["models"]))
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
    ok('railway tries Gemini 3.5 then Gemini 3.8 once on 429', $posts === 2, $text);
    ok(
        'railway model order is 3.5 then 3.8',
        str_contains($text, 'PYTHON_MODELS=gemini-3.5-flash,gemini-3.8-flash'),
        $text
    );
    ok('railway still raises 429 after both Gemini models and empty fallbacks', str_contains($text, 'PYTHON_RAISED=1'));
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
