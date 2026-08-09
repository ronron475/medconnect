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
                'en'  => '<p>I\'m sorry — I couldn\'t fully understand your question. Could you explain it a little differently? I\'m here to help with medConnect, appointments, registration, how you\'re feeling, and City Health services.</p>',
                'fil' => '<p>Paumanhin — hindi ko lubos na maintindihan ang tanong. Maaari mo bang ipaliwanag ito nang kaunti? Nandito ako para tumulong sa medConnect, appointments, rehistro, nararamdaman mo, at serbisyo ng City Health.</p>',
                'hil' => '<p>Pasensya — indi ko gid maintindihan ang imo pamangkot. Pwede mo bala i-saysay liwat? Diri ako para buligan ka sa medConnect, appointments, rehistro, pamatyag, kag serbisyo sang City Health.</p>',
            ],
            'no_exact_faq' => [
                'en'  => '<p>I couldn\'t find an exact answer in my guide, but based on what you shared, here\'s what may help. If this is about your health or how you feel, I can help you connect with a provider — I cannot diagnose or prescribe.</p>',
                'fil' => '<p>Wala akong eksaktong sagot sa gabay, pero batay sa sinabi mo, maaaring makatulong ito. Kung may kinalaman sa kalusugan o nararamdaman, matutulungan kitang makipag-ugnayan sa provider — hindi ako makakapag-diagnose o magreseta.</p>',
                'hil' => '<p>Wala ako eksaktong sabat sa akon guide, pero base sa imo ginsiling, amo ni ang mahimo makabulig. Kon parte sa imo kahimsog ukon pamatyag, matabangan ko ikaw makakonekta sa provider — indi ako makadiagnose ukon makapreskribar.</p>',
            ],
            'follow_up' => [
                'en'  => '<p>Thanks for the extra detail — that helps. What would you like to do next?</p>',
                'fil' => '<p>Salamat sa dagdag na detalye — makakatulong iyon. Ano ang gusto mong gawin susunod?</p>',
                'hil' => '<p>Salamat sa dugang nga detalye — makabulig ni. Ano ang gusto mo himuon sunod?</p>',
            ],
            'encourage_care' => [
                'en'  => '<p>When you\'re ready, booking a consultation through medConnect is a safe next step. For emergencies, call <strong>911</strong>.</p>',
                'fil' => '<p>Kapag handa ka, mag-book ng konsultasyon sa medConnect. Para sa emergency, tumawag sa <strong>911</strong>.</p>',
                'hil' => '<p>Kon ready ka, mag-book sang konsultasyon sa medConnect. Para sa emergency, tawagi ang <strong>911</strong>.</p>',
            ],
            'empathy_soft' => [
                'en'  => '<p>I understand — that sounds like a tough situation. Let\'s look at what options you have, step by step.</p>',
                'fil' => '<p>Naiintindihan ko — mukhang mahirap iyon. Tingnan natin ang mga opsyon mo, hakbang-hakbang.</p>',
                'hil' => '<p>Naintindihan ko — daw budlay gid nga situation. Tan-awon ta ang imo mga opsyon, step by step.</p>',
            ],
            'empathy_warm' => [
                'en'  => '<p>Thanks for telling me. I\'m here with you — we can work through this together.</p>',
                'fil' => '<p>Salamat sa pagbabahagi. Nandito ako — malalampasan natin ito nang magkasama.</p>',
                'hil' => '<p>Salamat sa pagsugid sa akon. Diri ako — aton ni hinay-hinayon nga mag-ubanay.</p>',
            ],
            'empathy_ack' => [
                'en'  => '<p>Gets it — that must feel heavy right now. Tell me more if you want, or I can suggest next steps.</p>',
                'fil' => '<p>Gets ko — mabigat siguro ngayon. Sabihin mo pa kung gusto, o maaari akong mag-suggest ng susunod na hakbang.</p>',
                'hil' => '<p>Gets ko — daw mabug-at gid subong. Silinga pa kon gusto mo, ukon makasugid ako sang sunod nga hakbang.</p>',
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

    /**
     * Pick a varied empathy line to avoid robotic repetition.
     */
    public static function empathyHtml(string $lang, ?int $seed = null): string
    {
        $keys = ['empathy_soft', 'empathy_warm', 'empathy_ack'];
        $idx = $seed !== null ? ($seed % count($keys)) : random_int(0, count($keys) - 1);
        return self::html($keys[$idx], $lang);
    }
}
