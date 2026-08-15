<?php
/**
 * Unified chatbot knowledge layer — searches scenario index, KB packs, synonyms, and dictionaries.
 * Does not replace existing datasets; combines them at query time.
 */
final class FaqChatbotUnifiedKnowledge
{
    /**
     * Multi-stage search across all integrated chatbot knowledge sources.
     *
     * @param array{
     *   intent?: string,
     *   emotion?: ?string,
     *   session_id?: string,
     *   context_boost?: string,
     *   lang?: string
     * } $ctx
     * @return array{
     *   key: string,
     *   category: string,
     *   score: float,
     *   html: string,
     *   flow_key: string,
     *   intent: string,
     *   sources: list<string>
     * }|null
     */
    public static function search(PDO $pdo, string $rawText, string $nlpText, string $lang, array $ctx = []): ?array
    {
        $lang = FaqEmotionEngine::normalizeLang($lang);
        $sources = [];
        $best = null;
        $bestScore = 0.0;

        $lex = FaqChatbotConversationalIntents::match($rawText, $nlpText);
        if ($lex !== null && ($lex['kb_key'] ?? '') !== '') {
            $kbKey = (string) $lex['kb_key'];
            $html = FaqChatbotKnowledgeBase::pickResponse($kbKey, $lang, (string) ($ctx['session_id'] ?? ''));
            $best = [
                'key'      => $kbKey,
                'category' => (string) $lex['category'],
                'score'    => max(2.4, (float) $lex['score']),
                'html'     => $html,
                'flow_key' => (string) ($lex['flow_key'] ?: self::flowForIntent((string) $lex['intent'], $kbKey)),
                'intent'   => (string) $lex['intent'],
                'sources'  => ['conversational_lexicon'],
            ];
            $bestScore = (float) $best['score'];
            $sources[] = 'conversational_lexicon';
        }

        $scenario = null;
        try {
            $scenario = FaqChatbotScenarioIndex::match($rawText, $nlpText, $ctx);
        } catch (Throwable) {
            $scenario = null;
        }
        if ($scenario !== null && ($scenario['kb_key'] ?? '') !== '') {
            $score = (float) $scenario['score'];
            if ($score > $bestScore) {
                $kbKey = (string) $scenario['kb_key'];
                $html = FaqChatbotKnowledgeBase::pickResponse($kbKey, $lang, (string) ($ctx['session_id'] ?? ''));
                $best = [
                    'key'      => $kbKey,
                    'category' => (string) $scenario['category'],
                    'score'    => $score,
                    'html'     => $html,
                    'flow_key' => self::flowForIntent((string) $scenario['intent'], $kbKey),
                    'intent'   => (string) $scenario['intent'],
                    'sources'  => array_values(array_unique([...$sources, 'scenario_index'])),
                ];
                $bestScore = $score;
            }
            $sources[] = 'scenario_index';
        }

        $kb = FaqChatbotKnowledgeBase::match($rawText, $nlpText, $lang, $ctx);
        if ($kb !== null && (float) ($kb['score'] ?? 0) > $bestScore) {
            $best = [
                'key'      => (string) $kb['key'],
                'category' => (string) $kb['category'],
                'score'    => (float) $kb['score'],
                'html'     => (string) $kb['html'],
                'flow_key' => (string) ($kb['flow_key'] ?? $kb['key']),
                'intent'   => (string) ($ctx['intent'] ?? self::intentFromKbKey((string) $kb['key'])),
                'sources'  => array_values(array_unique([...$sources, 'kb_packs'])),
            ];
            $bestScore = (float) $kb['score'];
        } elseif ($kb !== null) {
            $sources[] = 'kb_packs';
        }

        $intent = (string) ($ctx['intent'] ?? '');
        if ($best === null && $intent !== '') {
            $intentKey = FaqChatbotKnowledgeBase::keyForIntent($intent);
            if ($intentKey !== null) {
                $html = FaqChatbotKnowledgeBase::pickResponse(
                    $intentKey,
                    $lang,
                    (string) ($ctx['session_id'] ?? '')
                );
                $best = [
                    'key'      => $intentKey,
                    'category' => $intent,
                    'score'    => 2.4,
                    'html'     => $html,
                    'flow_key' => self::flowForIntent($intent, $intentKey),
                    'intent'   => $intent,
                    'sources'  => ['intent_map'],
                ];
            }
        }

        if ($best !== null) {
            $best['sources'] = array_values(array_unique($best['sources'] ?? $sources));
            return $best;
        }

        // Symptom / medical term hint from synonym expansion (read-only health routing)
        try {
            $syn = new FaqChatbotSynonymEngine($pdo);
            $expanded = $syn->expandToString($nlpText, $lang);
            if ($expanded !== FaqChatbotTextNormalizer::normalize($nlpText)) {
                $symptomKb = FaqChatbotKnowledgeBase::match($rawText, $expanded, $lang, $ctx);
                if ($symptomKb !== null && (float) ($symptomKb['score'] ?? 0) >= 2.0) {
                    return [
                        'key'      => (string) $symptomKb['key'],
                        'category' => (string) $symptomKb['category'],
                        'score'    => (float) $symptomKb['score'],
                        'html'     => (string) $symptomKb['html'],
                        'flow_key' => (string) ($symptomKb['flow_key'] ?? 'symptoms_general'),
                        'intent'   => 'symptoms',
                        'sources'  => ['synonyms', 'kb_packs'],
                    ];
                }
            }
        } catch (Throwable) {
            // synonyms table may be absent
        }

        return null;
    }

    public static function smartClarification(string $lang, string $intent, ?string $emotion = null): string
    {
        $L = FaqEmotionEngine::normalizeLang($lang);
        $emotionLead = '';
        if ($emotion !== null && $emotion !== FaqChatbotStandardEmotion::NEUTRAL) {
            $emotionLead = FaqChatbotResponseGenerator::empathyLine(
                FaqChatbotStandardEmotion::canonicalize($emotion),
                $L
            );
        }

        $body = match ($intent) {
            FaqChatbotIntentRecognizer::FINANCIAL => $L === 'fil'
                ? '<p>Gusto kong matiyak na naiintindihan kita. Nagtatanong ka ba tungkol sa gastos ng konsultasyon, libreng serbisyo, o paano makakuha ng affordable na care?</p>'
                : ($L === 'hil'
                    ? '<p>Gusto ko siguraduhon nga husto ang akon pag-intindi. Nagapamangkot ka bala parte sa cost sang konsultasyon, libre nga serbisyo, ukon paano makakuha sang affordable nga care?</p>'
                    : '<p>I want to make sure I understand you correctly. Are you asking about consultation cost, free services, or affordable care options?</p>'),
            FaqChatbotIntentRecognizer::APPOINTMENT => $L === 'fil'
                ? '<p>Gusto kong matiyak na naiintindihan kita. Tungkol ba ito sa pag-book, pag-cancel, o status ng appointment mo?</p>'
                : ($L === 'hil'
                    ? '<p>Gusto ko siguraduhon nga husto ang akon pag-intindi. Parte bala ini sa pag-book, pag-cancel, ukon status sang imo appointment?</p>'
                    : '<p>I want to make sure I understand you. Is this about booking, canceling, or checking your appointment status?</p>'),
            FaqChatbotIntentRecognizer::SYMPTOMS => $L === 'fil'
                ? '<p>Gusto kong matulungan ka. Maaari mo bang ibahagi ang mga sintomas at kailan nagsimula?</p>'
                : ($L === 'hil'
                    ? '<p>Gusto ko ikaw matabangan. Pwede mo bala ihambal ang mga sintomas kag san-o nagsugod?</p>'
                    : '<p>I want to help. Could you share your symptoms and when they started?</p>'),
            default => $L === 'fil'
                ? '<p>Gusto kong matiyak na matutulungan kita nang tama. Tungkol ba ito sa appointment, account, video consultation, o health concern?</p>'
                : ($L === 'hil'
                    ? '<p>Gusto ko siguraduhon nga husto ang bulig ko. Parte bala ini sa appointment, account, video consultation, ukon health concern?</p>'
                    : '<p>I want to make sure I help you correctly. Are you asking about your appointment, your account, a video consultation, or a health concern?</p>'),
        };

        if ($emotionLead !== '') {
            $emp = htmlspecialchars($emotionLead, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            return '<p class="fcb-php-lead"><em>' . $emp . '</em></p>' . $body;
        }
        return $body;
    }

    private static function flowForIntent(string $intent, string $kbKey): string
    {
        return match ($intent) {
            FaqChatbotIntentRecognizer::FINANCIAL => 'financial',
            FaqChatbotIntentRecognizer::CONNECTIVITY => 'video',
            FaqChatbotIntentRecognizer::WEATHER, FaqChatbotIntentRecognizer::TRANSPORT => 'distress_support',
            FaqChatbotIntentRecognizer::EMOTIONAL_SUPPORT, FaqChatbotIntentRecognizer::MENTAL_HEALTH => 'distress_support',
            FaqChatbotIntentRecognizer::APPOINTMENT => 'appointment',
            FaqChatbotIntentRecognizer::CONSULTATION => 'video',
            FaqChatbotIntentRecognizer::LOGIN, FaqChatbotIntentRecognizer::REGISTRATION, FaqChatbotIntentRecognizer::PASSWORD_RESET => $kbKey,
            default => $kbKey,
        };
    }

    private static function intentFromKbKey(string $key): string
    {
        $map = [
            'financial_barrier' => FaqChatbotIntentRecognizer::FINANCIAL,
            'financial_access'  => FaqChatbotIntentRecognizer::FINANCIAL,
            'book_appointment'  => FaqChatbotIntentRecognizer::APPOINTMENT,
            'video_consult'     => FaqChatbotIntentRecognizer::CONSULTATION,
            'signal_internet_problem' => FaqChatbotIntentRecognizer::CONNECTIVITY,
            'symptoms_general'  => FaqChatbotIntentRecognizer::SYMPTOMS,
            'login_help'        => FaqChatbotIntentRecognizer::LOGIN,
            'registration_help' => FaqChatbotIntentRecognizer::REGISTRATION,
        ];
        return $map[$key] ?? FaqChatbotIntentRecognizer::FAQ;
    }
}
