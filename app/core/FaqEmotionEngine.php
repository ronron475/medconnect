<?php
/**
 * FAQ chatbot — server-side emotion recognition & empathetic response hints.
 * Pattern-based (EN · Filipino · Hiligaynon), aligned with client McFaqEmotions.
 */
final class FaqEmotionEngine
{
    public const VERSION = '1.2';

    private const PRIORITY = [
        'emergency', 'panic', 'hopeless', 'afraid', 'angry', 'frustrated', 'irritated', 'anxious',
        'nervous', 'worried', 'stressed', 'overwhelmed', 'pain', 'sick', 'tired', 'bored',
        'sad', 'crying', 'grief', 'lonely', 'disappointed', 'embarrassed', 'ashamed', 'guilty', 'jealous',
        'confused', 'uncertain', 'curious', 'excited', 'surprised', 'affectionate', 'hopeful', 'proud', 'calm',
        'relieved', 'thankful', 'happy', 'mixed',
    ];

    private const ICONS = [
        'happy' => '😊', 'thankful' => '🙏', 'relieved' => '😌', 'excited' => '🎉', 'surprised' => '😲',
        'affectionate' => '🥰', 'curious' => '🤔', 'confused' => '😕', 'uncertain' => '😶', 'mixed' => '😕',
        'frustrated' => '😤', 'irritated' => '😒', 'worried' => '😟',
        'anxious' => '😰', 'nervous' => '😬', 'sad' => '😢', 'lonely' => '🥺',
        'afraid' => '😨', 'angry' => '😠', 'disappointed' => '😞', 'stressed' => '😫',
        'tired' => '😴', 'bored' => '😑', 'hopeless' => '💔', 'panic' => '🆘', 'emergency' => '🚨',
        'crying' => '😭', 'pain' => '🤕', 'sick' => '🤒', 'overwhelmed' => '😥',
        'grief' => '🕯️', 'embarrassed' => '😳', 'ashamed' => '😔', 'guilty' => '😣', 'jealous' => '😒',
        'hopeful' => '🌱', 'proud' => '😌', 'calm' => '😌',
    ];

    private const LABELS = [
        'en' => [
            'happy' => 'Happy', 'thankful' => 'Thankful', 'relieved' => 'Relieved',
            'excited' => 'Excited', 'curious' => 'Curious', 'confused' => 'Confused',
            'frustrated' => 'Frustrated', 'worried' => 'Worried', 'anxious' => 'Anxious',
            'nervous' => 'Nervous', 'sad' => 'Sad', 'lonely' => 'Lonely', 'afraid' => 'Afraid',
            'angry' => 'Angry', 'disappointed' => 'Disappointed', 'stressed' => 'Stressed',
            'tired' => 'Tired', 'hopeless' => 'Hopeless', 'panic' => 'Urgent distress',
            'emergency' => 'Emergency', 'crying' => 'Upset', 'pain' => 'Pain', 'sick' => 'Unwell',
            'overwhelmed' => 'Overwhelmed',
            'grief' => 'Grieving', 'embarrassed' => 'Embarrassed', 'ashamed' => 'Ashamed',
            'guilty' => 'Guilty', 'jealous' => 'Jealous', 'bored' => 'Bored', 'irritated' => 'Irritated',
            'surprised' => 'Surprised', 'affectionate' => 'Warm', 'uncertain' => 'Uncertain', 'mixed' => 'Mixed feelings',
            'hopeful' => 'Hopeful', 'proud' => 'Proud', 'calm' => 'Calm',
        ],
        'fil' => [
            'happy' => 'Masaya', 'thankful' => 'Pasalamat', 'relieved' => 'Ginhawa',
            'excited' => 'Excited', 'curious' => 'Curious', 'confused' => 'Nalilito',
            'frustrated' => 'Frustrated', 'worried' => 'Nag-aalala', 'anxious' => 'Kinakabahan',
            'nervous' => 'Kinakabahan', 'sad' => 'Malungkot', 'lonely' => 'Nag-iisa',
            'afraid' => 'Natakot', 'angry' => 'Galit', 'disappointed' => 'Nadismaya',
            'stressed' => 'Stressed', 'tired' => 'Pagod', 'hopeless' => 'Walang pag-asa',
            'panic' => 'Kailangan ng tulong', 'emergency' => 'Emergency', 'crying' => 'Malungkot',
            'pain' => 'Sakit', 'sick' => 'May sakit', 'overwhelmed' => 'Overwhelmed',
            'grief' => 'Nagdadalamhati', 'embarrassed' => 'Nahihiya', 'ashamed' => 'Nahihiya',
            'guilty' => 'May guilt', 'jealous' => 'Naiinggit', 'bored' => 'Nabobored', 'irritated' => 'Naiinis',
            'surprised' => 'Nagulat', 'affectionate' => 'Malambing', 'uncertain' => 'Hindi sigurado', 'mixed' => 'Magkahalong damdamin',
            'hopeful' => 'May pag-asa', 'proud' => 'Proud', 'calm' => 'Kalmado',
        ],
        'hil' => [
            'happy' => 'Masadya', 'thankful' => 'Salamat', 'relieved' => 'Ginhawa',
            'excited' => 'Excited', 'curious' => 'Curious', 'confused' => 'Nalibog',
            'frustrated' => 'Frustrated', 'worried' => 'Nabalaka', 'anxious' => 'Ginakulbaan',
            'nervous' => 'Kinakabahan', 'sad' => 'Kasubo', 'lonely' => 'Isa lang',
            'afraid' => 'Nahadlok', 'angry' => 'Akig', 'disappointed' => 'Nadismaya',
            'stressed' => 'Stressed', 'tired' => 'Kapoy', 'hopeless' => 'Wala paglaum',
            'panic' => 'Kinahanglan bulig', 'emergency' => 'Emergency', 'crying' => 'Kasubo',
            'pain' => 'Sakit', 'sick' => 'May hilanat',             'overwhelmed' => 'Overwhelmed',
            'grief' => 'Nagakaluoy', 'embarrassed' => 'Nahuya', 'ashamed' => 'Nahuya',
            'guilty' => 'May guilt', 'jealous' => 'Naiinggit', 'bored' => 'Bored', 'irritated' => 'Nainis',
            'surprised' => 'Nagulat', 'affectionate' => 'Malambing', 'uncertain' => 'Indi sigurado', 'mixed' => 'Magkahalong pamatyag',
            'hopeful' => 'May paglaum', 'proud' => 'Proud', 'calm' => 'Malinong',
        ],
    ];

    /**
     * @return list<array{0: string, 1: string, 2: float}>
     */
    private static function boostRules(): array
    {
        return [
            ['frustrated', '/\b(frustrat|annoyed|irritat|kapoy\s+na\s+ko\s+sini|badtrip|nabudlayan|nabudlay)\b/ui', 2.0],
            ['irritated', '/\b(irritat|inis|nainis|lain\s+gid|lain\s+gid\s+ya|nakainis|so\s+annoying)\b/ui', 2.1],
            ['angry', '/\b(angry|galit|akig|akig\s+ko)\b/ui', 2.0],
            ['worried', '/\b(worri|concerned|nabalaka|kabalaka|alala|worried\s+about\s+symptom)\b/ui', 2.0],
            ['anxious', '/\b(anxious|anxiety|ginakulbaan|kulba|kulbaan\s+ko)\b/ui', 2.0],
            ['panic', '/\b(panic|ginapanik|buligi\s+ko|help\s+me\s+now)\b/ui', 2.5],
            ['sad', '/\b(sad|lungkot|kasubo|subo|malungkot|nalain|nasubo|budlay\s+(gid\s+)?pamatyagon)\b/ui', 2.0],
            ['sad', '/\b(pamatyag|nararamdaman).*(malain|kasubo|nasubo)\b/ui', 2.2],
            ['crying', '/\b(crying|umiiyak|naga\s*hilib|naga\s*hibi)\b/ui', 2.0],
            ['tired', '/\b(tired|pagod|kapoy|ginakapoy|kapoy\s+ko|wala\s+na\s+(ko\s+)?kusog|can\'?t\s+sleep|indi\s+ko\s+ka\s*tulog|indi\s+ko\s+katulog)\b/ui', 2.0],
            ['bored', '/\b(bored|boring|walang\s+gana|wala\s+gana|wala\s+ko\s+gana|indi\s+ko\s+gusto\s+maghimo)\b/ui', 2.0],
            ['stressed', '/\b(stress|stressed|na-stress|na\s+stress|grabeng\s+stress|stressed\s+gid|burnout|school\s+stress|work\s+stress|stress\s+sa\s+(eskwela|skwela|obra))\b/ui', 2.0],
            ['hopeless', '/\b(hopeless|walang\s+pag-asa|wala\s+paglaum|wala\s+na\s+solusyon|ayaw\s+ko\s+mabuhay|going\s+to\s+die|gonna\s+die|im\s+going\s+to\s+die|i\'?m\s+going\s+to\s+die|depress(ed|ion)?|wala\s+na\s+pulos|gusto\s+ko\s+mawala|mag.?untat|mabuhi\s+pa\s+ko)\b/ui', 3.0],
            ['hopeless', '/\b(di\s+ko\s+na\s+kaya|dili\s+ko\s+na\s+kaya|mas\s+maayo\s+pa\s+siguro\s+kung\s+wala\s+na\s+ko)\b/ui', 3.2],
            ['hopeful', '/\b(hopeful|may\s+pag.?asa|may\s+paglaum|sana\s+okay|tani\s+okay)\b/ui', 2.0],
            ['proud', '/\b(proud|nagmalampuson|naka.?achieve)\b/ui', 2.0],
            ['calm', '/\b(calm|relaxed|malinong|mapanatag|peaceful)\b/ui', 2.0],
            ['lonely', '/\b(lonely|nag-iisa|isa\s+lang|wala\s+(ako|ko)\s+(makakausap|maistoryahan|makigstorya)|need\s+someone\s+to\s+talk|homesick|nahidlaw|wala\s+gid\s+ko\s+may\s+kastorya|wala\s+ko\s+may\s+maistoryahan)\b/ui', 2.0],
            ['afraid', '/\b(afraid|scared|fearful|nahadlok|nahadlok\s+ko|natakot|takot|afraid\s+of\s+(the\s+)?doctor|nahadlok\s+.*doktor|fear\s+of\s+hospital)\b/ui', 2.2],
            ['grief', '/\b(grief|grieving|namatay|passed\s+away|relationship\s+problem|naglubong|namatay\s+ang)\b/ui', 2.2],
            ['disappointed', '/\b(disappoint|nadismaya|dismaya)\b/ui', 2.0],
            ['embarrassed', '/\b(embarrass|nahihiya|nahuya|hiya\s+ko|nahuya\s+ko)\b/ui', 2.0],
            ['ashamed', '/\b(ashamed|shame|nahihiya|nahuya|nakahuya)\b/ui', 2.0],
            ['guilty', '/\b(guilty|guilt|may\s+guilt|nagkasala|kasalanan\s+ko)\b/ui', 2.0],
            ['jealous', '/\b(jealous|naiinggit|inggit|selos)\b/ui', 2.0],
            ['nervous', '/\b(nervous|kinakabahan|kabado)\b/ui', 2.0],
            ['surprised', '/\b(surprised|shocked|nagulat|wow|grabe\s+gid)\b/ui', 2.0],
            ['affectionate', '/\b(love\s+you|miss\s+you|care\s+about|malambing|gihigugma)\b/ui', 2.0],
            ['uncertain', '/\b(uncertain|not\s+sure|indi\s+ko\s+kabalo|indi\s+ko\s+bal-an|wala\s+ko\s+kasabot|wala\s+ko\s+kaintindi|hindi\s+ko\s+alam)\b/ui', 2.2],
            ['confused', '/\b(confus|nalibog|nalilito|libog|indi\s+ko\s+masabtan|ano\s+ni)\b/ui', 2.0],
            ['happy', '/\b(happy|masaya|masadya|sayo|malipayon|malipayon\s+ko|lipay\s+ko)\b/ui', 2.0],
            ['relieved', '/\b(relieved|okay\s+lang\s+ko|okay\s+lang|ginhawa)\b/ui', 2.0],
            ['excited', '/\b(excited|sabik|can\'?t\s+wait|excited\s+gid)\b/ui', 2.0],
            ['mixed', '/\b(mixed\s+feelings|magkahalong|conflicted|pero\s+nahadlok|pero\s+kapoy|pero\s+sad|but\s+scared|but\s+afraid|pero\s+ginakulbaan|pero\s+nabalaka|pero\s+akig|okay\s+lang\s+pero|okay\s+pero)\b/ui', 2.3],
            ['lonely', '/\b(miss\s+(ko|kamo|kayo)|miss\s+my\s+(family|home|parents)|nahidlaw|wala\s+ko\s+sang\s+(kaistoryahan|upod))\b/ui', 2.1],
            ['sad', '/\b(naguol|naghuoy|nagkasubo|subo\s+gid|malain\s+gid|wala\s+laman|heartbroken|broken\s+heart|feeling\s+empty)\b/ui', 2.1],
            ['frustrated', '/\b(badtrip|bad\s+trip|indi\s+gid\s+mag\s*work|tama\s+na\s+ya|bwesit|yawa)\b/ui', 2.1],
            ['irritated', '/\b(astang\s+grabe|asta\s+grabe|nainis\s+gid|sobra\s+inis)\b/ui', 2.1],
            ['worried', '/\b(nagabalaka|ginakabalaka|basi\s+(may|delikado)|budlay\s+ginhawa|indi\s+ko\s+kaginhawa)\b/ui', 2.1],
            ['anxious', '/\b(kinabahan|daw\s+indi\s+ko\s+kaya|indi\s+ko\s+kaya\s+na|d\s+ko\s+mapanatag)\b/ui', 2.1],
            ['tired', '/\b(pagod\s+na\s+gid|kapoy\s+na\s+ko\s+sa\s+tanan|exhausted\s+gid|wla\s+ko\s+kusog)\b/ui', 2.1],
            ['stressed', '/\b(sobra\s+nga\s+stress|sobra\s+stress|grabeng\s+problema|stress\s+gid\s+ya)\b/ui', 2.1],
            ['hopeless', '/\b(walang\s+saysay|wala\s+sense|indi\s+ko\s+gusto\s+magpadayon|daw\s+wala\s+na\s+solusyon)\b/ui', 2.8],
            ['panic', '/\b(buligi\s+ko\s+daw|tabangi\s+ko\s+daw|kinahanglan\s+ko\s+sang\s+dulungan|ano\s+na\s+himuon|ano\s+himuon\s+ko\s+na)\b/ui', 2.4],
            ['confused', '/\b(naglibog|libog\s+ko|ano\s+ah|anu\s+ah|ano\s+ni\s+ya|ano\s+man\s+ini)\b/ui', 2.1],
            ['uncertain', '/\b(indi\s+ko\s+sure|di\s+ko\s+sure|wala\s+ko\s+gusto|indi\s+gid\s+ko\s+gusto)\b/ui', 2.1],
            ['happy', '/\b(lipay\s+gid|masadya\s+gid|nami\s+gid|sobra\s+lipay|medyo\s+okay|sige\s+lang)\b/ui', 2.1],
            ['thankful', '/\b(salamat|thank\s*you|thanks|maraming\s+salamat|salamat\s+gid)\b/ui', 2.5],
            ['thankful', '/\b(salamat\s+kaayo\s+gid|damo\s+gid\s+nga\s+salamat|salamat\s+sa\s+bulig\s+gid|amo\s+gid\s+salamat)\b/ui', 2.6],
            ['surprised', '/\b(grabe\s+gid\s+ya|grabe\s+kaayo|wow\s+gid|nagulat\s+gid)\b/ui', 2.1],
            ['affectionate', '/\b(miss\s+ko\s+kamo|gihigugma\s+ko|malambing\s+ko)\b/ui', 2.1],
            ['sick', '/\b(may\s+sipon|may\s+ubo|gasuka\s+ko|daw\s+may\s+hilanat)\b/ui', 2.4],
            ['pain', '/\b(gasakit\s+lawas|masakit\s+gid\s+ang|sakit\s+gid\s+ang)\b/ui', 2.5],
            ['overwhelmed', '/\b(daw\s+wala\s+na\s+ko\s+gana|wala\s+na\s+ko\s+gana\s+sa\s+tanan|sobra\s+nga\s+problema)\b/ui', 2.1],
            ['emergency', '/\b(heart\s+attack|stroke|can\'?t\s+breathe|hindi\s+makahinga|dili\s+makaginhawa|indi\s+makahinga|indi\s+makaginhawa|unconscious|nawalan\s+ng\s+malay|gapalanakit\s+dughan)\b/ui', 3.5],
        ];
    }

    public static function normalizeLang(string $lang): string
    {
        $l = strtolower(trim($lang));
        if (in_array($l, ['fil', 'tl', 'filipino', 'tagalog'], true)) {
            return 'fil';
        }
        if (in_array($l, ['hil', 'hiligaynon', 'ilonggo'], true)) {
            return 'hil';
        }
        return 'en';
    }

    public static function normalizeText(string $text): string
    {
        $t = FaqChatbotEmotionShorthand::expand($text);
        $t = mb_strtolower(trim($t), 'UTF-8');
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;
        return $t;
    }

    /**
     * @param array<string, float> $scores
     */
    private static function pickPrimary(array $scores): array
    {
        $best = null;
        $bestScore = 0.0;
        foreach (self::PRIORITY as $emotion) {
            $s = $scores[$emotion] ?? 0.0;
            if ($s > $bestScore) {
                $bestScore = $s;
                $best = $emotion;
            }
        }
        if ($best === null || $bestScore < 1.2) {
            return ['primary' => null, 'score' => 0.0];
        }
        return ['primary' => $best, 'score' => $bestScore];
    }

    public static function toneFor(?string $emotion): string
    {
        if (!$emotion) {
            return 'neutral';
        }
        if (in_array($emotion, ['panic', 'emergency', 'hopeless'], true)) {
            return 'crisis';
        }
        if (in_array($emotion, ['happy', 'thankful', 'relieved', 'excited', 'curious', 'hopeful', 'proud', 'calm'], true)) {
            return 'positive';
        }
        if (in_array($emotion, ['confused'], true)) {
            return 'neutral';
        }
        return 'negative';
    }

    public static function suggestedFlow(?string $emotion, ?string $intent = null): ?string
    {
        if ($emotion === 'emergency') {
            return 'emergency';
        }
        if ($emotion === 'panic' || $emotion === 'hopeless') {
            return 'crisis';
        }
        if (in_array($emotion, [
            'anxious', 'nervous', 'worried', 'stressed', 'overwhelmed', 'sad',
            'crying', 'lonely', 'afraid', 'frustrated', 'angry', 'disappointed',
            'grief', 'embarrassed', 'ashamed', 'guilty', 'jealous', 'irritated',
            'bored', 'uncertain', 'mixed',
        ], true)) {
            return 'distress_support';
        }
        if ($emotion === 'pain' || $emotion === 'sick') {
            return 'pain_sick';
        }
        if ($emotion === 'thankful') {
            return 'gratitude';
        }
        if ($emotion === 'happy' || $emotion === 'relieved' || $emotion === 'excited' || $emotion === 'surprised' || $emotion === 'affectionate' || $emotion === 'hopeful' || $emotion === 'proud' || $emotion === 'calm') {
            return in_array($emotion, ['surprised', 'affectionate'], true) ? 'happy' : $emotion;
        }
        if ($emotion === 'confused' || $emotion === 'curious') {
            return 'clarify';
        }
        return $intent ?: null;
    }

    public static function empathyMessage(string $lang, ?string $emotion, string $flowKey = '_default'): string
    {
        $L = self::normalizeLang($lang);
        $key = $emotion ?: 'curious';
        $messages = self::empathyTable();
        $pack = $messages[$L][$key] ?? $messages['en'][$key] ?? $messages['en']['curious'];
        return $pack[$flowKey] ?? $pack['_default'] ?? $messages['en']['curious']['_default'];
    }

    /**
     * @return array<string, array<string, array<string, string>>>
     */
    private static function empathyTable(): array
    {
        static $table = null;
        if ($table !== null) {
            return $table;
        }
        $table = [
            'en' => [
                'frustrated' => ['_default' => 'I\'m sorry you\'re experiencing that. Let\'s work through it together step by step.'],
                'worried' => ['_default' => 'I understand your concern. Your feelings are valid — I\'m here to help with medConnect services.'],
                'anxious' => ['_default' => 'I hear that this feels stressful. Take your time; I\'ll guide you calmly.'],
                'sad' => ['_default' => 'I\'m sorry you\'re feeling this way. Thank you for trusting me with how you feel.'],
                'lonely' => ['_default' => 'Thank you for sharing that. You don\'t have to figure everything out alone — I can help with healthcare access.'],
                'stressed' => ['_default' => 'It sounds like a lot right now. I\'ll keep things simple and supportive.'],
                'overwhelmed' => ['_default' => 'That sounds overwhelming. We can go one small step at a time.'],
                'angry' => ['_default' => 'I\'m sorry for the inconvenience. I\'ll do my best to help resolve your concern.'],
                'hopeless' => ['_default' => 'I\'m really sorry you\'re going through this. If you are in immediate danger, please contact emergency services (911) or Hopeline 1553.'],
                'panic' => ['_default' => 'I\'m here with you. Tell me what you need and I\'ll guide you step by step.'],
                'thankful' => ['_default' => 'You\'re very welcome. I\'m glad I could help.'],
                'happy' => ['_default' => 'I\'m glad to hear that. How can I assist you with medConnect today?'],
                'confused' => ['_default' => 'No problem — I\'ll explain things clearly, step by step.'],
                'curious' => ['_default' => 'Good question. I\'m here to guide you.'],
                'pain' => ['_default' => 'I\'m sorry you\'re not feeling well. I can help you connect with care through medConnect.'],
                'sick' => ['_default' => 'I\'m sorry you\'re not feeling well. I can help you schedule a consultation.'],
                'grief' => ['_default' => 'I\'m truly sorry for what you\'re going through. Grief takes time — you don\'t have to face it alone.'],
                'embarrassed' => ['_default' => 'It\'s okay to feel embarrassed. Many people feel that way about health concerns — I\'m here without judgment.'],
                'ashamed' => ['_default' => 'Thank you for sharing that. Your feelings are valid, and seeking help is a brave step.'],
                'guilty' => ['_default' => 'I hear you. Guilt is heavy — let\'s focus on what small step might help you feel better today.'],
                'jealous' => ['_default' => 'Those feelings can be tough. I\'m here to listen and help with healthcare access if you need it.'],
                'bored' => ['_default' => 'Low energy happens. When you\'re ready, I can help with something practical on medConnect.'],
                'irritated' => ['_default' => 'I\'m sorry things feel irritating right now. Let\'s tackle one thing at a time.'],
                'surprised' => ['_default' => 'I hear you! Let me help clarify things for you.'],
                'affectionate' => ['_default' => 'That\'s kind — I\'m glad you reached out. How can I help you today?'],
                'uncertain' => ['_default' => 'No worries — I\'ll walk you through things clearly so you can decide comfortably.'],
                'mixed' => ['_default' => 'Mixed feelings make sense. Take your time — I\'ll keep things simple and supportive.'],
            ],
            'fil' => [
                'frustrated' => ['_default' => 'Paumanhin sa abala. Lutasin natin ito nang hakbang-hakbang.'],
                'worried' => ['_default' => 'Naiintindihan ko ang iyong alalahanin. Valid ang nararamdaman mo — nandito ako para tumulong.'],
                'anxious' => ['_default' => 'Naririnig ko na nakakabahala ito. Huwag magmadali; gagabayan kita nang mahinahon.'],
                'sad' => ['_default' => 'Paumanhin na nararamdaman mo iyon. Salamat sa pagbabahagi sa akin.'],
                'lonely' => ['_default' => 'Salamat sa pagbabahagi. Hindi mo kailangang harapin ito mag-isa — matutulungan kitang ma-access ang serbisyong pangkalusugan.'],
                'stressed' => ['_default' => 'Mukhang mabigat ngayon. Panatilihin nating simple at suportado.'],
                'overwhelmed' => ['_default' => 'Mukhang overwhelming. Isa-isang hakbang lang muna.'],
                'angry' => ['_default' => 'Paumanhin sa abala. Gagawin ko ang makakaya para matulungan ka.'],
                'hopeless' => ['_default' => 'Paumanhin sa pinagdadaanan mo. Kung nasa panganib ka, tawagan ang 911 o Hopeline 1553.'],
                'panic' => ['_default' => 'Nandito ako. Sabihin mo ang kailangan mo at gagabayan kita.'],
                'thankful' => ['_default' => 'Walang anuman. Natutuwa akong nakatulong.'],
                'happy' => ['_default' => 'Natutuwa akong marinig iyon. Paano kita matutulungan sa medConnect?'],
                'confused' => ['_default' => 'Walang problema — ipapaliwanag ko nang malinaw, hakbang-hakbang.'],
                'curious' => ['_default' => 'Magandang tanong. Nandito ako para gabayan ka.'],
                'pain' => ['_default' => 'Paumanhin sa hindi magandang pakiramdam. Matutulungan kitang makipag-ugnayan sa pangangalaga sa medConnect.'],
                'sick' => ['_default' => 'Paumanhin sa hindi magandang pakiramdam. Matutulungan kitang mag-schedule ng konsultasyon.'],
                'grief' => ['_default' => 'Taos-pusong pakikiramay. Walang takdang oras ang lungkot — hindi mo kailangang harapin ito mag-isa.'],
                'embarrassed' => ['_default' => 'Okay lang mahiya — marami ang nakakaramdam ng ganoon. Nandito ako nang walang paghatol.'],
                'ashamed' => ['_default' => 'Salamat sa pagbabahagi. Valid ang nararamdaman mo — humingi ng tulong ay matapang.'],
                'guilty' => ['_default' => 'Naririnig ko iyon. Mabigat ang guilt — subukan natin ang maliit na hakbang ngayon.'],
                'uncertain' => ['_default' => 'Walang problema — gagabayan kita nang malinaw para makapagdesisyon ka nang komportable.'],
                'mixed' => ['_default' => 'Natural ang magkahalong damdamin. Huwag magmadali — simple at suportado lang tayo.'],
            ],
            'hil' => [
                'frustrated' => ['_default' => 'Pasensya sa abala. Sulbaron ta ini nga pahuway-pahuway.'],
                'worried' => ['_default' => 'Nakaintindi ako sang imo kabalaka. Valid ang imo nabatyagan — diri ako para buligan.'],
                'anxious' => ['_default' => 'Nabatian ko nga makabalaka ini. Dula lang — ginagiyahan ko ikaw sing malinong.'],
                'sad' => ['_default' => 'Pasensya nga amo sini ang imo nabatyagan. Salamat sa pagpaambit.'],
                'lonely' => ['_default' => 'Salamat sa pagpaambit. Indi mo kinahanglan mag-isahan — matabangan ko ikaw sa healthcare access.'],
                'stressed' => ['_default' => 'Mabug-at siguro subong. Simplehon ta lang kag suportahan.'],
                'overwhelmed' => ['_default' => 'Mabug-at gid. Isa ka hakbang sa isa lang anay.'],
                'angry' => ['_default' => 'Pasensya sa abala. Himuon ko ang akon maayo para matabangan ka.'],
                'hopeless' => ['_default' => 'Pasensya sa imo gina-agi. Kon sa katalagman ka, tawagi ang 911 ukon Hopeline 1553.'],
                'panic' => ['_default' => 'Diri ako. Silinga kon ano ang imo kinahanglan kag ginagiyahan ko ikaw.'],
                'thankful' => ['_default' => 'Wala sapayan. Natuon ako nga nakatbulig.'],
                'happy' => ['_default' => 'Maayo nga mabatian. Paano ko ikaw matabangan sa medConnect?'],
                'confused' => ['_default' => 'Wala problema — ipahayag ko sing malinaw, pahuway-pahuway.'],
                'curious' => ['_default' => 'Maayo nga pamangkot. Diri ako para giyahan ka.'],
                'pain' => ['_default' => 'Pasensya nga indi ka maayo. Matabangan ko ikaw makakonekta sa care paagi sa medConnect.'],
                'sick' => ['_default' => 'Pasensya nga indi ka maayo. Matabangan ko ikaw mag-schedule sang konsultasyon.'],
                'grief' => ['_default' => 'Nagakaluoy gid ako. Wala takdo nga oras ang kaguol — indi mo kinahanglan mag-isahan.'],
                'embarrassed' => ['_default' => 'Okay lang mahuya — damo ang amo sini. Diri ako nga wala hatol.'],
                'ashamed' => ['_default' => 'Salamat sa pagpaambit. Valid ang imo nabatyagan — mangayo bulig matapang gid.'],
                'guilty' => ['_default' => 'Nabatian ko. Mabug-at ang guilt — tilawi lang sang gamay nga hakbang subong.'],
                'bored' => ['_default' => 'Normal ang wala gana. Kon ready ka, matabangan ko sa praktikal nga butang sa medConnect.'],
                'irritated' => ['_default' => 'Pasensya nga makalain subong. Isa ka butang lang anay.'],
                'uncertain' => ['_default' => 'Wala problema — ginagiyahan ko ikaw sing malinaw agod makadesisyon ka sing komportable.'],
                'mixed' => ['_default' => 'Natural ang magkahalong pamatyag. Dula lang — simple kag suportado lang ta.'],
                'surprised' => ['_default' => 'Nabatian ko! Buligan ko ikaw ipaliwanag ini.'],
                'affectionate' => ['_default' => 'Maayo nga nag-abot ka. Paano ko ikaw matabangan subong?'],
            ],
        ];
        return $table;
    }

    public static function buildEmpathyHtml(string $lang, ?string $emotion, string $message): string
    {
        $tone = self::toneFor($emotion);
        $icon = self::ICONS[$emotion ?? ''] ?? '💬';
        $safe = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $iconEsc = htmlspecialchars($icon, ENT_QUOTES, 'UTF-8');
        return '<div class="fcb-empathy fcb-empathy--' . $tone . '" role="note">'
            . '<span class="fcb-empathy__icon" aria-hidden="true">' . $iconEsc . '</span>'
            . '<p class="fcb-empathy__text">' . $safe . '</p></div>';
    }

    /**
     * @param array{emotion?: string|null, tone?: string, at?: int, topic?: ?string, intent?: ?string}|null $previousContext
     */
    public static function analyze(
        string $text,
        string $lang = 'en',
        ?string $intent = null,
        ?array $previousContext = null,
        ?string $nlpGloss = null
    ): array {
        $raw = trim($text);
        if ($raw === '') {
            return self::emptyResult();
        }

        $norm = self::normalizeText($raw);
        $matchNorm = self::normalizeText($nlpGloss !== null && $nlpGloss !== '' ? $nlpGloss : $raw);
        $scores = [];

        self::scoreEmojis($raw, $scores);
        self::scoreExpressiveTokens($norm, $scores);

        foreach (self::boostRules() as [$emotion, $pattern, $weight]) {
            if (preg_match($pattern, $matchNorm) || preg_match($pattern, $norm)) {
                $scores[$emotion] = ($scores[$emotion] ?? 0) + $weight;
            }
        }

        if (preg_match('/\b(how|paano|ano|what|why|safe|sigurado|trust)\b/ui', $matchNorm)) {
            $scores['curious'] = ($scores['curious'] ?? 0) + 1.5;
            unset($scores['panic'], $scores['emergency']);
        }

        self::applyConversationContext($norm, $matchNorm, $scores, $previousContext);

        $picked = self::pickPrimary($scores);
        $primary = $picked['primary'];
        $score = $picked['score'];

        $tone = self::toneFor($primary);
        $flow = self::suggestedFlow($primary, $intent);
        $L = self::normalizeLang($lang);
        $empathy = self::empathyMessage($L, $primary, '_default');
        $label = self::LABELS[$L][$primary ?? ''] ?? self::LABELS['en'][$primary ?? ''] ?? '';
        $confidence = min(0.98, max(0.0, $score / 4.0));

        return [
            'emotion'          => $primary,
            'label'            => $label,
            'icon'             => self::ICONS[$primary ?? ''] ?? '💬',
            'tone'             => $tone,
            'score'            => round($score, 2),
            'confidence'       => round($confidence, 2),
            'suggested_flow'   => $flow,
            'empathy_message'  => $empathy,
            'empathy_html'     => $primary ? self::buildEmpathyHtml($L, $primary, $empathy) : '',
            'scores'           => $scores,
            'engine'           => 'php-faq-emotion',
            'engine_version'   => self::VERSION,
        ];
    }

    /** @param array<string, float> $scores */
    private static function scoreEmojis(string $text, array &$scores): void
    {
        if (preg_match('/😭|🥺/', $text)) {
            $scores['crying'] = ($scores['crying'] ?? 0) + 2.4;
            $scores['sad'] = ($scores['sad'] ?? 0) + 2.0;
        }
        if (preg_match('/😔|😞/', $text)) {
            $scores['sad'] = ($scores['sad'] ?? 0) + 2.0;
        }
        if (preg_match('/😊|😄|🥰|❤️|💕/', $text)) {
            $scores['happy'] = ($scores['happy'] ?? 0) + 2.0;
        }
        if (preg_match('/😰|😨|😱/', $text)) {
            $scores['anxious'] = ($scores['anxious'] ?? 0) + 2.0;
            $scores['afraid'] = ($scores['afraid'] ?? 0) + 1.8;
        }
        if (preg_match('/😠|😡/', $text)) {
            $scores['angry'] = ($scores['angry'] ?? 0) + 2.0;
        }
        if (preg_match('/😫|😩/', $text)) {
            $scores['stressed'] = ($scores['stressed'] ?? 0) + 1.8;
            $scores['tired'] = ($scores['tired'] ?? 0) + 1.5;
        }
        if (preg_match('/💀|🖤/', $text)) {
            $scores['hopeless'] = ($scores['hopeless'] ?? 0) + 1.8;
            $scores['sad'] = ($scores['sad'] ?? 0) + 1.5;
        }
        if (preg_match('/🤗|💕|❤/', $text)) {
            $scores['affectionate'] = ($scores['affectionate'] ?? 0) + 2.0;
        }
        if (preg_match('/🙄|😒/', $text)) {
            $scores['irritated'] = ($scores['irritated'] ?? 0) + 2.0;
            $scores['frustrated'] = ($scores['frustrated'] ?? 0) + 1.5;
        }
        if (preg_match('/😑|😐/', $text)) {
            $scores['bored'] = ($scores['bored'] ?? 0) + 1.8;
        }
        if (preg_match('/🤯|😲/', $text)) {
            $scores['surprised'] = ($scores['surprised'] ?? 0) + 2.0;
        }
        if (preg_match('/😳/', $text)) {
            $scores['embarrassed'] = ($scores['embarrassed'] ?? 0) + 2.0;
        }
    }

    /** @param array<string, float> $scores */
    private static function scoreExpressiveTokens(string $norm, array &$scores): void
    {
        if (preg_match('/^(hay|haay|sigh)\b/ui', $norm)) {
            $scores['sad'] = ($scores['sad'] ?? 0) + 1.5;
            $scores['worried'] = ($scores['worried'] ?? 0) + 1.2;
        }
        if (preg_match('/^(grabe|grabeh)\b/ui', $norm) && mb_strlen($norm) <= 16) {
            $scores['surprised'] = ($scores['surprised'] ?? 0) + 1.4;
            $scores['stressed'] = ($scores['stressed'] ?? 0) + 1.2;
        }
        if (preg_match('/hahaha|hehehe|lol\b/ui', $norm) && !preg_match('/\b(sad|cry|subo|kasubo)\b/ui', $norm)) {
            $scores['happy'] = ($scores['happy'] ?? 0) + 1.2;
        }
    }

    /**
     * @param array<string, float> $scores
     * @param array{emotion?: string|null, tone?: string, at?: int, topic?: ?string, intent?: ?string}|null $previousContext
     */
    private static function applyConversationContext(
        string $norm,
        string $matchNorm,
        array &$scores,
        ?array $previousContext
    ): void {
        if (!$previousContext || empty($previousContext['emotion'])) {
            return;
        }

        $prev = (string) $previousContext['emotion'];
        $short = mb_strlen($norm) <= 28;

        if (preg_match('/^(sige|sige\s+lang|oo\s+man|amo\s+man|hoo\s+man|oo\s+po|opo|pwede|pwede\s+man|sige\s+po)\b/ui', $norm)) {
            $scores[$prev] = ($scores[$prev] ?? 0) + 2.0;
        }

        if ($short && preg_match('/^(hindi|indi|wala|dili|no\s+man|indi\s+man|indi\s+gid)\b/ui', $norm)) {
            $scores['uncertain'] = ($scores['uncertain'] ?? 0) + 1.4;
            if (!empty($previousContext['topic'])) {
                $scores[$prev] = ($scores[$prev] ?? 0) + 1.0;
            }
        }

        if ($short && preg_match('/^(indi\s+ko\s+gusto|hindi\s+ko\s+gusto|wala\s+ko\s+kasabot|indi\s+ko\s+kabalo)\b/ui', $norm)) {
            $scores['uncertain'] = ($scores['uncertain'] ?? 0) + 1.8;
            $scores['confused'] = ($scores['confused'] ?? 0) + 1.6;
            if (in_array($prev, ['worried', 'afraid', 'anxious', 'sad'], true)) {
                $scores[$prev] = ($scores[$prev] ?? 0) + 1.2;
            }
        }

        if (preg_match('/\b(pero|but)\s+(nahadlok|scared|afraid|kapoy|tired|sad|kasubo|nabalaka|worried|akig|angry|ginakulbaan|anxious)\b/ui', $matchNorm . ' ' . $norm)) {
            $scores['mixed'] = ($scores['mixed'] ?? 0) + 2.0;
        }

        $neg = ['sad', 'anxious', 'worried', 'stressed', 'lonely', 'hopeless', 'grief', 'afraid'];
        $currentBest = 0.0;
        foreach ($scores as $s) {
            if ($s > $currentBest) {
                $currentBest = $s;
            }
        }
        if ($currentBest > 0 && in_array($prev, $neg, true)) {
            $prevScore = $scores[$prev] ?? 0;
            if ($prevScore > 0 && $prevScore >= $currentBest - 0.5) {
                $scores[$prev] = $prevScore + 0.4;
            }
        }

        if (!empty($previousContext['topic']) && $short) {
            $topic = str_replace('_', ' ', (string) $previousContext['topic']);
            if (str_contains($topic, 'emotional') || str_contains($topic, 'mental') || str_contains($topic, 'crisis')) {
                $scores[$prev] = ($scores[$prev] ?? 0) + 0.8;
            }
        }
    }

    /** @return array<string, mixed> */
    private static function emptyResult(): array
    {
        return [
            'emotion' => null,
            'label' => '',
            'icon' => '💬',
            'tone' => 'neutral',
            'score' => 0,
            'confidence' => 0,
            'suggested_flow' => null,
            'empathy_message' => '',
            'empathy_html' => '',
            'scores' => [],
            'engine' => 'php-faq-emotion',
            'engine_version' => self::VERSION,
        ];
    }
}
