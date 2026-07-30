<?php
/**
 * Warm, human-like response templates (PHP only — no external AI).
 */
final class FaqChatbotResponseTemplates
{
    /**
     * @return array{en: string, fil: string, hil: string}
     */
    public static function get(string $key): array
    {
        static $templates = [
            'not_understood' => [
                'en'  => '<p>I\'m sorry — I couldn\'t fully understand your question. Could you explain it a little differently? I\'m here to help with medConnect, appointments, registration, and City Health services.</p>',
                'fil' => '<p>Paumanhin — hindi ko lubos na maintindihan ang tanong. Maaari mo bang ipaliwanag ito nang kaunti? Nandito ako para tumulong sa medConnect, appointments, rehistro, at serbisyo ng City Health.</p>',
                'hil' => '<p>Pasensya — indi ko gid maintindihan ang imo pamangkot. Pwede mo bala i-saysay liwat? Diri ako para buligan ka sa medConnect, appointments, rehistro, kag serbisyo sang City Health.</p>',
            ],
            'no_exact_faq' => [
                'en'  => '<p>I couldn\'t find an exact answer in my guide, but based on what you shared, here\'s what may help. If this is about your health, I can help you connect with a provider — I cannot diagnose or prescribe.</p>',
                'fil' => '<p>Wala akong eksaktong sagot sa gabay, pero batay sa sinabi mo, maaaring makatulong ito. Kung may kinalaman sa kalusugan, matutulungan kitang makipag-ugnayan sa provider — hindi ako makakapag-diagnose o magreseta.</p>',
                'hil' => '<p>Wala ako eksaktong sabat sa akon guide, pero base sa imo ginsiling, amo ni ang mahimo makabulig. Kon parte sa imo kahimsog, matabangan ko ikaw makakonekta sa provider — indi ako makadiagnose ukon makapreskribar.</p>',
            ],
            'follow_up' => [
                'en'  => '<p>Thanks for the extra detail — that helps. What would you like to do next?</p>',
                'fil' => '<p>Salamat sa dagdag na detalye — makakatulong iyon. Ano ang gusto mong gawin susunod?</p>',
                'hil' => '<p>Salamat sa dugang nga detalye — makabulig ni. Ano ang gusto mo himuon sunod?</p>',
            ],
        ];

        return $templates[$key] ?? $templates['not_understood'];
    }

    public static function html(string $key, string $lang): string
    {
        $pack = self::get($key);
        $L = FaqEmotionEngine::normalizeLang($lang);
        return $pack[$L] ?? $pack['en'];
    }
}
