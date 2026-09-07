<?php
require __DIR__ . '/nlp_cli_bootstrap.php';

function ok(bool $c, string $l, string $d = ''): void
{
    echo ($c ? 'PASS  ' : 'FAIL  ') . $l . ($d !== '' ? " — {$d}" : '') . "\n";
    if (!$c) {
        $GLOBALS['fails'] = ($GLOBALS['fails'] ?? 0) + 1;
    }
}
$GLOBALS['fails'] = 0;

echo "=== Duration extraction ===\n";
$cases = [
    'tatlo na ka semana' => '3 weeks',
    '3 weeks' => '3 weeks',
    'three weeks' => '3 weeks',
    'tatlong linggo' => '3 weeks',
    '3 ka semana' => '3 weeks',
    'isa ka semana' => '1 week',
    'duha ka semana' => '2 weeks',
    'dalawang linggo' => '2 weeks',
    'for 2 weeks' => '2 weeks',
    'tatlo na ka bulan' => '3 months',
    'for three months' => '3 months',
    'tatlong buwan' => '3 months',
    'dugay na' => 'For a long time',
    '5 ka adlaw' => '5 days',
    'tatlo na ka adlaw' => '3 days',
];
foreach ($cases as $text => $expect) {
    $d = ClinicalFeatureExtractors::extractDuration($text);
    $label = (string) ($d['label'] ?? '');
    ok($label === $expect || str_contains($label, explode(' ', $expect)[0]), "dur: {$text}", $label);
}

echo "\n=== Demo interview skip onset ===\n";
$p = NlpStep3DemoTrial::assess('kasakit ulo ko, tatlo na ka semana', [], ['allow_gemini' => false]);
$missing = is_array($p['missing_fields'] ?? null) ? $p['missing_fields'] : [];
$s = $p['complaint_summary'] ?? [];
ok(($s['location'] ?? '') === 'head' || str_contains(mb_strtolower((string) ($s['location'] ?? '')), 'head'), 'location head', (string) ($s['location'] ?? ''));
ok(str_contains((string) ($s['duration'] ?? ''), 'week'), 'duration weeks', (string) ($s['duration'] ?? ''));
ok(trim((string) ($s['onset'] ?? '')) !== '', 'onset filled', (string) ($s['onset'] ?? ''));
ok(!in_array('onset', $missing, true), 'onset NOT in missing', json_encode($missing));
ok(($p['followup_question']['question_id'] ?? '') !== 'ONSET', 'next question not ONSET', (string) ($p['followup_question']['question_id'] ?? ''));

$variants = [
    'masakit ang ulo ko for three weeks',
    'sakit ulo ko 3 weeks na',
    'nagasakit akon ulo duha ka semana',
    'my head hurts for 2 weeks',
    'tatlong linggo nang masakit ang ulo ko',
];
foreach ($variants as $text) {
    $r = NlpStep3DemoTrial::assess($text, [], ['allow_gemini' => false]);
    $miss = is_array($r['missing_fields'] ?? null) ? $r['missing_fields'] : [];
    ok(!in_array('onset', $miss, true), "no onset missing: {$text}", json_encode($miss));
    ok(($r['followup_question']['question_id'] ?? '') !== 'ONSET', "not ask ONSET: {$text}", (string) ($r['followup_question']['question_id'] ?? ''));
}

echo "\nFails: " . (int) ($GLOBALS['fails'] ?? 0) . "\n";
exit(($GLOBALS['fails'] ?? 0) > 0 ? 1 : 0);
