<?php
/**
 * Probe: FFF / nonsense must never triage as EMERGENCY.
 * Run: php scripts/dev/probe_fff_no_emergency.php
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($root): void {
    $path = $root . '/app/core/' . $class . '.php';
    if (is_readable($path)) {
        require_once $path;
    }
});

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

$fail = 0;
function ok(bool $cond, string $label): void
{
    global $fail;
    if (!$cond) {
        $fail++;
        echo "[FAIL] {$label}\n";
    } else {
        echo "[OK]   {$label}\n";
    }
}

echo "=== DomainScope: FFF ===\n";
ok(FaqChatbotDomainScope::looksUnclear('FFF'), 'FFF looksUnclear');
ok(FaqChatbotDomainScope::isLikelyNonsenseOrPrank('FFF'), 'FFF nonsense');
ok(FaqChatbotDomainScope::lacksClinicalMeaning('FFF'), 'FFF lacksClinicalMeaning');
ok(!FaqChatbotDomainScope::isHealthcareRelated('FFF'), 'FFF not healthcare');
ok(!ClinicalFeatureExtractors::isVagueComplaint('FFF'), 'FFF not vague pain');

echo "\n=== Misspellings not nonsense ===\n";
ok(!FaqChatbotDomainScope::looksUnclear('olo'), 'olo not unclear');
ok(!FaqChatbotDomainScope::isLikelyNonsenseOrPrank('masakit akon olo'), 'olo medical not nonsense');
ok(FaqChatbotDomainScope::isHealthcareRelated('masakit akon olo'), 'olo medical is healthcare');

echo "\n=== Out-of-scope ===\n";
ok(FaqChatbotDomainScope::isMeaningfulOutOfScope('Who is the president?'), 'president OOS');
ok(!FaqChatbotDomainScope::isMeaningfulOutOfScope('FFF'), 'FFF not meaningful OOS');
ok(!FaqChatbotDomainScope::isMeaningfulOutOfScope('olo'), 'olo not meaningful OOS');

echo "\n=== Emergency detector raw FFF ===\n";
$em = FaqChatbotEmergencyDetector::detect('FFF');
ok(empty($em['is_emergency']), 'FFF raw not emergency');

echo "\n=== KB emergency_redirect with memory pollution ===\n";
$_SESSION['faq_chatbot_memory'] = [
    'current_topic' => 'emergency',
    'last_kb_key' => 'emergency_redirect',
    'turns' => [
        ['role' => 'user', 'text' => 'chest pain'],
        ['role' => 'bot', 'text' => 'This may be urgent. Call 911'],
    ],
];
$hit = FaqChatbotKnowledgeBase::match('FFF', 'emergency emergency redirect chest pain FFF', 'en', [
    'context_boost' => 'emergency emergency redirect chest pain',
]);
ok($hit === null || ($hit['key'] ?? '') !== 'emergency_redirect', 'FFF must not match emergency_redirect (got ' . ($hit['key'] ?? 'null') . ')');

$hitReal = FaqChatbotKnowledgeBase::match('I have severe chest pain and cannot breathe', '', 'en', []);
ok($hitReal !== null, 'real emergency still matchable (key=' . ($hitReal['key'] ?? 'null') . ')');

echo "\n=== NLP demo gate ===\n";

try {
    $pack = NlpStep3DemoTrial::assess('FFF', []);
    ok(($pack['triage_display'] ?? 'x') === '' || ($pack['triage_display'] ?? null) === null, 'demo triage_display empty');
    ok(($pack['triage_final'] ?? null) === null, 'demo triage_final null');
    ok(($pack['clinical_status'] ?? '') === 'SKIPPED' || ($pack['domain_class'] ?? '') === 'NONSENSE_OR_UNKNOWN', 'demo skipped clinical');
    $gClass = (string) (($pack['gemini']['classification'] ?? '') ?: ($pack['domain_class'] ?? ''));
    ok(str_contains($gClass, 'NONSENSE') || $gClass === 'UNCLEAR', 'demo class nonsense-ish (' . $gClass . ')');
    $blob = (string) ($pack['triage_display'] ?? '') . ' ' . (string) ($pack['patient_message'] ?? '');
    ok(!preg_match('/\b(EMERGENCY|URGENT|911)\b/i', $blob), 'demo no emergency wording');
    echo '  primary_nlp=' . (($pack['gemini']['primary_nlp'] ?? '') ?: 'n/a') . PHP_EOL;
    echo '  gemini_status=' . (($pack['gemini']['status'] ?? '') ?: 'n/a') . PHP_EOL;
    echo '  final_routing=' . (($pack['gemini']['final_routing'] ?? '') ?: 'n/a') . PHP_EOL;
} catch (Throwable $e) {
    echo '[SKIP] demo assess: ' . $e->getMessage() . PHP_EOL;
}

echo "\nFailures: {$fail}\n";
exit($fail > 0 ? 1 : 0);
