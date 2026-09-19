<?php
/**
 * Combines existing PHP domain NLP with optional Gemini semantic validation.
 *
 * Dataset/domain validation runs FIRST. Gemini is a fallback when PHP cannot
 * confidently decide. Gemini is NOT triage. Final clinical flow stays in
 * ClinicalInterviewEngine / ClinicalTriageEngine.
 */
final class ComplaintSemanticValidator
{
    public const CLASS_VALID = 'VALID_MEDICAL_COMPLAINT';
    public const CLASS_INVALID = 'INVALID_MEDICAL_INPUT';
    public const STATE_NEEDS_VALID = 'NEEDS_VALID_COMPLAINT';

    public const DOMAIN_HEALTH = 'HEALTH_RELATED';
    public const DOMAIN_NON_HEALTH = 'NON_HEALTH_RELATED';
    public const DOMAIN_UNCLEAR = 'UNCLEAR';

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
        // Prefer ORIGINAL for domain detection so cleaner corrections/discards cannot
        // erase local clinical meaning. Cleaned text is still used for NLP enrichment.
        $phpSource = $raw;
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
                'normalized' => class_exists('HiligaynonTextNormalizer')
                    ? HiligaynonTextNormalizer::forMatch($raw)
                    : mb_strtolower($raw),
                'reason' => 'Domain detector unavailable',
            ];
        if ($usable && $nlpText !== '' && mb_strtolower($nlpText) !== mb_strtolower($raw)
            && class_exists('HealthComplaintDomainDetector')
        ) {
            $phpClean = HealthComplaintDomainDetector::detect($nlpText);
            $preferred = self::preferStrongerDomainPack($php, $phpClean);
            if ($preferred === $phpClean) {
                $phpSource = $nlpText;
            }
            $php = $preferred;
        }

        $phpEvidence = self::phpEvidenceStrength($php, $phpSource);
        $incompleteJunk = !$usable && self::looksLikeIncompleteJunk($raw);
        if ($incompleteJunk) {
            $phpEvidence = 'none';
        }

        // Dataset-first: call Gemini only when PHP is not confident, or tests inject a result.
        // Always send ORIGINAL patient wording when Gemini runs (cleaner may drop unknown local terms).
        $gemini = $geminiOverride ?? self::resolveGeminiFallback($raw, $phpEvidence, $incompleteJunk);

        $decision = self::combine($phpEvidence, $php, $gemini);
        $lang = self::detectLanguageKey($raw);
        $ollamaBridge = [];

        // When Gemini is down/uncertain and PHP has no concept, try local Ollama meaning
        // BEFORE hard reject. Does not triage; only health-domain understanding.
        if (empty($decision['is_valid']) && !$incompleteJunk) {
            $ollamaBridge = self::tryOllamaHealthMeaningBridge($raw, $php, $gemini);
            if (!empty($ollamaBridge['is_health_related'])) {
                $decision = [
                    'is_valid' => true,
                    'classification' => self::CLASS_VALID,
                    'reason' => 'Ollama/local meaning support — health-related; Gemini unavailable or uncertain',
                ];
                $meaning = trim((string) ($ollamaBridge['english_interpretation'] ?? ''));
                if ($meaning !== '') {
                    $nlpText = trim($raw . '. ' . $meaning);
                    $usable = true;
                } elseif (trim($nlpText) === '') {
                    $nlpText = $raw;
                    $usable = true;
                }
            }
        }

        $geminiCorrected = trim((string) ($gemini['corrected_text'] ?? ''));
        $geminiConcept = trim((string) ($gemini['medical_concept'] ?? ''));
        if (!$usable && !$incompleteJunk && !empty($gemini['is_medical_complaint'])) {
            $gloss = $geminiCorrected !== '' && mb_strtolower($geminiCorrected) !== mb_strtolower($raw)
                ? $geminiCorrected
                : ($geminiConcept !== '' ? $geminiConcept : '');
            // HEALTH_RELATED with or without gloss: keep ORIGINAL wording for NLP/display.
            // Gloss is additive only so existing datasets can match English concepts.
            if ($gloss !== '') {
                $nlpText = trim($raw . '. ' . $gloss);
            } else {
                $nlpText = $raw;
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
        } elseif (!$usable && !$incompleteJunk && $geminiCorrected !== '' && $geminiCorrected !== $raw) {
            // Legacy path kept for non-boolean medical flag payloads — still preserve original.
            $nlpText = trim($raw . '. ' . $geminiCorrected);
            $usable = true;
            $phpSource = $nlpText !== '' ? $nlpText : $raw;
            if (class_exists('HealthComplaintDomainDetector')) {
                $php = HealthComplaintDomainDetector::detect($phpSource);
                $phpEvidence = self::phpEvidenceStrength($php, $phpSource);
            }
            $decision = self::combine($phpEvidence, $php, $gemini);
        }

        $domainLabel = self::resolveDomainLabel($decision, $php, $gemini);
        $message = $decision['is_valid']
            ? ''
            : self::patientGateMessage($lang, $domainLabel);

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

        $clinicallyVague = !empty($decision['is_valid'])
            && class_exists('ClinicalFeatureExtractors')
            && ClinicalFeatureExtractors::isVagueComplaint($raw);

        // NEEDS_VALID_COMPLAINT only for confirmed non-health / prank — never for mere
        // dataset miss or Gemini UNCLEAR (those use clarification / continue paths).
        $needsValid = !$decision['is_valid'] && $domainLabel === self::DOMAIN_NON_HEALTH;
        $needsClarify = !$decision['is_valid'] && $domainLabel === self::DOMAIN_UNCLEAR;

        $out = [
            'is_valid' => $decision['is_valid'],
            'classification' => $decision['classification'],
            'domain_label' => $domainLabel,
            'needs_valid_complaint' => $needsValid,
            'needs_clarification' => $needsClarify,
            'clinically_vague' => $clinicallyVague,
            'workflow_state' => $decision['is_valid']
                ? self::CLASS_VALID
                : ($needsValid ? self::STATE_NEEDS_VALID : self::DOMAIN_UNCLEAR),
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
            'ollama_bridge' => $ollamaBridge,
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
     * @return array{is_valid:bool,classification:string,reason:string,domain_label?:string}
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

        $geminiClass = strtoupper(str_replace([' ', '-'], '_', (string) ($gemini['classification'] ?? '')));
        $normForCandidate = trim((string) ($php['normalized'] ?? ''));
        $isPrankClass = in_array($geminiClass, [
            'PRANK_OR_NON_MEDICAL',
            'NONSENSE_OR_PRANK',
            'PRANK',
            'NONSENSE',
        ], true);

        // Weak local NLP alone must never hard-reject. Gemini HEALTH → accept.
        if ($phpEvidence === 'weak' && $geminiValid) {
            return [
                'is_valid' => true,
                'classification' => self::CLASS_VALID,
                'reason' => 'Gemini VALID with weak PHP evidence — continue NLP',
            ];
        }

        // Weak PHP + Gemini INVALID: only hard-reject on high-confidence / prank.
        // Low-confidence invalid is not proof the complaint is non-health.
        if ($phpEvidence === 'weak' && $geminiInvalid) {
            if ($isPrankClass || $geminiHigh) {
                return [
                    'is_valid' => false,
                    'classification' => self::CLASS_INVALID,
                    'reason' => $isPrankClass
                        ? 'Gemini PRANK_OR_NON_MEDICAL'
                        : 'Weak PHP + Gemini high-confidence NON_HEALTH_RELATED',
                    'domain_label' => self::DOMAIN_NON_HEALTH,
                ];
            }
            if (!empty($php['health_related'])) {
                return [
                    'is_valid' => true,
                    'classification' => self::CLASS_VALID,
                    'reason' => 'Weak PHP health signals — Gemini low-confidence NON_HEALTH ignored',
                ];
            }

            return [
                'is_valid' => false,
                'classification' => self::CLASS_INVALID,
                'reason' => 'Gemini low-confidence NON_HEALTH without PHP health — ask to clarify',
                'domain_label' => self::DOMAIN_UNCLEAR,
            ];
        }

        if ($phpEvidence === 'weak' && !$geminiAvailable) {
            // Fuzzy-only / hard non-medical without Gemini: clarify, do not invent.
            if (self::isFuzzyOnlyWeak($php) || self::phpLooksHardNonMedical($php)) {
                return [
                    'is_valid' => false,
                    'classification' => self::CLASS_INVALID,
                    'reason' => 'Weak PHP only (fuzzy/noise) — Gemini unavailable; ask to clarify',
                    'domain_label' => self::DOMAIN_UNCLEAR,
                ];
            }

            return [
                'is_valid' => true,
                'classification' => self::CLASS_VALID,
                'reason' => 'Weak PHP health signals — Gemini unavailable fail-open to NLP',
            ];
        }

        // phpEvidence === none — dataset miss is NOT proof of non-health.
        if ($geminiValid) {
            return [
                'is_valid' => true,
                'classification' => self::CLASS_VALID,
                'reason' => 'Gemini HEALTH_RELATED / VALID — PHP dataset miss; continue NLP for unknown wording',
            ];
        }

        // UNCLEAR ≠ invalid / non-health. Prefer continue when any PHP health signal exists.
        if ($geminiAvailable && ($geminiClass === 'UNCLEAR' || ($gemini['is_medical_complaint'] === null && !$geminiInvalid))) {
            if (!empty($php['health_related']) || self::shouldAcceptUnknownHealthCandidate($php, $normForCandidate)) {
                return [
                    'is_valid' => true,
                    'classification' => self::CLASS_VALID,
                    'reason' => 'Gemini UNCLEAR with plausible health candidate — continue clinical interview',
                ];
            }

            return [
                'is_valid' => false,
                'classification' => self::CLASS_INVALID,
                'reason' => 'Gemini UNCLEAR — ask patient to clarify health concern',
                'domain_label' => self::DOMAIN_UNCLEAR,
            ];
        }

        if ($geminiInvalid || self::phpLooksHardNonMedical($php)) {
            // Low-confidence NON_HEALTH on a plausible local health phrase: prefer interview over reject.
            if ($geminiInvalid
                && !$isPrankClass
                && !$geminiHigh
                && (!empty($php['health_related']) || self::shouldAcceptUnknownHealthCandidate($php, $normForCandidate))
            ) {
                return [
                    'is_valid' => true,
                    'classification' => self::CLASS_VALID,
                    'reason' => 'Gemini low-confidence NON_HEALTH on plausible health candidate — continue interview',
                ];
            }

            if (self::phpLooksHardNonMedical($php) && !$geminiInvalid) {
                return [
                    'is_valid' => false,
                    'classification' => self::CLASS_INVALID,
                    'reason' => 'PHP hard non-medical (greeting/out-of-scope)',
                    'domain_label' => self::DOMAIN_NON_HEALTH,
                ];
            }

            return [
                'is_valid' => false,
                'classification' => self::CLASS_INVALID,
                'reason' => $isPrankClass
                    ? 'Gemini PRANK_OR_NON_MEDICAL'
                    : 'Gemini NON_HEALTH_RELATED / INVALID',
                'domain_label' => self::DOMAIN_NON_HEALTH,
            ];
        }

        if (!$geminiAvailable) {
            // Dataset miss with PHP health signals → continue. Never map miss → NON_HEALTH.
            if (!empty($php['health_related']) || self::shouldAcceptUnknownHealthCandidate($php, (string) ($php['normalized'] ?? ''))) {
                return [
                    'is_valid' => true,
                    'classification' => self::CLASS_VALID,
                    'reason' => 'Unknown wording (dataset miss) — Gemini unavailable; continue clinical interview',
                ];
            }

            return [
                'is_valid' => false,
                'classification' => self::CLASS_INVALID,
                'reason' => 'PHP unclear / no medical concept (Gemini unavailable) — ask to clarify',
                'domain_label' => self::DOMAIN_UNCLEAR,
            ];
        }

        return [
            'is_valid' => false,
            'classification' => self::CLASS_INVALID,
            'reason' => 'No reliable medical input evidence — ask to clarify',
            'domain_label' => self::DOMAIN_UNCLEAR,
        ];
    }

    /**
     * Gemini fallback only when PHP evidence is not strong.
     *
     * @return array<string, mixed>
     */
    private static function resolveGeminiFallback(string $raw, string $phpEvidence, bool $incompleteJunk): array
    {
        if ($incompleteJunk) {
            return [
                'available' => false,
                'is_medical_complaint' => null,
                'classification' => null,
                'confidence' => null,
                'error' => 'skipped_incomplete_junk',
                'corrected_text' => '',
                'medical_concept' => '',
            ];
        }
        if ($phpEvidence === 'strong') {
            return [
                'available' => false,
                'is_medical_complaint' => null,
                'classification' => null,
                'confidence' => null,
                'error' => 'skipped_php_strong',
                'corrected_text' => '',
                'medical_concept' => '',
            ];
        }
        if (!class_exists('GeminiComplaintInputValidator')) {
            return [
                'available' => false,
                'is_medical_complaint' => null,
                'classification' => null,
                'confidence' => null,
                'error' => 'class_missing',
                'corrected_text' => '',
                'medical_concept' => '',
            ];
        }

        return GeminiComplaintInputValidator::validate($raw);
    }

    /**
     * @param array{is_valid:bool,classification:string,reason:string,domain_label?:string} $decision
     * @param array<string, mixed> $php
     * @param array<string, mixed> $gemini
     */
    private static function resolveDomainLabel(array $decision, array $php, array $gemini): string
    {
        if (!empty($decision['domain_label'])) {
            return (string) $decision['domain_label'];
        }
        if (!empty($decision['is_valid'])) {
            return self::DOMAIN_HEALTH;
        }

        $geminiClass = strtoupper(str_replace([' ', '-'], '_', (string) ($gemini['classification'] ?? '')));
        if (in_array($geminiClass, ['UNCLEAR', 'AMBIGUOUS', 'UNCERTAIN'], true)) {
            return self::DOMAIN_UNCLEAR;
        }
        if (in_array($geminiClass, [
            'PRANK_OR_NON_MEDICAL', 'NONSENSE_OR_PRANK', 'PRANK', 'NONSENSE',
            'NON_HEALTH_RELATED', 'INVALID_MEDICAL_INPUT', 'INVALID', 'OUT_OF_SCOPE',
        ], true)) {
            return self::DOMAIN_NON_HEALTH;
        }

        $phpDomain = strtoupper((string) ($php['domain'] ?? ''));
        if ($phpDomain === HealthComplaintDomainDetector::DOMAIN_UNCLEAR
            || (string) ($php['routing'] ?? '') === HealthComplaintDomainDetector::ROUTE_GEMINI
        ) {
            return self::DOMAIN_UNCLEAR;
        }

        return self::DOMAIN_NON_HEALTH;
    }

    /**
     * Prefer the domain pack with clearer health evidence.
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     * @return array<string, mixed>
     */
    private static function preferStrongerDomainPack(array $a, array $b): array
    {
        $scoreA = (float) ($a['score'] ?? 0);
        $scoreB = (float) ($b['score'] ?? 0);
        $healthA = !empty($a['health_related']);
        $healthB = !empty($b['health_related']);
        if ($healthB && !$healthA) {
            return $b;
        }
        if ($healthA && !$healthB) {
            return $a;
        }
        if ($scoreB > $scoreA) {
            return $b;
        }

        return $a;
    }

    /**
     * Soft local meaning check before hard reject when Gemini failed/uncertain.
     * Returns health-domain support only — never triage class.
     *
     * @param array<string, mixed> $php
     * @param array<string, mixed> $gemini
     * @return array<string, mixed>
     */
    private static function tryOllamaHealthMeaningBridge(string $raw, array $php, array $gemini): array
    {
        $empty = [
            'is_health_related' => false,
            'english_interpretation' => '',
            'provider' => '',
            'confidence_score' => 0,
        ];
        if (self::phpLooksHardNonMedical($php) || self::looksLikeIncompleteJunk($raw)) {
            return $empty;
        }
        // Skip when Gemini already made a high-confidence non-health / prank call.
        $geminiAvailable = !empty($gemini['available']);
        $geminiInvalid = $geminiAvailable && $gemini['is_medical_complaint'] === false;
        $geminiConf = isset($gemini['confidence']) && is_numeric($gemini['confidence'])
            ? (float) $gemini['confidence']
            : null;
        $geminiClass = strtoupper(str_replace([' ', '-'], '_', (string) ($gemini['classification'] ?? '')));
        $isPrank = in_array($geminiClass, [
            'PRANK_OR_NON_MEDICAL', 'NONSENSE_OR_PRANK', 'PRANK', 'NONSENSE',
        ], true);
        if ($isPrank || ($geminiInvalid && $geminiConf !== null && $geminiConf >= 0.75)) {
            return $empty;
        }
        if (!class_exists('MedicalAiInterpreter')) {
            return $empty;
        }

        try {
            $result = MedicalAiInterpreter::interpretComplaintMeaning($raw);
        } catch (Throwable $e) {
            error_log('ComplaintSemanticValidator Ollama bridge: ' . $e->getMessage());

            return $empty;
        }
        if (($result['status'] ?? '') !== 'complete') {
            return $empty;
        }
        $meaning = trim((string) ($result['english_interpretation'] ?? ''));
        if ($meaning === '' || mb_strlen($meaning) > 180) {
            return $empty;
        }
        if (preg_match('/\b(EMERGENCY|URGENT|NON-URGENT|diagnos|prescription)\b/iu', $meaning)) {
            return $empty;
        }

        $concepts = is_array($result['concepts'] ?? null) ? $result['concepts'] : [];
        $hasClinicalConcept = false;
        foreach ($concepts as $c) {
            if (!is_array($c)) {
                continue;
            }
            $type = strtolower((string) ($c['type'] ?? ''));
            $term = strtolower((string) ($c['term'] ?? ''));
            if (in_array($type, ['symptom', 'condition', 'body_part', 'finding', 'complaint'], true)) {
                $hasClinicalConcept = true;
                break;
            }
            if ($term !== '' && preg_match(
                '/\b(pain|fever|cough|diarrhea|diarrhoea|vomit|nausea|dizzy|swell|bleed|breath|rash|weak|sick|unwell|peel|skin|stool|bowel|stomach|abdomen|headache)\b/u',
                $term
            )) {
                $hasClinicalConcept = true;
                break;
            }
        }
        $meaningLooksClinical = (bool) preg_match(
            '/\b(pain|fever|cough|diarrhea|diarrhoea|vomit|nausea|dizzy|swell|bleed|breath|rash|weak|sick|unwell|peeling|skin|stool|bowel|stomach|abdomen|headache|symptom|hurt|ache|illness|malaise)\b/iu',
            $meaning
        );
        if (!$hasClinicalConcept && !$meaningLooksClinical) {
            return $empty;
        }

        return [
            'is_health_related' => true,
            'english_interpretation' => $meaning,
            'provider' => (string) ($result['provider'] ?? 'local'),
            'confidence_score' => (int) ($result['confidence_score'] ?? 0),
            'concepts' => $concepts,
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

        $signals = is_array($php['signals'] ?? null) ? $php['signals'] : [];
        $hasClinicalSignal = false;
        $hasPatientRef = false;
        foreach ($signals as $s) {
            if (!is_array($s)) {
                continue;
            }
            $type = (string) ($s['type'] ?? '');
            if ($type === 'patient_reference') {
                $hasPatientRef = true;
            }
            if (in_array($type, [
                'symptom', 'body_part', 'duration', 'physical_change', 'injury',
                'bleeding', 'breathing', 'malaise', 'medication',
                'finding', 'condition', 'dataset_symptom',
            ], true)) {
                $hasClinicalSignal = true;
            }
        }

        // Clinical dictionary/finding evidence counts even when overall confidence is weak.
        if ($hasClinicalSignal || !empty($php['health_related'])) {
            $tokens = preg_split('/[^\p{L}\p{N}\-]+/u', mb_strtolower($norm), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (count($tokens) >= 2 && ($hasPatientRef || mb_strlen($norm) >= 6)) {
                return true;
            }
            if ((float) ($php['score'] ?? 0) >= 1.2) {
                return true;
            }
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
            // Keep original patient wording; append Gemini gloss for existing NLP matching only.
            if (mb_stripos($base, $corrected) !== false) {
                return $base;
            }

            return trim($base . '. ' . $corrected);
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
                'FINDING+BODY_PART',
                'CONDITION+BODY_PART',
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
                'finding', 'condition',
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
                'finding', 'condition',
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
            if ($value !== '' && in_array($type, [
                'symptom', 'body_part', 'duration', 'injury', 'malaise',
                'finding', 'condition', 'physical_change',
            ], true)) {
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
        return self::patientGateMessage($langKey, self::DOMAIN_NON_HEALTH);
    }

    public static function unclearMessage(string $langKey): string
    {
        return self::patientGateMessage($langKey, self::DOMAIN_UNCLEAR);
    }

    public static function patientGateMessage(string $langKey, string $domainLabel): string
    {
        $lang = strtolower($langKey);
        if ($domainLabel === self::DOMAIN_UNCLEAR) {
            return match ($lang) {
                'hiligaynon', 'ilonggo' => 'Palihog, isugid liwat ukon i-klaro ang imo problema sa lawas agod mahangpan namon.',
                'tagalog', 'filipino' => 'Pakisagot muli o linawin ang iyong problemang pangkalusugan upang maintindihan namin.',
                default => 'Please clarify or rephrase your health concern so we can understand.',
            };
        }

        return match ($lang) {
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
