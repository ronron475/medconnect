<?php
/**
 * Optional Python ai_service enrichment for the clinical interview.
 *
 * PHP remains primary (gate, interview, ClinicalTriageEngine final authority).
 * When the FastAPI service is healthy, this layer may add English symptom glosses
 * and matched terms from Python dataset matchers. It never sets EMERGENCY /
 * URGENT / NON-URGENT and never replaces the patient's original wording.
 *
 * Fail-open: offline / slow / error → empty enrichment; PHP continues alone.
 */
final class ClinicalInterviewPythonEnrichment
{
    private const DEFAULT_TIMEOUT = 6;

    public static function enabled(): bool
    {
        if (!defined('AI_SERVICE_ENABLED') || !AI_SERVICE_ENABLED) {
            return false;
        }
        if (!class_exists('AiServiceClient')) {
            return false;
        }
        // Independent of MEDCONNECT_PHP_NLP_ONLY: that flag blocks Python as the
        // main NLP brain; this path is additive enrichment only.
        $raw = getenv('MEDCONNECT_CLINICAL_PYTHON_ENRICH');
        if ($raw === false || $raw === '') {
            return true;
        }

        return !in_array(strtolower(trim((string) $raw)), ['0', 'false', 'no', 'off'], true);
    }

    /**
     * @return array{
     *   used: bool,
     *   english_symptoms: list<string>,
     *   english_gloss: string,
     *   body_locations: list<string>,
     *   matched_terms: list<string>,
     *   source: string,
     *   reason: string
     * }
     */
    public static function enrich(string $text): array
    {
        $empty = self::emptyResult('skipped');
        $text = trim($text);
        if ($text === '' || !self::enabled()) {
            return $empty;
        }

        try {
            if (!AiServiceClient::isHealthy(1)) {
                return self::emptyResult('python_offline');
            }
        } catch (Throwable) {
            return self::emptyResult('python_health_error');
        }

        $timeout = self::timeoutSeconds();
        $symptoms = [];
        $gloss = '';
        $bodies = [];
        $matched = [];
        $used = false;

        try {
            $symData = AiServiceClient::recognizeSymptoms($text, $timeout);
            if (is_array($symData)) {
                $used = true;
                foreach (self::stringList($symData['english_symptoms'] ?? []) as $s) {
                    $symptoms[] = $s;
                }
                foreach ((array) ($symData['detections'] ?? []) as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $en = trim((string) ($row['english_translation'] ?? ''));
                    if ($en !== '') {
                        $symptoms[] = $en;
                    }
                    $local = trim((string) ($row['detected_symptom'] ?? $row['normalized_symptom'] ?? ''));
                    if ($local !== '') {
                        $matched[] = $local;
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('ClinicalInterviewPythonEnrichment recognizeSymptoms: ' . $e->getMessage());
        }

        try {
            $textData = AiServiceClient::analyzeMedicalText($text, $timeout);
            if (is_array($textData)) {
                $used = true;
                $safe = self::stripTriageAuthority($textData);
                $gloss = self::pickEnglishGloss($safe);
                foreach (self::symptomsFromAnalyze($safe) as $s) {
                    $symptoms[] = $s;
                }
                foreach (self::bodyPartsFromAnalyze($safe) as $b) {
                    $bodies[] = $b;
                }
                foreach (self::matchedTermsFromAnalyze($safe) as $t) {
                    $matched[] = $t;
                }
            }
        } catch (Throwable $e) {
            error_log('ClinicalInterviewPythonEnrichment analyzeMedicalText: ' . $e->getMessage());
        }

        $symptoms = self::uniqueShort($symptoms, 12);
        $bodies = self::uniqueShort($bodies, 8);
        $matched = self::uniqueShort($matched, 12);
        if ($gloss !== '' && mb_strlen($gloss) > 220) {
            $gloss = trim(mb_substr($gloss, 0, 220));
        }
        if (self::looksLikeTriageOrDiagnosis($gloss)) {
            $gloss = '';
        }

        if (!$used && $symptoms === [] && $gloss === '') {
            return self::emptyResult('no_python_signal');
        }

        return [
            'used' => true,
            'english_symptoms' => $symptoms,
            'english_gloss' => $gloss,
            'body_locations' => $bodies,
            'matched_terms' => $matched,
            'source' => 'python-ai-service',
            'reason' => 'enrichment_only',
        ];
    }

    /**
     * Merge enrichment into interview context / facts without touching triage class.
     *
     * @param array<string, mixed> $context
     * @param array<string, mixed> $enrichment
     * @return array<string, mixed>
     */
    public static function applyToContext(array $context, array $enrichment): array
    {
        if (empty($enrichment['used'])) {
            $context['python_enrichment'] = $enrichment;

            return $context;
        }

        $context['python_enrichment'] = $enrichment;

        if (!isset($context['facts']) || !is_array($context['facts'])) {
            $context['facts'] = [];
        }
        $facts = $context['facts'];
        $aiNames = is_array($enrichment['english_symptoms'] ?? null) ? $enrichment['english_symptoms'] : [];
        foreach ($aiNames as $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            // AI/Python labels → symptoms_ai; still merge into legacy symptoms for compatibility.
            $legacy = is_array($facts['symptoms'] ?? null) ? $facts['symptoms'] : [];
            $facts['symptoms'] = self::mergeStringLists($legacy, [$name]);
            $ai = is_array($facts['symptoms_ai'] ?? null) ? $facts['symptoms_ai'] : [];
            $facts['symptoms_ai'] = self::mergeStringLists($ai, [$name]);
        }
        $facts['body_locations'] = self::mergeStringLists(
            is_array($facts['body_locations'] ?? null) ? $facts['body_locations'] : [],
            is_array($enrichment['body_locations'] ?? null) ? $enrichment['body_locations'] : []
        );
        $context['facts'] = $facts;

        if (!isset($context['semantic_bridge']) || !is_array($context['semantic_bridge'])) {
            $context['semantic_bridge'] = [];
        }
        $gloss = trim((string) ($enrichment['english_gloss'] ?? ''));
        if ($gloss !== '') {
            $context['semantic_bridge']['python_gloss'] = $gloss;
        }
        if (!empty($enrichment['english_symptoms'])) {
            $context['semantic_bridge']['python_symptoms'] = array_values(
                array_map('strval', (array) $enrichment['english_symptoms'])
            );
        }

        return $context;
    }

    /**
     * @return array{
     *   used: bool,
     *   english_symptoms: list<string>,
     *   english_gloss: string,
     *   body_locations: list<string>,
     *   matched_terms: list<string>,
     *   source: string,
     *   reason: string
     * }
     */
    private static function emptyResult(string $reason): array
    {
        return [
            'used' => false,
            'english_symptoms' => [],
            'english_gloss' => '',
            'body_locations' => [],
            'matched_terms' => [],
            'source' => '',
            'reason' => $reason,
        ];
    }

    private static function timeoutSeconds(): int
    {
        $raw = getenv('MEDCONNECT_CLINICAL_PYTHON_TIMEOUT');
        if ($raw === false || $raw === '') {
            return self::DEFAULT_TIMEOUT;
        }

        return max(2, min(15, (int) $raw));
    }

    /**
     * Drop any triage authority fields from Python payloads before use.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function stripTriageAuthority(array $data): array
    {
        unset(
            $data['triage'],
            $data['clinical_recommendation'],
            $data['recommendation_payload']
        );
        if (isset($data['nlp_result']) && is_array($data['nlp_result'])) {
            $nlp = $data['nlp_result'];
            foreach ([
                'triage_level',
                'triage_display',
                'triage_reason',
                'classification',
                'priority',
                'severity',
                'severity_score',
                'recommendation',
                'needs_provider_review',
                'red_flags',
            ] as $key) {
                unset($nlp[$key]);
            }
            $data['nlp_result'] = $nlp;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function pickEnglishGloss(array $data): string
    {
        $nlp = is_array($data['nlp_result'] ?? null) ? $data['nlp_result'] : [];
        $candidates = [
            (string) ($nlp['english_translation'] ?? ''),
            (string) ($data['translated_english'] ?? ''),
            (string) (($data['translation']['english_text'] ?? '') ?: ''),
        ];
        foreach ($candidates as $c) {
            $c = trim($c);
            if ($c !== '' && !self::looksLikeTriageOrDiagnosis($c)) {
                return $c;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private static function symptomsFromAnalyze(array $data): array
    {
        $out = [];
        $nlp = is_array($data['nlp_result'] ?? null) ? $data['nlp_result'] : [];
        foreach (self::stringList($nlp['detected_symptoms'] ?? []) as $s) {
            $out[] = $s;
        }
        foreach ((array) ($data['detected_keywords'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $en = trim((string) ($row['english_term'] ?? ''));
            if ($en !== '') {
                $out[] = $en;
            }
        }
        foreach ((array) ($data['matched_records'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $en = trim((string) ($row['english_term'] ?? $row['condition_name'] ?? $row['name'] ?? ''));
            if ($en !== '') {
                $out[] = $en;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private static function bodyPartsFromAnalyze(array $data): array
    {
        $out = [];
        foreach ((array) ($data['detected_keywords'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $cat = strtolower((string) ($row['dictionary_category'] ?? $row['category'] ?? ''));
            $en = trim((string) ($row['english_term'] ?? ''));
            if ($en !== '' && (str_contains($cat, 'body') || str_contains($cat, 'anatomy'))) {
                $out[] = $en;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private static function matchedTermsFromAnalyze(array $data): array
    {
        $out = [];
        foreach ((array) ($data['detected_keywords'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $local = trim((string) ($row['local_term'] ?? ''));
            if ($local !== '') {
                $out[] = $local;
            }
        }

        return $out;
    }

    private static function looksLikeTriageOrDiagnosis(string $text): bool
    {
        return (bool) preg_match(
            '/\b(EMERGENCY|URGENT|NON-URGENT|diagnos|prescription|you have|seek (ER|emergency))\b/iu',
            $text
        );
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            $s = trim((string) $value);

            return $s !== '' ? [$s] : [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $s = trim((string) ($item['name'] ?? $item['english'] ?? $item['english_translation'] ?? ''));
            } else {
                $s = trim((string) $item);
            }
            if ($s !== '') {
                $out[] = $s;
            }
        }

        return $out;
    }

    /**
     * @param list<string> $items
     * @return list<string>
     */
    private static function uniqueShort(array $items, int $limit): array
    {
        $seen = [];
        $out = [];
        foreach ($items as $item) {
            $item = trim((string) $item);
            if ($item === '' || mb_strlen($item) > 80) {
                continue;
            }
            if (self::looksLikeTriageOrDiagnosis($item)) {
                continue;
            }
            $key = mb_strtolower($item);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $item;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param list<mixed> $a
     * @param list<mixed> $b
     * @return list<string>
     */
    private static function mergeStringLists(array $a, array $b): array
    {
        return self::uniqueShort(array_merge(self::stringList($a), self::stringList($b)), 20);
    }
}
