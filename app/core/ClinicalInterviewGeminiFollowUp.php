<?php
/**
 * Gemini writes the next patient follow-up question after existing NLP
 * decides that clinical information is insufficient, ambiguous, unclear,
 * invalid, contradictory, or missing context needed for safe triage.
 *
 * Gemini does not classify triage and does not replace ClinicalTriageEngine.
 * If Gemini is unavailable, ClinicalFollowUpQuestionBank templates are used.
 */
final class ClinicalInterviewGeminiFollowUp
{
    private const DEFAULT_MODEL = 'gemini-3.5-flash';
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';
    private const MAX_QUESTION_CHARS = 280;

    private static string $lastError = '';

    public static function lastError(): string
    {
        return self::$lastError;
    }

    /**
     * @param array<string, mixed> $slot
     * @param array<string, mixed> $context
     */
    public static function phrase(array $slot, array $context, string $transcript, string $bankTemplate = ''): string
    {
        if (!self::enabled()) {
            self::$lastError = 'disabled';
            return '';
        }

        try {
            $raw = self::complete(self::userPrompt($slot, $context, $transcript, $bankTemplate));
            $text = self::sanitizeQuestion($raw);
            if ($text === '') {
                self::$lastError = 'rejected: ' . mb_substr(trim($raw), 0, 180);
            } else {
                self::$lastError = '';
            }
            return $text;
        } catch (Throwable $e) {
            self::$lastError = $e->getMessage();
            error_log('ClinicalInterviewGeminiFollowUp: ' . $e->getMessage());
            return '';
        }
    }

    public static function enabled(): bool
    {
        if (self::envFlag('MEDCONNECT_PHP_NLP_ONLY', false) || self::envFlag('MEDCONNECT_SKIP_GEMINI_FOLLOWUP', false)) {
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
     * @param array<string, mixed> $slot
     * @param array<string, mixed> $context
     */
    private static function userPrompt(array $slot, array $context, string $transcript, string $bankTemplate = ''): string
    {
        $lang = strtoupper((string) ($slot['language'] ?? $context['question_language'] ?? 'HILIGAYNON'));
        $langLine = match ($lang) {
            'TAGALOG', 'FILIPINO' => 'Tagalog/Filipino',
            'ENGLISH' => 'English',
            default => 'Hiligaynon/Ilonggo',
        };
        $complaints = [];
        foreach ((array) ($context['chief_complaints'] ?? []) as $row) {
            if (is_array($row)) {
                $complaints[] = trim((string) ($row['name'] ?? $row['id'] ?? ''));
            } elseif (is_string($row) && trim($row) !== '') {
                $complaints[] = trim($row);
            }
        }
        $facts = is_array($context['facts'] ?? null) ? $context['facts'] : [];
        $known = [];
        foreach ($facts['body_locations'] ?? [] as $loc) {
            if (is_string($loc) && $loc !== '') {
                $known[] = 'location=' . $loc;
            }
        }
        if (($facts['pain_score'] ?? null) !== null && $facts['pain_score'] !== '') {
            $known[] = 'pain_score=' . (int) $facts['pain_score'];
        }
        if (($facts['onset'] ?? '') !== '') {
            $known[] = 'onset=' . (string) $facts['onset'];
        }
        if (($facts['duration_label'] ?? '') !== '') {
            $known[] = 'duration=' . (string) $facts['duration_label'];
        }
        if (!empty($facts['denied_associated'])) {
            $known[] = 'patient_denied_other_symptoms=true';
        }
        $asked = array_values(array_filter(array_map('strval', (array) ($context['questions_asked'] ?? []))));
        $bankTemplate = trim($bankTemplate);

        return "Write one follow-up question a nurse would say out loud.\n"
            . "Language: {$langLine} only.\n"
            . 'Clinical purpose: ' . trim((string) ($slot['clinical_purpose'] ?? 'clarify the complaint')) . "\n"
            . ($bankTemplate !== '' ? "Keep the same meaning as this template: {$bankTemplate}\n" : '')
            . 'Detected complaints: ' . ($complaints !== [] ? implode(', ', $complaints) : '(unspecified)') . "\n"
            . 'Already known (do not ask again): ' . ($known !== [] ? implode('; ', $known) : '(none)') . "\n"
            . 'Already asked: ' . ($asked !== [] ? implode(', ', $asked) : '(none)') . "\n"
            . "Patient said: " . mb_substr(trim($transcript), 0, 800) . "\n"
            . "Reply with the question only. No preamble.";
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
            'temperature' => 0.3,
            'maxOutputTokens' => 1024,
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
            throw new RuntimeException('empty Gemini follow-up');
        }

        return $out;
    }

    private static function sanitizeQuestion(string $raw): string
    {
        $text = self::extractQuestionText($raw);
        $text = trim(strip_tags($text));
        $text = trim($text, " \t\n\r\0\x0B\"'`“”");
        $text = preg_replace('/^(question|follow-up|follow up)\s*:\s*/iu', '', $text) ?? $text;
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($text === '' || mb_strlen($text) > self::MAX_QUESTION_CHARS) {
            return '';
        }
        $words = preg_split('/\s+/u', $text) ?: [];
        if (count($words) < 4 || str_contains($text, '**') || str_contains($text, '```')) {
            return '';
        }
        if (preg_match('/^(rules|instructions|system|output|format)\b/iu', $text)) {
            return '';
        }
        $low = mb_strtolower($text);
        if (preg_match('/\b(json|gemini|nlp|triage|as an ai|here is the|as requested|follow-up slot)\b/u', $low)) {
            return '';
        }
        if (str_contains($low, 'you have') && preg_match('/\b(diagnosis|diagnosed|definitely)\b/u', $low)) {
            return '';
        }
        if (preg_match('/\b(EMERGENCY|URGENT|NON-URGENT|NON_URGENT)\b/u', $text) && !str_contains($low, '?')) {
            return '';
        }
        if (!str_contains($text, '?') && !preg_match('/^(diin|saan|where|ano|what|when|san-o|kailan|gaano|how|does|do you|is the|may|wala)/iu', $text)) {
            $text .= '?';
        }

        return $text;
    }

    private static function extractQuestionText(string $raw): string
    {
        $raw = trim(str_replace("\r\n", "\n", $raw));
        $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw) ?? $raw;
        $raw = preg_replace('/\s*```$/', '', $raw) ?? $raw;
        $raw = trim($raw);

        $parsed = json_decode($raw, true);
        if (is_array($parsed)) {
            $text = trim((string) ($parsed['text'] ?? $parsed['question'] ?? ''));
            if ($text !== '') {
                return $text;
            }
        }
        if (preg_match('/\{[\s\S]*\}/', $raw, $jsonMatch)) {
            $decoded = json_decode($jsonMatch[0], true);
            if (is_array($decoded)) {
                $text = trim((string) ($decoded['text'] ?? $decoded['question'] ?? ''));
                if ($text !== '') {
                    return $text;
                }
            }
        }

        return $raw;
    }

    private static function systemPrompt(): string
    {
        return <<<'PROMPT'
You write ONE follow-up question for medConnect preliminary triage.

Existing NLP already analyzed the complaint and decided that a follow-up is required because the information is insufficient, ambiguous, unclear, invalid, contradictory, or missing clinically relevant context.
You do NOT classify urgency. You do NOT diagnose. You do NOT invent symptoms, pain scores, vitals, or history.
Do NOT ask follow-up questions when the complaint already contains sufficient information for triage.

Rules:
- Ask exactly one simple spoken question that collects the required clinical purpose.
- Use the patient's language only (Hiligaynon/Ilonggo, Tagalog/Filipino, or English).
- Do not switch languages.
- Do not use medical jargon.
- Do not repeat facts the patient already provided.
- Do not mention datasets, JSON, NLP, Gemini, or triage colors.
- Output only the spoken question.
PROMPT;
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

        return max(4, min(12, $raw > 0 ? $raw : 10));
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
