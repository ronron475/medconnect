<?php
/**
 * FAQ chatbot routing tests (local DB). Avoids a long Gemini burst.
 * CLI: php scripts/test/faq_chatbot_scope_pipeline_tests.php
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
$sessionId = bin2hex(random_bytes(16));

function run_assist(FaqChatbotOrchestrator $orch, string $sessionId, string $text, string $lang = 'en'): array
{
    $_SESSION = [];
    return $orch->handle($sessionId, $text, $lang, ['mode' => 'assist']);
}

function plain_html(array $r): string
{
    return strtolower(trim(preg_replace('/\s+/', ' ', strip_tags((string) ($r['response_html'] ?? ''))) ?? ''));
}

echo "FAQ chatbot scope pipeline\n";

$hello = run_assist($orch, $sessionId, 'hello');
expect_true(($hello['healthcare_scope'] ?? '') === 'GREETING', 'hello greeting');
expect_true(empty($hello['emergency']), 'hello not emergency');
expect_true(($hello['final_response_type'] ?? '') !== 'OUT_OF_SCOPE', 'hello not boundary');

$sakit = run_assist($orch, $sessionId, 'sakit ulo ko');
$sakitPlain = plain_html($sakit);
expect_true(empty($sakit['emergency']), 'sakit ulo not emergency');
expect_true(
    !empty($sakit['dataset_match']) || !empty($sakit['fallback_required']),
    'sakit ulo uses dataset or Gemini fallback'
);
expect_true(!empty($sakit['use_server_response']), 'sakit ulo use_server');
expect_true(($sakit['final_response_type'] ?? '') !== 'OUT_OF_SCOPE', 'sakit ulo is not boundary');
expect_true(
    !str_contains($sakitPlain, 'sign in') || str_contains($sakitPlain, 'ulo') || str_contains($sakitPlain, 'head') || str_contains($sakitPlain, 'health concern'),
    'sakit ulo is not a generic account menu'
);

$breath = run_assist($orch, $sessionId, 'lisod ginhawa');
expect_true(!empty($breath['emergency']), 'lisod ginhawa emergency first');
expect_true(empty($breath['gemini_used']), 'emergency does not call Gemini');

$book = run_assist($orch, $sessionId, 'how do I book an appointment?');
expect_true(empty($book['emergency']), 'booking not emergency');
expect_true(($book['final_response_type'] ?? '') !== 'OUT_OF_SCOPE', 'booking is in-scope');

$joke = run_assist($orch, $sessionId, 'tell me a joke');
expect_true(empty($joke['emergency']), 'joke not emergency');
expect_true(
    ($joke['final_response_type'] ?? '') === 'OUT_OF_SCOPE'
        || !empty($joke['fallback_required']),
    'joke goes to Gemini classification or boundary'
);

$wala = run_assist($orch, $sessionId, 'wala kwarta');
expect_true(empty($wala['emergency']), 'wala kwarta is not emergency');
expect_true(!str_contains(plain_html($wala), '911') || !empty($wala['dataset_match']), 'wala kwarta does not invent emergency');

$novel = run_assist($orch, $sessionId, 'I have a stabbing pain behind my left eye after the lights flickered');
expect_true(empty($novel['emergency']), 'novel symptom is not emergency');
expect_true(($novel['final_response_type'] ?? '') !== 'OUT_OF_SCOPE', 'novel symptom is not boundary');
expect_true(
    !empty($novel['dataset_match']) || !empty($novel['fallback_required']),
    'novel symptom uses dataset or Gemini fallback'
);
expect_true(!empty($novel['use_server_response']), 'novel symptom use_server');

echo "\nPipeline tests: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
