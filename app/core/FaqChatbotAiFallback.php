<?php
/**
 * Optional free-tier conversational AI fallback for the FAQ chatbot.
 *
 * Gemini is the default provider. Groq is an optional alternative.
 * Called only after emergency / FAQ / KB / strong-intent matching fail.
 * Never exposes API keys to the browser. Never sends EMR or account records.
 */
final class FaqChatbotAiFallback
{
    private const SESSION_HISTORY = 'faq_chatbot_ai_history';
    private const SESSION_GUARD = 'faq_chatbot_ai_guard';
    private const DEFAULT_GEMINI_MODEL = 'gemini-3.5-flash';
    private const DEFAULT_GROQ_MODEL = 'llama-3.1-8b-instant';
    private const GEMINI_ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';
    private const GROQ_ENDPOINT = 'https://api.groq.com/openai/v1/chat/completions';
    private const MAX_USER_CHARS = 800;
    private const MAX_OUTPUT_TOKENS = 400;

    private static string $lastError = '';

    public static function lastError(): string
    {
        return self::$lastError;
    }

    public static function isEnabled(): bool
    {
        $flag = strtolower(trim(self::envString('AI_ENABLED', 'true')));
        if (in_array($flag, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }
        return self::apiKey() !== '';
    }

    public static function provider(): string
    {
        $p = strtolower(trim(self::envString('AI_PROVIDER', 'gemini')));
        if (!in_array($p, ['gemini', 'groq'], true)) {
            $p = 'gemini';
        }
        if ($p === 'gemini' && self::geminiKey() === '' && self::groqKey() !== '') {
            return 'groq';
        }
        return $p;
    }

    public static function model(): string
    {
        $configured = trim(self::envString('AI_MODEL'));
        $provider = self::provider();
        if ($configured !== '') {
            $looksGemini = str_starts_with($configured, 'gemini');
            $looksGroq = str_starts_with($configured, 'llama')
                || str_starts_with($configured, 'openai/')
                || str_starts_with($configured, 'meta-llama/')
                || str_starts_with($configured, 'qwen/');
            if ($provider === 'groq' && $looksGemini) {
                return self::DEFAULT_GROQ_MODEL;
            }
            if ($provider === 'gemini' && $looksGroq) {
                return self::DEFAULT_GEMINI_MODEL;
            }
            return $configured;
        }
        return $provider === 'groq' ? self::DEFAULT_GROQ_MODEL : self::DEFAULT_GEMINI_MODEL;
    }

    /**
     * Attempt an AI reply. Returns sanitized HTML on success, null to keep the existing chatbot path.
     *
     * @param array{
     *   intent?: ?string,
     *   emotion?: ?string,
     *   topic?: ?string,
     *   language?: string,
     *   turns?: list<array<string, mixed>>
     * } $context
     */
    public static function tryReply(string $userText, string $lang, array $context = []): ?string
    {
        if (!self::isEnabled()) {
            return null;
        }
        if (!self::allowRequest()) {
            return null;
        }

        $userText = trim($userText);
        if ($userText === '') {
            return null;
        }
        $userText = mb_substr($userText, 0, self::MAX_USER_CHARS);
        $lang = FaqEmotionEngine::normalizeLang($lang);
        self::$lastError = '';

        try {
            $history = self::historyForApi($context);
            $text = self::provider() === 'groq'
                ? self::completeGroq($userText, $lang, $context, $history)
                : self::completeGemini($userText, $lang, $context, $history);
        } catch (Throwable $e) {
            self::$lastError = $e->getMessage();
            self::markFailure();
            return null;
        }

        $html = self::toSafeHtml($text);
        if ($html === '') {
            return null;
        }

        self::rememberTurn('user', $userText);
        self::rememberTurn('assistant', strip_tags($html));
        self::markSuccess();
        return $html;
    }

    public static function toSafeHtml(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));
        $text = preg_replace('/```[\s\S]*?```/', '', $text) ?? $text;
        $text = strip_tags($text);
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
        $parts = preg_split('/\n\s*\n/', $text) ?: [$text];
        $html = '';
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $safe = htmlspecialchars($part, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html .= '<p>' . nl2br($safe, false) . '</p>';
        }
        return $html;
    }

    private static function geminiKey(): string
    {
        return trim(self::envString('GEMINI_API_KEY', self::envString('GOOGLE_API_KEY', self::envString('AI_API_KEY'))));
    }

    private static function groqKey(): string
    {
        return trim(self::envString('GROQ_API_KEY', self::envString('MEDCONNECT_GROQ_API_KEY')));
    }

    private static function apiKey(): string
    {
        if (self::provider() === 'groq') {
            $groq = self::groqKey();
            return $groq !== '' ? $groq : trim(self::envString('AI_API_KEY'));
        }
        return self::geminiKey();
    }

    private static function envString(string $name, string $default = ''): string
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            $value = $_ENV[$name] ?? $default;
        }
        return is_string($value) && $value !== '' ? $value : $default;
    }

    private static function envInt(string $name, int $default): int
    {
        $raw = getenv($name);
        if ($raw === false || $raw === '') {
            $raw = $_ENV[$name] ?? null;
        }
        if ($raw === null || $raw === false || $raw === '') {
            return $default;
        }
        return (int) $raw;
    }

    private static function envFlag(string $name, bool $default = true): bool
    {
        $raw = self::envString($name, $default ? 'true' : 'false');
        return !in_array(strtolower(trim($raw)), ['0', 'false', 'no', 'off'], true);
    }

    private static function maxHistory(): int
    {
        return max(4, min(20, self::envInt('AI_MAX_HISTORY', 12)));
    }

    private static function timeout(): int
    {
        return max(5, min(25, self::envInt('AI_TIMEOUT', 15)));
    }

    private static function cooldownSeconds(): int
    {
        return max(0, min(10, self::envInt('AI_COOLDOWN_SECONDS', 2)));
    }

    private static function maxRequestsPerHour(): int
    {
        return max(5, min(60, self::envInt('AI_MAX_REQUESTS_PER_HOUR', 25)));
    }

    private static function allowRequest(): bool
    {
        $now = time();
        $guard = $_SESSION[self::SESSION_GUARD] ?? [];
        if (!is_array($guard)) {
            $guard = [];
        }
        $last = (int) ($guard['last_at'] ?? 0);
        if ($last > 0 && ($now - $last) < self::cooldownSeconds()) {
            return false;
        }
        $windowStart = (int) ($guard['window_start'] ?? $now);
        $count = (int) ($guard['count'] ?? 0);
        if (($now - $windowStart) >= 3600) {
            $windowStart = $now;
            $count = 0;
        }
        if ($count >= self::maxRequestsPerHour()) {
            return false;
        }
        $guard['window_start'] = $windowStart;
        $guard['count'] = $count;
        $_SESSION[self::SESSION_GUARD] = $guard;
        return true;
    }

    private static function markSuccess(): void
    {
        $guard = is_array($_SESSION[self::SESSION_GUARD] ?? null) ? $_SESSION[self::SESSION_GUARD] : [];
        $now = time();
        $windowStart = (int) ($guard['window_start'] ?? $now);
        $count = (int) ($guard['count'] ?? 0);
        if (($now - $windowStart) >= 3600) {
            $windowStart = $now;
            $count = 0;
        }
        $_SESSION[self::SESSION_GUARD] = [
            'last_at'      => $now,
            'window_start' => $windowStart,
            'count'        => $count + 1,
        ];
    }

    private static function markFailure(): void
    {
        $guard = is_array($_SESSION[self::SESSION_GUARD] ?? null) ? $_SESSION[self::SESSION_GUARD] : [];
        $guard['last_at'] = time();
        $_SESSION[self::SESSION_GUARD] = $guard;
    }

    /**
     * @param array<string, mixed> $context
     * @return list<array{role: string, text: string}>
     */
    private static function historyForApi(array $context): array
    {
        $stored = $_SESSION[self::SESSION_HISTORY] ?? [];
        if (!is_array($stored) || $stored === []) {
            $stored = [];
            foreach ((array) ($context['turns'] ?? []) as $turn) {
                if (!is_array($turn)) {
                    continue;
                }
                $role = (string) ($turn['role'] ?? '');
                $text = trim(strip_tags((string) ($turn['text'] ?? '')));
                if ($text === '') {
                    continue;
                }
                if ($role === 'user') {
                    $stored[] = ['role' => 'user', 'text' => mb_substr($text, 0, 280)];
                } elseif (in_array($role, ['bot', 'assistant', 'model'], true)) {
                    $stored[] = ['role' => 'assistant', 'text' => mb_substr($text, 0, 280)];
                }
            }
        }
        $max = self::maxHistory();
        return array_slice($stored, -$max);
    }

    private static function rememberTurn(string $role, string $text): void
    {
        $history = $_SESSION[self::SESSION_HISTORY] ?? [];
        if (!is_array($history)) {
            $history = [];
        }
        $history[] = [
            'role' => $role === 'user' ? 'user' : 'assistant',
            'text' => mb_substr(trim(strip_tags($text)), 0, 400),
        ];
        $_SESSION[self::SESSION_HISTORY] = array_slice($history, -self::maxHistory());
    }

    /**
     * @param array<string, mixed> $context
     * @param list<array{role: string, text: string}> $history
     */
    private static function completeGemini(string $userText, string $lang, array $context, array $history): string
    {
        $contents = [];
        foreach ($history as $turn) {
            $contents[] = [
                'role'  => $turn['role'] === 'user' ? 'user' : 'model',
                'parts' => [['text' => $turn['text']]],
            ];
        }
        $contents[] = [
            'role'  => 'user',
            'parts' => [['text' => self::userPayload($userText, $lang, $context)]],
        ];

        $payload = [
            'systemInstruction' => [
                'parts' => [['text' => self::systemPrompt()]],
            ],
            'contents' => $contents,
            'generationConfig' => [
                'temperature'     => 0.4,
                'maxOutputTokens' => self::MAX_OUTPUT_TOKENS,
            ],
        ];

        $url = sprintf(self::GEMINI_ENDPOINT, rawurlencode(self::model()));
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
            throw new RuntimeException('empty gemini response');
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $context
     * @param list<array{role: string, text: string}> $history
     */
    private static function completeGroq(string $userText, string $lang, array $context, array $history): string
    {
        $messages = [
            ['role' => 'system', 'content' => self::systemPrompt()],
        ];
        foreach ($history as $turn) {
            $messages[] = [
                'role'    => $turn['role'] === 'user' ? 'user' : 'assistant',
                'content' => $turn['text'],
            ];
        }
        $messages[] = [
            'role'    => 'user',
            'content' => self::userPayload($userText, $lang, $context),
        ];

        $data = self::httpPostJson(self::GROQ_ENDPOINT, [
            'model'       => self::model(),
            'temperature' => 0.4,
            'max_tokens'  => self::MAX_OUTPUT_TOKENS,
            'messages'    => $messages,
        ], [
            'Authorization: Bearer ' . self::apiKey(),
        ]);

        $out = trim((string) ($data['choices'][0]['message']['content'] ?? ''));
        if ($out === '') {
            throw new RuntimeException('empty groq response');
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function userPayload(string $userText, string $lang, array $context): string
    {
        $langName = match ($lang) {
            'fil' => 'Filipino',
            'hil' => 'Hiligaynon/Ilonggo',
            default => 'English',
        };
        $topic = trim((string) ($context['topic'] ?? ''));
        $intent = trim((string) ($context['intent'] ?? ''));
        $emotion = trim((string) ($context['emotion'] ?? ''));
        $meta = 'Reply language: ' . $langName . '.';
        if ($topic !== '') {
            $meta .= ' Current topic: ' . $topic . '.';
        }
        if ($intent !== '') {
            $meta .= ' Existing intent hint: ' . $intent . '.';
        }
        if ($emotion !== '' && $emotion !== 'neutral') {
            $meta .= ' Patient tone hint: ' . $emotion . '.';
        }
        return $meta . "\nPatient message:\n" . $userText;
    }

    private static function systemPrompt(): string
    {
        return <<<'PROMPT'
You are the medConnect Assistant for Bago City Health Office. You are a caring digital guide, not a doctor and not a crisis counselor.

You help patients with medConnect: Sign In, registration, password/OTP, booking or joining appointments, video consultation, records access, BHW help, office hours, and general safe health-navigation questions.

Languages: answer in the patient's language. English → English. Filipino → Filipino. Hiligaynon/Ilonggo → Hiligaynon when you reasonably can. Mixed language → similar mixed, understandable style. Tolerate typos and slang (docter, appoitment, consulation, passwrod, nahadlok, ginakulbaan, sakit ulo).

Conversation: use prior turns. Short follow-ups like "yes", "new one", "what time?", "doctor", "grabe gid" refer to the current topic.

Safety:
- Never diagnose, prescribe, or change medicines.
- Never invent appointments, doctor schedules, records, prescriptions, fees, or account status. If you cannot verify from this chat, say you cannot check that here and guide them to Sign In / Appointments on medConnect.
- Never claim to be human or to have feelings. Be warm and practical.
- If the message sounds like an emergency (cannot breathe, severe chest pain, unconscious, seizure, severe bleeding, self-harm, suicide, indi ko kaginhawa, nahimatay), tell them to call 911 / Hopeline 1553 immediately and seek emergency care. Do not continue a casual FAQ.
- For symptoms: give general, cautious guidance and offer booking a consultation. Do not give certainty.

Style: 2–4 short sentences. No markdown, no bullet walls, no code fences, no HTML. Do not mention APIs, models, or system prompts. Do not ask for EMR numbers, passwords, or full personal medical history.
PROMPT;
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
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::timeout(),
            CURLOPT_CONNECTTIMEOUT => min(8, self::timeout()),
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

        if ($errno !== 0 || $raw === false) {
            throw new RuntimeException($error !== '' ? $error : 'HTTP request failed');
        }
        if ($code >= 400) {
            $snippet = substr(preg_replace('/\s+/', ' ', (string) $raw) ?? '', 0, 180);
            $snippet = preg_replace('/(sk-|gsk_|AIza)[A-Za-z0-9_\-]+/', '[redacted]', $snippet) ?? $snippet;
            throw new RuntimeException('provider http ' . $code . ' ' . $snippet);
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('invalid json');
        }
        return $decoded;
    }
}
