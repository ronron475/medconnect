<?php
require __DIR__ . '/../../bootstrap/app.php';
ob_implicit_flush(true);

putenv('MEDCONNECT_PHP_NLP_ONLY=1');
putenv('MEDCONNECT_AI_INTERPRETER=0');
putenv('MEDCONNECT_SKIP_ML_LAYER=1');
$_ENV['MEDCONNECT_PHP_NLP_ONLY'] = '1';
$_ENV['MEDCONNECT_AI_INTERPRETER'] = '0';
$_ENV['MEDCONNECT_SKIP_ML_LAYER'] = '1';

$cases = [
    'Sakit',
    'Masakit.',
    'It hurts.',
    'Sakit ulo ko, 3/10, nagsugod kahapon kag wala iban nga sintomas.',
    'Grabe gid sakit sang ulo ko, nagsugod gulpi kag nangaluya akon wala nga kamot.',
    'Sakit dughan ko.',
    'Indi ko makahinga.',
    'Sakit ilong ko kag gadugo kamot ko.',
    'Masakit ulo ko at nahihilo ako.',
    'My chest hurts and I am having difficulty breathing.',
    '3/10 lang ang sakit pero kalit lang kag nangaluya akon wala nga kamot.',
    '10/10 ang sakit.',
    'hedake kag hilo',
    'Sakit gid akon head kag daw indi ko makahinga.',
];

foreach ($cases as $i => $text) {
    $n = $i + 1;
    $t0 = microtime(true);
    $a = ClinicalInterviewEngine::assess($text);
    $ms = round(microtime(true) - $t0, 2);
    $ids = [];
    foreach ((array) ($a['interview']['chief_complaints'] ?? []) as $row) {
        $ids[] = is_array($row) ? (string) ($row['id'] ?? '') : (string) $row;
    }
    echo sprintf(
        "T%02d %.2fs %s class=%s lang=%s q=%s complaints=%s flags=%s\n",
        $n,
        $ms,
        $a['assessment_status'] ?? '',
        $a['triage']['triage_display'] ?? $a['triage']['triage_classification'] ?? '',
        $a['followup_question']['language'] ?? $a['interview']['question_language'] ?? '',
        $a['followup_question']['question_id'] ?? '-',
        implode('|', $ids),
        implode('|', (array) ($a['triage']['red_flags'] ?? []))
    );
}
