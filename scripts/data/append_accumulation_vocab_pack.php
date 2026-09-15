<?php
/**
 * Append-only accumulation pack: colloquial Hiligaynon follow-up vocabulary,
 * negation of red-flag phrasing, and diarrhea/vomiting surface forms.
 * Does not overwrite existing rows.
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2) . '/data/nlp';

function loadExistingKeys(string $path, int $keyCol = 0): array
{
    $keys = [];
    if (!is_readable($path)) {
        return $keys;
    }
    $h = fopen($path, 'r');
    if ($h === false) {
        return $keys;
    }
    fgetcsv($h);
    while (($row = fgetcsv($h)) !== false) {
        if (!isset($row[$keyCol])) {
            continue;
        }
        $keys[strtolower(trim((string) $row[$keyCol]))] = true;
    }
    fclose($h);

    return $keys;
}

function appendCsv(string $path, array $header, array $rows, int $keyCol = 0): int
{
    $existing = loadExistingKeys($path, $keyCol);
    $added = 0;
    $isNew = !is_readable($path) || filesize($path) === 0;
    $h = fopen($path, $isNew ? 'w' : 'a');
    if ($h === false) {
        fwrite(STDERR, "Cannot write {$path}\n");

        return 0;
    }
    if ($isNew) {
        fputcsv($h, $header);
    }
    foreach ($rows as $row) {
        $key = strtolower(trim((string) ($row[$keyCol] ?? '')));
        if ($key === '' || isset($existing[$key])) {
            continue;
        }
        fputcsv($h, $row);
        $existing[$key] = true;
        $added++;
    }
    fclose($h);

    return $added;
}

$stats = [];

// Hiligaynon medical terms (feeds SymptomKnowledgeBase CSV boosts)
$hilTerms = [
    ['', 'ga suka', 'Vomiting', 'vomiting', 'hiligaynon', 'active'],
    ['', 'ga suka ko', 'Vomiting', 'vomiting', 'hiligaynon', 'active'],
    ['', 'gasuka ko', 'Vomiting', 'vomiting', 'hiligaynon', 'active'],
    ['', 'ginasuka ko', 'Vomiting', 'vomiting', 'hiligaynon', 'active'],
    ['', 'nagsuka ko', 'Vomiting', 'vomiting', 'hiligaynon', 'active'],
    ['', 'galupot', 'Diarrhea', 'diarrhea', 'hiligaynon', 'active'],
    ['', 'galupot tiyan', 'Diarrhea', 'diarrhea', 'hiligaynon', 'active'],
    ['', 'permi galupot', 'Diarrhea', 'diarrhea', 'hiligaynon', 'active'],
    ['', 'ga lupot', 'Diarrhea', 'diarrhea', 'hiligaynon', 'active'],
    ['', 'nagagalupot', 'Diarrhea', 'diarrhea', 'hiligaynon', 'active'],
    ['', 'masakit gid ulo', 'Headache', 'headache', 'hiligaynon', 'active'],
    ['', 'masakit gid ulo ko', 'Headache', 'headache', 'hiligaynon', 'active'],
    ['', 'gasakit ulo ko', 'Headache', 'headache', 'hiligaynon', 'active'],
    ['', 'nag gulpi', 'Sudden onset', 'sudden_onset', 'hiligaynon', 'active'],
    ['', 'nagsugod gulpi', 'Sudden onset', 'sudden_onset', 'hiligaynon', 'active'],
    ['', 'gulpi ang pagsugod', 'Sudden onset', 'sudden_onset', 'hiligaynon', 'active'],
    ['', 'grabe ang pagsugod', 'Sudden severe onset', 'sudden_onset', 'hiligaynon', 'active'],
];
$stats['hiligaynon_medical_terms.csv'] = appendCsv(
    $root . '/hiligaynon_medical_terms.csv',
    ['term_id', 'term', 'english', 'concept', 'language', 'status'],
    $hilTerms,
    1
);

$filTerms = [
    ['', 'sinusuka ako', 'Vomiting', 'vomiting', 'filipino', 'active'],
    ['', 'nagsusuka ako', 'Vomiting', 'vomiting', 'filipino', 'active'],
    ['', 'nagtatae', 'Diarrhea', 'diarrhea', 'filipino', 'active'],
    ['', 'sumasakit ang ulo', 'Headache', 'headache', 'filipino', 'active'],
    ['', 'biglang sumakit', 'Sudden onset', 'sudden_onset', 'filipino', 'active'],
];
$stats['filipino_medical_terms.csv'] = appendCsv(
    $root . '/filipino_medical_terms.csv',
    ['term_id', 'term', 'english', 'concept', 'language', 'status'],
    $filTerms,
    1
);

$dictRows = [
    ['', 'ga suka', 'vomiting', 'symptom'],
    ['', 'ga suka ko', 'vomiting', 'symptom'],
    ['', 'gasuka ko', 'vomiting', 'symptom'],
    ['', 'galupot', 'diarrhea', 'symptom'],
    ['', 'galupot tiyan', 'diarrhea', 'symptom'],
    ['', 'permi galupot', 'diarrhea', 'symptom'],
    ['', 'masakit gid ulo ko', 'severe headache', 'symptom'],
    ['', 'gasakit ulo ko', 'headache', 'symptom'],
    ['', 'nag gulpi', 'sudden onset', 'symptom'],
    ['', 'nagsugod gulpi', 'sudden onset', 'symptom'],
    ['', 'grabe ang pagsugod', 'sudden severe onset', 'symptom'],
    ['', 'ambot', 'uncertain', 'response'],
    ['', 'di ko sure', 'uncertain', 'response'],
    ['', 'indi ko sure', 'uncertain', 'response'],
    ['', 'wala ko sure', 'uncertain', 'response'],
];
$stats['medical_dictionary.csv'] = appendCsv(
    $root . '/medical_dictionary.csv',
    ['dictionary_id', 'local_term', 'english_term', 'category'],
    $dictRows,
    1
);

$synRows = [
    ['ga suka', 'vomiting', 'vomiting', 'active'],
    ['ga suka ko', 'vomiting', 'vomiting', 'active'],
    ['gasuka ko', 'vomiting', 'vomiting', 'active'],
    ['galupot', 'diarrhea', 'diarrhea', 'active'],
    ['galupot tiyan', 'diarrhea', 'diarrhea', 'active'],
    ['permi galupot', 'diarrhea', 'diarrhea', 'active'],
    ['ga lupot', 'diarrhea', 'diarrhea', 'active'],
    ['masakit gid ulo ko', 'severe headache', 'headache', 'active'],
    ['gasakit ulo ko', 'headache', 'headache', 'active'],
    ['nag gulpi', 'sudden onset', 'onset_sudden', 'active'],
    ['nagsugod gulpi', 'sudden onset', 'onset_sudden', 'active'],
    ['grabe ang pagsugod', 'sudden severe onset', 'onset_sudden', 'active'],
];
$stats['symptom_synonyms.csv'] = appendCsv(
    $root . '/symptom_synonyms.csv',
    ['hiligaynon_term', 'english_term', 'synonym_group', 'status'],
    $synRows,
    0
);

$negRows = [
    ['NEGX9001', 'wala ko difficulty breathing', 'difficulty breathing', 'mixed', 'active'],
    ['NEGX9002', 'wala ko budlay ginhawa', 'difficulty breathing', 'hiligaynon', 'active'],
    ['NEGX9003', 'wala budlay ginhawa', 'difficulty breathing', 'hiligaynon', 'active'],
    ['NEGX9004', 'indi ko budlay ginhawa', 'difficulty breathing', 'hiligaynon', 'active'],
    ['NEGX9005', 'wala ko hirap huminga', 'difficulty breathing', 'filipino', 'active'],
    ['NEGX9006', 'walang hirap huminga', 'difficulty breathing', 'filipino', 'active'],
    ['NEGX9007', 'no difficulty breathing', 'difficulty breathing', 'english', 'active'],
    ['NEGX9008', 'wala ga suka', 'vomiting', 'hiligaynon', 'active'],
    ['NEGX9009', 'wala gasuka', 'vomiting', 'hiligaynon', 'active'],
    ['NEGX9010', 'wala ko ga suka', 'vomiting', 'hiligaynon', 'active'],
    ['NEGX9011', 'wala ko galupot', 'diarrhea', 'hiligaynon', 'active'],
];
$stats['negation_words.csv'] = appendCsv(
    $root . '/negation_words.csv',
    ['rule_id', 'pattern', 'negated_concept', 'language', 'status'],
    $negRows,
    1
);

$misspellRows = [
    ['gasuka', 'ga suka', 'symptom_root', 'active'],
    ['galupot', 'galopot', 'symptom_root', 'active'],
    ['galupot', 'galuput', 'symptom_root', 'active'],
    ['galupot', 'ga lupot', 'symptom_root', 'active'],
    ['gulpi', 'gulpy', 'onset', 'active'],
    ['gulpi', 'golpi', 'onset', 'active'],
    ['kagapong', 'kagapon', 'duration', 'active'],
    ['masakit ulo', 'msk ulo', 'symptom_phrase', 'active'],
    ['difficulty breathing', 'difficuty breathing', 'clinical', 'active'],
];
$stats['medical_misspellings.csv'] = appendCsv(
    $root . '/medical_misspellings.csv',
    ['correct_term', 'misspelling', 'term_type', 'status'],
    $misspellRows,
    1
);

// Duration / severity conversational forms used in follow-ups
$durRows = [
    ['DURX9101', 'nag gulpi', 'acute_hours', '0', 'hiligaynon', 'active'],
    ['DURX9102', 'nagsugod gulpi', 'acute_hours', '0', 'hiligaynon', 'active'],
    ['DURX9103', 'gulpi ang pagsugod', 'acute_hours', '0', 'hiligaynon', 'active'],
    ['DURX9104', 'grabe ang pagsugod', 'acute_hours', '0', 'hiligaynon', 'active'],
    ['DURX9105', 'bigla lang', 'acute_hours', '0', 'filipino', 'active'],
    ['DURX9106', 'all of a sudden', 'acute_hours', '0', 'english', 'active'],
];
$stats['duration_patterns.csv'] = appendCsv(
    $root . '/duration_patterns.csv',
    ['pattern_id', 'pattern', 'bucket', 'days', 'language', 'status'],
    $durRows,
    1
);

// Soft-disable harmful reverse canonicalization that collapses valid phrases before matching.
// Keep the row for audit but mark inactive so MedicalMisspellingsLoader skips it if status-aware;
// also add protective identity mappings for the longer valid phrases.
$protect = [
    ['masakit ulo ko', 'masakit uloo ko', 'protect_variant', 'active'],
    ['ga suka ko', 'ga sukako', 'protect_variant', 'active'],
];
$stats['medical_misspellings_protect'] = appendCsv(
    $root . '/medical_misspellings.csv',
    ['correct_term', 'misspelling', 'term_type', 'status'],
    $protect,
    1
);

echo json_encode($stats, JSON_PRETTY_PRINT) . PHP_EOL;
