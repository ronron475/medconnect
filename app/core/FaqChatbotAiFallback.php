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

    public const CLASS_GREETING = 'GREETING';
    public const CLASS_HEALTHCARE = 'HEALTHCARE';
    public const CLASS_POSSIBLY_HEALTHCARE = 'POSSIBLY_HEALTHCARE';
    public const CLASS_NON_HEALTHCARE = 'NON_HEALTHCARE';

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

    /** Curated healthcare/service cards that may win without token-in-HTML overlap. */
    private const HEALTHCARE_DATASET_KEYS = [
        'symptoms_general', 'worry_symptoms', 'common_illness', 'first_aid',
        'healthy_lifestyle', 'nutrition', 'exercise', 'vaccinations',
        'womens_health', 'childrens_health', 'senior_health', 'pregnancy',
        'health_education', 'symptom_and_booking', 'emergency_redirect',
        'appointment_how', 'appointment_book', 'login_help', 'register_help',
        'password_reset', 'consultation_join', 'medical_records', 'video_consult',
        'contact_cho', 'office_hours', 'bhw_help', 'privacy_policy',
        'consultation_cost', 'multi_access_barriers',
    ];

    public static function isHealthcareDatasetKey(?string $key): bool
    {
        $key = strtolower(trim((string) $key));
        if ($key === '') {
            return false;
        }
        if (in_array($key, self::HEALTHCARE_DATASET_KEYS, true)) {
            return true;
        }
        foreach (self::HEALTHCARE_DATASET_KEYS as $known) {
            if (str_contains($key, $known)) {
                return true;
            }
        }
        return str_starts_with($key, 'multi_')
            && (str_contains($key, 'symptom') || str_contains($key, 'illness') || str_contains($key, 'appointment') || str_contains($key, 'health'));
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
            && mb_strlen($t) <= 48
            && (bool) preg_match(
                '/^(hi|hello|hey|helo|hola|kamusta|kumusta|musta|maayong(\s+(aga|hapon|gab-?i|adlaw))?|magandang(\s+(umaga|hapon|gabi))?|good\s+(morning|afternoon|evening|day)|can\s+you\s+help(\s+me)?)(\s+(po|gid|there|doc|doctor|doktor))*[.!?]*\s*$/ui',
                $t
            );
    }

    /**
     * Greetings, thanks, goodbye, and short help openings — not Gemini work.
     */
    public static function isConversationalOpeningOnly(string $text): bool
    {
        if (self::isClearGreeting($text) || self::isClearThanks($text) || self::isClearGoodbye($text)) {
            return true;
        }
        if (!class_exists('FaqChatbotDomainScope')) {
            return false;
        }
        $scope = (string) (FaqChatbotDomainScope::classify($text)['scope'] ?? '');
        return in_array($scope, [FaqChatbotDomainScope::GREETING, FaqChatbotDomainScope::CONVERSATION], true);
    }

    public static function isClearGoodbye(string $text): bool
    {
        $t = FaqEmotionEngine::normalizeText($text);
        return (bool) preg_match('/^(goodbye|bye|see\s+you|paalam)(\b|!|\.|$)/ui', $t)
            && mb_strlen($t) <= 48;
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
        if (self::isConversationalOpeningOnly($text)) {
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
        $score = (float) ($kbHit['score'] ?? 0);
        $tokens = self::contentTokens($text);
        $kbHay = FaqEmotionEngine::normalizeText($kbKey . ' ' . str_replace('_', ' ', $kbKey) . ' ' . strip_tags((string) ($kbHit['html'] ?? '')));
        $hits = 0;
        foreach ($tokens as $tok) {
            if (str_contains($kbHay, $tok)) {
                $hits++;
            }
        }
        // Curated medical KB cards are often in another language than the patient.
        // A high pattern/keyword score is still a real dataset answer.
        if ($hits === 0 && $score >= 2.2 && self::isHealthcareDatasetKey($kbKey)) {
            return true;
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
        $pack = self::tryAssist($userText, $lang, $context);
        if ($pack === null) {
            return null;
        }
        $html = trim((string) ($pack['html'] ?? ''));
        return $html !== '' ? $html : null;
    }

    /**
     * Gemini/Groq fallback with a structured healthcare-domain classification.
     *
     * @param array<string, mixed> $context
     * @return array{html: string, classification: string, response_type: string}|null
     */
    public static function tryAssist(string $userText, string $lang, array $context = []): ?array
    {
        $userText = trim($userText);
        if ($userText === '') {
            return null;
        }

        $lang = FaqEmotionEngine::normalizeLang($lang);

        if (!self::isEnabled()) {
            return null;
        }
        if (!self::allowRequest()) {
            return null;
        }

        $userText = mb_substr($userText, 0, self::MAX_USER_CHARS);
        self::$lastError = '';

        try {
            $history = self::historyForApi($context);
            $raw = '';
            $classification = '';
            if (self::shouldUseRailway()) {
                try {
                    $rail = self::completeRailway($userText, $lang, $context, $history);
                    $raw = (string) ($rail['raw'] ?? '');
                    $classification = (string) ($rail['classification'] ?? '');
                    $html = (string) ($rail['html'] ?? '');
                    if ($raw !== '') {
                        $parsed = self::parseModelReply($raw);
                        $pack = self::packFromParsed($parsed, $lang);
                        self::rememberTurn('user', $userText);
                        self::rememberTurn('assistant', strip_tags((string) $pack['html']));
                        self::markSuccess();
                        return $pack;
                    }
                    if ($classification === self::CLASS_NON_HEALTHCARE) {
                        $pack = self::outOfScopePack($lang);
                        self::rememberTurn('user', $userText);
                        self::rememberTurn('assistant', strip_tags($pack['html']));
                        self::markSuccess();
                        return $pack;
                    }
                    if ($html !== '') {
                        $mapped = self::mapClassificationToResponse($classification !== '' ? $classification : self::CLASS_HEALTHCARE, $html, $lang);
                        self::rememberTurn('user', $userText);
                        self::rememberTurn('assistant', strip_tags($mapped['html']));
                        self::markSuccess();
                        return $mapped;
                    }
                } catch (Throwable $e) {
                    self::$lastError = $e->getMessage();
                    if (self::apiKey() === '') {
                        self::markFailure();
                        return null;
                    }
                }
            }
            if ($raw === '') {
                $raw = self::provider() === 'groq'
                    ? self::completeGroq($userText, $lang, $context, $history)
                    : self::completeGemini($userText, $lang, $context, $history);
            }
            $parsed = self::parseModelReply($raw);
            $pack = self::packFromParsed($parsed, $lang);
        } catch (Throwable $e) {
            self::$lastError = $e->getMessage();
            self::markFailure();
            return null;
        }

        if (($pack['html'] ?? '') === '' && ($pack['classification'] ?? '') !== self::CLASS_NON_HEALTHCARE) {
            return null;
        }

        self::rememberTurn('user', $userText);
        self::rememberTurn('assistant', strip_tags((string) $pack['html']));
        self::markSuccess();
        return $pack;
    }

    /**
     * Parse CLASSIFICATION / REPLY (or JSON / OUT_OF_SCOPE token) from the model.
     *
     * @return array<string, mixed>
     */
    public static function parseModelReply(string $raw): array
    {
        $text = trim(str_replace("\r\n", "\n", $raw));
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
        $text = preg_replace('/\s*```$/', '', $text) ?? $text;
        $text = trim($text);

        if ($text === '') {
            return ['classification' => self::CLASS_POSSIBLY_HEALTHCARE, 'reply' => ''];
        }

        if (preg_match('/"is_healthcare_related"\s*:\s*false/i', $text)
            || preg_match('/"isHealthcareRelated"\s*:\s*false/i', $text)) {
            return self::normalizeParsed(self::CLASS_NON_HEALTHCARE, 'OUT_OF_SCOPE');
        }

        if (preg_match('/\{[\s\S]*\}/', $text, $jsonMatch)) {
            $decoded = json_decode($jsonMatch[0], true);
            if (is_array($decoded)) {
                $fromJson = self::packFromStructuredJson($decoded);
                if ($fromJson !== null) {
                    return $fromJson;
                }
            }
        }

        if (preg_match('/^\s*OUT_OF_SCOPE\s*$/i', $text)) {
            return ['classification' => self::CLASS_NON_HEALTHCARE, 'reply' => 'OUT_OF_SCOPE'];
        }

        $class = '';
        if (preg_match('/CLASSIFICATION\s*:\s*(GREETING|HEALTHCARE|POSSIBLY_HEALTHCARE|NON_HEALTHCARE)\b/i', $text, $m)) {
            $class = self::normalizeClassification($m[1]);
        }

        $reply = $text;
        if (preg_match('/\bREPLY\s*:\s*(.*)$/is', $text, $m)) {
            $reply = trim($m[1]);
        } elseif ($class !== '') {
            $reply = preg_replace('/CLASSIFICATION\s*:\s*[A-Z_]+\s*/i', '', $text) ?? $text;
            $reply = trim($reply);
        }
        if ($reply === '' && $class !== '' && $class !== self::CLASS_NON_HEALTHCARE) {
            $reply = trim(preg_replace('/CLASSIFICATION\s*:\s*[A-Z_]+\s*(?:REPLY\s*:)?/i', '', $text) ?? '');
        }

        if ($class === '') {
            if (preg_match('/^\s*OUT_OF_SCOPE\s*$/i', $reply) || (preg_match('/\bOUT_OF_SCOPE\b/', $text) && mb_strlen($text) < 48)) {
                $class = self::CLASS_NON_HEALTHCARE;
                $reply = 'OUT_OF_SCOPE';
            } else {
                $class = self::CLASS_POSSIBLY_HEALTHCARE;
            }
        }

        return self::normalizeParsed($class, $reply);
    }

    /**
     * @param array<string, mixed> $decoded
     * @return array<string, mixed>|null
     */
    public static function packFromStructuredJson(array $decoded): ?array
    {
        $isHealth = $decoded['is_healthcare_related'] ?? $decoded['isHealthcareRelated'] ?? null;
        $class = self::normalizeClassification((string) ($decoded['classification'] ?? $decoded['CLASSIFICATION'] ?? ''));
        if ($isHealth === true || $isHealth === 1 || $isHealth === 'true' || $isHealth === '1') {
            $class = self::CLASS_HEALTHCARE;
        } elseif ($isHealth === false || $isHealth === 0 || $isHealth === 'false' || $isHealth === '0') {
            $class = self::CLASS_NON_HEALTHCARE;
        }
        $reply = trim((string) ($decoded['reply'] ?? $decoded['REPLY'] ?? $decoded['text'] ?? ''));
        $intent = strtolower(str_replace('-', '_', trim((string) ($decoded['intent'] ?? ''))));
        if ($class === '' && in_array($intent, ['non_healthcare', 'nonhealthcare', 'out_of_scope'], true)) {
            $class = self::CLASS_NON_HEALTHCARE;
        }
        if ($class === '' && $reply === '' && $isHealth === null) {
            return null;
        }
        if ($class === '') {
            $class = self::CLASS_POSSIBLY_HEALTHCARE;
        }
        $parsed = self::normalizeParsed($class, $reply);
        $parsed['is_healthcare_related'] = ($class === self::CLASS_HEALTHCARE || $class === self::CLASS_POSSIBLY_HEALTHCARE);
        $parsed['detected_intent'] = trim((string) ($decoded['intent'] ?? ''));
        $parsed['language'] = trim((string) ($decoded['language'] ?? ''));
        $parsed['normalized_meaning'] = trim((string) ($decoded['normalized_meaning'] ?? $decoded['normalizedMeaning'] ?? ''));
        $parsed['urgency'] = strtoupper(trim((string) ($decoded['urgency'] ?? 'NON_URGENT')));
        $parsed['model_confidence'] = isset($decoded['confidence']) ? (float) $decoded['confidence'] : null;
        return $parsed;
    }

    /**
     * @param array<string, mixed> $parsed
     * @return array<string, mixed>
     */
    public static function packFromParsed(array $parsed, string $lang = 'en'): array
    {
        $class = self::normalizeClassification((string) ($parsed['classification'] ?? self::CLASS_HEALTHCARE));
        $reply = trim((string) ($parsed['reply'] ?? ''));
        $pack = self::mapClassificationToResponse($class, $reply, $lang);
        if ($class === self::CLASS_POSSIBLY_HEALTHCARE && $reply !== '' && $pack['response_type'] !== FaqChatbotDomainScope::RESPONSE_OUT_OF_SCOPE) {
            $pack['response_type'] = FaqChatbotDomainScope::RESPONSE_MEDICAL_GEMINI;
            $pack['classification'] = self::CLASS_HEALTHCARE;
        }
        foreach (['is_healthcare_related', 'detected_intent', 'language', 'normalized_meaning', 'urgency', 'model_confidence'] as $key) {
            if (array_key_exists($key, $parsed)) {
                $pack[$key] = $parsed[$key];
            }
        }
        if (!array_key_exists('is_healthcare_related', $pack)) {
            $pack['is_healthcare_related'] = $pack['classification'] !== self::CLASS_NON_HEALTHCARE;
        }
        return $pack;
    }

    /**
     * @return array{html: string, classification: string, response_type: string}
     */
    public static function outOfScopePack(string $lang = 'en'): array
    {
        $html = class_exists('FaqChatbotDomainScope')
            ? FaqChatbotDomainScope::replyHtml(FaqChatbotDomainScope::OUT_OF_SCOPE, $lang)
            : '<p>Please type a question or concern related to healthcare or your health.</p>';
        return [
            'html'                  => $html,
            'classification'        => self::CLASS_NON_HEALTHCARE,
            'response_type'         => FaqChatbotDomainScope::RESPONSE_OUT_OF_SCOPE,
            'is_healthcare_related' => false,
            'detected_intent'       => 'non_healthcare',
            'urgency'               => 'NON_URGENT',
        ];
    }

    /** Too-short model replies (e.g. "Kabay pa") are not usable healthcare answers. */
    public static function isInsufficientModelReply(string $html): bool
    {
        $plain = trim(preg_replace('/\s+/', ' ', strip_tags($html)) ?? '');
        if ($plain === '') {
            return true;
        }
        if (preg_match('/^\s*OUT_OF_SCOPE\s*$/i', $plain)) {
            return true;
        }
        return mb_strlen($plain) < 40;
    }

    /**
     * @return array{html: string, classification: string, response_type: string}
     */
    private static function mapClassificationToResponse(string $classification, string $replyOrHtml, string $lang): array
    {
        $class = self::normalizeClassification($classification);
        if ($class === self::CLASS_NON_HEALTHCARE || preg_match('/^\s*OUT_OF_SCOPE\s*$/i', strip_tags($replyOrHtml))) {
            return self::outOfScopePack($lang);
        }

        $clean = preg_replace('/CLASSIFICATION\s*:\s*[A-Z_]+\s*/i', '', $replyOrHtml) ?? $replyOrHtml;
        $clean = preg_replace('/^\s*REPLY\s*:\s*/i', '', trim($clean)) ?? $clean;
        if (self::isInternalClassificationPayload($clean)) {
            $parsed = self::parseModelReply($clean);
            if (($parsed['classification'] ?? '') === self::CLASS_NON_HEALTHCARE) {
                return self::outOfScopePack($lang);
            }
            $extracted = trim((string) ($parsed['reply'] ?? ''));
            if ($extracted !== '' && !self::isInternalClassificationPayload($extracted)) {
                $clean = $extracted;
            } else {
                return self::outOfScopePack($lang);
            }
        }
        $looksHtml = str_contains($clean, '<p>') || str_contains($clean, '<br');
        $html = $looksHtml ? $clean : self::toSafeHtml($clean);
        if ($html === '' || self::isInsufficientModelReply($html)) {
            $html = class_exists('FaqChatbotDomainScope')
                ? FaqChatbotDomainScope::unmatchedHealthcareHtml($lang)
                : '';
        }

        $type = match ($class) {
            self::CLASS_GREETING => FaqChatbotDomainScope::RESPONSE_GREETING,
            self::CLASS_POSSIBLY_HEALTHCARE => FaqChatbotDomainScope::RESPONSE_MEDICAL_CLARIFICATION,
            default => FaqChatbotDomainScope::RESPONSE_MEDICAL_GEMINI,
        };

        return [
            'html'           => $html,
            'classification' => $class,
            'response_type'  => $type,
        ];
    }

    private static function normalizeClassification(string $value): string
    {
        $v = strtoupper(trim($value));
        $v = str_replace([' ', '-'], '_', $v);
        return match ($v) {
            self::CLASS_GREETING, self::CLASS_HEALTHCARE, self::CLASS_POSSIBLY_HEALTHCARE, self::CLASS_NON_HEALTHCARE => $v,
            'OUT_OF_SCOPE', 'NONHEALTHCARE', 'UNRELATED' => self::CLASS_NON_HEALTHCARE,
            'POSSIBLY', 'AMBIGUOUS', 'CLARIFY' => self::CLASS_POSSIBLY_HEALTHCARE,
            'MEDICAL', 'HEALTH' => self::CLASS_HEALTHCARE,
            default => $v,
        };
    }

    /**
     * @return array{classification: string, reply: string}
     */
    private static function normalizeParsed(string $class, string $reply): array
    {
        $class = self::normalizeClassification($class);
        $reply = trim($reply);
        if ($class === self::CLASS_NON_HEALTHCARE || preg_match('/^\s*OUT_OF_SCOPE\s*$/i', $reply)) {
            return ['classification' => self::CLASS_NON_HEALTHCARE, 'reply' => 'OUT_OF_SCOPE'];
        }
        return ['classification' => $class !== '' ? $class : self::CLASS_HEALTHCARE, 'reply' => $reply];
    }

    /** Detect Gemini/internal classification JSON that must never be shown to patients. */
    public static function isInternalClassificationPayload(string $text): bool
    {
        $plain = trim(strip_tags($text));
        if ($plain === '') {
            return false;
        }
        if (preg_match('/"is_healthcare_related"\s*:/i', $plain)
            || preg_match('/"isHealthcareRelated"\s*:/i', $plain)) {
            return true;
        }
        if (preg_match('/^\s*\{[\s\S]*\}\s*$/', $plain)
            && preg_match('/"(intent|classification|normalized_meaning|urgency|confidence)"\s*:/i', $plain)) {
            return true;
        }
        return false;
    }

    /**
     * Replace leaked model JSON / routing metadata with a safe patient-facing HTML reply.
     */
    public static function sanitizePatientFacingHtml(string $html, string $lang = 'en'): string
    {
        $plain = trim(strip_tags($html));
        if ($plain === '' || !self::isInternalClassificationPayload($plain)) {
            return $html;
        }

        if (preg_match('/\{[\s\S]*\}/', $plain, $jsonMatch)) {
            $decoded = json_decode($jsonMatch[0], true);
            if (is_array($decoded)) {
                $isHealth = $decoded['is_healthcare_related'] ?? $decoded['isHealthcareRelated'] ?? null;
                $reply = trim((string) ($decoded['reply'] ?? $decoded['REPLY'] ?? $decoded['text'] ?? $decoded['response'] ?? ''));
                if ($isHealth === false || $isHealth === 0 || $isHealth === 'false' || $isHealth === '0') {
                    return class_exists('FaqChatbotDomainScope')
                        ? FaqChatbotDomainScope::replyHtml(FaqChatbotDomainScope::OUT_OF_SCOPE, $lang)
                        : '<p>Please ask a healthcare or medConnect-related question.</p>';
                }
                if ($reply !== '' && !self::isInternalClassificationPayload($reply)) {
                    $safe = self::toSafeHtml($reply);
                    return $safe !== '' ? $safe : FaqChatbotDomainScope::unmatchedHealthcareHtml($lang);
                }
                if ($isHealth === true || $isHealth === 1 || $isHealth === 'true' || $isHealth === '1') {
                    return FaqChatbotDomainScope::unmatchedHealthcareHtml($lang);
                }
            }
        }

        if (preg_match('/"is_healthcare_related"\s*:\s*false/i', $plain)
            || preg_match('/"isHealthcareRelated"\s*:\s*false/i', $plain)) {
            return FaqChatbotDomainScope::replyHtml(FaqChatbotDomainScope::OUT_OF_SCOPE, $lang);
        }

        return FaqChatbotDomainScope::replyHtml(FaqChatbotDomainScope::OUT_OF_SCOPE, $lang);
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
        if (self::isInternalClassificationPayload($text)) {
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
     * @return array{html: string, classification: string, raw: string}
     */
    private static function completeRailway(string $userText, string $lang, array $context, array $history): array
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
        if (!is_array($data)) {
            throw new RuntimeException('empty railway faq reply');
        }
        $classification = self::normalizeClassification((string) ($data['classification'] ?? ''));
        $html = strip_tags(trim((string) ($data['html'] ?? '')), '<p><br>');
        $raw = trim((string) ($data['raw'] ?? strip_tags($html)));
        if ($classification === self::CLASS_NON_HEALTHCARE || preg_match('/^\s*OUT_OF_SCOPE\s*$/i', $raw)) {
            return ['html' => '', 'classification' => self::CLASS_NON_HEALTHCARE, 'raw' => 'OUT_OF_SCOPE'];
        }
        if ($classification === '' && $raw !== '') {
            $parsed = self::parseModelReply($raw);
            $classification = $parsed['classification'];
            if ($classification === self::CLASS_NON_HEALTHCARE) {
                return ['html' => '', 'classification' => self::CLASS_NON_HEALTHCARE, 'raw' => 'OUT_OF_SCOPE'];
            }
            if ($html === '' && $parsed['reply'] !== '') {
                $html = self::toSafeHtml($parsed['reply']);
            }
        }
        if ($html === '') {
            throw new RuntimeException('empty railway faq reply');
        }
        return [
            'html'           => $html,
            'classification' => $classification !== '' ? $classification : self::CLASS_HEALTHCARE,
            'raw'            => $raw,
        ];
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
                'temperature'        => 0.3,
                'maxOutputTokens'    => self::MAX_OUTPUT_TOKENS,
                'responseMimeType'   => 'application/json',
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
You are a healthcare assistant for medConnect.

First classify whether the user message is related to healthcare, medicine, a symptom, illness, injury, treatment, medication, doctor/provider consultation, medical records, appointment, emergency, or another legitimate medConnect healthcare concern.

Understand English, Filipino/Tagalog, Hiligaynon/Ilonggo, mixed language, slang, abbreviations, misspellings, and short informal sentences. Example: "sakit ulo ko" means the patient has a headache.

If it IS healthcare-related, write a helpful reply in the patient's language:
- Acknowledge the concern (for "sakit ulo ko", talk about head pain / headache).
- You may ask relevant follow-up questions and give safe general information.
- Do not diagnose, prescribe, or invent records.
- Do not claim to be a doctor.
- Recommend professional care when appropriate.
- Do not invent an emergency unless clearly described symptoms support it (cannot breathe, severe chest pain, unconscious, seizure, severe bleeding, self-harm, suicide, indi ko kaginhawa, nahimatay). Then set urgency to EMERGENCY and tell them to call 911 / Hopeline 1553.

If it is NOT healthcare-related (jokes, weather, sports, trivia, coding, cooking, money-only chat with no health context), set is_healthcare_related to false and leave reply empty.

Return ONLY this JSON object, no markdown, no extra text:
{"is_healthcare_related":true,"intent":"symptom","language":"Hiligaynon","normalized_meaning":"headache","urgency":"NON_URGENT","confidence":0.94,"reply":"your 2-4 sentence reply"}

intent must be one of: symptom, medication, appointment, records, consultation, emergency, medconnect, other_healthcare, non_healthcare
urgency must be one of: EMERGENCY, URGENT, NON_URGENT
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
