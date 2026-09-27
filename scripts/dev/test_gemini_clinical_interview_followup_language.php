<?php
/**
 * Focused follow-up language tests for the Gemini clinical interview demo.
 *
 * 1) Language lock (no Gemini): English, Hiligaynon, Tagalog, mixed dominant, sticky turns, clear switch.
 * 2) Live follow-up wording from GeminiClinicalInterviewDemo::start / ::answer.
 *
 * Usage: php scripts/dev/test_gemini_clinical_interview_followup_language.php
 */
require __DIR__ . '/nlp_cli_bootstrap.php';

$fail = 0;
$pass = 0;

function ok(string $label, bool $cond, string $detail = ''): void
{
    global $fail, $pass;
    if ($cond) {
        echo "PASS  $label" . ($detail !== '' ? " [$detail]" : '') . "\n";
        $pass++;
        return;
    }
    echo "FAIL  $label" . ($detail !== '' ? " [$detail]" : '') . "\n";
    $fail++;
}

$cases = [
    'english' => [
        'complaint' => 'I have a headache',
        'expect' => 'english',
        'hold' => 'yes',
        'switch' => 'Gasakit gid akon ulo kag budlay ko magginhawa subong',
        'switch_expect' => 'hiligaynon',
    ],
    'hiligaynon' => [
        'complaint' => 'Gasakit akon ulo',
        'expect' => 'hiligaynon',
        'hold' => 'oo',
        'switch' => 'I have been vomiting since yesterday morning',
        'switch_expect' => 'english',
    ],
    'tagalog' => [
        'complaint' => 'Masakit ang ulo ko',
        'expect' => 'tagalog',
        'hold' => 'opo',
        'switch' => 'Masakit gid akon dughan kag budlay magginhawa',
        'switch_expect' => 'hiligaynon',
    ],
    'mixed' => [
        'complaint' => 'I have hilanat kag sakit ulo since yesterday',
        'expect' => 'hiligaynon',
        'hold' => '7',
        'switch' => 'Simula kahapon masakit ang ulo ko at nahihilo ako',
        'switch_expect' => 'tagalog',
    ],
];

echo "=== language lock ===\n";
foreach ($cases as $name => $case) {
    $opened = GeminiClinicalInterviewDemo::questionLanguageStateForTest($case['complaint']);
    $state = GeminiClinicalInterviewDemo::questionLanguageStateForTest($case['complaint'], [
        $case['hold'],
        '3 days',
        $case['switch'],
    ]);
    ok(
        "$name opening language",
        ($opened['question_language'] ?? '') === $case['expect'],
        (string) ($opened['question_language'] ?? '')
    );
    $after = $state['after_turns'] ?? [];
    ok(
        "$name stays after short reply",
        ($after[0]['question_language'] ?? '') === $case['expect'],
        (string) ($after[0]['question_language'] ?? '')
    );
    ok(
        "$name stays after brief fragment",
        ($after[1]['question_language'] ?? '') === $case['expect'],
        (string) ($after[1]['question_language'] ?? '')
    );
    ok(
        "$name switches when patient clearly changes language",
        ($after[2]['question_language'] ?? '') === $case['switch_expect'],
        (string) ($after[2]['question_language'] ?? '')
    );
}

ok(
    'hiligaynon question accepted',
    GeminiClinicalInterviewDemo::followUpQuestionLanguageMatchesForTest('San-o nagsugod ang imo kasakit sa dughan?', 'hiligaynon')
);
ok(
    'tagalog question accepted',
    GeminiClinicalInterviewDemo::followUpQuestionLanguageMatchesForTest('Kailan ito nagsimula ang sakit ng ulo mo?', 'tagalog')
);
ok(
    'english question accepted',
    GeminiClinicalInterviewDemo::followUpQuestionLanguageMatchesForTest('When did this headache start?', 'english')
);
ok(
    'english question rejected for hiligaynon',
    !GeminiClinicalInterviewDemo::followUpQuestionLanguageMatchesForTest('When did this headache start?', 'hiligaynon')
);
ok(
    'bare english symptom rejected for hiligaynon',
    !GeminiClinicalInterviewDemo::followUpQuestionLanguageMatchesForTest('Any vomiting?', 'hiligaynon')
);

echo "=== live follow-ups ===\n";
putenv('MEDCONNECT_PHP_NLP_ONLY=0');
$_ENV['MEDCONNECT_PHP_NLP_ONLY'] = '0';

function liveStart(string $complaint): array
{
    $last = [];
    for ($try = 0; $try < 1; $try++) {
        $last = GeminiClinicalInterviewDemo::start($complaint);
        $msg = strtolower((string) ($last['message'] ?? ''));
        if (empty($last['error']) || (!str_contains($msg, '503') && !str_contains($msg, '429'))) {
            return $last;
        }
    }

    return $last;
}

function liveAnswer(string $answer, array $prior): array
{
    $last = [];
    for ($try = 0; $try < 1; $try++) {
        $last = GeminiClinicalInterviewDemo::answer($answer, $prior);
        $msg = strtolower((string) ($last['message'] ?? ''));
        if (empty($last['error']) || (!str_contains($msg, '503') && !str_contains($msg, '429'))) {
            return $last;
        }
    }

    return $last;
}

foreach ($cases as $name => $case) {
    if ($name !== array_key_first($cases)) {
        sleep(12);
    }
    $complaint = $case['complaint'];
    $started = liveStart($complaint);
    $question = trim((string) ($started['awaiting_question'] ?? ''));
    $locked = strtolower((string) ($started['interview_context']['question_language'] ?? ''));
    $storedComplaint = (string) ($started['chief_complaint'] ?? '');
    $authority = (string) ($started['final_triage']['final_authority'] ?? '');
    $detail = $question !== '' ? $question : (string) ($started['message'] ?? '');
    $langOk = $question !== ''
        && $locked === $case['expect']
        && $storedComplaint === $complaint
        && GeminiClinicalInterviewDemo::followUpQuestionLanguageMatchesForTest($question, $case['expect']);
    if ($authority !== '') {
        $langOk = $langOk && $authority === 'ClinicalTriageEngine';
    }
    ok("live $name follow-up", $langOk, $detail);

    if ($question === '' || !empty($started['error'])) {
        ok("live $name next follow-up still $case[expect]", false, 'no first follow-up');
        continue;
    }

    $continued = liveAnswer($case['hold'], $started);
    if (!empty($continued['error'])) {
        ok("live $name next follow-up still {$case['expect']}", false, (string) ($continued['message'] ?? 'error'));
        continue;
    }
    $next = trim((string) ($continued['awaiting_question'] ?? ''));
    $locked2 = strtolower((string) ($continued['interview_context']['question_language'] ?? ''));
    $stored2 = (string) ($continued['chief_complaint'] ?? '');
    $authority2 = (string) ($continued['final_triage']['final_authority'] ?? '');
    if (($continued['status'] ?? '') === 'final_triage' || ($continued['status'] ?? '') === 'information_sufficient') {
        $still = $locked2 === $case['expect']
            && $stored2 === $complaint
            && ($authority2 === '' || $authority2 === 'ClinicalTriageEngine');
        ok("live $name next follow-up still {$case['expect']}", $still, 'interview ended; language ' . $locked2);
        continue;
    }
    $still = $next !== ''
        && $locked2 === $case['expect']
        && $stored2 === $complaint
        && GeminiClinicalInterviewDemo::followUpQuestionLanguageMatchesForTest($next, $case['expect']);
    ok("live $name next follow-up still {$case['expect']}", $still, $next !== '' ? $next : (string) ($continued['message'] ?? ''));
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
