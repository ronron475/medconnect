<?php
/**
 * FAQ chatbot AI fallback tests (no UI changes).
 * CLI: php scripts/test/faq_chatbot_ai_fallback_tests.php
 *
 * Live Gemini/Groq call runs only when AI_API_KEY or GEMINI_API_KEY / GROQ_API_KEY is set.
 * Production Hostinger can also use Railway /faq-chatbot/assist without a local Gemini key.
 */
$root = dirname(__DIR__, 2);
if (!defined('BASE_PATH')) {
    define('BASE_PATH', $root);
}
require_once $root . '/config/env_loader.php';

spl_autoload_register(static function (string $class) use ($root): void {
    $file = $root . '/app/core/' . $class . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

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

echo "FAQ chatbot AI fallback\n";

$html = FaqChatbotAiFallback::toSafeHtml("Hello.\n\n<script>alert(1)</script>Need a doctor?");
expect_true(str_contains($html, '<p>') && !str_contains($html, '<script>'), 'HTML wrap strips tags');
expect_true(str_contains($html, 'Need a doctor?'), 'plain text preserved');

$empty = FaqChatbotAiFallback::toSafeHtml('   ');
expect_true($empty === '', 'blank text → empty html');

$_SESSION = [];
expect_true(FaqChatbotAiFallback::provider() === 'gemini' || FaqChatbotAiFallback::provider() === 'groq', 'provider is gemini or groq');
expect_true(FaqChatbotAiFallback::model() !== '', 'model is configured or defaulted');

$keyPresent = FaqChatbotAiFallback::isEnabled();
$groqKey = trim((string) (getenv('GROQ_API_KEY') ?: getenv('MEDCONNECT_GROQ_API_KEY') ?: ''));
if (!$keyPresent && $groqKey !== '') {
    putenv('AI_PROVIDER=groq');
    putenv('AI_MODEL=llama-3.1-8b-instant');
    $keyPresent = FaqChatbotAiFallback::isEnabled();
    if ($keyPresent) {
        echo "  NOTE using Groq live test because Gemini AI_API_KEY is not set\n";
    }
}
if (!$keyPresent) {
    expect_true(FaqChatbotAiFallback::tryReply('What should I prepare before talking to a doctor?', 'en') === null, 'no key → null (existing chatbot path)');
    echo "  SKIP live API (set AI_API_KEY or GEMINI_API_KEY in .env to test Gemini)\n";
} else {
    putenv('AI_COOLDOWN_SECONDS=0');
    $_ENV['AI_COOLDOWN_SECONDS'] = '0';
    if (PHP_OS_FAMILY === 'Windows') {
        putenv('AI_SSL_VERIFY=false');
        echo "  NOTE Windows/XAMPP: AI_SSL_VERIFY=false for this live test only\n";
    }
    echo "Live API (" . FaqChatbotAiFallback::provider() . " / " . FaqChatbotAiFallback::model() . ")\n";
    $first = FaqChatbotAiFallback::tryReply('What should I prepare before talking to a doctor?', 'en', [
        'intent' => 'general',
        'topic'  => 'consultation',
    ]);
    if ($first === null && FaqChatbotAiFallback::lastError() !== '') {
        echo "  INFO live error: " . FaqChatbotAiFallback::lastError() . "\n";
    }
    expect_true(is_string($first) && $first !== '' && str_contains($first, '<p>'), 'AI returns HTML for open question');
    expect_true(!str_contains(strtolower(strip_tags((string) $first)), 'http 429'), 'no raw http error');

    $follow = FaqChatbotAiFallback::tryReply('what do I need?', 'en', [
        'intent' => 'appointment',
        'topic'  => 'appointments',
        'turns'  => [
            ['role' => 'user', 'text' => 'I need a doctor'],
            ['role' => 'bot', 'text' => 'Would you like to book a new appointment?'],
            ['role' => 'user', 'text' => 'yes'],
        ],
    ]);
    if ($follow === null && FaqChatbotAiFallback::lastError() !== '') {
        echo "  INFO follow-up error: " . FaqChatbotAiFallback::lastError() . "\n";
    }
    expect_true(is_string($follow) && $follow !== '', 'follow-up returns a reply');

    $hil = FaqChatbotAiFallback::tryReply('nahadlok ko kay sakit akon ulo', 'hil', [
        'emotion' => 'afraid',
        'topic'   => 'symptoms',
    ]);
    expect_true(is_string($hil) && $hil !== '', 'Hiligaynon message returns a reply');

    $fil = FaqChatbotAiFallback::tryReply('paano magbook ng appointment', 'fil', [
        'intent' => 'appointment',
    ]);
    expect_true(is_string($fil) && $fil !== '', 'Filipino message returns a reply');
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
