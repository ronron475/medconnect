<?php
/**
 * Focused FAQ chatbot XSS / HTML sanitizer tests.
 * Run: php scripts/dev/test_faq_xss_security.php
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$failures = 0;

function pass(string $m): void
{
    echo "PASS  {$m}\n";
}

function fail(string $m, string $d = ''): void
{
    global $failures;
    $failures++;
    echo "FAIL  {$m}" . ($d !== '' ? " — {$d}" : '') . "\n";
}

function assert_no_js_vector(string $out, string $label): void
{
    $lower = strtolower($out);
    $bad = [
        'onclick',
        'onerror',
        'onload',
        'onmouseover',
        'javascript:',
        '<script',
        '</script',
        '<iframe',
        '<svg',
        '<img',
        'expression(',
    ];
    foreach ($bad as $needle) {
        if (str_contains($lower, $needle)) {
            fail($label, "still contains {$needle}: {$out}");
            return;
        }
    }
    pass($label);
}

require_once $root . '/app/core/FaqChatbotHtmlSanitizer.php';
require_once $root . '/app/core/FaqChatbotAiFallback.php';
require_once $root . '/app/core/FaqChatbotDomainScope.php';
require_once $root . '/app/core/FaqChatbotResponseGenerator.php';

echo "FAQ XSS security\n";

// --- FaqChatbotHtmlSanitizer ---
$eventPayload = '<p onclick="alert(1)">Rest and hydrate</p>';
$out = FaqChatbotHtmlSanitizer::sanitize($eventPayload);
if (str_contains($out, 'Rest and hydrate') && !str_contains(strtolower($out), 'onclick')) {
    pass('strips onclick from allowlisted <p>');
} else {
    fail('strips onclick from allowlisted <p>', $out);
}
assert_no_js_vector($out, 'onclick payload has no JS vectors');

$imgPayload = 'Hello <img src=x onerror=alert(1)> there';
$out = FaqChatbotHtmlSanitizer::sanitize($imgPayload);
assert_no_js_vector($out, 'strips img/onerror XSS');
if (str_contains($out, 'Hello') && str_contains($out, 'there')) {
    pass('preserves surrounding text around removed img');
} else {
    fail('preserves surrounding text around removed img', $out);
}

$scriptPayload = '<p>Safe</p><script>alert(1)</script><p>More</p>';
$out = FaqChatbotHtmlSanitizer::sanitize($scriptPayload);
assert_no_js_vector($out, 'strips script tags');
if (str_contains($out, 'Safe') && str_contains($out, 'More')) {
    pass('keeps sibling paragraph text after script removal');
} else {
    fail('keeps sibling paragraph text after script removal', $out);
}

$svgPayload = '<svg/onload=alert(1)><p>ok</p>';
$out = FaqChatbotHtmlSanitizer::sanitize($svgPayload);
assert_no_js_vector($out, 'strips svg/onload XSS');

$jsUrl = '<a href="javascript:alert(1)">click</a><p>body</p>';
$out = FaqChatbotHtmlSanitizer::sanitize($jsUrl);
assert_no_js_vector($out, 'unwraps javascript: anchor without executing');
if (str_contains($out, 'click') || str_contains($out, 'body')) {
    pass('preserves anchor text content after unwrap');
} else {
    fail('preserves anchor text content after unwrap', $out);
}

$stripTagsTrap = '<p onmouseover="alert(1)" class="evil">Tip</p><br onclick="alert(2)">';
$out = FaqChatbotHtmlSanitizer::sanitize($stripTagsTrap);
assert_no_js_vector($out, 'strip_tags allowlist trap: attributes removed');
if (str_contains(strtolower($out), 'class="evil"')) {
    fail('non-fcb class must be removed', $out);
} else {
    pass('removes non-fcb class tokens');
}

$legit = '<div class="fcb-kb-answer" data-kb-key="login_help"><p>Use <strong>Sign In</strong>.</p></div>';
$out = FaqChatbotHtmlSanitizer::sanitize($legit);
if (str_contains($out, 'fcb-kb-answer')
    && str_contains($out, 'data-kb-key="login_help"')
    && str_contains($out, '<strong>')
    && str_contains($out, 'Sign In')
) {
    pass('preserves legitimate FAQ KB formatting');
} else {
    fail('preserves legitimate FAQ KB formatting', $out);
}

$empathy = '<div class="fcb-empathy fcb-empathy--calm" role="note">'
    . '<span class="fcb-empathy__icon" aria-hidden="true">💬</span>'
    . '<p class="fcb-empathy__text">I hear you.</p></div>';
$out = FaqChatbotHtmlSanitizer::sanitize($empathy);
if (str_contains($out, 'fcb-empathy')
    && str_contains($out, 'role="note"')
    && str_contains($out, 'aria-hidden="true"')
    && str_contains($out, 'I hear you.')
) {
    pass('preserves empathy markup attrs');
} else {
    fail('preserves empathy markup attrs', $out);
}

$plainXss = '<script>alert(1)</script>';
$out = FaqChatbotHtmlSanitizer::sanitize($plainXss);
assert_no_js_vector($out, 'bare script payload neutralized');

// --- FaqChatbotAiFallback gate (must not return raw attacker HTML) ---
$stored = '<p onclick=alert(1)>Drink water</p><img src=x onerror=alert(2)>';
$out = FaqChatbotAiFallback::sanitizePatientFacingHtml($stored, 'en');
assert_no_js_vector($out, 'sanitizePatientFacingHtml blocks stored XSS');
if (str_contains($out, 'Drink water')) {
    pass('sanitizePatientFacingHtml keeps safe FAQ text');
} else {
    fail('sanitizePatientFacingHtml keeps safe FAQ text', $out);
}

$reflected = 'Please help with <svg onload=alert(1)> login';
$out = FaqChatbotAiFallback::sanitizePatientFacingHtml($reflected, 'en');
assert_no_js_vector($out, 'sanitizePatientFacingHtml blocks reflected-style markup');

$dbAnswer = FaqChatbotResponseGenerator::faqAnswerHtml('<script>alert(1)</script>Call 911');
assert_no_js_vector($dbAnswer, 'faqAnswerHtml escapes DB answer content');
$dbSanitized = FaqChatbotAiFallback::sanitizePatientFacingHtml($dbAnswer, 'en');
assert_no_js_vector($dbSanitized, 'orchestrator path re-sanitizes escaped FAQ answer');
if (str_contains($dbSanitized, 'Call 911') && str_contains($dbSanitized, 'fcb-faq-answer')) {
    pass('FAQ answer formatting class preserved after sanitize');
} else {
    fail('FAQ answer formatting class preserved after sanitize', $dbSanitized);
}

$toSafe = FaqChatbotAiFallback::toSafeHtml("Line one\n\n<script>alert(1)</script>Line two");
assert_no_js_vector($toSafe, 'toSafeHtml never emits script');
if (str_contains($toSafe, 'Line one') && str_contains($toSafe, 'Line two')) {
    pass('toSafeHtml preserves paragraph breaks as safe HTML');
} else {
    fail('toSafeHtml preserves paragraph breaks as safe HTML', $toSafe);
}

echo "\n";
if ($failures === 0) {
    echo "RESULT: PASS ({$failures} failures)\n";
    exit(0);
}
echo "RESULT: FAIL ({$failures} failures)\n";
exit(1);
