<?php
/**
 * Build data/nlp/faq_chatbot_translation_dictionary.json from project NLP corpora.
 * CLI: php scripts/data/build_faq_chatbot_dictionary.php
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

$outPath = BASE_PATH . '/data/nlp/faq_chatbot_translation_dictionary.json';
$entries = [];
$seen = [];

$add = static function (string $source, string $target, string $category, bool $phrase, int $priority = 10) use (&$entries, &$seen): void {
    $source = mb_strtolower(trim($source));
    $target = trim($target);
    if ($source === '' || $target === '') {
        return;
    }
    $key = $source . "\0" . $target;
    if (isset($seen[$key])) {
        return;
    }
    $seen[$key] = true;
    $entries[] = [
        'source'   => $source,
        'target'   => $target,
        'category' => $category,
        'phrase'   => $phrase,
        'priority' => $priority,
    ];
};

foreach (FaqChatbotDictionarySeed::entries() as $e) {
    $add((string) $e['source'], (string) $e['target'], (string) $e['category'], !empty($e['phrase']), (int) ($e['priority'] ?? 0));
}

$csvFiles = [
    [BASE_PATH . '/data/nlp/medical_dictionary.csv', 'local_term', 'english_term', 'condition', true, 8],
    [BASE_PATH . '/data/nlp/hiligaynon_nlp_expansion_2026.csv', 'local_term', 'english_term', 'symptom', true, 12],
    [BASE_PATH . '/data/nlp/patient_typing_dictionary_2026.csv', 'local_term', 'english_term', 'symptom', true, 6],
];

foreach ($csvFiles as [$path, $srcCol, $tgtCol, $cat, $phrase, $pri]) {
    if (!is_readable($path)) {
        continue;
    }
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        continue;
    }
    $header = fgetcsv($fh);
    if (!is_array($header)) {
        fclose($fh);
        continue;
    }
    $header = array_map('strtolower', array_map('trim', $header));
    $si = array_search($srcCol, $header, true);
    $ti = array_search($tgtCol, $header, true);
    if ($si === false || $ti === false) {
        fclose($fh);
        continue;
    }
    while (($row = fgetcsv($fh)) !== false) {
        $add((string) ($row[$si] ?? ''), (string) ($row[$ti] ?? ''), $cat, $phrase, $pri);
    }
    fclose($fh);
}

$lexPath = BASE_PATH . '/data/nlp/hiligaynon_symptom_lexicon.json';
if (is_readable($lexPath)) {
    $lex = json_decode((string) file_get_contents($lexPath), true);
    if (is_array($lex)) {
        foreach ($lex['symptoms'] ?? [] as $key => $block) {
            if (!is_array($block)) {
                continue;
            }
            $en = (string) ($block['english'] ?? $block['medical_term'] ?? $key);
            foreach ($block['hiligaynon'] ?? [] as $term) {
                $add((string) $term, $en, 'symptom', mb_strlen((string) $term) > 12, 7);
            }
        }
    }
}

usort($entries, static fn ($a, $b) => ($b['priority'] <=> $a['priority']) ?: strcmp($a['source'], $b['source']));

$json = json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
if ($json === false) {
    fwrite(STDERR, "JSON encode failed\n");
    exit(1);
}

if (!is_dir(dirname($outPath))) {
    mkdir(dirname($outPath), 0755, true);
}
file_put_contents($outPath, $json);
echo 'Wrote ' . count($entries) . ' entries to ' . $outPath . "\n";
