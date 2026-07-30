<?php
/**
 * FAQ chatbot — session id (PHP + MySQL conversation key).
 * GET: returns or creates session_id stored in PHP session.
 */
require_once dirname(dirname(__DIR__)) . '/bootstrap.php';

Api::startJson();

require_once BASE_PATH . '/app/includes/faq_chatbot_schema.php';
faq_chatbot_ensure_schema($pdo);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    Api::error('Method not allowed. Use GET.', 405);
}

if (empty($_SESSION['faq_chatbot_session_id'])
    || !preg_match('/^[a-zA-Z0-9_-]{16,64}$/', (string) $_SESSION['faq_chatbot_session_id'])
) {
    $_SESSION['faq_chatbot_session_id'] = bin2hex(random_bytes(24));
}

Api::success([
    'data' => [
        'session_id' => $_SESSION['faq_chatbot_session_id'],
    ],
], 'OK');
