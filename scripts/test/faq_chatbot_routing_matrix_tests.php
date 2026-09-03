<?php
/**
 * Trace the production FAQ chatbot route for the required 16-message matrix.
 * CLI: php scripts/test/faq_chatbot_routing_matrix_tests.php
 */
$root = dirname(__DIR__, 2);
if (!defined('BASE_PATH')) {
    define('BASE_PATH', $root);
}

require_once $root . '/bootstrap.php';
require_once $root . '/app/includes/faq_chatbot_schema.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$failed = 0;
$passed = 0;

function expect_true(bool $ok, string $label): void
{
    global $failed, $passed;
    if ($ok) {
        $passed++;
        echo "  OK  {$label}\n";
        return;
    }
    $failed++;
    echo "  FAIL  {$label}\n";
}

faq_chatbot_ensure_schema($pdo);
$orch = new FaqChatbotOrchestrator($pdo);

function run_one(FaqChatbotOrchestrator $orch, string $text, string $lang = 'en'): array
{
    $_SESSION = [];
    return $orch->handle(bin2hex(random_bytes(16)), $text, $lang, ['mode' => 'assist']);
}

function route_of(array $r): string
{
    if (!empty($r['emergency'])) {
        return 'EMERGENCY';
    }
    $type = (string) ($r['final_response_type'] ?? '');
    if ($type === 'GREETING') {
        return 'GREETING';
    }
    if ($type === 'OUT_OF_SCOPE' || $type === 'NON_HEALTH' || $type === 'UNCLEAR') {
        return 'BOUNDARY';
    }
    if (!empty($r['dataset_match']) && $type === 'MEDICAL_DATASET') {
        return 'DATASET';
    }
    if (!empty($r['gemini_used']) && in_array($type, ['MEDICAL_GEMINI', 'MEDICAL_CLARIFICATION'], true)) {
        return 'GEMINI_HEALTHCARE';
    }
    if ($type === 'MEDICAL_GEMINI' || $type === 'MEDICAL_CLARIFICATION') {
        return 'HEALTHCARE_FALLBACK';
    }
    return $type !== '' ? $type : 'UNKNOWN';
}

$cases = [
    ['sakit ulo ko', 'medical'],
    ['ga sakit ulo ko', 'medical'],
    ['sakit gid ulo ko', 'medical'],
    ['ginahilo ko', 'medical'],
    ['gasakit tiyan ko', 'medical'],
    ['may hilanat ko', 'medical'],
    ['I have a headache', 'medical'],
    ['my stomach hurts', 'medical'],
    ['masakit ulo ko', 'medical'],
    ['nahihilo ako', 'medical'],
    ['hi', 'greeting'],
    ['hello', 'greeting'],
    ['kamusta', 'greeting'],
    ['tell me a joke', 'boundary'],
    ['what is the weather?', 'boundary'],
    ['happy birthday', 'boundary'],
    ['who won the basketball game?', 'boundary'],
    ['how do I book an appointment?', 'medconnect'],
];

echo "FAQ chatbot routing matrix\n";
echo str_pad('message', 32)
    . str_pad('route', 20)
    . str_pad('dataset', 10)
    . str_pad('score', 8)
    . str_pad('gemini', 8)
    . str_pad('health', 8)
    . str_pad('intent', 14)
    . "urgency\n";

foreach ($cases as [$text, $expect]) {
    $r = run_one($orch, $text);
    $route = route_of($r);
    $plain = strtolower(trim(preg_replace('/\s+/', ' ', strip_tags((string) ($r['response_html'] ?? ''))) ?? ''));
    echo str_pad(mb_substr($text, 0, 30), 32)
        . str_pad($route, 20)
        . str_pad(!empty($r['dataset_match']) ? 'yes' : 'no', 10)
        . str_pad((string) ($r['dataset_match_score'] ?? 0), 8)
        . str_pad(!empty($r['gemini_used']) ? 'yes' : 'no', 8)
        . str_pad(($r['gemini_healthcare'] === true || $r['gemini_healthcare'] === 1) ? 'yes' : (($r['gemini_healthcare'] === false) ? 'no' : '-'), 8)
        . str_pad((string) (($r['gemini_intent'] ?? $r['intent']) ?: '-'), 14)
        . (($r['gemini_urgency'] ?? '-') ?: '-')
        . "\n";
    echo '    lang=' . ($r['detected_lang'] ?? '')
        . ' gloss=' . mb_substr((string) ($r['english_gloss'] ?? ''), 0, 40)
        . ' type=' . ($r['final_response_type'] ?? '')
        . ' scope=' . ($r['healthcare_scope'] ?? '')
        . "\n";

    expect_true(!empty($r['use_server_response']), "{$text}: use_server");
    expect_true(!str_contains($plain, 'is_healthcare_related') && !str_contains($plain, '"intent"'), "{$text}: no classification JSON in patient HTML");
    expect_true(!str_contains($plain, 'create account') && !str_contains($plain, 'sign in') || $expect === 'greeting' || $expect === 'medconnect', "{$text}: no generic account menu");

    if ($expect === 'boundary') {
        expect_true(
            str_contains($plain, 'outside the scope of the medconnect assistant')
            || str_contains($plain, 'city health office')
            || str_contains($plain, 'rephrase')
            || str_contains($plain, 'not sure i understood')
            || str_contains($plain, 'healthcare concern'),
            "{$text}: boundary/non-health/unclear copy visible"
        );
    }

    if ($expect === 'greeting') {
        expect_true($route === 'GREETING', "{$text}: greeting route");
        expect_true(empty($r['emergency']), "{$text}: not emergency");
    } elseif ($expect === 'boundary') {
        expect_true(empty($r['emergency']), "{$text}: not emergency");
        expect_true(
            $route === 'BOUNDARY' || !empty($r['fallback_required']) || !empty($r['gemini_used']),
            "{$text}: boundary or Gemini classify"
        );
        expect_true(($r['final_response_type'] ?? '') !== 'GREETING', "{$text}: not greeting");
    } elseif ($expect === 'medconnect') {
        expect_true(empty($r['emergency']), "{$text}: not emergency");
        expect_true(!in_array(($r['final_response_type'] ?? ''), ['OUT_OF_SCOPE', 'NON_HEALTH', 'UNCLEAR'], true), "{$text}: in-scope");
    } else {
        expect_true(empty($r['emergency']), "{$text}: not emergency");
        expect_true(!in_array(($r['final_response_type'] ?? ''), ['OUT_OF_SCOPE', 'NON_HEALTH'], true), "{$text}: not boundary");
        expect_true(
            in_array($route, ['DATASET', 'GEMINI_HEALTHCARE', 'HEALTHCARE_FALLBACK', 'MEDICAL_DATASET', 'MEDICAL_GEMINI'], true),
            "{$text}: healthcare route ({$route})"
        );
    }
}

echo "\nRouting matrix: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
