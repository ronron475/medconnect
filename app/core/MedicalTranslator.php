<?php
/**
 * Maps preprocessed local medical terms to standardized English via medical_dictionary.csv.
 * Validation always uses English equivalents — never raw Hiligaynon.
 */

final class MedicalTranslator
{
    /**
     * @param array{keywords:list<string>, cleaned:string, normalized?:string, english_preview?:string, field:string} $preprocessedField
     * @return array<string, mixed>
     */
    public static function translateField(array $preprocessedField, string $expectedCategory): array
    {
        $keywords = $preprocessedField['keywords'] ?? [];
        $cleaned  = (string) ($preprocessedField['cleaned'] ?? '');
        $normalized = (string) ($preprocessedField['normalized'] ?? $cleaned);
        $field    = (string) ($preprocessedField['field'] ?? 'conditions');
        $englishPreview = (string) ($preprocessedField['english_preview'] ?? '');
        $original = (string) ($preprocessedField['original'] ?? $normalized);

        $phraseInput = $normalized !== '' ? $normalized : ($cleaned !== '' ? $cleaned : $original);
        if ($phraseInput !== '' && HiligaynonPhraseTranslator::isHiligaynonInput($phraseInput)) {
            return self::translateHiligaynonPhrase($phraseInput, $expectedCategory, $field, $englishPreview);
        }

        $items = [];
        $validationQueue = [];
        $matched = 0;
        $unmatched = 0;
        $seenEnglish = [];

        foreach ($keywords as $keyword) {
            $item = self::translateTerm($keyword, $expectedCategory);
            $items[] = $item;
            if ($item['status'] === 'matched') {
                $matched++;
            } else {
                $unmatched++;
            }
            self::appendQueueItem($validationQueue, $item, $seenEnglish);
        }

        if ($items === [] && ($normalized !== '' || $cleaned !== '')) {
            $phraseText = $cleaned !== '' ? $cleaned : $normalized;
            $symptomItems = self::translateFromSymptomMatcher($phraseText, $expectedCategory);
            if ($symptomItems !== []) {
                foreach ($symptomItems as $symptomItem) {
                    $items[] = $symptomItem;
                    if ($symptomItem['status'] === 'matched') {
                        $matched++;
                    } else {
                        $unmatched++;
                    }
                    self::appendQueueItem($validationQueue, $symptomItem, $seenEnglish);
                }
            } else {
                $fullItem = self::translatePhrase($phraseText, $expectedCategory);
                $items[] = $fullItem;
                if ($fullItem['status'] === 'matched') {
                    $matched++;
                } else {
                    $unmatched++;
                }
                self::appendQueueItem($validationQueue, $fullItem, $seenEnglish);
            }
        }

        if ($validationQueue === [] && $englishPreview !== '' && $items === []) {
            foreach (self::splitEnglishPhrases($englishPreview) as $phrase) {
                $item = self::translateTerm($phrase, $expectedCategory);
                $items[] = $item;
                self::appendQueueItem($validationQueue, $item, $seenEnglish);
            }
        }

        $englishParts = [];
        foreach ($validationQueue as $q) {
            $englishParts[] = $q['match_term'];
        }
        $englishText = implode(', ', array_unique(array_filter($englishParts)));

        if ($englishText === '' && $cleaned !== '') {
            $englishText = MedicalDictionary::translateText($normalized ?: $cleaned);
        }

        $total = count($validationQueue);
        $status = self::overallStatus($matched, $unmatched, max(count($keywords), $total));

        return [
            'field'              => $field,
            'expected_category'  => $expectedCategory,
            'status'             => $status,
            'status_label'       => self::statusLabel($status, $matched, max(1, count($keywords))),
            'english_text'       => $englishText,
            'english_preview'    => $englishPreview ?: $englishText,
            'matched_count'      => $matched,
            'unmatched_count'    => $unmatched,
            'total_count'        => $total,
            'items'              => $items,
            'validation_queue'   => $validationQueue,
            'translate_first'    => true,
        ];
    }

    /**
     * @param array{allergies:array, conditions:array} $preprocessing
     * @return array<string, mixed>
     */
    public static function translateProfile(array $preprocessing): array
    {
        $allergies = self::translateField($preprocessing['allergies'] ?? [], 'allergy');
        $conditions = self::translateField($preprocessing['conditions'] ?? [], 'condition');

        $combined = array_filter([
            $conditions['english_text'] ?? '',
            $allergies['english_text'] ?? '',
        ]);
        $combinedEnglish = implode(' | ', $combined);

        $totalMatched = ($allergies['matched_count'] ?? 0) + ($conditions['matched_count'] ?? 0);
        $totalUnmatched = ($allergies['unmatched_count'] ?? 0) + ($conditions['unmatched_count'] ?? 0);
        $total = ($allergies['total_count'] ?? 0) + ($conditions['total_count'] ?? 0);
        $overall = self::overallStatus($totalMatched, $totalUnmatched, $total);

        return [
            'allergies'              => $allergies,
            'conditions'             => $conditions,
            'combined_english'       => $combinedEnglish,
            'overall_status'         => $overall,
            'overall_status_label'   => self::statusLabel($overall, $totalMatched, $total),
            'translate_first'        => true,
        ];
    }

    /**
     * Map an English medical concept to validation queue item (never raw Hiligaynon).
     *
     * @return array<string, mixed>
     */
    public static function translateEnglishConcept(
        string $english,
        string $localSource,
        string $expectedCategory,
        string $note = 'english_concept'
    ): array {
        $english = BodyPartPainSymptoms::canonicalEnglish(trim($english));
        if ($english === '') {
            return self::buildItem($localSource, $localSource, null, $expectedCategory, false, 'unmapped');
        }

        $entry = MedicalDictionary::lookupByEnglish($english) ?? MedicalDictionary::lookup($english);
        $wasTranslated = mb_strtolower($english) !== mb_strtolower($localSource);

        return self::buildItem($localSource, $english, $entry, $expectedCategory, $wasTranslated, $note);
    }

    /**
     * Phrase-first Hiligaynon translation → English concepts → validation queue.
     *
     * @return array<string, mixed>
     */
    private static function translateHiligaynonPhrase(
        string $phraseInput,
        string $expectedCategory,
        string $field,
        string $englishPreview
    ): array {
        $phraseTranslation = HiligaynonPhraseTranslator::translateFullPhrase($phraseInput);
        $items = [];
        $validationQueue = [];
        $seenEnglish = [];
        $matched = 0;
        $unmatched = 0;

        if ($phraseTranslation !== null) {
            foreach (MedicalConceptExtractor::extractFromTranslation($phraseTranslation) as $concept) {
                $item = self::translateEnglishConcept(
                    $concept['english'],
                    $phraseInput,
                    $concept['category'] !== '' ? $concept['category'] : $expectedCategory,
                    (string) ($phraseTranslation['source'] ?? 'phrase_translation')
                );
                $item['medical_keyword'] = $concept['medical_keyword'];
                $item['body_part'] = $concept['body_part'] ?? '';
                $item['input_language'] = 'hiligaynon';
                $items[] = $item;
                if ($item['status'] === 'matched') {
                    $matched++;
                } else {
                    $unmatched++;
                }
                self::appendQueueItem($validationQueue, $item, $seenEnglish);
            }
        }

        $cleaned = NlpPreprocessor::removeFillers(HiligaynonTextNormalizer::normalize($phraseInput));
        foreach (NlpPreprocessor::extractTokenDictionaryKeywords($cleaned) as $token) {
            $item = self::translateTerm($token, $expectedCategory);
            $items[] = $item;
            if ($item['status'] === 'matched') {
                $matched++;
            } else {
                $unmatched++;
            }
            self::appendQueueItem($validationQueue, $item, $seenEnglish);
        }

        if ($validationQueue === [] && $englishPreview !== '') {
            foreach (self::splitEnglishPhrases($englishPreview) as $enPhrase) {
                $item = self::translateEnglishConcept($enPhrase, $phraseInput, $expectedCategory, 'english_preview');
                $items[] = $item;
                if ($item['status'] === 'matched') {
                    $matched++;
                } else {
                    $unmatched++;
                }
                self::appendQueueItem($validationQueue, $item, $seenEnglish);
            }
        }

        $englishText = $phraseTranslation['english'] ?? $englishPreview;
        if ($englishText === '') {
            $englishText = MedicalDictionary::translateText($phraseInput);
        }

        $total = count($validationQueue);
        $status = self::overallStatus($matched, $unmatched, max(1, $total));

        return [
            'field'              => $field,
            'expected_category'  => $expectedCategory,
            'status'             => $status,
            'status_label'       => self::statusLabel($status, $matched, max(1, $total)),
            'english_text'       => $englishText,
            'english_preview'    => $englishPreview ?: $englishText,
            'phrase_translation' => $phraseTranslation,
            'matched_count'      => $matched,
            'unmatched_count'    => $unmatched,
            'total_count'        => $total,
            'items'              => $items,
            'validation_queue'   => $validationQueue,
            'translate_first'    => true,
            'pipeline'           => 'phrase_first',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function translateTerm(string $localTerm, string $expectedCategory): array
    {
        $localTerm = trim($localTerm);

        $painEntry = HiligaynonPainRecognition::lookup($localTerm);
        if ($painEntry !== null) {
            $english = (string) ($painEntry['english'] ?? '');
            if ($english !== '') {
                $item = self::buildItem(
                    $localTerm,
                    $english,
                    null,
                    $expectedCategory,
                    true,
                    'hiligaynon_pain_recognition'
                );
                $item['category'] = (string) ($painEntry['pain_category'] ?? 'pain');

                return $item;
            }
        }

        $nlpEntry = HiligaynonNlpDataset::lookup($localTerm);
        if ($nlpEntry !== null) {
            $english = (string) ($nlpEntry['english'] ?? '');
            if ($english !== '') {
                $english = BodyPartPainSymptoms::canonicalEnglish($english);

                return self::buildItem(
                    $localTerm,
                    $english,
                    null,
                    $expectedCategory,
                    true,
                    'hiligaynon_nlp_dataset'
                );
            }
        }

        $kbEntry = HiligaynonMedicalKnowledgeBase::lookup($localTerm);
        if ($kbEntry !== null) {
            $english = (string) ($kbEntry['english'] ?? '');
            if ($english !== '') {
                $item = self::buildItem(
                    $localTerm,
                    $english,
                    null,
                    $expectedCategory,
                    true,
                    'hiligaynon_medical_knowledge_base'
                );
                $item['category'] = (string) ($kbEntry['body_system'] ?? $expectedCategory);

                return $item;
            }
        }

        $complaintEntry = HiligaynonPatientComplaints::lookup($localTerm);
        if ($complaintEntry !== null) {
            $english = (string) ($complaintEntry['english'] ?? '');
            if ($english !== '') {
                $item = self::buildItem(
                    $localTerm,
                    $english,
                    null,
                    $expectedCategory,
                    true,
                    'hiligaynon_patient_complaints'
                );
                $item['category'] = (string) ($complaintEntry['body_system'] ?? $expectedCategory);

                return $item;
            }
        }

        $entry = MedicalDictionary::lookup($localTerm);

        if ($entry !== null) {
            $wasTranslated = mb_strtolower($entry['english_term']) !== mb_strtolower($localTerm);

            return self::buildItem($localTerm, $entry['english_term'], $entry, $expectedCategory, $wasTranslated);
        }

        $phraseEnglish = MedicalDictionary::translateText($localTerm);
        if ($phraseEnglish !== '' && mb_strtolower($phraseEnglish) !== mb_strtolower($localTerm)) {
            $entry = MedicalDictionary::lookupByEnglish($phraseEnglish) ?? MedicalDictionary::lookup($phraseEnglish);

            return self::buildItem(
                $localTerm,
                $phraseEnglish,
                $entry,
                $expectedCategory,
                true,
                'phrase_dictionary'
            );
        }

        $symptomItem = self::lookupSymptomLexicon($localTerm, $expectedCategory);
        if ($symptomItem !== null) {
            return $symptomItem;
        }

        $englishEntry = MedicalDictionary::lookupByEnglish($localTerm);
        if ($englishEntry !== null || MedicalDictionary::isLikelyEnglish($localTerm)) {
            return self::buildItem(
                $localTerm,
                $localTerm,
                $englishEntry,
                $expectedCategory,
                false,
                'english_input'
            );
        }

        return self::buildItem($localTerm, $localTerm, null, $expectedCategory, false, 'unmapped');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function translateFromSymptomMatcher(string $text, string $expectedCategory): array
    {
        $result = HiligaynonSymptomMatcher::recognize($text);
        $detections = $result['detections'] ?? [];
        if ($detections === []) {
            return [];
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
        $english = trim((string) ($best['english_translation'] ?? ''));
        if ($english === '') {
            return [];
        }

        $item = self::buildItem(
            (string) ($best['detected_symptom'] ?? $text),
            $english,
            null,
            $expectedCategory,
            true,
            'hiligaynon_symptom_lexicon'
        );
        $item['category'] = (string) ($best['category'] ?? 'symptom');

        return [$item];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function lookupSymptomLexicon(string $localTerm, string $expectedCategory): ?array
    {
        $items = self::translateFromSymptomMatcher($localTerm, $expectedCategory);

        return $items[0] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function translatePhrase(string $text, string $expectedCategory): array
    {
        $english = MedicalDictionary::translateText($text);
        if ($english === '' || $english === $text) {
            return self::translateTerm($text, $expectedCategory);
        }

        return self::translateTerm($english, $expectedCategory);
    }

    /**
     * @param array{dictionary_id?:int, english_term?:string, category?:string}|null $entry
     * @return array<string, mixed>
     */
    private static function buildItem(
        string $localTerm,
        string $english,
        ?array $entry,
        string $expectedCategory,
        bool $wasTranslated,
        string $note = 'dictionary'
    ): array {
        $category = $entry['category'] ?? $expectedCategory;
        if ($expectedCategory === 'auto' && $entry !== null) {
            $expectedCategory = $category;
        }
        $status = $entry !== null ? 'matched' : ($wasTranslated ? 'matched' : 'unmatched');
        $inputLanguage = 'english';
        if ($wasTranslated) {
            $inputLanguage = 'hiligaynon';
        } elseif (
            $entry !== null
            && mb_strtolower((string) $entry['local_term']) !== mb_strtolower((string) $entry['english_term'])
        ) {
            $inputLanguage = 'hiligaynon';
        }

        return [
            'local_term'           => $localTerm,
            'english_term'         => $english,
            'match_term'           => $english,
            'category'             => $category,
            'dictionary_id'        => $entry['dictionary_id'] ?? null,
            'status'               => $status,
            'category_match'       => ($expectedCategory === 'auto' || $category === $expectedCategory),
            'ready_for_validation' => true,
            'translation_note'     => $note,
            'input_language'       => $inputLanguage,
            'was_translated'       => $wasTranslated,
        ];
    }

    /**
     * @param list<array<string, mixed>> $queue
     * @param array<string, mixed> $item
     * @param array<string, bool> $seenEnglish
     */
    private static function appendQueueItem(array &$queue, array $item, array &$seenEnglish): void
    {
        if (empty($item['ready_for_validation'])) {
            return;
        }
        $localTerm = trim((string) ($item['local_term'] ?? ''));
        $matchTerm = trim((string) ($item['match_term'] ?? $item['english_term'] ?? ''));
        if ($matchTerm === '') {
            return;
        }
        if (
            !MedicalTermFilter::isMedicalTerm($localTerm)
            && !MedicalTermFilter::isMedicalTerm($matchTerm)
        ) {
            return;
        }
        $key = mb_strtolower($matchTerm);
        if (isset($seenEnglish[$key])) {
            return;
        }
        $seenEnglish[$key] = true;
        $queue[] = [
            'local_term'     => $item['local_term'],
            'english_term'   => $item['english_term'],
            'match_term'     => $matchTerm,
            'category'       => $item['category'],
            'status'         => $item['status'],
            'input_language' => $item['input_language'] ?? 'unknown',
            'was_translated' => (bool) ($item['was_translated'] ?? false),
        ];
    }

    /** @return list<string> */
    private static function splitEnglishPhrases(string $english): array
    {
        $parts = preg_split('/\s*,\s*/', $english);
        $out = [];
        foreach ($parts ?: [] as $part) {
            $part = trim((string) $part);
            if ($part !== '') {
                $out[] = $part;
            }
        }

        return $out;
    }

    private static function overallStatus(int $matched, int $unmatched, int $total): string
    {
        if ($total === 0) {
            return 'empty';
        }
        if ($unmatched === 0) {
            return 'complete';
        }
        if ($matched === 0) {
            return 'unmatched';
        }

        return 'partial';
    }

    private static function statusLabel(string $status, int $matched, int $total): string
    {
        return match ($status) {
            'complete'  => "All terms translated to English ({$matched}/{$total})",
            'partial'   => "Partial translation to English ({$matched}/{$total})",
            'unmatched' => 'Could not map all terms to English via dictionary',
            'empty'     => 'No keywords to translate',
            default     => $status,
        };
    }

    public static function overallStatusPublic(int $matched, int $unmatched, int $total): string
    {
        return self::overallStatus($matched, $unmatched, $total);
    }

    public static function statusLabelPublic(string $status, int $matched, int $total): string
    {
        return self::statusLabel($status, $matched, $total);
    }
}
