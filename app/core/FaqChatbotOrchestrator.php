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

        // Greeting → emergency → dataset → Gemini classification.
        $scopePack = FaqChatbotDomainScope::classify($effectiveText, $matchText);
        $scope = (string) ($scopePack['scope'] ?? '');
        $isOpening = in_array($scope, [
            FaqChatbotDomainScope::GREETING,
            FaqChatbotDomainScope::CONVERSATION,
            FaqChatbotDomainScope::HELP_OPEN,
        ], true)
            || (class_exists('FaqChatbotAiFallback') && FaqChatbotAiFallback::isConversationalOpeningOnly($text));

        if (!$isOpening) {
            $focusText = FaqChatbotDomainScope::healthcareFocusText($effectiveText, $matchText);
            if ($focusText !== '' && mb_strtolower($focusText) !== mb_strtolower(trim($effectiveText))) {
                $effectiveText = $focusText;
                $matchText = FaqChatbotConversationMemory::contextualMatchText($focusText, $nlpText);
            }
        }

        $emergency = ['is_emergency' => false, 'type' => null, 'flow' => null, 'reason' => ''];
        if (!$isOpening) {
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

        $intentPack = FaqChatbotIntentRecognizer::recognize($matchText);
        if (!empty($emergency['is_emergency'])) {
            $intentPack = [
                'intent'     => FaqChatbotIntentRecognizer::EMERGENCY,
                'confidence' => 0.99,
                'flow_key'   => $emergency['flow'],
            ];
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
        $healthcareScopeLabel = $isOpening ? 'GREETING' : 'PENDING';
        $finalResponseType = $isOpening
            ? FaqChatbotDomainScope::RESPONSE_GREETING
            : FaqChatbotDomainScope::RESPONSE_MEDICAL_DATASET;
        $datasetScore = 0.0;
        $geminiMeta = [
            'is_healthcare_related' => null,
            'intent'                => null,
            'language'              => null,
            'normalized_meaning'    => null,
            'urgency'               => null,
            'confidence'            => null,
        ];
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

        if ($emergency['is_emergency']) {
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

            $faqUsable = $best
                && ($best['score'] ?? 0) >= $faqThreshold
                && class_exists('FaqChatbotAiFallback')
                && FaqChatbotAiFallback::currentMessageSupportsFaq($text, $best);
            if ($faqUsable) {
                $datasetScore = (float) $best['score'];
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
                $best = null;
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
                    $existingScore = (float) ($kbHit['score'] ?? 0);
                    $comboKey = (string) ($combined['key'] ?? '');
                    $hay = mb_strtolower($effectiveText . ' ' . $matchText);
                    $hasEmotionWord = (bool) preg_match(
                        '/\b(nahadlok|scared|afraid|ginakulbaan|worried|kasubo|crying|akig|sad|anxious|panic)\b/ui',
                        $hay
                    );
                    $keepSymptomCard = $existingScore >= 2.2
                        && in_array((string) ($kbHit['key'] ?? ''), ['symptoms_general', 'worry_symptoms', 'common_illness'], true)
                        && $comboKey === 'emotion_and_symptoms'
                        && !$hasEmotionWord;
                    if (!$keepSymptomCard) {
                        $kbHit = $combined;
                        $intent = (string) ($combined['intent'] ?? $intent);
                        $_SESSION['faq_chatbot_last_intent'] = $intent;
                    }
                }

                if ($kbHit !== null) {
                    $lead = $empathyWrap ?? FaqChatbotResponseGenerator::wrapAnswer($empathy, '');
                    // Crisis / emergency KB cards already carry strong framing — light empathy only
                    if (in_array($kbHit['key'], ['crisis_hopeless', 'emergency_redirect'], true)) {
                        $responseHtml = $kbHit['html'];
                    } else {
                        $responseHtml = $followBridge . $lead . $kbHit['html'];
                    }
                    $datasetScore = max($datasetScore, (float) ($kbHit['score'] ?? 0));
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

        $openingOnly = $isOpening
            || (class_exists('FaqChatbotAiFallback') && FaqChatbotAiFallback::isConversationalOpeningOnly($text));
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
        $fallbackRequired = !$openingOnly && !$useDataset && empty($emergency['is_emergency']);
        $useServer = $mode === 'full' || ($mode === 'assist' && ($useDataset || $openingOnly || $fallbackRequired));

        if ($emergency['is_emergency']) {
            $healthcareScopeLabel = 'HEALTHCARE';
            $finalResponseType = FaqChatbotDomainScope::RESPONSE_MEDICAL_DATASET;
            $useServer = true;
        } elseif ($openingOnly) {
            $healthcareScopeLabel = 'GREETING';
            $finalResponseType = FaqChatbotDomainScope::RESPONSE_GREETING;
        } elseif ($useDataset) {
            $healthcareScopeLabel = 'HEALTHCARE';
            $finalResponseType = FaqChatbotDomainScope::RESPONSE_MEDICAL_DATASET;
        }

        if ($fallbackRequired && ($mode === 'assist' || $mode === 'full')) {
            $aiText = $effectiveText !== '' ? $effectiveText : $text;
            if (class_exists('FaqChatbotAiFallback')) {
                $aiPack = FaqChatbotAiFallback::tryAssist($aiText, $replyLang, [
                    'intent'  => $intent,
                    'emotion' => $canonical,
                    'topic'   => $kbHit['category'] ?? ($flowKey ?: $intent),
                    'turns'   => FaqChatbotConversationMemory::get()['turns'] ?? [],
                ]);
                $geminiUsed = is_array($aiPack);
                if (is_array($aiPack)) {
                    $geminiClassification = (string) ($aiPack['classification'] ?? '');
                    $geminiMeta = [
                        'is_healthcare_related' => $aiPack['is_healthcare_related'] ?? ($geminiClassification === FaqChatbotAiFallback::CLASS_HEALTH_RELATED),
                        'intent'                => $aiPack['detected_intent'] ?? $aiPack['intent'] ?? null,
                        'language'              => $aiPack['language'] ?? $detectedLang,
                        'normalized_meaning'    => $aiPack['normalized_meaning'] ?? null,
                        'urgency'               => $aiPack['urgency'] ?? null,
                        'confidence'            => $aiPack['model_confidence'] ?? $aiPack['confidence'] ?? null,
                    ];
                    $urgency = strtoupper((string) ($geminiMeta['urgency'] ?? ''));
                    $isHealthClass = $geminiClassification === FaqChatbotAiFallback::CLASS_HEALTH_RELATED
                        || $geminiClassification === 'HEALTHCARE';
                    $isNonHealthClass = $geminiClassification === FaqChatbotAiFallback::CLASS_NON_HEALTH_RELATED
                        || $geminiClassification === 'NON_HEALTHCARE';
                    $isUnclearClass = $geminiClassification === FaqChatbotAiFallback::CLASS_UNCLEAR
                        || $geminiClassification === 'POSSIBLY_HEALTHCARE'
                        || $geminiClassification === '';
                    if ($isHealthClass
                        && FaqChatbotDomainScope::looksUnclear($aiText, $matchText)
                        && !FaqChatbotDomainScope::isHealthcareRelated($aiText, $matchText)
                    ) {
                        $isHealthClass = false;
                        $isUnclearClass = true;
                        $geminiClassification = FaqChatbotAiFallback::CLASS_UNCLEAR;
                        $geminiMeta['is_healthcare_related'] = false;
                    }
                    if ($isHealthClass && $urgency === 'EMERGENCY') {
                        $emergency = [
                            'is_emergency' => true,
                            'type'         => 'medical',
                            'flow'         => 'emergency',
                            'reason'       => 'gemini_emergency_classification',
                        ];
                        $responseHtml = FaqChatbotResponseGenerator::emergencyHtml($replyLang, 'emergency');
                        $finalResponseType = FaqChatbotDomainScope::RESPONSE_MEDICAL_DATASET;
                        $healthcareScopeLabel = 'HEALTHCARE';
                        $flowKey = 'emergency';
                        $intent = FaqChatbotIntentRecognizer::EMERGENCY;
                    } elseif ($isNonHealthClass || (($geminiMeta['is_healthcare_related'] ?? null) === false && !$isHealthClass && !$isUnclearClass)) {
                        $responseHtml = FaqChatbotDomainScope::nonHealthHtml($replyLang);
                        $finalResponseType = FaqChatbotDomainScope::RESPONSE_NON_HEALTH;
                        $healthcareScopeLabel = 'OUTSIDE';
                        $flowKey = 'domain_non_health';
                        $intent = 'non_health';
                        $suggestions = [];
                    } elseif ($isHealthClass) {
                        $html = trim((string) ($aiPack['html'] ?? ''));
                        $responseHtml = $html !== ''
                            ? $html
                            : FaqChatbotDomainScope::unmatchedHealthcareHtml($replyLang);
                        $finalResponseType = FaqChatbotDomainScope::RESPONSE_MEDICAL_GEMINI;
                        $healthcareScopeLabel = 'HEALTHCARE';
                        $flowKey = 'ai_conversation';
                        $suggestions = [];
                    } else {
                        $responseHtml = FaqChatbotDomainScope::unclearHtml($replyLang);
                        $finalResponseType = FaqChatbotDomainScope::RESPONSE_UNCLEAR;
                        $healthcareScopeLabel = 'UNCLEAR';
                        $flowKey = 'message_unclear';
                        $intent = 'unclear';
                        $suggestions = [];
                    }
                    $useServer = true;
                    $confidence = max($confidence, 0.62);
                    $_SESSION['faq_chatbot_last_intent'] = $intent;
                    FaqChatbotConversationMemory::update([
                        'bot_snippet' => $responseHtml,
                        'topic'       => $flowKey,
                        'intent'      => $intent,
                    ]);
                } else {
                    if (FaqChatbotDomainScope::isHealthcareRelated($text, $matchText)) {
                        $responseHtml = FaqChatbotDomainScope::unmatchedHealthcareHtml($replyLang);
                        $finalResponseType = FaqChatbotDomainScope::RESPONSE_MEDICAL_CLARIFICATION;
                        $healthcareScopeLabel = 'HEALTHCARE';
                        $flowKey = 'ai_conversation';
                        $suggestions = [];
                    } elseif (FaqChatbotDomainScope::looksUnclear($text, $matchText)) {
                        $responseHtml = FaqChatbotDomainScope::unclearHtml($replyLang);
                        $finalResponseType = FaqChatbotDomainScope::RESPONSE_UNCLEAR;
                        $healthcareScopeLabel = 'UNCLEAR';
                        $flowKey = 'message_unclear';
                        $intent = 'unclear';
                        $suggestions = [];
                    } else {
                        $responseHtml = FaqChatbotDomainScope::nonHealthHtml($replyLang);
                        $finalResponseType = FaqChatbotDomainScope::RESPONSE_NON_HEALTH;
                        $healthcareScopeLabel = 'OUTSIDE';
                        $flowKey = 'domain_non_health';
                        $intent = 'non_health';
                        $suggestions = [];
                    }
                    $useServer = true;
                    $_SESSION['faq_chatbot_last_intent'] = $intent;
                    FaqChatbotConversationMemory::update([
                        'bot_snippet' => $responseHtml,
                        'topic'       => $flowKey,
                        'intent'      => $intent,
                    ]);
                }
            } else {
                if (FaqChatbotDomainScope::looksUnclear($text, $matchText)) {
                    $responseHtml = FaqChatbotDomainScope::unclearHtml($replyLang);
                    $finalResponseType = FaqChatbotDomainScope::RESPONSE_UNCLEAR;
                    $healthcareScopeLabel = 'UNCLEAR';
                    $flowKey = 'message_unclear';
                } else {
                    $responseHtml = FaqChatbotDomainScope::nonHealthHtml($replyLang);
                    $finalResponseType = FaqChatbotDomainScope::RESPONSE_NON_HEALTH;
                    $healthcareScopeLabel = 'OUTSIDE';
                    $flowKey = 'domain_non_health';
                }
                $useServer = true;
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
                $this->routingFields(
                    $useDataset,
                    $fallbackRequired,
                    $geminiClassification,
                    $finalResponseType,
                    $healthcareScopeLabel,
                    $geminiUsed,
                    $datasetScore,
                    $geminiMeta
                )
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
            $this->routingFields(
                $useDataset,
                $fallbackRequired,
                $geminiClassification,
                $finalResponseType,
                $healthcareScopeLabel,
                $geminiUsed,
                $datasetScore,
                $geminiMeta
            )
        );
    }

    /**
     * @param array<string, mixed> $geminiMeta
     * @return array<string, mixed>
     */
    private function routingFields(
        bool $useDataset,
        bool $fallbackRequired,
        ?string $geminiClassification,
        ?string $finalResponseType,
        string $healthcareScope,
        bool $geminiUsed,
        float $datasetScore,
        array $geminiMeta
    ): array {
        return [
            'dataset_match'                => $useDataset,
            'dataset_match_score'          => round($datasetScore, 3),
            'fallback_required'            => $fallbackRequired,
            'gemini_domain_classification' => $geminiClassification,
            'final_response_type'          => $finalResponseType,
            'healthcare_scope'             => $healthcareScope,
            'gemini_used'                  => $geminiUsed,
            'gemini_healthcare'            => $geminiMeta['is_healthcare_related'] ?? null,
            'gemini_intent'                => $geminiMeta['intent'] ?? null,
            'gemini_language'              => $geminiMeta['language'] ?? null,
            'gemini_normalized_meaning'    => $geminiMeta['normalized_meaning'] ?? null,
            'gemini_urgency'               => $geminiMeta['urgency'] ?? null,
            'gemini_confidence'            => $geminiMeta['confidence'] ?? null,
        ];
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
        if ($responseHtml !== '' && class_exists('FaqChatbotAiFallback')) {
            $responseHtml = FaqChatbotAiFallback::sanitizePatientFacingHtml($responseHtml, $lang);
        }
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
            'dataset_match_score'          => (float) ($routing['dataset_match_score'] ?? 0),
            'fallback_required'            => (bool) ($routing['fallback_required'] ?? false),
            'gemini_used'                  => (bool) ($routing['gemini_used'] ?? false),
            'gemini_domain_classification' => $routing['gemini_domain_classification'] ?? null,
            'gemini_healthcare'            => $routing['gemini_healthcare'] ?? null,
            'gemini_intent'                => $routing['gemini_intent'] ?? null,
            'gemini_language'              => $routing['gemini_language'] ?? null,
            'gemini_normalized_meaning'    => $routing['gemini_normalized_meaning'] ?? null,
            'gemini_urgency'               => $routing['gemini_urgency'] ?? null,
            'gemini_confidence'            => $routing['gemini_confidence'] ?? null,
            'final_response_type'          => $routing['final_response_type'] ?? null,
        ];
    }
}
