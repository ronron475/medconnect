<?php
/**
 * Session conversation memory for the FAQ chatbot (PHP only).
 * Tracks language, topics, emotion, appointment intent, and recent turns.
 */
final class FaqChatbotConversationMemory
{
    private const SESSION_KEY = 'faq_chatbot_memory';
    private const MAX_TURNS = 12;

    /**
     * @return array{
     *   language: string,
     *   current_topic: ?string,
     *   previous_topic: ?string,
     *   emotion: ?string,
     *   emotion_detail: ?string,
     *   intent: ?string,
     *   appointment_intent: bool,
     *   last_kb_key: ?string,
     *   turns: list<array{role: string, text: string, intent: ?string, topic: ?string, at: int}>
     * }
     */
    public static function get(): array
    {
        $mem = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($mem)) {
            return self::empty();
        }
        return array_merge(self::empty(), $mem);
    }

    /**
     * @return array<string, mixed>
     */
    public static function empty(): array
    {
        return [
            'language'           => 'en',
            'current_topic'      => null,
            'previous_topic'     => null,
            'emotion'            => null,
            'emotion_detail'     => null,
            'intent'             => null,
            'appointment_intent' => false,
            'last_kb_key'        => null,
            'pending_prompt'     => null,
            'active_situations'  => [],
            'turns'              => [],
        ];
    }

    public static function rememberLanguage(string $lang): void
    {
        $mem = self::get();
        $mem['language'] = FaqEmotionEngine::normalizeLang($lang);
        $_SESSION[self::SESSION_KEY] = $mem;
        $_SESSION['faq_chatbot_lang'] = $mem['language'];
    }

    /**
     * @param array{
     *   intent?: ?string,
     *   topic?: ?string,
     *   emotion?: ?string,
     *   emotion_detail?: ?string,
     *   kb_key?: ?string,
     *   lang?: string,
     *   user_text?: string,
     *   bot_snippet?: string
     * } $update
     */
    public static function update(array $update): void
    {
        $mem = self::get();

        if (!empty($update['lang'])) {
            $mem['language'] = FaqEmotionEngine::normalizeLang((string) $update['lang']);
        }

        $topic = isset($update['topic']) ? (string) $update['topic'] : null;
        if ($topic !== null && $topic !== '') {
            if ($mem['current_topic'] !== null && $mem['current_topic'] !== $topic) {
                $mem['previous_topic'] = $mem['current_topic'];
            }
            $mem['current_topic'] = $topic;
        }

        if (array_key_exists('emotion', $update) && $update['emotion'] !== null && $update['emotion'] !== '') {
            $mem['emotion'] = (string) $update['emotion'];
        }
        if (array_key_exists('emotion_detail', $update) && $update['emotion_detail'] !== null) {
            $mem['emotion_detail'] = (string) $update['emotion_detail'];
        }
        if (array_key_exists('intent', $update) && $update['intent'] !== null) {
            $mem['intent'] = (string) $update['intent'];
            if (in_array($mem['intent'], [
                FaqChatbotIntentRecognizer::APPOINTMENT,
                FaqChatbotIntentRecognizer::FOLLOW_UP,
                FaqChatbotIntentRecognizer::CONSULTATION,
                FaqChatbotIntentRecognizer::SCHEDULE,
            ], true)) {
                $mem['appointment_intent'] = true;
            }
        }
        if (!empty($update['kb_key'])) {
            $mem['last_kb_key'] = (string) $update['kb_key'];
            $mem['pending_prompt'] = match ((string) $update['kb_key']) {
                'doctor_clarify' => 'book_or_join',
                'symptoms_general', 'worry_symptoms', 'emotion_and_symptoms' => 'severe_or_book',
                'capabilities', 'navigation_help', 'uncertainty_support' => 'topic_menu',
                default => null,
            };
        }
        if (!empty($update['situations']) && is_array($update['situations'])) {
            $mem['active_situations'] = array_values(array_unique(array_merge(
                is_array($mem['active_situations'] ?? null) ? $mem['active_situations'] : [],
                $update['situations']
            )));
        }

        $turns = is_array($mem['turns']) ? $mem['turns'] : [];
        if (!empty($update['user_text'])) {
            $turns[] = [
                'role'   => 'user',
                'text'   => mb_substr((string) $update['user_text'], 0, 280),
                'intent' => $mem['intent'],
                'topic'  => $mem['current_topic'],
                'at'     => time(),
            ];
        }
        if (!empty($update['bot_snippet'])) {
            $turns[] = [
                'role'   => 'bot',
                'text'   => mb_substr(strip_tags((string) $update['bot_snippet']), 0, 280),
                'intent' => $mem['intent'],
                'topic'  => $mem['current_topic'],
                'at'     => time(),
            ];
        }
        $mem['turns'] = array_slice($turns, -self::MAX_TURNS);

        $_SESSION[self::SESSION_KEY] = $mem;
        if (!empty($mem['language'])) {
            $_SESSION['faq_chatbot_lang'] = $mem['language'];
        }
    }

    /**
     * Detect short follow-up / clarification utterances that rely on prior topic.
     */
    public static function isFollowUpUtterance(string $text): bool
    {
        $t = FaqEmotionEngine::normalizeText($text);
        if ($t === '') {
            return false;
        }
        if (preg_match('/^(yes|yeah|yep|ok|okay|sure|please|sige|oo|hoo|opo|oo\s+gid|amo\s+gid|amo|oo\s+po|yes\s+po|ano\s+sunod|and\s+then|then\s+what|how|paano|ano|what\s+about|tell\s+me\s+more|more|continue|go\s+on|that|this|same|okay\s+lang|sige\s+lang|oo\s+man|amo\s+man|pwede|sige\s+po)\??$/ui', $t)) {
            return true;
        }
        if (preg_match('/^(book(\s+one)?|one|new|new\s+one|join|existing|where|diin|saan|how|paano|pano)\??$/ui', $t)) {
            return true;
        }
        if (preg_match('/\b(about\s+that|regarding\s+that|same\s+issue|as\s+i\s+said|like\s+i\s+said|sunod|liwat|about\s+it|amo\s+gid|amo\s+man|amo\s+na|pero|but|kay|tungkol\s+sa|gani|nga)\b/ui', $t)) {
            return true;
        }
        if (preg_match('/^(indi\s+ko\s+gusto|hindi\s+ko\s+gusto|wala\s+ko\s+kasabot|indi\s+ko\s+kabalo|indi\s+ko\s+masabtan)\b/ui', $t)) {
            return true;
        }
        if (preg_match('/^(pero|but)\s+(nahadlok|scared|afraid|kapoy|tired|sad|kasubo|nabalaka|worried|akig|angry)\b/ui', $t)) {
            return true;
        }
        if (preg_match('/^(diin|where|paano|how|nga|ngaa|why|pero|but)\s*[?.!]*$/ui', $t)) {
            return true;
        }
        return mb_strlen($t) <= 24 && preg_match('/^(yes|no|sige|pwede|please|help|bulig|tabang|oo|hindi|indi|wala|grabe|hay|hays|salamat)\b/ui', $t);
    }

    /**
     * Enrich short/contextual messages with session memory for NLP matching.
     */
    public static function contextualMatchText(string $text, string $nlpText): string
    {
        $mem = self::get();
        $boost = self::contextBoostText();
        $base = trim($nlpText);

        if (!self::isFollowUpUtterance($text) && mb_strlen(FaqEmotionEngine::normalizeText($text)) > 28) {
            return $boost !== '' ? trim($base . ' ' . $boost) : $base;
        }

        $parts = [];
        if ($boost !== '') {
            $parts[] = $boost;
        }
        $turns = is_array($mem['turns']) ? $mem['turns'] : [];
        foreach (array_slice($turns, -4) as $turn) {
            if (($turn['role'] ?? '') === 'user' && !empty($turn['text'])) {
                $parts[] = (string) $turn['text'];
            }
        }
        $parts[] = $text;
        return trim(implode(' ', $parts));
    }

    /**
     * @return array{emotion?: string|null, tone?: string, at?: int, topic?: ?string, intent?: ?string}
     */
    public static function emotionContext(): array
    {
        $mem = self::get();
        $ctx = $_SESSION['faq_emotion_context'] ?? null;
        if (!is_array($ctx)) {
            $ctx = [];
        }
        if (!empty($mem['emotion_detail'])) {
            $ctx['emotion'] = (string) $mem['emotion_detail'];
        } elseif (!empty($mem['emotion'])) {
            $ctx['emotion'] = (string) $mem['emotion'];
        }
        if (!empty($mem['current_topic'])) {
            $ctx['topic'] = (string) $mem['current_topic'];
        }
        if (!empty($mem['intent'])) {
            $ctx['intent'] = (string) $mem['intent'];
        }
        return $ctx;
    }

    /**
     * Build a short context boost string from memory for matching.
     */
    public static function contextBoostText(): string
    {
        $mem = self::get();
        $parts = [];
        if (!empty($mem['current_topic'])) {
            $parts[] = str_replace('_', ' ', (string) $mem['current_topic']);
        }
        if (!empty($mem['last_kb_key'])) {
            $parts[] = str_replace('_', ' ', (string) $mem['last_kb_key']);
        }
        if (!empty($mem['appointment_intent'])) {
            $parts[] = 'appointment booking';
        }
        if (!empty($mem['active_situations']) && is_array($mem['active_situations'])) {
            foreach ($mem['active_situations'] as $sit) {
                $parts[] = str_replace('_', ' ', (string) $sit);
            }
        }
        if (!empty($mem['emotion_detail'])) {
            $parts[] = (string) $mem['emotion_detail'];
        } elseif (!empty($mem['emotion'])) {
            $parts[] = (string) $mem['emotion'];
        }
        return trim(implode(' ', $parts));
    }

    /**
     * Natural follow-up bridge line (not a full answer).
     */
    public static function followUpBridge(string $lang): string
    {
        $L = FaqEmotionEngine::normalizeLang($lang);
        $mem = self::get();
        $topic = (string) ($mem['current_topic'] ?? $mem['last_kb_key'] ?? 'your question');
        $topicLabel = htmlspecialchars(str_replace('_', ' ', $topic), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $pool = [
            'en' => [
                '<p>Got it — continuing from <strong>' . $topicLabel . '</strong>.</p>',
                '<p>Thanks for following up. Staying on <strong>' . $topicLabel . '</strong> with you.</p>',
            ],
            'fil' => [
                '<p>Sige — itutuloy natin ang tungkol sa <strong>' . $topicLabel . '</strong>.</p>',
            ],
            'hil' => [
                '<p>Sige — padayon kita sa <strong>' . $topicLabel . '</strong>.</p>',
                '<p>Salamat sa follow-up. Diretso ta sa <strong>' . $topicLabel . '</strong>.</p>',
            ],
        ];
        $lines = $pool[$L] ?? $pool['en'];
        return $lines[random_int(0, count($lines) - 1)];
    }

    /**
     * Expand short/contextual replies ("yes", "book one", "where?") using session memory.
     * Returns null when the utterance should be matched as-is.
     */
    public static function resolveShortUtterance(string $text): ?string
    {
        $t = FaqEmotionEngine::normalizeText($text);
        if ($t === '') {
            return null;
        }
        if (preg_match('/\b(thank|thanks|salamat|got it|that\'?s all)\b/ui', $t)) {
            return null;
        }
        if (preg_match('/^(hi|hello|hey|kumusta|musta|maayong)\b/ui', $t) && mb_strlen($t) <= 24) {
            return null;
        }

        $mem = self::get();
        $intent = (string) ($mem['intent'] ?? '');
        $kb = (string) ($mem['last_kb_key'] ?? '');
        $topic = (string) ($mem['current_topic'] ?? '');
        $pending = (string) ($mem['pending_prompt'] ?? '');
        $hasContext = $intent !== '' || $kb !== '' || $topic !== '';
        if (!$hasContext && $pending === '') {
            return null;
        }

        $apptish = $mem['appointment_intent']
            || in_array($intent, [
                FaqChatbotIntentRecognizer::APPOINTMENT,
                FaqChatbotIntentRecognizer::DOCTOR,
                FaqChatbotIntentRecognizer::CONSULTATION,
            ], true)
            || in_array($kb, ['book_appointment', 'doctor_clarify', 'appointment_status'], true);

        if (preg_match('/^(book(\s+one)?|one|new|new\s+one)$/ui', $t)) {
            return 'book a new appointment consultation';
        }
        if (preg_match('/^(join|existing|already have)$/ui', $t)) {
            return 'join existing appointment video consultation';
        }

        if (preg_match('/^(yes|yeah|yep|oo|hoo|opo|sige|okay|ok|sure)$/ui', $t)) {
            if ($pending === 'book_or_join' || $apptish) {
                return $text . ' book a new appointment consultation';
            }
            if ($pending === 'severe_or_book' || $intent === FaqChatbotIntentRecognizer::SYMPTOMS || str_contains($kb, 'symptom')) {
                return $text . ' book appointment consultation for symptoms';
            }
            if ($intent === FaqChatbotIntentRecognizer::LOGIN || $kb === 'login_help') {
                return $text . ' login help account';
            }
            if ($topic !== '') {
                return trim($text . ' ' . str_replace('_', ' ', $topic));
            }
        }

        if (preg_match('/^(where|diin|saan)\??$/ui', $t)) {
            if ($apptish || $pending === 'book_or_join') {
                return 'where do I book an appointment';
            }
            $hint = $kb !== '' ? $kb : $topic;
            return $hint !== '' ? trim($text . ' ' . str_replace('_', ' ', $hint)) : null;
        }

        if (preg_match('/^(how|paano|pano)\??$/ui', $t)) {
            if ($apptish) {
                return 'how do I book an appointment';
            }
            $hint = $kb !== '' ? $kb : $topic;
            return $hint !== '' ? trim($text . ' ' . str_replace('_', ' ', $hint)) : null;
        }

        if (preg_match('/^(doctor|doktor|doc)$/ui', $t)) {
            if ($intent === FaqChatbotIntentRecognizer::SYMPTOMS || str_contains($kb, 'symptom') || str_contains($topic, 'symptom')) {
                return 'need doctor because of symptoms book appointment';
            }
            return 'I need a doctor book or join appointment';
        }

        return null;
    }
}
