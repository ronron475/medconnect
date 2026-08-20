<?php
require dirname(__DIR__, 2) . '/bootstrap/app.php';

putenv('MEDCONNECT_PHP_NLP_ONLY=1');
putenv('MEDCONNECT_AI_INTERPRETER=0');
putenv('MEDCONNECT_SKIP_ML_LAYER=1');
$_ENV['MEDCONNECT_PHP_NLP_ONLY'] = '1';
$_ENV['MEDCONNECT_AI_INTERPRETER'] = '0';
$_ENV['MEDCONNECT_SKIP_ML_LAYER'] = '1';

function timed(string $label, callable $fn): mixed
{
    $start = microtime(true);
    $result = $fn();
    echo $label . ' ' . round(microtime(true) - $start, 3) . "s\n";
    flush();

    return $result;
}

timed('misspellings map', static fn () => count(MedicalMisspellingsLoader::sortedMap()));
timed('phrase index', static fn () => count(SymptomPhrasesLoader::phraseIndex()));

$cases = [
    'Sakit' => 'Sakit',
    't11' => '3/10 lang ang sakit pero kalit lang kag nangaluya akon wala nga kamot.',
    't12' => '10/10 ang sakit.',
    't12-repeat' => '10/10 ang sakit.',
    't13' => 'hedake kag hilo',
];

foreach ($cases as $label => $text) {
    timed($label, static fn () => ClinicalInterviewEngine::assess($text));
}
