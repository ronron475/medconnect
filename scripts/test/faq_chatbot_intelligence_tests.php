<?php
/**
 * FAQ chatbot conversational intelligence tests (PHP only, no external AI).
 * CLI: php scripts/test/faq_chatbot_intelligence_tests.php
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

echo "Lexicon size: " . FaqChatbotConversationalIntents::intentCount() . " intents, "
    . FaqChatbotConversationalIntents::phraseCount() . " phrases\n\n";

expect_true(FaqChatbotConversationalIntents::intentCount() >= 20, "at least 20 intent ids");
expect_true(FaqChatbotConversationalIntents::phraseCount() >= 2000, "at least 2000 phrase variants");

$cases = [
    ['How do I book?', 'appointment'],
    ['How do I reset my password?', 'password_reset'],
    ['I am scared.', 'emotional_support'],
    ['I need a doctor.', 'doctor'],
    ['My head hurts.', 'symptoms'],
    ['Can I join my consultation?', 'consultation'],
    ['Paano mag book?', 'appointment'],
    ['Masakit ulo ko.', 'symptoms'],
    ['Natatakot ako.', 'emotional_support'],
    ['Pwede ba magpa checkup?', 'appointment'],
    ['Masakit akon ulo.', 'symptoms'],
    ['Buligi ko.', 'capabilities'],
    ['Nahadlok ko.', 'emotional_support'],
    ['Ginakulbaan ko.', 'emotional_support'],
    ['Diin ko maka book?', 'appointment'],
    ['Indi ko ka login.', 'login'],
    ['Masakit ulo ko need doctor.', 'symptoms'],
    ['Indi ko ka login sa account.', 'login'],
    ['Gusto ko magpa consultation.', 'appointment'],
    ['consulation', 'appointment'],
    ['appoinment', 'appointment'],
    ['regster', 'registration'],
    ['docter', 'doctor'],
    ['passwrod', 'password_reset'],
    ['help me please', 'capabilities'],
    ['I don\'t know what to do', 'emotional_support'],
    ['nahadlok gid ko', 'emotional_support'],
    ['can BHW help me?', 'bhw'],
    ['can I cancel?', 'appointment'],
    ['how much', 'financial'],
    ['free ni?', 'financial'],
    ['video call not working', 'connectivity'],
    ['where are my records', 'medical_record'],
    ['office hours', 'schedule'],
    ['where is my prescription', 'prescription'],
    ['update profile', 'profile'],
    ['goodbye', 'goodbye'],
    ['my tummy hurts', 'symptoms'],
    ['daw malipong ko', 'symptoms'],
    ['ga sakit akon ulo', 'symptoms'],
    ['pila ang bayad', 'financial'],
    ['safe bala ni', 'privacy'],
    ['what is ai triage', 'triage'],
    ['follow up appointment', 'follow_up'],
    ['tawag sa cho', 'contact'],
    ['bakuna', 'health_advice'],
    ['buntis ako', 'symptoms'],
    ['account locked', 'login'],
    ['verify email', 'registration'],
    ['my child is sick', 'symptoms'],
    ['when should i see a doctor', 'symptoms'],
    ['latest announcements', 'announcement'],
    ['need referral', 'referral'],
];

$family = [
    'doctor' => ['appointment', 'doctor'],
    'appointment' => ['appointment', 'consultation', 'doctor', 'follow_up'],
    'consultation' => ['consultation', 'appointment', 'connectivity'],
    'emotional_support' => ['emotional_support', 'mental_health', 'capabilities'],
    'capabilities' => ['capabilities', 'emotional_support', 'navigation', 'greeting'],
    'connectivity' => ['connectivity', 'consultation', 'technical'],
    'password_reset' => ['password_reset', 'login'],
    'symptoms' => ['symptoms', 'appointment', 'doctor', 'emotional_support'],
    'login' => ['login', 'password_reset', 'otp'],
    'registration' => ['registration', 'login'],
    'financial' => ['financial'],
    'bhw' => ['bhw', 'navigation', 'capabilities'],
    'medical_record' => ['medical_record', 'prescription', 'faq'],
    'schedule' => ['schedule', 'appointment', 'contact'],
    'prescription' => ['prescription', 'medicine', 'medical_record'],
    'profile' => ['profile', 'login', 'registration'],
    'goodbye' => ['goodbye', 'thanks', 'greeting'],
    'privacy' => ['privacy', 'reassurance'],
    'triage' => ['triage', 'symptoms', 'health_advice'],
    'follow_up' => ['follow_up', 'appointment', 'consultation'],
    'contact' => ['contact', 'hospital', 'navigation'],
    'health_advice' => ['health_advice', 'symptoms', 'triage', 'faq'],
    'announcement' => ['announcement', 'faq', 'navigation'],
    'referral' => ['referral', 'bhw', 'appointment'],
];

echo "Intent recognition\n";
foreach ($cases as [$text, $want]) {
    $got = FaqChatbotIntentRecognizer::recognize($text);
    $ok = in_array($got['intent'] ?? '', $family[$want] ?? [$want], true)
        && ($got['intent'] ?? '') !== FaqChatbotIntentRecognizer::GENERAL;
    expect_true($ok, "\"{$text}\" → {$got['intent']} (want {$want})");
}

$emergencies = [
    'indi ko kaginhawa',
    'wala ko malay',
    'grabe chest pain',
    'severe bleeding',
    'nag seizure',
    'I can\'t breathe',
    'nahimatay',
    'dugo gid',
    'budlay ginhawa',
    'indi ko makaginhawa',
];

echo "\nEmergency override\n";
foreach ($emergencies as $text) {
    $em = FaqChatbotEmergencyDetector::detect($text);
    $intent = FaqChatbotIntentRecognizer::recognize($text);
    $ok = !empty($em['is_emergency']) && ($intent['intent'] ?? '') === FaqChatbotIntentRecognizer::EMERGENCY;
    expect_true($ok, "\"{$text}\" emergency=" . (!empty($em['is_emergency']) ? 'yes' : 'no') . " intent={$intent['intent']}");
}

$nonEmergency = ['how to book', 'I am scared', 'masakit ulo ko'];
echo "\nNon-emergency must not override FAQ\n";
foreach ($nonEmergency as $text) {
    $em = FaqChatbotEmergencyDetector::detect($text);
    expect_true(empty($em['is_emergency']), "\"{$text}\" is not emergency");
}

$extreme = [
    'need doctor',
    'doctor',
    'help',
    'buligi ko',
    'nahadlok ko',
    'ginakulbaan ko',
    'sakit ulo ko',
    'masakit akon ulo',
    'diin ko maka book',
    'paano magpakonsulta',
    'indi ko ka login',
    'wala ko OTP',
    'gusto ko magpa checkup',
    'may appointment ko',
    'wala doctor sa video',
    'indi ko makita',
    'indi ko mabatian',
    'camera indi naga work',
    'wala ko kabalo ano ubrahon',
    'masakit ulo ko kag nahadlok ko',
    'need doctor because my head hurts',
    'appoitment',
    'doctur',
    'headeche',
    'okay thank you',
    'hi',
    'ga sakit akon ulo',
    'daw malipong ko',
    'my tummy hurts',
    'my head feels heavy',
    'pila ang bayad',
    'where are my records',
    'office hours',
    'reseta ko',
    'bye',
    'presciption',
    'scheduel',
    'gusto ko mag book appointment',
    'nalipat ko password',
    'indi ko ka register',
    'bakuna',
    'buntis ako',
    'account locked',
    'verify email',
    'my child is sick',
    'vacine',
    'referal',
    'masakit ang ulo ko',
    'nahihilo ako',
    'sakit ang bata',
];

echo "\nExtreme acceptance (no generic unknown)\n";
foreach ($extreme as $text) {
    $lex = FaqChatbotConversationalIntents::match($text);
    $intent = FaqChatbotIntentRecognizer::recognize($text);
    $ok = $lex !== null
        && ($intent['intent'] ?? '') !== FaqChatbotIntentRecognizer::GENERAL
        && ($lex['kb_key'] ?? '') !== 'navigation_help';
    $label = $lex['kb_key'] ?? 'none';
    expect_true($ok, "\"{$text}\" kb={$label} intent={$intent['intent']}");
}

echo "\nMulti-intent\n";
$multiCases = [
    ['Masakit ulo ko kag gusto ko magpa doctor', ['healthcare', 'appointments']],
    ['nahadlok ko kay sakit akon ulo', ['emotional_support', 'healthcare']],
    ['Indi ko ka login kag may appointment ko subong', ['accounts', 'appointments']],
    ['need doctor because my head hurts', ['appointments', 'healthcare']],
];
foreach ($multiCases as [$text, $wantCats]) {
    $all = FaqChatbotConversationalIntents::matchAll($text, $text, 2);
    $cats = array_column($all, 'category');
    $ok = count($all) >= 2;
    foreach ($wantCats as $c) {
        $ok = $ok && in_array($c, $cats, true);
    }
    expect_true($ok, "\"{$text}\" cats=" . implode(',', $cats));
}

echo "\nShort-utterance context\n";
$_SESSION = [];
FaqChatbotConversationMemory::update([
    'intent' => FaqChatbotIntentRecognizer::DOCTOR,
    'topic'  => 'appointments',
    'kb_key' => 'doctor_clarify',
    'user_text' => 'I need a doctor',
]);
$resolved = FaqChatbotConversationMemory::resolveShortUtterance('book one');
expect_true(is_string($resolved) && str_contains(strtolower($resolved), 'book'), 'book one → appointment context');

$_SESSION = [];
FaqChatbotConversationMemory::update([
    'intent' => FaqChatbotIntentRecognizer::SYMPTOMS,
    'topic'  => 'symptoms',
    'kb_key' => 'symptoms_general',
    'user_text' => 'my head hurts',
]);
$resolvedYes = FaqChatbotConversationMemory::resolveShortUtterance('yes');
expect_true(is_string($resolvedYes) && str_contains(strtolower($resolvedYes), 'book'), 'yes after symptoms → booking');

$_SESSION = [];
FaqChatbotConversationMemory::update([
    'intent' => FaqChatbotIntentRecognizer::APPOINTMENT,
    'topic'  => 'appointments',
    'kb_key' => 'book_appointment',
    'user_text' => 'I need a doctor',
]);
$resolvedWhere = FaqChatbotConversationMemory::resolveShortUtterance('where?');
expect_true(is_string($resolvedWhere) && str_contains(strtolower($resolvedWhere), 'book'), 'where? after booking → book location');

echo "\nKB combined keys\n";
foreach (['emotion_and_symptoms', 'symptom_and_booking', 'login_and_appointment', 'doctor_clarify', 'greeting', 'thank_you'] as $key) {
    $html = FaqChatbotKnowledgeBase::pickResponse($key, 'hil', 'testsession12345678');
    $ok = $html !== '' && !str_contains($html, 'not_understood');
    expect_true($ok, "KB {$key} has hil/en response");
}

echo "\nEmotion kb routing\n";
$emotionKb = [
    'nahadlok ko' => 'fear_support',
    'ginakulbaan ko' => 'anxiety_support',
    'i am sad' => 'sadness_support',
    'nasubo ko' => 'sadness_support',
    'akig ko' => 'anger_support',
    'i am angry' => 'anger_support',
    'i am crying' => 'crying_support',
    'naga hibi ko' => 'crying_support',
    'lonely' => 'need_to_talk',
    'i am tired' => 'burnout_support',
    'kapoy na ko' => 'burnout_support',
    "can't sleep" => 'cant_sleep',
    'i feel guilty' => 'guilt_support',
    'homesick' => 'homesickness',
    'mixed feelings' => 'mixed_feelings',
    'i am disappointed' => 'disappointment_support',
    'nahadlok ko sa doktor' => 'afraid_of_doctor',
    'panic attack' => 'panic_support',
];
foreach ($emotionKb as $text => $want) {
    $lex = FaqChatbotConversationalIntents::match($text);
    $got = $lex['kb_key'] ?? 'none';
    expect_true($got === $want, "\"{$text}\" kb={$got} want={$want}");
}

foreach (['fear_support', 'anxiety_support', 'sadness_support', 'anger_support', 'crying_support', 'need_to_talk', 'panic_support', 'disappointment_support'] as $key) {
    $html = FaqChatbotKnowledgeBase::pickResponse($key, 'hil', 'testsession12345678');
    $ok = $html !== '' && !str_contains($html, 'not_understood');
    expect_true($ok, "emotion KB {$key} has hil/en response");
}

echo "\nTypo normalization\n";
expect_true(str_contains(FaqChatbotConversationalIntents::normalize('doctur'), 'doctor'), 'doctur → doctor');
expect_true(str_contains(FaqChatbotConversationalIntents::normalize('appoitment'), 'appointment'), 'appoitment → appointment');
expect_true(str_contains(FaqChatbotConversationalIntents::normalize('headeche'), 'headache'), 'headeche → headache');
expect_true(str_contains(FaqChatbotConversationalIntents::normalize('registation'), 'registration'), 'registation → registration');
expect_true(str_contains(FaqChatbotConversationalIntents::normalize('presciption'), 'prescription'), 'presciption → prescription');
expect_true(str_contains(FaqChatbotConversationalIntents::normalize('scheduel'), 'schedule'), 'scheduel → schedule');
expect_true(str_contains(FaqChatbotConversationalIntents::normalize('throath'), 'throat'), 'throath → throat');
$sched = FaqChatbotConversationalIntents::match('scheduel');
expect_true(($sched['kb_key'] ?? '') === 'book_appointment', 'scheduel maps to booking not vaccine');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
