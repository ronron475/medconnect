<?php
/**
 * FAQ chatbot — emotional interaction API (server-side emotion recognition).
 * POST JSON: { "text": "...", "lang": "en|fil|hil", "intent": "optional" }
 */
require_once dirname(dirname(__DIR__)) . '/bootstrap.php';

Api::startJson();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    Api::error('Method not allowed. Use POST.', 405);
}

$rawBody = file_get_contents('php://input') ?: '';
$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$text = trim((string) ($payload['text'] ?? ''));
$lang = trim((string) ($payload['lang'] ?? 'en'));
$intent = trim((string) ($payload['intent'] ?? ''));

if ($text === '') {
    Api::error('Text is required.');
}

if (mb_strlen($text) > 2000) {
    Api::error('Text is too long.');
}

require_once BASE_PATH . '/app/includes/faq_chatbot_schema.php';
require_once BASE_PATH . '/app/includes/rate_limiter.php';
faq_chatbot_ensure_schema($pdo);

$rl = mc_rate_limiter_allow('faq_chatbot_emotion', 60, 60, (int) ($_SESSION['user_id'] ?? 0));
if (!$rl['allowed']) {
    Api::error('Too many requests. Please wait a moment.', 429, [
        'code' => 'rate_limited',
        'restriction_seconds' => 30,
    ]);
}

$sessionId = trim((string) ($payload['session_id'] ?? ''));
if ($sessionId === '') {
    $sessionId = (string) ($_SESSION['faq_chatbot_session_id'] ?? '');
}
if ($sessionId === '' || !preg_match('/^[a-zA-Z0-9_-]{16,64}$/', $sessionId)) {
    $sessionId = bin2hex(random_bytes(24));
    $_SESSION['faq_chatbot_session_id'] = $sessionId;
}

$guard = FaqChatbotMessageGuard::evaluate($text, $sessionId, (int) ($_SESSION['user_id'] ?? 0), $lang);
if (!$guard['allowed']) {
    Api::error('Message not accepted.', 422, [
        'code'                => (string) ($guard['code'] ?? 'blocked'),
        'restriction_seconds' => (int) ($guard['restriction_seconds'] ?? 0),
        'restricted'          => (bool) (($guard['action'] ?? '') === 'restricted'),
    ]);
}
$text = (string) $guard['sanitized_text'];

$nlp = FaqChatbotNlpPipeline::process($pdo, $text, $lang);

$prev = FaqChatbotConversationMemory::emotionContext();
$contextualNlp = FaqChatbotConversationMemory::contextualMatchText($text, $nlp['english_text']);
$result = FaqEmotionEngine::analyze($text, $nlp['reply_lang'], $intent !== '' ? $intent : null, $prev, $contextualNlp);

if (!empty($result['emotion'])) {
    $_SESSION['faq_emotion_context'] = [
        'emotion' => $result['emotion'],
        'tone'    => $result['tone'],
        'at'      => time(),
    ];
}

$emotional_support = in_array($result['tone'] ?? '', ['negative', 'crisis'], true);

Api::success([
    'data' => array_merge($result, [
        'emotional_support_active' => $emotional_support,
        'english_gloss'            => $nlp['english_text'],
        'nlp_text'                 => $nlp['expanded_english'] ?: $nlp['english_text'],
        'reply_lang'               => $nlp['reply_lang'],
        'detected_lang'              => $nlp['detected_lang'],
        'nlp_pipeline'               => $nlp['pipeline_steps'],
        'continuity'               => is_array($prev) ? [
            'previous_emotion' => $prev['emotion'] ?? null,
            'same_as_previous' => ($prev['emotion'] ?? null) === ($result['emotion'] ?? null),
        ] : null,
    ]),
], 'OK');
