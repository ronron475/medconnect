<?php
/**
 * Acceptance probes: follow-up answer relevance gate (PHP-only; Gemini skipped).
 *
 * Usage: php scripts/dev/probe_followup_answer_relevance.php
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

/**
 * @param array<string, mixed> $assessment
 * @return array<string, mixed>
 */
function withAwaiting(array $assessment, string $qid): array
{
    $ctx = is_array($assessment['interview'] ?? null) ? $assessment['interview'] : [];
    $qid = strtoupper($qid);
    $ctx['awaiting_question_id'] = $qid;
    $asked = is_array($ctx['questions_asked'] ?? null) ? $ctx['questions_asked'] : [];
    if (!in_array($qid, $asked, true)) {
        $asked[] = $qid;
    }
    $ctx['questions_asked'] = $asked;
    $lang = strtolower((string) ($ctx['question_language'] ?? 'english'));
    $row = ClinicalFollowUpQuestionBank::byId($qid);
    $text = is_array($row) ? ClinicalFollowUpQuestionBank::textForLanguage($row, $lang) : $qid;
    $ctx['last_followup_question'] = [
        'question_id' => $qid,
        'text' => $text,
        'language' => strtoupper($lang === 'tagalog' ? 'TAGALOG' : ($lang === 'hiligaynon' ? 'HILIGAYNON' : 'ENGLISH')),
    ];

    return $ctx;
}

echo "=== Follow-up answer relevance ===\n";

$fever = ClinicalInterviewEngine::assess('I have a fever.');
ok(
    ($fever['assessment_status'] ?? '') === ClinicalInterviewEngine::STATUS_IN_PROGRESS
        || ($fever['assessment_status'] ?? '') === ClinicalInterviewEngine::STATUS_COMPLETED,
    'fever opening runs',
    (string) ($fever['assessment_status'] ?? '')
);
$durCtx = withAwaiting($fever, 'DURATION');

$t1 = ClinicalInterviewEngine::assess('Yesterday.', $durCtx);
ok(empty($t1['retry_current_question']), 'TEST 1 yesterday accepted');
ok(
    empty($t1['answer_rejected']),
    'TEST 1 not rejected',
    (string) ($t1['followup_answer_validation']['reason'] ?? $t1['assessment_status'] ?? '')
);
ok(
    str_contains(strtolower((string) ($t1['interview']['duration'] ?? $t1['interview']['facts']['duration_label'] ?? $t1['interview']['onset'] ?? '')), 'yesterday')
        || str_contains(strtolower(json_encode($t1['interview']['patient_turns'] ?? []) ?: ''), 'yesterday'),
    'TEST 1 stores duration-ish text',
    json_encode([
        'duration' => $t1['interview']['duration'] ?? '',
        'onset' => $t1['interview']['onset'] ?? '',
        'turns' => $t1['interview']['patient_turns'] ?? [],
    ], JSON_UNESCAPED_UNICODE)
);

$t2 = ClinicalInterviewEngine::assess('yesturday', $durCtx);
ok(empty($t2['retry_current_question']) && empty($t2['answer_rejected']), 'TEST 2 yesturday accepted');
ok(
    str_contains(strtolower((string) json_encode($t2['interview']['patient_turns'] ?? [])), 'yesterday')
        || str_contains(strtolower((string) ($t2['interview']['duration'] ?? '')), 'yesterday')
        || str_contains(strtolower((string) ($t2['interview']['onset'] ?? '')), 'yesterday'),
    'TEST 2 corrected toward yesterday',
    json_encode($t2['interview']['patient_turns'] ?? [], JSON_UNESCAPED_UNICODE)
);

$t3 = ClinicalInterviewEngine::assess('I like basketball.', $durCtx);
ok(!empty($t3['retry_current_question']) || !empty($t3['answer_rejected']), 'TEST 3 basketball rejected');
ok(($t3['interview']['awaiting_question_id'] ?? '') === 'DURATION', 'TEST 3 keeps DURATION');
ok(
    str_contains(strtolower((string) ($t3['patient_message'] ?? '')), 'related')
        || str_contains((string) ($t3['patient_message'] ?? ''), 'kaangtanan')
        || str_contains((string) ($t3['patient_message'] ?? ''), 'kaugnayan'),
    'TEST 3 polite retry',
    (string) ($t3['patient_message'] ?? '')
);
ok(
    !str_contains(strtolower(json_encode($t3['interview']['patient_turns'] ?? []) ?: ''), 'basketball'),
    'TEST 3 does not store basketball as a turn'
);

$head = ClinicalInterviewEngine::assess('I have a headache.');
$sevCtx = withAwaiting($head, 'PAIN_SEVERITY');
$priorTurns = $sevCtx['patient_turns'] ?? [];
$priorDuration = (string) ($sevCtx['duration'] ?? $sevCtx['facts']['duration_label'] ?? '');

$t4 = ClinicalInterviewEngine::assess('My stomach hurts.', $sevCtx);
ok(!empty($t4['retry_current_question']) || !empty($t4['answer_rejected']), 'TEST 4 stomach-on-severity rejected');
ok(($t4['interview']['awaiting_question_id'] ?? '') === 'PAIN_SEVERITY', 'TEST 4 keeps PAIN_SEVERITY');
ok(($t4['interview']['pain_score'] ?? null) === null, 'TEST 4 does not record stomach as pain score');

$t5 = ClinicalInterviewEngine::assess('5', $sevCtx);
ok(empty($t5['answer_rejected']), 'TEST 5 score 5 accepted');
ok((int) ($t5['interview']['pain_score'] ?? 0) === 5, 'TEST 5 pain_severity=5', (string) ($t5['interview']['pain_score'] ?? 'null'));

$t6 = ClinicalInterviewEngine::assess('It is about a 7 out of 10.', $sevCtx);
ok(empty($t6['answer_rejected']), 'TEST 6 7/10 accepted');
ok((int) ($t6['interview']['pain_score'] ?? 0) === 7, 'TEST 6 pain_severity=7', (string) ($t6['interview']['pain_score'] ?? 'null'));

$hil = ClinicalInterviewEngine::assess('Masakit akon ulo.');
$locCtx = withAwaiting($hil, 'PAIN_LOCATION');
$t7 = ClinicalInterviewEngine::assess('Sa wala nga bahin.', $locCtx);
ok(empty($t7['answer_rejected']), 'TEST 7 Hiligaynon location accepted');

$t8 = ClinicalInterviewEngine::assess('Ang akon utod naga-eskwela.', $locCtx);
ok(!empty($t8['answer_rejected']) || !empty($t8['retry_current_question']), 'TEST 8 Hiligaynon unrelated rejected');
ok(($t8['interview']['awaiting_question_id'] ?? '') === 'PAIN_LOCATION', 'TEST 8 keeps location question');
ok(
    str_contains((string) ($t8['patient_message'] ?? ''), 'kaangtanan')
        || str_contains((string) ($t8['patient_message'] ?? ''), 'related'),
    'TEST 8 Hiligaynon retry language',
    (string) ($t8['patient_message'] ?? '') . ' / lang=' . (string) ($hil['interview']['question_language'] ?? '')
);

$tl = ClinicalInterviewEngine::assess('Masakit ang ulo ko.');
$tlDur = withAwaiting($tl, 'DURATION');
$t9 = ClinicalInterviewEngine::assess('Dalawang linggo na.', $tlDur);
ok(empty($t9['answer_rejected']), 'TEST 9 Tagalog duration accepted');

$t10 = ClinicalInterviewEngine::assess('asdfgh', $durCtx);
ok(!empty($t10['answer_rejected']) || !empty($t10['retry_current_question']), 'TEST 10 nonsense rejected');

$t11 = ClinicalInterviewEngine::assess('', $durCtx);
ok(!empty($t11['answer_rejected']) || !empty($t11['retry_current_question']), 'TEST 11 empty rejected');
ok(
    str_contains(strtolower((string) ($t11['patient_message'] ?? '')), 'provide an answer')
        || str_contains((string) ($t11['patient_message'] ?? ''), 'Palihog hatag')
        || str_contains((string) ($t11['patient_message'] ?? ''), 'Pakibigay ang sagot sa tanong'),
    'TEST 11 empty prompt',
    (string) ($t11['patient_message'] ?? '')
);

$already = ClinicalInterviewEngine::assess('My headache started 2 weeks ago.');
$askedId = strtoupper((string) ($already['followup_question']['question_id'] ?? $already['interview']['awaiting_question_id'] ?? ''));
ok(
    $askedId !== 'DURATION' && $askedId !== 'ONSET',
    'TEST 12 does not re-ask duration',
    $askedId !== '' ? $askedId : (string) ($already['assessment_status'] ?? 'none')
);

$head2 = ClinicalInterviewEngine::assess('headache for 2 weeks');
$sev2 = withAwaiting($head2, 'PAIN_SEVERITY');
$knownDuration = (string) ($sev2['duration'] ?? $sev2['facts']['duration_label'] ?? $head2['interview']['duration'] ?? '');
$rej = ClinicalInterviewEngine::assess('My mother has diabetes.', $sev2);
ok(!empty($rej['answer_rejected']), 'unrelated medical info rejected on severity');
ok(
    ($rej['interview']['patient_turns'] ?? []) === ($sev2['patient_turns'] ?? []),
    'prior turns preserved after reject'
);
ok(
    (string) ($rej['interview']['duration'] ?? $rej['interview']['facts']['duration_label'] ?? '') === $knownDuration
        || $knownDuration === ''
        || str_contains(strtolower(json_encode($rej['interview']['patient_turns'] ?? []) ?: ''), 'week'),
    'prior duration not discarded',
    'known=' . $knownDuration . ' after=' . (string) ($rej['interview']['duration'] ?? '')
);

$yesOnSev = ClinicalInterviewEngine::assess('No.', $sevCtx);
ok(!empty($yesOnSev['answer_rejected']), 'yes/no rejected on pain severity');

$assoc = withAwaiting($head, 'ASSOCIATED_SYMPTOMS');
$noAssoc = ClinicalInterviewEngine::assess('No.', $assoc);
ok(empty($noAssoc['answer_rejected']), 'No accepted on associated symptoms');

$v = ClinicalFollowUpAnswerValidator::validate('yesterday', 'DURATION', [
    'chief_complaint' => 'I have a fever.',
    'question_language' => 'english',
    'facts' => [],
]);
ok(!empty($v['accept']), 'validator yesterday', $v['reason'] ?? '');

$vBad = ClinicalFollowUpAnswerValidator::validate('I want to eat pizza', 'DURATION', [
    'chief_complaint' => 'I have a fever.',
    'question_language' => 'english',
    'facts' => [],
]);
ok(empty($vBad['accept']), 'validator pizza rejected', $vBad['reason'] ?? '');

$ctxEn = ['chief_complaint' => 'I have a fever.', 'question_language' => 'english', 'facts' => []];
$ctxHead = ['chief_complaint' => 'I have a headache.', 'question_language' => 'english', 'facts' => []];
$ctxHil = ['chief_complaint' => 'Masakit akon ulo.', 'question_language' => 'hiligaynon', 'facts' => []];

$acc = [
    ['yesterday', 'DURATION', $ctxEn, true, 'since yesterday'],
    ['since yesterday', 'DURATION', $ctxEn, true, 'since yesterday phrase'],
    ['2 days', 'DURATION', $ctxEn, true, '2 days'],
    ['for three days', 'DURATION', $ctxEn, true, 'for three days'],
    ['since last night', 'DURATION', $ctxEn, true, 'since last night'],
    ['about a week', 'DURATION', $ctxEn, true, 'about a week'],
    ['almost a week', 'DURATION', $ctxEn, true, 'almost a week'],
    ['since Monday', 'DURATION', $ctxEn, true, 'since Monday'],
    ['yesturday', 'DURATION', $ctxEn, true, 'typo yesterday'],
    ['yesturday my dog was sick', 'DURATION', $ctxEn, true, 'mixed duration+dog'],
    ['ligad pa', 'DURATION', $ctxHil, true, 'ligad pa'],
    ['dugay na', 'DURATION', $ctxHil, true, 'dugay na'],
    ['kahapon', 'DURATION', $ctxHil, true, 'kahapon'],
    ['Duha na ka semana.', 'DURATION', $ctxHil, true, 'duha ka semana'],
    ['Dalawang linggo na.', 'DURATION', $ctxEn, true, 'dalawang linggo'],
    ['5', 'PAIN_SEVERITY', $ctxHead, true, 'bare 5'],
    ['sevun', 'PAIN_SEVERITY', $ctxHead, true, 'sevun'],
    ['It is about a 7 out of 10.', 'PAIN_SEVERITY', $ctxHead, true, '7 out of 10'],
    ['around 6', 'PAIN_SEVERITY', $ctxHead, true, 'around 6'],
    ['Very severe', 'PAIN_SEVERITY', $ctxHead, true, 'very severe'],
    ['It hurts a lot', 'PAIN_SEVERITY', $ctxHead, true, 'hurts a lot'],
    ['Sa wala nga bahin.', 'PAIN_LOCATION', $ctxHil, true, 'wala nga bahin'],
    ['May hilo ako', 'ASSOCIATED_SYMPTOMS', $ctxHead, true, 'hilo associated'],
    ['Wala na', 'ASSOCIATED_SYMPTOMS', $ctxHil, true, 'wala na'],
    ['I like basketball.', 'DURATION', $ctxEn, false, 'basketball'],
    ['I went for a walk', 'DURATION', $ctxEn, false, 'walk is not duration'],
    ['I am 20', 'DURATION', $ctxEn, false, 'bare age not duration'],
    ['My dog is 5 years old.', 'PAIN_SEVERITY', $ctxHead, false, 'dog age'],
    ['My stomach hurts.', 'PAIN_SEVERITY', $ctxHead, false, 'stomach on severity'],
    ['I have diarrhea.', 'PAIN_SEVERITY', $ctxHead, false, 'diarrhea on severity'],
    ['No.', 'PAIN_SEVERITY', $ctxHead, false, 'no on severity'],
    ['asdfgh', 'DURATION', $ctxEn, false, 'smash'],
    ['I went to school yesterday', 'DURATION', $ctxEn, true, 'extract yesterday from mixed'],
];

echo "=== Accuracy lexicon ===\n";
foreach ($acc as [$ans, $qid, $ctx, $expect, $label]) {
    $r = ClinicalFollowUpAnswerValidator::validate($ans, $qid, $ctx);
    ok(!empty($r['accept']) === $expect, ($expect ? 'ACCEPT' : 'REJECT') . " {$label}", ($r['reason'] ?? '') . ' / ' . $ans);
}

echo "\n";
if ($fails > 0) {
    echo "FAILED {$fails} check(s)\n";
    exit(1);
}
echo "All follow-up relevance checks passed.\n";
exit(0);
