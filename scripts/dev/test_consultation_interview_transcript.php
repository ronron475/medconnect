<?php
/**
 * Doctor consultation interview transcript is read from assessment_payload only.
 * It pairs stored follow-up questions with patient answers and ignores suggested questions.
 */
declare(strict_types=1);

require_once __DIR__ . '/nlp_cli_bootstrap.php';
require_once BASE_PATH . '/app/includes/provider_clinical_support.php';

$pass = 0;
$fail = 0;

function check(bool $ok, string $label): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "PASS  {$label}\n";
        return;
    }
    $fail++;
    echo "FAIL  {$label}\n";
}

$payload = [
    'suggested_questions' => [
        'Any fever, chest pain, difficulty breathing, or other red-flag symptoms?',
    ],
    'consult_type' => 'video',
    'chief_complaint' => 'Gasakit akon ulo',
    'followup_question' => [
        'question_id' => 'DURATION',
        'text' => 'Makadugay na bala ini?',
    ],
    'interview' => [
        'question_language' => 'HILIGAYNON',
        'patient_turns' => ['Gasakit akon ulo', 'kahapon', '7'],
        'questions_answered' => [
            ['question_id' => 'ONSET', 'answer' => 'normalized onset'],
            ['question_id' => 'PAIN_SEVERITY', 'answer' => 'normalized pain'],
        ],
        'last_followup_question' => [
            'question_id' => 'ONSET',
            'text' => 'San-o ini nagsugod ang imo kasakit?',
        ],
        'complaints' => [
            [
                'questions_answered' => [
                    ['question_id' => 'ONSET', 'answer' => 'normalized onset'],
                ],
            ],
        ],
    ],
];

$rows = provider_clinical_support_interview_transcript_from_payload($payload);
check(count($rows) === 2, 'two answered follow-ups, opening complaint excluded');
check(($rows[0]['question_id'] ?? '') === 'ONSET', 'first row is ONSET');
check(
    ($rows[0]['question'] ?? '') === 'San-o ini nagsugod ang imo kasakit?',
    'stored follow-up wording is kept'
);
check(($rows[0]['answer'] ?? '') === 'kahapon', 'patient_turns answer is used for ONSET');
check(
    ($rows[1]['question'] ?? '') === 'Pila ka grabe ang imo kasakit, halin 1 tubtob 10?',
    'Hiligaynon bank text is used when no stored wording exists'
);
check(($rows[1]['answer'] ?? '') === '7', 'patient_turns answer is used for pain score');
check(($rows[0]['answer'] ?? '') !== 'Gasakit akon ulo', 'chief complaint is not shown as an answer');

$questions = array_column($rows, 'question');
check(
    !in_array('Any fever, chest pain, difficulty breathing, or other red-flag symptoms?', $questions, true),
    'suggested questions are not copied into the transcript'
);
check(!in_array('video', $questions, true) && !in_array('video', array_column($rows, 'answer'), true), 'consult_type is not used');

$english = provider_clinical_support_interview_transcript_from_payload([
    'interview' => [
        'question_language' => 'ENGLISH',
        'patient_turns' => ['I have a headache', 'yesterday morning'],
        'questions_answered' => [
            ['question_id' => 'ONSET', 'answer' => 'yesterday morning'],
        ],
    ],
]);
check(
    ($english[0]['question'] ?? '') === 'When did this start, and did it begin suddenly or gradually?',
    'English bank text is used for an English interview'
);

$tagalog = provider_clinical_support_interview_transcript_from_payload([
    'interview' => [
        'question_language' => 'TAGALOG',
        'patient_turns' => ['Masakit ang ulo ko', 'kahapon'],
        'questions_answered' => [
            ['question_id' => 'ONSET', 'answer' => 'kahapon'],
        ],
    ],
]);
check(
    ($tagalog[0]['question'] ?? '') === 'Kailan ito nagsimula, at biglaan ba o unti-unti?',
    'Tagalog bank text is used for a Tagalog interview'
);

check(provider_clinical_support_interview_transcript_from_payload([]) === [], 'empty payload has no transcript');
check(
    provider_clinical_support_interview_transcript_from_payload([
        'interview' => [
            'patient_turns' => ['I have a headache'],
            'questions_answered' => [],
            'suggested_questions' => ['How long have you had these symptoms?'],
        ],
    ]) === [],
    'complaint-only interview has no follow-up rows'
);
check(
    provider_clinical_support_interview_transcript_from_payload([
        'interview' => [
            'patient_turns' => ['I have a headache', 'yesterday'],
            'questions_answered' => [
                ['question_id' => 'NOT_A_REAL_QUESTION', 'answer' => 'yesterday'],
            ],
        ],
    ]) === [],
    'an answer without a known question is not shown'
);

$other = provider_clinical_support_interview_transcript_from_payload([
    'interview' => [
        'question_language' => 'english',
        'patient_turns' => ['Other patient headache', '2 days'],
        'questions_answered' => [
            ['question_id' => 'DURATION', 'answer' => '2 days'],
        ],
    ],
]);
check(($other[0]['answer'] ?? '') === '2 days', 'a second payload stays on its own answers');
check(($rows[1]['answer'] ?? '') === '7', 'the first payload is unchanged by the second');

$stored = provider_clinical_support_without_interview_transcript([
    'chief_complaint' => 'headache',
    'interview_transcript' => $rows,
    'suggested_questions' => ['How long have you had these symptoms?'],
]);
check(!array_key_exists('interview_transcript', $stored), 'snapshot copy drops the interview transcript');
check(($stored['chief_complaint'] ?? '') === 'headache', 'snapshot copy keeps the chief complaint');
check(count($stored['suggested_questions'] ?? []) === 1, 'snapshot copy keeps suggested questions separate');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
