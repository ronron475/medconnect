<?php
/**
 * POST JSON: { "text": "...", "lang": "en|hil" }
 * Returns NLP pipeline output (translation, detected language).
 */
require_once dirname(dirname(__DIR__)) . '/bootstrap.php';

Api::startJson();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    Api::error('Method not allowed. Use POST.', 405);
}

require_once BASE_PATH . '/app/includes/faq_chatbot_schema.php';
require_once BASE_PATH . '/app/includes/rate_limiter.php';
faq_chatbot_ensure_schema($pdo);

$rl = mc_rate_limiter_allow('faq_chatbot_translate', 80, 60, (int) ($_SESSION['user_id'] ?? 0));
if (!$rl['allowed']) {
    Api::error('Too many requests. Please wait a moment.', 429);
}

$rawBody = file_get_contents('php://input') ?: '';
$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$text = trim((string) ($payload['text'] ?? ''));
$lang = trim((string) ($payload['lang'] ?? 'en'));

if ($text === '') {
    Api::error('Text is required.');
}

try {
    $nlp = FaqChatbotNlpPipeline::process($pdo, $text, $lang);
    Api::success(['data' => $nlp], 'OK');
} catch (Throwable $e) {
    error_log('faq_chatbot_translate: ' . $e->getMessage());
    Api::error('Translation failed.', 500);
}
