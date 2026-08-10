<?php
/**
 * Build unified chatbot scenario index (20,000+ intent variations).
 * Architecture: phrase variation → shared intent → kb_key → response template lookup.
 *
 * CLI: php scripts/data/build_chatbot_scenario_index.php
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';

$outPath = BASE_PATH . '/data/nlp/chatbot_scenario_index.json';
$metaPath = BASE_PATH . '/data/nlp/chatbot_scenario_index_meta.json';
$targetMin = 20_000;

/** @var array<string, true> */
$seen = [];
/** @var list<array<string, mixed>> */
$scenarios = [];

$add = static function (
    string $phrase,
    string $intent,
    string $category,
    string $kbKey,
    string $language = 'en',
    ?string $emotion = null,
    array $keywords = [],
    int $priority = 5
) use (&$seen, &$scenarios): void {
    $phrase = trim($phrase);
    if ($phrase === '' || mb_strlen($phrase) < 2 || mb_strlen($phrase) > 240) {
        return;
    }
    $norm = mb_strtolower(preg_replace('/\s+/u', ' ', $phrase) ?? $phrase, 'UTF-8');
    if (isset($seen[$norm])) {
        $existingIdx = $seen[$norm];
        $existingPri = (int) ($scenarios[$existingIdx]['priority'] ?? 0);
        if ($priority <= $existingPri) {
            return;
        }
        $scenarios[$existingIdx] = [
            'phrase'     => $phrase,
            'intent'     => $intent,
            'category'   => $category,
            'kb_key'     => $kbKey,
            'language'   => $language,
            'emotion'    => $emotion,
            'keywords'   => $keywords !== [] ? $keywords : tokenize($norm),
            'priority'   => $priority,
            'confidence' => 0.55,
        ];
        return;
    }
    $seen[$norm] = count($scenarios);
    $scenarios[] = [
        'phrase'     => $phrase,
        'intent'     => $intent,
        'category'   => $category,
        'kb_key'     => $kbKey,
        'language'   => $language,
        'emotion'    => $emotion,
        'keywords'   => $keywords !== [] ? $keywords : tokenize($norm),
        'priority'   => $priority,
        'confidence' => 0.55,
    ];
};

function tokenize(string $text): array
{
    $text = preg_replace('/[^a-z0-9\s]/u', ' ', mb_strtolower($text, 'UTF-8')) ?? '';
    $parts = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return array_values(array_unique(array_filter($parts, static fn ($w) => mb_strlen($w) >= 3)));
}

/** @return array{intent: string, category: string, kb_key: string, emotion: ?string} */
function mapEmotion(string $emotion): array
{
    static $map = [
        'emergency' => ['intent' => 'emergency', 'category' => 'emergency', 'kb_key' => 'emergency_redirect', 'emotion' => 'urgent'],
        'panic' => ['intent' => 'emotional_support', 'category' => 'emotional_support', 'kb_key' => 'panic_support', 'emotion' => 'panicking'],
        'hopeless' => ['intent' => 'emotional_support', 'category' => 'crisis', 'kb_key' => 'crisis_hopeless', 'emotion' => 'hopeless'],
        'afraid' => ['intent' => 'emotional_support', 'category' => 'emotional_support', 'kb_key' => 'fear_support', 'emotion' => 'scared'],
        'angry' => ['intent' => 'emotional_support', 'category' => 'emotional_support', 'kb_key' => 'stress_support', 'emotion' => 'angry'],
        'frustrated' => ['intent' => 'emotional_support', 'category' => 'emotional_support', 'kb_key' => 'stress_support', 'emotion' => 'frustrated'],
        'anxious' => ['intent' => 'emotional_support', 'category' => 'emotional_support', 'kb_key' => 'anxiety_support', 'emotion' => 'anxious'],
        'nervous' => ['intent' => 'emotional_support', 'category' => 'emotional_support', 'kb_key' => 'anxiety_support', 'emotion' => 'anxious'],
        'worried' => ['intent' => 'emotional_support', 'category' => 'emotional_support', 'kb_key' => 'worry_symptoms', 'emotion' => 'worried'],
        'stressed' => ['intent' => 'emotional_support', 'category' => 'emotional_support', 'kb_key' => 'stress_support', 'emotion' => 'stressed'],
        'sad' => ['intent' => 'emotional_support', 'category' => 'emotional_support', 'kb_key' => 'depression_support', 'emotion' => 'sad'],
        'lonely' => ['intent' => 'emotional_support', 'category' => 'emotional_support', 'kb_key' => 'loneliness_no_one', 'emotion' => 'lonely'],
        'confused' => ['intent' => 'clarification', 'category' => 'system_help', 'kb_key' => 'navigation_help', 'emotion' => 'confused'],
        'uncertain' => ['intent' => 'clarification', 'category' => 'system_help', 'kb_key' => 'navigation_help', 'emotion' => 'confused'],
        'thankful' => ['intent' => 'thanks', 'category' => 'general', 'kb_key' => 'thank_you', 'emotion' => 'thankful'],
        'happy' => ['intent' => 'small_talk', 'category' => 'general', 'kb_key' => 'small_talk', 'emotion' => 'happy'],
        'relieved' => ['intent' => 'small_talk', 'category' => 'general', 'kb_key' => 'small_talk', 'emotion' => 'relieved'],
        'curious' => ['intent' => 'capabilities', 'category' => 'system_help', 'kb_key' => 'capabilities', 'emotion' => 'neutral'],
    ];
    return $map[$emotion] ?? [
        'intent' => 'emotional_support',
        'category' => 'emotional_support',
        'kb_key' => 'stress_support',
        'emotion' => $emotion,
    ];
}

// ── 1. Emotion / intent phrase corpora ──
$emotionCsvs = [
    BASE_PATH . '/data/nlp/emotion_intent_phrases_full.csv',
    BASE_PATH . '/data/nlp/emotion_intent_phrases.csv',
    BASE_PATH . '/data/nlp/emotion_intent_phrases_hil_expansion.csv',
    BASE_PATH . '/data/nlp/emotion_hiligaynon_extra_phrases.csv',
    BASE_PATH . '/data/nlp/emotion_situations_phrases.csv',
];
foreach ($emotionCsvs as $path) {
    if (!is_readable($path)) {
        continue;
    }
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        continue;
    }
    $header = fgetcsv($fh);
    if ($header === false) {
        fclose($fh);
        continue;
    }
    $cols = array_flip(array_map('strtolower', $header));
    while (($row = fgetcsv($fh)) !== false) {
        $emotion = trim((string) ($row[$cols['emotion'] ?? 0] ?? ''));
        $phrase = trim((string) ($row[$cols['phrase'] ?? 1] ?? ''));
        $lang = strtolower(trim((string) ($row[$cols['language'] ?? 2] ?? 'en')));
        if ($emotion === '' || $phrase === '') {
            continue;
        }
        $m = mapEmotion($emotion);
        $add($phrase, $m['intent'], $m['category'], $m['kb_key'], $lang === 'hil' ? 'hil' : ($lang === 'fil' ? 'fil' : 'en'), $m['emotion'], [], 6);
    }
    fclose($fh);
}

// ── 2. Translation / medical phrase corpora ──
$translationSources = [
    [BASE_PATH . '/data/nlp/faq_chatbot_translation_dictionary.json', 'json_dict'],
    [BASE_PATH . '/data/nlp/translation_dictionary.csv', 'csv_dict', 'local_term', 'english_term'],
    [BASE_PATH . '/data/nlp/hiligaynon_nlp_expansion_2026.csv', 'csv_dict', 'local_term', 'english_term'],
    [BASE_PATH . '/data/nlp/patient_typing_dictionary_2026.csv', 'csv_dict', 'local_term', 'english_term'],
    [BASE_PATH . '/data/nlp/medical_synonyms.csv', 'csv_dict', 'term', 'synonym'],
];
foreach ($translationSources as $spec) {
    $path = $spec[0];
    if (!is_readable($path)) {
        continue;
    }
    if (($spec[1] ?? '') === 'json_dict') {
        $raw = json_decode((string) file_get_contents($path), true);
        if (!is_array($raw)) {
            continue;
        }
        $rows = $raw['entries'] ?? $raw;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $source = trim((string) ($row['source'] ?? ''));
            $target = trim((string) ($row['target'] ?? ''));
            $cat = (string) ($row['category'] ?? 'symptom');
            if ($source === '') {
                continue;
            }
            $kb = str_contains($cat, 'symptom') || str_contains($cat, 'pain') ? 'symptoms_general' : 'health_education';
            $intent = $kb === 'symptoms_general' ? 'symptoms' : 'health_advice';
            $lang = preg_match('/\b(ko|sang|gid|indi|wala)\b/ui', $source) ? 'hil' : 'en';
            $add($source, $intent, 'health', $kb, $lang, null, tokenize($target), 4);
            if ($target !== '' && $target !== $source) {
                $add($target, $intent, 'health', $kb, 'en', null, tokenize($source), 4);
            }
        }
        continue;
    }
    $srcCol = $spec[2] ?? 'local_term';
    $tgtCol = $spec[3] ?? 'english_term';
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        continue;
    }
    $header = fgetcsv($fh);
    $cols = array_flip(array_map('strtolower', $header ?: []));
    while (($row = fgetcsv($fh)) !== false) {
        $source = trim((string) ($row[$cols[$srcCol] ?? 0] ?? ''));
        $target = trim((string) ($row[$cols[$tgtCol] ?? 1] ?? ''));
        if ($source === '') {
            continue;
        }
        $lang = preg_match('/\b(ko|sang|gid|indi|wala)\b/ui', $source) ? 'hil' : 'en';
        $add($source, 'symptoms', 'health', 'symptoms_general', $lang, null, tokenize($target), 4);
    }
    fclose($fh);
}

// ── 3. Structured intent templates (account, appointment, financial, etc.) ──
$intentTemplates = [
    'financial' => [
        'kb_key' => 'financial_barrier',
        'category' => 'financial',
        'phrases' => [
            ['i have no money', 'en'], ['i dont have money', 'en'], ['no money for doctor', 'en'],
            ['cannot afford consultation', 'en'], ['cant afford checkup', 'en'], ['too expensive', 'en'],
            ['wala ko kwarta', 'hil'], ['wala ako kwarta', 'fil'], ['walang pera ako', 'fil'],
            ['wala budget', 'hil'], ['indi ko kaya magbayad', 'hil'], ['libre nga consultation', 'hil'],
            ['kung wala kwarta paano ko', 'hil'], ['no budget for checkup', 'en'],
            ['im broke', 'en'], ['broke no money', 'en'], ['poor cant pay', 'en'],
        ],
    ],
    'appointment' => [
        'kb_key' => 'book_appointment',
        'category' => 'appointments',
        'phrases' => [
            ['how can i book', 'en'], ['how to book appointment', 'en'], ['i need a doctor', 'en'],
            ['book appointment', 'en'], ['schedule consultation', 'en'], ['my appointment is tomorrow', 'en'],
            ['can i cancel my appointment', 'en'], ['reschedule my appointment', 'en'],
            ['paano mag appointment', 'fil'], ['di ko kabalo paano mag appointment', 'hil'],
            ['gusto mag konsulta', 'fil'], ['gusto ko magpa check up', 'fil'],
            ['appointmnt', 'en'], ['appoinment', 'en'], ['book doktor', 'hil'],
        ],
    ],
    'login' => [
        'kb_key' => 'login_help',
        'category' => 'account',
        'phrases' => [
            ['how to login', 'en'], ['cant login', 'en'], ['forgot password', 'en'],
            ['paano mag login', 'fil'], ['hindi ako maka login', 'fil'], ['di ko maka login', 'hil'],
        ],
    ],
    'registration' => [
        'kb_key' => 'registration_help',
        'category' => 'account',
        'phrases' => [
            ['how to register', 'en'], ['create account', 'en'], ['paano mag register', 'fil'],
            ['paano magrehistro', 'fil'], ['paano mag rehistro', 'hil'],
        ],
    ],
    'consultation' => [
        'kb_key' => 'video_consult',
        'category' => 'consultation',
        'phrases' => [
            ['how does video consultation work', 'en'], ['join video call', 'en'],
            ['camera not working', 'en'], ['microphone problem', 'en'], ['wala ko kabati', 'hil'],
            ['paano mag video consult', 'fil'],
        ],
    ],
    'symptoms' => [
        'kb_key' => 'symptoms_general',
        'category' => 'health',
        'phrases' => [
            ['sakit ulo ko', 'hil'], ['masakit ang ulo ko', 'fil'], ['my head hurts', 'en'],
            ['sakit gid sang ulo ko', 'hil'], ['grabe sakit sang ulo ko', 'hil'],
            ['im sick', 'en'], ['may sakit ako', 'fil'], ['may sakit ko', 'hil'],
            ['since yesterday', 'en'], ['since last night', 'en'], ['im also dizzy', 'en'],
        ],
    ],
    'emotional_support' => [
        'kb_key' => 'fear_support',
        'category' => 'emotional_support',
        'phrases' => [
            ["im scared", 'en'], ['nahadlok ko', 'hil'], ['natatakot ako', 'fil'],
            ['im worried', 'en'], ['nabalaka ko', 'hil'], ['im confused', 'en'],
            ['nalibog ko', 'hil'], ['help me', 'en'], ['buligi ko', 'hil'], ['tulungan mo ako', 'fil'],
            ['thank you', 'en'], ['salamat', 'fil'], ['salamat gid', 'hil'], ['okay', 'en'], ['sige', 'fil'],
        ],
    ],
    'connectivity' => [
        'kb_key' => 'signal_internet_problem',
        'category' => 'connectivity',
        'phrases' => [
            ['wala signal', 'hil'], ['no internet', 'en'], ['video call lag', 'en'], ['ga lag', 'hil'],
        ],
    ],
];
foreach ($intentTemplates as $intent => $pack) {
    foreach ($pack['phrases'] as [$phrase, $lang]) {
        $add($phrase, $intent, $pack['category'], $pack['kb_key'], $lang, null, tokenize($phrase), 12);
    }
}

// ── 4. Combinatorial spelling / slang variants for high-value intents ──
$variantBases = [
    ['appointment', 'appointment', 'appointments', 'book_appointment'],
    ['doctor', 'doctor', 'appointments', 'book_appointment'],
    ['login', 'login', 'account', 'login_help'],
    ['register', 'registration', 'account', 'registration_help'],
    ['kwarta', 'financial', 'financial', 'financial_barrier'],
    ['money', 'financial', 'financial', 'financial_barrier'],
];
$prefixes = ['', 'how to ', 'paano ', 'di ko kabalo ', 'help with ', 'need '];
$suffixes = ['', '?', ' please', ' po', ' gid'];
foreach ($variantBases as [$base, $intent, $cat, $kb]) {
    foreach ($prefixes as $pre) {
        foreach ($suffixes as $suf) {
            $add($pre . $base . $suf, $intent, $cat, $kb, 'en', null, [$base], 3);
        }
    }
}

// ── 5. Common patient sentences (sample health complaints) ──
$ccPath = BASE_PATH . '/data/nlp/common_patient_sentences.csv';
if (is_readable($ccPath)) {
    $fh = fopen($ccPath, 'rb');
    $header = fgetcsv($fh);
    $cols = array_flip(array_map('strtolower', $header ?: []));
    $n = 0;
    while (($row = fgetcsv($fh)) !== false && $n < 8000) {
        $complaint = trim((string) ($row[$cols['complaint'] ?? 1] ?? ''));
        $complaint = preg_replace('/\s*\[\d+\]\s*$/', '', $complaint) ?? $complaint;
        if ($complaint === '' || mb_strlen($complaint) > 180) {
            continue;
        }
        $lang = strtolower((string) ($row[$cols['language'] ?? 2] ?? 'english'));
        $L = str_contains($lang, 'hil') ? 'hil' : (str_contains($lang, 'fil') || str_contains($lang, 'tagalog') ? 'fil' : 'en');
        $add($complaint, 'symptoms', 'health', 'symptoms_general', $L, null, tokenize($complaint), 2);
        $n++;
    }
    fclose($fh);
}

// ── 6. Misspelling variants from misspellings.csv ──
$missPath = BASE_PATH . '/data/nlp/misspellings.csv';
if (is_readable($missPath)) {
    $fh = fopen($missPath, 'rb');
    $header = fgetcsv($fh);
    $cols = array_flip(array_map('strtolower', $header ?: []));
    while (($row = fgetcsv($fh)) !== false) {
        $wrong = trim((string) ($row[$cols['misspelling'] ?? $cols['wrong'] ?? 0] ?? ''));
        $right = trim((string) ($row[$cols['correct'] ?? $cols['correction'] ?? 1] ?? ''));
        if ($wrong === '' || $right === '') {
            continue;
        }
        $intent = 'faq';
        $kb = 'navigation_help';
        if (preg_match('/appoint|book|schedul|consult/i', $right)) {
            $intent = 'appointment';
            $kb = 'book_appointment';
        } elseif (preg_match('/login|password|sign/i', $right)) {
            $intent = 'login';
            $kb = 'login_help';
        } elseif (preg_match('/register|account/i', $right)) {
            $intent = 'registration';
            $kb = 'registration_help';
        }
        $add($wrong, $intent, 'system_help', $kb, 'en', null, tokenize($right), 5);
    }
    fclose($fh);
}

$count = count($scenarios);

// Critical patient phrases — highest priority overrides
$critical = [
    ['wala ako kwarta', 'financial', 'financial', 'financial_barrier', 'fil'],
    ['wala ko kwarta', 'financial', 'financial', 'financial_barrier', 'hil'],
    ['i have no money', 'financial', 'financial', 'financial_barrier', 'en'],
    ['sakit ulo ko', 'symptoms', 'health', 'symptoms_general', 'hil'],
    ['masakit ang ulo ko', 'symptoms', 'health', 'symptoms_general', 'fil'],
    ['my head hurts', 'symptoms', 'health', 'symptoms_general', 'en'],
];
foreach ($critical as [$phrase, $intent, $cat, $kb, $lang]) {
    $add($phrase, $intent, $cat, $kb, $lang, null, tokenize($phrase), 99);
}

$count = count($scenarios);
if ($count < $targetMin) {
    fwrite(STDERR, "Warning: only {$count} scenarios generated (target {$targetMin}).\n");
}

$payload = [
    'version'     => '1.0',
    'generated'   => gmdate('c'),
    'count'       => $count,
    'architecture'=> 'phrase_variation → intent → kb_key → response_template',
    'scenarios'   => $scenarios,
];

$json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
if ($json === false) {
    fwrite(STDERR, "JSON encode failed.\n");
    exit(1);
}
file_put_contents($outPath, $json);

$sources = [];
foreach (glob(BASE_PATH . '/data/nlp/*.{csv,json}', GLOB_BRACE) ?: [] as $f) {
    $sources[] = basename($f);
}
file_put_contents($metaPath, json_encode([
    'count' => $count,
    'target' => $targetMin,
    'output' => basename($outPath),
    'sources_sampled' => array_slice($sources, 0, 40),
    'generated' => gmdate('c'),
], JSON_PRETTY_PRINT));

echo "Wrote {$count} scenarios to {$outPath}\n";
