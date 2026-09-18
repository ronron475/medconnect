<?php
/**
 * Secondary Gemini verification for Hiligaynon body-location terms
 * that local CSV/datasets could not confidently resolve.
 *
 * Never invents anatomy, symptoms, severity, duration, diagnosis, or triage.
 */

final class GeminiBodyLocationVerifier
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';
    private const DEFAULT_MODEL = 'gemini-3.5-flash';

    private static string $lastError = '';

    public static function lastError(): string
    {
        return self::$lastError;
    }

    public static function enabled(): bool
    {
        if (self::envFlag('MEDCONNECT_PHP_NLP_ONLY', false)
            || self::envFlag('MEDCONNECT_SKIP_GEMINI_VALIDATION', false)
            || self::envFlag('MEDCONNECT_SKIP_GEMINI_BODY_LOCATION', false)
        ) {
            return false;
        }
        if (!self::envFlag('AI_ENABLED', true)) {
            return false;
        }

        return self::apiKey() !== '';
    }

    /**
     * @param list<array<string, mixed>> $localMatches
     * @return array{
     *   available:bool,
     *   verification:string,
     *   canonical_body_location:?string,
     *   normalized_term:?string,
     *   confidence:float,
     *   supported_by_patient_wording:bool,
     *   reason:string,
     *   is_medical_complaint:?bool
     * }
     */
    public static function verify(
        string $originalText,
        string $detectedLanguage = '',
        array $localMatches = [],
        array $localEvidence = []
    ): array {
        self::$lastError = '';
        $originalText = trim($originalText);
        if ($originalText === '') {
            return self::pack(true, 'uncertain', null, null, 0.0, false, 'empty_input', null);
        }
        if (!self::enabled()) {
            return self::pack(false, 'unavailable', null, null, 0.0, false, 'gemini_disabled', null);
        }

        try {
            $raw = self::complete($originalText, $detectedLanguage, $localMatches, $localEvidence);
            $parsed = self::parse($raw, $originalText);
            if ($parsed === null) {
                self::$lastError = 'unparseable: ' . mb_substr($raw, 0, 160);

                return self::pack(true, 'uncertain', null, null, 0.0, false, 'unparseable_response', null);
            }

            return $parsed;
        } catch (Throwable $e) {
            self::$lastError = $e->getMessage();
            error_log('GeminiBodyLocationVerifier: ' . $e->getMessage());

            return self::pack(false, 'unavailable', null, null, 0.0, false, 'gemini_error', null);
        }
    }

    /**
     * @param list<array<string, mixed>> $localMatches
     * @param array<string, mixed> $localEvidence
     */
    private static function complete(
        string $originalText,
        string $detectedLanguage,
        array $localMatches,
        array $localEvidence
    ): string {
        $payload = self::requestPayload($originalText, $detectedLanguage, $localMatches, $localEvidence, true);
        $attempts = 0;
        $last = null;
        while ($attempts < 3) {
            $attempts++;
            try {
                return self::generateFromPayload($payload);
            } catch (Throwable $e) {
                $last = $e;
                $msg = $e->getMessage();
                if (str_contains($msg, 'Gemini HTTP 400') && $attempts === 1) {
                    $payload = self::requestPayload($originalText, $detectedLanguage, $localMatches, $localEvidence, false);
                    continue;
                }
                if (preg_match('/Gemini HTTP (429|500|502|503)/', $msg) && $attempts < 3) {
                    usleep(200000 * $attempts);
                    continue;
                }
                throw $e;
            }
        }
        throw $last ?? new RuntimeException('Gemini body-location verification failed');
    }

    /**
     * @param list<array<string, mixed>> $localMatches
     * @param array<string, mixed> $localEvidence
     * @return array<string, mixed>
     */
    private static function requestPayload(
        string $originalText,
        string $detectedLanguage,
        array $localMatches,
        array $localEvidence,
        bool $withThinkingConfig
    ): array {
        $config = [
            'temperature' => 0.0,
            'maxOutputTokens' => 220,
            'responseMimeType' => 'application/json',
        ];
        if ($withThinkingConfig) {
            $config['thinkingConfig'] = ['thinkingBudget' => 0];
        }

        $localJson = json_encode(array_values($localMatches), JSON_UNESCAPED_UNICODE);
        $evidenceJson = json_encode($localEvidence, JSON_UNESCAPED_UNICODE);
        $user = "Detected language: " . ($detectedLanguage !== '' ? $detectedLanguage : 'unknown') . "\n"
            . "Original patient wording (preserve; do not replace):\n" . mb_substr($originalText, 0, 800) . "\n\n"
            . "Local CSV/dataset matches (may be empty):\n" . mb_substr((string) $localJson, 0, 1200) . "\n\n"
            . "Local evidence notes:\n" . mb_substr((string) $evidenceJson, 0, 800) . "\n\n"
            . "Return JSON only.";

        return [
            'systemInstruction' => [
                'parts' => [['text' => self::systemPrompt()]],
            ],
            'contents' => [[
                'role' => 'user',
                'parts' => [['text' => $user]],
            ]],
            'generationConfig' => $config,
        ];
    }

    private static function systemPrompt(): string
    {
        return <<<'PROMPT'
You verify Hiligaynon/Filipino/English patient complaint wording for BODY LOCATION only.

Local medical CSV datasets already failed or were low-confidence. You are a SECONDARY check.

Return ONLY JSON:
{
  "verification": "confirmed_body_location" | "uncertain" | "not_medical",
  "canonical_body_location": "english anatomy term or null",
  "normalized_term": "the patient word/phrase that denotes the site, or null",
  "confidence": 0.0-1.0,
  "supported_by_patient_wording": true|false,
  "is_medical_complaint": true|false|null,
  "reason": "short reason"
}

Rules:
- Do NOT invent a body location not supported by the patient's words.
- Do NOT diagnose, prescribe, triage, or invent severity/duration/symptoms.
- If a Hiligaynon slang/alias clearly means a body part in context (e.g. pain + genital slang), verification=confirmed_body_location.
- If unclear whether a token is anatomy, verification=uncertain and canonical_body_location=null.
- If the whole input is clearly not a health complaint, verification=not_medical.
- canonical_body_location must be a plain English anatomy word (head, abdomen, vagina, ear, arm, foot, throat, ...).
- Preserve meaning of the original wording; never rewrite the patient complaint.
PROMPT;
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
            throw new RuntimeException('empty Gemini body-location response');
        }

        return $out;
    }

    /**
     * @return array{
     *   available:bool,
     *   verification:string,
     *   canonical_body_location:?string,
     *   normalized_term:?string,
     *   confidence:float,
     *   supported_by_patient_wording:bool,
     *   reason:string,
     *   is_medical_complaint:?bool
     * }|null
     */
    private static function parse(string $raw, string $originalText): ?array
    {
        $raw = trim(str_replace("\r\n", "\n", $raw));
        $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw) ?? $raw;
        $raw = preg_replace('/\s*```$/', '', $raw) ?? $raw;
        $decoded = json_decode(trim($raw), true);
        if (!is_array($decoded) && preg_match('/\{[\s\S]*\}/', $raw, $m)) {
            $decoded = json_decode($m[0], true);
        }
        if (!is_array($decoded)) {
            return null;
        }

        $verification = strtolower(trim((string) ($decoded['verification'] ?? '')));
        $verification = str_replace([' ', '-'], '_', $verification);
        if (in_array($verification, ['confirmed', 'confirmed_body_location', 'body_location', 'medical_body_location'], true)) {
            $verification = 'confirmed_body_location';
        } elseif (in_array($verification, ['not_medical', 'non_medical', 'invalid', 'non_health'], true)) {
            $verification = 'not_medical';
        } else {
            $verification = 'uncertain';
        }

        $canonical = strtolower(trim((string) ($decoded['canonical_body_location'] ?? '')));
        if ($canonical === '' || $canonical === 'null' || $canonical === 'none') {
            $canonical = null;
        } else {
            $canonical = preg_replace('/[^a-z\s\-]/u', '', $canonical) ?? $canonical;
            $canonical = trim(preg_replace('/\s+/u', ' ', $canonical) ?? $canonical);
            if ($canonical === '' || mb_strlen($canonical) > 40) {
                $canonical = null;
            }
        }

        $normalized = strtolower(trim((string) ($decoded['normalized_term'] ?? '')));
        if ($normalized === '' || $normalized === 'null') {
            $normalized = null;
        } else {
            $normalized = trim(preg_replace('/\s+/u', ' ', $normalized) ?? $normalized);
            // Must appear in patient wording (support check) — avoid free invention.
            $hay = mb_strtolower($originalText, 'UTF-8');
            if ($normalized !== null && $normalized !== '' && !str_contains($hay, $normalized)) {
                // Allow if tokens are in the original text.
                $ok = true;
                foreach (preg_split('/\s+/u', $normalized) ?: [] as $tok) {
                    if ($tok !== '' && !preg_match('/\b' . preg_quote($tok, '/') . '\b/u', $hay)) {
                        $ok = false;
                        break;
                    }
                }
                if (!$ok) {
                    $normalized = null;
                    if ($verification === 'confirmed_body_location') {
                        $verification = 'uncertain';
                        $canonical = null;
                    }
                }
            }
        }

        $confidence = 0.0;
        if (isset($decoded['confidence']) && is_numeric($decoded['confidence'])) {
            $confidence = (float) $decoded['confidence'];
            if ($confidence > 1.0 && $confidence <= 100.0) {
                $confidence /= 100.0;
            }
            $confidence = max(0.0, min(1.0, $confidence));
        }

        $supported = !empty($decoded['supported_by_patient_wording']);
        if ($verification === 'confirmed_body_location' && (!$supported || $canonical === null)) {
            $verification = 'uncertain';
            $canonical = null;
        }

        $isMedical = null;
        if (array_key_exists('is_medical_complaint', $decoded) && $decoded['is_medical_complaint'] !== null) {
            $isMedical = (bool) $decoded['is_medical_complaint'];
        }
        if ($verification === 'not_medical') {
            $isMedical = false;
            $canonical = null;
        }

        $reason = trim((string) ($decoded['reason'] ?? ''));
        if (mb_strlen($reason) > 200) {
            $reason = mb_substr($reason, 0, 200);
        }

        return self::pack(true, $verification, $canonical, $normalized, $confidence, $supported, $reason, $isMedical);
    }

    /**
     * @return array{
     *   available:bool,
     *   verification:string,
     *   canonical_body_location:?string,
     *   normalized_term:?string,
     *   confidence:float,
     *   supported_by_patient_wording:bool,
     *   reason:string,
     *   is_medical_complaint:?bool
     * }
     */
    private static function pack(
        bool $available,
        string $verification,
        ?string $canonical,
        ?string $normalized,
        float $confidence,
        bool $supported,
        string $reason,
        ?bool $isMedical
    ): array {
        return [
            'available' => $available,
            'verification' => $verification,
            'canonical_body_location' => $canonical,
            'normalized_term' => $normalized,
            'confidence' => $confidence,
            'supported_by_patient_wording' => $supported,
            'reason' => $reason,
            'is_medical_complaint' => $isMedical,
        ];
    }

    /** @param array<string, mixed> $payload @param list<string> $extraHeaders @return array<string, mixed> */
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
        $raw = (int) self::envString('AI_TIMEOUT', '15');

        return max(3, min(25, $raw > 0 ? $raw : 15));
    }

    private static function envString(string $key, string $default = ''): string
    {
        $v = $_ENV[$key] ?? getenv($key);
        if ($v === false || $v === null) {
            return $default;
        }

        return trim((string) $v);
    }

    private static function envFlag(string $key, bool $default): bool
    {
        $v = $_ENV[$key] ?? getenv($key);
        if ($v === false || $v === null || $v === '') {
            return $default;
        }
        $s = strtolower(trim((string) $v));

        return in_array($s, ['1', 'true', 'yes', 'on'], true);
    }
}
