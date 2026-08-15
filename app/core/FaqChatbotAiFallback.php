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

    /** Generic menu/capability cards — not a real answer to the patient's message. */
    private const GENERIC_KB_KEYS = [
        'capabilities', 'navigation_help', 'greeting', 'identity', 'small_talk',
        'uncertainty_support', 'clarify', 'not_understood', 'topic_menu',
        'help_general', 'confused_start', 'health_education', 'stress_support',
    ];

    /** Emotion cards should yield to a natural Gemini reply unless crisis/emergency already fired. */
    private const EMOTION_KB_KEYS = [
        'fear_support', 'anxiety_support', 'sadness_support', 'anger_support', 'crying_support',
        'need_to_talk', 'panic_support', 'mixed_feelings', 'disappointment_support',
        'burnout_support', 'homesickness', 'guilt_support', 'shame_support',
        'afraid_of_doctor', 'cant_sleep', 'emotion_talk', 'emotion_hope',
        'emotion_social', 'emotion_exam', 'emotion_and_symptoms',
    ];

    public static function isGenericKnowledgeKey(?string $key): bool
    {
        $key = strtolower(trim((string) $key));
        return $key !== '' && in_array($key, self::GENERIC_KB_KEYS, true);
    }

    public static function isEmotionKnowledgeKey(?string $key): bool
    {
        $key = strtolower(trim((string) $key));
        return $key !== '' && in_array($key, self::EMOTION_KB_KEYS, true);
    }

    public static function isGenericFallbackHtml(string $html): bool
    {
        $plain = strtolower(trim(preg_replace('/\s+/', ' ', strip_tags($html)) ?? ''));
        if ($plain === '') {
            return true;
        }
        $needles = [
            "i'm here to help with medconnect",
            'nandito ako para tumulong sa medconnect',
            'diri ako para buligan ka sa medconnect',
            'i can help you with appointments, registration',
            'i can guide you with booking, login',
            "i'm here for appointments, registration, account help",
            'please choose one',
            'please select',
            "i didn't quite understand",
            'did not quite understand',
            'could you share a bit more',
            'what can i help you with today',
            'how can i help?',
            'ano ang matabangan ko',
            'choose one of the options',
        ];
        foreach ($needles as $needle) {
            if (str_contains($plain, $needle)) {
                return true;
            }
        }
        return false;
    }

    public static function isClearGreeting(string $text): bool
    {
        $t = FaqEmotionEngine::normalizeText($text);
        return $t !== ''
            && mb_strlen($t) <= 40
            && (bool) preg_match('/^(hi|hello|hey|helo|hola|kumusta|musta|maayong|good\s+(morning|afternoon|evening))(\b|!|\.|,)/ui', $t);
    }

    public static function isClearThanks(string $text): bool
    {
        $t = FaqEmotionEngine::normalizeText($text);
        return (bool) preg_match('/^(thanks|thank\s+you|thankyou|salamat|ty|tysm)(\b|!|\.|$)/ui', $t)
            && mb_strlen($t) <= 48;
    }

    public static function currentMessageSupportsFaq(string $text, array $faqRow): bool
    {
        $hay = FaqEmotionEngine::normalizeText(
            (string) ($faqRow['question'] ?? '') . ' ' . (string) ($faqRow['keywords'] ?? '') . ' ' . (string) ($faqRow['category'] ?? '')
        );
        $tokens = self::contentTokens($text);
        if ($tokens === []) {
            return false;
        }
        $hits = 0;
        foreach ($tokens as $tok) {
            if (str_contains($hay, $tok)) {
                $hits++;
            }
        }
        $score = (float) ($faqRow['score'] ?? 0);
        return $hits >= 2 || ($hits >= 1 && $score >= 2.45);
    }

    /**
     * True when the existing FAQ/KB hit is a real answer to THIS utterance (not a generic fallback).
     *
     * @param array<string, mixed>|null $faqRow
     * @param array<string, mixed>|null $kbHit
     */
    public static function shouldUseDatasetAnswer(
        string $text,
        bool $isEmergency,
        ?int $faqId,
        ?array $faqRow,
        ?array $kbHit,
        string $html = ''
    ): bool {
        if ($isEmergency) {
            return true;
        }
        $kbKey = is_array($kbHit) ? strtolower(trim((string) ($kbHit['key'] ?? ''))) : '';
        if (in_array($kbKey, ['crisis_hopeless', 'emergency_redirect'], true)) {
            return true;
        }
        if (self::isClearGreeting($text) && $kbKey === 'greeting') {
            return true;
        }
        if (self::isClearThanks($text) && $kbKey === 'thank_you') {
            return true;
        }
        if ($faqId !== null && is_array($faqRow) && self::currentMessageSupportsFaq($text, $faqRow) && !self::isGenericFallbackHtml($html)) {
            return true;
        }
        if (!is_array($kbHit) || (float) ($kbHit['score'] ?? 0) < 1.85) {
            return false;
        }
        if (self::isGenericKnowledgeKey($kbKey) || self::isEmotionKnowledgeKey($kbKey)) {
            return false;
        }
        if (self::isGenericFallbackHtml((string) ($kbHit['html'] ?? $html))) {
            return false;
        }
        $tokens = self::contentTokens($text);
        if ($tokens === []) {
            return false;
        }
        $kbHay = FaqEmotionEngine::normalizeText($kbKey . ' ' . str_replace('_', ' ', $kbKey) . ' ' . strip_tags((string) ($kbHit['html'] ?? '')));
        $hits = 0;
        foreach ($tokens as $tok) {
            if (str_contains($kbHay, $tok)) {
                $hits++;
            }
        }
        return $hits >= 1;
    }

    /** @return list<string> */
    private static function contentTokens(string $text): array
    {
        $norm = FaqEmotionEngine::normalizeText($text);
        $parts = preg_split('/\s+/u', $norm, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $stop = [
            'the', 'and', 'for', 'with', 'this', 'that', 'what', 'when', 'where', 'how',
            'can', 'you', 'your', 'are', 'sure', 'please', 'just', 'about', 'have', 'from',
            'will', 'would', 'could', 'should', 'want', 'need', 'help', 'then', 'them',
            'they', 'their', 'there', 'here', 'into', 'onto', 'also', 'very', 'much',
            'ano', 'ang', 'mga', 'ako', 'ko', 'sa', 'ng', 'nang', 'ba', 'po',
            'ikaw', 'kag', 'kon', 'lang', 'gid', 'man', 'indi', 'wala',
        ];
        $out = [];
        foreach ($parts as $part) {
            $part = trim((string) $part, '?!.,;:\'"');
            if (mb_strlen($part) < 4 || in_array($part, $stop, true)) {
                continue;
            }
            $out[] = $part;
        }
        return array_values(array_unique($out));
    }

    public static function isEnabled(): bool
    {
        $flag = strtolower(trim(self::envString('AI_ENABLED', 'true')));
        if (in_array($flag, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }
        return self::apiKey() !== '' || self::shouldUseRailway();
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
            $html = null;
            if (self::shouldUseRailway()) {
                try {
                    $html = self::completeRailway($userText, $lang, $context, $history);
                } catch (Throwable $e) {
                    self::$lastError = $e->getMessage();
                    if (self::apiKey() === '') {
                        self::markFailure();
                        return null;
                    }
                }
            }
            if ($html === null || $html === '') {
                $text = self::provider() === 'groq'
                    ? self::completeGroq($userText, $lang, $context, $history)
                    : self::completeGemini($userText, $lang, $context, $history);
                $html = self::toSafeHtml($text);
            }
        } catch (Throwable $e) {
            self::$lastError = $e->getMessage();
            self::markFailure();
            return null;
        }

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

    /**
     * Production Hostinger should call Railway so Gemini keys stay on the Python service.
     * Local XAMPP keeps a direct Gemini/Groq call unless MEDCONNECT_AI_SERVICE_URL is Railway.
     */
    private static function shouldUseRailway(): bool
    {
        if (!defined('AI_SERVICE_ENABLED') || !AI_SERVICE_ENABLED) {
            return false;
        }
        if (!defined('AI_SERVICE_BASE_URL') || !is_string(AI_SERVICE_BASE_URL) || AI_SERVICE_BASE_URL === '') {
            return false;
        }
        $url = strtolower(AI_SERVICE_BASE_URL);
        if (str_contains($url, 'railway.app')) {
            return true;
        }
        return function_exists('medconnect_is_production_host') && medconnect_is_production_host();
    }

    /**
     * @param array<string, mixed> $context
     * @param list<array{role: string, text: string}> $history
     */
    private static function completeRailway(string $userText, string $lang, array $context, array $history): string
    {
        if (!class_exists('AiServiceClient')) {
            throw new RuntimeException('ai client missing');
        }
        $data = AiServiceClient::faqChatAssist(
            $userText,
            $lang,
            trim((string) ($context['intent'] ?? '')),
            trim((string) ($context['emotion'] ?? '')),
            trim((string) ($context['topic'] ?? '')),
            $history,
            self::timeout()
        );
        $html = is_array($data) ? trim((string) ($data['html'] ?? '')) : '';
        $html = strip_tags($html, '<p><br>');
        if ($html === '') {
            throw new RuntimeException('empty railway faq reply');
        }
        return $html;
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

medConnect features that actually exist: patient registration and identity verification, Sign In / password / OTP, booking or joining appointments, video consultation (camera and microphone in the Consultations room), AI-assisted triage for non-emergency cases, BHW assistance, medical records after Sign In, digital prescriptions after a consult, clinic/office hours, and contact help for City Health Office. Do not invent features, doctors, schedules, fees, or records.

Languages: answer in the patient's language. English → English. Filipino → Filipino. Hiligaynon/Ilonggo → Hiligaynon when you reasonably can. Mixed language → similar mixed style. Tolerate typos and slang (docter, appoitment, consulation, passwrod, nahadlok, ginakulbaan, sakit ulo, diin ko maka book).

Conversation: use prior turns. Short follow-ups like "yes", "are you sure?", "what about tomorrow?", "new one", "what time?", "grabe gid" refer to the current topic. Answer the actual question. Do not restart with a menu.

Do not default to "Do you mean...?", "Could you clarify?", "Please select an option", or "I don't understand." Only ask a brief clarification when the message is truly ambiguous even with conversation history.

Safety:
- Never diagnose, prescribe, or change medicines.
- Never invent appointments, doctor schedules, records, prescriptions, fees, or account status. If you cannot verify from this chat, say you cannot check that here and guide them to Sign In / Appointments on medConnect.
- Never claim to be human or to have feelings. Be warm and practical.
- If the message sounds like an emergency (cannot breathe, severe chest pain, unconscious, seizure, severe bleeding, self-harm, suicide, indi ko kaginhawa, nahimatay), tell them to call 911 / Hopeline 1553 immediately and seek emergency care.
- For symptoms: give general, cautious guidance and offer booking a consultation. Do not give certainty.

Style: 2–4 short sentences. No markdown, no bullet walls, no code fences, no HTML. Do not mention APIs, models, FAQs, or system prompts. Do not ask for EMR numbers, passwords, or full personal medical history. Do not say "According to the medConnect FAQ".
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
