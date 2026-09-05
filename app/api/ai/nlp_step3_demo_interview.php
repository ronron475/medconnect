<?php
/**
 * TRIAL ONLY — adaptive chief-complaint interview for nlp_step3_demo.php.
 * Does not persist triage_results or touch production chatbot routes.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';

Api::startJson();
Api::requirePost();
set_time_limit(120);

$utterance = trim((string) ($_POST['utterance'] ?? $_POST['chief_complaint'] ?? $_POST['text'] ?? ''));
$reset = filter_var($_POST['reset'] ?? false, FILTER_VALIDATE_BOOLEAN);

$priorRaw = $_POST['interview_context'] ?? $_POST['context'] ?? '';
if (is_string($priorRaw)) {
    $decoded = json_decode($priorRaw, true);
    $prior = is_array($decoded) ? $decoded : [];
} elseif (is_array($priorRaw)) {
    $prior = $priorRaw;
} else {
    $prior = [];
}

if ($reset) {
    $prior = [];
}

if ($utterance === '' && !$reset) {
    Api::error('Enter a chief complaint or follow-up answer.');
}

if (mb_strlen($utterance) > 1000) {
    Api::error('Input is too long (max 1000 characters).');
}

$result = NlpStep3DemoTrial::assess($utterance, $prior);

Api::success([
    'trial' => $result,
    'interview_context' => $result['interview_context'],
], 'Step 3 demo interview turn complete.');
