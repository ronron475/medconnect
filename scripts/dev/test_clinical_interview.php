<?php
/**
 * Clinical interview + existing NLP regression checks (no UI).
 *
 * php scripts/dev/test_clinical_interview.php
 */

require dirname(__DIR__, 2) . '/bootstrap/app.php';

ob_implicit_flush(true);
@ob_end_flush();

putenv('MEDCONNECT_PHP_NLP_ONLY=1');
putenv('MEDCONNECT_AI_INTERPRETER=0');
putenv('MEDCONNECT_SKIP_ML_LAYER=1');
$_ENV['MEDCONNECT_PHP_NLP_ONLY'] = '1';
$_ENV['MEDCONNECT_AI_INTERPRETER'] = '0';
$_ENV['MEDCONNECT_SKIP_ML_LAYER'] = '1';

$failed = 0;
$passed = 0;

function expect(bool $ok, string $label, string $detail = ''): void
{
    global $failed, $passed;
    if ($ok) {
        $passed++;
        echo "PASS  {$label}\n";
        flush();
        return;
    }
    $failed++;
    echo "FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    flush();
}

function interview(string $text, array $prior = []): array
{
    return ClinicalInterviewEngine::assess($text, $prior);
}

function statusOf(array $a): string
{
    return strtoupper((string) ($a['assessment_status'] ?? ''));
}

function classOf(array $a): string
{
    $raw = strtoupper(str_replace('_', '-', (string) ($a['triage']['triage_display'] ?? $a['triage']['triage_classification'] ?? '')));
    if (str_contains($raw, 'EMERGENCY')) {
        return 'EMERGENCY';
    }
    if (str_contains($raw, 'URGENT') && !str_contains($raw, 'NON')) {
        return 'URGENT';
    }
    if (str_contains($raw, 'NON-URGENT') || $raw === 'NONURGENT') {
        return 'NON-URGENT';
    }

    return $raw;
}

function questionOf(array $a): string
{
    return (string) ($a['followup_question']['text'] ?? $a['patient_message'] ?? '');
}

function qidOf(array $a): string
{
    return strtoupper((string) ($a['followup_question']['question_id'] ?? ''));
}

function langOf(array $a): string
{
    return strtoupper((string) ($a['followup_question']['language'] ?? $a['interview']['question_language'] ?? ''));
}

function complaintIds(array $a): array
{
    $ids = [];
    foreach ((array) ($a['interview']['chief_complaints'] ?? $a['interview']['normalized_complaints'] ?? []) as $row) {
        if (is_array($row) && isset($row['id'])) {
            $ids[] = strtoupper((string) $row['id']);
        } elseif (is_string($row)) {
            $ids[] = strtoupper($row);
        }
    }

    return $ids;
}

echo "=== Clinical interview tests ===\n";
expect(!ClinicalInterviewGeminiFollowUp::enabled(), 'Gemini follow-up skipped in NLP-only tests');

$t1 = interview('Sakit');
expect(statusOf($t1) === 'IN_PROGRESS', 'TEST 1 status IN_PROGRESS', statusOf($t1) . ' / ' . classOf($t1));
expect(qidOf($t1) === 'PAIN_LOCATION' || str_contains(mb_strtolower(questionOf($t1)), 'diin'), 'TEST 1 asks location', qidOf($t1) . ' ' . questionOf($t1));
expect(langOf($t1) === 'HILIGAYNON', 'TEST 1 Hiligaynon question', langOf($t1));
expect(($t1['followup_question']['source'] ?? '') === 'question_bank', 'TEST 1 uses question bank when Gemini is off', (string) ($t1['followup_question']['source'] ?? ''));
expect(classOf($t1) === '', 'TEST 1 no final class yet', classOf($t1));

$t2 = interview('Masakit.');
expect(statusOf($t2) === 'IN_PROGRESS', 'TEST 2 status IN_PROGRESS', statusOf($t2));
expect(langOf($t2) === 'TAGALOG', 'TEST 2 Tagalog question', langOf($t2));
expect(classOf($t2) === '', 'TEST 2 no final class yet', classOf($t2));

$t3 = interview('It hurts.');
expect(statusOf($t3) === 'IN_PROGRESS', 'TEST 3 status IN_PROGRESS', statusOf($t3));
expect(langOf($t3) === 'ENGLISH', 'TEST 3 English question', langOf($t3));
expect(classOf($t3) === '', 'TEST 3 no final class yet', classOf($t3));

$t4 = interview('Sakit ulo ko, 3/10, nagsugod kahapon kag wala iban nga sintomas.');
expect(statusOf($t4) === 'COMPLETED', 'TEST 4 completed', statusOf($t4) . ' q=' . qidOf($t4));
expect(classOf($t4) === 'NON-URGENT', 'TEST 4 NON-URGENT', classOf($t4));
expect(qidOf($t4) === '', 'TEST 4 no follow-up when sufficient', qidOf($t4));

$t4b = interview('I have a mild headache since this morning, no weakness, no numbness.');
expect(statusOf($t4b) === 'COMPLETED', 'TEST 4b sufficient English headache completes', statusOf($t4b) . ' q=' . qidOf($t4b));
expect(qidOf($t4b) === '', 'TEST 4b no follow-up when sufficient', qidOf($t4b));

$t5 = interview('Grabe gid sakit sang ulo ko, nagsugod gulpi kag nangaluya akon wala nga kamot.');
expect(statusOf($t5) === 'COMPLETED', 'TEST 5 completed', statusOf($t5));
expect(classOf($t5) === 'EMERGENCY', 'TEST 5 EMERGENCY', classOf($t5));
unset($t5);

echo "BEGIN TEST 6\n";
flush();
$t6 = interview('Sakit dughan ko.');
echo "END TEST 6\n";
flush();
expect(statusOf($t6) === 'IN_PROGRESS', 'TEST 6 chest pain in progress', statusOf($t6) . ' / ' . classOf($t6) . ' q=' . qidOf($t6));
expect(classOf($t6) === '', 'TEST 6 not immediately classified', classOf($t6));

$t7 = interview('Indi ko makahinga.');
expect(statusOf($t7) === 'COMPLETED' && classOf($t7) === 'EMERGENCY', 'TEST 7 breathing EMERGENCY', statusOf($t7) . '/' . classOf($t7));

$t8 = interview('Sakit ilong ko kag gadugo kamot ko.');
$ids8 = complaintIds($t8);
expect(
    in_array('NOSE_PAIN', $ids8, true) && in_array('BLEEDING', $ids8, true),
    'TEST 8 both complaints',
    implode(',', $ids8)
);
expect(statusOf($t8) === 'IN_PROGRESS', 'TEST 8 still collecting', statusOf($t8) . ' / ' . classOf($t8));
expect(langOf($t8) === 'HILIGAYNON', 'TEST 8 Hiligaynon questions', langOf($t8));

$t9 = interview('Masakit ulo ko at nahihilo ako.');
$ids9 = complaintIds($t9);
expect(
    in_array('HEADACHE', $ids9, true) && in_array('DIZZINESS', $ids9, true),
    'TEST 9 headache + dizziness',
    implode(',', $ids9)
);
expect(langOf($t9) === 'TAGALOG' || statusOf($t9) === 'COMPLETED', 'TEST 9 Tagalog or completed', langOf($t9) . '/' . statusOf($t9));

$t10 = interview('My chest hurts and I am having difficulty breathing.');
expect(classOf($t10) === 'EMERGENCY', 'TEST 10 chest + breathing EMERGENCY', statusOf($t10) . '/' . classOf($t10));

$t11 = interview('3/10 lang ang sakit pero kalit lang kag nangaluya akon wala nga kamot.');
expect(classOf($t11) === 'EMERGENCY', 'TEST 11 low pain + weakness EMERGENCY', classOf($t11));

$t12 = interview('10/10 ang sakit.');
expect(statusOf($t12) === 'IN_PROGRESS', 'TEST 12 high pain asks context', statusOf($t12) . '/' . classOf($t12));
expect(classOf($t12) !== 'EMERGENCY', 'TEST 12 not auto EMERGENCY', classOf($t12));

$t12b = interview('asdfgh sakit xzy');
expect(statusOf($t12b) === 'IN_PROGRESS', 'TEST 12b unintelligible asks clarification', statusOf($t12b) . ' q=' . qidOf($t12b));
expect(classOf($t12b) === '', 'TEST 12b not classified from gibberish', classOf($t12b));

echo "BEGIN TEST 13\n";
flush();
$t13 = interview('hedake kag hilo');
echo "END TEST 13\n";
flush();
$ids13 = complaintIds($t13);
$sx13 = array_map('strtolower', (array) ($t13['detected_symptoms'] ?? []));
$joined13 = strtolower(implode(' ', array_merge($ids13, $sx13)));
expect(
    str_contains($joined13, 'head') || str_contains($joined13, 'hilo') || str_contains($joined13, 'dizz'),
    'TEST 13 spelling maps headache/dizziness',
    $joined13
);

unset($t1, $t2, $t3, $t4, $t6, $t7, $t8, $t9, $t10, $t11, $t12, $t13);
echo "BEGIN TEST 14\n";
flush();
$t14 = interview('Sakit gid akon head kag daw indi ko makahinga.');
echo "END TEST 14\n";
flush();
$ids14 = complaintIds($t14);
expect(
    classOf($t14) === 'EMERGENCY' || in_array('DIFFICULTY_BREATHING', $ids14, true),
    'TEST 14 mixed language breathing',
    classOf($t14) . ' ' . implode(',', $ids14)
);

echo "\n=== Follow-up continuation ===\n";
$c1 = interview('Sakit');
$c2 = interview('Sakit ulo ko.', $c1);
expect(
    in_array('HEADACHE', complaintIds($c2), true),
    'Continuation maps headache',
    implode(',', complaintIds($c2))
);
expect(qidOf($c2) !== 'PAIN_LOCATION', 'Does not repeat location', qidOf($c2));

echo "BEGIN multi-fact continuation\n";
flush();
$painAns = interview('8 kag nagsugod gulpi kag daw naga numb akon wala nga kamot.', $c2);
echo "END multi-fact continuation\n";
flush();
expect(classOf($painAns) === 'EMERGENCY', 'Multi-fact answer becomes EMERGENCY', classOf($painAns) . ' status=' . statusOf($painAns));

echo "\n=== Dataset participation ===\n";
$kb = SymptomKnowledgeBase::load();
$rf = SymptomKnowledgeBase::loadRedFlags();
expect(!empty($kb['symptoms']), 'Symptom KB loaded', (string) count($kb['symptoms'] ?? []));
expect(!empty($rf['red_flags']), 'Red-flag library loaded', (string) count($rf['red_flags'] ?? []));
$qbank = ClinicalFollowUpQuestionBank::questions();
expect($qbank !== [], 'Follow-up question bank loaded', (string) count($qbank));

echo "\n{$passed} passed, {$failed} failed\n";

if (is_readable(dirname(__DIR__, 2) . '/config/db.php')) {
    echo "\n=== Database classification write ===\n";
    $mysql = @fsockopen('127.0.0.1', 3306, $errno, $errstr, 1);
    if ($mysql === false) {
        echo "SKIP  DB checks (MySQL not reachable)\n";
    } else {
        fclose($mysql);
        try {
            require_once dirname(__DIR__, 2) . '/config/db.php';
            require_once dirname(__DIR__, 2) . '/app/includes/triage_assessment_schema.php';
            if (isset($pdo) && $pdo instanceof PDO) {
                $pdo->setAttribute(PDO::ATTR_TIMEOUT, 3);
                triage_assessment_ensure_schema($pdo);
                $sample = interview('Indi ko makahinga.');
                $class = classOf($sample);
                expect($class === 'EMERGENCY', 'DB fixture engine class EMERGENCY', $class);
                $cols = $pdo->query("SHOW COLUMNS FROM triage_results LIKE 'assessment_status'")->fetchAll();
                expect($cols !== [], 'assessment_status column exists');
            }
        } catch (Throwable $e) {
            echo "SKIP  DB checks (" . $e->getMessage() . ")\n";
        }
    }
}

exit($failed > 0 ? 1 : 0);
