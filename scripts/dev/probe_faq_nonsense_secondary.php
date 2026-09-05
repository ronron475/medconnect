<?php
/**
 * Probe: FAQ chatbot nonsense / Gemini secondary classification routing.
 * Run: php scripts/dev/probe_faq_nonsense_secondary.php
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/app/core/FaqChatbotDomainScope.php';
require_once $root . '/app/core/FaqChatbotAiFallback.php';

function line(string $label, mixed $value): void
{
    if (is_bool($value)) {
        $value = $value ? 'true' : 'false';
    }
    echo str_pad($label, 28) . ': ' . (string) $value . PHP_EOL;
}

$cases = [
    'lkfdlkgrl' => ['expect_nonsense' => true, 'expect_health' => false],
    'asdfgh' => ['expect_nonsense' => true, 'expect_health' => false],
    'hello' => ['expect_nonsense' => false, 'expect_opening' => true],
    'masakit akon ulo' => ['expect_nonsense' => false, 'expect_health' => true],
    'masakit akon olo' => ['expect_nonsense' => false, 'expect_health' => true],
    'olo' => ['expect_nonsense' => false],
    'kagapong' => ['expect_nonsense' => false],
    'How do I book an appointment?' => ['expect_nonsense' => false, 'expect_health' => true],
    'haha test lang' => ['expect_nonsense' => true, 'expect_health' => false],
    'Masakit akon ulo kag nahilo ko.' => ['expect_nonsense' => false, 'expect_health' => true],
    'gshdhdhdhd' => ['expect_nonsense' => true, 'expect_health' => false],
];
$cases['123123123'] = ['expect_nonsense' => true, 'expect_health' => false];

echo "=== Local heuristics ===\n";
$fail = 0;
foreach ($cases as $input => $expect) {
    $input = (string) $input;
    $nonsense = FaqChatbotDomainScope::isLikelyNonsenseOrPrank($input);
    $unclear = FaqChatbotDomainScope::looksUnclear($input);
    $health = FaqChatbotDomainScope::isHealthcareRelated($input);
    $opening = FaqChatbotDomainScope::isAllowedOpening($input);
    $ok = true;
    if (($expect['expect_nonsense'] ?? null) === true && !($nonsense || $unclear)) {
        $ok = false;
    }
    if (($expect['expect_nonsense'] ?? null) === false && ($expect['expect_health'] ?? false) && ($nonsense && !$health)) {
        $ok = false;
    }
    if (($expect['expect_health'] ?? null) === true && !$health) {
        $ok = false;
    }
    if (($expect['expect_opening'] ?? null) === true && !$opening) {
        $ok = false;
    }
    if (!$ok) {
        $fail++;
    }
    echo ($ok ? '[OK] ' : '[FAIL] ') . json_encode($input, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    line('  nonsense', $nonsense);
    line('  looksUnclear', $unclear);
    line('  healthcare', $health);
    line('  opening', $opening);
}

echo "\n=== Gemini JSON parse / normalize ===\n";
$jsonCases = [
    '{"understood":false,"confidence":0.97,"classification":"NONSENSE_OR_PRANK","normalized_text":null,"meaning":null,"clinical_entities":{}}',
    '{"understood":true,"confidence":0.95,"classification":"MEDICAL_SYMPTOM","normalized_text":"masakit akon ulo","meaning":"head pain","clinical_entities":{"symptom":"headache","body_location":"head"}}',
    '{"understood":true,"confidence":0.91,"classification":"MEDICAL_FOLLOWUP_ANSWER","normalized_text":"ulo","meaning":"head","clinical_entities":{"body_location":"head"}}',
    '{"understood":true,"confidence":0.9,"classification":"MEDCONNECT_SERVICE","normalized_text":"book appointment","meaning":"appointment","clinical_entities":{}}',
    '{"understood":true,"confidence":0.88,"classification":"GREETING","normalized_text":"hello","meaning":"greeting","clinical_entities":{}}',
    '{"understood":false,"confidence":0.55,"classification":"UNKNOWN","normalized_text":null,"meaning":null,"clinical_entities":{}}',
    '{"classification":"NON_HEALTH_RELATED","confidence":0.9}',
];

foreach ($jsonCases as $raw) {
    $parsed = FaqChatbotAiFallback::parseModelReply($raw);
    $pack = FaqChatbotAiFallback::packFromParsed($parsed, 'hil');
    echo 'IN:  ' . $raw . PHP_EOL;
    line('  class', $pack['classification'] ?? '');
    line('  fine', $pack['fine_classification'] ?? '');
    line('  response', $pack['response_type'] ?? '');
    line('  health', !empty($pack['is_healthcare_related']));
    $plain = trim(strip_tags((string) ($pack['html'] ?? '')));
    line('  html_snip', mb_substr($plain, 0, 90));
    echo PHP_EOL;
}

echo "=== Clarification copy (hil) ===\n";
echo strip_tags(FaqChatbotDomainScope::nonsenseClarificationHtml('hil')) . PHP_EOL;
echo strip_tags(FaqChatbotDomainScope::unclearHtml('hil')) . PHP_EOL;

echo "\nHeuristic failures: {$fail}\n";
exit($fail > 0 ? 1 : 0);
