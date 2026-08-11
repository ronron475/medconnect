<?php
/**
 * Server-side chatbot message security: sanitization, abuse scoring, rate patterns,
 * prompt-injection blocking, and progressive temporary restrictions.
 *
 * Emergency messages always pass through. Does not replace the rule-based chatbot.
 */
final class FaqChatbotMessageGuard
{
    private const SESSION_KEY = 'faq_chatbot_guard';
    private const MAX_TEXT_LEN = 2000;
    private const FLOOD_WINDOW_SEC = 10;
    private const FLOOD_MAX_MSGS = 8;
    private const NONSENSE_SCORE_WARN = 4;
    private const NONSENSE_SCORE_HARD = 8;
    private const RESTRICT_BASE_SEC = 60;
    private const RESTRICT_MAX_SEC = 180;

    /** @var list<string> */
    private const ALLOWLIST_EXACT = [
        'hello', 'hi', 'hey', 'helo', 'hola', 'thanks', 'thank you', 'thankyou', 'salamat',
        'yes', 'no', 'yep', 'nope', 'ok', 'okay', 'okey', 'help', 'please', 'pls',
        'good morning', 'good afternoon', 'good evening', 'good night',
        'fever', 'headache', 'pain', 'cough', 'cold', 'flu', 'dizzy', 'nausea', 'vomit',
        'ulo', 'tiyan', 'dughan', 'doctor', 'schedule', 'appointment', 'consultation',
        'masakit', 'sakit', 'ginhawa', 'hospital', 'emergency', 'medicine', 'symptoms',
        'oo', 'hindi', 'huo', 'indi', 'sige', 'pwede', 'puede',
    ];

    /** @var list<string> */
    private const MEDICAL_HINTS = [
        'pain', 'hurt', 'hurts', 'ache', 'fever', 'cough', 'symptom', 'sick', 'ill',
        'headache', 'stomach', 'chest', 'breath', 'breathe', 'bleed', 'blood', 'rash',
        'medicine', 'medication', 'doctor', 'nurse', 'hospital', 'clinic', 'appointment',
        'consult', 'schedule', 'triage', 'emergency', 'allergy', 'diabetes', 'pressure',
        'masakit', 'sakit', 'ginhawa', 'hospital', 'doktor', 'doctor', 'tiyan', 'ulo',
        'dughan', 'dugo', 'lagnat', 'ubo', 'sip-on', 'sipon', 'kalintura', 'pamatian',
        'konsulta', 'appointment', 'schedule', 'health', 'medical', 'patient', 'feverish',
        'maka schedule', 'mag schedule', 'book', 'register', 'login', 'password', 'account',
    ];

    /** @var list<string> */
    private const PROMPT_INJECTION_PATTERNS = [
        '/ignore\s+(all\s+)?(your|previous|prior)\s+(instructions?|rules?|prompts?)/ui',
        '/disregard\s+(your|all|previous)\s+(instructions?|rules?|prompts?)/ui',
        '/forget\s+(your|all|previous)\s+(instructions?|rules?|prompts?)/ui',
        '/show\s+(me\s+)?(your|the)\s+(system\s+)?prompt/ui',
        '/reveal\s+(your|the|hidden)\s+(instructions?|rules?|prompts?)/ui',
        '/what\s+are\s+your\s+(hidden\s+)?(instructions?|rules?|prompts?)/ui',
        '/tell\s+me\s+your\s+(internal|hidden|system)\s+(rules?|instructions?|prompts?)/ui',
        '/disable\s+(your|all)\s+(restrictions?|filters?|safety)/ui',
        '/bypass\s+(your|the)\s+(restrictions?|filters?|safety)/ui',
        '/act\s+as\s+(if\s+you\s+are|an?)\s+(unrestricted|jailbroken)/ui',
        '/developer\s+mode\s+enabled/ui',
        '/print\s+(your|the)\s+(system\s+)?prompt/ui',
        '/output\s+(your|the)\s+(system\s+)?prompt/ui',
        '/api\s*key|database\s+credential|db\s+password|secret\s+key/ui',
    ];

    /** @var list<string> */
    private const PROFANITY_LIGHT = [
        'fuck', 'shit', 'bitch', 'asshole', 'puta', 'putangina', 'tangina', 'gago', 'ulol',
    ];

    /**
     * @return array{
     *   action: string,
     *   allowed: bool,
     *   sanitized_text: string,
     *   abuse_score: int,
     *   restriction_until: int,
     *   restriction_seconds: int,
     *   flow_key: string,
     *   code: string
     * }
     */
    public static function evaluate(string $text, string $sessionId, int $userId = 0, string $lang = 'en'): array
    {
        $sanitized = self::sanitize($text);
        $state = &self::loadState($sessionId);

        if (self::isRestricted($state)) {
            return self::buildResult('restricted', $sanitized, $state, 0);
        }

        if (self::isFlooding($state)) {
            self::applyRestriction($state, self::RESTRICT_BASE_SEC);
            self::saveState($sessionId, $state);
            return self::buildResult('restricted', $sanitized, $state, 0);
        }

        self::recordMessageTime($state);

        if ($sanitized === '') {
            return self::buildResult('empty', $sanitized, $state, 0);
        }

        // Emergency always passes — never block crisis/medical emergencies as nonsense.
        $emergency = FaqChatbotEmergencyDetector::detect($sanitized);
        if (!empty($emergency['is_emergency'])) {
            self::recordValidMessage($sanitized, $state);
            self::saveState($sessionId, $state);
            return self::buildResult('allow', $sanitized, $state, 0);
        }

        if (self::isPromptInjection($sanitized)) {
            $state['invalid_count'] = (int) ($state['invalid_count'] ?? 0) + 1;
            if ($state['invalid_count'] >= 2) {
                self::applyRestriction($state, self::RESTRICT_BASE_SEC);
                self::saveState($sessionId, $state);
                return self::buildResult('restricted', $sanitized, $state, 0);
            }
            self::saveState($sessionId, $state);
            return self::buildResult('prompt_injection', $sanitized, $state, 0);
        }

        $score = self::abuseScore($sanitized, $state);

        if ($score >= self::NONSENSE_SCORE_WARN) {
            $state['invalid_count'] = (int) ($state['invalid_count'] ?? 0) + 1;
            $hard = $score >= self::NONSENSE_SCORE_HARD;
            if ($state['invalid_count'] >= 2 || $hard) {
                $secs = min(
                    self::RESTRICT_MAX_SEC,
                    self::RESTRICT_BASE_SEC + max(0, $state['invalid_count'] - 2) * 30
                );
                self::applyRestriction($state, $secs);
                self::saveState($sessionId, $state);
                return self::buildResult('restricted', $sanitized, $state, $score);
            }
            self::saveState($sessionId, $state);
            return self::buildResult('nonsense_warning', $sanitized, $state, $score);
        }

        self::recordValidMessage($sanitized, $state);
        self::saveState($sessionId, $state);
        return self::buildResult('allow', $sanitized, $state, $score);
    }

    /**
     * Client-safe restriction status (no abuse scores).
     *
     * @return array{restricted: bool, restriction_seconds: int, restriction_until: int}
     */
    public static function clientStatus(string $sessionId): array
    {
        $state = self::loadState($sessionId);
        $until = (int) ($state['restriction_until'] ?? 0);
        $remaining = max(0, $until - time());
        return [
            'restricted'            => $remaining > 0,
            'restriction_seconds'   => $remaining,
            'restriction_until'     => $until > 0 ? $until : 0,
        ];
    }

    /**
     * Assist-mode payload for guard-blocked messages (warning or restriction).
     *
     * @param array<string, mixed> $guard
     * @return array<string, mixed>
     */
    public static function assistPayload(array $guard, string $sessionId, string $lang): array
    {
        $replyLang = FaqEmotionEngine::normalizeLang($lang);
        $action = (string) ($guard['action'] ?? '');
        $seconds = (int) ($guard['restriction_seconds'] ?? 0);

        return [
            'session_id'            => $sessionId,
            'guard_action'          => $action,
            'use_server_response'   => true,
            'response_html'         => self::responseHtml($action, $replyLang, $seconds),
            'flow_key'              => (string) ($guard['flow_key'] ?? 'guard'),
            'restricted'            => $action === 'restricted',
            'restriction_seconds'   => $seconds,
            'restriction_until'     => (int) ($guard['restriction_until'] ?? 0),
            'typing_ms'             => 650,
            'emergency'             => false,
            'confidence'            => 0.88,
            'mode'                  => 'assist',
        ];
    }

    public static function sanitize(string $text): string
    {
        $text = str_replace("\0", '', $text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text;
        $text = trim($text);
        if (mb_strlen($text) > self::MAX_TEXT_LEN) {
            $text = mb_substr($text, 0, self::MAX_TEXT_LEN);
        }
        return $text;
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildResult(string $action, string $sanitized, array $state, int $score): array
    {
        $until = (int) ($state['restriction_until'] ?? 0);
        $remaining = max(0, $until - time());
        $allowed = $action === 'allow';
        $flowKey = match ($action) {
            'nonsense_warning' => 'nonsense_warning',
            'prompt_injection' => 'prompt_injection',
            'restricted'       => 'restricted',
            default            => 'guard',
        };

        return [
            'action'                => $action,
            'allowed'               => $allowed,
            'sanitized_text'        => $sanitized,
            'abuse_score'           => $score,
            'restriction_until'     => $until,
            'restriction_seconds'   => $remaining,
            'flow_key'              => $flowKey,
            'code'                  => $action,
        ];
    }

    private static function responseHtml(string $action, string $lang, int $seconds): string
    {
        $messages = [
            'en' => [
                'nonsense_warning' => '<p>I couldn\'t understand that message. Please enter a clear question or describe your health concern.</p>',
                'prompt_injection' => '<p>I can help with medConnect and healthcare-related questions, but I can\'t provide internal system instructions.</p>',
                'restricted'       => '<div class="fcb-mod-badge fcb-mod-badge--restricted" role="alert"><span aria-hidden="true">🔒</span> Chat temporarily limited</div>'
                    . '<p>Several invalid or repetitive messages were detected. Please wait before sending another message.</p>'
                    . ($seconds > 0 ? '<p>Please wait <strong data-fcb-cooldown>' . $seconds . '</strong> seconds.</p>' : ''),
            ],
            'fil' => [
                'nonsense_warning' => '<p>Hindi ko naintindihan ang mensaheng iyon. Mangyaring maglagay ng malinaw na tanong o ilarawan ang iyong health concern.</p>',
                'prompt_injection' => '<p>Makakatulong ako sa medConnect at healthcare-related na tanong, ngunit hindi ko maibibigay ang internal system instructions.</p>',
                'restricted'       => '<div class="fcb-mod-badge fcb-mod-badge--restricted" role="alert"><span aria-hidden="true">🔒</span> Pansamantalang limitado ang chat</div>'
                    . '<p>Nakita ang ilang invalid o paulit-ulit na mensahe. Mangyaring maghintay bago magpadala muli.</p>'
                    . ($seconds > 0 ? '<p>Maghintay ng <strong data-fcb-cooldown>' . $seconds . '</strong> segundo.</p>' : ''),
            ],
            'hil' => [
                'nonsense_warning' => '<p>Indi ko maintindihan ang mensahe. Palihog magbutang sang malinaw nga pamangkot ukon ihambal ang imo health concern.</p>',
                'prompt_injection' => '<p>Makabulig ako sa medConnect kag healthcare-related nga pamangkot, pero indi ko mahimo ihatag ang internal system instructions.</p>',
                'restricted'       => '<div class="fcb-mod-badge fcb-mod-badge--restricted" role="alert"><span aria-hidden="true">🔒</span> Temporaryo nga limitado ang chat</div>'
                    . '<p>Nakita ang pila ka invalid ukon paulit-ulit nga mensahe. Palihog hulat antes magpadala liwat.</p>'
                    . ($seconds > 0 ? '<p>Palihog hulat sang <strong data-fcb-cooldown>' . $seconds . '</strong> ka segundo.</p>' : ''),
            ],
        ];

        $pack = $messages[$lang] ?? $messages['en'];
        return $pack[$action] ?? $pack['nonsense_warning'];
    }

    /**
     * @return array<string, mixed>
     */
    private static function &loadState(string $sessionId): array
    {
        if (!isset($_SESSION[self::SESSION_KEY]) || !is_array($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = [];
        }
        $key = substr(hash('sha256', $sessionId), 0, 16);
        if (!isset($_SESSION[self::SESSION_KEY][$key]) || !is_array($_SESSION[self::SESSION_KEY][$key])) {
            $_SESSION[self::SESSION_KEY][$key] = [
                'invalid_count'       => 0,
                'last_hash'           => '',
                'repeat_streak'       => 0,
                'restriction_until'   => 0,
                'msg_times'           => [],
                'last_message_time'   => 0,
            ];
        }

        return $_SESSION[self::SESSION_KEY][$key];
    }

  private static function saveState(string $sessionId, array $state): void
    {
        $key = substr(hash('sha256', $sessionId), 0, 16);
        $_SESSION[self::SESSION_KEY][$key] = $state;
    }

    private static function isRestricted(array $state): bool
    {
        return (int) ($state['restriction_until'] ?? 0) > time();
    }

    private static function applyRestriction(array &$state, int $seconds): void
    {
        $seconds = max(15, min(self::RESTRICT_MAX_SEC, $seconds));
        $state['restriction_until'] = time() + $seconds;
    }

    private static function isFlooding(array &$state): bool
    {
        $now = time();
        $times = is_array($state['msg_times'] ?? null) ? $state['msg_times'] : [];
        $times = array_values(array_filter($times, static fn ($t) => ($now - (int) $t) < self::FLOOD_WINDOW_SEC));
        $state['msg_times'] = $times;
        return count($times) >= self::FLOOD_MAX_MSGS;
    }

    private static function recordMessageTime(array &$state): void
    {
        $now = time();
        $times = is_array($state['msg_times'] ?? null) ? $state['msg_times'] : [];
        $times[] = $now;
        $state['msg_times'] = array_slice($times, -20);
        $state['last_message_time'] = $now;
    }

    private static function recordValidMessage(string $text, array &$state): void
    {
        $norm = self::normalize($text);
        $state['invalid_count'] = 0;
        $state['repeat_streak'] = 0;
        $state['last_hash'] = hash('xxh128', $norm);
    }

    private static function isPromptInjection(string $text): bool
    {
        foreach (self::PROMPT_INJECTION_PATTERNS as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }
        return false;
    }

    private static function abuseScore(string $text, array &$state): int
    {
        $norm = self::normalize($text);
        if ($norm === '') {
            return 0;
        }

        if (self::isAllowlisted($norm)) {
            return 0;
        }

        if (self::hasMedicalRelevance($norm)) {
            return 0;
        }

        $score = 0;
        $compact = preg_replace('/\s+/', '', $norm) ?? $norm;
        $len = mb_strlen($compact);

        if ($len < 3) {
            return 0;
        }

        // Keyboard smash / known patterns
        if (preg_match('/^(asdf|qwer|zxcv|hjkl|qwerty|asdfgh|zxcvbn)/i', $compact)) {
            $score += 5;
        }
        if (preg_match('/^(\d{2,4})\1{2,}$/', $compact)) {
            $score += 5;
        }
        if (preg_match('/^(.)\1{7,}$/u', $compact)) {
            $score += 5;
        }

        // Single-character dominance
        if ($len >= 8) {
            $chars = mb_strtolower($compact);
            $freq = [];
            $charLen = mb_strlen($chars);
            for ($i = 0; $i < $charLen; $i++) {
                $ch = mb_substr($chars, $i, 1);
                $freq[$ch] = ($freq[$ch] ?? 0) + 1;
            }
            if ($freq !== []) {
                $max = max($freq);
                if ($max / max(1, $charLen) > 0.72) {
                    $score += 4;
                }
            }
        }

        // Very low vowel ratio on longer strings (random consonants)
        $alpha = preg_replace('/[^a-z]/', '', mb_strtolower($compact)) ?? '';
        if (strlen($alpha) >= 10) {
            $vowels = preg_match_all('/[aeiou]/', $alpha);
            if ($vowels / strlen($alpha) < 0.12) {
                $score += 3;
            }
        }

        // Repeated identical messages
        $hash = hash('xxh128', $norm);
        if (($state['last_hash'] ?? '') === $hash && $hash !== '') {
            $state['repeat_streak'] = (int) ($state['repeat_streak'] ?? 0) + 1;
            $score += 2 + min(3, $state['repeat_streak']);
        } else {
            $state['repeat_streak'] = 0;
        }
        $state['last_hash'] = $hash;

        // Excessive length with low word density
        $words = preg_split('/\s+/u', trim($norm)) ?: [];
        $wordCount = count(array_filter($words, static fn ($w) => $w !== ''));
        if (mb_strlen($norm) > 220 && $wordCount < 6) {
            $score += 3;
        }
        if (mb_strlen($norm) > 450 && $wordCount < 12) {
            $score += 2;
        }

        // Light profanity — small bump only (client moderation handles stronger cases)
        $lower = mb_strtolower($norm);
        foreach (self::PROFANITY_LIGHT as $token) {
            if (preg_match('/\b' . preg_quote($token, '/') . '\b/u', $lower)) {
                $score += 1;
                break;
            }
        }

        // Rapid-fire under 1 second
        $last = (int) ($state['last_message_time'] ?? 0);
        if ($last > 0 && (time() - $last) < 1 && $score > 0) {
            $score += 2;
        }

        return $score;
    }

    private static function normalize(string $text): string
    {
        $norm = FaqEmotionEngine::normalizeText($text);
        return trim(preg_replace('/\s+/u', ' ', $norm) ?? $norm);
    }

    private static function isAllowlisted(string $norm): bool
    {
        if (in_array($norm, self::ALLOWLIST_EXACT, true)) {
            return true;
        }
        if (mb_strlen($norm) <= 28) {
            foreach (self::ALLOWLIST_EXACT as $phrase) {
                if ($norm === $phrase || str_starts_with($norm, $phrase . ' ')) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function hasMedicalRelevance(string $norm): bool
    {
        $lower = mb_strtolower($norm);
        foreach (self::MEDICAL_HINTS as $hint) {
            if (str_contains($lower, $hint)) {
                return true;
            }
        }
        // Short symptom-like messages (2–6 words) with letters only are likely legitimate
        $words = preg_split('/\s+/u', $lower) ?: [];
        if (count($words) >= 2 && count($words) <= 8 && mb_strlen($lower) <= 80) {
            if (preg_match('/\b(masakit|sakit|hurt|pain|fever|ubo|sipon|lagnat|ulo|tiyan|dughan)\b/u', $lower)) {
                return true;
            }
        }
        return false;
    }
}
