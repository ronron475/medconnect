<?php
require_once __DIR__ . '/_auth.php';
require_once BASE_PATH . '/app/includes/faq_chatbot_schema.php';
faq_chatbot_ensure_schema($pdo);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST only']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$raw = json_decode(file_get_contents('php://input') ?: '', true);
$action = is_array($raw) ? (string) ($raw['action'] ?? '') : '';

if ($action === 'reimport') {
    $force = !empty($raw['force']);
    if ($force) {
        $pdo->exec('TRUNCATE TABLE translation_dictionary');
        $pdo->exec('TRUNCATE TABLE medical_terms');
    }
    $repo = new FaqChatbotDictionaryRepository($pdo);
    $inserted = $repo->importSeed();
    $total = (int) $pdo->query('SELECT COUNT(*) FROM translation_dictionary')->fetchColumn();
    echo json_encode(['success' => true, 'inserted' => $inserted, 'total' => $total]);
    exit;
}

if ($action === 'translate_test') {
    $text = trim((string) ($raw['text'] ?? ''));
    if ($text === '') {
        echo json_encode(['success' => false, 'message' => 'text required']);
        exit;
    }
    $nlp = FaqChatbotNlpPipeline::process($pdo, $text, 'hil');
    echo json_encode(['success' => true, 'data' => $nlp]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action']);
