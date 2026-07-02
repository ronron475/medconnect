<?php
/**
 * Medical term filtering layer: discard stop words and non-medical tokens before
 * translation, fuzzy matching, and validation. Only terms present in the medical
 * dictionary or official conditions, allergies, or symptoms datasets proceed.
 */

final class MedicalTermFilter
{
    /** @var array<string, true>|null */
    private static ?array $lexicon = null;

    /** @var list<string>|null */
    private static ?array $dictionaryPhrases = null;

    /** @var array<string, true>|null */
    private static ?array $stopWordIndex = null;

    /** @var list<string> */
    private const STOP_WORD_LIST = [
        'may', 'ako', 'ko', 'ikaw', 'siya', 'kami', 'tayo', 'nila', 'sila',
        'kag', 'ka', 'og', 'ug', 'ang', 'nga', 'sa', 'si', 'ni', 'kay',
        'na', 'pa', 'ba', 'ho', 'po', 'din', 'rin', 'lang', 'gid', 'man',
        'gyud', 'gud', ' gihapon', 'wala', 'walay', 'dili', 'hindi',
        'oo', 'yes', 'no', 'none', 'walang', 'mayroon', 'meron',
        'sing', 'sang', 'yung', 'yun', 'yan', 'ito', 'iya', 'iyan', 'iyon',
        'mga', 'ng', 'muna', 'naman', 'talaga', 'pala', 'raw', 'daw', 'kuno',
        'lamang', 'palang', 'gyapon', 'mo', 'nimo', 'namon', 'nato', 'ila',
        'tungod', 'bang', 'pero', 'gid', 'man', 'sakit', 'gamot',
        'the', 'a', 'an', 'and', 'or', 'to', 'of', 'in', 'on', 'at', 'for',
        'with', 'have', 'has', 'had', 'am', 'is', 'are', 'was', 'were', 'be',
        'been', 'being', 'my', 'me', 'i', 'we', 'you', 'he', 'she', 'they', 'it',
        'this', 'that', 'these', 'those', 'very', 'so', 'just', 'also', 'as',
        'by', 'from', 'into', 'about', 'but', 'not', 'do', 'does', 'did',
        'can', 'could', 'would', 'should', 'will', 'shall', 'might', 'must',
        'than', 'then', 'there', 'here', 'when', 'where', 'why', 'how',
        'all', 'each', 'every', 'both', 'few', 'more', 'most', 'other', 'some',
        'such', 'only', 'own', 'same', 'too', 'up', 'out', 'off', 'over',
        'existing', 'known', 'allergy', 'allergies', 'condition', 'conditions',
        'medical', 'history', 'patient', 'symptom', 'symptoms', 'problem', 'problems',
        'medication', 'medicine', 'unknown', 'n/a', 'nil', 'null', 'wala', 'walay',
        'none known', 'no known', 'walang', 'wala sang',
    ];

    public static function isStopWord(string $term): bool
    {
        $key = self::normalizeKey($term);
        if ($key === '') {
            return true;
        }

        if (self::$stopWordIndex === null) {
            self::$stopWordIndex = array_fill_keys(self::STOP_WORD_LIST, true);
        }

        return isset(self::$stopWordIndex[$key]);
    }

    public static function normalizeKey(string $term): string
    {
        $term = mb_strtolower(trim($term));
        if ($term === '') {
            return '';
        }

        return trim(preg_replace('/\s+/u', ' ', $term) ?? $term);
    }

    /** @return array<string, true> */
    public static function lexicon(): array
    {
        if (self::$lexicon !== null) {
            return self::$lexicon;
        }

        self::$lexicon = [];

        foreach (MedicalDictionary::rows() as $row) {
            self::addLexiconTerm($row['local_term']);
            self::addLexiconTerm($row['english_term']);
        }

        self::loadCsvTerms(BASE_PATH . '/data/nlp/allergies.csv', ['allergy_name', 'search_name']);
        self::loadCsvTerms(BASE_PATH . '/data/nlp/medical_conditions.csv', ['condition_name']);
        self::loadCsvTerms(BASE_PATH . '/data/nlp/symptoms.csv', ['symptom_name']);
        self::loadSymptomLexiconVariants();

        return self::$lexicon;
    }

    private static function loadSymptomLexiconVariants(): void
    {
        foreach (SymptomLexicon::variantIndex() as $variant => $_meta) {
            self::addLexiconTerm($variant);
        }
        foreach (HiligaynonNlpDataset::termIndex() as $variant => $_meta) {
            self::addLexiconTerm($variant);
            $english = (string) ($_meta['english'] ?? '');
            if ($english !== '') {
                self::addLexiconTerm($english);
            }
        }
        foreach (HiligaynonPainRecognition::complaintIndex() as $variant => $_meta) {
            self::addLexiconTerm($variant);
            $english = (string) ($_meta['english'] ?? '');
            if ($english !== '') {
                self::addLexiconTerm($english);
            }
        }
        foreach (HiligaynonMedicalKnowledgeBase::statementIndex() as $variant => $_meta) {
            self::addLexiconTerm($variant);
            $english = (string) ($_meta['english'] ?? '');
            if ($english !== '') {
                self::addLexiconTerm($english);
            }
        }
        foreach (HiligaynonPatientComplaints::complaintIndex() as $variant => $_meta) {
            self::addLexiconTerm($variant);
            $english = (string) ($_meta['english'] ?? '');
            if ($english !== '') {
                self::addLexiconTerm($english);
            }
        }
    }

    public static function isMedicalTerm(string $term): bool
    {
        if (self::isStopWord($term)) {
            return false;
        }

        $key = self::normalizeKey($term);
        if ($key === '') {
            return false;
        }

        if (isset(self::lexicon()[$key])) {
            return true;
        }

        if (MedicalDictionary::lookup($term) !== null || MedicalDictionary::lookupByEnglish($term) !== null) {
            return true;
        }

        if (HiligaynonNlpDataset::lookup($term) !== null) {
            return true;
        }

        if (HiligaynonPainRecognition::lookup($term) !== null) {
            return true;
        }

        if (HiligaynonMedicalKnowledgeBase::lookup($term) !== null) {
            return true;
        }

        if (HiligaynonPatientComplaints::lookup($term) !== null) {
            return true;
        }

        $nlpTranslated = HiligaynonNlpDataset::translateTerm($term);
        $nlpKey = self::normalizeKey($nlpTranslated);
        if (
            $nlpKey !== ''
            && $nlpKey !== $key
            && isset(self::lexicon()[$nlpKey])
        ) {
            return true;
        }

        $translated = MedicalDictionary::translateText($term);
        $translatedKey = self::normalizeKey($translated);
        if (
            $translatedKey !== ''
            && $translatedKey !== $key
            && isset(self::lexicon()[$translatedKey])
        ) {
            return true;
        }

        return false;
    }

    /**
     * @param list<string> $keywords
     * @return array{
     *   accepted: list<string>,
     *   discarded: list<string>,
     *   accepted_count: int,
     *   discarded_count: int
     * }
     */
    public static function filterKeywords(array $keywords, string $normalized = ''): array
    {
        $candidates = self::mergeUnique($keywords, self::extractDictionaryPhrases($normalized));
        $accepted = [];
        $discarded = [];

        foreach ($candidates as $keyword) {
            $normalizedTerm = self::normalizeAcceptedTerm($keyword);
            if ($normalizedTerm === '') {
                $discarded[] = $keyword;
                continue;
            }
            if (self::isMedicalTerm($normalizedTerm)) {
                $accepted[] = $normalizedTerm;
                if ($normalizedTerm !== $keyword) {
                    $discarded[] = $keyword;
                }
            } else {
                $discarded[] = $keyword;
            }
        }

        $accepted = self::pruneSubsumed($accepted);

        return [
            'accepted'         => $accepted,
            'discarded'        => $discarded,
            'accepted_count'   => count($accepted),
            'discarded_count'  => count($discarded),
        ];
    }

    /**
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    public static function applyToField(array $field): array
    {
        $normalized = (string) ($field['normalized'] ?? '');
        $filter = self::filterKeywords($field['keywords'] ?? [], $normalized);

        $field['keywords'] = $filter['accepted'];
        $field['keywords_text'] = implode(' ', $filter['accepted']);
        $field['medical_term_filter'] = [
            'accepted'          => $filter['accepted'],
            'discarded'         => $filter['discarded'],
            'accepted_count'    => $filter['accepted_count'],
            'discarded_count'   => $filter['discarded_count'],
            'lexicon_sources'   => ['dictionary', 'conditions', 'allergies', 'symptoms'],
            'policy'            => 'Only recognized medical dictionary and dataset terms proceed to translation and validation.',
        ];

        return $field;
    }

    /**
     * @param array{allergies:array, conditions:array} $preprocessing
     * @return array{allergies:array, conditions:array, medical_term_filter:array}
     */
    public static function applyToProfile(array $preprocessing): array
    {
        $allergies = self::applyToField($preprocessing['allergies'] ?? []);
        $conditions = self::applyToField($preprocessing['conditions'] ?? []);

        return [
            'allergies'           => $allergies,
            'conditions'          => $conditions,
            'medical_term_filter' => [
                'allergies'  => $allergies['medical_term_filter'] ?? [],
                'conditions' => $conditions['medical_term_filter'] ?? [],
                'discarded_total' => ($allergies['medical_term_filter']['discarded_count'] ?? 0)
                    + ($conditions['medical_term_filter']['discarded_count'] ?? 0),
                'accepted_total' => ($allergies['medical_term_filter']['accepted_count'] ?? 0)
                    + ($conditions['medical_term_filter']['accepted_count'] ?? 0),
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $queue
     * @return array{accepted:list<array<string, mixed>>, discarded:list<array<string, mixed>>}
     */
    public static function filterValidationQueue(array $queue): array
    {
        $accepted = [];
        $discarded = [];

        foreach ($queue as $item) {
            $local = (string) ($item['local_term'] ?? '');
            $english = (string) ($item['match_term'] ?? $item['english_term'] ?? '');
            if (self::isMedicalTerm($local) || self::isMedicalTerm($english)) {
                $accepted[] = $item;
            } else {
                $discarded[] = $item;
            }
        }

        return [
            'accepted'  => $accepted,
            'discarded' => $discarded,
        ];
    }

    /** @return list<string> */
    public static function extractDictionaryPhrases(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $len = strlen($text);
        $occupied = array_fill(0, $len, false);
        $candidates = [];

        foreach (self::dictionaryPhrases() as $term) {
            $pattern = '/(?<!\w)' . preg_quote($term, '/') . '(?!\w)/iu';
            if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $start = $match[1];
                    $end = $start + strlen($match[0]);
                    $candidates[] = [$start, $end, $term, $end - $start];
                }
            }
        }

        usort($candidates, static function ($a, $b) {
            if ($a[3] !== $b[3]) {
                return $b[3] <=> $a[3];
            }

            return $a[0] <=> $b[0];
        });

        $matched = [];
        foreach ($candidates as [$start, $end, $term]) {
            $overlap = false;
            for ($i = $start; $i < $end; $i++) {
                if (!empty($occupied[$i])) {
                    $overlap = true;
                    break;
                }
            }
            if ($overlap) {
                continue;
            }
            for ($i = $start; $i < $end; $i++) {
                $occupied[$i] = true;
            }
            $matched[] = $term;
        }

        return array_values(array_unique($matched));
    }

    /** @return list<string> */
    private static function dictionaryPhrases(): array
    {
        if (self::$dictionaryPhrases !== null) {
            return self::$dictionaryPhrases;
        }

        self::$dictionaryPhrases = MedicalDictionary::termsByLength();

        return self::$dictionaryPhrases;
    }

    private static function addLexiconTerm(string $term): void
    {
        $key = self::normalizeKey($term);
        if ($key === '' || self::isStopWord($key)) {
            return;
        }
        self::$lexicon[$key] = true;
    }

    /**
     * @param list<string> $columns
     */
    private static function loadCsvTerms(string $path, array $columns): void
    {
        if (!is_readable($path)) {
            return;
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            return;
        }

        $header = fgetcsv($handle);
        $indexes = [];
        if (is_array($header)) {
            foreach ($columns as $column) {
                $idx = array_search($column, $header, true);
                if ($idx !== false) {
                    $indexes[] = (int) $idx;
                }
            }
        }
        if ($indexes === []) {
            $indexes = [1];
        }

        while (($row = fgetcsv($handle)) !== false) {
            foreach ($indexes as $idx) {
                $value = trim($row[$idx] ?? '');
                if ($value !== '') {
                    self::addLexiconTerm($value);
                }
            }
        }
        fclose($handle);
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     * @return list<string>
     */
    private static function mergeUnique(array $a, array $b): array
    {
        $seen = [];
        $out = [];
        foreach (array_merge($a, $b) as $term) {
            $key = self::normalizeKey($term);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = trim($term);
        }

        return $out;
    }

    /**
     * @param list<string> $terms
     * @return list<string>
     */
    private static function pruneSubsumed(array $terms): array
    {
        if (count($terms) <= 1) {
            return $terms;
        }

        usort($terms, static fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        $kept = [];
        foreach ($terms as $term) {
            $termLower = mb_strtolower($term);
            $subsumed = false;
            foreach ($kept as $longer) {
                if (preg_match('/(?<!\w)' . preg_quote($termLower, '/') . '(?!\w)/iu', mb_strtolower($longer))) {
                    $subsumed = true;
                    break;
                }
            }
            if (!$subsumed) {
                $kept[] = $term;
            }
        }

        return $kept;
    }

    public static function stripStopPadding(string $term): string
    {
        $words = preg_split('/\s+/u', trim($term), -1, PREG_SPLIT_NO_EMPTY);
        if (!$words) {
            return '';
        }

        while ($words !== [] && self::isStopWord($words[0])) {
            array_shift($words);
        }
        while ($words !== [] && self::isStopWord($words[count($words) - 1])) {
            array_pop($words);
        }

        return implode(' ', $words);
    }

    public static function normalizeAcceptedTerm(string $term): string
    {
        $stripped = self::stripStopPadding($term);
        if ($stripped === '') {
            return '';
        }

        if (self::isMedicalTerm($stripped)) {
            return $stripped;
        }

        return self::isMedicalTerm($term) ? trim($term) : '';
    }
}
