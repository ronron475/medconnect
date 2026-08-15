<?php
/**
 * FAQ chatbot — main PHP pipeline (emotion, intent, FAQ, emergency, logging).
 *
 * POST JSON:
 * {
 *   "text": "user message",
 *   "lang": "en|fil|hil",
 *   "session_id": "optional — uses PHP session if omitted",
 *   "mode": "full|assist|log_only",
 *   "client_html": "for log_only",
 *   "flow_key": "optional",
 *   "confidence": 0.0-1.0
 * }
 */
require_once dirname(dirname(__DIR__)) . '/bootstrap.php';

Api::startJson();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    Api::error('Method not allowed. Use POST.', 405);
}

require_once BASE_PATH . '/app/includes/faq_chatbot_schema.php';
require_once BASE_PATH . '/app/includes/rate_limiter.php';
faq_chatbot_ensure_schema($pdo);

$rl = mc_rate_limiter_allow('faq_chatbot_chat', 40, 60, (int) ($_SESSION['user_id'] ?? 0));
if (!$rl['allowed']) {
    Api::error('Too many messages. Please wait a moment.', 429, [
        'code' => 'rate_limited',
        'restriction_seconds' => 30,
    ]);
}

$rawBody = file_get_contents('php://input') ?: '';
$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$text = trim((string) ($payload['text'] ?? ''));
$lang = trim((string) ($payload['lang'] ?? 'en'));
$mode = strtolower(trim((string) ($payload['mode'] ?? 'assist')));
if (!in_array($mode, ['full', 'assist', 'log_bot', 'log_only'], true)) {
    $mode = 'assist';
}

$sessionId = trim((string) ($payload['session_id'] ?? ''));
if ($sessionId === '') {
    $sessionId = (string) ($_SESSION['faq_chatbot_session_id'] ?? '');
}
if ($sessionId === '' || !preg_match('/^[a-zA-Z0-9_-]{16,64}$/', $sessionId)) {
    $sessionId = bin2hex(random_bytes(24));
    $_SESSION['faq_chatbot_session_id'] = $sessionId;
}

$_SESSION['faq_chatbot_lang'] = FaqEmotionEngine::normalizeLang($lang);

if ($text === '' && !in_array($mode, ['log_bot', 'log_only'], true)) {
    Api::error('Text is required.');
}

if ($text !== '' && !in_array($mode, ['log_bot', 'log_only'], true)) {
    $guard = FaqChatbotMessageGuard::evaluate($text, $sessionId, (int) ($_SESSION['user_id'] ?? 0), $lang);
    $text = (string) $guard['sanitized_text'];

    if (!$guard['allowed']) {
        $payload = FaqChatbotMessageGuard::assistPayload($guard, $sessionId, $lang);
        Api::success(['data' => $payload], 'OK');
    }
}

try {
    $orchestrator = new FaqChatbotOrchestrator($pdo);
    $result = $orchestrator->handle($sessionId, $text, $lang, [
        'mode'          => $mode,
        'client_html'   => (string) ($payload['client_html'] ?? ''),
        'flow_key'      => (string) ($payload['flow_key'] ?? ''),
        'intent'        => (string) ($payload['intent'] ?? ''),
        'confidence'    => isset($payload['confidence']) ? (float) $payload['confidence'] : null,
    ]);

    Api::success(['data' => $result], 'OK');
} catch (InvalidArgumentException $e) {
    Api::error($e->getMessage(), 422);
} catch (Throwable $e) {
    error_log('faq_chatbot_chat: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    // Keep the landing widget alive: JS assist mode continues with the rule-based client engine.
    Api::success([
        'data' => [
            'session_id'          => $sessionId,
            'conversation_id'     => 0,
            'user_message_id'     => 0,
            'bot_message_id'      => 0,
            'response_html'       => '',
            'use_server_response' => false,
            'mode'                => $mode,
            'confidence'          => 0,
            'pipeline_error'      => (new ReflectionClass($e))->getShortName(),
            'pipeline_at'         => basename($e->getFile()) . ':' . $e->getLine(),
            'pipeline_msg'        => substr(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 0, 180),
        ],
    ], 'OK');
}
