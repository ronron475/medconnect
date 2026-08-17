<?php
/**
 * Rule-based intent recognition for the FAQ chatbot (PHP only).
 * Uses keyword/pattern scoring so mixed Hiligaynon + English still matches.
 */
final class FaqChatbotIntentRecognizer
{
    public const GREETING = 'greeting';
    public const THANKS = 'thanks';
    public const SMALL_TALK = 'small_talk';
    public const IDENTITY = 'identity';
    public const CAPABILITIES = 'capabilities';
    public const APOLOGY = 'apology';
    public const CLARIFICATION = 'clarification';
    public const APPOINTMENT = 'appointment';
    public const CONSULTATION = 'consultation';
    public const MEDICINE = 'medicine';
    public const DOCTOR = 'doctor';
    public const SYMPTOMS = 'symptoms';
    public const LOGIN = 'login';
    public const REGISTRATION = 'registration';
    public const PASSWORD_RESET = 'password_reset';
    public const OTP = 'otp';
    public const PROFILE = 'profile';
    public const SCHEDULE = 'schedule';
    public const EMERGENCY = 'emergency';
    public const FEEDBACK = 'feedback';
    public const GOODBYE = 'goodbye';
    public const HOSPITAL = 'hospital';
    public const RECORDS = 'medical_record';
    public const HEALTH_ADVICE = 'health_advice';
    public const FOLLOW_UP = 'follow_up';
    public const FAQ = 'faq';
    public const PRESCRIPTION = 'prescription';
    public const MENTAL_HEALTH = 'mental_health';
    public const FINANCIAL = 'financial';
    public const NAVIGATION = 'navigation';
    public const CONTACT = 'contact';
    public const EMOTIONAL_SUPPORT = 'emotional_support';
    public const TRIAGE = 'triage';
    public const REFERRAL = 'referral';
    public const ANNOUNCEMENT = 'announcement';
    public const CONNECTIVITY = 'connectivity';
    public const PRIVACY = 'privacy';
    public const WEATHER = 'weather_barrier';
    public const TRANSPORT = 'transport';
    public const REASSURANCE = 'reassurance';
    public const BHW = 'bhw';
    public const TECHNICAL = 'technical';
    public const GENERAL = 'general';

    /**
     * @return array{intent: string, confidence: float, flow_key: ?string}
     */
    public static function recognize(string $text): array
    {
        $norm = FaqEmotionEngine::normalizeText($text);
        if ($norm === '') {
            return ['intent' => self::GENERAL, 'confidence' => 0.0, 'flow_key' => null];
        }

        $emergency = FaqChatbotEmergencyDetector::detect($text);
        if ($emergency['is_emergency']) {
            return [
                'intent'     => self::EMERGENCY,
                'confidence' => 0.99,
                'flow_key'   => $emergency['flow'],
            ];
        }

        $lex = FaqChatbotConversationalIntents::match($text);
        if ($lex !== null && empty($lex['emergency']) && ($lex['score'] ?? 0) >= 2.2) {
            return [
                'intent'     => (string) $lex['intent'],
                'confidence' => min(0.96, 0.72 + ((float) $lex['score'] / 20)),
                'flow_key'   => (string) $lex['flow_key'],
            ];
        }

        /** @var list<array{0: string, 1: float, 2: string, 3: string}> $rules */
        $rules = [
            [self::GOODBYE, 0.92, '/\b(bye|goodbye|see\s+you|paalam|kita\s+ta|hangtod)\b/ui', 'welcome'],
            [self::GREETING, 0.93, '/^(hi|hello|hey|helo|hola|good\s+(morning|afternoon|evening|day)|kamusta|kumusta|musta|maayong(\s+(aga|hapon|gab-?i|adlaw))?|magandang(\s+(umaga|hapon|gabi))?)(\s+(po|gid|there|doc|doctor|doktor))*[\s!.?]*$/ui', 'welcome'],
            [self::THANKS, 0.92, '/\b(thank\s*you|thanks|salamat|maraming\s+salamat|damo\s+nga\s+salamat)\b/ui', 'gratitude'],
            [self::IDENTITY, 0.9, '/\b(who\s+are\s+you|what\s+are\s+you|are\s+you\s+(a\s+)?(bot|ai|robot)|sino\s+ka|ano\s+ka)\b/ui', 'welcome'],
            [self::CAPABILITIES, 0.9, '/\b(what\s+can\s+you\s+do|how\s+can\s+you\s+help|ano\s+ang\s+kaya\s+mo|your\s+features)\b/ui', 'services'],
            [self::SMALL_TALK, 0.88, '/\b(how\s+are\s+you|kamusta\s+ka|kumusta\s+ka|musta\s+ka)\b/ui', 'welcome'],
            [self::APOLOGY, 0.85, '/\b(sorry|i\s+apologize|pasensya|paumanhin)\b/ui', 'welcome'],
            [self::CLARIFICATION, 0.86, '/\b(i\s+don\'?t\s+understand|indi\s+ko\s+maintindihan|explain\s+again|can\s+you\s+explain)\b/ui', 'clarify'],
            [self::OTP, 0.9, '/\b(otp|one\s*time\s*pin|verification\s*code|wala\s+(ko\s+)?otp|wala\s+nag-?abot\s+otp)\b/ui', 'signin'],
            [self::PASSWORD_RESET, 0.9, '/\b(forgot\s+(my\s+)?password|reset\s+(my\s+)?password|nakalimtan.*(password)|nakalimot.*(password)|nalipat.*(password)|i-?reset\s+.*password)\b/ui', 'reset'],
            [self::REGISTRATION, 0.88, '/\b(register|sign\s*up|create\s+account|rehistro|magrehistro|bagong\s+account|verify\s+email)\b/ui', 'register'],
            [self::LOGIN, 0.88, '/\b(login|log\s*in|sign\s*in|sulod|indi\s+(ko\s+)?makasulod|wala\s+ko\s+ka\s*login|indi\s+ko\s+ka\s*login|can\'?t\s+login|cannot\s+login|hindi\s+ako\s+makalogin)\b/ui', 'signin'],
            [self::BHW, 0.9, '/\b(bhw|barangay\s+health\s+worker|health\s+worker)\b/ui', 'services'],
            [self::TECHNICAL, 0.86, '/\b(website\s+not\s+loading|page\s+stuck|blank\s+page|button\s+not\s+working|error\s+message|loading\s+forever)\b/ui', 'services'],
            [self::PROFILE, 0.86, '/\b(update\s+(my\s+)?profile|edit\s+profile|change\s+(my\s+)?(name|address|contact))\b/ui', 'services'],
            [self::TRIAGE, 0.9, '/\b(ai\s+triage|triage|symptom\s+checker)\b/ui', 'services'],
            [self::APPOINTMENT, 0.86, '/\b(appointment|book|mag-book|maka-book|schedule\s+visit|gusto\s+ko\s+mag\s*book|pa\s*checkup|magpakonsulta|pakonsulta|diin\s+ko\s+maka\s*book|need\s+doctor|want\s+consultation)\b/ui', 'appointment'],
            [self::SCHEDULE, 0.84, '/\b(schedule|oras|office\s+hours|bukas|sarado|clinic\s+hours|doctor\s+schedule)\b/ui', 'hours'],
            [self::CONSULTATION, 0.86, '/\b(video|consultation|konsultasyon|telemedicine|online\s+consult)\b/ui', 'video'],
            [self::REFERRAL, 0.86, '/\b(referral|referrals|pa-refer|i-refer)\b/ui', 'services'],
            [self::ANNOUNCEMENT, 0.84, '/\b(announcement|announcements|balita|health\s+advisory)\b/ui', 'services'],
            [self::CONTACT, 0.86, '/\b(city\s+health|contact\s+(support|cho)|tawag\s+sa\s+(support|cho)|cho)\b/ui', 'contact'],
            [self::NAVIGATION, 0.8, '/\b(how\s+do\s+i\s+use|paano\s+gamiton|where\s+(is|do)|diin\s+ko|navigate|help\s+topics|which\s+page)\b/ui', 'services'],
            [self::CONNECTIVITY, 0.9, '/\b(wala\s+signal|nadula\s+signal|gadula.{0,16}signal|hinay\s+signal|wala\s+internet|putol.{0,12}connection|ga.?lag|di\s+ko\s+ka.?video|wala\s+ko\s+kabati|indi\s+ko\s+(makita|mabatian|marinig)|camera\s+(doesn\'?t\s+work|indi\s+naga\s+work)|can\'?t\s+hear\s+(the\s+)?doctor|wala\s+doctor\s+sa\s+video)\b/ui', 'video'],
            [self::PRIVACY, 0.88, '/\b(masaligan\s+ni\s+bala|safe\s+bala|confidential\s+bala|data\s+privacy|makita\s+bala\s+ni\s+sang\s+iban|tinuod\s+bala)\b/ui', 'policy'],
            [self::WEATHER, 0.86, '/\b(gaulan|grabe\s+ang\s+ulan|baha|bad\s+weather|indi\s+ko\s+makaguwa)\b/ui', 'distress_support'],
            [self::TRANSPORT, 0.86, '/\b(wala\s+ko\s+masakyan|layo\s+amon|budlay\s+magkadto|wala\s+ko\s+pamasahe|indi\s+ko\s+makakadto)\b/ui', 'distress_support'],
            [self::REASSURANCE, 0.84, '/\b(safe\s+bala|sigurado\s+bala|trust|reliable|legit|masaligan)\b/ui', 'policy'],
            [self::FINANCIAL, 0.88, '/\b(no\s+money|i\s+have\s+no\s+money|cannot\s+afford|can\'?t\s+afford|wala\s+(ko|ako)\s+kwarta|walang\s+pera|financial|wala\s+budget|libre\s+nga\s+consultation|indi\s+ko\s+kaya\s+magbayad|too\s+expensive|im\s+broke)\b/ui', 'financial'],
            [self::MENTAL_HEALTH, 0.87, '/\b(anxiety|depression|mental\s+health|hopeless|panic|stress(ed)?|lonely|overwhelmed|burnout|grief|ginakulbaan|kasubo|wala\s+paglaum|di\s+ko\s+na\s+kaya|mabuhi\s+pa\s+ko|wala\s+na\s+pulos|mag.?untat)\b/ui', 'distress_support'],
            [self::EMOTIONAL_SUPPORT, 0.9, '/\b(need\s+someone|talk\s+to\s+someone|afraid|scared|i\s+am\s+scared|nahadlok|ginakulbaan|nabalaka|natatakot|kinakabahan|buligi\s+ko|help\s+me\s+please|can\'?t\s+sleep|cannot\s+sleep|indi\s+ko\s+ka\s*tulog|family\s+problem|homesick|relationship\s+problem)\b/ui', 'distress_support'],
            [self::PRESCRIPTION, 0.9, '/\b(prescription|reseta|digital\s+prescription)\b/ui', 'prescriptions'],
            [self::MEDICINE, 0.9, '/\b(gamot|medicine|medication|bulong|tambal)\b/ui', 'prescriptions'],
            [self::DOCTOR, 0.82, '/\b(doctor|physician|provider|doktor|nars|nurse)\b/ui', 'services'],
            [self::HOSPITAL, 0.85, '/\b(hospital|ospital|emergency\s+room|\ber\b)\b/ui', 'contact'],
            [self::RECORDS, 0.86, '/\b(medical\s+record|health\s+summary|medical\s+history|emr)\b/ui', 'records'],
            [self::SYMPTOMS, 0.84, '/\b(symptom|masakit|sakit|gasakit|ginasakit|fever|lagnat|hilanat|ubo|sipon|headache|dizzy|nalipong|nahilo|chest\s+pain|dughan|pamatyagon|budlay|pregnan(?:t|cy)|buntis|vaccinations?|vaccines?|bakuna)\b/ui', 'pain_sick'],
            [self::HEALTH_ADVICE, 0.78, '/\b(should\s+i|what\s+should|health\s+advice|treatment|gamot\s+sa|prevention|nutrition|exercise|first\s*aid|vaccinations?)\b/ui', 'policy'],
            [self::FOLLOW_UP, 0.8, '/\b(follow\s*up|followup|sunod\s+nga|balik\s+consult)\b/ui', 'appointment'],
            [self::FAQ, 0.75, '/\b(what\s+is\s+medconnect|about\s+medconnect|faq)\b/ui', 'services'],
            [self::FEEDBACK, 0.75, '/\b(feedback|suggestion|complaint|reklamo)\b/ui', 'gratitude'],
        ];

        foreach ($rules as [$intent, $conf, $pattern, $flow]) {
            if (preg_match($pattern, $norm)) {
                return ['intent' => $intent, 'confidence' => $conf, 'flow_key' => $flow];
            }
        }

        return ['intent' => self::GENERAL, 'confidence' => 0.35, 'flow_key' => null];
    }
}
