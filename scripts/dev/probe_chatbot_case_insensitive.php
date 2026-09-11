<?php
/**
 * Smoke: FAQ chatbot match keys must be case-insensitive.
 * "How to book", "HOW TO BOOK", "how to book" → same forMatch key / intent.
 */
declare(strict_types=1);

require __DIR__ . '/nlp_cli_bootstrap.php';

$variants = [
    'how to book',
    'How To Book',
    'HOW TO BOOK',
    'HoW tO bOoK',
];

$keys = [];
$intents = [];

foreach ($variants as $v) {
    $key = FaqChatbotTextNormalizer::forMatch($v);
    $keys[$v] = $key;
    $pack = FaqChatbotIntentRecognizer::recognize($v);
    $intents[$v] = (string) ($pack['intent'] ?? '');
    echo sprintf(
        "[%s]\n  forMatch=%s\n  intent=%s\n  equals(how to book)=%s\n\n",
        $v,
        $key,
        $intents[$v],
        FaqChatbotTextNormalizer::equals($v, 'how to book') ? 'yes' : 'no'
    );
}

$uniqueKeys = array_values(array_unique(array_values($keys)));
$okKeys = count($uniqueKeys) === 1 && $uniqueKeys[0] === 'how to book';
$uniqueIntents = array_values(array_unique(array_values($intents)));
$okIntent = count($uniqueIntents) === 1;

echo $okKeys ? "PASS match keys identical\n" : 'FAIL match keys diverge: ' . json_encode($uniqueKeys) . "\n";
echo $okIntent ? "PASS intents identical ({$uniqueIntents[0]})\n" : 'FAIL intents diverge: ' . json_encode($uniqueIntents) . "\n";

$extra = [
    'hello' => 'HELLO',
    'sakit ulo' => 'SAKIT ULO',
    'I forgot my password' => 'i FORGOT MY PASSWORD',
];
foreach ($extra as $a => $b) {
    $same = FaqChatbotTextNormalizer::equals($a, $b);
    $ia = (string) (FaqChatbotIntentRecognizer::recognize($a)['intent'] ?? '');
    $ib = (string) (FaqChatbotIntentRecognizer::recognize($b)['intent'] ?? '');
    $sameIntent = $ia === $ib;
    echo ($same ? 'PASS' : 'FAIL') . " equals($a, $b)\n";
    echo ($sameIntent ? 'PASS' : 'FAIL') . " intent($a)=$ia intent($b)=$ib\n";
}

$scopeA = FaqChatbotDomainScope::classify('Hi', 'Hi');
$scopeB = FaqChatbotDomainScope::classify('HI', 'HI');
$scopeOk = ($scopeA['scope'] ?? '') === ($scopeB['scope'] ?? '');
echo ($scopeOk ? 'PASS' : 'FAIL') . ' greeting scope case-insensitive (' . ($scopeA['scope'] ?? '') . ")\n";

exit(($okKeys && $okIntent && $scopeOk) ? 0 : 1);
