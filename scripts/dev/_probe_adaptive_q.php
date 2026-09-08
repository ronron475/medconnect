<?php
require __DIR__ . '/nlp_cli_bootstrap.php';
$cases = [
    'Masakit tiyan ko',
    'Masakit sa tuo nga idalom sang tiyan ko',
    'kasakit ulo ko, tatlo na ka semana, 3/10',
    'sakit mata ko',
    'ginaubo ko',
    'masakit akon dughan',
];
foreach ($cases as $c) {
    $p = NlpStep3DemoTrial::assess($c, [], ['allow_gemini' => false]);
    $s = $p['complaint_summary'] ?? [];
    echo "=== {$c} ===\n";
    echo 'family=' . ($s['family'] ?? '') . ' loc=' . ($s['location'] ?? '') . ' lat=' . ($s['laterality'] ?? '') . "\n";
    echo 'dur=' . ($s['duration'] ?? '') . ' onset=' . ($s['onset'] ?? '') . ' sev=' . ($s['pain_severity'] ?? '') . "\n";
    echo 'qid=' . (($p['followup_question']['question_id'] ?? '') ?: 'none') . ' missing=' . json_encode($p['missing_fields'] ?? []) . "\n";
    echo 'q=' . mb_substr((string) (($p['followup_question']['text'] ?? '') ?: ($p['patient_message'] ?? '')), 0, 120) . "\n\n";
}
