<?php
/**
 * Medical-domain boundary for the FAQ chatbot.
 *
 * Greeting / conversation helpers and last-resort healthcare cues.
 * Production routing is: greeting → emergency → dataset → Gemini classification.
 */
final class FaqChatbotDomainScope
{
    public const GREETING = 'greeting';
    public const CONVERSATION = 'conversation';
    public const HELP_OPEN = 'help_open';
    public const MEDICAL = 'medical';
    public const AMBIGUOUS = 'ambiguous';
    public const OUT_OF_SCOPE = 'out_of_scope';

    public const RESPONSE_GREETING = 'GREETING';
    public const RESPONSE_MEDICAL_DATASET = 'MEDICAL_DATASET';
    public const RESPONSE_MEDICAL_GEMINI = 'MEDICAL_GEMINI';
    public const RESPONSE_MEDICAL_CLARIFICATION = 'MEDICAL_CLARIFICATION';
    public const RESPONSE_OUT_OF_SCOPE = 'OUT_OF_SCOPE';

    /**
     * @return array{scope: string, stripped_text: string, confidence: float}
     */
    public static function classify(string $text, string $nlpText = ''): array
    {
        $raw = trim($text);
        $hay = self::normalize($raw . ' ' . $nlpText);
        $rawHay = self::normalize($raw);
        $focus = self::healthcareFocusText($raw, $nlpText);
        $focusHay = self::normalize($focus);

        if ($hay === '' && $rawHay === '') {
            return self::pack(self::OUT_OF_SCOPE, $raw, 0.4);
        }

        if (self::isGreetingOnly($rawHay) || self::isGreetingOnly($hay) || ($focusHay === '' && self::isGreetingOnly($hay))) {
            return self::pack(self::GREETING, $raw, 0.96);
        }

        if ((self::isHelpOpen($rawHay) || self::isHelpOpen($hay)) && !self::isHealthcareRelated($raw, $nlpText)) {
            return self::pack(self::HELP_OPEN, $raw, 0.9);
        }

        if ((self::isConversationOnly($rawHay) || self::isConversationOnly($hay)) && !self::isHealthcareRelated($raw, $nlpText)) {
            return self::pack(self::CONVERSATION, $raw, 0.93);
        }

        if (self::isHealthcareRelated($raw, $nlpText)) {
            $medicalRaw = $focus !== '' ? $focus : $raw;
            return self::pack(self::MEDICAL, $medicalRaw, 0.9);
        }

        return self::pack(self::OUT_OF_SCOPE, $raw, 0.92);
    }

    /**
     * Greetings and short help openings — not out-of-scope, not Gemini work.
     */
    public static function isAllowedOpening(string $text): bool
    {
        $hay = self::normalize($text);
        return self::isGreetingOnly($hay) || self::isConversationOnly($hay) || self::isHelpOpen($hay);
    }

    /**
     * True when the message has a legitimate healthcare / health-service intent.
     * Money, time, weather, cooking, trivia, and identity questions stay false
     * unless a real health cue is also present.
     */
    public static function isHealthcareRelated(string $text, string $nlpText = ''): bool
    {
        $raw = trim($text);
        $hay = self::normalize($raw . ' ' . $nlpText);
        if ($hay === '') {
            return false;
        }
        if (self::isGreetingOnly($hay) || self::isConversationOnly($hay)) {
            return false;
        }
        if (self::isFalseMedicalPositive($hay)) {
            return false;
        }
        $health = self::healthcareEvidence($hay, $raw);
        if ($health >= 2.0) {
            return true;
        }
        return false;
    }

    /**
     * Intercept clearly unrelated questions. Greetings are not intercepted.
     *
     * @param array{scope?: string} $pack
     */
    public static function shouldIntercept(array $pack): bool
    {
        return ($pack['scope'] ?? '') === self::OUT_OF_SCOPE;
    }

    public static function isClearlyNonHealthcare(string $text, string $nlpText = ''): bool
    {
        if (self::isAllowedOpening($text) && !self::isHealthcareRelated($text, $nlpText)) {
            return false;
        }
        return !self::isHealthcareRelated($text, $nlpText);
    }

    /**
     * Prefer the healthcare portion of mixed messages (greeting + symptom + trivia).
     */
    public static function healthcareFocusText(string $text, string $nlpText = ''): string
    {
        $raw = trim($text);
        $stripped = self::stripGreetingLead($raw);
        $base = $stripped !== '' ? $stripped : $raw;
        $focused = self::keepHealthcareSentences($base);
        $hay = self::normalize($focused . ' ' . $nlpText);
        if ($focused !== '' && self::medicalScore($hay, $focused) >= 1.2) {
            return $focused;
        }
        return $base !== '' ? $base : $raw;
    }

    public static function replyHtml(string $scope, string $lang = 'en'): string
    {
        $L = in_array($lang, ['en', 'fil', 'hil'], true) ? $lang : 'en';
        if ($scope === self::OUT_OF_SCOPE) {
            $copy = [
                'en' => "I'm sorry, I can't answer that because it is outside the scope of the medConnect Assistant. I can only assist with healthcare concerns and medConnect-related services.",
                'fil' => 'Paumanhin, hindi ko masagot iyan dahil wala ito sa saklaw ng medConnect Assistant. Makakatulong lang ako sa mga alalahanin sa kalusugan at mga serbisyong may kaugnayan sa medConnect.',
                'hil' => 'Pasensya, indi ko masabat sina kay wala ini sa saklaw sang medConnect Assistant. Makatabang lang ako sa mga health concern kag mga serbisyo nga may kaangtan sa medConnect.',
            ];
            return '<p>' . htmlspecialchars($copy[$L], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        }

        $copy = [
            'en' => "I'm here to help with health concerns. Could you tell me what symptom, health problem, or medical concern you're experiencing?",
            'fil' => 'Nandito ako para tumulong sa mga alalahanin sa kalusugan. Ano pong sintomas, problema sa katawan, o medical concern ang nais mong itanong?',
            'hil' => 'Diri ako para magbulig sa health concerns. Ano nga sintomas, problema sa lawas, ukon medical concern ang gusto mo pamangkuton?',
        ];
        return '<p>' . htmlspecialchars($copy[$L], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
    }

    /**
     * Safe acknowledgment when a health concern is unmatched and Gemini is unavailable.
     * Does not diagnose and does not show generic account/navigation menus.
     */
    public static function unmatchedHealthcareHtml(string $lang = 'en'): string
    {
        $L = in_array($lang, ['en', 'fil', 'hil'], true) ? $lang : 'en';
        $copy = [
            'en' => "I understand this sounds like a health concern. I can't diagnose or prescribe, but I can share general guidance and help you book a medConnect consultation. If symptoms are sudden, severe, or include trouble breathing, chest pain, fainting, or heavy bleeding, seek emergency care or call 911.",
            'fil' => 'Naiintindihan ko na ito ay health concern. Hindi ako nagda-diagnose o nagre-reseta, pero makakapagbigay ako ng pangkalahatang gabay at matutulungan kitang mag-book ng konsultasyon sa medConnect. Kung biglaan, malala, o may hirap sa paghinga, sakit sa dibdib, pagkahimatay, o mabigat na pagdurugo, magpunta sa emergency o tumawag sa 911.',
            'hil' => 'Naintiendihan ko nga health concern ini. Indi ako nagadiagnose ukon nagareseta, pero makahatag ako sang general nga giya kag matabangan ko ikaw mag-book sang konsultasyon sa medConnect. Kon bigla, grabe, ukon may budlay ginhawa, sakit sa dughan, pagkalipong, ukon grabeng dugo, magpangayo emergency care ukon tawag sa 911.',
        ];
        return '<p>' . htmlspecialchars($copy[$L], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
    }

    public static function stripGreetingLead(string $text): string
    {
        $t = trim($text);
        $t = preg_replace(
            '/^(hi|hello|hey|helo|hola|yo|good\s+(morning|afternoon|evening|day)|kamusta|kumusta|musta|maayong\s+(aga|hapon|gab-?i|adlaw)|magandang\s+(umaga|hapon|gabi))(\s+(po|gid|there|doc|doctor|doktor))*\s*[,!.:-]+\s*/ui',
            '',
            $t
        ) ?? $t;
        return trim($t);
    }

    /** @return array{scope: string, stripped_text: string, confidence: float} */
    private static function pack(string $scope, string $stripped, float $confidence): array
    {
        return [
            'scope'          => $scope,
            'stripped_text'  => $stripped,
            'confidence'     => $confidence,
        ];
    }

    private static function keepHealthcareSentences(string $text): string
    {
        $t = trim($text);
        if ($t === '') {
            return '';
        }
        $parts = preg_split('/(?<=[.?!])\s+|(?<=,)\s+(?=by\s+the\s+way|anyway)/ui', $t) ?: [$t];
        if (count($parts) < 2) {
            if (preg_match('/^(.*?)[,.]?\s+(by\s+the\s+way|anyway)\b[,.]?\s+(.+)$/ui', $t, $m)) {
                $head = trim($m[1]);
                $tail = trim($m[3]);
                $headMed = self::medicalScore(self::normalize($head), $head);
                $tailOff = self::offTopicScore(self::normalize($tail), $tail);
                $tailMed = self::medicalScore(self::normalize($tail), $tail);
                if ($headMed >= 1.2 && $tailOff >= 2.4 && $tailMed < 1.2) {
                    return $head;
                }
                if ($tailMed >= 1.2 && self::offTopicScore(self::normalize($head), $head) >= 2.4) {
                    return $tail;
                }
            }
            return $t;
        }
        $kept = [];
        foreach ($parts as $part) {
            $part = trim($part, " \t\n\r\0\x0B,");
            if ($part === '' || preg_match('/^(by\s+the\s+way|anyway)$/ui', $part)) {
                continue;
            }
            $part = preg_replace('/^(by\s+the\s+way|anyway)\b[,.]?\s*/ui', '', $part) ?? $part;
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $hay = self::normalize($part);
            $off = self::offTopicScore($hay, $part);
            $med = self::medicalScore($hay, $part);
            if ($off >= 2.4 && $med < 1.2) {
                continue;
            }
            $kept[] = $part;
        }
        return $kept !== [] ? implode(' ', $kept) : $t;
    }

    private static function normalize(string $text): string
    {
        if (class_exists('FaqEmotionEngine')) {
            $t = FaqEmotionEngine::normalizeText($text);
        } else {
            $t = mb_strtolower(trim($text), 'UTF-8');
        }
        $t = preg_replace('/[^\p{L}\p{N}\s\'-]/u', ' ', $t) ?? $t;
        return trim(preg_replace('/\s+/u', ' ', $t) ?? $t);
    }

    private static function isGreetingOnly(string $hay): bool
    {
        return (bool) preg_match(
            '/^(hi|hello|hey|helo|hola|yo|good\s+(morning|afternoon|evening|day)|kamusta|kumusta|musta|maayong(\s+(aga|hapon|gab-?i|adlaw))?|magandang(\s+(umaga|hapon|gabi))?|can\s+you\s+help(\s+me)?)(\s+(po|gid|there|doc|doctor|doktor))*\s*$/u',
            $hay
        );
    }

    private static function isConversationOnly(string $hay): bool
    {
        return (bool) preg_match(
            '/^(thanks|thank\s+you|thankyou|salamat|maraming\s+salamat|damo\s+nga\s+salamat|ty|tysm|goodbye|bye|see\s+you|paalam|what\s+can\s+you\s+do|how\s+are\s+you|kamusta\s+ka|kumusta\s+ka|musta\s+ka)[\s!.?]*$/u',
            $hay
        );
    }

    private static function isHelpOpen(string $hay): bool
    {
        return (bool) preg_match(
            '/^(can\s+you\s+help(\s+me)?|i\s+need\s+help|need\s+help|help\s+me|can\s+i\s+ask(\s+something)?|can\s+you\s+explain(\s+this)?|i\'?m\s+worried(\s+about\s+something)?|buligi\s+ko|tulungan\s+mo\s+ako|pwede\s+ko\s+magpamangkot)[\s!.?]*$/u',
            $hay
        );
    }

    private static function hasMedicalContext(string $hay): bool
    {
        return self::healthcareEvidence($hay, $hay) >= 2.0;
    }

    /**
     * Positive healthcare evidence. A single incidental word is not enough
     * unless it is a strong symptom/service/medication cue.
     */
    private static function healthcareEvidence(string $hay, string $raw): float
    {
        $score = 0.0;
        $strong = [
            '/\b(sakit|masakit|gasakit|ginasakit|nagasakit|ga\s+sakit)\s+(gid\s+)?(ang\s+)?(ulo|mata|tiyan|dughan|dibdib|lawas|likod|tuhod|throat|tungol)\b/u',
            '/\b(sakit|masakit|gasakit)\s+(gid\s+)?(ulo|mata|tiyan|dughan|lawas|likod)\s*(ko|akon|ako|q)?\b/u',
            '/\b(ginahilo|nahihilo|ginahilo\s+ko|nahihilo\s+ako|gakubo|ga\s+kubo|nagaubo)\b/u',
            '/\b(dugo)\s+(ulo|ilong|baka)\s*(ko)?\b/u',
            '/\b(ginahilanat|hilanat|lagnat|kalintura|ginalagnat|may\s+hilanat|may\s+lagnat)\b/u',
            '/\b(i\s+have|i\'?ve\s+got|i\s+got)\s+(a\s+)?(fever|cough|headache|cold|flu|rash|diarrhea|vomit)/u',
            '/\bmy\s+(head|stomach|tummy|chest|eye|eyes|throat|back|skin|ear)\s+(hurts?|ache[sd]?|is\s+swollen|swollen)\b/u',
            '/\b(chest\s+hurts|chest\s+pain|sakit\s+dughan|masakit\s+akon\s+dughan|budlay\s+ginhawa|lisod\s+ginhawa|lisud\s+ginhawa|hirap\s+huminga|difficulty\s+breathing)\b/u',
            '/\b(first\s*aid|side\s+effects?|dosage|gamot|medicine|medication|tambal|paracetamol|antibiotics?|prescription|reseta)\b/u',
            '/\b(appointment|mag-?book|pakonsulta|konsulta|checkup|triage|medical\s+record|clinic|hospital|ospital|health\s+center|city\s+health|health\s+office|bhw|barangay\s+health|login|log\s*in|sign\s*in|register|otp|forgot\s+(my\s+)?password|reset\s+password)\b/u',
            '/\b(pila\s+ang\s+bayad|consultation\s+(fee|cost)|magkano\s+ang\s+(consult|consultation|checkup))\b/u',
            '/\b(i\s+don\'?t\s+feel\s+well|not\s+feeling\s+well|masama\s+ang\s+pakiramdam|may\s+sakit\s+ako)\b/u',
            '/\b(i\s+don\'?t\s+feel\s+right|don\'?t\s+feel\s+right(\s+today)?)\b/u',
            '/\b(i\s+feel\s+(weak|strange|off|weird)|feel\s+weak\s+and\s+strange|my\s+body\s+feels\s+(very\s+)?weak)\b/u',
            '/\b(something\s+feels\s+wrong(\s+with\s+(my\s+)?body)?|something\s+is\s+wrong\s+with\s+(me|my\s+body))\b/u',
            '/\b(i\s+have\s+been\s+feeling\s+sick|feeling\s+sick\s+lately|i\s+feel\s+sick|i\'?m\s+sick)\b/u',
            '/\b(i\s+feel\s+pain\s+after\s+eating|pain\s+after\s+eating|feel(ing)?\s+dizzy|nahilo|nalipong|hilo)\b/u',
            '/\b(ginalain|nagasakit|ginakulbaan|ano\s+tambal|ano\s+ubrahon\s+ko\s+sa|what\s+medicine|when\s+should\s+i\s+see\s+a\s+doctor)\b/u',
            '/\b(diabetes|hypertension|asthma|anemia|dengue|infection|allergy|allergies|pregnant|buntis|mental\s+health)\b/u',
            '/\b(pagsusuka|vomiting|diarrhea|diarrhoea|samad|wound|bleeding|nagsuka|ubo|sip-?on|sipon|cough|fever|headache)\b/u',
            '/\b(doctor|doktor|nurse|nars|consultation|medconnect\s+consultation)\b/u',
        ];
        foreach ($strong as $re) {
            if (preg_match($re, $hay) || preg_match($re, $raw)) {
                $score += 2.6;
            }
        }

        $body = '(head|ulo|mata|eye|eyes|tiyan|stomach|tummy|chest|dughan|dibdib|throat|skin|likod|back|ear|ilong|body|lawas)';
        $person = '(i|my|ako|ko|akon|ang\s+akin)';
        if (preg_match('/\b' . $person . '\b/u', $hay) && preg_match('/\b' . $body . '\b/u', $hay)
            && preg_match('/\b(hurt|hurts|pain|ache|sakit|masakit|swollen|swell|dugo|bleed|fever|cough|dizzy|nahilo|nalipong|weak|strange|wrong|sick)\b/u', $hay)) {
            $score += 2.4;
        }

        $cues = [
            'fever', 'cough', 'headache', 'dizzy', 'nausea', 'vomit', 'diarrhea', 'allergy', 'pregnant', 'buntis',
            'symptom', 'injury', 'wound', 'rash', 'asthma', 'diabetes', 'medicine', 'doctor', 'nurse', 'hospital',
            'ubo', 'sipon', 'lagnat', 'gamot', 'tambal', 'doktor', 'sakit', 'masakit', 'pamatyag', 'first aid',
            'self-care', 'nauseous', 'swollen', 'bleeding', 'hilanat', 'dughan', 'ginhawa', 'triage', 'clinic',
            'appointment', 'prescription', 'reseta', 'checkup', 'konsulta',
        ];
        $hits = 0;
        foreach ($cues as $cue) {
            if (preg_match('/\b' . preg_quote($cue, '/') . '\b/u', $hay)) {
                $hits++;
            }
        }
        if ($hits >= 1) {
            $score += ($hits >= 2) ? 2.0 : 2.2;
        }

        return $score;
    }

    private static function medicalScore(string $hay, string $raw): float
    {
        return self::healthcareEvidence($hay, $raw);
    }

    private static function isFalseMedicalPositive(string $hay): bool
    {
        if (preg_match('/\b(my|the|this|a)\s+(computer|laptop|pc|phone|car|engine|printer|router)\s+(has|have|got|is|keeps)\b.{0,24}\b(headache|fever|sick|hurt|hurts|pain|sakit)\b/u', $hay)) {
            return true;
        }
        if (preg_match('/\b(computer|laptop|pc|software|program|programming|code|coding)\b/u', $hay)
            && preg_match('/\b(headache|fever|sick|hurt|hurts|pain|sakit)\b/u', $hay)
            && !preg_match('/\b(ulo|tiyan|dughan|mata|stomach|chest|throat|i\s+have|masakit|ginahilanat)\b/u', $hay)) {
            return true;
        }
        if (preg_match('/\b(studying|study|course|major|degree|computer\s+science|information\s+technology)\b/u', $hay)
            && !preg_match('/\b(sakit|masakit|fever|cough|pain|hurt|ulo|tiyan|doctor|gamot|feel|feeling|body|sick)\b/u', $hay)) {
            return true;
        }
        return false;
    }

    private static function offTopicScore(string $hay, string $raw): float
    {
        $score = 0.0;
        $patterns = [
            '/\bcapital\s+of\b/u',
            '/\b(write|make|compose)\s+(me\s+)?(a\s+|an\s+)?(poem|story|essay|song|joke|business\s+plan)\b/u',
            '/\btell\s+me\s+a\s+joke\b/u',
            '/\b(who\s+won|basketball\s+game|soccer\s+game|football\s+game|score\s+of\s+the\s+game)\b/u',
            '/\b(how\s+do\s+i\s+(code|program|fix\s+my\s+computer|install\s+windows)|programming\s+tutorial|code\s+php|php\s+tutorial|help\s+me\s+code(\s+php)?)\b/u',
            '/\b(what\s+is\s+the\s+weather|what\'?s\s+the\s+weather|weather\s+(today|tomorrow|forecast)|weather\s+like|anong\s+weather|ano\s+oras)\b/u',
            '/\b(stock\s+market|cryptocurrency|bitcoin|movie\s+recommend|best\s+restaurant|business\s+plan)\b/u',
            '/\b(how\s+do\s+i\s+fix\s+my\s+(computer|laptop|wifi|printer))\b/u',
            '/\b(explain\s+(cryptocurrency|bitcoin|blockchain)|what\s+is\s+cryptocurrency)\b/u',
            '/\b(wala\s+kwarta|may\s+kwarta|bigyan\s+mo\s+ako\s+pera|may\s+pera\s+ba|magluto|sino\s+ka|who\s+are\s+you|ano\s+ka)\b/u',
            '/\b(football|basketball|movie|anime|music|facebook|programming|coding|school\s+assignment)\b/u',
        ];
        foreach ($patterns as $re) {
            if (preg_match($re, $hay) || preg_match($re, $raw)) {
                $score += 3.0;
            }
        }

        if (preg_match('/\b(poem|joke|basketball|programming|javascript|python\s+code|capital\s+of\s+(france|japan))\b/u', $hay)
            && !preg_match('/\b(sakit|fever|cough|doctor|appointment|gamot|ulo|tiyan|dizzy|feel|feeling|body|sick)\b/u', $hay)) {
            $score += 2.2;
        }

        return $score;
    }
}
