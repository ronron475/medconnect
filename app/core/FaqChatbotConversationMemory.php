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
        if (preg_match('/^(yes|yeah|yep|ok|okay|sure|please|sige|oo|hoo|ano\s+sunod|and\s+then|then\s+what|how|paano|ano|what\s+about|tell\s+me\s+more|more|continue|go\s+on|that|this|same)\??$/ui', $t)) {
            return true;
        }
        if (preg_match('/\b(about\s+that|regarding\s+that|same\s+issue|as\s+i\s+said|like\s+i\s+said|sunod|liwat|about\s+it)\b/ui', $t)) {
            return true;
        }
        return mb_strlen($t) <= 18 && preg_match('/^(yes|no|sige|pwede|please|help|bulig|tabang)\b/ui', $t);
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
}
