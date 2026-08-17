<?php
/**
 * FAQ chatbot healthcare-scope pipeline tests (uses local DB).
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

echo "FAQ chatbot scope pipeline\n";

$wala = run_assist($orch, $sessionId, 'wala kwarta');
$plain = strtolower(trim(preg_replace('/\s+/', ' ', strip_tags((string) ($wala['response_html'] ?? ''))) ?? ''));
expect_true(($wala['healthcare_scope'] ?? '') === 'OUTSIDE', 'wala kwarta healthcare_scope=OUTSIDE got=' . ($wala['healthcare_scope'] ?? ''));
expect_true(empty($wala['emergency']), 'wala kwarta emergency=false');
expect_true(empty($wala['gemini_used']), 'wala kwarta gemini_used=false');
expect_true(!empty($wala['use_server_response']), 'wala kwarta use_server_response');
expect_true(str_contains($plain, 'healthcare-related concerns only'), 'wala kwarta scope copy');
expect_true(!str_contains($plain, '911') && !str_contains($plain, 'emergency'), 'wala kwarta has no 911/emergency');

$hello = run_assist($orch, $sessionId, 'hello');
expect_true(in_array($hello['healthcare_scope'] ?? '', ['GREETING', 'HEALTHCARE'], true), 'hello is greeting got=' . ($hello['healthcare_scope'] ?? ''));
expect_true(empty($hello['emergency']), 'hello is not emergency');
expect_true(($hello['final_response_type'] ?? '') !== 'OUT_OF_SCOPE', 'hello is not out of scope');

$kumusta = run_assist($orch, $sessionId, 'kumusta', 'hil');
expect_true(($kumusta['healthcare_scope'] ?? '') === 'GREETING', 'kumusta greeting');
expect_true(empty($kumusta['emergency']), 'kumusta not emergency');

$head = run_assist($orch, $sessionId, 'sakit ulo ko');
expect_true(($head['healthcare_scope'] ?? '') === 'HEALTHCARE', 'sakit ulo healthcare');
expect_true(empty($head['emergency']), 'sakit ulo not emergency');

$breath = run_assist($orch, $sessionId, 'lisod ginhawa');
expect_true(($breath['healthcare_scope'] ?? '') === 'HEALTHCARE', 'lisod ginhawa healthcare');
expect_true(!empty($breath['emergency']), 'lisod ginhawa emergency');
expect_true(empty($breath['gemini_used']), 'lisod ginhawa does not use Gemini');

$mixed = run_assist($orch, $sessionId, 'wala kwarta pambili tambal kay may hilanat ako');
expect_true(($mixed['healthcare_scope'] ?? '') === 'HEALTHCARE', 'mixed money+fever healthcare');
expect_true(empty($mixed['emergency']), 'mixed money+fever not emergency');

$joke = run_assist($orch, $sessionId, 'tell me a joke');
expect_true(($joke['healthcare_scope'] ?? '') === 'OUTSIDE', 'joke outside');
expect_true(empty($joke['gemini_used']), 'joke gemini_used=false');

$rand = run_assist($orch, $sessionId, 'asdfgh random text');
expect_true(($rand['healthcare_scope'] ?? '') === 'OUTSIDE', 'random outside');

$cho = run_assist($orch, $sessionId, 'diin ang City Health Office?');
expect_true(($cho['healthcare_scope'] ?? '') === 'HEALTHCARE', 'CHO healthcare');
expect_true(empty($cho['emergency']), 'CHO not emergency');

echo "\nPipeline tests: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
