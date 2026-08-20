<?php
/**
 * Live Gemini phrasing smoke (does not classify triage).
 * php scripts/dev/smoke_gemini_followup.php
 */
require dirname(__DIR__, 2) . '/bootstrap/app.php';

echo 'enabled=' . (ClinicalInterviewGeminiFollowUp::enabled() ? 'yes' : 'no') . PHP_EOL;
if (!ClinicalInterviewGeminiFollowUp::enabled()) {
    echo "skip: Gemini follow-up is off\n";
    exit(0);
}

$slot = [
    'question_id' => 'PAIN_LOCATION',
    'clinical_purpose' => 'Identify where the pain or discomfort is felt.',
    'red_flag_related' => false,
    'priority' => 1,
    'language' => 'HILIGAYNON',
];
$context = [
    'question_language' => 'hiligaynon',
    'chief_complaints' => [],
    'facts' => [],
    'questions_asked' => [],
];
$bank = 'Diin mo nabatyagan ang sakit ukon discomfort?';

$start = microtime(true);
$text = ClinicalInterviewGeminiFollowUp::phrase($slot, $context, 'Sakit', $bank);
$ms = (int) round((microtime(true) - $start) * 1000);
echo 'ms=' . $ms . PHP_EOL;
echo 'text=' . ($text !== '' ? $text : '(empty, bank would be used)') . PHP_EOL;
if ($text === '') {
    $err = ClinicalInterviewGeminiFollowUp::lastError();
    if ($err !== '') {
        echo 'error=' . $err . PHP_EOL;
    }
}
