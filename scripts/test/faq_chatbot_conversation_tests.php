<?php
/**
 * FAQ chatbot conversation tests (500+ representative cases).
 * CLI: php scripts/test/faq_chatbot_conversation_tests.php
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

if (!FaqChatbotScenarioIndex::isAvailable()) {
    fwrite(STDERR, "Run: php scripts/data/build_chatbot_scenario_index.php first.\n");
    exit(1);
}

$scenarioCount = FaqChatbotScenarioIndex::count();
echo "Loading scenario index ({$scenarioCount} entries)...\n";

$minScenarios = 20_000;
if ($scenarioCount < $minScenarios) {
    fwrite(STDERR, "Scenario index has {$scenarioCount} entries (need {$minScenarios}+).\n");
    exit(1);
}

/** @var PDO|null $pdo */
$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo instanceof PDO) {
    try {
        require_once BASE_PATH . '/app/includes/db.php';
        $pdo = $GLOBALS['pdo'] ?? null;
    } catch (Throwable) {
        $pdo = null;
    }
}

$mustPass = [
    'i have no money' => ['intents' => ['financial'], 'kb' => 'financial_barrier'],
    'wala ko kwarta' => ['intents' => ['financial'], 'kb' => 'financial_barrier'],
    'wala ako kwarta' => ['intents' => ['financial'], 'kb' => 'financial_barrier'],
    "I'm scared" => ['intents' => ['emotional_support', 'fear_support'], 'kb' => null],
    'nahadlok ko' => ['intents' => ['emotional_support'], 'kb' => null],
    "I'm confused" => ['intents' => ['clarification', 'emotional_support', 'navigation'], 'kb' => null],
    'di ko kabalo paano mag appointment' => ['intents' => ['appointment'], 'kb' => 'book_appointment'],
    'sakit ulo ko' => ['intents' => ['symptoms'], 'kb' => 'symptoms_general'],
    'I need a doctor' => ['intents' => ['appointment', 'doctor'], 'kb' => 'book_appointment'],
    'how can I book' => ['intents' => ['appointment'], 'kb' => 'book_appointment'],
    'my appointment is tomorrow' => ['intents' => ['appointment'], 'kb' => 'book_appointment'],
    'can I cancel my appointment' => ['intents' => ['appointment'], 'kb' => 'book_appointment'],
    'thank you' => ['intents' => ['thanks'], 'kb' => 'thank_you'],
    'okay' => ['intents' => ['small_talk', 'thanks', 'clarification'], 'kb' => null],
    'help me' => ['intents' => ['emotional_support', 'capabilities', 'clarification'], 'kb' => null],
];

$extraPhrases = [];
$csv = BASE_PATH . '/data/nlp/emotion_intent_phrases_full.csv';
if (is_readable($csv)) {
    $fh = fopen($csv, 'rb');
    fgetcsv($fh);
    $n = 0;
    while (($row = fgetcsv($fh)) !== false && $n < 485) {
        $phrase = trim((string) ($row[1] ?? ''));
        if ($phrase !== '' && mb_strlen($phrase) < 120) {
            $extraPhrases[] = $phrase;
            $n++;
        }
    }
    fclose($fh);
}

$allTests = array_merge(array_keys($mustPass), $extraPhrases);
$passed = 0;
$failed = 0;
$failures = [];

foreach ($allTests as $i => $phrase) {
    $hit = FaqChatbotScenarioIndex::match($phrase, $phrase, []);
    $intentPack = FaqChatbotIntentRecognizer::recognize($phrase);
    $intent = $intentPack['intent'];

    $unified = null;
    if ($pdo instanceof PDO && isset($mustPass[$phrase])) {
        $unified = FaqChatbotUnifiedKnowledge::search($pdo, $phrase, $phrase, 'en', ['intent' => $intent]);
    }

    $genericFallback = false;
    if ($unified !== null) {
        $html = (string) ($unified['html'] ?? '');
        $genericFallback = str_contains($html, "didn't quite understand")
            || str_contains($html, 'not_understood')
            || str_contains($html, "couldn't understand your message");
    }

    $ok = ($hit !== null || $unified !== null) && !$genericFallback;

    if (isset($mustPass[$phrase])) {
        $spec = $mustPass[$phrase];
        $intentOk = in_array($intent, $spec['intents'], true)
            || in_array($hit['intent'] ?? '', $spec['intents'], true)
            || in_array($unified['intent'] ?? '', $spec['intents'], true);
        $kbOk = $spec['kb'] === null
            || ($hit['kb_key'] ?? '') === $spec['kb']
            || ($unified['key'] ?? '') === $spec['kb'];
        $ok = $ok && $intentOk && ($spec['kb'] === null || $kbOk);
    }

    if ($ok) {
        $passed++;
    } else {
        $failed++;
        if (count($failures) < 20) {
            $failures[] = [
                'phrase' => $phrase,
                'intent' => $intent,
                'hit' => $hit['kb_key'] ?? ($hit['intent'] ?? null),
                'unified' => $unified['key'] ?? ($unified['intent'] ?? null),
            ];
        }
    }

    if ($i > 0 && $i % 100 === 0) {
        echo "  ... {$i} tests\n";
    }
}

$total = count($allTests);
echo "Scenario index: {$scenarioCount} loadable scenarios\n";
echo "Tests run: {$total}\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";

if ($failures !== []) {
    echo "\nSample failures:\n";
    foreach ($failures as $f) {
        echo "  - \"{$f['phrase']}\" intent={$f['intent']} hit={$f['hit']} unified={$f['unified']}\n";
    }
}

exit($failed > 0 ? 1 : 0);
