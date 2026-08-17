<?php
/**
 * Medical-domain boundary for the FAQ chatbot.
 * Runs in front of FAQ/KB/AI matching. Does not replace emergency, intent, or KB logic.
 */
final class FaqChatbotDomainScope
{
    public const GREETING = 'greeting';
    public const CONVERSATION = 'conversation';
    public const HELP_OPEN = 'help_open';
    public const MEDICAL = 'medical';
    public const AMBIGUOUS = 'ambiguous';
    public const OUT_OF_SCOPE = 'out_of_scope';

    /**
     * @return array{scope: string, stripped_text: string, confidence: float}
     */
    public static function classify(string $text, string $nlpText = ''): array
    {
        $raw = trim($text);
        $hay = self::normalize($raw . ' ' . $nlpText);
        $stripped = self::stripGreetingLead($raw);
        $strippedHay = self::normalize($stripped);

        if ($hay === '') {
            return self::pack(self::AMBIGUOUS, $raw, 0.4);
        }

        if (class_exists('FaqChatbotEmergencyDetector')) {
            $emergency = FaqChatbotEmergencyDetector::detect($raw . ' ' . $nlpText);
            if (!empty($emergency['is_emergency'])) {
                return self::pack(self::MEDICAL, $stripped !== '' ? $stripped : $raw, 0.99);
            }
        }

        if (self::isGreetingOnly($hay) || ($strippedHay === '' && self::isGreetingOnly($hay))) {
            return self::pack(self::GREETING, $raw, 0.96);
        }

        if (self::isConversationOnly($hay) && !self::hasMedicalContext($hay)) {
            return self::pack(self::CONVERSATION, $raw, 0.93);
        }

        $medical = self::medicalScore($strippedHay !== '' ? $strippedHay : $hay, $stripped !== '' ? $stripped : $raw);
        $off = self::offTopicScore($hay, $raw);

        if (self::isFalseMedicalPositive($hay)) {
            $medical = 0.0;
            $off += 3.0;
        }

        if ($medical >= 2.2 && $medical > ($off + 0.6)) {
            return self::pack(self::MEDICAL, $stripped !== '' ? $stripped : $raw, min(0.97, 0.55 + $medical / 8));
        }

        if ($off >= 2.4 && $off > $medical) {
            return self::pack(self::OUT_OF_SCOPE, $raw, min(0.95, 0.55 + $off / 8));
        }

        if (self::isHelpOpen($hay) && $medical < 2.2) {
            return self::pack(self::HELP_OPEN, $raw, 0.86);
        }

        if ($medical >= 1.4 && $off < 1.6) {
            return self::pack(self::MEDICAL, $stripped !== '' ? $stripped : $raw, 0.72);
        }

        if ($off >= 1.8 && $medical < 1.2) {
            return self::pack(self::OUT_OF_SCOPE, $raw, 0.8);
        }

        return self::pack(self::AMBIGUOUS, $raw, 0.5);
    }

    /**
     * @param array{scope?: string} $pack
     */
    public static function shouldIntercept(array $pack): bool
    {
        $scope = (string) ($pack['scope'] ?? '');
        return in_array($scope, [self::OUT_OF_SCOPE, self::AMBIGUOUS, self::HELP_OPEN], true);
    }

    public static function replyHtml(string $scope, string $lang = 'en'): string
    {
        $L = in_array($lang, ['en', 'fil', 'hil'], true) ? $lang : 'en';
        if ($scope === self::OUT_OF_SCOPE) {
            $copy = [
                'en' => "I'm designed to assist with health and medical-related concerns in medConnect. I can't help with that topic, but you can ask me about symptoms, health concerns, medicines, self-care, or when to seek medical attention.",
                'fil' => 'Nakalaan ako para tumulong sa mga alalahanin sa kalusugan at medikal sa medConnect. Hindi ko matutulungan ang paksang iyon, pero maaari kang magtanong tungkol sa sintomas, gamot, self-care, o kung kailan dapat magpatingin.',
                'hil' => 'Ginhanda ako para magbulig sa mga concern sa health kag medical sa medConnect. Indi ko matabangan ina nga topic, pero pwede ka magpamangkot parte sa sintomas, gamot, self-care, ukon kun san-o dapat magpacheckup.',
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

    private static function normalize(string $text): string
    {
        if (class_exists('FaqEmotionEngine')) {
            return FaqEmotionEngine::normalizeText($text);
        }
        $t = mb_strtolower(trim($text), 'UTF-8');
        $t = preg_replace('/[^\p{L}\p{N}\s\'-]/u', ' ', $t) ?? $t;
        return trim(preg_replace('/\s+/u', ' ', $t) ?? $t);
    }

    private static function isGreetingOnly(string $hay): bool
    {
        return (bool) preg_match(
            '/^(hi|hello|hey|helo|hola|yo|good\s+(morning|afternoon|evening|day)|kamusta|kumusta|musta|maayong(\s+(aga|hapon|gab-?i|adlaw))?|magandang(\s+(umaga|hapon|gabi))?)(\s+(po|gid|there|doc|doctor|doktor))*\s*$/u',
            $hay
        );
    }

    private static function isConversationOnly(string $hay): bool
    {
        return (bool) preg_match(
            '/^(thanks|thank\s+you|thankyou|salamat|maraming\s+salamat|damo\s+nga\s+salamat|ty|tysm|goodbye|bye|see\s+you|paalam|who\s+are\s+you|what\s+are\s+you|what\s+can\s+you\s+do|how\s+are\s+you|kamusta\s+ka|kumusta\s+ka|musta\s+ka|ano\s+ka|sino\s+ka)[\s!.?]*$/u',
            $hay
        );
    }

    private static function isHelpOpen(string $hay): bool
    {
        return (bool) preg_match(
            '/\b(can\s+you\s+help(\s+me)?|i\s+need\s+help|need\s+help|help\s+me|can\s+i\s+ask(\s+something)?|can\s+you\s+explain(\s+this)?|i\'?m\s+worried(\s+about\s+something)?|buligi\s+ko|tulungan\s+mo\s+ako|pwede\s+ko\s+magpamangkot)\b/u',
            $hay
        );
    }

    private static function hasMedicalContext(string $hay): bool
    {
        return self::medicalScore($hay, $hay) >= 2.2;
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
            && !preg_match('/\b(sakit|masakit|fever|cough|pain|hurt|ulo|tiyan|doctor|gamot)\b/u', $hay)) {
            return true;
        }
        return false;
    }

    private static function medicalScore(string $hay, string $raw): float
    {
        $score = 0.0;

        $strong = [
            '/\b(sakit|masakit|gasakit|ginasakit|nagasakit)\s+(ang\s+)?(ulo|mata|tiyan|dughan|dibdib|lawas|likod|tuhod|kalooy|throat|tungol)\b/u',
            '/\b(sakit|masakit)\s+(ulo|mata|tiyan|dughan|lawas)\s+(ko|akon|ako)\b/u',
            '/\b(dugo)\s+(ulo|ilong|baka)\s*(ko)?\b/u',
            '/\b(ginahilanat|hilanat|lagnat|kalintura|ginalagnat)\b/u',
            '/\b(i\s+have|i\'?ve\s+got|i\s+got)\s+(a\s+)?(fever|cough|headache|cold|flu|rash|diarrhea|vomit)/u',
            '/\bmy\s+(head|stomach|tummy|chest|eye|eyes|throat|back|skin|ear)\s+(hurts?|ache[sd]?|is\s+swollen|swollen)\b/u',
            '/\b(chest\s+hurts|chest\s+pain|budlay\s+ginhawa|hirap\s+huminga)\b/u',
            '/\b(what\s+should\s+i\s+do\s+for|treatment\s+for|first\s*aid|side\s+effects?|dosage|gamot|medicine|medication)\b/u',
            '/\b(appointment|mag-?book|pakonsulta|konsulta|checkup|triage|prescription|reseta|otp|login|register|medical\s+record)\b/u',
            '/\b(i\s+don\'?t\s+feel\s+well|not\s+feeling\s+well|masama\s+ang\s+pakiramdam|may\s+sakit\s+ako)\b/u',
            '/\b(gaulan|baha|bad\s+weather|indi\s+ko\s+makaguwa|wala\s+signal|forgot\s+(my\s+)?password|barangay\s+health|bhw)\b/u',
        ];
        foreach ($strong as $re) {
            if (preg_match($re, $hay) || preg_match($re, $raw)) {
                $score += 2.6;
            }
        }

        $body = '(head|ulo|mata|eye|eyes|tiyan|stomach|tummy|chest|dughan|dibdib|throat|skin|likod|back|ear|ilong)';
        $person = '(i|my|ako|ko|akon|ang\s+akin)';
        if (preg_match('/\b' . $person . '\b/u', $hay) && preg_match('/\b' . $body . '\b/u', $hay)
            && preg_match('/\b(hurt|hurts|pain|ache|sakit|masakit|swollen|swell|dugo|bleed|fever|cough|dizzy|nahilo|nalipong)\b/u', $hay)) {
            $score += 2.4;
        }

        $cues = [
            'fever', 'cough', 'headache', 'dizzy', 'nausea', 'vomit', 'diarrhea', 'allergy', 'pregnant', 'buntis',
            'symptom', 'injury', 'wound', 'rash', 'asthma', 'diabetes', 'medicine', 'doctor', 'nurse', 'hospital',
            'ubo', 'sipon', 'lagnat', 'gamot', 'doktor', 'sakit', 'masakit', 'pamatyag', 'first aid', 'self-care',
        ];
        $hits = 0;
        foreach ($cues as $cue) {
            if (preg_match('/\b' . preg_quote($cue, '/') . '\b/u', $hay)) {
                $hits++;
            }
        }
        if ($hits >= 2) {
            $score += 2.0;
        } elseif ($hits === 1 && preg_match('/\b(i|my|ako|ko|have|may|feel|feeling)\b/u', $hay)) {
            $score += 1.8;
        }

        return $score;
    }

    private static function offTopicScore(string $hay, string $raw): float
    {
        $score = 0.0;
        $patterns = [
            '/\bcapital\s+of\b/u',
            '/\b(write|make|compose)\s+(me\s+)?(a\s+|an\s+)?(poem|story|essay|song|joke)\b/u',
            '/\btell\s+me\s+a\s+joke\b/u',
            '/\b(who\s+won|basketball\s+game|soccer\s+game|football\s+game|score\s+of\s+the\s+game)\b/u',
            '/\b(how\s+do\s+i\s+(code|program|fix\s+my\s+computer|install\s+windows)|programming\s+tutorial|code\s+php|php\s+tutorial)\b/u',
            '/\b(what\s+is\s+the\s+weather|weather\s+(today|tomorrow|forecast)|weather\s+like)\b/u',
            '/\b(stock\s+market|cryptocurrency|bitcoin|movie\s+recommend|best\s+restaurant)\b/u',
            '/\b(how\s+do\s+i\s+fix\s+my\s+(computer|laptop|wifi|printer))\b/u',
        ];
        foreach ($patterns as $re) {
            if (preg_match($re, $hay) || preg_match($re, $raw)) {
                $score += 3.0;
            }
        }

        if (preg_match('/\b(poem|joke|basketball|programming|javascript|python\s+code|capital\s+of\s+france)\b/u', $hay)
            && !preg_match('/\b(sakit|fever|cough|doctor|appointment|gamot|ulo|tiyan)\b/u', $hay)) {
            $score += 2.2;
        }

        return $score;
    }
}
