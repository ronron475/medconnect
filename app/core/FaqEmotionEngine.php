<?php
/**
 * FAQ chatbot — server-side emotion recognition & empathetic response hints.
 * Pattern-based (EN · Filipino · Hiligaynon), aligned with client McFaqEmotions.
 */
final class FaqEmotionEngine
{
    public const VERSION = '1.0';

    private const PRIORITY = [
        'emergency', 'panic', 'hopeless', 'afraid', 'angry', 'frustrated', 'anxious',
        'nervous', 'worried', 'stressed', 'overwhelmed', 'pain', 'sick', 'tired',
        'sad', 'crying', 'lonely', 'disappointed',
        'confused', 'curious', 'excited', 'relieved', 'thankful', 'happy',
    ];

    private const ICONS = [
        'happy' => '😊', 'thankful' => '🙏', 'relieved' => '😌', 'excited' => '🎉',
        'curious' => '🤔', 'confused' => '😕', 'frustrated' => '😤', 'worried' => '😟',
        'anxious' => '😰', 'nervous' => '😬', 'sad' => '😢', 'lonely' => '🥺',
        'afraid' => '😨', 'angry' => '😠', 'disappointed' => '😞', 'stressed' => '😫',
        'tired' => '😴', 'hopeless' => '💔', 'panic' => '🆘', 'emergency' => '🚨',
        'crying' => '😭', 'pain' => '🤕', 'sick' => '🤒', 'overwhelmed' => '😥',
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
        ],
        'hil' => [
            'happy' => 'Masadya', 'thankful' => 'Salamat', 'relieved' => 'Ginhawa',
            'excited' => 'Excited', 'curious' => 'Curious', 'confused' => 'Nalibog',
            'frustrated' => 'Frustrated', 'worried' => 'Nabalaka', 'anxious' => 'Ginakulbaan',
            'nervous' => 'Kinakabahan', 'sad' => 'Kasubo', 'lonely' => 'Isa lang',
            'afraid' => 'Nahadlok', 'angry' => 'Akig', 'disappointed' => 'Nadismaya',
            'stressed' => 'Stressed', 'tired' => 'Kapoy', 'hopeless' => 'Wala paglaum',
            'panic' => 'Kinahanglan bulig', 'emergency' => 'Emergency', 'crying' => 'Kasubo',
            'pain' => 'Sakit', 'sick' => 'May hilanat', 'overwhelmed' => 'Overwhelmed',
        ],
    ];

    /**
     * @return list<array{0: string, 1: string, 2: float}>
     */
    private static function boostRules(): array
    {
        return [
            ['frustrated', '/\b(frustrat|annoyed|irritat|kapoy\s+na\s+ko\s+sini|badtrip)\b/ui', 2.0],
            ['angry', '/\b(angry|galit|akig)\b/ui', 2.0],
            ['worried', '/\b(worri|concerned|nabalaka|kabalaka|alala|worried\s+about\s+symptom)\b/ui', 2.0],
            ['anxious', '/\b(anxious|anxiety|ginakulbaan|kulba)\b/ui', 2.0],
            ['panic', '/\b(panic|ginapanik|buligi\s+ko|help\s+me\s+now)\b/ui', 2.5],
            ['sad', '/\b(sad|lungkot|kasubo|subo|malungkot|nalain|budlay\s+(gid\s+)?pamatyagon)\b/ui', 2.0],
            ['sad', '/\b(pamatyag|nararamdaman).*(malain|kasubo)\b/ui', 2.2],
            ['tired', '/\b(tired|pagod|kapoy|wala\s+na\s+(ko\s+)?kusog|can\'?t\s+sleep|indi\s+ko\s+ka\s*tulog|indi\s+ko\s+katulog)\b/ui', 2.0],
            ['stressed', '/\b(stress|stressed|grabeng\s+stress|stressed\s+gid|burnout|school\s+stress|work\s+stress|stress\s+sa\s+(eskwela|skwela|obra))\b/ui', 2.0],
            ['lonely', '/\b(lonely|nag-iisa|isa\s+lang|wala\s+(ako|ko)\s+(makakausap|maistoryahan|makigstorya)|need\s+someone\s+to\s+talk|homesick|nahidlaw)\b/ui', 2.0],
            ['thankful', '/\b(salamat|thank\s*you|thanks|maraming\s+salamat|damo\s+nga\s+salamat)\b/ui', 2.5],
            ['hopeless', '/\b(hopeless|walang\s+pag-asa|wala\s+paglaum|wala\s+na\s+solusyon|ayaw\s+ko\s+mabuhay|going\s+to\s+die|gonna\s+die|im\s+going\s+to\s+die|i\'?m\s+going\s+to\s+die|depress(ed|ion)?)\b/ui', 3.0],
            ['afraid', '/\b(afraid|scared|fearful|nahadlok|natakot|takot|afraid\s+of\s+(the\s+)?doctor|nahadlok\s+.*doktor|fear\s+of\s+hospital)\b/ui', 2.2],
            ['sad', '/\b(grief|grieving|namatay|passed\s+away|relationship\s+problem)\b/ui', 2.1],
            ['disappointed', '/\b(disappoint|nadismaya|dismaya)\b/ui', 2.0],
            ['nervous', '/\b(nervous|kinakabahan|kabado)\b/ui', 2.0],
            ['pain', '/\b(sakit\s+ulo|headache|masakit|sakit\s+lawas|ginasakit|gasakit|gapalanakit)\b/ui', 2.5],
            ['sick', '/\b(hilanat|lagnat|fever|sick|sipon|ubo|gasuka|may\s+sakit|budlay\s+pamatyag)\b/ui', 2.5],
            ['crying', '/\b(crying|umiiyak|naga\s*hilib|naga\s*hibi)\b/ui', 2.0],
            ['overwhelmed', '/\b(overwhelm|overwhelmed|daw\s+wala\s+na\s+ko\s+gana|wala\s+na\s+akong\s+gana|family\s+problem|problema\s+sa\s+pamilya|no\s+money|wala\s+(ko\s+)?kwarta)\b/ui', 2.0],
            ['happy', '/\b(happy|masaya|masadya|sayo)\b/ui', 2.0],
            ['confused', '/\b(confus|nalibog|nalilito|libog)\b/ui', 2.0],
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
        $t = mb_strtolower(trim($text), 'UTF-8');
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
        if (in_array($emotion, ['happy', 'thankful', 'relieved', 'excited', 'curious'], true)) {
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
        ], true)) {
            return 'distress_support';
        }
        if ($emotion === 'pain' || $emotion === 'sick') {
            return 'pain_sick';
        }
        if ($emotion === 'thankful') {
            return 'gratitude';
        }
        if ($emotion === 'happy' || $emotion === 'relieved' || $emotion === 'excited') {
            return $emotion;
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
     * @param array{emotion?: string|null, at?: int}|null $previousContext
     * @return array<string, mixed>
     */
    public static function analyze(string $text, string $lang = 'en', ?string $intent = null, ?array $previousContext = null): array
    {
        $raw = trim($text);
        if ($raw === '') {
            return self::emptyResult();
        }

        $norm = self::normalizeText($raw);
        $scores = [];

        foreach (self::boostRules() as [$emotion, $pattern, $weight]) {
            if (preg_match($pattern, $norm)) {
                $scores[$emotion] = ($scores[$emotion] ?? 0) + $weight;
            }
        }

        if (preg_match('/\b(how|paano|ano|what|why|safe|sigurado|trust)\b/ui', $norm)) {
            $scores['curious'] = ($scores['curious'] ?? 0) + 1.5;
            unset($scores['panic'], $scores['emergency']);
        }

        $picked = self::pickPrimary($scores);
        $primary = $picked['primary'];
        $score = $picked['score'];

        if ($previousContext && !empty($previousContext['emotion'])) {
            $prev = (string) $previousContext['emotion'];
            $neg = ['sad', 'anxious', 'worried', 'stressed', 'lonely', 'hopeless'];
            if ($primary && in_array($primary, $neg, true) && $prev === $primary) {
                $score += 0.4;
            }
        }

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
