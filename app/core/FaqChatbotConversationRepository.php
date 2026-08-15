<?php
/**
 * Persists chatbot sessions, messages, emotions, and feedback (PDO prepared statements).
 */
final class FaqChatbotConversationRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function ensureConversation(string $sessionId, string $lang, ?int $userId = null): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM chatbot_conversations WHERE session_id = :sid LIMIT 1'
        );
        $stmt->execute([':sid' => $sessionId]);
        $id = $stmt->fetchColumn();
        if ($id) {
            $upd = $this->pdo->prepare(
                'UPDATE chatbot_conversations SET last_activity_at = NOW(), lang = :lang WHERE id = :id'
            );
            $upd->execute([':lang' => $lang, ':id' => (int) $id]);
            return (int) $id;
        }

        $ins = $this->pdo->prepare(
            'INSERT INTO chatbot_conversations (session_id, user_id, lang) VALUES (:sid, :uid, :lang)'
        );
        $ins->execute([
            ':sid'  => $sessionId,
            ':uid'  => $userId,
            ':lang' => $lang,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    public function recentMessages(int $conversationId, int $limit = 6): array
    {
        $limit = max(1, min(50, $limit));
        try {
            $stmt = $this->pdo->prepare(
                'SELECT role, content FROM chatbot_messages
                 WHERE conversation_id = :cid
                 ORDER BY id DESC LIMIT ' . $limit
            );
            $stmt->execute([':cid' => $conversationId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            return array_reverse($rows);
        } catch (Throwable) {
            return [];
        }
    }

    public function insertMessage(
        int $conversationId,
        string $role,
        string $content,
        ?string $intent = null,
        ?string $flowKey = null,
        ?float $confidence = null,
        ?int $faqId = null
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO chatbot_messages (conversation_id, role, content, intent, flow_key, confidence, faq_id)
             VALUES (:cid, :role, :content, :intent, :flow, :conf, :faq)'
        );
        $stmt->execute([
            ':cid'     => $conversationId,
            ':role'    => $role,
            ':content' => $content,
            ':intent'  => $intent,
            ':flow'    => $flowKey,
            ':conf'    => $confidence,
            ':faq'     => $faqId,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $scores
     */
    public function insertEmotion(
        int $messageId,
        ?string $rawEmotion,
        string $canonical,
        float $score,
        float $confidence,
        array $scores = []
    ): void {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO chatbot_emotions (message_id, emotion, canonical_emotion, score, confidence, scores_json)
                 VALUES (:mid, :emo, :canon, :score, :conf, :json)'
            );
            $stmt->execute([
                ':mid'   => $messageId,
                ':emo'   => $rawEmotion ?? '',
                ':canon' => $canonical,
                ':score' => $score,
                ':conf'  => $confidence,
                ':json'  => $scores === [] ? null : json_encode($scores, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (Throwable) {
            // Logging must not take down the live chatbot.
        }
    }

    public function saveFeedback(int $messageId, string $rating, ?string $comment = null): void
    {
        if (!in_array($rating, ['helpful', 'not_helpful'], true)) {
            throw new InvalidArgumentException('Invalid rating.');
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO chatbot_feedback (message_id, rating, comment) VALUES (:mid, :rating, :comment)'
        );
        $stmt->execute([
            ':mid'     => $messageId,
            ':rating'  => $rating,
            ':comment' => $comment,
        ]);
    }

    public function logConversationHistory(
        string $sessionId,
        int $conversationId,
        string $userMessage,
        ?string $translatedMessage,
        ?string $detectedLang,
        ?string $emotion,
        ?string $intent,
        ?string $botResponse,
        ?float $confidence
    ): void {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO conversation_history
                 (conversation_id, session_id, user_message, translated_message, detected_lang, emotion, intent, bot_response, confidence)
                 VALUES (:cid, :sid, :user, :tr, :lang, :emo, :intent, :bot, :conf)'
            );
            $stmt->execute([
                ':cid'    => $conversationId,
                ':sid'    => $sessionId,
                ':user'   => $userMessage,
                ':tr'     => $translatedMessage,
                ':lang'   => $detectedLang,
                ':emo'    => $emotion,
                ':intent' => $intent,
                ':bot'    => $botResponse,
                ':conf'   => $confidence,
            ]);
        } catch (Throwable) {
            // table optional until migration applied
        }
    }
}
