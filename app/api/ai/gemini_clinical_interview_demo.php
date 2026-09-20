<?php
/**
 * DEMO ONLY — Gemini clinical interview experiment API.
 * Does not persist triage_results or touch production patient portal routes.
 *
 * Auth: session CSRF + demo token from gemini_clinical_interview_demo.php
 * Optional NLP_DEMO_API_KEY for scripted clients.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(__DIR__)) . '/includes/security_throttle.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth_guard.php';

Api::startJson();
Api::requirePost();
set_time_limit(120);

/** @var PDO $pdo */

$csrf = (string) ($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
$sessionToken = (string) ($_SESSION['gemini_clinical_demo_token'] ?? '');
$postedToken = (string) ($_POST['demo_token'] ?? $_SERVER['HTTP_X_GEMINI_CLINICAL_DEMO_TOKEN'] ?? '');
$sessionOk = auth_csrf_validate($csrf)
    && $sessionToken !== ''
    && $postedToken !== ''
    && hash_equals($sessionToken, $postedToken);

$envApiKey = trim((string) (getenv('NLP_DEMO_API_KEY') ?: ($_ENV['NLP_DEMO_API_KEY'] ?? '')));
$postedKey = (string) ($_POST['demo_api_key'] ?? $_SERVER['HTTP_X_NLP_DEMO_API_KEY'] ?? '');
$apiKeyOk = $envApiKey !== '' && $postedKey !== '' && hash_equals($envApiKey, $postedKey);

if (!$sessionOk && !$apiKeyOk) {
    if ($envApiKey !== '' && $postedKey !== '') {
        Api::error('Unauthorized demo API key.', 401, ['code' => 'demo_api_key_invalid']);
    }
    Api::error('Demo session missing or expired. Reload the Gemini Clinical Interview Demo page.', 403, [
        'code' => 'demo_session_invalid',
    ]);
}

$ip = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown'));
if (str_contains($ip, ',')) {
    $ip = trim(explode(',', $ip)[0]);
}
$ipKey = security_throttle_key('gemini_clinical_demo_ip', $ip ?: 'unknown');
$ipState = security_throttle_check($pdo, $ipKey);
if (!empty($ipState['locked'])) {
    Api::error('Too many demo requests. Please wait and try again.', 429, [
        'code' => 'rate_limited',
        'locked_until' => $ipState['locked_until'] ?? null,
    ]);
}

$action = strtolower(trim((string) ($_POST['action'] ?? 'start')));
$reset = filter_var($_POST['reset'] ?? false, FILTER_VALIDATE_BOOLEAN);
if ($reset) {
    $action = 'reset';
}

$complaint = trim((string) ($_POST['chief_complaint'] ?? $_POST['complaint'] ?? ''));
$answer = trim((string) ($_POST['answer'] ?? $_POST['followup_answer'] ?? ''));

$priorRaw = $_POST['interview_context'] ?? $_POST['context'] ?? '';
if (is_string($priorRaw)) {
    $decoded = json_decode($priorRaw, true);
    $prior = is_array($decoded) ? $decoded : [];
} elseif (is_array($priorRaw)) {
    $prior = $priorRaw;
} else {
    $prior = [];
}

if ($action === 'reset') {
    Api::success([
        'demo' => true,
        'status' => 'idle',
        'status_label' => 'Ready',
        'interview_context' => [],
        'conversation' => [],
        'clinical_facts' => [],
        'final_triage' => null,
        'message' => 'Demo reset.',
    ], 'Demo reset.');
}

security_throttle_fail($pdo, $ipKey, 'gemini_clinical_demo_ip', 600, 40, 15);
$ipStateAfter = security_throttle_check($pdo, $ipKey);
if (!empty($ipStateAfter['locked'])) {
    Api::error('Too many demo requests. Please wait and try again.', 429, [
        'code' => 'rate_limited',
        'locked_until' => $ipStateAfter['locked_until'] ?? null,
    ]);
}

if ($action === 'answer') {
    if ($answer === '') {
        Api::error('Enter a follow-up answer.');
    }
    $result = GeminiClinicalInterviewDemo::answer($answer, $prior);
} else {
    if ($complaint === '') {
        Api::error('Enter a patient complaint to start.');
    }
    if (mb_strlen($complaint) > 1000) {
        Api::error('Complaint is too long (max 1000 characters).');
    }
    $result = GeminiClinicalInterviewDemo::start($complaint);
}

if (!empty($result['error'])) {
    Api::error((string) ($result['message'] ?? 'Demo interview error'), 422, [
        'code' => 'gemini_demo_error',
        'demo' => $result,
    ]);
}

Api::success($result, (string) ($result['message'] ?? 'Demo turn complete.'));
