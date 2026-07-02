<?php
/**
 * Phrase-first Hiligaynon → English translation.
 * Full phrases are translated before any token-level or dataset matching.
 */

final class HiligaynonPhraseTranslator
{
    /** @var array<string, array{english:string, medical_keyword:string, category:string, body_part:string}> */
    private const SWELLING_BODY_PARTS = [
        'unto'       => ['english' => 'swollen gums', 'medical_keyword' => 'swollen gums', 'category' => 'symptom', 'body_part' => 'gums'],
        'unud'       => ['english' => 'swollen gums', 'medical_keyword' => 'swollen gums', 'category' => 'symptom', 'body_part' => 'gums'],
        'ngipon'     => ['english' => 'swollen gums', 'medical_keyword' => 'swollen gums', 'category' => 'symptom', 'body_part' => 'gums'],
        'tiyan'      => ['english' => 'abdominal swelling', 'medical_keyword' => 'abdominal swelling', 'category' => 'symptom', 'body_part' => 'abdomen'],
        'tyan'       => ['english' => 'abdominal swelling', 'medical_keyword' => 'abdominal swelling', 'category' => 'symptom', 'body_part' => 'abdomen'],
        'mata'       => ['english' => 'swollen eyes', 'medical_keyword' => 'swollen eyes', 'category' => 'symptom', 'body_part' => 'eyes'],
        'ilong'      => ['english' => 'swollen nose', 'medical_keyword' => 'nasal swelling', 'category' => 'symptom', 'body_part' => 'nose'],
        'dughan'     => ['english' => 'chest swelling', 'medical_keyword' => 'chest swelling', 'category' => 'symptom', 'body_part' => 'chest'],
        'kamot'      => ['english' => 'swollen hands', 'medical_keyword' => 'hand swelling', 'category' => 'symptom', 'body_part' => 'hands'],
        'tiil'       => ['english' => 'swollen foot', 'medical_keyword' => 'foot swelling', 'category' => 'symptom', 'body_part' => 'foot'],
        'lawas'      => ['english' => 'body swelling', 'medical_keyword' => 'body swelling', 'category' => 'symptom', 'body_part' => 'body'],
        'tutunlan'   => ['english' => 'throat swelling', 'medical_keyword' => 'throat swelling', 'category' => 'symptom', 'body_part' => 'throat'],
        'dalunggan'  => ['english' => 'ear swelling', 'medical_keyword' => 'ear swelling', 'category' => 'symptom', 'body_part' => 'ear'],
        'likod'      => ['english' => 'back swelling', 'medical_keyword' => 'back swelling', 'category' => 'symptom', 'body_part' => 'back'],
        'tuhod'      => ['english' => 'knee swelling', 'medical_keyword' => 'knee swelling', 'category' => 'symptom', 'body_part' => 'knee'],
        'abaga'      => ['english' => 'shoulder swelling', 'medical_keyword' => 'shoulder swelling', 'category' => 'symptom', 'body_part' => 'shoulder'],
        'palad'      => ['english' => 'swollen palms', 'medical_keyword' => 'hand swelling', 'category' => 'symptom', 'body_part' => 'hands'],
    ];

    /** @var list<string> */
    private const HILIGAYNON_MARKERS = [
        'sakit', 'masakit', 'kirot', 'hapdi', 'gin', 'nag', 'ga', 'ako', 'akon',
        'hubag', 'gahubag', 'ubo', 'sipon', 'sip-on', 'tiyan', 'dughan', 'ulo',
        'mata', 'lawas', 'gint', 'budlay', 'ginhawa', 'kalibanga', 'hilanat',
        'lingin', 'nahilo', 'unto', 'unud', 'dalunggan', 'tutunlan', 'ngipon',
        'kag', 'gid', 'man', 'subong', 'halin', 'daw', 'may', 'ara',
    ];

    public static function detectLanguage(string $text): string
    {
        return HiligaynonLanguageDetector::primaryLanguage($text);
    }

    /**
     * @return array{
     *   english:string,
     *   medical_keyword:string,
     *   category:string,
     *   body_part:string,
     *   source:string,
     *   input_language:string
     * }|null
     */
    public static function translateFullPhrase(string $text): ?array
    {
        $original = trim($text);
        $corrected = MedicalMisspellingsLoader::applyCorrections($original);
        $normalized = HiligaynonTextNormalizer::normalize($corrected);
        if ($normalized === '') {
            return null;
        }

        foreach (array_merge(
            HiligaynonTextNormalizer::phraseVariants($corrected),
            HiligaynonTextNormalizer::phraseVariants($original)
        ) as $variant) {
            $entity = MedicalEntityExtractor::extractPrimaryEntity($variant !== '' ? $variant : $corrected);
            if ($entity !== null && ($entity['english_term'] ?? '') !== '') {
                $en = (string) $entity['english_term'];

                return self::formatResult(
                    $en,
                    (string) ($entity['condition'] ?: $entity['symptom'] ?: $en),
                    ($entity['type'] ?? '') === 'condition' ? 'condition' : 'symptom',
                    (string) ($entity['body_part'] ?? ''),
                    (string) ($entity['source'] ?? 'phrase_entity')
                );
            }

            $phrase = SymptomPhrasesLoader::lookupPhrase(HiligaynonTextNormalizer::normalize($variant));
            if ($phrase !== null) {
                $en = (string) $phrase['english_term'];

                return self::formatResult(
                    $en,
                    $en,
                    'condition',
                    (string) ($phrase['body_part'] ?? ''),
                    'symptom_phrases'
                );
            }

            $result = self::lookupExactPhrase(HiligaynonTextNormalizer::normalize($variant));
            if ($result !== null) {
                return $result;
            }
        }

        $result = self::lookupExactPhrase($normalized);
        if ($result !== null) {
            return $result;
        }

        $result = self::translateContextualPatterns($normalized);
        if ($result !== null) {
            return $result;
        }

        $result = self::translateViaSymptomMatcher($normalized);
        if ($result !== null) {
            return $result;
        }

        $dictionaryEnglish = MedicalDictionary::translateText($normalized);
        if (
            $dictionaryEnglish !== ''
            && mb_strtolower($dictionaryEnglish) !== mb_strtolower($normalized)
        ) {
            return self::formatResult(
                $dictionaryEnglish,
                $dictionaryEnglish,
                'symptom',
                '',
                'phrase_dictionary'
            );
        }

        foreach (
            [
                [HiligaynonPainRecognition::class, 'translateText'],
                [HiligaynonMedicalKnowledgeBase::class, 'translateText'],
                [HiligaynonPatientComplaints::class, 'translateText'],
                [HiligaynonNlpDataset::class, 'translateText'],
            ] as [$class, $method]
        ) {
            $english = $class::$method($normalized);
            if (
                $english !== ''
                && mb_strtolower($english) !== mb_strtolower($normalized)
            ) {
                $english = BodyPartPainSymptoms::canonicalEnglish($english);

                return self::formatResult($english, $english, 'symptom', '', 'phrase_' . strtolower($class));
            }
        }

        return null;
    }

    public static function isHiligaynonInput(string $text): bool
    {
        return HiligaynonLanguageDetector::isLocalLanguage($text);
    }

    /**
     * @return array{
     *   english:string,
     *   medical_keyword:string,
     *   category:string,
     *   body_part:string,
     *   source:string,
     *   input_language:string
     * }|null
     */
    private static function lookupExactPhrase(string $normalized): ?array
    {
        $lookups = [
            [HiligaynonPainRecognition::class, 'lookup', 'pain_category'],
            [HiligaynonNlpDataset::class, 'lookup', 'category'],
            [HiligaynonMedicalKnowledgeBase::class, 'lookup', 'body_system'],
            [HiligaynonPatientComplaints::class, 'lookup', 'body_system'],
        ];

        foreach ($lookups as [$class, $method, $catField]) {
            $entry = $class::$method($normalized);
            if ($entry === null) {
                continue;
            }
            $english = trim((string) ($entry['english'] ?? ''));
            if ($english === '') {
                continue;
            }
            $english = BodyPartPainSymptoms::canonicalEnglish($english);

            return self::formatResult(
                $english,
                $english,
                (string) ($entry[$catField] ?? $entry['category'] ?? 'symptom'),
                (string) ($entry['body_part'] ?? ''),
                'exact_phrase_' . strtolower($class)
            );
        }

        $dict = MedicalDictionary::lookup($normalized);
        if ($dict !== null) {
            return self::formatResult(
                $dict['english_term'],
                $dict['english_term'],
                $dict['category'] !== '' ? $dict['category'] : 'symptom',
                '',
                'exact_dictionary'
            );
        }

        if (class_exists('HiligaynonMedicalTraining')) {
            $training = HiligaynonMedicalTraining::lookup($normalized);
            if ($training !== null) {
                return self::formatResult(
                    $training['english_translation'],
                    $training['medical_keyword'],
                    $training['category'],
                    '',
                    'training_dataset'
                );
            }
        }

        return null;
    }

    /**
     * @return array{
     *   english:string,
     *   medical_keyword:string,
     *   category:string,
     *   body_part:string,
     *   source:string,
     *   input_language:string
     * }|null
     */
    private static function translateContextualPatterns(string $normalized): ?array
    {
        if (preg_match('/\b(?:may\s+)?nanah\b.*\bpilas\b|\bpilas\b.*\bnanah\b/u', $normalized)) {
            return self::formatResult(
                'infected wound',
                'infected wound',
                'symptom',
                'skin',
                'contextual_infected_wound'
            );
        }

        if (preg_match('/\bwala\b.*\bkusog\b|\bdaw wala ko kusog\b/u', $normalized)) {
            return self::formatResult('weakness', 'weakness', 'symptom', 'body', 'contextual_weakness');
        }

        if (preg_match('/\bubo\b.*\bsipon\b|\bsipon\b.*\bubo\b/u', $normalized)) {
            return self::formatResult(
                'cough and runny nose',
                'common cold',
                'symptom',
                'respiratory',
                'contextual_cold'
            );
        }

        if (preg_match('/\bsuka\b.*\bkalibanga\b|\bkalibanga\b.*\bsuka\b/u', $normalized)) {
            return self::formatResult(
                'vomiting and diarrhea',
                'gastroenteritis',
                'symptom',
                'abdomen',
                'contextual_gastroenteritis'
            );
        }

        if (preg_match('/\b(?:ga\s+)?pito\b.*\bdughan\b|\bpito dughan\b/u', $normalized)) {
            return self::formatResult('chest pain', 'chest pain', 'pain', 'chest', 'contextual_chest_pain');
        }

        if (preg_match('/\bkapoy\b.*\blawas\b/u', $normalized)) {
            return self::formatResult('fatigue', 'fatigue', 'symptom', 'body', 'contextual_fatigue');
        }

        if (preg_match('/\bindi ko kaginhawa\b|\bindi ko makaginhawa\b/u', $normalized)) {
            return self::formatResult(
                'cannot breathe',
                'respiratory distress',
                'emergency',
                'respiratory',
                'contextual_emergency_breathing'
            );
        }

        if (preg_match('/\bbudlay\b.*\bginhawa\b/u', $normalized)) {
            return self::formatResult(
                'difficulty breathing',
                'dyspnea',
                'symptom',
                'respiratory',
                'contextual_dyspnea'
            );
        }

        if (preg_match('/\b(?:ga\s+)?dugo\b.*\bgid\b|\bgrabe pagdugo\b/u', $normalized)) {
            return self::formatResult(
                'severe bleeding',
                'hemorrhage',
                'emergency',
                'cardiovascular',
                'contextual_bleeding'
            );
        }

        if (preg_match('/\bmay\s+nana\s+sa\s+bilat\b/u', $normalized)) {
            return self::formatResult('vaginal infection', 'vaginal infection', 'condition', 'vagina', 'contextual_vaginal_infection');
        }
        if (preg_match('/\bmay\s+nana\s+sa\s+ari\b/u', $normalized)) {
            return self::formatResult('penile infection', 'penile infection', 'condition', 'penis', 'contextual_penile_infection');
        }
        if (preg_match('/\b(?:nagadugo|gadugo|nagdugo)\s+bilat\b/u', $normalized)) {
            return self::formatResult('vaginal bleeding', 'vaginal bleeding', 'symptom', 'vagina', 'contextual_vaginal_bleeding');
        }
        if (preg_match('/\b(?:gadugo|nagadugo|nagdugo)\s+ari\b/u', $normalized)) {
            return self::formatResult('penile bleeding', 'penile bleeding', 'symptom', 'penis', 'contextual_penile_bleeding');
        }
        if (preg_match('/\b(?:gahabok|gahubag|hubag)\s+(?:akon\s+)?itlog\b/u', $normalized)) {
            return self::formatResult('testicular swelling', 'testicular swelling', 'symptom', 'testicle', 'contextual_testicular_swelling');
        }
        if (preg_match('/\b(?:kakatol|gakatol)\s+bilat\b/u', $normalized)) {
            return self::formatResult('vaginal itching', 'vaginal itching', 'symptom', 'vagina', 'contextual_vaginal_itching');
        }

        foreach (self::SWELLING_BODY_PARTS as $part => $meta) {
            $pattern = '/(?:ga\s+|naga\s+|gina\s+|gi)?hubag(?:-hubag)?\s+(?:ang|sang)\s+' . preg_quote($part, '/') . '\b/u';
            if (preg_match($pattern, $normalized)) {
                return self::formatResult(
                    $meta['english'],
                    $meta['medical_keyword'],
                    $meta['category'],
                    $meta['body_part'],
                    'contextual_swelling'
                );
            }

            $patternLoose = '/\b(?:ga\s+)?hubag\b.*\b' . preg_quote($part, '/') . '\b/u';
            if (preg_match($patternLoose, $normalized) && !preg_match('/\blawas\b/u', $normalized)) {
                return self::formatResult(
                    $meta['english'],
                    $meta['medical_keyword'],
                    $meta['category'],
                    $meta['body_part'],
                    'contextual_swelling_loose'
                );
            }

            $pattern2 = '/\bhubag\s+(?:ang|sang)\s+' . preg_quote($part, '/') . '\b/u';
            if (preg_match($pattern, $normalized)) {
                return self::formatResult(
                    $meta['english'],
                    $meta['medical_keyword'],
                    $meta['category'],
                    $meta['body_part'],
                    'contextual_swelling'
                );
            }

            $pattern2 = '/\bhubag\s+(?:ang|sang)\s+' . preg_quote($part, '/') . '\b/u';
            if (preg_match($pattern2, $normalized)) {
                return self::formatResult(
                    $meta['english'],
                    $meta['medical_keyword'],
                    $meta['category'],
                    $meta['body_part'],
                    'contextual_swelling'
                );
            }
        }

        if (preg_match('/(?:ga|naga|gina|gi)?hubag(?:-hubag)?\s+(?:ang|sang)\s+lawas\b/u', $normalized)
            || preg_match('/\bhubag\s+lawas\b/u', $normalized)) {
            return self::formatResult('hives', 'hives', 'symptom', 'body', 'contextual_hives');
        }

        if (preg_match('/(?:ga|naga|gina|gi)?hubag(?:-hubag)?\s+ko\b/u', $normalized)
            && !preg_match('/\b(?:ang|sang)\s+\w+/u', $normalized)) {
            return self::formatResult('swelling', 'swelling', 'symptom', 'body', 'contextual_general_swelling');
        }

        return null;
    }

    /**
     * @return array{
     *   english:string,
     *   medical_keyword:string,
     *   category:string,
     *   body_part:string,
     *   source:string,
     *   input_language:string
     * }|null
     */
    private static function translateViaSymptomMatcher(string $normalized): ?array
    {
        $match = HiligaynonSymptomMatcher::recognize($normalized, null, true);
        $detections = $match['detections'] ?? [];
        if ($detections === []) {
            return null;
        }

        usort(
            $detections,
            static function (array $a, array $b): int {
                $lenDiff = mb_strlen((string) ($b['detected_symptom'] ?? ''))
                    <=> mb_strlen((string) ($a['detected_symptom'] ?? ''));
                if ($lenDiff !== 0) {
                    return $lenDiff;
                }

                return ((int) ($b['confidence'] ?? 0)) <=> ((int) ($a['confidence'] ?? 0));
            }
        );

        $best = $detections[0];
        $detected = (string) ($best['detected_symptom'] ?? '');
        $english = trim((string) ($best['english_translation'] ?? ''));
        if ($english === '' || mb_strlen($detected) < 4) {
            return null;
        }

        $contentTokens = self::contentTokenCount($normalized);
        if ($contentTokens >= 2 && mb_strlen($detected) < 6) {
            return null;
        }

        $english = BodyPartPainSymptoms::canonicalEnglish($english);

        return self::formatResult(
            $english,
            (string) ($best['medical_term'] ?? $english),
            (string) ($best['category'] ?? 'symptom'),
            '',
            'symptom_lexicon_phrase'
        );
    }

  /**
     * @return array{
     *   english:string,
     *   medical_keyword:string,
     *   category:string,
     *   body_part:string,
     *   source:string,
     *   input_language:string
     * }
     */
    private static function formatResult(
        string $english,
        string $medicalKeyword,
        string $category,
        string $bodyPart,
        string $source
    ): array {
        return [
            'english'          => trim($english),
            'medical_keyword'  => trim($medicalKeyword !== '' ? $medicalKeyword : $english),
            'category'         => $category !== '' ? $category : 'symptom',
            'body_part'        => $bodyPart,
            'source'           => $source,
            'input_language'   => 'hiligaynon',
        ];
    }

    private static function countHiligaynonMarkers(string $text): int
    {
        $count = 0;
        foreach (self::HILIGAYNON_MARKERS as $marker) {
            if (preg_match('/\b' . preg_quote($marker, '/') . '\b/u', $text)) {
                $count++;
            }
        }

        return $count;
    }

    private static function contentTokenCount(string $text): int
    {
        $fillers = array_flip([
            'ang', 'nga', 'sa', 'ko', 'ako', 'gid', 'man', 'subong', 'may', 'sang', 'sing', 'ka',
        ]);
        $tokens = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $n = 0;
        foreach ($tokens as $token) {
            if (!isset($fillers[$token])) {
                $n++;
            }
        }

        return $n;
    }
}
