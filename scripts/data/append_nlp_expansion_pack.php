<?php
/**
 * Append-only NLP knowledge expansion pack (does NOT overwrite existing rows).
 *
 * Adds curated multilingual variants into existing CSVs + new expansion files
 * that existing loaders merge. No architecture replacement. No triage invention.
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
    $header = fgetcsv($h);
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

// --- Duration conversational expansions ---
$durRows = [];
$durId = 9000;
$durations = [
    ['pila na ka adlaw', '5_plus_days', '5', 'hiligaynon'],
    ['tatlo na ka adlaw', '3_to_4_days', '3', 'hiligaynon'],
    ['duha na ka adlaw', '1_to_2_days', '2', 'hiligaynon'],
    ['isa na ka adlaw', '1_to_2_days', '1', 'hiligaynon'],
    ['duha na ka semana', 'chronic_weeks', '14', 'hiligaynon'],
    ['tatlo na ka semana', 'chronic_weeks', '21', 'hiligaynon'],
    ['isa na ka semana', 'chronic_weeks', '7', 'hiligaynon'],
    ['isa na ka bulan', 'chronic_months', '30', 'hiligaynon'],
    ['duha na ka bulan', 'chronic_months', '60', 'hiligaynon'],
    ['tatlo na ka bulan', 'chronic_months', '90', 'hiligaynon'],
    ['upat na ka bulan', 'chronic_months', '120', 'hiligaynon'],
    ['lima na ka bulan', 'chronic_months', '150', 'hiligaynon'],
    ['halos isa ka bulan', 'chronic_months', '30', 'hiligaynon'],
    ['mga tatlo ka bulan', 'chronic_months', '90', 'hiligaynon'],
    ['for three weeks', 'chronic_weeks', '21', 'english'],
    ['for 3 weeks', 'chronic_weeks', '21', 'english'],
    ['three weeks', 'chronic_weeks', '21', 'english'],
    ['for one month', 'chronic_months', '30', 'english'],
    ['for 1 month', 'chronic_months', '30', 'english'],
    ['for three months', 'chronic_months', '90', 'english'],
    ['for 3 months', 'chronic_months', '90', 'english'],
    ['several months', 'chronic_months', '90', 'english'],
    ['several weeks', 'chronic_weeks', '21', 'english'],
    ['several days', '5_plus_days', '5', 'english'],
    ['ilang araw na', '5_plus_days', '5', 'filipino'],
    ['tatlong araw na', '3_to_4_days', '3', 'filipino'],
    ['dalawang araw na', '1_to_2_days', '2', 'filipino'],
    ['isang linggo na', 'chronic_weeks', '7', 'filipino'],
    ['dalawang linggo na', 'chronic_weeks', '14', 'filipino'],
    ['tatlong linggo na', 'chronic_weeks', '21', 'filipino'],
    ['isang buwan na', 'chronic_months', '30', 'filipino'],
    ['dalawang buwan na', 'chronic_months', '60', 'filipino'],
    ['tatlong buwan na', 'chronic_months', '90', 'filipino'],
    ['mga tatlong buwan', 'chronic_months', '90', 'filipino'],
    ['since this morning', 'acute_hours', '0', 'english'],
    ['kangina pa', 'acute_hours', '0', 'hiligaynon'],
    ['kagapon pa', '1_to_2_days', '1', 'hiligaynon'],
    ['gahapon pa', '1_to_2_days', '1', 'hiligaynon'],
    ['kahapon pa', '1_to_2_days', '1', 'filipino'],
    ['since last week', 'chronic_weeks', '7', 'english'],
    ['since last month', 'chronic_months', '30', 'english'],
];
foreach ($durations as [$pat, $bucket, $days, $lang]) {
    $durId++;
    $durRows[] = [sprintf('DURX%04d', $durId), $pat, $bucket, $days, $lang, 'active'];
}
$stats['duration_patterns.csv'] = appendCsv(
    $root . '/duration_patterns.csv',
    ['pattern_id', 'pattern', 'bucket', 'days', 'language', 'status'],
    $durRows,
    1
);

// --- Pain / severity phrase expansions (severity alone ≠ triage) ---
$painRows = [
    ['masakit gid', '8', 'severe', '4', 'hiligaynon', 'active'],
    ['grabe kasakit', '9', 'severe', '4', 'hiligaynon', 'active'],
    ['grabe gid sakit', '9', 'severe', '4', 'hiligaynon', 'active'],
    ['indi na maagwanta', '10', 'severe', '4', 'hiligaynon', 'active'],
    ['indi ko na maagwanta', '10', 'severe', '4', 'hiligaynon', 'active'],
    ['daw kaya pa', '3', 'mild', '0', 'hiligaynon', 'active'],
    ['gamay lang', '2', 'mild', '0', 'hiligaynon', 'active'],
    ['gamay lang sakit', '2', 'mild', '0', 'hiligaynon', 'active'],
    ['medyo masakit', '5', 'moderate', '2', 'filipino', 'active'],
    ['masakit na masakit', '8', 'severe', '4', 'filipino', 'active'],
    ['sobrang sakit', '9', 'severe', '4', 'filipino', 'active'],
    ['hindi na matiis', '10', 'severe', '4', 'filipino', 'active'],
    ['very painful', '8', 'severe', '4', 'english', 'active'],
    ['extremely painful', '9', 'severe', '4', 'english', 'active'],
    ['worst pain', '10', 'severe', '4', 'english', 'active'],
    ['pain is mild', '2', 'mild', '0', 'english', 'active'],
    ['pain is moderate', '5', 'moderate', '2', 'english', 'active'],
    ['pain is severe', '8', 'severe', '4', 'english', 'active'],
    ['kaya pa', '3', 'mild', '0', 'mixed', 'active'],
    ['tolerable pain', '4', 'moderate', '2', 'english', 'active'],
];
$stats['pain_scale.csv'] = appendCsv(
    $root . '/pain_scale.csv',
    ['pattern', 'pain_score', 'band', 'severity_points', 'language', 'status'],
    $painRows,
    0
);

// --- Negation expansions ---
$negId = 8000;
$negPatterns = [
    ['wala ko hilanat', 'fever', 'hiligaynon'],
    ['wala ako hilanat', 'fever', 'hiligaynon'],
    ['indi ko may hilanat', 'fever', 'hiligaynon'],
    ['wala suka', 'vomiting', 'hiligaynon'],
    ['wala ko suka', 'vomiting', 'hiligaynon'],
    ['indi ko gasuka', 'vomiting', 'hiligaynon'],
    ['indi ko ginaubo', 'cough', 'hiligaynon'],
    ['wala ko ubo', 'cough', 'hiligaynon'],
    ['wala dugo', 'bleeding', 'hiligaynon'],
    ['wala ko dugo', 'bleeding', 'hiligaynon'],
    ['indi nalipong', 'dizziness', 'hiligaynon'],
    ['wala ko nalipong', 'dizziness', 'hiligaynon'],
    ['indi budlay ginhawa', 'difficulty breathing', 'hiligaynon'],
    ['wala akong lagnat', 'fever', 'filipino'],
    ['walang lagnat', 'fever', 'filipino'],
    ['hindi ako nilalagnat', 'fever', 'filipino'],
    ['walang ubo', 'cough', 'filipino'],
    ['hindi ako umuubo', 'cough', 'filipino'],
    ['walang suka', 'vomiting', 'filipino'],
    ['hindi nagsusuka', 'vomiting', 'filipino'],
    ['walang hilo', 'dizziness', 'filipino'],
    ['no vomiting', 'vomiting', 'english'],
    ['without cough', 'cough', 'english'],
    ['denies dizziness', 'dizziness', 'english'],
    ['denies vomiting', 'vomiting', 'english'],
    ['no shortness of breath', 'difficulty breathing', 'english'],
    ['without shortness of breath', 'difficulty breathing', 'english'],
    ['no bleeding', 'bleeding', 'english'],
    ['wala iban', 'associated symptoms', 'hiligaynon'],
    ['wala sang iban', 'associated symptoms', 'hiligaynon'],
    ['no other symptoms', 'associated symptoms', 'english'],
    ['walang ibang sintomas', 'associated symptoms', 'filipino'],
];
$negRows = [];
foreach ($negPatterns as [$pat, $concept, $lang]) {
    $negId++;
    $negRows[] = [sprintf('NEGX%04d', $negId), $pat, $concept, $lang, 'active'];
}
$stats['negation_words.csv'] = appendCsv(
    $root . '/negation_words.csv',
    ['rule_id', 'pattern', 'negated_concept', 'language', 'status'],
    $negRows,
    1
);

// --- Body part Tagalog / colloquial / misspell aliases (local term column) ---
$bodyRows = [
    ['dibdib', 'chest', 'cardiovascular', 'cardiovascular', 'active'],
    ['dibdib ko', 'chest', 'cardiovascular', 'cardiovascular', 'active'],
    ['sa dibdib', 'chest', 'cardiovascular', 'cardiovascular', 'active'],
    ['utok', 'head', 'neurological', 'head', 'active'],
    ['ulo ko', 'head', 'neurological', 'head', 'active'],
    ['olo', 'head', 'neurological', 'head', 'active'],
    ['tenga', 'ear', 'ent', 'ent', 'active'],
    ['tenga ko', 'ear', 'ent', 'ent', 'active'],
    ['ear', 'ear', 'ent', 'ent', 'active'],
    ['nose', 'nose', 'ent', 'ent', 'active'],
    ['leeg', 'neck', 'musculoskeletal', 'musculoskeletal', 'active'],
    ['leeg ko', 'neck', 'musculoskeletal', 'musculoskeletal', 'active'],
    ['braso', 'arm', 'musculoskeletal', 'musculoskeletal', 'active'],
    ['braso ko', 'arm', 'musculoskeletal', 'musculoskeletal', 'active'],
    ['arm', 'arm', 'musculoskeletal', 'musculoskeletal', 'active'],
    ['paa', 'leg', 'musculoskeletal', 'musculoskeletal', 'active'],
    ['paa ko', 'leg', 'musculoskeletal', 'musculoskeletal', 'active'],
    ['leg', 'leg', 'musculoskeletal', 'musculoskeletal', 'active'],
    ['legs', 'leg', 'musculoskeletal', 'musculoskeletal', 'active'],
    ['pusod', 'abdomen', 'gastrointestinal', 'gastrointestinal', 'active'],
    ['tiyanan', 'abdomen', 'gastrointestinal', 'gastrointestinal', 'active'],
    ['tyan', 'abdomen', 'gastrointestinal', 'gastrointestinal', 'active'],
    ['tian', 'abdomen', 'gastrointestinal', 'gastrointestinal', 'active'],
    ['belly', 'abdomen', 'gastrointestinal', 'gastrointestinal', 'active'],
    ['stomach', 'abdomen', 'gastrointestinal', 'gastrointestinal', 'active'],
    ['back', 'back', 'musculoskeletal', 'musculoskeletal', 'active'],
    ['likod', 'back', 'musculoskeletal', 'musculoskeletal', 'active'],
    ['likod ko', 'back', 'musculoskeletal', 'musculoskeletal', 'active'],
    ['dug-an', 'chest', 'cardiovascular', 'cardiovascular', 'active'],
    ['dughan ko', 'chest', 'cardiovascular', 'cardiovascular', 'active'],
    ['hand', 'hand', 'musculoskeletal', 'musculoskeletal', 'active'],
    ['foot', 'foot', 'musculoskeletal', 'musculoskeletal', 'active'],
    ['eye', 'eye', 'ophthalmologic', 'eye', 'active'],
    ['eyes', 'eye', 'ophthalmologic', 'eye', 'active'],
    ['mata ko', 'eye', 'ophthalmologic', 'eye', 'active'],
    ['right lower abdomen', 'abdomen', 'gastrointestinal', 'gastrointestinal', 'active'],
    ['tuo nga idalom tiyan', 'abdomen', 'gastrointestinal', 'gastrointestinal', 'active'],
    ['kaliwa nga idalom tiyan', 'abdomen', 'gastrointestinal', 'gastrointestinal', 'active'],
];
$stats['body_parts.csv'] = appendCsv(
    $root . '/body_parts.csv',
    ['hiligaynon_term', 'english_term', 'body_system', 'anatomy_category', 'status'],
    $bodyRows,
    0
);
$stats['body_parts_cds.csv'] = appendCsv(
    $root . '/body_parts_cds.csv',
    ['hiligaynon_term', 'english_term', 'body_system', 'anatomy_category', 'status'],
    $bodyRows,
    0
);

// --- Symptom expression variants (NEW file; also mirrored into aliases) ---
$concepts = [
    'headache' => [
        'sakit ulo', 'headache', 'head pain', 'kasakit ulo', 'sakit sang ulo', 'ga sakit ulo',
        'ulo ko masakit', 'masakit ulo', 'masakit ulo ko', 'masakit ang ulo ko', 'sakit gid akon ulo',
        'ga sakit akon ulo', 'nagakasakit ulo ko', 'kasakit ulo ko', 'sumasakit ang ulo ko',
        'bug-at ulo', 'daw ginapislit ulo', 'daw nagapukpok ang ulo', 'headech', 'msk ulo', 'saket olo',
        'my head hurts', 'pain in my head', 'sakit ng ulo',
    ],
    'chest_pain' => [
        'masakit dughan', 'chest pain', 'sakit dughan', 'masakit dughan ko', 'sakit sa dibdib',
        'masakit dibdib', 'masakit ang dibdib ko', 'hapdi dughan', 'msk dughan', 'msk dibdib',
        'pain in chest', 'my chest hurts', 'sumasakit ang dibdib',
    ],
    'abdominal_pain' => [
        'masakit tiyan', 'abdominal pain', 'stomach pain', 'sakit tiyan', 'kasakit tiyan',
        'msk tiyan', 'saket tyan', 'tiyan ko masakit', 'ga sakit tiyan', 'belly pain',
        'masakit ang tiyan ko', 'sakit sa tiyan',
    ],
    'difficulty_breathing' => [
        'budlay ginhawa', 'difficulty breathing', 'shortness of breath', 'cannot breathe', "can't breathe",
        'budlay gid ginhawa', 'indi ko makaginhawa', 'di ako makahinga',
        'hirap huminga', 'hirap na ako huminga', 'sob', 'kapos ginhawa',
    ],
    'cough' => [
        'ubo', 'cough', 'ginaubo', 'nagauubo', 'umuubo', 'cought', 'couph', 'may ubo ako',
    ],
    'fever' => [
        'hilanat', 'fever', 'lagnat', 'nilalagnat', 'may lagnat', 'may hilanat', 'lgnt', 'pyrexia',
    ],
    'dizziness' => [
        'nalipong', 'dizziness', 'dizzy', 'malipong', 'nahilo', 'nahihilo', 'lingin', 'hilo',
    ],
    'vomiting' => [
        'suka', 'vomiting', 'vomit', 'gasuka', 'nagsusuka', 'pagsuka', 'throwing up',
    ],
    'diarrhea' => [
        'libang', 'diarrhea', 'diarrhoea', 'nagtatae', 'nagdudumi', 'kalibanga', 'loose stool',
    ],
    'eye_pain' => [
        'sakit mata', 'eye pain', 'masakit mata', 'kasakit mata', 'mata ko masakit', 'sakit sa mata',
    ],
    'bleeding' => [
        'nagadugo', 'bleeding', 'nagdugo', 'dumudugo', 'may dugo', 'gadugo',
    ],
    'weakness' => [
        'kaluya', 'weakness', 'nangaluya', 'nanlalata', 'weak', 'panghihina',
    ],
    'burn' => [
        'nasunog', 'burn', 'burns', 'napaso', 'nasunugan', 'paso',
    ],
];

$variantRows = [];
$aliasRows = [];
$misspellRows = [];
$vid = 0;
foreach ($concepts as $conceptId => $variants) {
    $canonicalName = ucwords(str_replace('_', ' ', $conceptId));
    $primary = $variants[0];
    foreach ($variants as $v) {
        $vid++;
        $variantRows[] = [
            sprintf('SEV%04d', $vid),
            $v,
            $primary,
            $conceptId,
            $canonicalName,
            'mixed',
            'active',
        ];
        $aliasRows[] = [$v, $conceptId, $canonicalName, 'mixed', 'active'];
        if (mb_strtolower($v) !== mb_strtolower($primary) && !str_contains($v, ' ')) {
            $misspellRows[] = [$primary, $v, 'expression_variant', 'active'];
        }
    }
}

$stats['symptom_expression_variants.csv'] = appendCsv(
    $root . '/symptom_expression_variants.csv',
    ['variant_id', 'variant', 'canonical_phrase', 'concept_id', 'canonical_name', 'language', 'status'],
    $variantRows,
    1
);
$stats['canonical_symptom_aliases.csv'] = appendCsv(
    $root . '/canonical_symptom_aliases.csv',
    ['alias', 'canonical_concept_id', 'canonical_name', 'language', 'status'],
    $aliasRows,
    0
);

// Multi-word phrase expansions into misspellings (acts as phrase normalizer)
$phraseNorm = [];
foreach ($concepts as $conceptId => $variants) {
    $primary = $variants[0];
    foreach ($variants as $v) {
        if ($v === $primary) {
            continue;
        }
        // Map variant phrase → a clinically useful English/local canonical for matching
        $target = match ($conceptId) {
            'headache' => 'sakit ulo',
            'chest_pain' => 'masakit dughan',
            'abdominal_pain' => 'masakit tiyan',
            'difficulty_breathing' => 'budlay ginhawa',
            'eye_pain' => 'sakit mata',
            default => $primary,
        };
        if (mb_strtolower($v) !== mb_strtolower($target)) {
            $phraseNorm[] = [$target, $v, 'expression_variant', 'active'];
        }
    }
}
$stats['medical_misspellings.csv'] = appendCsv(
    $root . '/medical_misspellings.csv',
    ['correct_term', 'misspelling', 'term_type', 'status'],
    array_merge($misspellRows, $phraseNorm),
    1
);

// --- Associated symptom hints (NOT diagnoses) ---
$assoc = [
    ['headache', 'dizziness', 'common_associated', 'active'],
    ['headache', 'vomiting', 'common_associated', 'active'],
    ['headache', 'vision changes', 'common_associated', 'active'],
    ['headache', 'weakness', 'common_associated', 'active'],
    ['headache', 'confusion', 'common_associated', 'active'],
    ['headache', 'fever', 'common_associated', 'active'],
    ['abdominal_pain', 'vomiting', 'common_associated', 'active'],
    ['abdominal_pain', 'diarrhea', 'common_associated', 'active'],
    ['abdominal_pain', 'fever', 'common_associated', 'active'],
    ['abdominal_pain', 'bleeding', 'common_associated', 'active'],
    ['abdominal_pain', 'urinary symptoms', 'common_associated', 'active'],
    ['cough', 'fever', 'common_associated', 'active'],
    ['cough', 'phlegm', 'common_associated', 'active'],
    ['cough', 'difficulty breathing', 'common_associated', 'active'],
    ['cough', 'chest pain', 'common_associated', 'active'],
    ['eye_pain', 'redness', 'common_associated', 'active'],
    ['eye_pain', 'blurred vision', 'common_associated', 'active'],
    ['eye_pain', 'discharge', 'common_associated', 'active'],
    ['eye_pain', 'swelling', 'common_associated', 'active'],
    ['chest_pain', 'difficulty breathing', 'common_associated', 'active'],
    ['chest_pain', 'sweating', 'common_associated', 'active'],
    ['dizziness', 'weakness', 'common_associated', 'active'],
    ['dizziness', 'vomiting', 'common_associated', 'active'],
    ['fever', 'cough', 'common_associated', 'active'],
    ['fever', 'headache', 'common_associated', 'active'],
];
$assocRows = [];
$aid = 0;
foreach ($assoc as [$primary, $associated, $rel, $status]) {
    $aid++;
    $assocRows[] = [sprintf('ASH%04d', $aid), $primary, $associated, $rel, $status];
}
$stats['associated_symptom_hints.csv'] = appendCsv(
    $root . '/associated_symptom_hints.csv',
    ['hint_id', 'primary_concept', 'associated_concept', 'relation', 'status'],
    $assocRows,
    0
);

// --- Domain gate labeled examples (training/reference; detector stays rule-based) ---
$domainRows = [
    ['tatlo na ka bulan kasakit mata ko', 'HEALTH_RELATED', 'duration+symptom+body', 'hiligaynon', 'active'],
    ['kasakit ulo ko', 'HEALTH_RELATED', 'symptom+body', 'hiligaynon', 'active'],
    ['masakit tiyan ko, ga suka kag ga diarrhea', 'HEALTH_RELATED', 'multi_symptom', 'hiligaynon', 'active'],
    ['ubo kag hilanat ko', 'HEALTH_RELATED', 'multi_symptom', 'hiligaynon', 'active'],
    ['my head hurts since yesterday', 'HEALTH_RELATED', 'english', 'english', 'active'],
    ['sumasakit ang ulo ko', 'HEALTH_RELATED', 'tagalog', 'filipino', 'active'],
    ['what is the weather', 'OUT_OF_SCOPE', 'unrelated', 'english', 'active'],
    ['tell me a joke', 'OUT_OF_SCOPE', 'unrelated', 'english', 'active'],
    ['who won the game', 'OUT_OF_SCOPE', 'unrelated', 'english', 'active'],
    ['good morning', 'GREETING', 'greeting', 'english', 'active'],
    ['asdfghjkl qwerty', 'OUT_OF_SCOPE', 'nonsense', 'english', 'active'],
    ['sakitgbgjgbvd', 'OUT_OF_SCOPE', 'nonsense', 'mixed', 'active'],
    ['how do i book an appointment', 'MEDCONNECT_RELATED', 'platform', 'english', 'active'],
    ['wala ko hilanat', 'HEALTH_RELATED', 'negation_still_clinical', 'hiligaynon', 'active'],
];
$stats['domain_gate_examples.csv'] = appendCsv(
    $root . '/domain_gate_examples.csv',
    ['example', 'expected_domain', 'note', 'language', 'status'],
    $domainRows,
    0
);

// --- Multi-complaint examples ---
$multiRows = [
    ['kasakit ulo ko kag tiyan ko', 'headache|abdominal_pain', 'hiligaynon', 'active'],
    ['masakit ulo ko kag daw nalipong ko', 'headache|dizziness', 'hiligaynon', 'active'],
    ['ubo kag hilanat ko', 'cough|fever', 'hiligaynon', 'active'],
    ['masakit tiyan ko, ga suka kag ga diarrhea', 'abdominal_pain|vomiting|diarrhea', 'hiligaynon', 'active'],
    ['headache and fever', 'headache|fever', 'english', 'active'],
    ['cough with shortness of breath', 'cough|difficulty_breathing', 'english', 'active'],
    ['sumasakit ang ulo at tiyan ko', 'headache|abdominal_pain', 'filipino', 'active'],
];
$stats['multi_complaint_examples.csv'] = appendCsv(
    $root . '/multi_complaint_examples.csv',
    ['example', 'expected_concepts', 'language', 'status'],
    $multiRows,
    0
);

// --- Abbreviations ---
$abbr = [
    ['n&v', 'nausea and vomiting', 'active'],
    ['doe', 'dyspnea on exertion', 'active'],
    ['loc', 'loss of consciousness', 'active'],
    ['ams', 'altered mental status', 'active'],
    ['ha', 'headache', 'active'],
    ['abd', 'abdomen', 'active'],
    ['c/o', 'complains of', 'active'],
    ['pmh', 'past medical history', 'active'],
    ['nkda', 'no known drug allergies', 'active'],
    ['rom', 'range of motion', 'active'],
];
$stats['medical_abbreviations.csv'] = appendCsv(
    $root . '/medical_abbreviations.csv',
    ['abbreviation', 'expansion', 'status'],
    $abbr,
    0
);

// --- Patient typing dictionary ---
$pt = [];
$pid = 100;
$typing = [
    ['kasakit ulo ko', 'headache', 'symptom'],
    ['sakit gid akon ulo', 'headache', 'symptom'],
    ['ga sakit akon ulo', 'headache', 'symptom'],
    ['nagakasakit ulo ko', 'headache', 'symptom'],
    ['bug-at ulo', 'headache', 'symptom'],
    ['daw ginapislit ulo', 'headache', 'symptom'],
    ['tatlo na ka bulan kasakit mata ko', 'eye pain for three months', 'symptom'],
    ['kasakit ulo ko kag tiyan ko', 'headache and abdominal pain', 'multi'],
    ['ubo kag hilanat ko', 'cough and fever', 'multi'],
    ['wala ko hilanat', 'no fever', 'negation'],
    ['indi ko ginaubo', 'no cough', 'negation'],
    ['indi na maagwanta', 'unbearable pain', 'severity'],
    ['pila na ka adlaw', 'several days', 'timing'],
    ['tatlo na ka bulan', 'three months', 'timing'],
    ['duha na ka semana', 'two weeks', 'timing'],
];
foreach ($typing as [$local, $en, $cat]) {
    $pid++;
    $pt[] = [sprintf('PT%03d', $pid), $local, $en, $cat];
}
$stats['patient_typing_dictionary_2026.csv'] = appendCsv(
    $root . '/patient_typing_dictionary_2026.csv',
    ['dictionary_id', 'local_term', 'english_term', 'category'],
    $pt,
    1
);

echo "=== APPEND-ONLY EXPANSION RESULTS ===\n";
$total = 0;
foreach ($stats as $file => $n) {
    echo sprintf("%-42s +%d\n", $file, $n);
    $total += $n;
}
echo "TOTAL NEW ROWS: {$total}\n";
