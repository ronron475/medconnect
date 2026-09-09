<?php
/**
 * Follow-up generation: never re-ask known timing / location / severity.
 *
 * Usage: php scripts/dev/probe_followup_no_duplicate.php
 */
declare(strict_types=1);

require __DIR__ . '/nlp_cli_bootstrap.php';

$fails = 0;
function ok(bool $c, string $l, string $d = ''): void
{
    global $fails;
    echo ($c ? 'PASS  ' : 'FAIL  ') . $l . ($d !== '' ? " — {$d}" : '') . "\n";
    if (!$c) {
        $fails++;
    }
}

function qid(array $a): string
{
    return strtoupper((string) ($a['followup_question']['question_id'] ?? $a['interview']['awaiting_question_id'] ?? ''));
}

function facts(array $a): array
{
    return is_array($a['interview']['facts'] ?? null) ? $a['interview']['facts'] : [];
}

echo "=== Extractors ===\n";
$d = ClinicalFeatureExtractors::extractDuration('sakit ulo ko ya ligad pa');
ok(($d['label'] ?? '') !== '', 'ligad pa → duration', (string) ($d['label'] ?? ''));
ok(ClinicalFeatureExtractors::hasTimingInformation('sakit ulo ko ya ligad pa'), 'hasTiming ligad pa');
ok(ClinicalFeatureExtractors::hasTimingInformation('Masakit ang ulo ko since yesterday'), 'hasTiming since yesterday');
ok(ClinicalFeatureExtractors::hasTimingInformation('headache dugay na'), 'hasTiming dugay na');
ok(!ClinicalFeatureExtractors::hasTimingInformation('sakit ulo ko'), 'no timing bare headache');

echo "=== Opening: sakit ulo ko ya ligad pa ===\n";
$a = ClinicalInterviewEngine::assess('sakit ulo ko ya ligad pa');
$f = facts($a);
ok(
    ($f['duration_label'] ?? '') !== '' || ($f['onset'] ?? '') !== '',
    'stores timing from ligad pa',
    'duration=' . ($f['duration_label'] ?? '') . ' onset=' . ($f['onset'] ?? '')
);
ok(
    in_array('head', (array) ($f['body_locations'] ?? []), true)
        || str_contains(mb_strtolower((string) ($a['interview']['chief_complaint'] ?? '')), 'ulo'),
    'knows head/ulo location'
);
$q1 = qid($a);
ok($q1 !== 'ONSET' && $q1 !== 'DURATION', 'does NOT ask ONSET/DURATION first', $q1 !== '' ? $q1 : (string) ($a['assessment_status'] ?? 'none'));
ok($q1 === 'PAIN_SEVERITY' || $q1 === '' || ($a['assessment_status'] ?? '') === 'COMPLETED', 'asks severity or completes', $q1);

if ($q1 === 'PAIN_SEVERITY') {
    $b = ClinicalInterviewEngine::assess('5', $a['interview'] ?? []);
    $q2 = qid($b);
    ok($q2 !== 'ONSET' && $q2 !== 'DURATION', 'after score still skips ONSET/DURATION', $q2 !== '' ? $q2 : 'none');
}

echo "=== English already-known duration ===\n";
$c = ClinicalInterviewEngine::assess('My headache started 2 weeks ago');
ok(qid($c) !== 'ONSET' && qid($c) !== 'DURATION', 'skips timing', qid($c) ?: (string) ($c['assessment_status'] ?? ''));

echo "=== Tagalog duration ===\n";
$t = ClinicalInterviewEngine::assess('Masakit ang ulo ko dalawang linggo na');
ok(
    ClinicalFeatureExtractors::hasTimingInformation(
        (string) ($t['interview']['chief_complaint'] ?? 'Masakit ang ulo ko dalawang linggo na'),
        facts($t)
    ),
    'Tagalog weeks timing known'
);
ok(qid($t) !== 'ONSET' && qid($t) !== 'DURATION', 'Tagalog skips timing ask', qid($t) ?: 'none');

echo "=== Answer validation keeps same question ===\n";
$base = ClinicalInterviewEngine::assess('I have a fever');
$ctx = $base['interview'] ?? [];
$ctx['awaiting_question_id'] = 'DURATION';
$ctx['questions_asked'] = array_values(array_unique(array_merge(
    (array) ($ctx['questions_asked'] ?? []),
    ['DURATION']
)));
$ctx['last_followup_question'] = [
    'question_id' => 'DURATION',
    'text' => 'How long have you had the fever?',
    'language' => 'ENGLISH',
];
$rej = ClinicalInterviewEngine::assess('I like basketball', $ctx);
ok(!empty($rej['answer_rejected']), 'unrelated rejected');
ok(($rej['interview']['awaiting_question_id'] ?? '') === 'DURATION', 'keeps DURATION');

$okAns = ClinicalInterviewEngine::assess('ligad pa', $ctx);
ok(empty($okAns['answer_rejected']), 'ligad pa accepted as duration answer');
ok(
    (facts($okAns)['duration_label'] ?? '') !== '' || (facts($okAns)['onset'] ?? '') !== '',
    'ligad pa stored as timing',
    json_encode(facts($okAns), JSON_UNESCAPED_UNICODE)
);

echo "\n";
if ($fails > 0) {
    echo "FAILED {$fails} check(s)\n";
    exit(1);
}
echo "All duplicate-followup checks passed.\n";
exit(0);
