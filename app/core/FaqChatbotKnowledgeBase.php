<?php
/**
 * Rule-based conversational knowledge base engine for medConnect Assistant (PHP only).
 * Merges modular packs: general, emotions, healthcare, services.
 * Scoring: patterns + keywords + light fuzzy match + conversation-memory boost.
 *
 * Never diagnoses. Encourages professional care and emergency services when needed.
 */
final class FaqChatbotKnowledgeBase
{
    public const VERSION = '2.0';

    /**
     * @param array{intent?: string, emotion?: ?string, is_hiligaynon?: bool, session_id?: string, context_boost?: string} $ctx
     * @return array{key: string, category: string, score: float, html: string, flow_key: string}|null
     */
    public static function match(string $rawText, string $nlpText, string $lang, array $ctx = []): ?array
    {
        $lang = FaqEmotionEngine::normalizeLang($lang);
        $boost = trim((string) ($ctx['context_boost'] ?? FaqChatbotConversationMemory::contextBoostText()));
        $hay = FaqEmotionEngine::normalizeText(trim($rawText . ' ' . $nlpText . ' ' . $boost));
        if ($hay === '') {
            return null;
        }

        $barriers = self::detectAccessBarriers($hay);
        if (count($barriers) >= 2) {
            $sessionId = (string) ($ctx['session_id'] ?? ($_SESSION['faq_chatbot_session_id'] ?? ''));
            $html = self::pickResponse('multi_access_barriers', $lang, $sessionId);
            return [
                'key'      => 'multi_access_barriers',
                'category' => 'access_barriers',
                'score'    => round(3.0 + count($barriers) * 0.4, 3),
                'html'     => $html,
                'flow_key' => 'distress_support',
                'barriers' => $barriers,
            ];
        }

        $combined = self::matchTopDistinct($hay, $lang, $ctx, 2);
        if ($combined !== null) {
            return $combined;
        }
        $best = null;
        $bestScore = 0.0;

        foreach (self::scenarios() as $scenario) {
            $score = self::scoreScenario($hay, $scenario, $ctx);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $scenario;
            }
        }

        if ($best === null || $bestScore < 2.2) {
            return null;
        }

        $sessionId = (string) ($ctx['session_id'] ?? ($_SESSION['faq_chatbot_session_id'] ?? ''));
        $html = self::pickResponse($best['key'], $lang, $sessionId);

        return [
            'key'      => $best['key'],
            'category' => $best['category'],
            'score'    => round($bestScore, 3),
            'html'     => $html,
            'flow_key' => $best['flow_key'] ?? $best['key'],
        ];
    }

    public static function pickResponse(string $key, string $lang, string $sessionId = ''): string
    {
        $lang = FaqEmotionEngine::normalizeLang($lang);
        $pack = self::responses()[$key] ?? null;
        if ($pack === null) {
            return FaqChatbotResponseTemplates::html('not_understood', $lang);
        }
        $lines = $pack[$lang] ?? $pack['en'] ?? [];
        if ($lines === []) {
            return FaqChatbotResponseTemplates::html('not_understood', $lang);
        }

        $usedKey = 'faq_kb_used_' . $key;
        $used = $_SESSION[$usedKey] ?? [];
        if (!is_array($used)) {
            $used = [];
        }

        $available = [];
        foreach ($lines as $i => $line) {
            if (!in_array($i, $used, true)) {
                $available[] = $i;
            }
        }
        if ($available === []) {
            $used = [];
            $available = array_keys($lines);
        }

        $pick = $available[random_int(0, count($available) - 1)];
        $used[] = $pick;
        $_SESSION[$usedKey] = array_slice($used, -count($lines));

        $body = $lines[$pick];
        $disclaimer = self::needsDisclaimer($key)
            ? FaqChatbotResponseGenerator::medicalDisclaimer($lang)
            : '';

        return '<div class="fcb-kb-answer" data-kb-key="' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '">'
            . $body
            . $disclaimer
            . '</div>';
    }

    /**
     * Resolve KB key from intent when pattern match is weak but intent is clear.
     */
    public static function keyForIntent(string $intent): ?string
    {
        return match ($intent) {
            FaqChatbotIntentRecognizer::GREETING => 'greeting',
            FaqChatbotIntentRecognizer::THANKS => 'thank_you',
            FaqChatbotIntentRecognizer::GOODBYE => 'goodbye',
            FaqChatbotIntentRecognizer::LOGIN => 'login_help',
            FaqChatbotIntentRecognizer::REGISTRATION => 'registration_help',
            FaqChatbotIntentRecognizer::PASSWORD_RESET => 'password_reset',
            FaqChatbotIntentRecognizer::APPOINTMENT, FaqChatbotIntentRecognizer::FOLLOW_UP => 'book_appointment',
            FaqChatbotIntentRecognizer::CONSULTATION => 'video_consult',
            FaqChatbotIntentRecognizer::CONTACT, FaqChatbotIntentRecognizer::HOSPITAL => 'contact_cho',
            FaqChatbotIntentRecognizer::NAVIGATION => 'navigation_help',
            FaqChatbotIntentRecognizer::FINANCIAL => 'financial_barrier',
            FaqChatbotIntentRecognizer::MENTAL_HEALTH => 'mental_wellness',
            FaqChatbotIntentRecognizer::EMOTIONAL_SUPPORT => 'stress_support',
            FaqChatbotIntentRecognizer::SYMPTOMS => 'symptoms_general',
            FaqChatbotIntentRecognizer::HEALTH_ADVICE, FaqChatbotIntentRecognizer::FAQ => 'health_education',
            FaqChatbotIntentRecognizer::RECORDS => 'medical_records',
            FaqChatbotIntentRecognizer::PRESCRIPTION, FaqChatbotIntentRecognizer::MEDICINE => 'digital_prescriptions',
            FaqChatbotIntentRecognizer::SCHEDULE => 'clinic_schedules',
            FaqChatbotIntentRecognizer::TRIAGE => 'ai_triage_info',
            FaqChatbotIntentRecognizer::OTP => 'otp_verification',
            FaqChatbotIntentRecognizer::PROFILE => 'profile_update',
            FaqChatbotIntentRecognizer::IDENTITY => 'identity',
            FaqChatbotIntentRecognizer::CAPABILITIES => 'capabilities',
            FaqChatbotIntentRecognizer::SMALL_TALK => 'small_talk',
            FaqChatbotIntentRecognizer::CONNECTIVITY => 'signal_internet_problem',
            FaqChatbotIntentRecognizer::PRIVACY => 'privacy_security',
            FaqChatbotIntentRecognizer::WEATHER => 'weather_barrier',
            FaqChatbotIntentRecognizer::TRANSPORT => 'transport_barrier',
            FaqChatbotIntentRecognizer::REASSURANCE => 'privacy_security',
            FaqChatbotIntentRecognizer::BHW => 'bhw_help',
            FaqChatbotIntentRecognizer::TECHNICAL => 'technical_support',
            FaqChatbotIntentRecognizer::DOCTOR => 'doctor_clarify',
            default => null,
        };
    }

    /**
     * @return list<string> weather|signal|money|transport
     */
    public static function detectAccessBarriers(string $hay): array
    {
        $barriers = [];
        if (preg_match('/\b(gaulan|grabe\s+ang\s+ulan|grabe\s+nga\s+ulan|baha|bad\s+weather|storm|ulan\s+pa)\b/ui', $hay)) {
            $barriers[] = 'weather';
        }
        if (preg_match('/\b(wala\s+signal|nadula\s+signal|gadula.{0,16}signal|hinay\s+signal|wala\s+internet|putol.{0,12}connection|ga.?lag|di\s+ko\s+ka.?video|indi\s+ko\s+maka.?video|wala\s+ko\s+kabati)\b/ui', $hay)) {
            $barriers[] = 'signal';
        }
        if (preg_match('/\b(wala\s+(ko\s+)?kwarta|wala\s+budget|indi\s+ko\s+kaya\s+magbayad|walang\s+pera|no\s+money|cannot\s+afford)\b/ui', $hay)) {
            $barriers[] = 'money';
        }
        if (preg_match('/\b(wala\s+ko\s+masakyan|layo\s+amon|budlay\s+magkadto|wala\s+ko\s+pamasahe|indi\s+ko\s+makakadto)\b/ui', $hay)) {
            $barriers[] = 'transport';
        }
        return $barriers;
    }

    /**
     * Combine top distinct KB hits when user mentions multiple problems in one message.
     *
     * @param array<string, mixed> $ctx
     */
    private static function matchTopDistinct(string $hay, string $lang, array $ctx, int $maxParts = 2): ?array
    {
        $scored = [];
        foreach (self::scenarios() as $scenario) {
            $key = (string) ($scenario['key'] ?? '');
            if ($key === '' || $key === 'multi_access_barriers') {
                continue;
            }
            $score = self::scoreScenario($hay, $scenario, $ctx);
            if ($score >= 2.2) {
                $scored[] = ['scenario' => $scenario, 'score' => $score];
            }
        }
        if (count($scored) < 2) {
            return null;
        }
        usort($scored, static fn ($a, $b) => $b['score'] <=> $a['score']);
        $picked = [];
        $categories = [];
        foreach ($scored as $row) {
            $cat = (string) ($row['scenario']['category'] ?? '');
            if (in_array($cat, $categories, true)) {
                continue;
            }
            $picked[] = $row;
            $categories[] = $cat;
            if (count($picked) >= $maxParts) {
                break;
            }
        }
        if (count($picked) < 2) {
            return null;
        }

        $sessionId = (string) ($ctx['session_id'] ?? ($_SESSION['faq_chatbot_session_id'] ?? ''));
        $parts = [];
        $keys = [];
        $totalScore = 0.0;
        $flow = 'distress_support';
        foreach ($picked as $row) {
            $scenario = $row['scenario'];
            $key = (string) $scenario['key'];
            $keys[] = $key;
            $totalScore += (float) $row['score'];
            $parts[] = self::pickResponse($key, $lang, $sessionId);
            $flow = (string) ($scenario['flow_key'] ?? $flow);
        }

        return [
            'key'      => 'multi_' . implode('_', array_slice($keys, 0, 2)),
            'category' => 'multi_topic',
            'score'    => round($totalScore, 3),
            'html'     => '<div class="fcb-kb-multi">' . implode('', $parts) . '</div>',
            'flow_key' => $flow,
        ];
    }

    private static function needsDisclaimer(string $key): bool
    {
        return in_array($key, [
            'symptoms_general', 'worry_symptoms', 'cant_sleep', 'common_illness',
            'pregnancy', 'first_aid', 'nutrition', 'mental_wellness', 'depression_support',
            'panic_support', 'ai_triage_info',
        ], true);
    }

    /**
     * @param array{patterns: list<string>, keywords: list<string>, weight?: float, key?: string, category?: string} $scenario
     * @param array<string, mixed> $ctx
     */
    private static function scoreScenario(string $hay, array $scenario, array $ctx = []): float
    {
        $score = 0.0;
        foreach ($scenario['patterns'] as $pattern) {
            if (@preg_match($pattern, $hay)) {
                $score += 2.6;
            }
        }

        $kwHits = 0;
        foreach ($scenario['keywords'] as $kw) {
            $kw = mb_strtolower(trim($kw), 'UTF-8');
            if ($kw === '') {
                continue;
            }
            if (mb_strpos($hay, $kw) !== false) {
                $kwHits++;
                $score += 1.15;
                continue;
            }
            // Light fuzzy match for typos / slang fragments (3+ chars)
            if (mb_strlen($kw) >= 4) {
                $fuzzy = self::fuzzyContains($hay, $kw);
                if ($fuzzy >= 0.86) {
                    $kwHits++;
                    $score += 0.85 * $fuzzy;
                }
            }
        }
        if ($kwHits >= 2) {
            $score += 0.8;
        }

        // Conversation memory: boost continuing topic
        $memTopic = (string) (FaqChatbotConversationMemory::get()['current_topic'] ?? '');
        $memKey = (string) (FaqChatbotConversationMemory::get()['last_kb_key'] ?? '');
        if ($memKey !== '' && ($scenario['key'] ?? '') === $memKey) {
            $score += 0.55;
        }
        if ($memTopic !== '' && ($scenario['category'] ?? '') === $memTopic) {
            $score += 0.35;
        }

        $intent = (string) ($ctx['intent'] ?? '');
        $intentKey = $intent !== '' ? self::keyForIntent($intent) : null;
        if ($intentKey !== null && ($scenario['key'] ?? '') === $intentKey) {
            $score += 0.7;
        }

        return $score * (float) ($scenario['weight'] ?? 1.0);
    }

    private static function fuzzyContains(string $hay, string $needle): float
    {
        $tokens = preg_split('/\s+/u', $hay) ?: [];
        $best = 0.0;
        $nlen = mb_strlen($needle);
        foreach ($tokens as $tok) {
            if ($tok === '') {
                continue;
            }
            // Compare token and short windows
            similar_text($tok, $needle, $pct);
            $best = max($best, $pct / 100);
            if (mb_strlen($tok) >= $nlen) {
                for ($i = 0, $max = mb_strlen($tok) - $nlen; $i <= $max; $i++) {
                    $slice = mb_substr($tok, $i, $nlen);
                    similar_text($slice, $needle, $pct2);
                    $best = max($best, $pct2 / 100);
                }
            }
        }
        return $best;
    }

    /** @return list<array<string, mixed>> */
    private static function scenarios(): array
    {
        static $all = null;
        if ($all !== null) {
            return $all;
        }
        $all = array_merge(
            FaqChatbotKbEmotions::scenarios(),
            FaqChatbotKbSituations::scenarios(),
            FaqChatbotKbHealthcare::scenarios(),
            FaqChatbotKbServices::scenarios(),
            FaqChatbotKbGeneral::scenarios()
        );
        return $all;
    }

    /** @return array<string, array{en: list<string>, fil?: list<string>, hil?: list<string>}> */
    private static function responses(): array
    {
        static $all = null;
        if ($all !== null) {
            return $all;
        }
        $all = array_merge(
            FaqChatbotKbEmotions::responses(),
            FaqChatbotKbSituations::responses(),
            FaqChatbotKbHealthcare::responses(),
            FaqChatbotKbServices::responses(),
            FaqChatbotKbGeneral::responses()
        );
        return $all;
    }
}
