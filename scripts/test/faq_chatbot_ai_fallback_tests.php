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

expect_true(FaqChatbotAiFallback::isGenericKnowledgeKey('capabilities'), 'capabilities is generic');
expect_true(FaqChatbotAiFallback::isGenericKnowledgeKey('navigation_help'), 'navigation_help is generic');
expect_true(!FaqChatbotAiFallback::isGenericKnowledgeKey('password_reset'), 'password_reset is specific');
expect_true(FaqChatbotAiFallback::isEmotionKnowledgeKey('fear_support'), 'fear_support defers to AI');
expect_true(FaqChatbotAiFallback::isGenericFallbackHtml("<p>I'm here to help with medConnect and City Health services.</p>"), 'generic help line is fallback');
expect_true(FaqChatbotAiFallback::isClearGreeting('hello'), 'hello is a greeting');
expect_true(FaqChatbotAiFallback::isClearGreeting('Kamusta'), 'Kamusta is a greeting');
expect_true(FaqChatbotAiFallback::isClearGreeting('Can you help me?'), 'Can you help me is a greeting');
expect_true(!FaqChatbotAiFallback::isClearGreeting('are you sure?'), 'are you sure is not a greeting');
expect_true(!FaqChatbotAiFallback::isClearGreeting("Hello, I've been feeling dizzy"), 'mixed greeting + dizzy is not opening-only');
expect_true(FaqChatbotAiFallback::isConversationalOpeningOnly('Thank you'), 'thank you is conversational opening');
expect_true(FaqChatbotAiFallback::isConversationalOpeningOnly('Goodbye'), 'goodbye is conversational opening');
expect_true(!FaqChatbotAiFallback::isConversationalOpeningOnly("I don't feel right today."), 'vague health is not opening-only');

$parsedNon = FaqChatbotAiFallback::parseModelReply("CLASSIFICATION: NON_HEALTHCARE\nREPLY:\nOUT_OF_SCOPE");
expect_true($parsedNon['classification'] === FaqChatbotAiFallback::CLASS_NON_HEALTHCARE, 'parse NON_HEALTHCARE');
expect_true($parsedNon['reply'] === 'OUT_OF_SCOPE', 'parse OUT_OF_SCOPE token');

$parsedHealth = FaqChatbotAiFallback::parseModelReply("CLASSIFICATION: HEALTHCARE\nREPLY:\nRest, sip water, and book a consult if this continues.");
expect_true($parsedHealth['classification'] === FaqChatbotAiFallback::CLASS_HEALTHCARE, 'parse HEALTHCARE');
expect_true(str_contains(strtolower($parsedHealth['reply']), 'rest'), 'parse keeps medical reply');

$parsedMaybe = FaqChatbotAiFallback::parseModelReply("CLASSIFICATION: POSSIBLY_HEALTHCARE\nREPLY:\nWhat symptoms are you feeling?");
expect_true($parsedMaybe['classification'] === FaqChatbotAiFallback::CLASS_POSSIBLY_HEALTHCARE, 'parse POSSIBLY_HEALTHCARE');

$parsedToken = FaqChatbotAiFallback::parseModelReply('OUT_OF_SCOPE');
expect_true($parsedToken['classification'] === FaqChatbotAiFallback::CLASS_NON_HEALTHCARE, 'bare OUT_OF_SCOPE token');

$oosPack = FaqChatbotAiFallback::packFromParsed($parsedNon, 'en');
expect_true($oosPack['response_type'] === FaqChatbotDomainScope::RESPONSE_OUT_OF_SCOPE, 'NON maps to OUT_OF_SCOPE');
expect_true(str_contains($oosPack['html'], 'outside the scope of the medConnect Assistant'), 'OOS pack uses backend copy');
expect_true(!str_contains($oosPack['html'], 'OUT_OF_SCOPE'), 'user never sees OUT_OF_SCOPE token');

$maybePack = FaqChatbotAiFallback::packFromParsed($parsedMaybe, 'en');
expect_true($maybePack['response_type'] === FaqChatbotDomainScope::RESPONSE_MEDICAL_GEMINI, 'POSSIBLY with a real reply is treated as healthcare');
expect_true(FaqChatbotAiFallback::isInsufficientModelReply('Kabay pa'), 'short Kabay pa reply is insufficient');
$shortPack = FaqChatbotAiFallback::packFromParsed([
    'classification' => FaqChatbotAiFallback::CLASS_HEALTHCARE,
    'reply'          => 'Kabay pa',
], 'hil');
expect_true($shortPack['response_type'] === FaqChatbotDomainScope::RESPONSE_MEDICAL_GEMINI, 'short healthcare reply stays medical');
expect_true(!str_contains(strtolower($shortPack['html']), 'kabay pa'), 'short Kabay pa is not shown as the answer');
expect_true(str_contains(strtolower($shortPack['html']), 'health concern') || str_contains(strtolower($shortPack['html']), 'health'), 'short reply becomes unmatched healthcare copy');

expect_true(FaqChatbotAiFallback::shouldUseDatasetAnswer(
    'sakit ulo ko',
    false,
    null,
    null,
    [
        'key'   => 'symptoms_general',
        'score' => 4.2,
        'html'  => '<p>Nasabtan ko nga indi ka maayo. Provider ang makasusi — indi ako nagadiagnose.</p>',
    ]
), 'high-score symptoms_general is a dataset answer for sakit ulo ko');
expect_true(FaqChatbotAiFallback::shouldUseDatasetAnswer(
    'may hilanat ko',
    false,
    null,
    null,
    [
        'key'   => 'multi_symptoms_general_common_illness',
        'score' => 7.4,
        'html'  => '<p>Thank you for telling me. Symptoms can have several possible causes — I cannot diagnose.</p>',
    ]
), 'combined symptom KB card is a dataset answer');

$healthPack = FaqChatbotAiFallback::packFromParsed($parsedHealth, 'en');
expect_true($healthPack['response_type'] === FaqChatbotDomainScope::RESPONSE_MEDICAL_GEMINI, 'HEALTHCARE maps to MEDICAL_GEMINI');
expect_true(!str_contains($healthPack['html'], 'CLASSIFICATION'), 'classification header stripped from HTML');

$genericHelp = "<p class=\"fcb-php-lead\"><em>I'm here to help with medConnect and City Health services.</em></p><div class=\"fcb-kb-answer\">Hello</div>";
expect_true(!FaqChatbotAiFallback::shouldUseDatasetAnswer(
    'are you sure?',
    false,
    null,
    null,
    ['key' => 'capabilities', 'score' => 3.0, 'html' => $genericHelp]
), 'are you sure? must not keep a generic capabilities card');

expect_true(FaqChatbotAiFallback::shouldUseDatasetAnswer(
    'How do I reset my password?',
    false,
    12,
    ['question' => 'How do I reset my password?', 'keywords' => 'password reset otp', 'category' => 'login', 'score' => 3.2],
    null,
    '<div class="fcb-faq-answer">Use Forgot Password on Sign In.</div>'
), 'password FAQ is a meaningful dataset answer');

expect_true(!FaqChatbotAiFallback::shouldUseDatasetAnswer(
    'What should I prepare before talking to a doctor?',
    false,
    4,
    ['question' => 'How does video consultation work?', 'keywords' => 'video join camera', 'category' => 'consultation', 'score' => 1.9],
    null,
    '<div class="fcb-faq-answer">Open Consultations and join the video room.</div>'
), 'unrelated consultation FAQ must not block Gemini');

expect_true(FaqChatbotAiFallback::shouldUseDatasetAnswer(
    'nahadlok gid ko',
    true,
    null,
    null,
    ['key' => 'fear_support', 'score' => 3.0, 'html' => '<p>scared</p>']
), 'emergency still uses dataset/safety path');

expect_true(!FaqChatbotAiFallback::shouldUseDatasetAnswer(
    'nahadlok gid ko',
    false,
    null,
    null,
    ['key' => 'fear_support', 'score' => 3.2, 'html' => '<p>I understand you feel afraid.</p>']
), 'emotional support card yields to Gemini');

$html = FaqChatbotAiFallback::toSafeHtml("Hello.\n\n<script>alert(1)</script>Need a doctor?");
expect_true(str_contains($html, '<p>') && !str_contains($html, '<script>'), 'HTML wrap strips tags');
expect_true(str_contains($html, 'Need a doctor?'), 'plain text preserved');

$empty = FaqChatbotAiFallback::toSafeHtml('   ');
expect_true($empty === '', 'blank text → empty html');

$jsonLeak = '{"is_healthcare_related":false,"intent":"non_healthcare"}';
expect_true(FaqChatbotAiFallback::isInternalClassificationPayload($jsonLeak), 'detect classification JSON leak');
$sanitized = FaqChatbotAiFallback::sanitizePatientFacingHtml(FaqChatbotAiFallback::toSafeHtml($jsonLeak) ?: $jsonLeak, 'en');
expect_true(str_contains($sanitized, 'outside the scope of the medConnect Assistant'), 'sanitize replaces JSON with boundary');
expect_true(!str_contains($sanitized, 'is_healthcare_related'), 'sanitize strips classification fields');
expect_true(FaqChatbotAiFallback::toSafeHtml($jsonLeak) === '', 'toSafeHtml rejects classification JSON');

$jsonNon = '{"is_healthcare_related":false,"intent":"non_healthcare","language":"English","normalized_meaning":"capital of Japan","urgency":"NON_URGENT","confidence":0.99,"reply":""}';
$parsedJsonNon = FaqChatbotAiFallback::parseModelReply($jsonNon);
expect_true($parsedJsonNon['classification'] === FaqChatbotAiFallback::CLASS_NON_HEALTHCARE, 'JSON non-healthcare classification');
$jsonPack = FaqChatbotAiFallback::packFromParsed($parsedJsonNon, 'en');
expect_true($jsonPack['response_type'] === FaqChatbotDomainScope::RESPONSE_OUT_OF_SCOPE, 'JSON non-healthcare maps to boundary');
expect_true(!preg_match('/tokyo/i', (string) $jsonPack['html']), 'does not answer the trivia question');

$jsonHead = '{"is_healthcare_related":true,"intent":"symptom","language":"Hiligaynon","normalized_meaning":"headache","urgency":"NON_URGENT","confidence":0.94,"reply":"Nasabtan ko nga nagasakit ang imo ulo. Indi ako makadiagnose, pero para sa mild headache makabulig ang pahulay kag tubig. Kon grabe ukon may iban nga seryoso nga sintomas, magpakonsulta."}';
$parsedHead = FaqChatbotAiFallback::parseModelReply($jsonHead);
expect_true($parsedHead['classification'] === FaqChatbotAiFallback::CLASS_HEALTHCARE, 'JSON sakit ulo is healthcare');
expect_true(($parsedHead['normalized_meaning'] ?? '') === 'headache', 'JSON normalized_meaning headache');
$headPack = FaqChatbotAiFallback::packFromParsed($parsedHead, 'hil');
expect_true($headPack['response_type'] === FaqChatbotDomainScope::RESPONSE_MEDICAL_GEMINI, 'JSON headache is MEDICAL_GEMINI');
expect_true(str_contains(strtolower($headPack['html']), 'ulo') || str_contains(strtolower($headPack['html']), 'head'), 'JSON keeps medical reply');

$vagueHealth = FaqChatbotAiFallback::parseModelReply("CLASSIFICATION: POSSIBLY_HEALTHCARE\nREPLY:\nWhat symptoms are you noticing today?");
expect_true($vagueHealth['classification'] === FaqChatbotAiFallback::CLASS_POSSIBLY_HEALTHCARE, 'vague health stays possibly healthcare');

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
    $quota = str_contains(FaqChatbotAiFallback::lastError(), '429');
    if ($quota) {
        echo "  SKIP remaining live API calls (provider quota 429)\n";
    } else {
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
        if ($hil === null && FaqChatbotAiFallback::lastError() !== '') {
            echo "  INFO Hiligaynon error: " . FaqChatbotAiFallback::lastError() . "\n";
        }
        expect_true(is_string($hil) && $hil !== '', 'Hiligaynon message returns a reply');

        $fil = FaqChatbotAiFallback::tryReply('paano magbook ng appointment', 'fil', [
            'intent' => 'appointment',
        ]);
        if ($fil === null && FaqChatbotAiFallback::lastError() !== '') {
            echo "  INFO Filipino error: " . FaqChatbotAiFallback::lastError() . "\n";
        }
        expect_true(is_string($fil) && $fil !== '', 'Filipino message returns a reply');

        $vagueLive = FaqChatbotAiFallback::tryReply("I don't feel right today.", 'en', [
            'intent' => 'general',
            'topic'  => 'symptoms',
        ]);
        if ($vagueLive === null && FaqChatbotAiFallback::lastError() !== '') {
            echo "  INFO vague-health error: " . FaqChatbotAiFallback::lastError() . "\n";
        }
        expect_true(is_string($vagueLive) && $vagueLive !== '', 'vague health concern returns a reply');
        expect_true(
            !str_contains((string) $vagueLive, 'outside the scope of the medConnect Assistant'),
            'vague health is not OUT_OF_SCOPE'
        );
    }
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
