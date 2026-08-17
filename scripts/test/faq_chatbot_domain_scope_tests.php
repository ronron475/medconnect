<?php
/**
 * Domain-scope classifier tests (no database required).
 * CLI: php scripts/test/faq_chatbot_domain_scope_tests.php
 */
$root = dirname(__DIR__, 2);
if (!defined('BASE_PATH')) {
    define('BASE_PATH', $root);
}
spl_autoload_register(static function (string $class) use ($root): void {
    $file = $root . '/app/core/' . $class . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

$failed = 0;
$passed = 0;

function expect_scope(string $text, string $expect, string $label = ''): void
{
    global $failed, $passed;
    $got = FaqChatbotDomainScope::classify($text)['scope'] ?? '';
    $ok = $got === $expect;
    $tag = $label !== '' ? $label : $text;
    if ($ok) {
        $passed++;
        echo "  OK  {$tag}\n";
        return;
    }
    $failed++;
    echo "  FAIL  {$tag} expected={$expect} got={$got}\n";
}

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

echo "FAQ chatbot domain scope\n";

foreach (['Hello', 'Hi', 'Hey', 'Good morning', 'Kamusta', 'Kumusta', 'Maayong aga', 'Thank you', 'Thanks', 'Goodbye', 'Bye', 'How are you?', 'What can you do?', 'Who are you?', 'Can you help me?'] as $g) {
    $scope = FaqChatbotDomainScope::classify($g)['scope'];
    expect_true(
        in_array($scope, [FaqChatbotDomainScope::GREETING, FaqChatbotDomainScope::CONVERSATION], true),
        "greeting/conversation: {$g} => {$scope}"
    );
    expect_true(!FaqChatbotDomainScope::shouldIntercept(FaqChatbotDomainScope::classify($g)), "do not intercept: {$g}");
}

expect_scope('I have a headache', FaqChatbotDomainScope::MEDICAL);
expect_scope('sakit ulo ko', FaqChatbotDomainScope::MEDICAL);
expect_scope('sakit mata ko', FaqChatbotDomainScope::MEDICAL);
expect_scope('masakit ang tiyan ko', FaqChatbotDomainScope::MEDICAL);
expect_scope('I have fever and cough', FaqChatbotDomainScope::MEDICAL);
expect_scope('ginahilanat ko', FaqChatbotDomainScope::MEDICAL);
expect_scope('my chest hurts', FaqChatbotDomainScope::MEDICAL);
expect_scope('why is my eye swollen?', FaqChatbotDomainScope::MEDICAL);
expect_scope('My head hurts.', FaqChatbotDomainScope::MEDICAL);
expect_scope("I don't feel right today.", FaqChatbotDomainScope::MEDICAL);
expect_scope('I feel weak and strange.', FaqChatbotDomainScope::MEDICAL);
expect_scope('Something feels wrong with my body.', FaqChatbotDomainScope::MEDICAL);
expect_scope('I have been feeling sick lately.', FaqChatbotDomainScope::MEDICAL);
expect_scope('Why do I suddenly feel dizzy?', FaqChatbotDomainScope::MEDICAL);
expect_scope('I feel pain after eating.', FaqChatbotDomainScope::MEDICAL);
expect_scope('My body feels very weak.', FaqChatbotDomainScope::MEDICAL);

expect_scope('Hi, sakit ulo ko', FaqChatbotDomainScope::MEDICAL, 'mixed hi + sakit ulo');
expect_scope('Hello doctor, my chest hurts', FaqChatbotDomainScope::MEDICAL, 'mixed hello doctor + chest');
expect_scope('Good morning, ginahilanat ko', FaqChatbotDomainScope::MEDICAL, 'mixed good morning + fever');
expect_scope('Hello, good morning. I have a fever and cough.', FaqChatbotDomainScope::MEDICAL, 'mixed greeting + fever cough');
expect_scope("Hello, I've been feeling dizzy. By the way, what's the weather?", FaqChatbotDomainScope::MEDICAL, 'mixed dizzy + weather');

expect_scope('What is the capital of France?', FaqChatbotDomainScope::OUT_OF_SCOPE);
expect_scope('What is the capital of Japan?', FaqChatbotDomainScope::OUT_OF_SCOPE);
expect_scope('Write a poem', FaqChatbotDomainScope::OUT_OF_SCOPE);
expect_scope('Write me a poem.', FaqChatbotDomainScope::OUT_OF_SCOPE);
expect_scope('How do I code PHP?', FaqChatbotDomainScope::OUT_OF_SCOPE);
expect_scope('Help me code PHP.', FaqChatbotDomainScope::OUT_OF_SCOPE);
expect_scope('What is the weather?', FaqChatbotDomainScope::OUT_OF_SCOPE);
expect_scope('Tell me a joke', FaqChatbotDomainScope::OUT_OF_SCOPE);
expect_scope('How do I fix my computer?', FaqChatbotDomainScope::OUT_OF_SCOPE);
expect_scope('My computer has a headache.', FaqChatbotDomainScope::OUT_OF_SCOPE);
expect_scope("I'm studying computer science.", FaqChatbotDomainScope::OUT_OF_SCOPE);
expect_scope('Explain cryptocurrency.', FaqChatbotDomainScope::OUT_OF_SCOPE);
expect_scope('Make me a business plan.', FaqChatbotDomainScope::OUT_OF_SCOPE);

expect_scope('I need help', FaqChatbotDomainScope::HELP_OPEN);
expect_scope('Can I ask something?', FaqChatbotDomainScope::HELP_OPEN);
expect_scope('Something is wrong', FaqChatbotDomainScope::AMBIGUOUS);
expect_scope('I feel strange.', FaqChatbotDomainScope::MEDICAL);

expect_true(FaqChatbotDomainScope::shouldIntercept(FaqChatbotDomainScope::classify('Tell me a joke')), 'intercept joke');
expect_true(FaqChatbotDomainScope::shouldIntercept(FaqChatbotDomainScope::classify('What is the capital of Japan?')), 'intercept capital');
expect_true(!FaqChatbotDomainScope::shouldIntercept(FaqChatbotDomainScope::classify('sakit ulo ko')), 'do not intercept headache');
expect_true(!FaqChatbotDomainScope::shouldIntercept(FaqChatbotDomainScope::classify('Hello')), 'do not intercept hello');
expect_true(!FaqChatbotDomainScope::shouldIntercept(FaqChatbotDomainScope::classify('Can you help me?')), 'do not intercept can you help me');
expect_true(!FaqChatbotDomainScope::shouldIntercept(FaqChatbotDomainScope::classify('Something is wrong')), 'do not intercept ambiguous health');
expect_true(!FaqChatbotDomainScope::shouldIntercept(FaqChatbotDomainScope::classify("I don't feel right.")), 'do not intercept vague health');
expect_true(!FaqChatbotDomainScope::shouldIntercept(FaqChatbotDomainScope::classify('I need help')), 'do not intercept help-open');

$html = FaqChatbotDomainScope::replyHtml(FaqChatbotDomainScope::OUT_OF_SCOPE, 'en');
expect_true(str_contains($html, 'Please type a question or concern related to healthcare'), 'out-of-scope copy');

$focus = FaqChatbotDomainScope::healthcareFocusText("Hello, I've been feeling dizzy. By the way, what's the weather?");
expect_true(str_contains(mb_strtolower($focus), 'dizzy') && !str_contains(mb_strtolower($focus), 'weather'), 'focus keeps dizzy, drops weather');

$stripped = FaqChatbotDomainScope::stripGreetingLead('Hi, sakit ulo ko');
expect_true(str_contains(mb_strtolower($stripped), 'sakit ulo'), 'strip greeting keeps medical text');
$intent = FaqChatbotIntentRecognizer::recognize($stripped);
expect_true($intent['intent'] === FaqChatbotIntentRecognizer::SYMPTOMS, 'stripped mixed greeting uses symptoms intent');

$hello = FaqChatbotIntentRecognizer::recognize('Hello');
expect_true($hello['intent'] === FaqChatbotIntentRecognizer::GREETING, 'hello remains greeting intent');

echo "\nDomain scope tests: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
