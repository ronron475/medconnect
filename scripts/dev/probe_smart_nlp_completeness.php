<?php
/**
 * Master smart NLP acceptance probes (production ClinicalInterviewEngine path).
 * Usage: php scripts/dev/probe_smart_nlp_completeness.php
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

function assess(string $text, array $prior = []): array
{
    return ClinicalInterviewEngine::assess($text, $prior, []);
}

function status(array $r): string
{
    return strtoupper((string) ($r['assessment_status'] ?? ''));
}

function qid(array $r): string
{
    return strtoupper((string) ($r['followup_question']['question_id'] ?? ''));
}

function qlang(array $r): string
{
    $q = is_array($r['followup_question'] ?? null) ? $r['followup_question'] : [];
    $lang = strtoupper((string) ($q['language'] ?? $r['question_language'] ?? $r['interview']['question_language'] ?? ''));
    return $lang;
}

function qtext(array $r): string
{
    return (string) ($r['followup_question']['text'] ?? $r['patient_message'] ?? '');
}

echo "=== Smart NLP completeness / language ===\n";

// TEST 1
$t1 = assess('I have a headache. It\'s been 2 weeks.');
ok(status($t1) === 'IN_PROGRESS', 'T1 incomplete status', status($t1));
ok(qid($t1) === 'PAIN_SEVERITY', 'T1 asks severity', qid($t1));
ok(qlang($t1) === 'ENGLISH', 'T1 English output', qlang($t1) . ' | ' . qtext($t1));
ok(str_contains(qtext($t1), '0') && str_contains(qtext($t1), '10'), 'T1 0-10 scale text');

// TEST 2
$t2 = assess('I have a headache for 2 weeks and it\'s 4/10.');
ok(qid($t2) !== 'PAIN_SEVERITY', 'T2 does not re-ask severity', qid($t2));
ok(qid($t2) !== 'ONSET' && qid($t2) !== 'DURATION', 'T2 does not re-ask timing', qid($t2));

// TEST 3
$t3 = assess('I have a mild headache for 2 weeks. It is 3/10. I have no weakness, no vision changes, no confusion, no vomiting, and no fainting.');
ok(status($t3) === 'COMPLETED' || (status($t3) === 'IN_PROGRESS' && qid($t3) !== 'PAIN_SEVERITY' && qid($t3) !== 'DURATION'),
    'T3 complete or non-duplicate follow-up', status($t3) . '/' . qid($t3));

// TEST 4 Hiligaynon incomplete
$t4 = assess('Masakit akon ulo kag duha na ka semana.');
ok(status($t4) === 'IN_PROGRESS', 'T4 HIL incomplete', status($t4));
ok(qid($t4) === 'PAIN_SEVERITY', 'T4 HIL severity', qid($t4));
ok(qlang($t4) === 'HILIGAYNON', 'T4 Hiligaynon output', qlang($t4) . ' | ' . qtext($t4));

// TEST 5 Hiligaynon rich
$t5 = assess('Masakit akon ulo duha na ka semana, 3/10 lang ang kasakit kag wala sang pagkaluya, paglain sang panan-aw, pagsusuka ukon pagkadula sang panimuot.');
ok(qid($t5) !== 'PAIN_SEVERITY' && qid($t5) !== 'DURATION' && qid($t5) !== 'ONSET',
    'T5 HIL no duplicate core', qid($t5) . '/' . status($t5));

// TEST 6 mixed
$t6 = assess('My head hurts, duha na ka semana na kag 4/10 ang kasakit.');
ok(qid($t6) !== 'PAIN_SEVERITY', 'T6 no severity re-ask', qid($t6));
ok(qid($t6) !== 'DURATION' && qid($t6) !== 'ONSET', 'T6 no timing re-ask', qid($t6));

// TEST 7 English short
$t7 = assess('My head hurts.');
ok(qlang($t7) === 'ENGLISH', 'T7 English follow-up language', qlang($t7) . ' | ' . qtext($t7));
ok(status($t7) === 'IN_PROGRESS', 'T7 incomplete', status($t7));

// TEST 8 Tagalog
$t8 = assess('Masakit ang ulo ko at dalawang linggo na.');
ok(status($t8) === 'IN_PROGRESS', 'T8 TL incomplete', status($t8));
ok(qid($t8) === 'PAIN_SEVERITY', 'T8 TL severity', qid($t8));
ok(in_array(qlang($t8), ['TAGALOG', 'HILIGAYNON'], true), 'T8 local language (not forced English)', qlang($t8));

// Multi-turn: answer severity then re-evaluate
$ctx = is_array($t1['interview'] ?? null) ? $t1['interview'] : [];
$t1b = assess('5', $ctx);
ok(qid($t1b) !== 'PAIN_SEVERITY', 'After numeric 5, no severity again', qid($t1b));
$score = $t1b['interview']['facts']['pain_score'] ?? $ctx['facts']['pain_score'] ?? null;
if ($score === null && is_array($t1b['interview']['facts'] ?? null)) {
    $score = $t1b['interview']['facts']['pain_score'] ?? null;
}
ok($score !== null && (int) $score === 5, 'Stores pain_score=5', var_export($score, true));

echo "\nFails: {$fails}\n";
exit($fails > 0 ? 1 : 0);
