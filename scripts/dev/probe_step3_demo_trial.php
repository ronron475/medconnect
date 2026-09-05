<?php
require __DIR__ . '/nlp_cli_bootstrap.php';

$cases = [
    'sakit',
    'sakit akon tiyan',
    'Masakit gid akon dughan kag budlay magginhawa.',
    'sakitgbgjgbvd',
    'hello',
];

foreach ($cases as $t) {
    $a = ClinicalInterviewEngine::assess($t);
    echo "=== {$t} ===\n";
    echo 'status=' . ($a['assessment_status'] ?? '') . "\n";
    echo 'class=' . ($a['triage']['triage_display'] ?? '') . "\n";
    echo 'qid=' . ($a['followup_question']['question_id'] ?? '') . "\n";
    echo 'q=' . ($a['followup_question']['text'] ?? '') . "\n";
    echo 'facts=' . json_encode($a['interview']['facts'] ?? [], JSON_UNESCAPED_UNICODE) . "\n";
    echo 'unclear=' . (ClinicalFeatureExtractors::isUnintelligibleComplaint($t) ? '1' : '0') . "\n";
    echo 'vague=' . (ClinicalFeatureExtractors::isVagueComplaint($t) ? '1' : '0') . "\n";
    echo 'domain_unclear=' . (FaqChatbotDomainScope::looksUnclear($t) ? '1' : '0') . "\n";
    echo 'health=' . (FaqChatbotDomainScope::isHealthcareRelated($t) ? '1' : '0') . "\n";
    echo 'greeting=' . (FaqChatbotDomainScope::isAllowedOpening($t) ? '1' : '0') . "\n\n";
}

$c1 = ClinicalInterviewEngine::assess('sakit');
$c2 = ClinicalInterviewEngine::assess('tiyan', $c1);
$c3 = ClinicalInterviewEngine::assess('7', $c2);
echo "=== chain sakit -> tiyan -> 7 ===\n";
echo 'status=' . ($c3['assessment_status'] ?? '') . "\n";
echo 'class=' . ($c3['triage']['triage_display'] ?? '') . "\n";
echo 'transcript=' . ($c3['clinical_transcript'] ?? '') . "\n";
echo 'facts=' . json_encode($c3['interview']['facts'] ?? [], JSON_UNESCAPED_UNICODE) . "\n";
echo 'qid=' . ($c3['followup_question']['question_id'] ?? '') . "\n";
echo 'q=' . ($c3['followup_question']['text'] ?? '') . "\n";
