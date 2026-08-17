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
        $memoryBoost = FaqChatbotConversationMemory::contextBoostText();
        $resolvedShort = FaqChatbotConversationMemory::resolveShortUtterance($text);
        $effectiveText = $resolvedShort ?? $text;
        if ($resolvedShort !== null && $resolvedShort !== $text) {
            $nlpText = trim($nlpText . ' ' . $resolvedShort);
        }
        $matchText = FaqChatbotConversationMemory::contextualMatchText($effectiveText, $nlpText);

        // Greeting → healthcare scope gate → (only then) emergency / dataset / Gemini.
        $scopePack = FaqChatbotDomainScope::classify($effectiveText, $matchText);
        $scope = (string) ($scopePack['scope'] ?? '');
        $isOpening = in_array($scope, [
            FaqChatbotDomainScope::GREETING,
            FaqChatbotDomainScope::CONVERSATION,
            FaqChatbotDomainScope::HELP_OPEN,
        ], true);
        $isHealthcare = $scope === FaqChatbotDomainScope::MEDICAL
            || FaqChatbotDomainScope::isHealthcareRelated($effectiveText, $matchText);

        if ($isHealthcare && !$isOpening) {
            $focusText = FaqChatbotDomainScope::healthcareFocusText($effectiveText, $matchText);
            if ($focusText !== '' && mb_strtolower($focusText) !== mb_strtolower(trim($effectiveText))) {
                $effectiveText = $focusText;
                $matchText = FaqChatbotConversationMemory::contextualMatchText($focusText, $nlpText);
            }
        }

        $emergency = ['is_emergency' => false, 'type' => null, 'flow' => null, 'reason' => ''];
        if ($isHealthcare && !$isOpening) {
            $emergency = FaqChatbotEmergencyDetector::detect($effectiveText);
            if (
                empty($emergency['is_emergency'])
                && $matchText !== ''
                && strcasecmp(trim($matchText), trim($effectiveText)) !== 0
            ) {
                $second = FaqChatbotEmergencyDetector::detect($matchText);
                if (!empty($second['is_emergency'])) {
                    $emergency = $second;
                }
            }
        }

        if (!$isHealthcare && !$isOpening) {
            $intentPack = [
                'intent'     => 'out_of_scope',
                'confidence' => 0.95,
                'flow_key'   => 'domain_out_of_scope',
            ];
        } else {
            $intentPack = FaqChatbotIntentRecognizer::recognize($matchText);
            if (!empty($emergency['is_emergency'])) {
                $intentPack = [
                    'intent'     => FaqChatbotIntentRecognizer::EMERGENCY,
                    'confidence' => 0.99,
                    'flow_key'   => $emergency['flow'],
                ];
            }
        }
        $intent = (string) ($intentPack['intent'] ?? FaqChatbotIntentRecognizer::GENERAL);
        $flowKey = $intentPack['flow_key'] ?? null;

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

        $userMsgId = 0;
        try {
            $userMsgId = $this->convRepo->insertMessage($conversationId, 'user', $text, $intent, $flowKey, null, null);
            $this->convRepo->insertEmotion(
                $userMsgId,
                $emotionResult['emotion'] ?? null,
                $canonical,
                (float) ($emotionResult['score'] ?? 0),
                (float) ($emotionResult['confidence'] ?? 0),
                is_array($emotionResult['scores'] ?? null) ? $emotionResult['scores'] : []
            );
        } catch (Throwable) {
            $userMsgId = 0;
        }

        $empathy = FaqChatbotResponseGenerator::empathyLine($canonical, $replyLang);
        if ($bridge['is_hiligaynon'] && $canonical !== FaqChatbotStandardEmotion::NEUTRAL) {
            $empathyWrap = FaqChatbotLanguageBridge::bilingualEmpathyLead($canonical, $empathy);
        } else {
            $empathyWrap = null;
        }
        $responseHtml = '';
        $faqId = null;
        $kbHit = null;
        $best = null;
        $confidence = (float) ($intentPack['confidence'] ?? 0.35);
        $suggestions = [];
        $geminiClassification = null;
        $geminiUsed = false;
        $useDataset = false;
        $fallbackRequired = false;
        $useServer = $mode === 'full';
        $healthcareScopeLabel = $isOpening ? 'GREETING' : ($isHealthcare ? 'HEALTHCARE' : 'OUTSIDE');
        $finalResponseType = $isOpening
            ? FaqChatbotDomainScope::RESPONSE_GREETING
            : ($isHealthcare ? FaqChatbotDomainScope::RESPONSE_MEDICAL_DATASET : FaqChatbotDomainScope::RESPONSE_OUT_OF_SCOPE);
        $inScopeIntent = in_array($intent, [
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
            FaqChatbotIntentRecognizer::GOODBYE,
            FaqChatbotIntentRecognizer::IDENTITY,
            FaqChatbotIntentRecognizer::SMALL_TALK,
            FaqChatbotIntentRecognizer::OTP,
            FaqChatbotIntentRecognizer::MEDICINE,
            FaqChatbotIntentRecognizer::PRESCRIPTION,
            FaqChatbotIntentRecognizer::HEALTH_ADVICE,
            FaqChatbotIntentRecognizer::MENTAL_HEALTH,
            FaqChatbotIntentRecognizer::TRIAGE,
            FaqChatbotIntentRecognizer::HOSPITAL,
            FaqChatbotIntentRecognizer::CONTACT,
            FaqChatbotIntentRecognizer::SCHEDULE,
            FaqChatbotIntentRecognizer::FOLLOW_UP,
            FaqChatbotIntentRecognizer::NAVIGATION,
            FaqChatbotIntentRecognizer::PROFILE,
        ], true) && $confidence >= 0.62;
        $intentStrong = $inScopeIntent;

        if (!$isHealthcare && !$isOpening) {
            $responseHtml = FaqChatbotDomainScope::replyHtml(FaqChatbotDomainScope::OUT_OF_SCOPE, $replyLang);
            $finalResponseType = FaqChatbotDomainScope::RESPONSE_OUT_OF_SCOPE;
            $useServer = true;
            $confidence = 0.95;
            $flowKey = 'domain_out_of_scope';
            $intent = 'out_of_scope';
            $_SESSION['faq_chatbot_last_intent'] = $intent;
            $suggestions = [];
            $useDataset = false;
            $fallbackRequired = false;
        } elseif ($emergency['is_emergency']) {
            $flowKey = $emergency['flow'] ?? 'emergency';
            $responseHtml = FaqChatbotResponseGenerator::emergencyHtml($replyLang, $flowKey);
            $confidence = 0.99;
            $finalResponseType = FaqChatbotDomainScope::RESPONSE_MEDICAL_DATASET;
            $useDataset = true;
            $useServer = true;
        } else {
            $faqHits = [];
            try {
                $faqHits = $this->faqRepo->search($text, 5);
            } catch (Throwable) {
                $faqHits = [];
            }
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

        // Dataset first. Gemini is fallback for unknown healthcare questions only.
        $openingOnly = $isOpening
            || (class_exists('FaqChatbotAiFallback') && FaqChatbotAiFallback::isConversationalOpeningOnly($text));
        if ($isHealthcare || $isOpening) {
            $useDataset = $openingOnly
                || (class_exists('FaqChatbotAiFallback')
                ? FaqChatbotAiFallback::shouldUseDatasetAnswer(
                    $text,
                    (bool) $emergency['is_emergency'],
                    $faqId,
                    ($faqId !== null && is_array($best ?? null)) ? $best : null,
                    $kbHit,
                    $responseHtml
                )
                : ((bool) $emergency['is_emergency'] || $faqId !== null));
            $fallbackRequired = $isHealthcare && !$openingOnly && !$useDataset && empty($emergency['is_emergency']);
            $useServer = $mode === 'full' || ($mode === 'assist' && ($useDataset || $openingOnly));

            if ($emergency['is_emergency']) {
                $finalResponseType = FaqChatbotDomainScope::RESPONSE_MEDICAL_DATASET;
                $useServer = true;
            } elseif ($useDataset || $openingOnly) {
                $greetingIntent = in_array($intent, [
                    FaqChatbotIntentRecognizer::GREETING,
                    FaqChatbotIntentRecognizer::THANKS,
                    FaqChatbotIntentRecognizer::GOODBYE,
                    FaqChatbotIntentRecognizer::IDENTITY,
                    FaqChatbotIntentRecognizer::SMALL_TALK,
                    FaqChatbotIntentRecognizer::CAPABILITIES,
                ], true);
                $finalResponseType = ($openingOnly || $greetingIntent)
                    ? FaqChatbotDomainScope::RESPONSE_GREETING
                    : FaqChatbotDomainScope::RESPONSE_MEDICAL_DATASET;
            }
        }

        if ($fallbackRequired && ($mode === 'assist' || $mode === 'full') && $isHealthcare) {
            $aiText = $effectiveText !== '' ? $effectiveText : $text;
            if (class_exists('FaqChatbotAiFallback')) {
                $aiPack = FaqChatbotAiFallback::tryAssist($aiText, $replyLang, [
                    'intent'             => $intent,
                    'emotion'            => $canonical,
                    'topic'              => $kbHit['category'] ?? ($flowKey ?: $intent),
                    'turns'              => FaqChatbotConversationMemory::get()['turns'] ?? [],
                    'already_healthcare' => true,
                ]);
                $geminiUsed = is_array($aiPack);
                if (is_array($aiPack) && trim((string) ($aiPack['html'] ?? '')) !== '') {
                    $responseHtml = (string) $aiPack['html'];
                    $geminiClassification = (string) ($aiPack['classification'] ?? '');
                    $finalResponseType = (string) ($aiPack['response_type'] ?? FaqChatbotDomainScope::RESPONSE_MEDICAL_GEMINI);
                    if ($finalResponseType === FaqChatbotDomainScope::RESPONSE_OUT_OF_SCOPE) {
                        // Message already passed the gate — do not let Gemini re-open scope.
                        $responseHtml = FaqChatbotDomainScope::replyHtml(FaqChatbotDomainScope::AMBIGUOUS, $replyLang);
                        $finalResponseType = FaqChatbotDomainScope::RESPONSE_MEDICAL_CLARIFICATION;
                        $geminiClassification = FaqChatbotAiFallback::CLASS_POSSIBLY_HEALTHCARE;
                    }
                    $useServer = true;
                    $confidence = max($confidence, 0.58);
                    $flowKey = match ($finalResponseType) {
                        FaqChatbotDomainScope::RESPONSE_GREETING => 'greeting',
                        FaqChatbotDomainScope::RESPONSE_MEDICAL_CLARIFICATION => 'domain_ambiguous',
                        default => 'ai_conversation',
                    };
                    FaqChatbotConversationMemory::update([
                        'bot_snippet' => $responseHtml,
                        'topic'       => $flowKey,
                        'intent'      => $intent,
                    ]);
                } else {
                    $responseHtml = FaqChatbotDomainScope::replyHtml(FaqChatbotDomainScope::AMBIGUOUS, $replyLang);
                    $finalResponseType = FaqChatbotDomainScope::RESPONSE_MEDICAL_CLARIFICATION;
                    $geminiClassification = FaqChatbotAiFallback::CLASS_POSSIBLY_HEALTHCARE;
                    $flowKey = 'domain_ambiguous';
                    $intent = 'clarification';
                    $useServer = true;
                    $_SESSION['faq_chatbot_last_intent'] = $intent;
                    FaqChatbotConversationMemory::update([
                        'bot_snippet' => $responseHtml,
                        'topic'       => $flowKey,
                        'intent'      => $intent,
                    ]);
                }
            } else {
                $responseHtml = FaqChatbotDomainScope::replyHtml(FaqChatbotDomainScope::AMBIGUOUS, $replyLang);
                $finalResponseType = FaqChatbotDomainScope::RESPONSE_MEDICAL_CLARIFICATION;
                $useServer = true;
                $flowKey = 'domain_ambiguous';
            }
        }

        $this->logScopeDebug(
            $text,
            (string) $intent,
            $healthcareScopeLabel,
            $useDataset,
            $geminiUsed,
            (bool) $emergency['is_emergency']
        );

        if ($responseHtml === '' && $mode === 'assist') {
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
                $nlp,
                $scopePack['scope'] ?? null,
                [
                    'dataset_match'                => $useDataset,
                    'fallback_required'            => $fallbackRequired,
                    'gemini_domain_classification' => $geminiClassification,
                    'final_response_type'          => $finalResponseType,
                    'healthcare_scope'             => $healthcareScopeLabel,
                    'gemini_used'                  => $geminiUsed,
                ]
            );
        }

        $botMsgId = 0;
        try {
            $botMsgId = $this->convRepo->insertMessage(
                $conversationId,
                'bot',
                strip_tags($responseHtml),
                $intent,
                $flowKey,
                $confidence,
                $faqId
            );
        } catch (Throwable) {
            $botMsgId = 0;
        }

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
            $nlp,
            $scopePack['scope'] ?? null,
            [
                'dataset_match'                => $useDataset,
                'fallback_required'            => $fallbackRequired,
                'gemini_domain_classification' => $geminiClassification,
                'final_response_type'          => $finalResponseType,
                'healthcare_scope'             => $healthcareScopeLabel,
                'gemini_used'                  => $geminiUsed,
            ]
        );
    }

    /**
     * Internal routing log. Never shown to users.
     */
    private function logScopeDebug(
        string $message,
        string $intent,
        string $healthcareScope,
        bool $datasetMatch,
        bool $geminiUsed,
        bool $emergency
    ): void {
        $flag = strtolower((string) (getenv('FAQ_CHATBOT_SCOPE_DEBUG') ?: ($_ENV['FAQ_CHATBOT_SCOPE_DEBUG'] ?? '')));
        if (!in_array($flag, ['1', 'true', 'yes'], true)) {
            return;
        }
        error_log(sprintf(
            '[faq-chatbot] message=%s intent=%s healthcare_scope=%s dataset_match=%s gemini_used=%s emergency=%s',
            json_encode(mb_substr($message, 0, 160), JSON_UNESCAPED_UNICODE),
            $intent,
            $healthcareScope,
            $datasetMatch ? 'true' : 'false',
            $geminiUsed ? 'true' : 'false',
            $emergency ? 'true' : 'false'
        ));
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
     * @param array{
     *   dataset_match?: bool,
     *   fallback_required?: bool,
     *   gemini_domain_classification?: ?string,
     *   final_response_type?: ?string,
     *   healthcare_scope?: ?string,
     *   gemini_used?: bool
     * } $routing
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
        ?array $nlp = null,
        ?string $domainScope = null,
        array $routing = []
    ): array {
        return [
            'session_id'                   => $sessionId,
            'conversation_id'              => $conversationId,
            'user_message_id'              => $userMessageId,
            'bot_message_id'               => $botMessageId,
            'emotion'                      => $canonical,
            'emotion_label'                => FaqChatbotStandardEmotion::label($canonical, $lang),
            'emotion_detail'               => $emotionResult['emotion'] ?? null,
            'intent'                       => $intent,
            'flow_key'                     => $flowKey,
            'confidence'                   => round($confidence, 3),
            'emergency'                    => (bool) $emergency['is_emergency'],
            'emergency_flow'               => $emergency['flow'] ?? null,
            'response_html'                => $responseHtml,
            'empathy_html'                 => $emotionResult['empathy_html'] ?? '',
            'suggestions'                  => $suggestions,
            'typing_ms'                    => $typingMs,
            'use_server_response'          => $useServer && ($mode === 'full' || $mode === 'assist'),
            'mode'                         => $mode,
            'detected_lang'                => $nlp['detected_lang'] ?? $lang,
            'english_gloss'                => $nlp['english_text'] ?? '',
            'nlp_pipeline'                 => $nlp['pipeline_steps'] ?? [],
            'domain_scope'                 => $domainScope,
            'healthcare_scope'             => $routing['healthcare_scope'] ?? ($domainScope === FaqChatbotDomainScope::OUT_OF_SCOPE ? 'OUTSIDE' : ($domainScope === FaqChatbotDomainScope::MEDICAL ? 'HEALTHCARE' : 'GREETING')),
            'dataset_match'                => (bool) ($routing['dataset_match'] ?? false),
            'fallback_required'            => (bool) ($routing['fallback_required'] ?? false),
            'gemini_used'                  => (bool) ($routing['gemini_used'] ?? false),
            'gemini_domain_classification' => $routing['gemini_domain_classification'] ?? null,
            'final_response_type'          => $routing['final_response_type'] ?? null,
        ];
    }
}
