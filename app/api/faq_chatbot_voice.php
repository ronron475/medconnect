<?php
/**
 * FAQ chatbot voice — public config + optional server transcription fallback.
 * GET  — feature flags and language hints for the landing FAQ assistant.
 * POST — transcribe short audio (webm/wav/mp3) when browser STT is unavailable.
 */
require_once dirname(dirname(__DIR__)) . '/bootstrap.php';
require_once BASE_PATH . '/app/includes/upload_security.php';

Api::startJson();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    echo json_encode([
        'success'              => true,
        'browser_stt'          => true,
        'browser_tts'          => true,
        'fallback_transcribe'  => true,
        'max_audio_bytes'      => 2 * 1024 * 1024,
        'max_recording_sec'    => 25,
        'languages'            => [
            ['code' => 'en-PH', 'label' => 'English (Philippines)'],
            ['code' => 'fil-PH', 'label' => 'Filipino'],
            ['code' => 'en-US', 'label' => 'English (US)'],
        ],
        'default_stt_lang'     => 'en-PH',
    ]);
    exit;
}

if ($method !== 'POST') {
    Api::error('Method not allowed.', 405);
}

$audio = $_FILES['audio'] ?? null;
if (!$audio) {
    Api::error('Audio file is required.');
}

$maxBytes = 2 * 1024 * 1024;
$validated = upload_security_validate($audio, [
    'audio/webm' => 'webm',
    'video/webm' => 'webm',
    'audio/wav' => 'wav',
    'audio/x-wav' => 'wav',
    'audio/mpeg' => 'mp3',
    'audio/mp3' => 'mp3',
    'audio/ogg' => 'ogg',
    'application/ogg' => 'ogg',
    'audio/mp4' => 'm4a',
    'audio/x-m4a' => 'm4a',
], $maxBytes);
if (!$validated['ok']) {
    Api::error($validated['message']);
}

require_once BASE_PATH . '/app/includes/rate_limiter.php';

$rl = mc_rate_limiter_allow('faq_chatbot_voice', 20, 60, (int) ($_SESSION['user_id'] ?? 0));
if (!$rl['allowed']) {
    Api::error('Too many voice requests. Please wait a moment.', 429, [
        'code' => 'rate_limited',
        'restriction_seconds' => 30,
    ]);
}

try {
    if (!AiServiceClient::isHealthy()) {
        AiServiceLauncher::ensureRunning(true);
    }
} catch (Throwable $e) {
    /* non-fatal */
}

$data = AiServiceClient::transcribeFile(
    $validated['tmp'],
    $validated['mime'],
    'faq_voice.' . $validated['ext'],
    'audio'
);

if (!$data) {
    Api::error('Could not transcribe audio. Please type your question instead.', 503);
}

$text = trim((string) (
    $data['english_transcript']
    ?? $data['hiligaynon_transcript']
    ?? ($data['transcription']['text'] ?? '')
));

if ($text === '' && !empty($data['summary']) && stripos((string) $data['summary'], 'No speech') === false) {
    $text = trim((string) $data['summary']);
}

if ($text === '') {
    Api::error('No speech detected. Please try again or type your message.');
}

echo json_encode([
    'success' => true,
    'text'    => $text,
    'engine'  => (string) ($data['engine'] ?? 'faster-whisper'),
]);
