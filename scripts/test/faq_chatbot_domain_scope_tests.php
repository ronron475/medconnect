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

foreach (['Hello', 'Hi', 'Hey', 'Good morning', 'Kamusta', 'Kumusta', 'Maayong aga', 'Thank you', 'Thanks', 'Goodbye', 'Bye', 'How are you?', 'What can you do?', 'Can you help me?'] as $g) {
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
expect_scope('Something is wrong', FaqChatbotDomainScope::OUT_OF_SCOPE);
expect_scope('I feel strange.', FaqChatbotDomainScope::MEDICAL);

expect_true(FaqChatbotDomainScope::shouldIntercept(FaqChatbotDomainScope::classify('Tell me a joke')), 'intercept joke');
expect_true(FaqChatbotDomainScope::shouldIntercept(FaqChatbotDomainScope::classify('What is the capital of Japan?')), 'intercept capital');
expect_true(!FaqChatbotDomainScope::shouldIntercept(FaqChatbotDomainScope::classify('sakit ulo ko')), 'do not intercept headache');
expect_true(!FaqChatbotDomainScope::shouldIntercept(FaqChatbotDomainScope::classify('Hello')), 'do not intercept hello');
expect_true(!FaqChatbotDomainScope::shouldIntercept(FaqChatbotDomainScope::classify('Can you help me?')), 'do not intercept can you help me');
expect_true(FaqChatbotDomainScope::shouldIntercept(FaqChatbotDomainScope::classify('Something is wrong')), 'intercept unrelated something is wrong');
expect_true(!FaqChatbotDomainScope::shouldIntercept(FaqChatbotDomainScope::classify("I don't feel right.")), 'do not intercept vague health');
expect_true(!FaqChatbotDomainScope::shouldIntercept(FaqChatbotDomainScope::classify('I need help')), 'do not intercept help-open');

$html = FaqChatbotDomainScope::replyHtml(FaqChatbotDomainScope::OUT_OF_SCOPE, 'en');
expect_true(str_contains($html, 'healthcare and medConnect-related concerns'), 'out-of-scope copy');

$focus = FaqChatbotDomainScope::healthcareFocusText("Hello, I've been feeling dizzy. By the way, what's the weather?");
expect_true(str_contains(mb_strtolower($focus), 'dizzy') && !str_contains(mb_strtolower($focus), 'weather'), 'focus keeps dizzy, drops weather');

$stripped = FaqChatbotDomainScope::stripGreetingLead('Hi, sakit ulo ko');
expect_true(str_contains(mb_strtolower($stripped), 'sakit ulo'), 'strip greeting keeps medical text');
$intent = FaqChatbotIntentRecognizer::recognize($stripped);
expect_true($intent['intent'] === FaqChatbotIntentRecognizer::SYMPTOMS, 'stripped mixed greeting uses symptoms intent');

$hello = FaqChatbotIntentRecognizer::recognize('Hello');
expect_true($hello['intent'] === FaqChatbotIntentRecognizer::GREETING, 'hello remains greeting intent');

echo "\nAcceptance matrix\n";
expect_scope('hello', FaqChatbotDomainScope::GREETING, 'A hello');
expect_scope('kumusta', FaqChatbotDomainScope::GREETING, 'B kumusta');
expect_scope('wala kwarta', FaqChatbotDomainScope::OUT_OF_SCOPE, 'C wala kwarta');
expect_scope('ano oras?', FaqChatbotDomainScope::OUT_OF_SCOPE, 'D ano oras');
expect_scope('tell me a joke', FaqChatbotDomainScope::OUT_OF_SCOPE, 'E joke');
expect_scope('what is the weather?', FaqChatbotDomainScope::OUT_OF_SCOPE, 'F weather');
expect_scope('sakit ulo ko', FaqChatbotDomainScope::MEDICAL, 'G sakit ulo');
expect_scope('may hilanat ako', FaqChatbotDomainScope::MEDICAL, 'H hilanat');
expect_scope('masakit akon dughan', FaqChatbotDomainScope::MEDICAL, 'I chest pain healthcare');
expect_scope('lisod ginhawa', FaqChatbotDomainScope::MEDICAL, 'J breathing healthcare');
expect_scope('wala kwarta pambili tambal kay may hilanat ako', FaqChatbotDomainScope::MEDICAL, 'K money + medicine');
expect_scope('diin ang City Health Office?', FaqChatbotDomainScope::MEDICAL, 'L CHO');
expect_scope('what medicine is used for fever?', FaqChatbotDomainScope::MEDICAL, 'M medicine fever');
expect_scope('asdfgh random text', FaqChatbotDomainScope::OUT_OF_SCOPE, 'N random');
expect_scope('sino ka', FaqChatbotDomainScope::OUT_OF_SCOPE, 'sino ka');
expect_scope('diin ang hospital?', FaqChatbotDomainScope::MEDICAL, 'hospital location is healthcare');

expect_true(FaqChatbotDomainScope::isHealthcareRelated('sakit ulo ko'), 'isHealthcareRelated sakit ulo');
expect_true(!FaqChatbotDomainScope::isHealthcareRelated('wala kwarta'), 'isHealthcareRelated wala kwarta false');
expect_true(FaqChatbotDomainScope::isHealthcareRelated('wala kwarta pambili tambal kay may hilanat ako'), 'mixed money+fever is healthcare');

$emWala = FaqChatbotEmergencyDetector::detect('wala kwarta');
expect_true(empty($emWala['is_emergency']), 'wala kwarta is not emergency');
$emBreath = FaqChatbotEmergencyDetector::detect('lisod ginhawa');
expect_true(!empty($emBreath['is_emergency']), 'lisod ginhawa is emergency after healthcare');
$emChest = FaqChatbotEmergencyDetector::detect('masakit akon dughan');
expect_true(!empty($emChest['is_emergency']), 'masakit akon dughan is emergency after healthcare');
$emHospital = FaqChatbotEmergencyDetector::detect('diin ang hospital?');
expect_true(empty($emHospital['is_emergency']), 'hospital location is not automatic emergency');

$htmlHil = FaqChatbotDomainScope::replyHtml(FaqChatbotDomainScope::OUT_OF_SCOPE, 'hil');
expect_true(str_contains($htmlHil, 'Palihog pamangkot parte sa sintomas'), 'hiligaynon out-of-scope copy');

echo "\nDomain scope tests: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
