<?php
/**
 * TRIAL ONLY — adaptive chief-complaint interview for nlp_step3_demo.php.
 * Does not persist triage_results or touch production chatbot routes.
 *
 * Auth / abuse controls (demo page only):
 * - Same-origin session CSRF
 * - Session-bound demo interview token (issued by nlp_step3_demo.php)
 * - Optional shared API key (NLP_DEMO_API_KEY) when set in env
 * - IP rate limit for the endpoint + stricter Gemini budget
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(__DIR__)) . '/includes/security_throttle.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth_guard.php';

Api::startJson();
Api::requirePost();
set_time_limit(120);

/** @var PDO $pdo */

$csrf = (string) ($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
$sessionToken = (string) ($_SESSION['nlp_demo_interview_token'] ?? '');
$postedToken = (string) ($_POST['demo_token'] ?? $_SERVER['HTTP_X_NLP_DEMO_TOKEN'] ?? '');
$sessionOk = auth_csrf_validate($csrf)
    && $sessionToken !== ''
    && $postedToken !== ''
    && hash_equals($sessionToken, $postedToken);

$envApiKey = trim((string) (getenv('NLP_DEMO_API_KEY') ?: ($_ENV['NLP_DEMO_API_KEY'] ?? '')));
$postedKey = (string) ($_POST['demo_api_key'] ?? $_SERVER['HTTP_X_NLP_DEMO_API_KEY'] ?? '');
$apiKeyOk = $envApiKey !== '' && $postedKey !== '' && hash_equals($envApiKey, $postedKey);

// Browser: session CSRF + demo token from nlp_step3_demo.php.
// Optional NLP_DEMO_API_KEY: allows scripted clients without a browser session.
if (!$sessionOk && !$apiKeyOk) {
    if ($envApiKey !== '' && $postedKey !== '') {
        Api::error('Unauthorized demo API key.', 401, ['code' => 'demo_api_key_invalid']);
    }
    Api::error('Demo interview session is missing or expired. Reload the NLP demo page.', 403, [
        'code' => 'demo_session_invalid',
    ]);
}

$ip = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown'));
if (str_contains($ip, ',')) {
    $ip = trim(explode(',', $ip)[0]);
}
$ipKey = security_throttle_key('nlp_demo_interview_ip', $ip ?: 'unknown');
$ipState = security_throttle_check($pdo, $ipKey);
if (!empty($ipState['locked'])) {
    Api::error('Too many demo interview requests. Please wait and try again.', 429, [
        'code' => 'rate_limited',
        'locked_until' => $ipState['locked_until'] ?? null,
    ]);
}

$geminiKey = security_throttle_key('nlp_demo_gemini_ip', $ip ?: 'unknown');
$geminiState = security_throttle_check($pdo, $geminiKey);
$allowGemini = empty($geminiState['locked']);
$geminiMax = (int) (getenv('NLP_DEMO_GEMINI_MAX_PER_HOUR') ?: ($_ENV['NLP_DEMO_GEMINI_MAX_PER_HOUR'] ?? 20));
if ($geminiMax < 1) {
    $geminiMax = 20;
}
if ($geminiMax > 100) {
    $geminiMax = 100;
}

$utterance = trim((string) ($_POST['utterance'] ?? $_POST['chief_complaint'] ?? $_POST['text'] ?? ''));
$reset = filter_var($_POST['reset'] ?? false, FILTER_VALIDATE_BOOLEAN);

$priorRaw = $_POST['interview_context'] ?? $_POST['context'] ?? '';
if (is_string($priorRaw)) {
    $decoded = json_decode($priorRaw, true);
    $prior = is_array($decoded) ? $decoded : [];
} elseif (is_array($priorRaw)) {
    $prior = $priorRaw;
} else {
    $prior = [];
}

if ($reset) {
    $prior = [];
}

if ($utterance === '' && !$reset) {
    Api::error('Enter a chief complaint or follow-up answer.');
}

if (mb_strlen($utterance) > 1000) {
    Api::error('Input is too long (max 1000 characters).');
}

// Soft window: 40 requests / 10 minutes → 15 min lock (counts accepted calls only).
security_throttle_fail($pdo, $ipKey, 'nlp_demo_interview_ip', 600, 40, 15);
$ipStateAfter = security_throttle_check($pdo, $ipKey);
if (!empty($ipStateAfter['locked'])) {
    Api::error('Too many demo interview requests. Please wait and try again.', 429, [
        'code' => 'rate_limited',
        'locked_until' => $ipStateAfter['locked_until'] ?? null,
    ]);
}

$result = NlpStep3DemoTrial::assess($utterance, $prior, [
    'allow_gemini' => $allowGemini,
]);

// Charge Gemini budget only when this turn actually called a model.
if (!empty($result['gemini_called']) || !empty(($result['gemini']['called'] ?? false))) {
    security_throttle_fail($pdo, $geminiKey, 'nlp_demo_gemini_ip', 3600, $geminiMax, 60);
}

Api::success([
    'trial' => $result,
    'interview_context' => $result['interview_context'],
    'gemini_allowed' => $allowGemini,
], 'Step 3 demo interview turn complete.');
