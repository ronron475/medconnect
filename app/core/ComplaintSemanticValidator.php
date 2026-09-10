<?php
/**
 * Combines existing PHP domain NLP with optional Gemini semantic validation.
 *
 * Gemini is NOT triage. Final clinical flow stays in ClinicalInterviewEngine /
 * ClinicalTriageEngine. This class only decides VALID vs INVALID medical input.
 */
final class ComplaintSemanticValidator
{
    public const CLASS_VALID = 'VALID_MEDICAL_COMPLAINT';
    public const CLASS_INVALID = 'INVALID_MEDICAL_INPUT';
    public const STATE_NEEDS_VALID = 'NEEDS_VALID_COMPLAINT';

    /**
     * Opening-complaint gate used before clinical follow-ups / triage.
     *
     * @param array<string, mixed>|null $geminiOverride Injected Gemini result for tests
     * @return array<string, mixed>
     */
    public static function validateOpeningComplaint(string $text, ?array $geminiOverride = null): array
    {
        $raw = trim($text);
        $cleanedPack = class_exists('ComplaintTriageTextCleaner')
            ? ComplaintTriageTextCleaner::prepare($raw)
            : [
                'original' => $raw,
                'cleaned' => $raw,
                'discarded' => [],
                'kept' => $raw !== '' ? [$raw] : [],
                'has_usable_clinical_text' => $raw !== '',
            ];
        $nlpText = trim((string) ($cleanedPack['cleaned'] ?? ''));
        $usable = !empty($cleanedPack['has_usable_clinical_text']);
        $phpSource = $usable && $nlpText !== '' ? $nlpText : $raw;
        $php = class_exists('HealthComplaintDomainDetector')
            ? HealthComplaintDomainDetector::detect($phpSource)
            : [
                'domain' => 'UNCLEAR',
                'confidence' => 'LOW',
                'score' => 0.0,
                'health_related' => false,
                'routing' => 'OUT_OF_SCOPE',
                'signals' => [],
                'relationships' => [],
                'normalized' => mb_strtolower($raw),
                'reason' => 'Domain detector unavailable',
            ];

        $phpEvidence = self::phpEvidenceStrength($php, $phpSource);
        $incompleteJunk = !$usable && self::looksLikeIncompleteJunk($raw);
        if ($incompleteJunk) {
            $phpEvidence = 'none';
        }
        // Always send ORIGINAL patient wording to Gemini. The cleaner may drop unknown
        // Hiligaynon/Visayan symptom words that are still health-related (dataset miss ≠ junk).
        $gemini = $geminiOverride ?? (
            ($incompleteJunk)
                ? [
                    'available' => false,
                    'is_medical_complaint' => null,
                    'classification' => null,
                    'confidence' => null,
                    'error' => 'skipped_incomplete_junk',
                ]
                : (class_exists('GeminiComplaintInputValidator')
                    ? GeminiComplaintInputValidator::validate($raw)
                    : [
                        'available' => false,
                        'is_medical_complaint' => null,
                        'classification' => null,
                        'confidence' => null,
                        'error' => 'class_missing',
                    ])
        );

        $decision = self::combine($phpEvidence, $php, $gemini);
        $lang = self::detectLanguageKey($raw);

        $geminiCorrected = trim((string) ($gemini['corrected_text'] ?? ''));
        $geminiConcept = trim((string) ($gemini['medical_concept'] ?? ''));
        if (!$usable && !$incompleteJunk && !empty($gemini['is_medical_complaint'])) {
            $bridgeSource = $geminiCorrected !== '' && $geminiCorrected !== $raw
                ? $geminiCorrected
                : ($geminiConcept !== '' ? $geminiConcept : '');
            if ($bridgeSource !== '') {
                $retry = class_exists('ComplaintTriageTextCleaner')
                    ? ComplaintTriageTextCleaner::prepare($bridgeSource)
                    : [
                        'cleaned' => $bridgeSource,
                        'has_usable_clinical_text' => true,
                        'discarded' => [],
                        'kept' => [$bridgeSource],
                        'corrections' => [],
                    ];
                if (!empty($retry['has_usable_clinical_text']) || $bridgeSource !== '') {
                    $cleanedPack = is_array($retry) ? $retry : $cleanedPack;
                    $nlpText = trim((string) ($retry['cleaned'] ?? $bridgeSource));
                    if ($nlpText === '') {
                        $nlpText = $bridgeSource;
                    }
                    // Prefer original + concept so existing NLP can match without losing local wording.
                    if ($geminiConcept !== '' && $geminiCorrected === '') {
                        $nlpText = trim($raw . '. ' . $geminiConcept);
                    }
                    $usable = true;
                    $phpSource = $nlpText !== '' ? $nlpText : $raw;
                    if (class_exists('HealthComplaintDomainDetector')) {
                        $php = HealthComplaintDomainDetector::detect($phpSource);
                        $phpEvidence = self::phpEvidenceStrength($php, $phpSource);
                    }
                    $decision = self::combine($phpEvidence, $php, $gemini);
                    if (empty($decision['is_valid']) && !empty($gemini['is_medical_complaint'])) {
                        $decision = [
                            'is_valid' => true,
                            'classification' => self::CLASS_VALID,
                            'reason' => 'Gemini HEALTH_RELATED lexical bridge for unknown local wording',
                        ];
                    }
                }
            } elseif (!empty($gemini['is_medical_complaint'])) {
                // Health-related but no lexical bridge yet — keep original for interview.
                $nlpText = $raw;
                $usable = true;
                $decision = [
                    'is_valid' => true,
                    'classification' => self::CLASS_VALID,
                    'reason' => 'Gemini HEALTH_RELATED — continue interview with original wording',
                ];
            }
        } elseif (!$usable && !$incompleteJunk && $geminiCorrected !== '' && $geminiCorrected !== $raw) {
            // Legacy path kept for non-boolean medical flag payloads.
            $retry = class_exists('ComplaintTriageTextCleaner')
                ? ComplaintTriageTextCleaner::prepare($geminiCorrected)
                : $cleanedPack;
            if (!empty($retry['has_usable_clinical_text'])) {
                $cleanedPack = $retry;
                $nlpText = trim((string) ($retry['cleaned'] ?? $geminiCorrected));
                $usable = true;
                $phpSource = $nlpText !== '' ? $nlpText : $raw;
                if (class_exists('HealthComplaintDomainDetector')) {
                    $php = HealthComplaintDomainDetector::detect($phpSource);
                    $phpEvidence = self::phpEvidenceStrength($php, $phpSource);
                }
                $decision = self::combine($phpEvidence, $php, $gemini);
            }
        }

        $message = $decision['is_valid']
            ? ''
            : self::clarificationMessage($lang);

        // ADDITIVE NLP bridge: when PHP dataset has no confident match but Gemini
        // understood a local health expression, enrich nlp_text for existing engines.
        // Patient-facing original wording is preserved separately by the interview engine.
        if (!empty($decision['is_valid'])) {
            if (trim($nlpText) === '') {
                $nlpText = $raw;
            }
            $nlpText = self::enrichNlpTextForExistingEngine(
                $raw,
                $nlpText,
                $phpEvidence,
                $gemini
            );
        }

        $concept = trim((string) ($gemini['medical_concept'] ?? ''));
        $medicalConcepts = self::conceptLabels($php);
        if ($concept !== '' && !in_array('gemini_bridge:' . $concept, $medicalConcepts, true)) {
            $medicalConcepts[] = 'gemini_bridge:' . $concept;
        }

        $out = [
            'is_valid' => $decision['is_valid'],
            'classification' => $decision['classification'],
            'needs_valid_complaint' => !$decision['is_valid'],
            'workflow_state' => $decision['is_valid'] ? self::CLASS_VALID : self::STATE_NEEDS_VALID,
            'triage_ready' => false,
            'triage_status' => $decision['is_valid'] ? 'NOT_STARTED' : 'NOT_READY',
            'patient_message' => $message,
            'detected_language' => $lang,
            'php_validation_result' => $phpEvidence,
            'php_domain' => $php,
            'gemini_validation_result' => $gemini['available']
                ? (string) ($gemini['classification'] ?? '')
                : 'UNAVAILABLE',
            'gemini_confidence' => $gemini['confidence'] ?? null,
            'gemini' => $gemini,
            'gemini_medical_concept' => $concept,
            'medical_concepts_found' => $medicalConcepts,
            'fuzzy_matches' => self::fuzzyLabels($php),
            'final_validation_result' => $decision['classification'],
            'combine_reason' => $decision['reason'],
            'original_patient_input' => $raw,
            'nlp_text' => $nlpText,
            'typo_corrections' => is_array($cleanedPack['corrections'] ?? null) ? $cleanedPack['corrections'] : [],
            'discarded_tokens' => is_array($cleanedPack['discarded'] ?? null) ? $cleanedPack['discarded'] : [],
            'kept_tokens' => is_array($cleanedPack['kept'] ?? null) ? $cleanedPack['kept'] : [],
        ];

        if (self::debugEnabled()) {
            error_log('ComplaintSemanticValidator: ' . json_encode([
                'original_input' => mb_substr($raw, 0, 120),
                'normalized_input' => $nlpText,
                'php_validation_result' => $out['php_validation_result'],
                'gemini_validation_result' => $out['gemini_validation_result'],
                'gemini_confidence' => $out['gemini_confidence'],
                'gemini_correction' => $gemini['corrected_text'] ?? '',
                'medical_concepts_found' => $out['medical_concepts_found'],
                'fuzzy_matches' => $out['fuzzy_matches'],
                'typo_corrections' => $out['typo_corrections'],
                'final_validation_result' => $out['final_validation_result'],
                'detected_language' => $out['detected_language'],
                'clinical_status' => $out['workflow_state'],
                'triage_ready' => $out['triage_ready'],
                'combine_reason' => $out['combine_reason'],
            ], JSON_UNESCAPED_UNICODE));
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $php
     * @param array<string, mixed> $gemini
     * @return array{is_valid:bool,classification:string,reason:string}
     */
    private static function combine(string $phpEvidence, array $php, array $gemini): array
    {
        $geminiAvailable = !empty($gemini['available']);
        $geminiValid = $geminiAvailable && !empty($gemini['is_medical_complaint']);
        $geminiInvalid = $geminiAvailable && $gemini['is_medical_complaint'] === false;
        $geminiConf = isset($gemini['confidence']) && is_numeric($gemini['confidence'])
            ? (float) $gemini['confidence']
            : null;
        $geminiHigh = $geminiConf !== null && $geminiConf >= 0.75;
        $geminiUncertain = $geminiAvailable && ($geminiConf === null || $geminiConf < 0.55);

        // Strong PHP medical evidence: never block on Gemini alone.
        if ($phpEvidence === 'strong') {
            if ($geminiInvalid && $geminiHigh && self::isFuzzyOnlyWeak($php)) {
                return [
                    'is_valid' => false,
                    'classification' => self::CLASS_INVALID,
                    'reason' => 'Gemini high-confidence invalid overrides fuzzy-only PHP match',
                ];
            }

            return [
                'is_valid' => true,
                'classification' => self::CLASS_VALID,
                'reason' => 'PHP strong medical evidence' . ($geminiUncertain ? ' (Gemini uncertain — allow)' : ''),
            ];
        }

        // Weak / fuzzy-only PHP + Gemini clear invalid → reject nonsense.
        if ($phpEvidence === 'weak' && $geminiInvalid) {
            return [
                'is_valid' => false,
                'classification' => self::CLASS_INVALID,
                'reason' => 'Weak/fuzzy PHP + Gemini INVALID_MEDICAL_INPUT',
            ];
        }

        if ($phpEvidence === 'weak' && $geminiValid) {
            return [
                'is_valid' => true,
                'classification' => self::CLASS_VALID,
                'reason' => 'Gemini VALID with weak PHP evidence — continue NLP',
            ];
        }

        if ($phpEvidence === 'weak' && !$geminiAvailable) {
            // Fuzzy-only / noise-adjacent without Gemini: do not invent a complaint.
            if (self::isFuzzyOnlyWeak($php) || self::phpLooksHardNonMedical($php)) {
                return [
                    'is_valid' => false,
                    'classification' => self::CLASS_INVALID,
                    'reason' => 'Weak PHP only (fuzzy/noise) — Gemini unavailable',
                ];
            }

            return [
                'is_valid' => true,
                'classification' => self::CLASS_VALID,
                'reason' => 'Weak PHP health signals — Gemini unavailable fail-open to NLP',
            ];
        }

        // phpEvidence === none
        if ($geminiValid) {
            return [
                'is_valid' => true,
                'classification' => self::CLASS_VALID,
                'reason' => 'Gemini HEALTH_RELATED / VALID — PHP dataset miss; continue NLP for unknown wording',
            ];
        }

        $geminiClass = strtoupper(str_replace([' ', '-'], '_', (string) ($gemini['classification'] ?? '')));
        $normForCandidate = trim((string) ($php['normalized'] ?? ''));
        $isPrankClass = in_array($geminiClass, [
            'PRANK_OR_NON_MEDICAL',
            'NONSENSE_OR_PRANK',
            'PRANK',
            'NONSENSE',
        ], true);

        // UNCLEAR ≠ reject when utterance still looks like an unknown local health complaint.
        if ($geminiAvailable && ($geminiClass === 'UNCLEAR' || ($gemini['is_medical_complaint'] === null && !$geminiInvalid))) {
            if (self::shouldAcceptUnknownHealthCandidate($php, $normForCandidate)) {
                return [
                    'is_valid' => true,
                    'classification' => self::CLASS_VALID,
                    'reason' => 'Gemini UNCLEAR but plausible unknown health wording — continue clinical interview',
                ];
            }

            return [
                'is_valid' => false,
                'classification' => self::CLASS_INVALID,
                'reason' => 'Gemini UNCLEAR — ask patient to clarify health concern',
            ];
        }

        if ($geminiInvalid || self::phpLooksHardNonMedical($php)) {
            // Low-confidence NON_HEALTH on a plausible local health phrase: prefer interview over reject.
            // High-confidence / explicit prank classifications still reject.
            if ($geminiInvalid
                && !$isPrankClass
                && !$geminiHigh
                && self::shouldAcceptUnknownHealthCandidate($php, $normForCandidate)
            ) {
                return [
                    'is_valid' => true,
                    'classification' => self::CLASS_VALID,
                    'reason' => 'Gemini low-confidence NON_HEALTH on plausible local health wording — continue interview',
                ];
            }

            return [
                'is_valid' => false,
                'classification' => self::CLASS_INVALID,
                'reason' => $isPrankClass
                    ? 'Gemini PRANK_OR_NON_MEDICAL'
                    : ($geminiInvalid
                        ? 'PHP no medical concept + Gemini NON_HEALTH_RELATED / INVALID'
                        : 'PHP hard non-medical (greeting/out-of-scope)'),
            ];
        }

        if (!$geminiAvailable) {
            // ADDITIVE: dataset miss ≠ invalid. If domain already routed to Gemini fallback
            // (or text looks like an unknown local health utterance), continue the interview
            // instead of rejecting as "invalid medical term".
            if (self::shouldAcceptUnknownHealthCandidate($php, (string) ($php['normalized'] ?? ''))) {
                return [
                    'is_valid' => true,
                    'classification' => self::CLASS_VALID,
                    'reason' => 'Unknown wording (dataset miss) — Gemini unavailable; continue clinical interview',
                ];
            }

            return [
                'is_valid' => false,
                'classification' => self::CLASS_INVALID,
                'reason' => 'PHP no medical concept (Gemini unavailable)',
            ];
        }

        return [
            'is_valid' => false,
            'classification' => self::CLASS_INVALID,
            'reason' => 'No reliable medical input evidence',
        ];
    }

    /**
     * True when PHP found no dataset concept but the utterance still looks like a
     * plausible local/informal health complaint (not a greeting / keyboard smash).
     *
     * @param array<string, mixed> $php
     */
    private static function shouldAcceptUnknownHealthCandidate(array $php, string $normalized): bool
    {
        $routing = (string) ($php['routing'] ?? '');
        if ($routing === HealthComplaintDomainDetector::ROUTE_GREETING
            || $routing === HealthComplaintDomainDetector::ROUTE_OOS
        ) {
            return false;
        }
        if (self::phpLooksHardNonMedical($php)) {
            return false;
        }

        $norm = trim($normalized);
        if ($norm === '' || self::looksLikeIncompleteJunk($norm)) {
            return false;
        }

        // Laugh/joke spam without clinical tokens is not an "unknown health" candidate.
        if (preg_match('/\b(?:(?:ha){2,}|(?:he){2,}|lol+|lmao+|jk)\b/u', mb_strtolower($norm))) {
            $hasClinicalCue = (bool) preg_match(
                '/\b(sakit|masakit|tiyan|ulo|fever|hilanat|lagnat|cough|ubo|pain|head|chest|dughan|suka|hilo|lawas|body)\b/u',
                mb_strtolower($norm)
            );
            if (!$hasClinicalCue) {
                return false;
            }
        }

        if ($routing === HealthComplaintDomainDetector::ROUTE_GEMINI) {
            return true;
        }

        $signals = is_array($php['signals'] ?? null) ? $php['signals'] : [];
        $hasPatientRef = false;
        foreach ($signals as $s) {
            if (is_array($s) && (string) ($s['type'] ?? '') === 'patient_reference') {
                $hasPatientRef = true;
                break;
            }
        }

        $tokens = preg_split('/[^\p{L}\p{N}\-]+/u', mb_strtolower($norm), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($tokens) >= 2 && $hasPatientRef) {
            return true;
        }
        // Multi-token informal utterance with enough length to be a real complaint phrase.
        if (count($tokens) >= 2 && mb_strlen($norm) >= 6) {
            return true;
        }

        return false;
    }

    /**
     * Bridge unknown local wording into text the existing NLP datasets can match.
     * Does not diagnose; only applies Gemini lexical understanding when PHP is weak/none.
     *
     * @param array<string, mixed> $gemini
     */
    private static function enrichNlpTextForExistingEngine(
        string $raw,
        string $nlpText,
        string $phpEvidence,
        array $gemini
    ): string {
        $base = trim($nlpText !== '' ? $nlpText : $raw);
        if (!in_array($phpEvidence, ['none', 'weak'], true)) {
            return $base;
        }
        if (empty($gemini['available']) || empty($gemini['is_medical_complaint'])) {
            return $base;
        }

        $corrected = trim((string) ($gemini['corrected_text'] ?? ''));
        $concept = trim((string) ($gemini['medical_concept'] ?? ''));
        $corrected = trim((string) preg_replace('/\s+/u', ' ', $corrected));
        $concept = trim((string) preg_replace('/\s+/u', ' ', $concept));

        // Reject unsafe bridge content (triage labels / long essays).
        if ($corrected !== '' && (
            mb_strlen($corrected) > 180
            || preg_match('/\b(EMERGENCY|URGENT|NON-URGENT|diagnos|prescription)\b/iu', $corrected)
        )) {
            $corrected = '';
        }
        if ($concept !== '' && (
            mb_strlen($concept) > 80
            || preg_match('/\b(EMERGENCY|URGENT|NON-URGENT|diagnos|prescription)\b/iu', $concept)
        )) {
            $concept = '';
        }

        if ($corrected !== '' && mb_strtolower($corrected) !== mb_strtolower($raw)) {
            return $corrected;
        }
        if ($concept !== '') {
            // Keep original tokens + English concept so existing NLP/fuzzy can fire.
            if (mb_stripos($base, $concept) !== false) {
                return $base;
            }

            return trim($base . '. ' . $concept);
        }

        return $base;
    }

    private static function looksLikeIncompleteJunk(string $text): bool
    {
        $low = mb_strtolower(trim($text));
        if ($low === '') {
            return true;
        }
        if (preg_match('/^(hh+|aa+|zz+|asdf+|qwer+|zxcv+|qwerty+|test+|testing+|hello|hi|hey|yo|haha+|lol+|lmao+|ok|okay)$/u', $low)) {
            return true;
        }
        $tokens = preg_split('/[^\p{L}\p{N}\-]+/u', $low, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($tokens === []) {
            return true;
        }
        if (count($tokens) === 1) {
            $t = $tokens[0];
            $len = mb_strlen($t);
            if ($len <= 2) {
                return true;
            }
            if ($len <= 3 && !preg_match('/[aeiouàáéíóú]/iu', $t)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $php
     * @return 'strong'|'weak'|'none'
     */
    private static function phpEvidenceStrength(array $php, string $raw): string
    {
        $health = !empty($php['health_related']);
        $score = (float) ($php['score'] ?? 0);
        $conf = strtoupper((string) ($php['confidence'] ?? ''));
        $routing = (string) ($php['routing'] ?? '');
        $signals = is_array($php['signals'] ?? null) ? $php['signals'] : [];
        $relationships = is_array($php['relationships'] ?? null) ? $php['relationships'] : [];

        if (!$health) {
            return 'none';
        }

        if (self::isFuzzyOnlyWeak($php)) {
            return 'weak';
        }

        $hasStrongRel = false;
        foreach ($relationships as $rel) {
            if (in_array((string) $rel, [
                'SYMPTOM+BODY_PART',
                'SYMPTOM+BODY_PART+PATIENT_REFERENCE',
                'SYMPTOM+DURATION',
                'PHYSICAL_CHANGE+BODY_PART',
                'INJURY+BODY_PART',
                'DURATION+SYMPTOM+BODY_PART',
            ], true)) {
                $hasStrongRel = true;
                break;
            }
        }

        $nonFuzzyClinical = 0;
        foreach ($signals as $s) {
            if (!is_array($s)) {
                continue;
            }
            $type = (string) ($s['type'] ?? '');
            if (in_array($type, [
                'symptom', 'body_part', 'duration', 'physical_change', 'injury',
                'bleeding', 'breathing', 'malaise', 'medication', 'existing_healthcare_flag',
            ], true)) {
                $nonFuzzyClinical++;
            }
        }

        if ($hasStrongRel || ($conf === 'HIGH' && $score >= 2.4) || ($nonFuzzyClinical >= 2 && $score >= 1.8)) {
            return 'strong';
        }

        if ($score >= 1.2 || $nonFuzzyClinical >= 1 || $routing === HealthComplaintDomainDetector::ROUTE_NLP) {
            return $score >= 2.0 ? 'strong' : 'weak';
        }

        // health_related but almost no score — treat weak
        unset($raw);

        return 'weak';
    }

    /** @param array<string, mixed> $php */
    private static function isFuzzyOnlyWeak(array $php): bool
    {
        $signals = is_array($php['signals'] ?? null) ? $php['signals'] : [];
        $hasFuzzy = false;
        $clinicalTypes = [];
        foreach ($signals as $s) {
            if (!is_array($s)) {
                continue;
            }
            $type = (string) ($s['type'] ?? '');
            if ($type === 'fuzzy_correction') {
                $hasFuzzy = true;
                continue;
            }
            if (in_array($type, [
                'symptom', 'body_part', 'duration', 'physical_change', 'injury',
                'bleeding', 'breathing', 'malaise', 'medication', 'existing_healthcare_flag',
            ], true)) {
                $clinicalTypes[$type] = true;
            }
        }
        if (!$hasFuzzy) {
            return false;
        }
        $relationships = is_array($php['relationships'] ?? null) ? $php['relationships'] : [];
        if ($relationships !== []) {
            return false;
        }
        $score = (float) ($php['score'] ?? 0);
        // Fuzzy invented a clinical token with little else backing it.
        return $clinicalTypes !== [] && $score < 1.5;
    }

    /** @param array<string, mixed> $php */
    private static function phpLooksHardNonMedical(array $php): bool
    {
        $routing = (string) ($php['routing'] ?? '');
        $conf = (string) ($php['confidence'] ?? '');
        $health = !empty($php['health_related']);

        return !$health
            && $conf === HealthComplaintDomainDetector::CONF_HIGH
            && in_array($routing, [
                HealthComplaintDomainDetector::ROUTE_OOS,
                HealthComplaintDomainDetector::ROUTE_GREETING,
            ], true);
    }

    /** @param array<string, mixed> $php @return list<string> */
    private static function conceptLabels(array $php): array
    {
        $out = [];
        foreach ((array) ($php['signals'] ?? []) as $s) {
            if (!is_array($s)) {
                continue;
            }
            $type = (string) ($s['type'] ?? '');
            $value = (string) ($s['value'] ?? '');
            if ($value !== '' && in_array($type, ['symptom', 'body_part', 'duration', 'injury', 'malaise'], true)) {
                $out[] = $type . ':' . $value;
            }
        }

        return array_values(array_unique($out));
    }

    /** @param array<string, mixed> $php @return list<string> */
    private static function fuzzyLabels(array $php): array
    {
        $out = [];
        foreach ((array) ($php['signals'] ?? []) as $s) {
            if (!is_array($s)) {
                continue;
            }
            if (($s['type'] ?? '') === 'fuzzy_correction') {
                $out[] = (string) ($s['value'] ?? '');
            }
        }

        return array_values(array_filter($out));
    }

    private static function detectLanguageKey(string $text): string
    {
        if ($text === '' || !class_exists('HiligaynonLanguageDetector')) {
            return 'english';
        }
        try {
            $detected = HiligaynonLanguageDetector::detect($text);
            $primary = strtolower((string) ($detected['primary'] ?? 'english'));

            return match ($primary) {
                'hiligaynon', 'ilonggo' => 'hiligaynon',
                'tagalog', 'filipino' => 'tagalog',
                default => 'english',
            };
        } catch (Throwable) {
            return 'english';
        }
    }

    public static function clarificationMessage(string $langKey): string
    {
        return match (strtolower($langKey)) {
            'hiligaynon', 'ilonggo' => 'Palihog, isugid ang imo ginabatyag nga sintomas ukon problema sa lawas agod makapadayon kita.',
            'tagalog', 'filipino' => 'Pakilarawan ang sintomas o problemang pangkalusugan na iyong nararanasan upang makapagpatuloy tayo.',
            default => 'Please describe a health concern or symptom you are experiencing so we can continue.',
        };
    }

    private static function debugEnabled(): bool
    {
        $raw = getenv('NLP_DEBUG');
        if ($raw === false || $raw === '') {
            $raw = $_ENV['NLP_DEBUG'] ?? '';
        }

        return in_array(strtolower(trim((string) $raw)), ['1', 'true', 'yes', 'on'], true);
    }
}
