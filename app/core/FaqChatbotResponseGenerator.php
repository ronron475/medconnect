<?php
/**
 * Builds warm, safe chatbot copy (no diagnosis). PHP templates + FAQ merge.
 */
final class FaqChatbotResponseGenerator
{
    /**
     * Opening line by canonical emotion (healthcare-appropriate).
     */
    public static function empathyLine(string $canonical, string $lang): string
    {
        $L = FaqEmotionEngine::normalizeLang($lang);
        $lines = [
            'en' => [
                FaqChatbotStandardEmotion::HAPPY      => "That's wonderful to hear! How else can I help you today?",
                FaqChatbotStandardEmotion::SAD        => "I'm sorry you're feeling this way. Let me see how I can help.",
                FaqChatbotStandardEmotion::WORRIED    => "I understand your concern. Let's go through it together.",
                FaqChatbotStandardEmotion::ANGRY      => "I'm sorry for the frustration. I'll do my best to help resolve this.",
                FaqChatbotStandardEmotion::FRUSTRATED => "I hear you — that can be really frustrating. We'll take it step by step.",
                FaqChatbotStandardEmotion::CONFUSED   => "No worries — I'll explain things clearly, one step at a time.",
                FaqChatbotStandardEmotion::FEARFUL    => "It's understandable to feel scared. I'm here to guide you with medConnect services.",
                FaqChatbotStandardEmotion::NEUTRAL    => "I'm here to help with medConnect and City Health services.",
            ],
            'fil' => [
                FaqChatbotStandardEmotion::HAPPY      => 'Masaya akong marinig iyon! Paano pa kita matutulungan?',
                FaqChatbotStandardEmotion::SAD        => 'Paumanhin sa nararamdaman mo. Tingnan natin kung paano kita matutulungan.',
                FaqChatbotStandardEmotion::WORRIED    => 'Naiintindihan ko ang iyong alalahanin. Sama-sama nating lutasin ito.',
                FaqChatbotStandardEmotion::ANGRY      => 'Paumanhin sa abala. Gagawin ko ang makakaya para matulungan ka.',
                FaqChatbotStandardEmotion::FRUSTRATED => 'Naiintindihan ko — nakakainis nga iyan. Hakbang-hakbang lang tayo.',
                FaqChatbotStandardEmotion::CONFUSED   => 'Walang problema — ipapaliwanag ko nang malinaw.',
                FaqChatbotStandardEmotion::FEARFUL    => 'Natural lang matakot. Nandito ako para gabayan ka sa medConnect.',
                FaqChatbotStandardEmotion::NEUTRAL    => 'Nandito ako para tumulong sa medConnect at serbisyo ng City Health.',
            ],
            'hil' => [
                FaqChatbotStandardEmotion::HAPPY      => 'Maayo nga mabatian! Paano pa ko ikaw matabangan?',
                FaqChatbotStandardEmotion::SAD        => 'Pasensya sa imo nabatyagan. Tan-awon ta kon paano ko ikaw matabangan.',
                FaqChatbotStandardEmotion::WORRIED    => 'Nakaintindi ako sang imo kabalaka. Sige, mag-upod kita.',
                FaqChatbotStandardEmotion::ANGRY      => 'Pasensya sa abala. Himuon ko ang akon maayo para matabangan ka.',
                FaqChatbotStandardEmotion::FRUSTRATED => 'Nakaintindi ako — makalain gid sina. Pahuway-pahuway lang ta.',
                FaqChatbotStandardEmotion::CONFUSED   => 'Wala problema — ipahayag ko sing malinaw.',
                FaqChatbotStandardEmotion::FEARFUL    => 'Natural lang mahadlok. Diri ako para giyahan ka sa medConnect.',
                FaqChatbotStandardEmotion::NEUTRAL    => 'Diri ako para buligan ka sa medConnect kag City Health.',
            ],
        ];
        $pack = $lines[$L] ?? $lines['en'];
        return $pack[$canonical] ?? $pack[FaqChatbotStandardEmotion::NEUTRAL];
    }

    public static function emergencyHtml(string $lang, string $flow): string
    {
        $L = FaqEmotionEngine::normalizeLang($lang);
        if ($flow === 'crisis') {
            $msg = $L === 'fil'
                ? '<p><strong>Kailangan mo ng agarang suporta.</strong> Kung nasa panganib ka, tumawag sa <strong>911</strong> o Hopeline <strong>1553</strong>. Hindi ako makakapag-diagnose o makakapalit ng propesyonal na mental health care.</p>'
                : ($L === 'hil'
                    ? '<p><strong>Kinahanglan mo gid sang dulungan.</strong> Kon sa katalagman ka, tawagi ang <strong>911</strong> ukon Hopeline <strong>1553</strong>. Indi ako makadiagnose ukon makabulos sang propesyonal nga mental health care.</p>'
                    : '<p><strong>You deserve immediate support.</strong> If you are in danger, call <strong>911</strong> or Hopeline <strong>1553</strong>. I cannot diagnose or replace professional mental health care.</p>');
        } else {
            $msg = $L === 'fil'
                ? '<p><strong>Mukhang medikal na emergency ito.</strong> Tumawag sa <strong>911</strong> o pumunta sa pinakamalapit na emergency room. Huwag hintayin ang chat — kailangan mo ng agarang pangangalaga.</p>'
                : ($L === 'hil'
                    ? '<p><strong>Mukhang medical emergency ini.</strong> Tawagi ang <strong>911</strong> ukon kadto sa pinakamalapit nga emergency room. Indi maghulat sa chat — kinahanglan mo gid sang agarang care.</p>'
                    : '<p><strong>This sounds like a medical emergency.</strong> Call <strong>911</strong> or go to the nearest emergency room. Do not wait for chat — you need urgent in-person care.</p>');
        }
        return '<div class="fcb-emergency-card" role="alert">' . $msg . '</div>';
    }

    /**
     * Conversational fallback when FAQ match is weak (not “I don't know”).
     */
    public static function conversationalFallback(string $lang, string $intent): string
    {
        $L = FaqEmotionEngine::normalizeLang($lang);
        $pool = [
            'en' => [
                FaqChatbotResponseTemplates::html('no_exact_faq', 'en'),
            ],
            'fil' => [
                FaqChatbotResponseTemplates::html('no_exact_faq', 'fil'),
            ],
            'hil' => [
                FaqChatbotResponseTemplates::html('no_exact_faq', 'hil'),
            ],
        ];
        $lines = $pool[$L] ?? $pool['en'];
        $idx = crc32($intent . '|' . $L) % count($lines);
        return $lines[$idx];
    }

    public static function wrapAnswer(string $empathy, string $bodyHtml): string
    {
        $emp = htmlspecialchars($empathy, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $lead = '<p class="fcb-php-lead"><em>' . $emp . '</em></p>';
        return $lead . $bodyHtml;
    }

    public static function faqAnswerHtml(string $answer): string
    {
        $safe = nl2br(htmlspecialchars($answer, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        return '<div class="fcb-faq-answer">' . $safe . '</div>';
    }

    public static function medicalDisclaimer(string $lang): string
    {
        $L = FaqEmotionEngine::normalizeLang($lang);
        $text = $L === 'fil'
            ? 'Paalala: Hindi ako doktor at hindi ako makakapag-diagnose o magreseta ng gamot.'
            : ($L === 'hil'
                ? 'Paalala: Indi ako doktor kag indi ako makadiagnose ukon makapreskribar sang bulong.'
                : 'Reminder: I am not a doctor and cannot diagnose conditions or prescribe medication.');
        return '<p class="fcb-disclaimer-inline"><small>' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</small></p>';
    }
}
