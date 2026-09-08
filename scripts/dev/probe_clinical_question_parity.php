<?php
/**
 * Clinical question parity: same intents across EN / HIL / TL; pain scale mandatory.
 * Usage: php scripts/dev/probe_clinical_question_parity.php
 */
declare(strict_types=1);

require __DIR__ . '/nlp_cli_bootstrap.php';

$pass = 0;
$fail = 0;

function assertTrue(bool $ok, string $label): void
{
    global $pass, $fail;
    if ($ok) {
        echo "PASS  {$label}\n";
        $pass++;
    } else {
        echo "FAIL  {$label}\n";
        $fail++;
    }
}

function assess(string $text, array $prior = []): array
{
    return NlpStep3DemoTrial::assess($text, $prior, ['allow_gemini' => false]);
}

function qid(array $r): string
{
    return strtoupper((string) ($r['followup_question']['question_id'] ?? ''));
}

function qtext(array $r): string
{
    return (string) ($r['followup_question']['text'] ?? '');
}

$en = assess('My head hurts. It has been hurting for 2 weeks.');
$enQ = qid($en);
$enText = qtext($en);
assertTrue($enQ === 'PAIN_SEVERITY', 'EN headache next=PAIN_SEVERITY (got ' . $enQ . ')');
assertTrue(
    str_contains($enText, '0') && str_contains($enText, '10'),
    'EN pain scale mentions 0 and 10'
);

$hil = assess('Masakit akon ulo kag duha na ka semana.');
$hilQ = qid($hil);
$hilText = qtext($hil);
assertTrue($hilQ === 'PAIN_SEVERITY', 'HIL headache next=PAIN_SEVERITY (got ' . $hilQ . ')');
assertTrue(
    str_contains($hilText, '0') && str_contains($hilText, '10'),
    'HIL pain scale mentions 0 and 10'
);
assertTrue($hilQ === $enQ, 'EN/HIL same clinical question id');

$tl = assess('Masakit ang aking ulo, dalawang linggo na.');
$tlQ = qid($tl);
assertTrue($tlQ === 'PAIN_SEVERITY', 'TL headache next=PAIN_SEVERITY (got ' . $tlQ . ')');

assertTrue(qid(assess('Masakit gid ang ulo ko.')) === 'PAIN_SEVERITY', 'HIL "gid" still asks PAIN_SEVERITY');
assertTrue(qid(assess('Grabe sakit ulo ko.')) === 'PAIN_SEVERITY', 'HIL "grabe" still asks PAIN_SEVERITY');
assertTrue(qid(assess('I have severe headache for 2 weeks.')) === 'PAIN_SEVERITY', 'EN "severe" still asks PAIN_SEVERITY');
assertTrue(qid(assess('Sobrang sakit ng ulo ko.')) === 'PAIN_SEVERITY', 'TL "sobrang" still asks PAIN_SEVERITY');

$scored = assess('7', $en);
$score = $scored['facts']['pain_score']
    ?? ($scored['clinical_state']['severity'] ?? null)
    ?? ($scored['interview']['clinical_state']['severity'] ?? null);
assertTrue($score !== null && (int) $score === 7, 'Numeric 7 fills pain_score (got ' . var_export($score, true) . ')');
assertTrue(qid($scored) !== 'PAIN_SEVERITY', 'After 7, do not re-ask PAIN_SEVERITY (got ' . qid($scored) . ')');

echo "\n{$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    echo "\nEN sample: " . json_encode([
        'qid' => $enQ,
        'text' => $enText,
        'status' => $en['assessment_status'] ?? '',
        'missing' => $en['missing_fields'] ?? [],
    ], JSON_UNESCAPED_UNICODE) . "\n";
    echo "HIL sample: " . json_encode([
        'qid' => $hilQ,
        'text' => $hilText,
        'status' => $hil['assessment_status'] ?? '',
    ], JSON_UNESCAPED_UNICODE) . "\n";
}
exit($fail > 0 ? 1 : 0);
