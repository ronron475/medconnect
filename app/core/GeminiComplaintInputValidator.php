<?php
/**
 * Gemini semantic check: is this text a meaningful health complaint?
 *
 * Does NOT diagnose, triage, or replace PHP NLP / ClinicalTriageEngine.
 * On timeout/error/disabled → returns unavailable so PHP validation continues alone.
 */
final class GeminiComplaintInputValidator
{
    public const CLASS_VALID = 'VALID_MEDICAL_COMPLAINT';
    public const CLASS_INVALID = 'INVALID_MEDICAL_INPUT';

    private const DEFAULT_MODEL = 'gemini-3.5-flash';
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    private static string $lastError = '';

    public static function lastError(): string
    {
        return self::$lastError;
    }

    /**
     * @return array{
     *   available: bool,
     *   is_medical_complaint: bool|null,
     *   classification: string|null,
     *   confidence: float|null,
     *   error: string
     * }
     */
    public static function validate(string $complaintText): array
    {
        self::$lastError = '';
        $text = trim($complaintText);
        if ($text === '') {
            return self::pack(false, false, self::CLASS_INVALID, 1.0, 'empty');
        }
        if (!self::enabled()) {
            return self::unavailable('disabled');
        }

        try {
            $raw = self::complete($text);
            $parsed = self::parseStructured($raw);
            if ($parsed === null) {
                self::$lastError = 'unparseable: ' . mb_substr($raw, 0, 160);

                return self::unavailable(self::$lastError);
            }

            return $parsed;
        } catch (Throwable $e) {
            self::$lastError = $e->getMessage();
            error_log('GeminiComplaintInputValidator: ' . $e->getMessage());

            return self::unavailable(self::$lastError);
        }
    }

    public static function enabled(): bool
    {
        if (self::envFlag('MEDCONNECT_PHP_NLP_ONLY', false)
            || self::envFlag('MEDCONNECT_SKIP_GEMINI_VALIDATION', false)
        ) {
            return false;
        }
        if (!self::envFlag('AI_ENABLED', true)) {
            return false;
        }
        $provider = strtolower(trim(self::envString('AI_PROVIDER', 'gemini')));
        if ($provider !== '' && $provider !== 'gemini') {
            return false;
        }

        return self::apiKey() !== '';
    }

    /**
     * @return array{
     *   available: bool,
     *   is_medical_complaint: bool|null,
     *   classification: string|null,
     *   confidence: float|null,
     *   error: string
     * }|null
     */
    private static function parseStructured(string $raw): ?array
    {
        $raw = trim(str_replace("\r\n", "\n", $raw));
        $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw) ?? $raw;
        $raw = preg_replace('/\s*```$/', '', $raw) ?? $raw;
        $raw = trim($raw);

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) && preg_match('/\{[\s\S]*\}/', $raw, $m)) {
            $decoded = json_decode($m[0], true);
        }
        if (!is_array($decoded)) {
            return null;
        }

        $class = strtoupper(trim((string) ($decoded['classification'] ?? '')));
        $class = str_replace([' ', '-'], '_', $class);
        if (in_array($class, ['VALID', 'VALID_MEDICAL', 'HEALTH_RELATED', 'HEALTH', 'MEDICAL'], true)) {
            $class = self::CLASS_VALID;
        }
        if (in_array($class, ['INVALID', 'NON_MEDICAL', 'OUT_OF_SCOPE', 'NON_HEALTH_RELATED', 'NON_HEALTH'], true)) {
            $class = self::CLASS_INVALID;
        }
        if ($class === 'UNCLEAR' || $class === 'AMBIGUOUS' || $class === 'UNCERTAIN') {
            $confidence = null;
            if (isset($decoded['confidence']) && is_numeric($decoded['confidence'])) {
                $confidence = (float) $decoded['confidence'];
                if ($confidence > 1.0 && $confidence <= 100.0) {
                    $confidence /= 100.0;
                }
                $confidence = max(0.0, min(1.0, $confidence));
            }

            return self::pack(true, null, 'UNCLEAR', $confidence, '');
        }
        if (!in_array($class, [self::CLASS_VALID, self::CLASS_INVALID], true)) {
            if (array_key_exists('is_medical_complaint', $decoded)) {
                $class = !empty($decoded['is_medical_complaint']) ? self::CLASS_VALID : self::CLASS_INVALID;
            } else {
                return null;
            }
        }

        $isMedical = $class === self::CLASS_VALID;
        if (array_key_exists('is_medical_complaint', $decoded)) {
            $isMedical = (bool) $decoded['is_medical_complaint'];
            $class = $isMedical ? self::CLASS_VALID : self::CLASS_INVALID;
        }

        $confidence = null;
        if (isset($decoded['confidence']) && is_numeric($decoded['confidence'])) {
            $confidence = (float) $decoded['confidence'];
            if ($confidence > 1.0 && $confidence <= 100.0) {
                $confidence /= 100.0;
            }
            $confidence = max(0.0, min(1.0, $confidence));
        }

        $corrected = trim((string) ($decoded['corrected'] ?? $decoded['corrected_text'] ?? ''));
        $corrected = trim((string) preg_replace('/\s+/u', ' ', $corrected));
        if (mb_strlen($corrected) > 240 || preg_match('/\b(EMERGENCY|URGENT|NON-URGENT|diagnos)/u', $corrected)) {
            $corrected = '';
        }
        $concept = trim((string) ($decoded['medical_concept'] ?? ''));
        if (mb_strlen($concept) > 80) {
            $concept = '';
        }

        return self::pack(true, $isMedical, $class, $confidence, '', $corrected, $concept);
    }

    private static function complete(string $complaintText): string
    {
        $payload = self::requestPayload($complaintText, true);
        try {
            return self::generateFromPayload($payload);
        } catch (RuntimeException $e) {
            if (!str_contains($e->getMessage(), 'Gemini HTTP 400')) {
                throw $e;
            }

            return self::generateFromPayload(self::requestPayload($complaintText, false));
        }
    }

    /** @return array<string, mixed> */
    private static function requestPayload(string $complaintText, bool $withThinkingConfig): array
    {
        $config = [
            'temperature' => 0.1,
            'maxOutputTokens' => 256,
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
                'parts' => [['text' => "Patient input:\n" . mb_substr($complaintText, 0, 800)]],
            ]],
            'generationConfig' => $config,
        ];
    }

    /** @param array<string, mixed> $payload */
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
            throw new RuntimeException('empty Gemini validation response');
        }

        return $out;
    }

    /**
     * Semantic check only: does this follow-up answer the current clinical question?
     * Does not diagnose or triage.
     *
     * @param array<string, mixed> $input
     * @return array{
     *   available: bool,
     *   is_meaningful: bool|null,
     *   is_relevant: bool|null,
     *   answers_question: bool|null,
     *   corrected_answer: string|null,
     *   extracted_information: array<string, mixed>,
     *   reason: string
     * }
     */
    public static function validateFollowUpAnswer(array $input): array
    {
        self::$lastError = '';
        $answer = trim((string) ($input['answer'] ?? ''));
        if ($answer === '') {
            return [
                'available' => true,
                'is_meaningful' => false,
                'is_relevant' => false,
                'answers_question' => false,
                'corrected_answer' => null,
                'extracted_information' => [],
                'reason' => 'empty',
            ];
        }
        if (!self::enabled()) {
            return [
                'available' => false,
                'is_meaningful' => null,
                'is_relevant' => null,
                'answers_question' => null,
                'corrected_answer' => null,
                'extracted_information' => [],
                'reason' => 'disabled',
            ];
        }

        try {
            $raw = self::completeFollowUp($input);
            $parsed = self::parseFollowUpStructured($raw);
            if ($parsed === null) {
                self::$lastError = 'unparseable follow-up: ' . mb_substr($raw, 0, 160);

                return [
                    'available' => false,
                    'is_meaningful' => null,
                    'is_relevant' => null,
                    'answers_question' => null,
                    'corrected_answer' => null,
                    'extracted_information' => [],
                    'reason' => self::$lastError,
                ];
            }

            return $parsed;
        } catch (Throwable $e) {
            self::$lastError = $e->getMessage();
            error_log('GeminiComplaintInputValidator follow-up: ' . $e->getMessage());

            return [
                'available' => false,
                'is_meaningful' => null,
                'is_relevant' => null,
                'answers_question' => null,
                'corrected_answer' => null,
                'extracted_information' => [],
                'reason' => self::$lastError,
            ];
        }
    }

    /** @param array<string, mixed> $input */
    private static function completeFollowUp(array $input): string
    {
        $payload = self::followUpRequestPayload($input, true);
        try {
            return self::generateFromPayload($payload);
        } catch (RuntimeException $e) {
            if (!str_contains($e->getMessage(), 'Gemini HTTP 400')) {
                throw $e;
            }

            return self::generateFromPayload(self::followUpRequestPayload($input, false));
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private static function followUpRequestPayload(array $input, bool $withThinkingConfig): array
    {
        $config = [
            'temperature' => 0.1,
            'maxOutputTokens' => 320,
            'responseMimeType' => 'application/json',
        ];
        if ($withThinkingConfig) {
            $config['thinkingConfig'] = ['thinkingBudget' => 0];
        }

        $user = "Primary complaint:\n" . mb_substr((string) ($input['primary_complaint'] ?? ''), 0, 400)
            . "\nNormalized concepts:\n" . mb_substr((string) ($input['normalized_complaint'] ?? ''), 0, 300)
            . "\nPatient language:\n" . mb_substr((string) ($input['language'] ?? ''), 0, 40)
            . "\nCurrent follow-up question:\n" . mb_substr((string) ($input['question'] ?? ''), 0, 400)
            . "\nExpected clinical field:\n" . mb_substr((string) ($input['expected_field'] ?? ''), 0, 80)
            . "\nKnown valid clinical information:\n" . mb_substr(json_encode($input['known_facts'] ?? [], JSON_UNESCAPED_UNICODE) ?: '', 0, 400)
            . "\nPatient answer:\n" . mb_substr((string) ($input['answer'] ?? ''), 0, 400)
            . "\nTypo-corrected answer:\n" . mb_substr((string) ($input['corrected_answer'] ?? ''), 0, 400);

        return [
            'systemInstruction' => [
                'parts' => [['text' => self::followUpSystemPrompt()]],
            ],
            'contents' => [[
                'role' => 'user',
                'parts' => [['text' => $user]],
            ]],
            'generationConfig' => $config,
        ];
    }

    /** @return array<string, mixed>|null */
    private static function parseFollowUpStructured(string $raw): ?array
    {
        $raw = trim(str_replace("\r\n", "\n", $raw));
        $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw) ?? $raw;
        $raw = preg_replace('/\s*```$/', '', $raw) ?? $raw;
        $raw = trim($raw);

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) && preg_match('/\{[\s\S]*\}/', $raw, $m)) {
            $decoded = json_decode($m[0], true);
        }
        if (!is_array($decoded)) {
            return null;
        }

        $extracted = $decoded['extracted_information'] ?? [];
        if (!is_array($extracted)) {
            $extracted = [];
        }
        $corrected = trim((string) ($decoded['corrected_answer'] ?? ''));
        if ($corrected === '' || preg_match('/\b(EMERGENCY|URGENT|NON-URGENT|diagnos)/u', $corrected)) {
            $corrected = '';
        }

        return [
            'available' => true,
            'is_meaningful' => !empty($decoded['is_meaningful']),
            'is_relevant' => !empty($decoded['is_relevant']),
            'answers_question' => !empty($decoded['answers_question']),
            'corrected_answer' => $corrected !== '' ? $corrected : null,
            'extracted_information' => $extracted,
            'reason' => mb_substr(trim((string) ($decoded['reason'] ?? '')), 0, 240),
        ];
    }

    private static function followUpSystemPrompt(): string
    {
        return <<<'PROMPT'
You are a follow-up answer relevance validator for a medical consultation system.

Determine ONLY:
1. Is the answer meaningful (not nonsense)?
2. Is it relevant to the primary complaint?
3. Does it answer the current follow-up question / expected clinical field?
4. Can minor spelling mistakes be corrected?
5. What clinical information can safely be extracted from what the patient actually stated?

Do not diagnose.
Do not determine urgency.
Do not determine triage (EMERGENCY / URGENT / NON-URGENT).
Do not invent symptoms or clinical information.

A grammatically meaningful sentence can still be clinically irrelevant.
Yes/no may be valid only when the question expects that (e.g. other symptoms).
A bare 0-10 number may be valid for pain severity, but "my dog is 5 years old" is not.

Accept English, Hiligaynon/Ilonggo, Tagalog/Filipino, mixed language, and minor typos.

Return ONLY JSON:
{"is_meaningful":true,"is_relevant":true,"answers_question":true,"corrected_answer":"Yesterday","extracted_information":{"duration":"1 day"},"reason":"..."}
or
{"is_meaningful":true,"is_relevant":false,"answers_question":false,"corrected_answer":null,"extracted_information":{},"reason":"..."}
PROMPT;
    }

    private static function systemPrompt(): string
    {
        return <<<'PROMPT'
You are a semantic input validator for a medical consultation system.

Determine ONLY whether the patient's text is:
1. HEALTH_RELATED — a meaningful health concern, symptom, injury, body feeling, or reason for seeking care
2. NON_HEALTH_RELATED — greeting, joke, test input, casual chat, or clearly non-medical
3. UNCLEAR — cannot tell; patient should rephrase

Do not diagnose the patient.
Do not invent disease names the patient did not imply.
Do not determine urgency.
Do not determine triage (EMERGENCY / URGENT / NON-URGENT).
Do not provide medical advice.

CRITICAL:
Dataset miss ≠ invalid. Unknown Hiligaynon / Visayan / Tagalog / English / mixed informal words
that still sound like a bodily complaint or health feeling MUST be classified HEALTH_RELATED.

When HEALTH_RELATED and you recognize the lexical meaning of a local/informal health expression,
put a short English medical concept in medical_concept and a plain English restatement in corrected.
This is language understanding for the existing NLP bridge only — not a patient diagnosis.
Apply this dynamically to whatever local expression the patient used; do not rely on a fixed word list.

If you are unsure of the exact meaning but it is clearly health-related:
classification HEALTH_RELATED with empty medical_concept/corrected is OK.

Reject as NON_HEALTH_RELATED:
- nonsense / keyboard smashing
- random characters
- testing / prank input
- greetings (hello, hi)
- casual conversation with no health intent

Return ONLY JSON:
{"is_medical_complaint":true,"classification":"HEALTH_RELATED","confidence":0.9,"corrected":"<english restatement if known>","medical_concept":"<short english concept if known>"}
or
{"is_medical_complaint":false,"classification":"NON_HEALTH_RELATED","confidence":0.95,"corrected":"","medical_concept":""}
or
{"is_medical_complaint":false,"classification":"UNCLEAR","confidence":0.4,"corrected":"","medical_concept":""}

Never map greetings or keyboard smash to medical terms.
Never put EMERGENCY / URGENT / NON-URGENT in any field.

Allowed classification values:
HEALTH_RELATED
NON_HEALTH_RELATED
UNCLEAR
VALID_MEDICAL_COMPLAINT
INVALID_MEDICAL_INPUT
PROMPT;
    }

    /**
     * @return array{
     *   available: bool,
     *   is_medical_complaint: bool|null,
     *   classification: string|null,
     *   confidence: float|null,
     *   error: string,
     *   corrected_text: string,
     *   medical_concept: string
     * }
     */
    private static function pack(
        bool $available,
        ?bool $isMedical,
        ?string $classification,
        ?float $confidence,
        string $error,
        string $corrected = '',
        string $concept = ''
    ): array {
        return [
            'available' => $available,
            'is_medical_complaint' => $isMedical,
            'classification' => $classification,
            'confidence' => $confidence,
            'error' => $error,
            'corrected_text' => $corrected,
            'medical_concept' => $concept,
        ];
    }

    /** @return array{available:bool,is_medical_complaint:null,classification:null,confidence:null,error:string} */
    private static function unavailable(string $error): array
    {
        return self::pack(false, null, null, null, $error);
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
        $raw = (int) self::envString('AI_TIMEOUT', '8');

        return max(3, min(10, $raw > 0 ? $raw : 8));
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
            CURLOPT_CONNECTTIMEOUT => min(5, self::timeout()),
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
        $raw = getenv($key);
        if ($raw === false || $raw === '') {
            $raw = $_ENV[$key] ?? null;
        }
        if ($raw === null || $raw === '') {
            return $default;
        }

        return !in_array(strtolower(trim((string) $raw)), ['0', 'false', 'no', 'off'], true);
    }
}
