<?php
/**
 * Structured conversational intent lexicon for the FAQ chatbot (PHP only).
 * Exact → normalized → keyword/synonym → fuzzy scoring. No external AI.
 *
 * Dataset: data/nlp/faq_chatbot_conversational_intents.json plus in-code expansions.
 */
final class FaqChatbotConversationalIntents
{
    private const JSON_PATH = 'data/nlp/faq_chatbot_conversational_intents.json';
    private const MIN_SCORE = 1.55;

    /** @var array<string, array<string, mixed>>|null phrase → row */
    private static ?array $exact = null;

    /** @var array<string, list<string>>|null token → phrase keys */
    private static ?array $tokens = null;

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $rows = null;

    /**
     * @return array{
     *   intent: string,
     *   kb_key: string,
     *   flow_key: string,
     *   category: string,
     *   score: float,
     *   emergency: bool,
     *   phrase: string
     * }|null
     */
    public static function match(string $rawText, string $nlpText = ''): ?array
    {
        self::ensureLoaded();
        $hay = self::normalize($rawText . ' ' . $nlpText);
        if ($hay === '' || self::$exact === null) {
            return null;
        }

        if (isset(self::$exact[$hay])) {
            return self::hit(self::$exact[$hay], $hay, 5.0);
        }

        $tokens = self::tokenize($hay);
        if ($tokens === []) {
            return null;
        }

        /** @var array<string, float> $cand */
        $cand = [];
        foreach ($tokens as $tok) {
            if (mb_strlen($tok) < 3) {
                continue;
            }
            foreach (self::$tokens[$tok] ?? [] as $phrase) {
                $cand[$phrase] = ($cand[$phrase] ?? 0) + 1.15;
            }
        }

        arsort($cand);
        $cand = array_slice($cand, 0, 60, true);

        $best = null;
        $bestScore = 0.0;
        foreach ($cand as $phrase => $tokenScore) {
            $row = self::$exact[$phrase] ?? null;
            if (!is_array($row)) {
                continue;
            }
            $score = $tokenScore;
            if (mb_strpos($hay, $phrase) !== false || mb_strpos($phrase, $hay) !== false) {
                $score += 2.6;
            }
            similar_text($hay, $phrase, $pct);
            $score += ($pct / 100) * 2.2;
            $lev = levenshtein(mb_substr($hay, 0, 80), mb_substr($phrase, 0, 80));
            if ($lev <= 2 && mb_strlen($hay) >= 4) {
                $score += 1.4;
            } elseif ($lev <= 3 && mb_strlen($hay) >= 6) {
                $score += 0.7;
            }
            if (!empty($row['emergency'])) {
                $score += 3.0;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = [$row, $phrase];
            }
        }

        if ($best === null || $bestScore < self::MIN_SCORE) {
            return null;
        }

        return self::hit($best[0], $best[1], $bestScore);
    }

    /**
     * Top distinct-category matches for multi-intent messages.
     *
     * @return list<array{intent: string, kb_key: string, flow_key: string, category: string, score: float, emergency: bool, phrase: string}>
     */
    public static function matchAll(string $rawText, string $nlpText = '', int $limit = 2): array
    {
        self::ensureLoaded();
        $primary = self::match($rawText, $nlpText);
        if ($primary === null) {
            return [];
        }
        if (!empty($primary['emergency'])) {
            return [$primary];
        }

        $hay = self::normalize($rawText . ' ' . $nlpText);
        $picked = [$primary];
        $cats = [(string) $primary['category'] => true];

        $cues = [
            'appointments'          => ['doctor', 'doktor', 'book', 'appointment', 'checkup', 'konsulta', 'pakonsulta', 'consulta'],
            'healthcare'            => ['sakit', 'masakit', 'ulo', 'headache', 'tiyan', 'hilanat', 'dizzy', 'nahilo', 'head', 'tummy', 'fever', 'cough', 'lagnat'],
            'emotional_support'     => ['nahadlok', 'scared', 'afraid', 'kulbaan', 'ginakulbaan', 'nabalaka', 'worried', 'anxious', 'natatakot', 'kasubo', 'nasubo', 'lonely', 'akig', 'hibi', 'kapoy', 'panic', 'malungkot', 'galit', 'naguol', 'ginapanik', 'pagod', 'nahuya', 'namatay', 'homesick', 'nahidlaw', 'guilty', 'inggit', 'nadismaya'],
            'accounts'              => ['login', 'password', 'otp', 'register', 'account', 'sulod'],
            'video_consultation'    => ['video', 'camera', 'microphone', 'mabatian', 'makita', 'audio', 'speaker'],
            'records'               => ['record', 'soap', 'prescription', 'reseta', 'history', 'emr'],
            'privacy'               => ['privacy', 'confidential', 'security', 'masaligan'],
            'technical'             => ['loading', 'browser', 'stuck', 'blank', 'error'],
            'bhw'                   => ['bhw'],
            'access_barriers'       => ['kwarta', 'bayad', 'plete', 'mahal', 'afford'],
        ];

        foreach ($cues as $cat => $words) {
            if (count($picked) >= $limit) {
                break;
            }
            if (isset($cats[$cat])) {
                continue;
            }
            $hits = 0;
            foreach ($words as $w) {
                if (preg_match('/\b' . preg_quote($w, '/') . '\b/u', $hay)) {
                    $hits++;
                }
            }
            if ($hits === 0) {
                continue;
            }
            foreach (self::$rows ?? [] as $row) {
                if ((string) ($row['category'] ?? '') !== $cat || !empty($row['emergency'])) {
                    continue;
                }
                $picked[] = self::hit($row, 'multi:' . $cat, 2.6 + $hits);
                $cats[$cat] = true;
                break;
            }
        }
        return $picked;
    }

    public static function phraseCount(): int
    {
        self::ensureLoaded();
        return self::$exact === null ? 0 : count(self::$exact);
    }

    public static function intentCount(): int
    {
        self::ensureLoaded();
        $ids = [];
        foreach (self::$rows ?? [] as $row) {
            $ids[(string) ($row['id'] ?? '')] = true;
        }
        return count($ids);
    }

    public static function normalize(string $text): string
    {
        $t = mb_strtolower(trim($text), 'UTF-8');
        $t = preg_replace('/(.)\1{2,}/u', '$1$1', $t) ?? $t;
        $t = strtr($t, [
            'appoinment' => 'appointment', 'appointmnt' => 'appointment', 'apointment' => 'appointment',
            'appoitment' => 'appointment',
            'consulatation' => 'consultation', 'consulation' => 'consultation', 'konsultaion' => 'consultation',
            'regster' => 'register', 'rehistro' => 'register', 'registation' => 'registration',
            'passwrod' => 'password', 'pasword' => 'password',
            'docter' => 'doctor', 'doktor' => 'doctor', 'doctur' => 'doctor',
            'vidoe' => 'video', 'headake' => 'headache', 'headeche' => 'headache', 'headahe' => 'headache',
            'uloo' => 'ulo', 'ginakulaan' => 'ginakulbaan', 'nahadlokkk' => 'nahadlok',
            'masakit uloo' => 'masakit ulo', 'hulpp' => 'help', 'pleasee' => 'please',
            'camra' => 'camera', 'microfone' => 'microphone', 'mircophone' => 'microphone',
            'scheduel' => 'schedule', 'schedual' => 'schedule',
            'presciption' => 'prescription', 'prescripion' => 'prescription',
            'loggin' => 'login', 'logn' => 'login',
            'symtom' => 'symptom', 'fevr' => 'fever', 'throath' => 'throat',
            'vacine' => 'vaccine', 'bakunna' => 'bakuna', 'buntiss' => 'buntis',
            'notifcation' => 'notification', 'announcment' => 'announcement',
            'referal' => 'referral', 'preganant' => 'pregnant',
        ]);
        $t = preg_replace('/[^\p{L}\p{N}\s\'-]/u', ' ', $t) ?? $t;
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;
        return trim($t);
    }

    /**
     * @return array<string, mixed>
     */
    private static function hit(array $row, string $phrase, float $score): array
    {
        return [
            'intent'    => (string) ($row['intent'] ?? FaqChatbotIntentRecognizer::GENERAL),
            'kb_key'    => (string) ($row['kb_key'] ?? 'navigation_help'),
            'flow_key'  => (string) ($row['flow_key'] ?? 'services'),
            'category'  => (string) ($row['category'] ?? 'general'),
            'score'     => round($score, 3),
            'emergency' => !empty($row['emergency']),
            'phrase'    => $phrase,
        ];
    }

    /** @return list<string> */
    private static function tokenize(string $hay): array
    {
        $parts = preg_split('/\s+/u', $hay) ?: [];
        $tokens = [];
        foreach ($parts as $w) {
            if ($w === '' || mb_strlen($w) < 2) {
                continue;
            }
            $tokens[] = self::squeezeToken($w);
        }
        return FaqChatbotSynonymMap::expand($tokens);
    }

    private static function squeezeToken(string $w): string
    {
        while (mb_strlen($w) >= 4) {
            $a = mb_substr($w, -1);
            $b = mb_substr($w, -2, 1);
            if ($a === $b) {
                $w = mb_substr($w, 0, -1);
                continue;
            }
            break;
        }
        return $w;
    }

    private static function ensureLoaded(): void
    {
        if (self::$exact !== null) {
            return;
        }
        self::$exact = [];
        self::$tokens = [];
        self::$rows = [];

        foreach (self::catalog() as $row) {
            self::indexRow($row);
        }

        $jsonPath = (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2)) . '/' . self::JSON_PATH;
        if (is_readable($jsonPath)) {
            $decoded = json_decode((string) file_get_contents($jsonPath), true);
            if (is_array($decoded)) {
                $intents = $decoded['intents'] ?? $decoded;
                if (is_array($intents)) {
                    foreach ($intents as $row) {
                        if (is_array($row)) {
                            self::indexRow($row);
                        }
                    }
                }
            }
        }
    }

    /** @param array<string, mixed> $row */
    private static function indexRow(array $row): void
    {
        $id = (string) ($row['id'] ?? $row['intent_name'] ?? $row['intent'] ?? '');
        if ($id === '') {
            return;
        }
        $base = [
            'id'         => $id,
            'intent'     => (string) ($row['intent'] ?? FaqChatbotIntentRecognizer::GENERAL),
            'kb_key'     => (string) ($row['kb_key'] ?? 'navigation_help'),
            'flow_key'   => (string) ($row['flow_key'] ?? 'services'),
            'category'   => (string) ($row['category'] ?? 'general'),
            'emergency'  => !empty($row['emergency_flag']) || !empty($row['emergency']),
        ];
        self::$rows[$id] = $base;

        $phrases = [];
        foreach ((array) ($row['phrases'] ?? []) as $p) {
            $n = self::normalize((string) $p);
            if ($n !== '') {
                $phrases[$n] = true;
            }
        }
        foreach (FaqChatbotPhraseBank::forIntent($id) as $p) {
            $n = self::normalize((string) $p);
            if ($n !== '') {
                $phrases[$n] = true;
            }
        }
        foreach (self::expandRow($row) as $p) {
            $phrases[$p] = true;
        }

        foreach (array_keys($phrases) as $phrase) {
            if (!isset(self::$exact[$phrase]) || !empty($base['emergency'])) {
                self::$exact[$phrase] = $base + ['phrase' => $phrase];
            }
            foreach (self::tokenize($phrase) as $tok) {
                if (mb_strlen($tok) < 3) {
                    continue;
                }
                self::$tokens[$tok][] = $phrase;
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return list<string>
     */
    private static function expandRow(array $row): array
    {
        $out = [];
        $how = [
            'how to', 'how do i', 'how do we', 'how can i', 'paano', 'paano mag', 'paano ko', 'paano ba',
            'diin ko', 'diin ako', 'diin mag', 'saan ako', 'saan ko', 'saan mag', 'pano', 'pano mag', 'pano ko',
            'can i', 'pwede ba', 'pwede ko', 'pwede ba mag', 'gusto ko mag', 'palihog',
        ];
        $need = ['need', 'want', 'gusto', 'kailangan', 'kinahanglan', 'need to', 'want to', 'pls', 'please', 'i need', 'i want', 'tabangi', 'buligi'];
        foreach ((array) ($row['expand_acts'] ?? []) as $act) {
            $act = self::normalize((string) $act);
            if ($act === '') {
                continue;
            }
            foreach ($how as $h) {
                $out[] = self::normalize($h . ' ' . $act);
            }
            foreach ($need as $n) {
                $out[] = self::normalize($n . ' ' . $act);
            }
        }
        return $out;
    }

    /**
     * Core catalog — maps natural EN/FIL/HIL/mixed phrases onto existing KB keys.
     *
     * @return list<array<string, mixed>>
     */
    private static function catalog(): array
    {
        $A = FaqChatbotIntentRecognizer::APPOINTMENT;
        $L = FaqChatbotIntentRecognizer::LOGIN;
        $R = FaqChatbotIntentRecognizer::REGISTRATION;
        $P = FaqChatbotIntentRecognizer::PASSWORD_RESET;
        $C = FaqChatbotIntentRecognizer::CONSULTATION;
        $S = FaqChatbotIntentRecognizer::SYMPTOMS;
        $E = FaqChatbotIntentRecognizer::EMOTIONAL_SUPPORT;
        $F = FaqChatbotIntentRecognizer::FINANCIAL;
        $G = FaqChatbotIntentRecognizer::GREETING;
        $I = FaqChatbotIntentRecognizer::IDENTITY;
        $CAP = FaqChatbotIntentRecognizer::CAPABILITIES;
        $EM = FaqChatbotIntentRecognizer::EMERGENCY;
        $REC = FaqChatbotIntentRecognizer::RECORDS;
        $CON = FaqChatbotIntentRecognizer::CONNECTIVITY;
        $PR = FaqChatbotIntentRecognizer::PRIVACY;
        $TR = FaqChatbotIntentRecognizer::TRANSPORT;
        $NAV = FaqChatbotIntentRecognizer::NAVIGATION;
        $DOC = FaqChatbotIntentRecognizer::DOCTOR;
        $BHW = 'bhw';
        $TECH = 'technical';

        return [
            [
                'id' => 'greeting', 'category' => 'general', 'intent' => $G, 'kb_key' => 'greeting', 'flow_key' => 'welcome',
                'phrases' => ['hi', 'hello', 'hey', 'good morning', 'good afternoon', 'good evening', 'kumusta', 'musta', 'maayong aga', 'hello po', 'hi po'],
            ],
            [
                'id' => 'uncertainty', 'category' => 'emotional_support', 'intent' => $E, 'kb_key' => 'uncertainty_support', 'flow_key' => 'distress_support',
                'phrases' => [
                    'what do i do', 'i dont know what to do', "i don't know what to do",
                    'wala ko kabalo ano ubrahon', 'wala ko kabalo ano ubrahon ko',
                    'indi ko kabalo ano ubrahon', 'indi ko kabalo ano ubrahon ko',
                    'ano ubrahon ko', 'ano dapat ubrahon ko', 'hindi ko alam ang gagawin',
                    'di ko alam', 'indi ko kabalo', 'wala ko kabalo',
                ],
            ],
            [
                'id' => 'help_general', 'category' => 'general', 'intent' => $CAP, 'kb_key' => 'capabilities', 'flow_key' => 'services',
                'phrases' => [
                    'help', 'help me', 'please help me', 'help me please', 'what can you do', 'who are you', 'what is medconnect', 'explain medconnect',
                    'im confused', "i'm confused",
                    'di ko alam paano', 'buligi ko', 'bulig',
                    'tulungan mo ako', 'where should i start', 'how to use medconnect',
                    'confused ko', 'nalibog ko', 'help pls', 'tabang', 'tabangi ko',
                ],
            ],
            [
                'id' => 'login', 'category' => 'accounts', 'intent' => $L, 'kb_key' => 'login_help', 'flow_key' => 'signin',
                'phrases' => [
                    'sign in', 'login', 'log in', 'cannot login', "can't login", 'login not working', 'wala ko ka login',
                    'indi ko ka login', 'indi ko ka login sa account', 'hindi ako makalogin', 'di ko maka login',
                    'invalid credentials', 'incorrect password', 'account locked', 'sulod', 'indi ko makasulod',
                    'login problem', 'sign in not working',
                ],
                'expand_acts' => ['login', 'sign in', 'sulod', 'log in'],
            ],
            [
                'id' => 'register', 'category' => 'accounts', 'intent' => $R, 'kb_key' => 'registration_help', 'flow_key' => 'register',
                'phrases' => [
                    'create account', 'registration', 'new patient', 'how to register', 'who can register',
                    'registration failed', 'registration error', 'already registered', 'duplicate account',
                    'national id', 'ocr', 'identity verification', 'paano mag register', 'paano magrehistro',
                    'existing patient',
                ],
                'expand_acts' => ['register', 'rehistro', 'sign up', 'create account'],
            ],
            [
                'id' => 'password', 'category' => 'accounts', 'intent' => $P, 'kb_key' => 'password_reset', 'flow_key' => 'reset',
                'phrases' => [
                    'forgot password', 'reset password', 'change password', 'how do i reset my password',
                    'passwrod', 'nakalimtan password', 'nakalimutan password',
                ],
                'expand_acts' => ['reset password', 'forgot password'],
            ],
            [
                'id' => 'otp', 'category' => 'accounts', 'intent' => FaqChatbotIntentRecognizer::OTP, 'kb_key' => 'otp_verification', 'flow_key' => 'signin',
                'phrases' => [
                    'otp', 'otp not received', 'verification code', 'code not received', 'wala otp',
                    'wala ko otp', 'wala ko otp paano ni', 'wala nag-abot otp', 'email not received',
                ],
            ],
            [
                'id' => 'book', 'category' => 'appointments', 'intent' => $A, 'kb_key' => 'book_appointment', 'flow_key' => 'appointment',
                'phrases' => [
                    'how to book', 'paano mag book', 'pano magpa checkup', 'want consultation',
                    'pa checkup ko', 'magpakonsulta ko', 'diin ko maka book', 'diin ko ma book',
                    'paano ko magpa checkup', 'gusto ko magpakonsulta', 'can i talk to a doctor', 'schedule consultation',
                    'available doctor', 'urgent appointment', 'gusto ko magpa consultation', 'need consultation',
                    'book appointment', 'mag book', 'checkup', 'pakonsulta', 'consulta', 'consultation',
                    'konsultasyon', 'konsulta', 'schedule', 'scheduled visit',
                    'diin ko mag book', 'paano magpakonsulta', 'gusto ko magpa checkup',
                ],
                'expand_acts' => ['book', 'booking', 'appointment', 'checkup', 'pakonsulta', 'consultation', 'magbook', 'pa checkup'],
            ],
            [
                'id' => 'cancel', 'category' => 'appointments', 'intent' => $A, 'kb_key' => 'cancel_appointment', 'flow_key' => 'appointment',
                'phrases' => [
                    'can i cancel', 'cancel appointment', 'reschedule', 'change appointment', 'i-cancel',
                    'cancel ko appointment', 'move my appointment',
                ],
            ],
            [
                'id' => 'appt_status', 'category' => 'appointments', 'intent' => $A, 'kb_key' => 'appointment_status', 'flow_key' => 'appointment',
                'phrases' => [
                    'where is my consultation', 'di ko makita appointment', 'appointment status', 'upcoming appointment',
                    'may appointment ko', 'may appointment ako pero wala doctor', 'missed appointment',
                    'appointment confirmation', 'appointment reminder',
                ],
            ],
            [
                'id' => 'video', 'category' => 'video_consultation', 'intent' => $C, 'kb_key' => 'video_consult', 'flow_key' => 'video',
                'phrases' => [
                    'how video consultation works', 'join consultation', 'can i join my consultation',
                    'gusto ko mag video call doctor', 'video consult', 'telemedicine',
                ],
                'expand_acts' => ['video', 'video consult', 'video call', 'join consultation'],
            ],
            [
                'id' => 'video_trouble', 'category' => 'video_consultation', 'intent' => $CON, 'kb_key' => 'video_troubleshooting', 'flow_key' => 'video',
                'phrases' => [
                    'doctor didnt join', "doctor didn't join", 'video call not working', 'indi naga work ang video call',
                    'doctor not appearing', 'cannot see doctor', 'no audio', 'no microphone', 'no camera',
                    'camera permission', 'microphone permission', 'video frozen', 'connection problem',
                    'patient cannot see doctor', 'doctor cannot see patient',
                    'cannot hear the doctor', "can't hear doctor", 'camera doesnt work', "camera doesn't work",
                    'indi ko makita', 'indi ko mabatian', 'camera indi naga work', 'wala doctor sa video',
                    'indi ko marinig ang doktor', 'indi ko makita doctor',
                ],
            ],
            [
                'id' => 'symptoms', 'category' => 'healthcare', 'intent' => $S, 'kb_key' => 'symptoms_general', 'flow_key' => 'pain_sick',
                'phrases' => [
                    'my head hurts', 'headache', 'masakit ulo ko', 'sakit akon ulo', 'sakit ulo ko', 'masakit akon ulo',
                    'naga sakit akon ulo', 'headache ko', 'nag sakit ulo ko', 'ga sakit ulo ko', 'ginakasakit ulo ko',
                    'may sakit ako', 'masakit ulo ko need doctor', 'dugo ulo ko', 'stomach pain', 'chest pain mild',
                    'fever', 'cough', 'colds', 'dizzy', 'vomiting', 'diarrhea', 'body pain', 'back pain',
                    'sore throat', 'toothache', 'rash', 'swelling', 'nausea', 'masakit tiyan', 'sakit tiyan',
                    'lagnat', 'hilanat', 'ubo', 'sipon',
                ],
            ],
            [
                'id' => 'emotion', 'category' => 'emotional_support', 'intent' => $E, 'kb_key' => 'fear_support', 'flow_key' => 'distress_support',
                'phrases' => [
                    'i am scared', "i'm scared", 'i am afraid', 'scared', 'afraid',
                    'nahadlok ko', 'nahadlok gid ko', 'natatakot ako',
                    'nahadlok ko kay sakit akon ulo', 'nahadlok ko because of my symptoms',
                    'nahadlok ko kay grabe sakit ulo ko', 'scared about my symptoms',
                ],
            ],
            [
                'id' => 'emotion_anxiety', 'category' => 'emotional_support', 'intent' => $E, 'kb_key' => 'anxiety_support', 'flow_key' => 'distress_support',
                'phrases' => ['anxious', 'anxiety', 'nervous', 'worried', 'ginakulbaan ko', 'kulbaan gid ko', 'kinakabahan ako', 'nabalaka ko'],
            ],
            [
                'id' => 'emotion_sad', 'category' => 'emotional_support', 'intent' => $E, 'kb_key' => 'sadness_support', 'flow_key' => 'distress_support',
                'phrases' => ['sad', 'i am sad', 'malungkot', 'nasubo ko', 'kasubo', 'i feel sad'],
            ],
            [
                'id' => 'emotion_lonely', 'category' => 'emotional_support', 'intent' => $E, 'kb_key' => 'need_to_talk', 'flow_key' => 'distress_support',
                'phrases' => ['lonely', 'i am lonely', 'isa lang ko', 'wala ko maistoryahan'],
            ],
            [
                'id' => 'emotion_anger', 'category' => 'emotional_support', 'intent' => $E, 'kb_key' => 'anger_support', 'flow_key' => 'distress_support',
                'phrases' => ['i am angry', 'angry', 'galit ako', 'akig ko', 'akig gid ko'],
            ],
            [
                'id' => 'emotion_panic', 'category' => 'emotional_support', 'intent' => $E, 'kb_key' => 'panic_support', 'flow_key' => 'distress_support',
                'phrases' => ['panic', 'panic attack', "i'm panicking", 'im panicking', 'ginapanik ko'],
            ],
            [
                'id' => 'emotion_tired', 'category' => 'emotional_support', 'intent' => $E, 'kb_key' => 'burnout_support', 'flow_key' => 'distress_support',
                'phrases' => ['i am tired', 'exhausted', 'kapoy na ko', 'burnout', 'pagod na ako'],
            ],
            [
                'id' => 'emotion_overwhelmed', 'category' => 'emotional_support', 'intent' => $E, 'kb_key' => 'stress_support', 'flow_key' => 'distress_support',
                'phrases' => ['overwhelmed', 'too much', 'sobra na', 'mabug-at gid'],
            ],
            [
                'id' => 'emotion_crying', 'category' => 'emotional_support', 'intent' => $E, 'kb_key' => 'crying_support', 'flow_key' => 'distress_support',
                'phrases' => ['i am crying', 'crying', 'naga hibi ko', 'umiiyak ako'],
            ],
            [
                'id' => 'emotion_embarrass', 'category' => 'emotional_support', 'intent' => $E, 'kb_key' => 'embarrassment_support', 'flow_key' => 'distress_support',
                'phrases' => ['embarrassed', 'nahuya ko', 'nahihiya ako', 'ashamed'],
            ],
            [
                'id' => 'emotion_grief', 'category' => 'emotional_support', 'intent' => $E, 'kb_key' => 'grief_support', 'flow_key' => 'distress_support',
                'phrases' => ['grief', 'grieving', 'namatay', 'passed away'],
            ],
            [
                'id' => 'emotion_sleep', 'category' => 'emotional_support', 'intent' => $E, 'kb_key' => 'cant_sleep', 'flow_key' => 'distress_support',
                'phrases' => ["can't sleep", 'cannot sleep', 'indi ko katulog', 'insomnia'],
            ],
            [
                'id' => 'emotion_doctor_fear', 'category' => 'emotional_support', 'intent' => $E, 'kb_key' => 'afraid_of_doctor', 'flow_key' => 'distress_support',
                'phrases' => ['afraid of the doctor', 'scared of the doctor', 'nahadlok ko sa doktor', 'nahadlok sa hospital'],
            ],
            [
                'id' => 'emotion_talk', 'category' => 'emotional_support', 'intent' => $E, 'kb_key' => 'need_to_talk', 'flow_key' => 'distress_support',
                'phrases' => ['need someone to talk', 'just need to talk', 'i need to talk'],
            ],
            [
                'id' => 'emotion_hope', 'category' => 'emotional_support', 'intent' => FaqChatbotIntentRecognizer::REASSURANCE, 'kb_key' => 'reassurance_okay', 'flow_key' => 'welcome',
                'phrases' => ['i feel better', 'i am hopeful', 'may paglaum', 'i am relieved', 'malipayon ko'],
            ],
            [
                'id' => 'emotion_guilt', 'category' => 'emotional_support', 'intent' => $E, 'kb_key' => 'guilt_support', 'flow_key' => 'distress_support',
                'phrases' => ['i feel guilty', 'guilty', 'kasalanan ko', 'may guilt ako'],
            ],
            [
                'id' => 'emotion_shame', 'category' => 'emotional_support', 'intent' => $E, 'kb_key' => 'shame_support', 'flow_key' => 'distress_support',
                'phrases' => ['i feel ashamed', 'i am ashamed', 'nakahuya gid', 'huya gid'],
            ],
            [
                'id' => 'emotion_jealous', 'category' => 'emotional_support', 'intent' => $E, 'kb_key' => 'jealousy_support', 'flow_key' => 'distress_support',
                'phrases' => ['i am jealous', 'jealous', 'naiinggit ako', 'selos'],
            ],
            [
                'id' => 'emotion_bored', 'category' => 'emotional_support', 'intent' => $E, 'kb_key' => 'boredom_support', 'flow_key' => 'distress_support',
                'phrases' => ['i am bored', 'bored', 'wala ko gana', 'wala gana'],
            ],
            [
                'id' => 'emotion_mixed', 'category' => 'emotional_support', 'intent' => $E, 'kb_key' => 'mixed_feelings', 'flow_key' => 'distress_support',
                'phrases' => ['mixed feelings', 'magkahalong', 'pero nahadlok', 'happy but scared'],
            ],
            [
                'id' => 'emotion_social', 'category' => 'emotional_support', 'intent' => $E, 'kb_key' => 'social_anxiety', 'flow_key' => 'distress_support',
                'phrases' => ['social anxiety', 'scared of people', 'nahadlok sa tao', 'kulbaan ko sa tawo'],
            ],
            [
                'id' => 'emotion_exam', 'category' => 'emotional_support', 'intent' => $E, 'kb_key' => 'exam_anxiety', 'flow_key' => 'distress_support',
                'phrases' => ['exam anxiety', 'kulba sa exam', 'kinabahan sa exam', 'stress sa exam'],
            ],
            [
                'id' => 'emotion_homesick', 'category' => 'emotional_support', 'intent' => $E, 'kb_key' => 'homesickness', 'flow_key' => 'distress_support',
                'phrases' => ['homesick', 'nahidlaw ko', 'miss my family', 'nahidlaw sa balay'],
            ],
            [
                'id' => 'emotion_disappoint', 'category' => 'emotional_support', 'intent' => $E, 'kb_key' => 'disappointment_support', 'flow_key' => 'distress_support',
                'phrases' => ['disappointed', 'i am disappointed', 'nadismaya', 'nadismaya ako'],
            ],
            [
                'id' => 'thanks', 'category' => 'general', 'intent' => FaqChatbotIntentRecognizer::THANKS, 'kb_key' => 'thank_you', 'flow_key' => 'gratitude',
                'phrases' => [
                    'thanks', 'thank you', 'thank you so much', 'okay thanks', 'ok thanks', 'got it',
                    "that's all", 'thats all', 'salamat', 'salamat gid', 'okay salamat',
                ],
            ],
            [
                'id' => 'emergency', 'category' => 'emergency', 'intent' => $EM, 'kb_key' => 'emergency_redirect', 'flow_key' => 'emergency',
                'emergency_flag' => true,
                'phrases' => [
                    'difficulty breathing', 'severe chest pain', 'unconscious', 'fainting', 'seizure', 'severe bleeding',
                    'indi ko kaginhawa', 'indi ko ginhawa', 'budlay mag ginhawa', 'ginakulbaan ko kag indi ko kaginhawa',
                    'dugo gid', 'nahimatay', 'wala malay', 'nag seizure', 'grabe chest pain', 'blue lips',
                    'cannot breathe', "can't breathe", 'severe allergic reaction', 'poisoning', 'self harm',
                    'suicidal thoughts', 'facial drooping', 'difficulty speaking',
                ],
            ],
            [
                'id' => 'money', 'category' => 'access_barriers', 'intent' => $F, 'kb_key' => 'consultation_cost', 'flow_key' => 'financial',
                'phrases' => [
                    'how much', 'free ni', 'may bayad', 'libre ni', 'wala ko kwarta', 'wala ko budget', 'mahal',
                    'cannot afford', 'no money', 'wala ko plete', 'malayo kami', 'indi ko kaadto', 'no transportation',
                    'far from health center', 'expensive',
                ],
            ],
            [
                'id' => 'bhw', 'category' => 'bhw', 'intent' => $BHW, 'kb_key' => 'bhw_help', 'flow_key' => 'services',
                'phrases' => [
                    'what is bhw', 'can bhw help me', 'bhw assistance', 'barangay health worker',
                    'bhw can help register', 'bhw referral', 'bhw emergency referral',
                    'assisting an existing patient', 'patient already registered',
                ],
            ],
            [
                'id' => 'records', 'category' => 'records', 'intent' => $REC, 'kb_key' => 'medical_records', 'flow_key' => 'records',
                'phrases' => [
                    'medical record', 'soap', 'doctors notes', "doctor's notes", 'diagnosis', 'treatment plan',
                    'health summary', 'previous consultation', 'consultation history', 'emr',
                ],
            ],
            [
                'id' => 'privacy', 'category' => 'privacy', 'intent' => $PR, 'kb_key' => 'privacy_security', 'flow_key' => 'policy',
                'phrases' => [
                    'privacy', 'who can see my records', 'data security', 'confidential', 'bhw access',
                    'unauthorized access', 'account security',
                ],
            ],
            [
                'id' => 'tech', 'category' => 'technical', 'intent' => $TECH, 'kb_key' => 'technical_support', 'flow_key' => 'services',
                'phrases' => [
                    'website not loading', 'page stuck', 'loading forever', 'button not working', 'blank page',
                    'error message', 'browser problem', 'mobile problem', 'appointment not showing',
                    'dashboard problem', 'notification problem',
                ],
            ],
            [
                'id' => 'transport', 'category' => 'access_barriers', 'intent' => $TR, 'kb_key' => 'transport_barrier', 'flow_key' => 'distress_support',
                'phrases' => ['wala ko plete', 'malayo kami', 'indi ko kaadto', 'cannot travel', 'cannot leave home', 'layo amon'],
            ],
            [
                'id' => 'identity', 'category' => 'general', 'intent' => $I, 'kb_key' => 'identity', 'flow_key' => 'welcome',
                'phrases' => ['who are you', 'what are you', 'are you a bot', 'sino ka', 'ano ka'],
            ],
            [
                'id' => 'need_doctor', 'category' => 'appointments', 'intent' => $DOC, 'kb_key' => 'doctor_clarify', 'flow_key' => 'appointment',
                'phrases' => ['need doctor', 'doctor pls', 'doctor please', 'gusto ko doktor', 'kinahanglan ko doktor', 'need a doctor', 'i need a doctor', 'doctor'],
            ],
            [
                'id' => 'confused_start', 'category' => 'general', 'intent' => $NAV, 'kb_key' => 'navigation_help', 'flow_key' => 'services',
                'phrases' => ['where should i start', 'how do i use this', 'explain medconnect', 'what services are available'],
            ],
            [
                'id' => 'logout', 'category' => 'accounts', 'intent' => $L, 'kb_key' => 'login_help', 'flow_key' => 'signin',
                'phrases' => ['logout', 'log out', 'sign out', 'paano mag logout'],
            ],
            [
                'id' => 'profile', 'category' => 'accounts', 'intent' => FaqChatbotIntentRecognizer::PROFILE, 'kb_key' => 'profile_update', 'flow_key' => 'services',
                'phrases' => ['update profile', 'edit profile', 'change address', 'change contact'],
            ],
            [
                'id' => 'frustration', 'category' => 'emotional_support', 'intent' => $E, 'kb_key' => 'irritation_support', 'flow_key' => 'distress_support',
                'phrases' => ['this is frustrating', 'nakakainis', 'lain gid', 'why is this so hard'],
            ],
            [
                'id' => 'goodbye', 'category' => 'general', 'intent' => FaqChatbotIntentRecognizer::GOODBYE, 'kb_key' => 'goodbye', 'flow_key' => 'welcome',
                'phrases' => ['bye', 'goodbye', 'see you', 'paalam', 'kita ta'],
            ],
            [
                'id' => 'prescriptions', 'category' => 'records', 'intent' => FaqChatbotIntentRecognizer::PRESCRIPTION, 'kb_key' => 'digital_prescriptions', 'flow_key' => 'prescriptions',
                'phrases' => ['prescription', 'reseta', 'digital prescription', 'gamot ko', 'where is my prescription'],
            ],
            [
                'id' => 'hours', 'category' => 'general', 'intent' => FaqChatbotIntentRecognizer::SCHEDULE, 'kb_key' => 'clinic_schedules', 'flow_key' => 'hours',
                'phrases' => ['office hours', 'clinic hours', 'bukas', 'sarado', 'oras sang opisina'],
            ],
            [
                'id' => 'contact', 'category' => 'general', 'intent' => FaqChatbotIntentRecognizer::CONTACT, 'kb_key' => 'contact_cho', 'flow_key' => 'contact',
                'phrases' => ['contact city health', 'contact cho', 'tawag sa cho', 'city health office contact'],
            ],
            [
                'id' => 'triage', 'category' => 'healthcare', 'intent' => FaqChatbotIntentRecognizer::TRIAGE, 'kb_key' => 'ai_triage_info', 'flow_key' => 'services',
                'phrases' => ['ai triage', 'symptom checker', 'what is ai triage'],
            ],
            [
                'id' => 'followup', 'category' => 'appointments', 'intent' => FaqChatbotIntentRecognizer::FOLLOW_UP, 'kb_key' => 'followup_consult', 'flow_key' => 'appointment',
                'phrases' => ['follow up', 'followup', 'balik consult', 'sunod nga consultation'],
            ],
            [
                'id' => 'email_verify', 'category' => 'accounts', 'intent' => $R, 'kb_key' => 'email_verification', 'flow_key' => 'register',
                'phrases' => ['verify email', 'email verification', 'confirm email', 'not verified'],
            ],
            [
                'id' => 'locked', 'category' => 'accounts', 'intent' => $L, 'kb_key' => 'account_recovery', 'flow_key' => 'reset',
                'phrases' => ['account locked', 'locked out', 'recover my account', 'too many attempts'],
            ],
            [
                'id' => 'vaccines', 'category' => 'healthcare', 'intent' => FaqChatbotIntentRecognizer::HEALTH_ADVICE, 'kb_key' => 'vaccinations', 'flow_key' => 'policy',
                'phrases' => ['vaccine', 'vaccination', 'bakuna', 'booster', 'paano magpabakuna'],
            ],
            [
                'id' => 'pregnancy', 'category' => 'healthcare', 'intent' => $S, 'kb_key' => 'pregnancy', 'flow_key' => 'pain_sick',
                'phrases' => ['pregnant', 'pregnancy', 'buntis', 'prenatal', 'buntis ako'],
            ],
            [
                'id' => 'announcements', 'category' => 'general', 'intent' => FaqChatbotIntentRecognizer::ANNOUNCEMENT, 'kb_key' => 'announcements', 'flow_key' => 'services',
                'phrases' => ['announcement', 'announcements', 'balita', 'health advisory'],
            ],
            [
                'id' => 'notifications', 'category' => 'technical', 'intent' => $TECH, 'kb_key' => 'notifications_help', 'flow_key' => 'services',
                'phrases' => ['notification', 'notifications', 'wala notification', 'paalala', 'abiso'],
            ],
            [
                'id' => 'referrals', 'category' => 'bhw', 'intent' => FaqChatbotIntentRecognizer::REFERRAL, 'kb_key' => 'referrals', 'flow_key' => 'services',
                'phrases' => ['referral', 'referrals', 'pa-refer', 'need referral'],
            ],
            [
                'id' => 'nutrition', 'category' => 'healthcare', 'intent' => FaqChatbotIntentRecognizer::HEALTH_ADVICE, 'kb_key' => 'nutrition', 'flow_key' => 'policy',
                'phrases' => ['nutrition', 'what should i eat', 'healthy food', 'ano dapat kaonon'],
            ],
            [
                'id' => 'kids', 'category' => 'healthcare', 'intent' => $S, 'kb_key' => 'childrens_health', 'flow_key' => 'pain_sick',
                'phrases' => ['my child is sick', 'sakit ang bata', 'baby has fever', 'masakit ang anak ko'],
            ],
            [
                'id' => 'when_consult', 'category' => 'healthcare', 'intent' => $S, 'kb_key' => 'worry_symptoms', 'flow_key' => 'pain_sick',
                'phrases' => ['when should i see a doctor', 'should i consult', 'kailan magpatingin', 'san-o magpakonsulta'],
            ],
        ];
    }
}
