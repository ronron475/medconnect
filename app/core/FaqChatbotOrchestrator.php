<?php
/**
 * Main PHP-only chatbot pipeline: emotion → intent → emergency → FAQ → response → logging.
 */
final class FaqChatbotOrchestrator
{
    private FaqChatbotFaqRepository $faqRepo;
    private FaqChatbotConversationRepository $convRepo;

    public function __construct(private PDO $pdo)
    {
        $this->faqRepo = new FaqChatbotFaqRepository($pdo);
        $this->convRepo = new FaqChatbotConversationRepository($pdo);
    }

    /**
     * @param array<string, mixed> $options mode: full|log_only, client_html, flow_key, confidence
     * @return array<string, mixed>
     */
    public function handle(string $sessionId, string $text, string $lang = 'en', array $options = []): array
    {
        $text = trim($text);
        $lang = FaqEmotionEngine::normalizeLang($lang);
        $mode = (string) ($options['mode'] ?? 'full');
        $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;

        if ($sessionId === '' || !preg_match('/^[a-zA-Z0-9_-]{16,64}$/', $sessionId)) {
            throw new InvalidArgumentException('Invalid session id.');
        }
        if ($text === '') {
            throw new InvalidArgumentException('Message is required.');
        }
        if (mb_strlen($text) > 2000) {
            throw new InvalidArgumentException('Message is too long.');
        }

        $conversationId = $this->convRepo->ensureConversation($sessionId, $lang, $userId);

        $nlp = FaqChatbotNlpPipeline::process($this->pdo, $text, $lang);
        $nlpText = $nlp['expanded_english'] ?: $nlp['english_text'];
        $replyLang = $nlp['reply_lang'];
        $detectedLang = $nlp['detected_lang'];
        $bridge = [
            'reply_lang'     => $replyLang,
            'nlp_text'       => $nlpText,
            'english_gloss'  => $nlp['english_text'],
            'input_lang'     => $detectedLang,
            'is_hiligaynon'  => $nlp['is_hiligaynon'],
        ];

        if ($mode === 'log_bot') {
            $botHtml = (string) ($options['client_html'] ?? '');
            $botConf = isset($options['confidence']) ? (float) $options['confidence'] : null;
            $botFlow = (string) ($options['flow_key'] ?? 'client');
            $botIntent = (string) ($options['intent'] ?? $_SESSION['faq_chatbot_last_intent'] ?? FaqChatbotIntentRecognizer::GENERAL);
            $botId = $this->convRepo->insertMessage(
                $conversationId,
                'bot',
                strip_tags($botHtml) ?: $botHtml,
                $botIntent,
                $botFlow,
                $botConf,
                null
            );
            return [
                'session_id'          => $sessionId,
                'conversation_id'     => $conversationId,
                'bot_message_id'      => $botId,
                'use_server_response' => false,
                'mode'                => $mode,
            ];
        }

        // Context from DB + session memory
        FaqChatbotConversationMemory::rememberLanguage($replyLang);
        $history = $this->convRepo->recentMessages($conversationId, 8);
        $memoryBoost = FaqChatbotConversationMemory::contextBoostText();
        $resolvedShort = FaqChatbotConversationMemory::resolveShortUtterance($text);
        $effectiveText = $resolvedShort ?? $text;
        if ($resolvedShort !== null && $resolvedShort !== $text) {
            $nlpText = trim($nlpText . ' ' . $resolvedShort);
        }
        $contextText = trim($this->mergeContextText($history, $nlpText) . ' ' . $memoryBoost);
        $matchText = FaqChatbotConversationMemory::contextualMatchText($effectiveText, $nlpText);

        $emergency = FaqChatbotEmergencyDetector::detect($contextText . ' ' . $text);
        $intentPack = FaqChatbotIntentRecognizer::recognize($matchText);
        $intent = $intentPack['intent'];
        $flowKey = $intentPack['flow_key'];

        $emotionResult = FaqEmotionEngine::analyze(
            $text,
            $replyLang,
            $intent,
            FaqChatbotConversationMemory::emotionContext(),
            $matchText
        );
        $canonical = FaqChatbotStandardEmotion::canonicalize($emotionResult['emotion'] ?? null);

        if (!empty($emotionResult['emotion'])) {
            $_SESSION['faq_emotion_context'] = [
                'emotion' => $emotionResult['emotion'],
                'tone'    => $emotionResult['tone'] ?? 'neutral',
                'at'      => time(),
            ];
        }

        $_SESSION['faq_chatbot_last_intent'] = $intent;

        $userMsgId = $this->convRepo->insertMessage($conversationId, 'user', $text, $intent, $flowKey, null, null);
        $this->convRepo->insertEmotion(
            $userMsgId,
            $emotionResult['emotion'] ?? null,
            $canonical,
            (float) ($emotionResult['score'] ?? 0),
            (float) ($emotionResult['confidence'] ?? 0),
            is_array($emotionResult['scores'] ?? null) ? $emotionResult['scores'] : []
        );

        $empathy = FaqChatbotResponseGenerator::empathyLine($canonical, $replyLang);
        if ($bridge['is_hiligaynon'] && $canonical !== FaqChatbotStandardEmotion::NEUTRAL) {
            $empathyWrap = FaqChatbotLanguageBridge::bilingualEmpathyLead($canonical, $empathy);
        } else {
            $empathyWrap = null;
        }
        $responseHtml = '';
        $faqId = null;
        $kbHit = null;
        $confidence = (float) ($intentPack['confidence'] ?? 0.35);
        $suggestions = [];
        $intentStrong = in_array($intent, [
            FaqChatbotIntentRecognizer::FINANCIAL,
            FaqChatbotIntentRecognizer::APPOINTMENT,
            FaqChatbotIntentRecognizer::LOGIN,
            FaqChatbotIntentRecognizer::REGISTRATION,
            FaqChatbotIntentRecognizer::CONSULTATION,
            FaqChatbotIntentRecognizer::SYMPTOMS,
            FaqChatbotIntentRecognizer::EMOTIONAL_SUPPORT,
            FaqChatbotIntentRecognizer::CONNECTIVITY,
            FaqChatbotIntentRecognizer::TRANSPORT,
            FaqChatbotIntentRecognizer::WEATHER,
            FaqChatbotIntentRecognizer::EMERGENCY,
            FaqChatbotIntentRecognizer::PASSWORD_RESET,
            FaqChatbotIntentRecognizer::BHW,
            FaqChatbotIntentRecognizer::TECHNICAL,
            FaqChatbotIntentRecognizer::DOCTOR,
            FaqChatbotIntentRecognizer::PRIVACY,
            FaqChatbotIntentRecognizer::RECORDS,
            FaqChatbotIntentRecognizer::CAPABILITIES,
            FaqChatbotIntentRecognizer::GREETING,
            FaqChatbotIntentRecognizer::THANKS,
            FaqChatbotIntentRecognizer::OTP,
        ], true) && $confidence >= 0.62;

        if ($emergency['is_emergency']) {
            $flowKey = $emergency['flow'] ?? 'emergency';
            $responseHtml = FaqChatbotResponseGenerator::emergencyHtml($replyLang, $flowKey);
            $confidence = 0.99;
        } else {
            $faqHits = $this->faqRepo->search($contextText, 5);
            $best = $faqHits[0] ?? null;
            $faqThreshold = 1.85;

            if ($best && ($best['score'] ?? 0) >= $faqThreshold) {
                $faqId = (int) $best['id'];
                $body = FaqChatbotResponseGenerator::faqAnswerHtml((string) $best['answer']);
                $body .= FaqChatbotResponseGenerator::medicalDisclaimer($replyLang);
                $lead = $empathyWrap ?? FaqChatbotResponseGenerator::wrapAnswer($empathy, '');
                $responseHtml = $lead . $body;
                $confidence = min(0.98, 0.55 + ((float) $best['score'] / 4));
                $flowKey = 'faq_' . ($best['category'] ?? 'general');
                $suggestions = $this->formatSuggestions(
                    $this->faqRepo->suggestionsForCategory((string) ($best['category'] ?? ''), 3)
                );
            } else {
                try {
                    $kbHit = FaqChatbotResponseGenerator::kbMatchForAssist($text, $matchText, $replyLang, [
                        'intent'         => $intent,
                        'emotion'        => $emotionResult['emotion'] ?? null,
                        'session_id'     => $sessionId,
                        'context_boost'  => $memoryBoost,
                    ]);
                } catch (Throwable) {
                    $kbHit = null;
                }

                // Skip the 20MB scenario index when intent is already clear (Hostinger memory).
                if (!$intentStrong && ($kbHit === null || (float) ($kbHit['score'] ?? 0) < 2.0)) {
                    try {
                        $unified = FaqChatbotUnifiedKnowledge::search($this->pdo, $text, $matchText, $replyLang, [
                            'intent'        => $intent,
                            'emotion'       => $emotionResult['emotion'] ?? null,
                            'session_id'    => $sessionId,
                            'context_boost' => $memoryBoost,
                        ]);
                    } catch (Throwable) {
                        $unified = null;
                    }
                    if ($unified !== null && (float) ($unified['score'] ?? 0) >= 1.85) {
                        $kbHit = [
                            'key'      => $unified['key'],
                            'category' => $unified['category'],
                            'score'    => $unified['score'],
                            'html'     => $unified['html'],
                            'flow_key' => $unified['flow_key'],
                        ];
                        if (($unified['intent'] ?? '') !== '') {
                            $intent = (string) $unified['intent'];
                            $_SESSION['faq_chatbot_last_intent'] = $intent;
                        }
                    }
                }

                $followBridge = '';
                if (FaqChatbotConversationMemory::isFollowUpUtterance($text)
                    && !empty(FaqChatbotConversationMemory::get()['current_topic'])) {
                    $followBridge = FaqChatbotConversationMemory::followUpBridge($replyLang);
                }

                $combined = $this->combineMultiIntentHtml($effectiveText, $matchText, $replyLang, $sessionId);
                if ($combined !== null) {
                    $kbHit = $combined;
                    $intent = (string) ($combined['intent'] ?? $intent);
                    $_SESSION['faq_chatbot_last_intent'] = $intent;
                }

                if ($kbHit !== null) {
                    $lead = $empathyWrap ?? FaqChatbotResponseGenerator::wrapAnswer($empathy, '');
                    // Crisis / emergency KB cards already carry strong framing — light empathy only
                    if (in_array($kbHit['key'], ['crisis_hopeless', 'emergency_redirect'], true)) {
                        $responseHtml = $kbHit['html'];
                    } else {
                        $responseHtml = $followBridge . $lead . $kbHit['html'];
                    }
                    $confidence = min(0.96, 0.55 + ((float) $kbHit['score'] / 6));
                    $flowKey = $kbHit['flow_key'] ?? $flowKey ?? 'conversational';
                } else {
                    $fallback = FaqChatbotResponseGenerator::conversationalFallback($replyLang, $intent, [
                        'raw'           => $text,
                        'nlp'           => $matchText,
                        'emotion'       => $emotionResult['emotion'] ?? null,
                        'session_id'    => $sessionId,
                        'context_boost' => $memoryBoost,
                    ]);
                    if (str_contains($fallback, 'not_understood') || str_contains($fallback, "didn't quite understand")) {
                        $fallback = FaqChatbotUnifiedKnowledge::smartClarification(
                            $replyLang,
                            $intent,
                            $emotionResult['emotion'] ?? null
                        );
                    }
                    $lead = $empathyWrap ?? FaqChatbotResponseGenerator::wrapAnswer($empathy, '');
                    $responseHtml = $followBridge . $lead . $fallback;
                    $confidence = max(0.42, (float) ($emotionResult['confidence'] ?? 0.4));
                    $flowKey = $flowKey ?? 'conversational';
                }
                $suggestions = $this->formatSuggestions($this->faqRepo->suggestionsForCategory(null, 3));
            }
        }

        // Persist conversational memory for natural follow-ups
        FaqChatbotConversationMemory::update([
            'lang'           => $replyLang,
            'intent'         => $intent,
            'topic'          => $kbHit['category'] ?? ($flowKey ?: $intent),
            'emotion'        => $canonical,
            'emotion_detail' => $emotionResult['emotion'] ?? null,
            'kb_key'         => $kbHit['key'] ?? null,
            'situations'     => isset($kbHit['barriers']) && is_array($kbHit['barriers']) ? $kbHit['barriers'] : null,
            'user_text'      => $text,
            'bot_snippet'    => $responseHtml !== '' ? $responseHtml : null,
        ]);

        // Assist mode: serve PHP replies for emergency, FAQ, KB, intent-clear, or unified matches
        $kbStrong = $kbHit !== null && (($kbHit['score'] ?? 0) >= 1.85);
        $useServer = $mode === 'full'
            || ($mode === 'assist' && ($emergency['is_emergency'] || $faqId !== null || $kbStrong || $intentStrong));

        if ($mode === 'assist' && !$useServer && class_exists('FaqChatbotAiFallback')) {
            $aiHtml = FaqChatbotAiFallback::tryReply($text, $replyLang, [
                'intent'  => $intent,
                'emotion' => $canonical,
                'topic'   => $kbHit['category'] ?? ($flowKey ?: $intent),
                'turns'   => FaqChatbotConversationMemory::get()['turns'] ?? [],
            ]);
            if (is_string($aiHtml) && $aiHtml !== '') {
                $responseHtml = $aiHtml;
                $useServer = true;
                $confidence = max($confidence, 0.58);
                $flowKey = 'ai_conversation';
                FaqChatbotConversationMemory::update([
                    'bot_snippet' => $aiHtml,
                    'topic'       => 'ai_conversation',
                ]);
            } else {
                if ($suggestions === []) {
                    $suggestions = $this->formatSuggestions($this->faqRepo->suggestionsForCategory(null, 3));
                }
                return $this->payload(
                    $sessionId,
                    $conversationId,
                    $userMsgId,
                    0,
                    $canonical,
                    $emotionResult,
                    $intent,
                    $flowKey,
                    $emergency,
                    '',
                    $suggestions,
                    $confidence,
                    false,
                    $mode,
                    0,
                    $replyLang,
                    $nlp
                );
            }
        }

        $botMsgId = $this->convRepo->insertMessage(
            $conversationId,
            'bot',
            strip_tags($responseHtml),
            $intent,
            $flowKey,
            $confidence,
            $faqId
        );

        $typingMs = 0;

        $this->convRepo->logConversationHistory(
            $sessionId,
            $conversationId,
            $text,
            $nlp['english_text'],
            $detectedLang,
            $canonical,
            $intent,
            strip_tags($responseHtml),
            $confidence
        );

        return $this->payload(
            $sessionId,
            $conversationId,
            $userMsgId,
            $botMsgId,
            $canonical,
            $emotionResult,
            $intent,
            $flowKey,
            $emergency,
            $responseHtml,
            $suggestions,
            $confidence,
            $useServer,
            $mode,
            $typingMs,
            $replyLang,
            $nlp
        );
    }

    /**
     * @param list<array{role: string, content: string}> $history
     */
    private function mergeContextText(array $history, string $current): string
    {
        $parts = [];
        foreach ($history as $row) {
            if (($row['role'] ?? '') === 'user') {
                $parts[] = (string) ($row['content'] ?? '');
            }
        }
        $parts[] = $current;
        $tail = array_slice($parts, -3);
        return trim(implode(' ', $tail));
    }

    /**
     * Merge two distinct intents (emotion+symptom, symptom+booking, login+appointment).
     *
     * @return array{key: string, category: string, score: float, html: string, flow_key: string, intent: string}|null
     */
    private function combineMultiIntentHtml(string $text, string $matchText, string $lang, string $sessionId): ?array
    {
        $multi = FaqChatbotConversationalIntents::matchAll($text, $matchText, 2);
        if (count($multi) < 2) {
            return null;
        }
        $cats = array_column($multi, 'category');
        $keys = array_column($multi, 'kb_key');
        $hasEmotion = in_array('emotional_support', $cats, true);
        $hasHealth = in_array('healthcare', $cats, true);
        $hasBook = in_array('appointments', $cats, true);
        $hasAccount = in_array('accounts', $cats, true);

        $comboKey = null;
        $intent = (string) ($multi[0]['intent'] ?? FaqChatbotIntentRecognizer::GENERAL);
        $flow = (string) ($multi[0]['flow_key'] ?? 'conversational');
        if ($hasEmotion && $hasHealth) {
            $comboKey = 'emotion_and_symptoms';
            $intent = FaqChatbotIntentRecognizer::EMOTIONAL_SUPPORT;
            $flow = 'distress_support';
        } elseif ($hasHealth && $hasBook) {
            $comboKey = 'symptom_and_booking';
            $intent = FaqChatbotIntentRecognizer::APPOINTMENT;
            $flow = 'appointment';
        } elseif ($hasAccount && $hasBook) {
            $comboKey = 'login_and_appointment';
            $intent = FaqChatbotIntentRecognizer::LOGIN;
            $flow = 'signin';
        }

        if ($comboKey === null) {
            $html = FaqChatbotKnowledgeBase::pickResponse((string) ($keys[0] ?? 'navigation_help'), $lang, $sessionId)
                . FaqChatbotKnowledgeBase::pickResponse((string) ($keys[1] ?? 'navigation_help'), $lang, $sessionId);
            return [
                'key'      => (string) ($keys[0] ?? 'navigation_help'),
                'category' => (string) ($multi[0]['category'] ?? 'general'),
                'score'    => 3.1,
                'html'     => $html,
                'flow_key' => $flow,
                'intent'   => $intent,
            ];
        }

        return [
            'key'      => $comboKey,
            'category' => (string) ($multi[0]['category'] ?? 'general'),
            'score'    => 3.4,
            'html'     => FaqChatbotKnowledgeBase::pickResponse($comboKey, $lang, $sessionId),
            'flow_key' => $flow,
            'intent'   => $intent,
        ];
    }

    /**
     * @param list<array{id: int, question: string, category: string}> $rows
     * @return list<array{id: int, label: string, category: string}>
     */
    private function formatSuggestions(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id'       => (int) ($row['id'] ?? 0),
                'label'    => (string) ($row['question'] ?? ''),
                'category' => (string) ($row['category'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $emotionResult
     * @param array{is_emergency: bool, flow: ?string} $emergency
     * @param list<array{id: int, label: string, category: string}> $suggestions
     * @return array<string, mixed>
     */
    private function payload(
        string $sessionId,
        int $conversationId,
        int $userMessageId,
        int $botMessageId,
        string $canonical,
        array $emotionResult,
        string $intent,
        ?string $flowKey,
        array $emergency,
        string $responseHtml,
        array $suggestions,
        float $confidence,
        bool $useServer,
        string $mode,
        int $typingMs = 900,
        string $lang = 'en',
        ?array $nlp = null
    ): array {
        return [
            'session_id'         => $sessionId,
            'conversation_id'    => $conversationId,
            'user_message_id'    => $userMessageId,
            'bot_message_id'     => $botMessageId,
            'emotion'            => $canonical,
            'emotion_label'      => FaqChatbotStandardEmotion::label($canonical, $lang),
            'emotion_detail'     => $emotionResult['emotion'] ?? null,
            'intent'             => $intent,
            'flow_key'           => $flowKey,
            'confidence'         => round($confidence, 3),
            'emergency'          => (bool) $emergency['is_emergency'],
            'emergency_flow'     => $emergency['flow'] ?? null,
            'response_html'      => $responseHtml,
            'empathy_html'       => $emotionResult['empathy_html'] ?? '',
            'suggestions'        => $suggestions,
            'typing_ms'          => $typingMs,
            'use_server_response'=> $useServer && ($mode === 'full' || $mode === 'assist'),
            'mode'               => $mode,
            'detected_lang'      => $nlp['detected_lang'] ?? $lang,
            'english_gloss'      => $nlp['english_text'] ?? '',
            'nlp_pipeline'       => $nlp['pipeline_steps'] ?? [],
        ];
    }
}
