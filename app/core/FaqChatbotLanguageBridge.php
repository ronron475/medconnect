<?php
/**
 * FAQ chatbot — Hiligaynon/Filipino → English gloss for emotion, intent, and FAQ matching.
 * Replies stay in the user's language; NLP uses the English gloss.
 */
final class FaqChatbotLanguageBridge
{
    /** @var list<array{0: string, 1: string}> hil phrase => english (longest match first) */
    private const HIL_PHRASES = [
        ['buot ko nga magpakamatay', 'i want to commit suicide'],
        ['gusto ko na lang mamatay', 'i want to die'],
        ['indi ko na gusto mabuhi', 'i do not want to live anymore'],
        ['wala na ako paglaum sa kinabuhi', 'hopeless no reason to live'],
        ['patyon ko ang kaugalingon', 'kill myself'],
        ['buot ko mamatay', 'i want to die'],
        ['gusto ko mamatay', 'i want to die'],
        ['indi ko gusto mabuhi', 'i do not want to live'],
        ['wala ko gusto mabuhi', 'i do not want to live'],
        ['mamatay na lang ako', 'going to die'],
        ['mamatay na ako', 'going to die'],
        ['stress sa eskwela', 'school stress academic stress'],
        ['stress sa skwela', 'school stress academic stress'],
        ['stress sa obra', 'work stress job stress'],
        ['nahidlaw ako sa balay', 'homesick missing home'],
        ['nahidlaw ko sa balay', 'homesick missing home'],
        ['problema sa relasyon', 'relationship problems'],
        ['kapoy na gid ako', 'burnout exhausted tired'],
        ['wala na gana sa tanan', 'depression low motivation'],
        ['naga hibi ako subong', 'crying sad'],
        ['buntis ako', 'pregnant pregnancy prenatal'],
        ['kinahanglan ko bakuna', 'need vaccination vaccine'],
        ['paano ang ai triage', 'what is ai triage'],
        ['diin ang medical records', 'where are medical records'],
        ['wala ako nabaton nga otp', 'otp not received verification code'],
        ['i-update ang profile', 'update profile'],
        ['wala ko kwarta magpa-check up', 'no money cannot afford checkup financial'],
        ['wala ako kwarta magpa check up', 'no money cannot afford checkup financial'],
        ['wala ko kwarta magpa check up', 'no money cannot afford checkup financial'],
        ['wala kwarta magpa-check up', 'no money cannot afford checkup financial'],
        ['wala ko kwarta', 'no money financial problem'],
        ['wala ako kwarta', 'no money financial problem'],
        ['nahadlok ko sa doktor', 'afraid of seeing a doctor scared of doctor'],
        ['nahadlok ako sa doktor', 'afraid of seeing a doctor scared of doctor'],
        ['daw indi ko ka tulog', "can't sleep cannot sleep insomnia"],
        ['indi ko ka tulog', "can't sleep cannot sleep"],
        ['indi ko katulog', "can't sleep cannot sleep"],
        ['budlay gid pamatyagon ko', 'i feel very unwell sick worried about symptoms'],
        ['budlay pamatyagon ko', 'i feel unwell sick'],
        ['budlay gid pamatyagon', 'feeling unwell sick'],
        ['ginasakit ulo ko', 'my head hurts headache symptoms'],
        ['ginasakit ang ulo ko', 'my head hurts headache'],
        ['gasakit ulo ko', 'headache symptoms'],
        ['gapalanakit dughan ko', 'chest pain emergency'],
        ['gapalanakit ang dughan ko', 'chest pain emergency'],
        ['kinahanglan ko may maistoryahan', 'need someone to talk to lonely'],
        ['wala ko maistoryahan', 'lonely need someone to talk'],
        ['problema sa pamilya', 'family problems'],
        ['problema sa familia', 'family problems'],
        ['nabalaka gid ako subong', 'i am very worried today'],
        ['ginakulbaan gid ako', 'i am very anxious'],
        ['nahadlok gid ako subong', 'i am very scared today'],
        ['kasubo gid ako subong', 'i feel very sad today'],
        ['kapoy gid ako subong', 'i am so tired today'],
        ['stressed gid ako', 'i am so stressed'],
        ['grabeng stress ko', 'very stressed'],
        ['wala na ako gana', 'overwhelmed tired'],
        ['naga hibi ako', 'crying sad'],
        ['naga hilib ako', 'crying sad'],
        ['nabalaka gid ako', 'i am very worried'],
        ['ginakulbaan ako', 'i am anxious'],
        ['nahadlok gid ako', 'i am very scared'],
        ['kasubo ako subong', 'i feel sad today'],
        ['kapoy gid ako', 'i am so tired'],
        ['nalibog gid ako', 'i am very confused'],
        ['frustrated gid ako', 'i am very frustrated'],
        ['isa lang ako', 'lonely'],
        ['wala ako makigstorya', 'lonely'],
        ['indi ko maintindihan', 'i do not understand'],
        ['indi ko ma intiendihan', 'i do not understand'],
        ['libog gid ako', 'confused'],
        ['paano mag register sa medconnect', 'how to register medconnect'],
        ['paano magrehistro sa medconnect', 'how to register medconnect'],
        ['paano mag register', 'how to register'],
        ['paano magrehistro', 'how to register'],
        ['paano mag sign in', 'how to sign in'],
        ['paano mag login', 'how to login'],
        ['paano mag log in', 'how to login'],
        ['paano mag book sang appointment', 'how to book appointment'],
        ['paano mag book', 'how to book appointment'],
        ['paano mag schedule', 'how to schedule appointment'],
        ['paano mag konsulta online', 'how to online consultation'],
        ['paano mag konsulta', 'how to consultation'],
        ['paano mag video call', 'how to video consultation'],
        ['paano mag reset sang password', 'how to reset password'],
        ['paano i reset ang password', 'how to reset password'],
        ['gusto ko mag konsulta', 'i want consultation'],
        ['gusto ko mag book', 'i want book appointment'],
        ['kinahanglan ko mag konsulta', 'i need consultation'],
        ['kinahanglan ko appointment', 'i need appointment'],
        ['nakalimtan ko ang password', 'i forgot my password'],
        ['nakalimtan ko password', 'i forgot my password'],
        ['nakalimtan ko ang akon password', 'i forgot my password'],
        ['forgot ko password', 'i forgot my password'],
        ['mag book sang appointment', 'book appointment'],
        ['mag schedule sang appointment', 'schedule appointment'],
        ['status sang appointment', 'appointment status'],
        ['video konsultasyon', 'video consultation'],
        ['online konsultasyon', 'online consultation'],
        ['medical record', 'medical records'],
        ['medical history', 'medical records'],
        ['health summary', 'health summary records'],
        ['digital prescription', 'prescription'],
        ['reseta ko', 'my prescription'],
        ['notification ko', 'notifications'],
        ['office hours', 'office hours'],
        ['oras sang opisina', 'office hours'],
        ['contact support', 'contact support'],
        ['tawag sa support', 'contact support'],
        ['city health office', 'city health office services'],
        ['nabalaka ako', 'i am worried'],
        ['nahadlok ako', 'i am scared'],
        ['kasubo ako', 'i am sad'],
        ['kapoy ako', 'i am tired'],
        ['akig ako', 'i am angry'],
        ['nalibog ako', 'i am confused'],
        ['masadya ako', 'i am happy'],
        ['salamat gid', 'thank you very much'],
        ['damo nga salamat', 'thank you very much'],
        ['salamat guid', 'thank you'],
        ['buligi ko palihog', 'help me please'],
        ['kinahanglan ko bulig', 'i need help'],
        ['buligi ko', 'help me'],
        ['tabangi ko', 'help me'],
        ['masakit ang lawas ko', 'body pain sick'],
        ['masakit ang lawas', 'body pain sick'],
        ['may sakit ako', 'i am sick'],
        ['may hilanat ako', 'i have fever sick'],
        ['may lagnat ako', 'i have fever sick'],
        ['sakit ulo ko', 'headache'],
        ['sakit ulo', 'headache'],
        ['sakit tiyan', 'stomach pain'],
        ['sakit ang dughan', 'chest pain'],
        ['sakit dughan', 'chest pain'],
        ['indi makahinga', 'cannot breathe difficulty breathing'],
        ['indi makaginhawa', 'cannot breathe difficulty breathing'],
        ['grabeng pagdugo', 'severe bleeding'],
        ['wala siya malay', 'unconscious'],
        ['nawad an malay', 'unconscious'],
        ['wala paglaum', 'hopeless'],
        ['wala na paglaum', 'hopeless'],
        ['kumusta', 'hello greeting'],
        ['musta', 'hello greeting'],
        ['maayong aga', 'good morning greeting'],
        ['maayong hapon', 'good afternoon greeting'],
    ];

    /** @var array<string, string> */
    private const HIL_TOKENS = [
        'nabalaka' => 'worried',
        'kabalaka' => 'worried',
        'ginakulbaan' => 'anxious',
        'kulba' => 'anxious',
        'nahadlok' => 'scared afraid',
        'takot' => 'afraid',
        'kasubo' => 'sad',
        'subo' => 'sad',
        'kapoy' => 'tired',
        'pagod' => 'tired',
        'akig' => 'angry',
        'badtrip' => 'frustrated',
        'nalibog' => 'confused',
        'libog' => 'confused',
        'masadya' => 'happy',
        'salamat' => 'thankful',
        'bulig' => 'help',
        'buligi' => 'help me',
        'tabangi' => 'help me',
        'rehistro' => 'register',
        'magrehistro' => 'register',
        'konsultasyon' => 'consultation',
        'konsulta' => 'consultation',
        'telemedicine' => 'telemedicine',
        'appointment' => 'appointment',
        'schedule' => 'schedule',
        'password' => 'password',
        'nakalimtan' => 'forgot',
        'nakalimot' => 'forgot',
        'reseta' => 'prescription',
        'bulong' => 'medicine prescription',
        'record' => 'medical records',
        'hilanat' => 'fever sick',
        'lagnat' => 'fever sick',
        'masakit' => 'pain sick',
        'sakit' => 'pain',
        'dughan' => 'chest',
        'tiyan' => 'stomach',
        'ulo' => 'headache',
        'ubo' => 'cough sick',
        'sipon' => 'cold sick',
        'lawas' => 'body',
        'doktor' => 'doctor',
        'pasyente' => 'patient',
        'opisina' => 'office',
        'oras' => 'hours schedule',
        'tawag' => 'call contact',
        'subong' => 'today',
        'pwede' => 'can',
        'puwede' => 'can',
        'gusto' => 'want',
        'kinahanglan' => 'need',
        'gid' => '',
        'guid' => '',
        'sang' => '',
        'kag' => '',
        'ko' => 'i',
        'ako' => 'i',
        'imo' => 'you',
        'indi' => 'not',
        'wala' => 'none',
        'paano' => 'how',
        'diin' => 'where',
        'ano' => 'what',
        'kon' => 'if',
        'nga' => '',
        'palihog' => 'please',
        'medconnect' => 'medconnect',
    ];

    /**
     * @return array{
     *   reply_lang: string,
     *   nlp_text: string,
     *   english_gloss: string,
     *   input_lang: string,
     *   is_hiligaynon: bool
     * }
     */
    public static function prepare(string $text, string $lang = 'en'): array
    {
        $lang = FaqEmotionEngine::normalizeLang($lang);
        $original = trim($text);
        $isHil = $lang === 'hil' || self::looksHiligaynon($original);

        if (!$isHil && $lang !== 'fil') {
            return [
                'reply_lang'     => $lang,
                'nlp_text'       => $original,
                'english_gloss'  => '',
                'input_lang'     => $lang,
                'is_hiligaynon'  => false,
            ];
        }

        $gloss = $isHil ? self::hilToEnglish($original) : self::filToEnglish($original);
        $nlp = $gloss !== '' ? $gloss : $original;

        return [
            'reply_lang'     => $isHil ? 'hil' : ($lang === 'fil' ? 'fil' : 'hil'),
            'nlp_text'       => $nlp,
            'english_gloss'  => $gloss,
            'input_lang'     => $isHil ? 'hil' : 'fil',
            'is_hiligaynon'  => $isHil,
        ];
    }

    public static function looksHiligaynon(string $text): bool
    {
        $t = FaqEmotionEngine::normalizeText($text);
        if ($t === '') {
            return false;
        }
        $markers = ['gid', 'guid', 'sang', 'kag', 'indi', 'nabalaka', 'nahadlok', 'kasubo', 'kapoy', 'nalibog', 'buligi', 'tabangi', 'diin', 'amo', 'subong', 'kon', 'nga', 'halin', 'maayong', 'kumusta', 'musta', 'palihog', 'lawas', 'dughan', 'hilanat', 'nakalimtan', 'rehistro', 'konsultasyon'];
        $hits = 0;
        foreach ($markers as $m) {
            if (preg_match('/\b' . preg_quote($m, '/') . '\b/u', $t)) {
                $hits++;
            }
        }
        return $hits >= 2 || ($hits >= 1 && preg_match('/\b(paano|ano)\b/u', $t));
    }

    public static function hilToEnglish(string $text): string
    {
        $work = FaqEmotionEngine::normalizeText($text);
        if ($work === '') {
            return '';
        }

        foreach (self::HIL_PHRASES as [$hil, $en]) {
            if (str_contains($work, $hil)) {
                $work = str_replace($hil, $en, $work);
            }
        }

        $parts = preg_split('/\s+/u', $work) ?: [];
        $out = [];
        foreach ($parts as $tok) {
            if ($tok === '') {
                continue;
            }
            $out[] = self::HIL_TOKENS[$tok] ?? $tok;
        }
        $joined = trim(implode(' ', array_filter($out, static fn ($w) => $w !== '')));
        $joined = preg_replace('/\s+/u', ' ', $joined) ?? $joined;

        return $joined;
    }

    public static function filToEnglish(string $text): string
    {
        $map = [
            'paano mag register' => 'how to register',
            'paano magrehistro' => 'how to register',
            'nakalimutan ko' => 'i forgot',
            'gusto ko' => 'i want',
            'malungkot ako' => 'i am sad',
            'nag aalala ako' => 'i am worried',
            'takot ako' => 'i am scared',
            'salamat po' => 'thank you',
        ];
        $work = FaqEmotionEngine::normalizeText($text);
        foreach ($map as $fil => $en) {
            if (str_contains($work, $fil)) {
                $work = str_replace($fil, $en, $work);
            }
        }
        return $work;
    }

    /**
     * Bilingual empathy lead (Hiligaynon + English) for emotional replies.
     */
    public static function bilingualEmpathyLead(string $canonical, string $englishLine): string
    {
        $hil = match ($canonical) {
            'worried' => 'Nakaintindi ko sang imo kabalaka.',
            'sad' => 'Pasensya nga amo sini ang imo nabatyagan.',
            'fearful' => 'Natural lang mahadlok — diri ako para suportahan ikaw.',
            'angry', 'frustrated' => 'Pasensya sa abala — tabangan ta ka.',
            'confused' => 'Wala problema — ipahayag ko sing malinaw.',
            'happy' => 'Maayo nga mabatian!',
            default => 'Diri ako para buligan ka.',
        };
        $en = htmlspecialchars($englishLine, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $h = htmlspecialchars($hil, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<p class="fcb-bilingual-lead"><span lang="hil">' . $h . '</span> '
            . '<span lang="en"><em>' . $en . '</em></span></p>';
    }
}
