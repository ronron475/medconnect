<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2) . '/data/nlp';

$map = [
    'headache' => 'sakit ulo',
    'chest_pain' => 'masakit dughan',
    'abdominal_pain' => 'masakit tiyan',
    'difficulty_breathing' => 'budlay ginhawa',
    'cough' => 'ubo',
    'fever' => 'hilanat',
    'dizziness' => 'nalipong',
    'vomiting' => 'suka',
    'diarrhea' => 'libang',
    'eye_pain' => 'sakit mata',
    'bleeding' => 'nagadugo',
    'weakness' => 'kaluya',
    'burn' => 'nasunog',
];

$path = $root . '/symptom_expression_variants.csv';
$h = fopen($path, 'r');
$header = fgetcsv($h);
$rows = [];
while (($r = fgetcsv($h)) !== false) {
    $d = array_combine($header, $r);
    $cid = $d['concept_id'] ?? '';
    if (isset($map[$cid])) {
        $d['canonical_phrase'] = $map[$cid];
    }
    $rows[] = $d;
}
fclose($h);
$o = fopen($path, 'w');
fputcsv($o, $header);
foreach ($rows as $d) {
    fputcsv($o, array_map(static fn ($k) => $d[$k] ?? '', $header));
}
fclose($o);
echo 'fixed variants ' . count($rows) . PHP_EOL;

$badTargets = [
    'headache', 'chest pain', 'abdominal pain', 'difficulty breathing', 'cough', 'fever',
    'dizziness', 'vomiting', 'diarrhea', 'eye pain', 'bleeding', 'weakness', 'burn', 'burns',
];
$mp = $root . '/medical_misspellings.csv';
$h = fopen($mp, 'r');
$header = fgetcsv($h);
$keep = [];
$removed = 0;
while (($r = fgetcsv($h)) !== false) {
    $d = array_combine(
        array_map(static fn ($x) => strtolower((string) $x), $header),
        array_map(static fn ($x) => (string) $x, $r)
    );
    $correct = strtolower(trim($d['correct_term'] ?? ''));
    $type = strtolower(trim($d['term_type'] ?? ''));
    if ($type === 'expression_variant' && in_array($correct, $badTargets, true)) {
        $removed++;
        continue;
    }
    $keep[] = $r;
}
fclose($h);
$o = fopen($mp, 'w');
fputcsv($o, $header);
foreach ($keep as $r) {
    fputcsv($o, $r);
}
fclose($o);
echo "removed bad misspell rows {$removed} kept " . count($keep) . PHP_EOL;
