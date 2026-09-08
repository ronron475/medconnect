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
        $php = class_exists('HealthComplaintDomainDetector')
            ? HealthComplaintDomainDetector::detect($raw)
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

        $phpEvidence = self::phpEvidenceStrength($php, $raw);
        $gemini = $geminiOverride ?? (
            class_exists('GeminiComplaintInputValidator')
                ? GeminiComplaintInputValidator::validate($raw)
                : [
                    'available' => false,
                    'is_medical_complaint' => null,
                    'classification' => null,
                    'confidence' => null,
                    'error' => 'class_missing',
                ]
        );

        $decision = self::combine($phpEvidence, $php, $gemini);
        $lang = self::detectLanguageKey($raw);
        $message = $decision['is_valid']
            ? ''
            : self::clarificationMessage($lang);

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
            'medical_concepts_found' => self::conceptLabels($php),
            'fuzzy_matches' => self::fuzzyLabels($php),
            'final_validation_result' => $decision['classification'],
            'combine_reason' => $decision['reason'],
        ];

        if (self::debugEnabled()) {
            error_log('ComplaintSemanticValidator: ' . json_encode([
                'patient_input' => mb_substr($raw, 0, 120),
                'php_validation_result' => $out['php_validation_result'],
                'gemini_validation_result' => $out['gemini_validation_result'],
                'gemini_confidence' => $out['gemini_confidence'],
                'medical_concepts_found' => $out['medical_concepts_found'],
                'fuzzy_matches' => $out['fuzzy_matches'],
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
                'reason' => 'Gemini VALID — PHP found no concept; continue NLP for unknown wording',
            ];
        }

        if ($geminiInvalid || self::phpLooksHardNonMedical($php) || !$geminiAvailable) {
            return [
                'is_valid' => false,
                'classification' => self::CLASS_INVALID,
                'reason' => !$geminiAvailable
                    ? 'PHP no medical concept (Gemini unavailable)'
                    : 'PHP no medical concept + Gemini INVALID',
            ];
        }

        return [
            'is_valid' => false,
            'classification' => self::CLASS_INVALID,
            'reason' => 'No reliable medical input evidence',
        ];
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
