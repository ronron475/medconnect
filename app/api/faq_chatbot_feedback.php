<?php
/**
 * FAQ chatbot — thumbs up/down feedback.
 * POST JSON: { "message_id": 123, "rating": "helpful|not_helpful", "comment": "optional" }
 */
require_once dirname(dirname(__DIR__)) . '/bootstrap.php';

Api::startJson();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    Api::error('Method not allowed. Use POST.', 405);
}

require_once BASE_PATH . '/app/includes/faq_chatbot_schema.php';
faq_chatbot_ensure_schema($pdo);

$rawBody = file_get_contents('php://input') ?: '';
$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$messageId = (int) ($payload['message_id'] ?? 0);
$rating = strtolower(trim((string) ($payload['rating'] ?? '')));
$comment = trim((string) ($payload['comment'] ?? ''));

if ($messageId < 1) {
    Api::error('message_id is required.');
}

if (!in_array($rating, ['helpful', 'not_helpful'], true)) {
    Api::error('rating must be helpful or not_helpful.');
}

if (mb_strlen($comment) > 500) {
    Api::error('comment is too long.');
}

try {
    $repo = new FaqChatbotConversationRepository($pdo);
    $repo->saveFeedback($messageId, $rating, $comment !== '' ? $comment : null);
    Api::success(['data' => ['saved' => true]], 'Thank you for your feedback.');
} catch (InvalidArgumentException $e) {
    Api::error($e->getMessage(), 422);
} catch (Throwable $e) {
    error_log('faq_chatbot_feedback: ' . $e->getMessage());
    Api::error('Could not save feedback.', 500);
}
