<?php
require __DIR__ . '/nlp_cli_bootstrap.php';

foreach (['feve fever', 'feve', 'hello fever'] as $t) {
    $a = ClinicalInterviewEngine::assess($t, [], []);
    echo $t
        . ' status=' . ($a['assessment_status'] ?? '')
        . ' needs=' . json_encode(!empty($a['needs_valid_complaint']))
        . ' display=' . json_encode($a['triage']['triage_display'] ?? '')
        . ' q=' . json_encode($a['followup_question']['question_id'] ?? null)
        . ' complaint=' . json_encode($a['chief_complaint'] ?? '')
        . "\n";
}
