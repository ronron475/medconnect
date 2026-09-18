<?php
/**
 * Gemini phrases ONE follow-up question after existing NLP selects the clinical slot.
 *
 * Slot selection / sufficiency stay in ClinicalInterviewAdaptivePolicy + question bank.
 * Gemini does not invent the clinical agenda, does not classify triage, and does not
 * replace ClinicalTriageEngine. If Gemini is unavailable, bank templates are used.
 *
 * For Hiligaynon/local complaints, the prompt receives BOTH the original patient text
 * and optional Ollama meaning support so wording stays faithful without dropping either.
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
        if (class_exists('ClinicalInterviewAdaptivePolicy')) {
            $summary = ClinicalInterviewAdaptivePolicy::fullCaseHaystack($context, $transcript, $facts);
            if ($summary !== '') {
                $known[] = 'case=' . mb_substr($summary, 0, 900);
            }
        }
        foreach ($facts['body_locations'] ?? [] as $loc) {
            if (is_string($loc) && $loc !== '') {
                $known[] = 'location=' . $loc;
            }
        }
        if (($facts['pain_score'] ?? null) !== null && $facts['pain_score'] !== '') {
            $known[] = 'pain_score=' . (int) $facts['pain_score'] . ' (scale 1-10)';
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
        foreach ((array) ($facts['associated_symptoms'] ?? []) as $sym) {
            $s = trim((string) $sym);
            if ($s !== '') {
                $known[] = 'associated=' . $s;
            }
        }
        $asked = array_values(array_filter(array_map('strval', (array) ($context['questions_asked'] ?? []))));
        $answered = [];
        foreach ((array) ($context['questions_answered'] ?? []) as $qa) {
            if (!is_array($qa)) {
                continue;
            }
            $qid = trim((string) ($qa['question_id'] ?? ''));
            $a = trim((string) ($qa['answer'] ?? $qa['patient_answer'] ?? ''));
            if ($qid !== '' || $a !== '') {
                $answered[] = trim($qid . '=' . $a);
            }
        }
        $bankTemplate = trim($bankTemplate);
        $qid = strtoupper(trim((string) ($slot['question_id'] ?? '')));
        $painRule = $qid === 'PAIN_SEVERITY'
            ? "If asking pain intensity, ALWAYS use a 1 to 10 scale (1=very mild, 10=worst). Never use 0–10.\n"
            : '';

        $bridge = is_array($context['semantic_bridge'] ?? null) ? $context['semantic_bridge'] : [];
        $originalComplaint = trim((string) (
            ($bridge['original'] ?? '')
            ?: ($context['chief_complaint'] ?? '')
            ?: (($context['complaint_text_cleaner']['original'] ?? '') ?: '')
        ));
        $ollamaMeaning = trim((string) ($bridge['ollama_meaning'] ?? ''));
        if ($ollamaMeaning !== '' && $originalComplaint !== ''
            && mb_strtolower($ollamaMeaning) === mb_strtolower($originalComplaint)
        ) {
            $ollamaMeaning = '';
        }

        return "Write one follow-up question a nurse would say out loud.\n"
            . "Language: {$langLine} only.\n"
            . 'Clinical purpose (chosen by existing NLP — phrase this purpose only): '
            . trim((string) ($slot['clinical_purpose'] ?? 'clarify the complaint')) . "\n"
            . 'Question slot id: ' . ($qid !== '' ? $qid : '(none)') . "\n"
            . $painRule
            . ($bankTemplate !== '' ? "Keep the same meaning as this template: {$bankTemplate}\n" : '')
            . 'Detected complaints: ' . ($complaints !== [] ? implode(', ', $complaints) : '(unspecified)') . "\n"
            . 'Original patient complaint (authoritative wording; never discard): '
            . ($originalComplaint !== '' ? mb_substr($originalComplaint, 0, 400) : '(none)') . "\n"
            . 'Ollama/local meaning support (secondary; may be empty; do not invent beyond this): '
            . ($ollamaMeaning !== '' ? mb_substr($ollamaMeaning, 0, 240) : '(none)') . "\n"
            . 'Already known from the COMPLETE case (do not ask again): ' . ($known !== [] ? implode('; ', $known) : '(none)') . "\n"
            . 'Prior answers: ' . ($answered !== [] ? implode(' | ', $answered) : '(none)') . "\n"
            . 'Already asked slots: ' . ($asked !== [] ? implode(', ', $asked) : '(none)') . "\n"
            . 'Accumulated case text: ' . mb_substr(trim($transcript), 0, 800) . "\n"
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
You phrase ONE follow-up question for medConnect preliminary triage.

Existing NLP (adaptive policy + question bank) already chose the clinical purpose / slot.
You only write the spoken wording for that purpose. You do NOT invent a different clinical agenda.
You do NOT classify urgency. You do NOT diagnose. You do NOT invent symptoms, pain scores, vitals, or history.
Do NOT ask for information already present in the case (primary complaint, prior answers, or extracted facts).
Do NOT ask a generic fixed questionnaire. Ask only what is still unknown and clinically useful for THIS case.

When both an original patient complaint and an Ollama/local meaning support are provided:
- Treat the original patient wording as authoritative.
- Use the Ollama meaning only as secondary understanding help.
- Do not replace or discard the original language meaning.
- Do not invent clinical facts that appear in neither source.

CRITICAL — never assume unsupported clinical facts:
- Do not assume pain, body location, severity, duration, associated symptoms, or risk factors unless the patient already stated them.
- If the clinical purpose is to clarify a vague/unspecific complaint, ask what symptoms they feel / what is wrong — not a pain-location question unless pain is already established.
- Match the question to the clinical purpose and the patient's actual wording.

Rules:
- Ask exactly one simple spoken question that collects the required clinical purpose.
- Use the patient's language only (Hiligaynon/Ilonggo, Tagalog/Filipino, or English).
- Do not switch languages.
- Do not use medical jargon.
- Do not repeat facts the patient already provided.
- For pain intensity, always use a 1–10 scale (1=very mild, 10=worst). Never use 0–10.
- Do not mention datasets, JSON, NLP, Gemini, Ollama, or triage colors.
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
