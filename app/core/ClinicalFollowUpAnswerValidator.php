<?php
/**
 * Validates that a follow-up answer actually answers the current clinical question.
 *
 * Does not diagnose or triage. Invalid answers must not update clinical fields
 * or advance the interview.
 *
 * Order: empty/smash → typo correction → existing extractors → local clinical lexicon
 * → optional Gemini for leftover ambiguous text.
 */
final class ClinicalFollowUpAnswerValidator
{
    /**
     * @param array<string, mixed> $context Interview context
     * @return array{
     *   accept: bool,
     *   retry: bool,
     *   empty: bool,
     *   corrected_answer: string,
     *   reason: string,
     *   expected_field: string,
     *   message: string,
     *   extracted: array<string, mixed>,
     *   answer_class: string,
     *   polarity: string|null
     * }
     */
    public static function validate(string $answer, string $questionId, array $context): array
    {
        $qid = strtoupper(trim($questionId));
        $raw = trim($answer);
        $lang = self::langKey($context);
        $kind = self::expectedKind($qid);

        if ($raw === '') {
            return self::reject($qid, $kind, '', true, 'empty', self::emptyMessage($lang), 'UNCLEAR');
        }

        $corrected = self::correctTypos($raw, $qid);
        $low = mb_strtolower($corrected);

        if (self::isSmash($low)) {
            return self::reject($qid, $kind, $corrected, false, 'nonsense', self::retryMessage($lang), 'PRANK_OR_NON_MEDICAL');
        }

        if (self::looksPrankOrNonMedical($low)) {
            return self::reject($qid, $kind, $corrected, false, 'prank', self::retryMessage($lang), 'PRANK_OR_NON_MEDICAL');
        }

        // Uncertainty itself answers the question ("I don't know") — never reject as unrelated.
        if (class_exists('ClinicalFeatureExtractors') && ClinicalFeatureExtractors::looksPatientUncertain($corrected)) {
            return self::accept(
                $qid,
                $kind,
                $corrected,
                'patient_uncertain',
                [
                    'patient_uncertain' => true,
                    'onset' => ClinicalFeatureExtractors::extractOnset($corrected),
                    'duration' => (string) (ClinicalFeatureExtractors::extractDuration($corrected)['label'] ?? ''),
                ],
                'VALID_UNCERTAIN',
                'uncertain'
            );
        }

        // Conditional / hedged replies ("depende", "minsan") are valid partial answers.
        if (class_exists('ClinicalFeatureExtractors') && ClinicalFeatureExtractors::looksConditionalPartial($corrected)) {
            return self::accept(
                $qid,
                $kind,
                $corrected,
                'conditional_partial',
                [
                    'patient_conditional' => true,
                    'onset' => ClinicalFeatureExtractors::extractOnset($corrected),
                    'duration' => (string) (ClinicalFeatureExtractors::extractDuration($corrected)['label'] ?? ''),
                ],
                'VALID_PARTIAL',
                'partial'
            );
        }

        $decision = self::phpFieldDecision($kind, $qid, $low, $corrected, $context);
        if ($decision === 'accept') {
            $extracted = self::extractPayload($kind, $corrected);
            $classMeta = self::classifyAnswer($kind, $corrected, $extracted);
            return self::accept(
                $qid,
                $kind,
                $corrected,
                'php_field_match',
                $extracted,
                $classMeta['answer_class'],
                $classMeta['polarity']
            );
        }
        if ($decision === 'reject') {
            return self::reject($qid, $kind, $corrected, false, 'unrelated', self::retryMessage($lang), 'UNRELATED');
        }

        $gemini = self::askGemini($raw, $corrected, $qid, $kind, $context);
        if (is_array($gemini) && !empty($gemini['available'])) {
            $relevant = !empty($gemini['is_relevant']) && !empty($gemini['answers_question']);
            $use = trim((string) ($gemini['corrected_answer'] ?? $corrected));
            $extracted = is_array($gemini['extracted_information'] ?? null)
                ? $gemini['extracted_information']
                : self::extractPayload($kind, $use !== '' ? $use : $corrected);
            $class = strtoupper(trim((string) ($gemini['answer_class'] ?? '')));
            $polarity = isset($gemini['polarity']) ? strtolower(trim((string) $gemini['polarity'])) : null;
            $validClasses = ['VALID_POSITIVE', 'VALID_NEGATIVE', 'VALID_PARTIAL', 'VALID_UNCERTAIN', 'VALID_UNKNOWN', 'UNCLEAR', 'UNRELATED', 'PRANK_OR_NON_MEDICAL'];
            if (!in_array($class, $validClasses, true)) {
                $classMeta = self::classifyAnswer($kind, $use !== '' ? $use : $corrected, $extracted);
                $class = $classMeta['answer_class'];
                $polarity = $polarity ?: $classMeta['polarity'];
            }
            if ($class === 'PRANK_OR_NON_MEDICAL') {
                return self::reject(
                    $qid,
                    $kind,
                    $corrected,
                    false,
                    (string) ($gemini['reason'] ?? 'gemini_prank'),
                    self::retryMessage($lang),
                    'PRANK_OR_NON_MEDICAL'
                );
            }
            // Uncertainty / unknown / partial answers are valid even when Gemini is hesitant on answers_question.
            $acceptClass = in_array($class, ['VALID_POSITIVE', 'VALID_NEGATIVE', 'VALID_PARTIAL', 'VALID_UNCERTAIN', 'VALID_UNKNOWN'], true);
            if (($relevant || $acceptClass) && $class !== 'UNRELATED') {
                if ($class === 'UNCLEAR') {
                    $class = 'VALID_UNCERTAIN';
                    $polarity = $polarity ?: 'uncertain';
                    $extracted['patient_uncertain'] = true;
                }
                if (in_array($class, ['VALID_UNCERTAIN', 'VALID_UNKNOWN'], true)) {
                    $extracted['patient_uncertain'] = true;
                    $polarity = $polarity ?: 'uncertain';
                }

                return self::accept(
                    $qid,
                    $kind,
                    $use !== '' ? $use : $corrected,
                    'gemini_relevant',
                    $extracted,
                    $class,
                    $polarity
                );
            }

            return self::reject(
                $qid,
                $kind,
                $corrected,
                false,
                (string) ($gemini['reason'] ?? 'gemini_unrelated'),
                self::retryMessage($lang),
                'UNRELATED'
            );
        }

        // Gemini unavailable: accept local field evidence, onset-style replies, or fail-open partials.
        if (self::hasExpectedFieldEvidence($kind, $low, $corrected)) {
            $extracted = self::extractPayload($kind, $corrected);
            $classMeta = self::classifyAnswer($kind, $corrected, $extracted);
            return self::accept(
                $qid,
                $kind,
                $corrected,
                'local_field_evidence',
                $extracted,
                $classMeta['answer_class'],
                $classMeta['polarity']
            );
        }

        // Plausible short clinical reply that local rules didn't classify — do not reject.
        if (!self::looksOffTopic($low) && mb_strlen(trim($low)) >= 2) {
            $extracted = self::extractPayload($kind, $corrected);
            $classMeta = self::classifyAnswer($kind, $corrected, $extracted);
            $class = $classMeta['answer_class'] === 'UNCLEAR' ? 'VALID_PARTIAL' : $classMeta['answer_class'];

            return self::accept(
                $qid,
                $kind,
                $corrected,
                'local_failopen_partial',
                $extracted,
                $class,
                $classMeta['polarity'] ?: 'partial'
            );
        }

        return self::reject($qid, $kind, $corrected, false, 'uncertain_unrelated', self::retryMessage($lang), 'UNRELATED');
    }

    public static function retryMessage(string $langKey): string
    {
        return match (strtolower($langKey)) {
            'hiligaynon', 'ilonggo' => 'Palihog sabta ang pamangkot sa ibabaw kag magsabat nga may kaangtanan sa imo ginabatyag. Pwede mo liwaton.',
            'tagalog', 'filipino' => 'Pakibigay ang sagot na may kaugnayan sa iyong nararamdaman at sa tanong sa itaas. Maaari mong subukan muli.',
            default => 'Please provide an answer related to your current symptom and the question above. You can try again.',
        };
    }

    public static function emptyMessage(string $langKey): string
    {
        return match (strtolower($langKey)) {
            'hiligaynon', 'ilonggo' => 'Palihog hatag sang sabat sa pamangkot sa ibabaw.',
            'tagalog', 'filipino' => 'Pakibigay ang sagot sa tanong sa itaas.',
            default => 'Please provide an answer to the question above.',
        };
    }

    private static function expectedKind(string $qid): string
    {
        if (str_contains($qid, 'SEVERITY') || $qid === 'PAIN_SEVERITY') {
            return 'PAIN_SEVERITY';
        }
        if (in_array($qid, ['ONSET', 'DURATION'], true) || str_contains($qid, 'DURATION') || str_contains($qid, 'ONSET')) {
            return 'SYMPTOM_DURATION';
        }
        if (str_contains($qid, 'LOCATION') || str_contains($qid, 'WHERE') || str_contains($qid, 'SITE')
            || str_contains($qid, 'LATERALITY') || $qid === 'UNWELL_WHAT'
        ) {
            return 'PAIN_LOCATION';
        }
        if (str_contains($qid, 'ASSOCIATED') || str_contains($qid, 'NEURO') || str_contains($qid, 'BLEEDING')
            || str_contains($qid, 'FEVER_CONFIRM') || str_contains($qid, 'BREATHING')
            || str_contains($qid, 'CHEST') || str_contains($qid, 'TYPE') || str_contains($qid, 'DETAIL')
            || str_contains($qid, 'VISION') || str_contains($qid, 'COUGH') || str_contains($qid, 'URINARY')
        ) {
            return 'ASSOCIATED_SYMPTOMS';
        }

        return $qid !== '' ? $qid : 'GENERAL';
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function phpFieldDecision(string $kind, string $qid, string $low, string $corrected, array $context): string
    {
        $extracts = self::extractorUnderstands($kind, $corrected);
        $local = self::localSemanticRelevant($corrected, $qid, $kind);
        $offTopic = self::looksOffTopic($low);
        $wrongField = self::answersDifferentField($kind, $low, $corrected, $context);

        if ($kind === 'PAIN_SEVERITY') {
            if (self::looksAgeOrUnrelatedNumber($low) && !$extracts) {
                return 'reject';
            }
            if (self::looksYesNo($low)) {
                return 'reject';
            }
            if ($wrongField && !$extracts && !self::looksPainSeverity($low)) {
                return 'reject';
            }
            if ($extracts || $local || self::looksPainSeverity($low)) {
                return 'accept';
            }

            return $offTopic ? 'reject' : 'uncertain';
        }

        if ($kind === 'SYMPTOM_DURATION') {
            if ($extracts || $local || self::looksTiming($low) || self::looksOnsetStyle($low)) {
                return 'accept';
            }
            if ($wrongField || $offTopic) {
                return 'reject';
            }

            return 'uncertain';
        }

        if ($kind === 'PAIN_LOCATION') {
            if ($extracts || $local || self::looksLocation($low)) {
                return 'accept';
            }
            if ($wrongField || $offTopic) {
                return 'reject';
            }

            return 'uncertain';
        }

        if ($kind === 'ASSOCIATED_SYMPTOMS') {
            if (self::looksYesNo($low) || self::looksAssociated($low) || $extracts || $local) {
                return 'accept';
            }
            if ($offTopic) {
                return 'reject';
            }

            return 'uncertain';
        }

        if ($offTopic && !$extracts && !$local && !self::hasExpectedFieldEvidence($kind, $low, $corrected)) {
            return 'reject';
        }

        return 'accept';
    }

    private static function extractorUnderstands(string $kind, string $text): bool
    {
        if (!class_exists('ClinicalFeatureExtractors')) {
            return false;
        }
        $text = trim($text);
        if ($text === '') {
            return false;
        }

        try {
            return match ($kind) {
                'PAIN_SEVERITY' => (
                    (ClinicalFeatureExtractors::extractPainScale($text)['score'] ?? null) !== null
                    || ClinicalFeatureExtractors::extractStandalonePainScore($text, true) !== null
                ),
                'SYMPTOM_DURATION' => (
                    trim((string) (ClinicalFeatureExtractors::extractDuration($text)['label'] ?? '')) !== ''
                    || ClinicalFeatureExtractors::extractOnset($text) !== ''
                ),
                'PAIN_LOCATION' => ClinicalFeatureExtractors::extractBodyLocations($text) !== [],
                'ASSOCIATED_SYMPTOMS' => (
                    ClinicalFeatureExtractors::deniedAssociatedSymptoms($text)
                    || ClinicalFeatureExtractors::extractYesNo($text) !== null
                ),
                default => ClinicalFeatureExtractors::extractYesNo($text) !== null,
            };
        } catch (Throwable) {
            return false;
        }
    }

    private static function localSemanticRelevant(string $text, string $qid, string $kind): bool
    {
        if (class_exists('NlpStep3DemoGeminiAnswerInterpreter')) {
            try {
                $interp = NlpStep3DemoGeminiAnswerInterpreter::tryLocalSemanticInterpretation($text, $qid);
                if (is_array($interp)) {
                    if (!empty($interp['relevant']) && strtoupper((string) ($interp['answer_type'] ?? '')) !== 'UNRELATED') {
                        return true;
                    }
                    if (strtoupper((string) ($interp['answer_type'] ?? '')) === 'UNRELATED') {
                        return false;
                    }
                }
            } catch (Throwable) {
                // fall through to local lexicon
            }
        }

        $low = mb_strtolower($text);
        if ($kind === 'SYMPTOM_DURATION' && self::looksTiming($low)) {
            return true;
        }
        if ($kind === 'PAIN_SEVERITY' && self::looksPainSeverity($low)) {
            return true;
        }
        if ($kind === 'PAIN_LOCATION' && self::looksLocation($low)) {
            return true;
        }
        if ($kind === 'ASSOCIATED_SYMPTOMS' && (self::looksAssociated($low) || self::looksYesNo($low))) {
            return true;
        }

        return false;
    }

    /**
     * Patient answered a different clinical slot than the one being asked.
     *
     * @param array<string, mixed> $context
     */
    private static function answersDifferentField(string $kind, string $low, string $corrected, array $context): bool
    {
        unset($context);
        if (!class_exists('ClinicalFeatureExtractors')) {
            return self::looksWrongFieldLexically($kind, $low);
        }

        $score = ClinicalFeatureExtractors::extractStandalonePainScore($corrected, $kind === 'PAIN_SEVERITY')
            ?? (ClinicalFeatureExtractors::extractPainScale($corrected)['score'] ?? null);
        $duration = trim((string) (ClinicalFeatureExtractors::extractDuration($corrected)['label'] ?? ''));
        $onset = ClinicalFeatureExtractors::extractOnset($corrected);
        $locs = ClinicalFeatureExtractors::extractBodyLocations($corrected);
        $hasQualSeverity = self::looksPainSeverity($low);
        $hasTiming = $duration !== '' || $onset !== '' || self::looksTiming($low);
        $hasLocation = $locs !== [] || self::looksLocation($low);
        $newComplaint = (bool) preg_match(
            '/\b(diarrhea|lbm|libun|tae|suka|vomit|hilo|dizzy|cough|ubo|rash|lagnat|hilanat|fever)\b/u',
            $low
        );

        if ($kind === 'PAIN_SEVERITY') {
            if ($score !== null || $hasQualSeverity) {
                return false;
            }
            // Location or a different symptom is not a 0–10 answer.
            return $hasLocation || ($newComplaint && !$hasQualSeverity);
        }

        if ($kind === 'SYMPTOM_DURATION') {
            if ($hasTiming) {
                return false;
            }

            return ($score !== null && $score <= 10 && !self::looksTiming($low))
                || ($hasLocation && !$hasTiming && mb_strlen($low) < 40 && $newComplaint);
        }

        if ($kind === 'PAIN_LOCATION') {
            if ($hasLocation) {
                return false;
            }

            return $hasTiming && !$hasLocation && $score !== null;
        }

        return false;
    }

    private static function looksWrongFieldLexically(string $kind, string $low): bool
    {
        if ($kind === 'PAIN_SEVERITY') {
            return self::looksLocation($low) && !self::looksPainSeverity($low);
        }
        if ($kind === 'SYMPTOM_DURATION') {
            return self::looksPainSeverity($low) && !self::looksTiming($low);
        }

        return false;
    }

    private static function hasExpectedFieldEvidence(string $kind, string $low, string $corrected): bool
    {
        return match ($kind) {
            'PAIN_SEVERITY' => self::looksPainSeverity($low) || self::extractorUnderstands($kind, $corrected),
            'SYMPTOM_DURATION' => self::looksTiming($low) || self::looksOnsetStyle($low) || self::extractorUnderstands($kind, $corrected),
            'PAIN_LOCATION' => self::looksLocation($low) || self::extractorUnderstands($kind, $corrected),
            'ASSOCIATED_SYMPTOMS' => self::looksYesNo($low) || self::looksAssociated($low) || self::extractorUnderstands($kind, $corrected),
            default => self::looksYesNo($low) || self::extractorUnderstands($kind, $corrected),
        };
    }

    /** @return array<string, mixed> */
    private static function extractPayload(string $kind, string $text): array
    {
        $payload = [];
        if (!class_exists('ClinicalFeatureExtractors')) {
            return $payload;
        }
        try {
            $payload = match ($kind) {
                'PAIN_SEVERITY' => [
                    'pain_severity' => ClinicalFeatureExtractors::extractStandalonePainScore($text, true)
                        ?? (ClinicalFeatureExtractors::extractPainScale($text)['score'] ?? null),
                    'pain_qualifier' => ClinicalFeatureExtractors::extractPainQualifier($text),
                ],
                'SYMPTOM_DURATION' => [
                    'duration' => (string) (ClinicalFeatureExtractors::extractDuration($text)['label'] ?? ''),
                    'onset' => ClinicalFeatureExtractors::extractOnset($text),
                ],
                'PAIN_LOCATION' => [
                    'body_locations' => ClinicalFeatureExtractors::extractBodyLocations($text),
                ],
                default => [],
            };
            $yn = ClinicalFeatureExtractors::extractYesNo($text);
            if ($yn !== null) {
                $payload['yes_no'] = $yn;
                if ($yn === false) {
                    $payload['denied'] = true;
                }
            }
            if ($kind === 'ASSOCIATED_SYMPTOMS' && ClinicalFeatureExtractors::deniedAssociatedSymptoms($text)) {
                $payload['denied_associated'] = true;
                $payload['has_other_symptoms'] = false;
            }
        } catch (Throwable) {
            return $payload;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $extracted
     * @return array{answer_class: string, polarity: string|null}
     */
    private static function classifyAnswer(string $kind, string $text, array $extracted): array
    {
        $yn = null;
        if (array_key_exists('yes_no', $extracted)) {
            $yn = (bool) $extracted['yes_no'];
        } elseif (class_exists('ClinicalFeatureExtractors')) {
            $yn = ClinicalFeatureExtractors::extractYesNo($text);
        }

        if ($yn === true) {
            return ['answer_class' => 'VALID_POSITIVE', 'polarity' => 'positive'];
        }
        if ($yn === false || !empty($extracted['denied']) || !empty($extracted['denied_associated'])) {
            return ['answer_class' => 'VALID_NEGATIVE', 'polarity' => 'negative'];
        }
        if (!empty($extracted['patient_uncertain'])
            || (class_exists('ClinicalFeatureExtractors') && ClinicalFeatureExtractors::looksPatientUncertain($text))
        ) {
            return ['answer_class' => 'VALID_UNCERTAIN', 'polarity' => 'uncertain'];
        }

        $hasFact = false;
        foreach ($extracted as $key => $value) {
            if ($key === 'yes_no' || $key === 'denied' || $key === 'denied_associated') {
                continue;
            }
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $hasFact = true;
            break;
        }

        if ($kind === 'PAIN_SEVERITY' && $hasFact) {
            $score = $extracted['pain_severity'] ?? null;
            $qual = trim((string) ($extracted['pain_qualifier'] ?? ''));
            if ($score === null && $qual !== '') {
                return ['answer_class' => 'VALID_PARTIAL', 'polarity' => 'partial'];
            }

            return ['answer_class' => 'VALID_POSITIVE', 'polarity' => 'positive'];
        }

        if ($hasFact) {
            return ['answer_class' => 'VALID_POSITIVE', 'polarity' => 'positive'];
        }

        if (trim($text) !== '') {
            return ['answer_class' => 'VALID_PARTIAL', 'polarity' => 'partial'];
        }

        return ['answer_class' => 'UNCLEAR', 'polarity' => null];
    }

    private static function looksYesNo(string $low): bool
    {
        if (!class_exists('ClinicalFeatureExtractors')) {
            return false;
        }

        return ClinicalFeatureExtractors::extractYesNo($low) !== null;
    }

    private static function looksPainSeverity(string $low): bool
    {
        if (preg_match('/\b(10|[0-9]|zero|one|two|three|four|five|six|seven|eight|nine|ten)\s*(days?|weeks?|hours?|months?|years?|adlaw|semana|linggo|oras|bulan|tuig|taon|araw|buwan)\b/u', $low)) {
            return false;
        }
        if (preg_match('/\b(10|[0-9])\s*(\/|out of|sa|tubtob|hanggang)?\s*10\b/u', $low)) {
            return true;
        }
        if (preg_match('/^(?:it(?:\'s| is)?\s+)?(?:about|around|maybe|like|almost)?\s*(?:a\s+)?(10|[0-9])(?:\s*(?:\/\s*10|out of\s*10))?\.?$/u', $low)) {
            return true;
        }
        if (preg_match('/\b(around|about|maybe|like|almost|is)\s+(?:a\s+)?(10|[0-9])\b/u', $low)
            && !preg_match('/\b(year|years|old|tuig|taon|kids?|children|apples?)\b/u', $low)
        ) {
            return true;
        }
        $words = 'zero|one|two|three|four|five|six|seven|eight|nine|ten|isa|duha|tatlo|apat|lima|anom|unom|anim|pito|walo|siyam|napulo|sampu';
        if (preg_match('/^(' . $words . ')(?:\s*(?:\/\s*10|out of\s*10|sa\s*10))?\.?$/u', $low)) {
            return true;
        }
        if (preg_match('/\b(' . $words . ')\s*(?:\/\s*10|out of\s*10|sa\s*10)\b/u', $low)) {
            return true;
        }

        return (bool) preg_match(
            '/\b(severe|moderate|mild|worst|grabe|kagrabe|pinakagrabe|masakit kaayo|sobrang sakit|matindi|'
            . 'very painful|hurts a lot|hurts badly|painful|unbearable|bearable|light pain|malala|'
            . 'gamay lang|medyo|tunga-tunga)\b/u',
            $low
        );
    }

    private static function looksAgeOrUnrelatedNumber(string $low): bool
    {
        return (bool) preg_match('/\b(dog|ido|years?\s+old|taon|tuig|school|eskwela|phone|pizza|color|kulay|basketball|kids?|children)\b/u', $low)
            && !preg_match('/\b(out of\s*10|\/\s*10)\b/u', $low);
    }

    private static function looksOnsetStyle(string $low): bool
    {
        $expanded = (string) preg_replace_callback(
            '/\b([a-z]{2,})2\b/u',
            static fn (array $m): string => $m[1] . '-' . $m[1],
            $low
        );
        if (class_exists('ClinicalFeatureExtractors') && ClinicalFeatureExtractors::extractOnset($expanded) !== '') {
            return true;
        }

        return (bool) preg_match(
            '/\b(gulpi|kalit|sudden|suddenly|bigla|hinay-hinay|hinay|gradual|gradually|slowly|unti-unti|unti)\b/u',
            $expanded
        );
    }

    private static function looksTiming(string $low): bool
    {
        if (self::looksOnsetStyle($low)) {
            return true;
        }
        if (preg_match(
            '/\b(yesterday|yesturday|today|tonight|last\s+night|this\s+morning|this\s+afternoon|this\s+evening|'
            . 'kanina|gahapon|kahapon|kagapon|kagab-i|kagabi|subong|ngayon|halin|since|'
            . 'ligad|dugay|matagal|bag-o\s+lang|just\s+(now|started)|last\s+week|last\s+month|'
            . 'a\s+few\s+days|couple\s+of\s+days|almost\s+a\s+week|about\s+a\s+week|for\s+a\s+week|'
            . 'about\s+\d+|for\s+\d+|mga\s+\d+|mga\s+(isa|duha|tatlo|apat|lima)|'
            . 'monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b/u',
            $low
        )) {
            return true;
        }

        return (bool) preg_match(
            '/\b(\d+|one|two|three|four|five|six|seven|eight|nine|ten|isa|duha|dalawa|dalawang|tatlo|tulo|apat|lima|'
            . 'anom|anim|pito|walo|siyam)\s*(?:na\s+)?(?:ka\s+)?(days?|adlaw|araw|weeks?|semana|linggo|'
            . 'hours?|oras|months?|bulan|buwan)\b/u',
            $low
        );
    }

    private static function looksLocation(string $low): bool
    {
        if (preg_match(
            '/\b(ulo|olo|head|forehead|temple|dughan|chest|dibdib|tiyan|stomach|abdomen|belly|'
            . 'likod|lower back|my back|the back|mata|eye|neck|liog|leeg|ilong|nose|'
            . 'kamot|hand|tiil|leg|all over|whole head|tanan)\b/u',
            $low
        )) {
            return true;
        }

        return (bool) preg_match(
            '/\b(left|right|wala|tuo|kaliwa|kanan)\b/u',
            $low
        ) && (bool) preg_match('/\b(side|bahin|parte|part|portion|ulo|head|dughan|tiyan)\b/u', $low);
    }

    private static function looksAssociated(string $low): bool
    {
        return (bool) preg_match(
            '/\b(hilo|dizzy|dizziness|suka|vomit|nausea|fever|hilanat|lagnat|ubo|cough|rash|'
            . 'weak|weakness|diarrhea|lbm|libun|chills|sipon|runny|none|nothing|no other|'
            . 'wala na|walay iban|walang iba|only|lang|dry cough|productive)\b/u',
            $low
        ) || self::looksYesNo($low);
    }

    private static function looksOffTopic(string $low): bool
    {
        if (preg_match('/^(blue|red|green|yellow|pink|ok|lol|haha+|idk)$/u', $low)) {
            return true;
        }
        // Shopping / price questions during clinical interview are unrelated (not a symptom dictionary).
        if (preg_match('/\b(presyo|magkano|how\s+much|price\s+of|sapatos|shopping)\b/u', $low)) {
            return true;
        }

        return (bool) preg_match(
            '/\b(basketball|football|soccer|pizza|burger|phone|cellphone|wifi|facebook|color|kulay|'
            . 'favorite|eskwela|school|student|homework|dog|ido|cat|movie|song|youtube|'
            . 'broken|sira|utod naga-?eskwela|what time is it|i like|mahilig|maglaro)\b/u',
            $low
        );
    }

    /**
     * Genuine joke/spam with no recoverable clinical meaning — not short clinical replies.
     */
    private static function looksPrankOrNonMedical(string $low): bool
    {
        $t = trim($low);
        if ($t === '') {
            return false;
        }
        // Laugh runs: haha / hehe / hihi (repeated "ha"/"he"/"hi" syllables), lol, lmao, jk.
        if (preg_match('/^(?:(?:ha){2,}|(?:he){2,}|(?:hi){2,}|lol+|lmao+|whee+|jk)+$/u', $t)) {
            return true;
        }
        $hasLaugh = (bool) preg_match('/\b(?:(?:ha){2,}|(?:he){2,}|(?:hi){2,}|lol+|lmao+|jk)\b/u', $t);
        if (!$hasLaugh) {
            return false;
        }
        // Laugh spam mixed with no yes/no, timing, body, or uncertainty language → prank.
        if (self::looksYesNo($t)
            || self::looksTiming($t)
            || self::looksOnsetStyle($t)
            || self::looksLocation($t)
            || self::looksAssociated($t)
            || self::looksPainSeverity($t)
            || (class_exists('ClinicalFeatureExtractors') && (
                ClinicalFeatureExtractors::looksPatientUncertain($t)
                || ClinicalFeatureExtractors::looksConditionalPartial($t)
            ))
        ) {
            return false;
        }

        // Multiple tokens of laugh + unrelated chatter
        return mb_strlen($t) >= 10;
    }

    private static function isSmash(string $low): bool
    {
        $compact = preg_replace('/\s+/u', '', $low) ?? $low;
        if (preg_match('/^(asdfg?h?l?|qwerty|zxcvbn|qazwsx|abcdefghijklm?)$/u', $compact)) {
            return true;
        }
        if (preg_match('/^(asdf+|qwer+|zxcv+|hhhh+|zzzz+|aaaa+|xyz+\d*)$/u', $compact)) {
            return true;
        }

        return mb_strlen($compact) >= 6
            && (bool) preg_match('/^[bcdfghjklmnpqrstvwxyz]{6,}$/u', $compact)
            && !preg_match('/[aeiou]/u', $compact);
    }

    private static function applyKnownTypos(string $text): string
    {
        $out = $text;
        $repl = [
            'yesturday' => 'yesterday',
            'yesteday' => 'yesterday',
            'yeserday' => 'yesterday',
            'sevun' => 'seven',
            'sevn' => 'seven',
            'kagapong' => 'gahapon',
            'kagahapon' => 'gahapon',
        ];
        foreach ($repl as $from => $to) {
            $out = preg_replace('/\b' . preg_quote($from, '/') . '\b/ui', $to, $out) ?? $out;
        }
        // Compact "diko" / "indiko" spacing (uncertainty / negation clitics).
        $out = preg_replace('/\bdi\s*ko\b/ui', 'di ko', $out) ?? $out;
        $out = preg_replace('/\bindi\s*ko\b/ui', 'indi ko', $out) ?? $out;
        $out = preg_replace('/\bhindi\s*ko\b/ui', 'hindi ko', $out) ?? $out;
        // PH texting reduplication: word2 → word-word (hinay2, unti2, etc.).
        $out = preg_replace_callback(
            '/\b([A-Za-zÀ-ÿ]{2,})2\b/u',
            static fn (array $m): string => $m[1] . '-' . $m[1],
            $out
        ) ?? $out;

        return $out;
    }

    private static function correctTypos(string $raw, string $qid): string
    {
        $working = self::applyKnownTypos($raw);
        try {
            if (class_exists('NlpStep3DemoAnswerFuzzy')) {
                $prep = NlpStep3DemoAnswerFuzzy::prepare($working, $qid);
                $next = trim((string) ($prep['corrected'] ?? ''));
                if ($next !== '') {
                    $working = $next;
                }
            }
        } catch (Throwable) {
            // keep working
        }

        return mb_strtolower(trim(self::applyKnownTypos($working)));
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>|null
     */
    private static function askGemini(string $raw, string $corrected, string $qid, string $kind, array $context): ?array
    {
        if (!class_exists('GeminiComplaintInputValidator') || !GeminiComplaintInputValidator::enabled()) {
            return null;
        }

        $held = is_array($context['last_followup_question'] ?? null) ? $context['last_followup_question'] : [];
        $facts = is_array($context['facts'] ?? null) ? $context['facts'] : [];
        $priorQa = [];
        foreach ((array) ($context['questions_answered'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $priorQa[] = [
                'question_id' => (string) ($row['question_id'] ?? ''),
                'answer' => (string) ($row['answer'] ?? ''),
            ];
        }
        try {
            return GeminiComplaintInputValidator::validateFollowUpAnswer([
                'primary_complaint' => (string) ($context['chief_complaint'] ?? ''),
                'normalized_complaint' => json_encode($context['chief_complaints'] ?? [], JSON_UNESCAPED_UNICODE),
                'question' => (string) ($held['text'] ?? $qid),
                'expected_field' => $kind,
                'question_id' => $qid,
                'answer' => $raw,
                'corrected_answer' => $corrected,
                'language' => self::langKey($context),
                'known_facts' => $facts,
                'previous_qa' => $priorQa,
                'patient_turns' => array_slice((array) ($context['patient_turns'] ?? []), -6),
            ]);
        } catch (Throwable $e) {
            error_log('ClinicalFollowUpAnswerValidator Gemini fallback: ' . $e->getMessage());

            return null;
        }
    }

    /** @param array<string, mixed> $context */
    private static function langKey(array $context): string
    {
        $q = strtolower((string) ($context['question_language'] ?? $context['detected_language'] ?? 'english'));
        if (str_contains($q, 'tagalog') || str_contains($q, 'filipino')) {
            return 'tagalog';
        }
        if (str_contains($q, 'english')) {
            return 'english';
        }
        if (str_contains($q, 'hiligaynon') || str_contains($q, 'ilonggo') || $q === 'mixed') {
            return 'hiligaynon';
        }

        return 'english';
    }

    /**
     * @param array<string, mixed> $extracted
     * @return array<string, mixed>
     */
    private static function accept(
        string $qid,
        string $kind,
        string $corrected,
        string $reason,
        array $extracted = [],
        string $answerClass = 'VALID_POSITIVE',
        ?string $polarity = 'positive'
    ): array {
        return [
            'accept' => true,
            'retry' => false,
            'empty' => false,
            'corrected_answer' => $corrected,
            'reason' => $reason,
            'expected_field' => $kind !== '' ? $kind : $qid,
            'message' => '',
            'extracted' => $extracted,
            'answer_class' => $answerClass,
            'polarity' => $polarity,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function reject(
        string $qid,
        string $kind,
        string $corrected,
        bool $empty,
        string $reason,
        string $message,
        string $answerClass = 'UNRELATED'
    ): array {
        return [
            'accept' => false,
            'retry' => true,
            'empty' => $empty,
            'corrected_answer' => $corrected,
            'reason' => $reason,
            'expected_field' => $kind !== '' ? $kind : $qid,
            'message' => $message,
            'extracted' => [],
            'answer_class' => $answerClass,
            'polarity' => null,
        ];
    }
}
