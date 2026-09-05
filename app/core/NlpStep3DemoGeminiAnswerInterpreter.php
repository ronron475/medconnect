<?php
/**
 * DEMO ONLY — Gemini fallback for interpreting follow-up answers in nlp_step3_demo.
 *
 * Does not diagnose or triage. Existing NLP remains primary.
 * Reuses the same Gemini env keys as ClinicalInterviewGeminiFollowUp / FAQ.
 */
final class NlpStep3DemoGeminiAnswerInterpreter
{
    public const MIN_CONFIDENCE = 0.70;

    private const DEFAULT_MODEL = 'gemini-3.5-flash';
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    private const ALLOWED_TYPES = [
        'ONSET_DURATION',
        'PAIN_SEVERITY',
        'PAIN_LOCATION',
        'YES_NO',
        'ASSOCIATED_SYMPTOMS',
        'CHARACTER',
        'OTHER_CLINICAL',
        'AMBIGUOUS',
        'UNRELATED',
    ];

    private static string $lastError = '';

    public static function lastError(): string
    {
        return self::$lastError;
    }

    public static function enabled(): bool
    {
        if (self::envFlag('MEDCONNECT_PHP_NLP_ONLY', false)) {
            return false;
        }
        if (self::envFlag('MEDCONNECT_DEMO_GEMINI_ANSWER_DISABLED', false)) {
            return false;
        }
        if (!self::envFlag('AI_ENABLED', true)) {
            return false;
        }

        return self::apiKey() !== '';
    }

    public static function minConfidence(): float
    {
        $raw = self::envString('MEDCONNECT_DEMO_GEMINI_ANSWER_MIN_CONFIDENCE', (string) self::MIN_CONFIDENCE);
        $v = (float) $raw;
        if ($v <= 0 || $v > 1) {
            return self::MIN_CONFIDENCE;
        }

        return $v;
    }

    /**
     * Whether existing NLP already understands this follow-up answer for the awaiting slot.
     *
     * @param array<string, mixed> $prior
     */
    public static function nlpUnderstandsAnswer(string $turn, string $awaiting, array $prior = []): bool
    {
        unset($prior);
        $turn = trim($turn);
        $awaiting = strtoupper(trim($awaiting));
        if ($turn === '') {
            return false;
        }

        return match ($awaiting) {
            'PAIN_SEVERITY' => (
                (ClinicalFeatureExtractors::extractPainScale($turn)['score'] ?? null) !== null
                || ClinicalFeatureExtractors::extractStandalonePainScore($turn, true) !== null
            ),
            'PAIN_LOCATION' => ClinicalFeatureExtractors::extractBodyLocations($turn) !== [],
            'ONSET', 'DURATION' => (
                trim((string) (ClinicalFeatureExtractors::extractDuration($turn)['label'] ?? '')) !== ''
                || ClinicalFeatureExtractors::extractOnset($turn) !== ''
            ),
            'ASSOCIATED_SYMPTOMS',
            'ABDOMINAL_ASSOCIATED',
            'NEURO_WEAKNESS',
            'NEURO_SPEECH',
            'NEURO_VISION',
            'BREATHING_SEVERITY',
            'BLEEDING_CONTINUING',
            'BLEEDING_HEAVY',
            'BLEEDING_DIZZY',
            'CHEST_RADIATION',
            'CHEST_SWEATING' => (
                ClinicalFeatureExtractors::extractYesNo($turn) !== null
                || ClinicalFeatureExtractors::deniedAssociatedSymptoms($turn)
            ),
            default => (
                !ClinicalFeatureExtractors::isUnclearAnswer($turn)
                && (
                    ClinicalFeatureExtractors::extractBodyLocations($turn) !== []
                    || trim((string) (ClinicalFeatureExtractors::extractDuration($turn)['label'] ?? '')) !== ''
                    || ClinicalFeatureExtractors::extractOnset($turn) !== ''
                    || (ClinicalFeatureExtractors::extractPainScale($turn)['score'] ?? null) !== null
                    || ClinicalFeatureExtractors::extractYesNo($turn) !== null
                    || ClinicalFeatureExtractors::deniedAssociatedSymptoms($turn)
                )
            ),
        };
    }

    /**
     * Demo-only Hiligaynon/English semantic bridge used when Gemini is unavailable
     * or returns nothing usable. Does not invent exact durations.
     *
     * @return array<string, mixed>|null
     */
    public static function tryLocalSemanticInterpretation(string $turn, string $awaiting): ?array
    {
        $awaiting = strtoupper(trim($awaiting));
        $low = mb_strtolower(trim($turn));
        $low = trim((string) preg_replace('/[^\p{L}\p{N}\s\-]+/u', ' ', $low));
        $low = trim((string) preg_replace('/\s+/u', ' ', $low));
        if ($low === '') {
            return null;
        }

        // Clearly unrelated to a clinical follow-up.
        if (self::looksUnrelatedAnswer($low, $awaiting)) {
            return [
                'relevant' => false,
                'understood' => true,
                'answer_type' => 'UNRELATED',
                'normalized_value' => null,
                'confidence' => 0.95,
                'needs_clarification' => true,
                'clarification_reason' => 'The response does not answer the current clinical question.',
                'source' => 'demo_semantic_lexicon',
            ];
        }

        if (in_array($awaiting, ['ONSET', 'DURATION'], true)) {
            $map = [
                'ligad pa' => ['started earlier', false],
                'ligad pa gid' => ['started earlier', false],
                'ligad-ligad pa' => ['started earlier', false],
                'sang ligad pa' => ['started earlier', false],
                'halin pa sang una' => ['started some time ago', true],
                'sang una pa' => ['started some time ago', true],
                'sang una pa gid' => ['started some time ago', true],
                'daw sang una pa' => ['started some time ago', true],
                'daw sang una pa gid' => ['started some time ago', true],
                'daw sang una pa gid to' => ['started some time ago', true],
                'kanina pa' => ['earlier today', false],
                'kanina pa gid' => ['earlier today', false],
                'dugay-dugay na' => ['for a long time', false],
                'dugay dugay na' => ['for a long time', false],
                'matagal na' => ['for a long time', false],
                'for a while' => ['for a while', false],
                'some time ago' => ['some time ago', false],
                'started earlier' => ['started earlier', false],
                'kagapon' => ['Since yesterday', false],
                'halin kagapon' => ['Since yesterday', false],
                'yesterday pa' => ['Since yesterday', false],
                'halin pa yesterday' => ['Since yesterday', false],
                'since yesterday' => ['Since yesterday', false],
            ];
            if (isset($map[$low])) {
                [$value, $needsClarify] = $map[$low];

                return [
                    'relevant' => true,
                    'understood' => !$needsClarify,
                    'answer_type' => 'ONSET_DURATION',
                    'normalized_value' => $value,
                    'confidence' => $needsClarify ? 0.72 : 0.90,
                    'needs_clarification' => $needsClarify,
                    'clarification_reason' => $needsClarify
                        ? 'The patient indicates the pain started earlier but does not provide a clear timeframe.'
                        : null,
                    'source' => 'demo_semantic_lexicon',
                ];
            }

            // Fuzzy contains for common stems without inventing exact day counts.
            if (preg_match('/\bligad\b/u', $low)) {
                return [
                    'relevant' => true,
                    'understood' => true,
                    'answer_type' => 'ONSET_DURATION',
                    'normalized_value' => 'started earlier',
                    'confidence' => 0.86,
                    'needs_clarification' => false,
                    'clarification_reason' => null,
                    'source' => 'demo_semantic_lexicon',
                ];
            }
            if (preg_match('/\b(sang una|una pa)\b/u', $low) && !preg_match('/\b(\d+|duha|tatlo|apat|lima)\s*(ka\s*)?(adlaw|oras|hours?|days?)\b/u', $low)) {
                return [
                    'relevant' => true,
                    'understood' => false,
                    'answer_type' => 'ONSET_DURATION',
                    'normalized_value' => 'started some time ago',
                    'confidence' => 0.74,
                    'needs_clarification' => true,
                    'clarification_reason' => 'The patient indicates the pain started earlier but does not provide a clear timeframe.',
                    'source' => 'demo_semantic_lexicon',
                ];
            }
        }

        if ($awaiting === 'PAIN_LOCATION') {
            $locs = ClinicalFeatureExtractors::extractBodyLocations($low);
            if ($locs !== []) {
                return [
                    'relevant' => true,
                    'understood' => true,
                    'answer_type' => 'PAIN_LOCATION',
                    'normalized_value' => $locs[0],
                    'confidence' => 0.92,
                    'needs_clarification' => false,
                    'clarification_reason' => null,
                    'source' => 'demo_semantic_lexicon',
                ];
            }
        }

        return null;
    }

    private static function looksUnrelatedAnswer(string $low, string $awaiting): bool
    {
        unset($awaiting);
        if (preg_match('/\b(sakit|masakit|pain|oras|adlaw|gahapon|kahapon|ligad|dugay|subong|kanina|ulo|tiyan|dughan|yes|no|oo|indi|wala)\b/u', $low)) {
            return false;
        }
        if (preg_match('/\b(basketball|football|soccer|blue|red|green|yellow|pink|mahilig|maglaro|hobby|movie|youtube)\b/u', $low)) {
            return true;
        }
        if (preg_match('/^(blue|red|green|yellow|ok|lol|haha+)$/u', $low)) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $context
     * @return array{
     *   called: bool,
     *   available: bool,
     *   status: string,
     *   reason: string,
     *   interpretation: ?array<string, mixed>,
     *   primary_nlp: string,
     *   fallback: string
     * }
     */
    public static function interpret(
        string $patientAnswer,
        string $questionText,
        string $questionId,
        string $expectedType,
        array $context
    ): array {
        self::$lastError = '';
        $base = [
            'called' => false,
            'available' => self::enabled(),
            'status' => 'NOT_CALLED',
            'reason' => '',
            'interpretation' => null,
            'primary_nlp' => 'LOW_CONFIDENCE',
            'fallback' => 'none',
        ];

        // 1) Gemini when configured
        if (self::enabled()) {
            $base['called'] = true;
            $base['reason'] = 'Existing NLP confidence below threshold for this follow-up answer';
            $base['fallback'] = 'gemini';

            try {
                $raw = self::complete(self::userPrompt($patientAnswer, $questionText, $questionId, $expectedType, $context));
                $parsed = self::parseAndValidate($raw);
                if ($parsed === null) {
                    $base['status'] = 'INVALID_JSON';
                    $base['reason'] = 'Gemini returned invalid JSON';
                    self::$lastError = $base['reason'] . ': ' . mb_substr($raw, 0, 160);
                } else {
                    $conf = (float) ($parsed['confidence'] ?? 0);
                    if ($conf < self::minConfidence()) {
                        $parsed['needs_clarification'] = true;
                        if (empty($parsed['clarification_reason'])) {
                            $parsed['clarification_reason'] = 'Gemini confidence below threshold; ask the patient to clarify.';
                        }
                        $base['status'] = 'LOW_CONFIDENCE';
                    } elseif (!empty($parsed['needs_clarification']) || empty($parsed['understood']) || empty($parsed['relevant'])) {
                        $base['status'] = !empty($parsed['relevant']) ? 'NEEDS_CLARIFICATION' : 'UNRELATED';
                    } else {
                        $base['status'] = 'OK';
                    }
                    unset($parsed['triage'], $parsed['triage_display'], $parsed['urgency'], $parsed['classification']);
                    $parsed['source'] = 'gemini';
                    $base['interpretation'] = $parsed;
                    self::$lastError = '';

                    // Accept usable Gemini result (including clarification/unrelated holds).
                    if ($base['status'] !== 'INVALID_JSON') {
                        return $base;
                    }
                }
            } catch (Throwable $e) {
                $base['status'] = 'ERROR';
                $base['reason'] = 'Gemini fallback error: ' . $e->getMessage();
                self::$lastError = $base['reason'];
                error_log('NlpStep3DemoGeminiAnswerInterpreter: ' . $e->getMessage());
            }
        } else {
            $base['status'] = 'UNAVAILABLE';
            $base['reason'] = 'Gemini fallback unavailable (no API key or disabled)';
            self::$lastError = $base['reason'];
        }

        // 2) Demo semantic lexicon bridge (keeps interview moving when Gemini is down)
        $local = self::tryLocalSemanticInterpretation($patientAnswer, $questionId);
        if (is_array($local)) {
            $base['fallback'] = 'demo_semantic_lexicon';
            $base['interpretation'] = $local;
            if (empty($local['relevant'])) {
                $base['status'] = 'UNRELATED';
                $base['reason'] = 'Existing NLP low confidence; semantic lexicon marked answer unrelated'
                    . (!self::enabled() ? ' (Gemini unavailable)' : ' (after Gemini failure)');
            } elseif (!empty($local['needs_clarification'])) {
                $base['status'] = 'NEEDS_CLARIFICATION';
                $base['reason'] = 'Existing NLP low confidence; semantic lexicon needs clarification'
                    . (!self::enabled() ? ' (Gemini unavailable)' : '');
            } else {
                $base['status'] = 'DEMO_SEMANTIC_FALLBACK';
                $base['reason'] = 'Existing NLP low confidence; '
                    . (!self::enabled()
                        ? 'Gemini unavailable — demo Hiligaynon semantic lexicon applied'
                        : 'Gemini failed — demo Hiligaynon semantic lexicon applied');
            }

            return $base;
        }

        if ($base['status'] === 'UNAVAILABLE') {
            return $base;
        }

        return $base;
    }

    /**
     * Map a successful interpretation into interview facts + a turn NLP can absorb.
     *
     * @param array<string, mixed> $prior
     * @param array<string, mixed> $interp
     * @return array{prior: array<string, mixed>, turn: string}
     */
    public static function applyToPrior(array $prior, string $turn, array $interp, string $awaiting): array
    {
        $facts = is_array($prior['facts'] ?? null) ? $prior['facts'] : [];
        $value = trim((string) ($interp['normalized_value'] ?? ''));
        $type = strtoupper((string) ($interp['answer_type'] ?? ''));
        $awaiting = strtoupper($awaiting);
        // Keep the full patient utterance so out-of-order multi-facts are not discarded.
        $enrichedTurn = $turn;
        $isRich = str_contains($turn, ' ') || mb_strlen(trim($turn)) > 24;

        if ($value !== '') {
            // Prefer mapping through existing extractors when possible; never invent exact durations.
            if ($type === 'ONSET_DURATION' || in_array($awaiting, ['ONSET', 'DURATION'], true)) {
                $dur = ClinicalFeatureExtractors::extractDuration($value . ' ' . $turn);
                if (trim((string) ($dur['label'] ?? '')) !== '') {
                    $facts['duration_label'] = (string) $dur['label'];
                    if (!$isRich) {
                        $enrichedTurn = (string) $dur['label'];
                    }
                } else {
                    $facts['duration_label'] = $value;
                    if (!$isRich) {
                        $enrichedTurn = $value;
                    }
                }
                $onset = ClinicalFeatureExtractors::extractOnset($value . ' ' . $turn);
                if ($onset !== '' && ($facts['onset'] ?? '') === '') {
                    $facts['onset'] = $onset;
                }
            } elseif ($type === 'PAIN_LOCATION' || $awaiting === 'PAIN_LOCATION') {
                $locs = ClinicalFeatureExtractors::extractBodyLocations($value . ' ' . $turn);
                if ($locs === []) {
                    $map = [
                        'head' => 'head', 'ulo' => 'head',
                        'chest' => 'chest', 'dughan' => 'chest',
                        'abdomen' => 'abdomen', 'stomach' => 'abdomen', 'tiyan' => 'abdomen',
                        'back' => 'back', 'neck' => 'neck', 'eye' => 'eye', 'mata' => 'eye',
                    ];
                    $low = mb_strtolower($value);
                    foreach ($map as $term => $canonical) {
                        if (str_contains($low, $term) && !in_array($canonical, $locs, true)) {
                            $locs[] = $canonical;
                        }
                    }
                }
                foreach ($locs as $loc) {
                    if (!in_array($loc, $facts['body_locations'] ?? [], true)) {
                        $facts['body_locations'][] = $loc;
                    }
                }
                if ($locs !== [] && !$isRich) {
                    $enrichedTurn = $locs[0];
                }
            } elseif ($type === 'PAIN_SEVERITY' || $awaiting === 'PAIN_SEVERITY') {
                $score = ClinicalFeatureExtractors::extractStandalonePainScore($value, true)
                    ?? (ClinicalFeatureExtractors::extractPainScale($value . ' ' . $turn)['score'] ?? null);
                if ($score !== null) {
                    $facts['pain_score'] = (int) $score;
                    if (!$isRich) {
                        $enrichedTurn = ((int) $score) . '/10';
                    }
                }
            } elseif ($type === 'YES_NO' || $type === 'ASSOCIATED_SYMPTOMS') {
                $yn = ClinicalFeatureExtractors::extractYesNo($value . ' ' . $turn);
                if ($yn === true) {
                    if (!$isRich) {
                        $enrichedTurn = 'yes';
                    }
                    $facts['has_other_symptoms'] = true;
                } elseif ($yn === false) {
                    if (!$isRich) {
                        $enrichedTurn = 'no';
                    }
                    $facts['denied_associated'] = true;
                    $facts['has_other_symptoms'] = false;
                }
            } elseif ($type === 'CHARACTER' && $value !== '') {
                $facts['pain_qualifier'] = $value;
            }
        }

        // Sweep all clinically supported facts from the original answer text.
        if (class_exists('NlpStep3DemoClinicalState')) {
            $facts = NlpStep3DemoClinicalState::enrichInterviewFacts($facts, $turn . ($value !== '' ? ' ' . $value : ''));
        }

        $prior['facts'] = $facts;

        return ['prior' => $prior, 'turn' => $enrichedTurn !== '' ? $enrichedTurn : $turn];
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function userPrompt(
        string $patientAnswer,
        string $questionText,
        string $questionId,
        string $expectedType,
        array $context
    ): string {
        $facts = is_array($context['facts'] ?? null) ? $context['facts'] : [];
        $known = [];
        if (($facts['pain_score'] ?? null) !== null) {
            $known[] = 'pain_severity=' . (int) $facts['pain_score'] . '/10';
        }
        foreach ((array) ($facts['body_locations'] ?? []) as $loc) {
            if (is_string($loc) && $loc !== '') {
                $known[] = 'location=' . $loc;
            }
        }
        if (($facts['onset'] ?? '') !== '') {
            $known[] = 'onset=' . (string) $facts['onset'];
        }
        if (($facts['duration_label'] ?? '') !== '') {
            $known[] = 'duration=' . (string) $facts['duration_label'];
        }

        $schema = <<<'JSON'
{"relevant":true,"understood":true,"answer_type":"ONSET_DURATION","normalized_value":"started earlier","confidence":0.9,"needs_clarification":false,"clarification_reason":null,"extracted_facts":{"location":"head","severity":"7/10","onset":"yesterday","character":"pulsating"}}
JSON;

        return "CURRENT QUESTION:\n" . trim($questionText) . "\n\n"
            . "QUESTION TYPE:\n" . strtoupper($questionId) . "\n\n"
            . "EXPECTED INFORMATION:\n" . strtoupper($expectedType) . "\n\n"
            . "ACCUMULATED CLINICAL INFORMATION:\n" . ($known !== [] ? implode('; ', $known) : 'complaint in progress') . "\n\n"
            . "PATIENT'S LATEST ANSWER:\n" . mb_substr(trim($patientAnswer), 0, 700) . "\n\n"
            . "Return ONLY valid JSON matching this schema example:\n{$schema}\n"
            . "answer_type must be one of: " . implode(', ', self::ALLOWED_TYPES) . "\n"
            . "If the answer contains multiple clinically supported facts, include them in extracted_facts.\n"
            . "Do not invent exact durations, symptoms, diagnoses, or triage classes.\n"
            . "confidence must be a number from 0 to 1.";
    }

    private static function systemPrompt(): string
    {
        return <<<'PROMPT'
You are a clinical interview response comprehension assistant for medConnect DEMO only.

Your task is ONLY to determine whether the patient's latest answer answers the CURRENT QUESTION,
and to extract any additional clinically supported facts from that answer.

Interpret Hiligaynon, English, and mixed Hiligaynon-English naturally.

Do not diagnose.
Do not recommend treatment.
Do not prescribe medication.
Do not determine triage.
Do not determine emergency, urgent, or non-urgent classification.
Do not invent information not supported by the patient's words.
If the patient gives a vague but clinically meaningful answer, preserve uncertainty rather than inventing an exact value.

If the answer is ambiguous, mark it ambiguous.
If the answer is unrelated, mark it unrelated.

Return ONLY valid JSON. No markdown. No prose outside JSON.
PROMPT;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function parseAndValidate(string $raw): ?array
    {
        $raw = trim(str_replace("\r\n", "\n", $raw));
        $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw) ?? $raw;
        $raw = preg_replace('/\s*```$/', '', $raw) ?? $raw;
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data) && preg_match('/\{[\s\S]*\}/', $raw, $m)) {
            $data = json_decode($m[0], true);
        }
        if (!is_array($data)) {
            return null;
        }

        foreach (['relevant', 'understood', 'answer_type', 'confidence', 'needs_clarification'] as $req) {
            if (!array_key_exists($req, $data)) {
                return null;
            }
        }

        $conf = $data['confidence'];
        if (!is_numeric($conf)) {
            return null;
        }
        $conf = (float) $conf;
        if ($conf < 0 || $conf > 1) {
            return null;
        }

        $type = strtoupper(trim((string) $data['answer_type']));
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            $type = 'AMBIGUOUS';
        }

        $normalized = $data['normalized_value'] ?? null;
        if ($normalized !== null && !is_scalar($normalized)) {
            $normalized = null;
        }
        $normalized = $normalized === null ? null : trim((string) $normalized);
        if ($normalized !== null && mb_strlen($normalized) > 240) {
            $normalized = mb_substr($normalized, 0, 240);
        }
        // Refuse triage-like invented values.
        if ($normalized !== null && preg_match('/\b(EMERGENCY|URGENT|NON-?URGENT|NON_URGENT)\b/i', $normalized)) {
            $normalized = null;
        }

        return [
            'relevant' => (bool) $data['relevant'],
            'understood' => (bool) $data['understood'],
            'answer_type' => $type,
            'normalized_value' => $normalized,
            'confidence' => $conf,
            'needs_clarification' => (bool) $data['needs_clarification'],
            'clarification_reason' => isset($data['clarification_reason']) && is_scalar($data['clarification_reason'])
                ? trim((string) $data['clarification_reason'])
                : null,
        ];
    }

    private static function complete(string $userPrompt): string
    {
        $payload = self::requestPayload($userPrompt, true);
        try {
            return self::generateFromPayload($payload);
        } catch (RuntimeException $e) {
            if (!str_contains($e->getMessage(), 'Gemini HTTP 400')) {
                throw $e;
            }

            return self::generateFromPayload(self::requestPayload($userPrompt, false));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function requestPayload(string $userPrompt, bool $withThinkingConfig): array
    {
        $config = [
            'temperature' => 0.1,
            'maxOutputTokens' => 512,
            'responseMimeType' => 'application/json',
        ];
        if ($withThinkingConfig) {
            $config['thinkingConfig'] = ['thinkingBudget' => 0];
        }

        return [
            'systemInstruction' => [
                'parts' => [['text' => self::systemPrompt()]],
            ],
            'contents' => [[
                'role' => 'user',
                'parts' => [['text' => $userPrompt]],
            ]],
            'generationConfig' => $config,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function generateFromPayload(array $payload): string
    {
        $url = sprintf(self::ENDPOINT, rawurlencode(self::model()));
        $data = self::httpPostJson($url, $payload, [
            'x-goog-api-key: ' . self::apiKey(),
        ]);
        $parts = $data['candidates'][0]['content']['parts'] ?? [];
        $out = '';
        if (is_array($parts)) {
            foreach ($parts as $part) {
                if (is_array($part) && isset($part['text'])) {
                    $out .= (string) $part['text'];
                }
            }
        }
        $out = trim($out);
        if ($out === '') {
            throw new RuntimeException('empty Gemini interpretation');
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $extraHeaders
     * @return array<string, mixed>
     */
    private static function httpPostJson(string $url, array $payload, array $extraHeaders): array
    {
        $headers = array_merge(['Content-Type: application/json'], $extraHeaders);
        $verifySsl = self::envFlag('AI_SSL_VERIFY', true);
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::timeout(),
            CURLOPT_CONNECTTIMEOUT => min(6, self::timeout()),
            CURLOPT_SSL_VERIFYPEER => $verifySsl,
            CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
        ]);
        $ca = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'cacert.pem';
        if (!is_readable($ca)) {
            $ca = (string) (ini_get('curl.cainfo') ?: ini_get('openssl.cafile') ?: '');
        }
        if ($ca !== '' && is_readable($ca)) {
            curl_setopt($ch, CURLOPT_CAINFO, $ca);
        }
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $errno !== 0) {
            throw new RuntimeException('Gemini HTTP error: ' . ($error !== '' ? $error : 'errno ' . $errno));
        }
        $data = json_decode((string) $raw, true);
        if (!is_array($data) || $code >= 400) {
            throw new RuntimeException('Gemini HTTP ' . $code);
        }

        return $data;
    }

    private static function apiKey(): string
    {
        return trim(self::envString('GEMINI_API_KEY', self::envString('GOOGLE_API_KEY', self::envString('AI_API_KEY'))));
    }

    private static function model(): string
    {
        $model = trim(self::envString('AI_MODEL', self::DEFAULT_MODEL));

        return $model !== '' ? $model : self::DEFAULT_MODEL;
    }

    private static function timeout(): int
    {
        $raw = (int) self::envString('AI_TIMEOUT', '10');

        return max(4, min(15, $raw > 0 ? $raw : 10));
    }

    private static function envString(string $key, string $default = ''): string
    {
        $raw = getenv($key);
        if ($raw === false || $raw === '') {
            $raw = $_ENV[$key] ?? $default;
        }

        return is_string($raw) ? $raw : $default;
    }

    private static function envFlag(string $key, bool $default): bool
    {
        $raw = self::envString($key, $default ? '1' : '0');
        $low = strtolower(trim($raw));

        if (in_array($low, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($low, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        return $default;
    }
}
