<?php
/**
 * Universal health-complaint domain detector for production NLP.
 *
 * Detects meaningful health complaints (symptom + body part + duration, etc.)
 * across Hiligaynon / Tagalog / English / mixed / slang / light misspellings.
 *
 * Does NOT diagnose. Does NOT triage. Does NOT replace ClinicalTriageEngine.
 * Optional fuzzy prep uses NlpStep3DemoAnswerFuzzy when available.
 */
final class HealthComplaintDomainDetector
{
    public const DOMAIN_HEALTH = 'HEALTH_RELATED';
    public const DOMAIN_MEDCONNECT = 'MEDCONNECT_RELATED';
    public const DOMAIN_OOS = 'OUT_OF_SCOPE';
    public const DOMAIN_UNCLEAR = 'UNCLEAR';
    public const DOMAIN_GREETING = 'GREETING';

    public const CONF_HIGH = 'HIGH';
    public const CONF_MEDIUM = 'MEDIUM';
    public const CONF_LOW = 'LOW';

    public const ROUTE_NLP = 'ORIGINAL_NLP_FLOW';
    public const ROUTE_OOS = 'OUT_OF_SCOPE';
    public const ROUTE_GEMINI = 'GEMINI_FALLBACK';
    public const ROUTE_MEDCONNECT = 'MEDCONNECT_FLOW';
    public const ROUTE_GREETING = 'GREETING_FLOW';

    /**
     * @return array{
     *   domain: string,
     *   confidence: string,
     *   score: float,
     *   health_related: bool,
     *   routing: string,
     *   signals: list<array{type:string,value:string}>,
     *   relationships: list<string>,
     *   normalized: string,
     *   reason: string
     * }
     */
    public static function detect(string $text): array
    {
        $raw = trim($text);
        if ($raw === '') {
            return self::pack(
                self::DOMAIN_UNCLEAR,
                self::CONF_LOW,
                0.0,
                false,
                self::ROUTE_GEMINI,
                [],
                [],
                '',
                'Empty input'
            );
        }

        if (class_exists('FaqChatbotDomainScope') && FaqChatbotDomainScope::isAllowedOpening($raw)) {
            return self::pack(
                self::DOMAIN_GREETING,
                self::CONF_HIGH,
                0.2,
                false,
                self::ROUTE_GREETING,
                [['type' => 'opening', 'value' => self::normalize($raw)]],
                [],
                self::normalize($raw),
                'Greeting / conversational opening'
            );
        }

        // 1) Existing normalization + synonym/fuzzy correction FIRST (not keyword-only).
        $working = self::normalize($raw);
        $fuzzyPrep = null;
        if (class_exists('HiligaynonTextNormalizer')) {
            try {
                $hilNorm = HiligaynonTextNormalizer::normalize($raw);
                if ($hilNorm !== '') {
                    $working = self::normalize($hilNorm);
                }
            } catch (Throwable) {
                // keep prior working text
            }
        }
        if (class_exists('NlpStep3DemoAnswerFuzzy')) {
            try {
                $fuzzyPrep = NlpStep3DemoAnswerFuzzy::prepare($working);
                $corrected = trim((string) ($fuzzyPrep['corrected'] ?? ''));
                if ($corrected !== '') {
                    $working = self::normalize($corrected);
                }
            } catch (Throwable) {
                $fuzzyPrep = null;
            }
        }

        $signals = self::extractSignals($working, $raw);
        // Also scan original (pre-fuzzy) so we do not lose local spellings like kasakit.
        foreach (self::extractSignals(self::normalize($raw), $raw) as $sig) {
            if (!self::signalExists($signals, $sig['type'], $sig['value'])) {
                $signals[] = $sig;
            }
        }

        if (is_array($fuzzyPrep) && !empty($fuzzyPrep['corrections']) && is_array($fuzzyPrep['corrections'])) {
            foreach ($fuzzyPrep['corrections'] as $corr) {
                $from = (string) ($corr['from'] ?? '');
                $to = (string) ($corr['to'] ?? '');
                if ($from === '' || $to === '') {
                    continue;
                }
                $signals[] = ['type' => 'fuzzy_correction', 'value' => $from . '→' . $to];
                // Corrected clinical tokens count as recovered meaning.
                if (preg_match('/\b(sakit|kasakit|masakit|pain|ache|headache|ulo|mata|eye|tiyan|stomach|cough|ubo|fever|hilanat|hubag|swollen|dugo|dizzy|hilo)\b/u', $to)) {
                    $type = preg_match('/\b(ulo|mata|eye|tiyan|stomach|dughan|chest|likod|back|head)\b/u', $to) ? 'body_part' : 'symptom';
                    if (!self::signalExists($signals, $type, $to)) {
                        $signals[] = ['type' => $type, 'value' => $to];
                    }
                }
            }
        }

        // Existing medical dictionary + symptom dataset matches.
        foreach (self::dictionaryHits($working) as $hit) {
            if (!self::signalExists($signals, $hit['type'], $hit['value'])) {
                $signals[] = $hit;
            }
        }
        foreach (self::datasetSymptomHits($working, $raw) as $hit) {
            if (!self::signalExists($signals, $hit['type'], $hit['value'])) {
                $signals[] = $hit;
            }
        }

        $relationships = self::detectRelationships($signals);
        $score = self::score($signals, $relationships, $working, $raw);

        if (class_exists('FaqChatbotDomainScope') && FaqChatbotDomainScope::isHealthcareRelated($raw)) {
            $score = max($score, 3.2);
            if (!self::hasSignalType($signals, 'existing_healthcare_flag')) {
                $signals[] = ['type' => 'existing_healthcare_flag', 'value' => 'FaqChatbotDomainScope'];
            }
        }
        if (class_exists('FaqChatbotDomainScope') && FaqChatbotDomainScope::isHealthcareRelated($working)) {
            $score = max($score, 3.2);
        }

        $hasClinical = self::hasClinicalComplaintSignals($signals) || self::hasStrongRelationship($relationships);
        $looksLikeNoise = class_exists('FaqChatbotDomainScope')
            && (FaqChatbotDomainScope::isLikelyNonsenseOrPrank($raw) || FaqChatbotDomainScope::looksUnclear($raw));

        // Nonsense only AFTER fuzzy/dictionary recovery failed to find health meaning.
        if ($looksLikeNoise && !$hasClinical && $score < 1.2
            && !(class_exists('FaqChatbotDomainScope') && FaqChatbotDomainScope::isHealthcareRelated($raw))
            && !(class_exists('FaqChatbotDomainScope') && FaqChatbotDomainScope::isHealthcareRelated($working))
        ) {
            return self::pack(
                self::DOMAIN_UNCLEAR,
                self::CONF_HIGH,
                $score,
                false,
                self::ROUTE_OOS,
                $signals,
                $relationships,
                $working,
                'Nonsense / unclear after normalization — no recoverable health meaning'
            );
        }

        $hasMedconnect = self::hasSignalType($signals, 'medconnect_service')
            || (bool) preg_match('/\b(medconnect|city\s+health|health\s+office|appointment|mag-?book|konsulta|pakonsulta|bhw|login|otp|register)\b/u', $working);

        if ($hasMedconnect && $score < 2.0 && !$hasClinical) {
            return self::pack(
                self::DOMAIN_MEDCONNECT,
                self::CONF_HIGH,
                max($score, 2.5),
                true,
                self::ROUTE_MEDCONNECT,
                $signals,
                $relationships,
                $working,
                'MedConnect / City Health Office service intent'
            );
        }

        // HIGH confidence health meaning → ORIGINAL NLP (never OUT_OF_SCOPE).
        if ($score >= 2.4 || self::hasStrongRelationship($relationships) || ($hasClinical && $score >= 1.8)) {
            return self::pack(
                self::DOMAIN_HEALTH,
                self::CONF_HIGH,
                $score,
                true,
                self::ROUTE_NLP,
                $signals,
                $relationships,
                $working,
                self::reasonHealth($signals, $relationships)
            );
        }

        // Any clinical complaint signal → HEALTH (at least medium). Never OUT_OF_SCOPE.
        if ($hasClinical || $score >= 1.2) {
            $conf = $score >= 2.0 ? self::CONF_HIGH : self::CONF_MEDIUM;
            $route = $conf === self::CONF_HIGH ? self::ROUTE_NLP : self::ROUTE_GEMINI;

            return self::pack(
                self::DOMAIN_HEALTH,
                $conf,
                $score,
                true,
                $route,
                $signals,
                $relationships,
                $working,
                $conf === self::CONF_HIGH
                    ? self::reasonHealth($signals, $relationships)
                    : 'Possible health meaning — medium confidence; Gemini fallback if needed'
            );
        }

        $clearlyUnrelated = class_exists('FaqChatbotDomainScope')
            && FaqChatbotDomainScope::isMeaningfulOutOfScope($raw)
            && $score < 1.2
            && !$hasClinical;

        if ($clearlyUnrelated) {
            return self::pack(
                self::DOMAIN_OOS,
                self::CONF_HIGH,
                $score,
                false,
                self::ROUTE_OOS,
                $signals,
                $relationships,
                $working,
                'Clearly non-medical / out-of-scope topic'
            );
        }

        // Ambiguous residual — Gemini fallback (not hard OOS).
        return self::pack(
            self::DOMAIN_UNCLEAR,
            self::CONF_MEDIUM,
            $score,
            false,
            self::ROUTE_GEMINI,
            $signals,
            $relationships,
            $working,
            'Ambiguous domain — Gemini fallback'
        );
    }

    /**
     * Validate strict Gemini domain response. Returns one of HEALTH_RELATED / MEDCONNECT_RELATED / OUT_OF_SCOPE or null.
     */
    public static function validateGeminiDomain(mixed $raw): ?string
    {
        $domain = '';
        if (is_array($raw)) {
            $domain = (string) ($raw['domain'] ?? $raw['classification'] ?? '');
        } elseif (is_string($raw)) {
            $trim = trim($raw);
            if ($trim !== '' && ($trim[0] ?? '') === '{') {
                $decoded = json_decode($trim, true);
                if (is_array($decoded)) {
                    $domain = (string) ($decoded['domain'] ?? $decoded['classification'] ?? '');
                }
            } else {
                $domain = $trim;
            }
        }
        $domain = strtoupper(str_replace([' ', '-'], '_', trim($domain)));
        $map = [
            'HEALTH_RELATED' => self::DOMAIN_HEALTH,
            'HEALTHCARE' => self::DOMAIN_HEALTH,
            'MEDICAL' => self::DOMAIN_HEALTH,
            'MEDICAL_SYMPTOM' => self::DOMAIN_HEALTH,
            'MEDCONNECT_RELATED' => self::DOMAIN_MEDCONNECT,
            'MEDCONNECT_SERVICE' => self::DOMAIN_MEDCONNECT,
            'SERVICE' => self::DOMAIN_MEDCONNECT,
            'OUT_OF_SCOPE' => self::DOMAIN_OOS,
            'NON_HEALTH_RELATED' => self::DOMAIN_OOS,
            'NON_HEALTHCARE' => self::DOMAIN_OOS,
        ];
        return $map[$domain] ?? null;
    }

    /** @param list<array{type:string,value:string}> $signals */
    private static function hasClinicalComplaintSignals(array $signals): bool
    {
        foreach ($signals as $s) {
            if (in_array($s['type'], [
                'symptom', 'body_part', 'duration', 'physical_change', 'injury',
                'bleeding', 'breathing', 'malaise', 'medication',
            ], true)) {
                return true;
            }
        }
        return false;
    }

    /** @param list<string> $relationships */
    private static function hasStrongRelationship(array $relationships): bool
    {
        foreach ($relationships as $rel) {
            if (in_array($rel, [
                'SYMPTOM+BODY_PART',
                'SYMPTOM+BODY_PART+PATIENT_REFERENCE',
                'SYMPTOM+DURATION',
                'PHYSICAL_CHANGE+BODY_PART',
                'INJURY+BODY_PART',
                'DURATION+SYMPTOM+BODY_PART',
            ], true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<array{type:string,value:string}> $signals
     * @param list<string> $relationships
     */
    private static function reasonHealth(array $signals, array $relationships): string
    {
        if ($relationships !== []) {
            return 'Health complaint via ' . implode(', ', $relationships);
        }
        $types = [];
        foreach ($signals as $s) {
            $types[$s['type']] = true;
        }
        return 'Health complaint signals: ' . implode(', ', array_keys($types));
    }

    /**
     * @return list<array{type:string,value:string}>
     */
    private static function extractSignals(string $hay, string $raw): array
    {
        $signals = [];
        $hay = self::applyMisspellHints($hay);

        $patterns = [
            'duration' => [
                '/\b((isa|duha|tulo|tatlo|apat|lima|anom|pito|walo|siyam|napulo|one|two|three|four|five|[0-9]+)\s+na\s+ka\s+(adlaw|bulan|oras|semana))\b/u',
                '/\b((isa|duha|tulo|tatlo|apat|lima|[0-9]+)\s*(ka\s+)?(adlaw|bulan|oras|semana|days?|weeks?|months?))\b/u',
                '/\b(tatlong\s+buwan|ilang\s+buwan|ilang\s+araw|for\s+(three|3)\s+months?|for\s+a\s+long\s+time|dugay\s+na|matagal\s+na|nang\s+masakit|halin\s+gahapon|since\s+yesterday|for\s+\d+\s+days?)\b/u',
                '/\b(\d+\s*(days?|weeks?|months?|hours?)\s*(na|ago|nang)?)\b/u',
                '/\b((cought|cough|ubo|sakit|pain|ache).{0,20}\b(for\s+)?\d+\s*(days?|weeks?|months?))\b/u',
                '/\b((for\s+)?\d+\s*(days?|weeks?|months?).{0,20}\b(cought|cough|ubo|sakit|pain|ache|fever))\b/u',
            ],
            'symptom' => [
                '/\b(kasakit|masakit|gasakit|ginasakit|nagasakit|sumasakit|ginakasakit|sakit|ga\s*sakit|naga\s*sakit|nag\s*sakit|kirot|hapdi|pain|hurt|hurts|ache|aching|headache|fever|cough|dizzy|dizziness|nausea|vomit|vomiting)\b/u',
                '/\b(ginaubo|ginauubo|nagaubo|ga\s*ubo|ubo|ginasuka|nagsuka|gasuka|nahilo|ginahilo|nalipong|ginahilanat|ginalagnat|hilanat|lagnat|gahika)\b/u',
                '/\b(nangangati|pangati|makati|katol|gakatol|kakatol|itchy|itching)\b/u',
            ],
            'physical_change' => [
                '/\b(gapula|gakatol|kakatol|katol|gahabok|gahubag|hubag|namamaga|namamagang|pamamaga|swollen|swelling|red|pula|itchy|itching)\b/u',
            ],
            'malaise' => [
                '/\b(indi\s+ko\s+maayo|hindi\s+ko\s+mabuti|dili\s+ko\s+maayo|gakapoy|nagakapoy|kapoy\s+ko|luya\s+ko|masama\s+ang\s+pakiramdam|not\s+feeling\s+well|don\'?t\s+feel\s+well|feel(s|ing)?\s+weird|body\s+feels\s+weird|ginalain|lain\s+lawas)\b/u',
            ],
            'body_part' => [
                '/\b(ulo|olo|mata|eye|eyes|tiyan|stomach|tummy|dughan|dibdib|chest|lawas|body|likod|back|tuhod|throat|tungol|ilong|nose|tenga|ear|kamot|kamay|hand|tiil|paa|foot|feet|dila|tongue|ngipon|tooth|tudlo|finger|skin|balat|head)\b/u',
            ],
            'patient_reference' => [
                '/\b(ko|akon|ako|aku|my|i|ang\s+akin|q)\b/u',
            ],
            'breathing' => [
                '/\b(budlay\s+ginhawa|lisod\s+ginhawa|lisud\s+ginhawa|hirap\s+huminga|difficulty\s+breathing|short\s+of\s+breath|cannot\s+breathe|ginhawa)\b/u',
            ],
            'bleeding' => [
                '/\b(dugo|bleeding|bleed|nagdugo|hemorrhage)\b/u',
            ],
            'injury' => [
                '/\b(samad|wound|injury|injured|nasugatan|nabuno|nahulog|trauma|burn|nasunog)\b/u',
            ],
            'severity' => [
                '/\b(grabe|malala|severe|mild|moderate|[0-9]{1,2}\s*\/\s*10)\b/u',
            ],
            'medconnect_service' => [
                '/\b(medconnect|city\s+health|health\s+office|appointment|mag-?book|konsulta|pakonsulta|checkup|bhw|login|otp|register|forgot\s+password)\b/u',
            ],
            'medication' => [
                '/\b(gamot|tambal|medicine|medication|paracetamol|antibiotics?|reseta|prescription)\b/u',
            ],
        ];

        foreach ($patterns as $type => $res) {
            foreach ($res as $re) {
                if (preg_match_all($re, $hay, $m)) {
                    foreach ($m[0] as $val) {
                        $val = trim($val);
                        if ($val === '' || self::signalExists($signals, $type, $val)) {
                            continue;
                        }
                        $signals[] = ['type' => $type, 'value' => $val];
                    }
                }
            }
        }

        return $signals;
    }

    /**
     * @param list<array{type:string,value:string}> $signals
     * @return list<string>
     */
    private static function detectRelationships(array $signals): array
    {
        $has = static function (string $type) use ($signals): bool {
            foreach ($signals as $s) {
                if ($s['type'] === $type) {
                    return true;
                }
            }
            return false;
        };

        $rels = [];
        if ($has('symptom') && $has('body_part')) {
            $rels[] = 'SYMPTOM+BODY_PART';
        }
        if ($has('symptom') && $has('body_part') && $has('patient_reference')) {
            $rels[] = 'SYMPTOM+BODY_PART+PATIENT_REFERENCE';
        }
        if ($has('symptom') && $has('duration')) {
            $rels[] = 'SYMPTOM+DURATION';
        }
        if ($has('duration') && $has('symptom') && $has('body_part')) {
            $rels[] = 'DURATION+SYMPTOM+BODY_PART';
        }
        if ($has('physical_change') && $has('body_part')) {
            $rels[] = 'PHYSICAL_CHANGE+BODY_PART';
        }
        if ($has('injury') && $has('body_part')) {
            $rels[] = 'INJURY+BODY_PART';
        }
        if ($has('breathing')) {
            $rels[] = 'BREATHING_DIFFICULTY';
        }
        if ($has('bleeding') && $has('body_part')) {
            $rels[] = 'BLEEDING+BODY_PART';
        }
        return $rels;
    }

    /**
     * @param list<array{type:string,value:string}> $signals
     * @param list<string> $relationships
     */
    private static function score(array $signals, array $relationships, string $hay, string $raw): float
    {
        $score = 0.0;
        $typeWeights = [
            'symptom' => 1.4,
            'body_part' => 1.0,
            'duration' => 1.1,
            'physical_change' => 1.3,
            'malaise' => 1.5,
            'patient_reference' => 0.35,
            'breathing' => 2.2,
            'bleeding' => 1.8,
            'injury' => 1.6,
            'medication' => 1.2,
            'medconnect_service' => 1.5,
            'severity' => 0.3,
            'fuzzy_correction' => 0.4,
            'dictionary' => 0.5,
            'dataset_symptom' => 1.2,
            'existing_healthcare_flag' => 1.0,
        ];
        $seenTypes = [];
        foreach ($signals as $s) {
            $t = $s['type'];
            if (isset($seenTypes[$t])) {
                $score += 0.15;
                continue;
            }
            $seenTypes[$t] = true;
            $score += $typeWeights[$t] ?? 0.4;
        }

        $relBonus = [
            'SYMPTOM+BODY_PART' => 1.6,
            'SYMPTOM+BODY_PART+PATIENT_REFERENCE' => 2.0,
            'SYMPTOM+DURATION' => 1.5,
            'DURATION+SYMPTOM+BODY_PART' => 2.2,
            'PHYSICAL_CHANGE+BODY_PART' => 1.8,
            'INJURY+BODY_PART' => 1.8,
            'BREATHING_DIFFICULTY' => 2.0,
            'BLEEDING+BODY_PART' => 1.8,
        ];
        foreach ($relationships as $rel) {
            $score += $relBonus[$rel] ?? 0.5;
        }

        return $score;
    }

    /**
     * @return list<array{type:string,value:string}>
     */
    private static function datasetSymptomHits(string $hay, string $raw): array
    {
        $hits = [];
        if (!class_exists('SymptomKnowledgeBase')) {
            return $hits;
        }
        try {
            $matched = SymptomKnowledgeBase::matchSymptoms($hay, $raw);
        } catch (Throwable) {
            return $hits;
        }
        if (!is_array($matched)) {
            return $hits;
        }
        foreach (array_slice($matched, 0, 5) as $row) {
            $name = trim((string) ($row['symptom_name'] ?? $row['matched_term'] ?? ''));
            $term = trim((string) ($row['matched_term'] ?? $name));
            if ($name === '' && $term === '') {
                continue;
            }
            $hits[] = ['type' => 'dataset_symptom', 'value' => $term !== '' ? $term : $name];
            $hits[] = ['type' => 'symptom', 'value' => $name !== '' ? $name : $term];
        }
        return $hits;
    }

    /**
     * @return list<array{type:string,value:string}>
     */
    private static function dictionaryHits(string $hay): array
    {
        $hits = [];
        if (!class_exists('MedicalDictionary')) {
            return $hits;
        }
        $words = preg_split('/\s+/u', $hay, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($words as $w) {
            if (mb_strlen($w) < 3) {
                continue;
            }
            try {
                $entry = MedicalDictionary::lookup($w);
            } catch (Throwable) {
                $entry = null;
            }
            if (!is_array($entry)) {
                // Fuzzy token against dictionary via demo fuzzy lexicon when available.
                if (class_exists('NlpStep3DemoAnswerFuzzy')) {
                    $m = NlpStep3DemoAnswerFuzzy::bestMatch($w);
                    if (is_array($m) && ($m['to'] ?? '') !== '') {
                        $to = (string) $m['to'];
                        $entry = MedicalDictionary::lookup($to);
                        if ($entry === null && class_exists('MedicalDictionary')) {
                            // English-side clinical tokens from fuzzy lexicon.
                            if (preg_match('/\b(pain|fever|cough|headache|stomach|eye|head|dizzy|vomit|swollen|swelling|breath)\b/u', $to)) {
                                $type = preg_match('/\b(eye|head|stomach|chest|back)\b/u', $to) ? 'body_part' : 'symptom';
                                $hits[] = ['type' => $type, 'value' => $w . '→' . $to];
                            }
                        }
                    }
                }
            }
            if (!is_array($entry)) {
                continue;
            }
            $cat = strtolower((string) ($entry['category'] ?? ''));
            $en = (string) ($entry['english_term'] ?? $w);
            $type = 'dictionary';
            if (str_contains($cat, 'body') || preg_match('/\b(eye|head|stomach|chest|back)\b/u', $en)) {
                $type = 'body_part';
            } elseif (str_contains($cat, 'symptom') || preg_match('/\b(pain|fever|cough|swell|vomit|dizzy)\b/u', $en)) {
                $type = 'symptom';
            }
            if (!self::signalExists($hits, $type, $w)) {
                $hits[] = ['type' => $type, 'value' => $w . '→' . $en];
            }
        }
        return $hits;
    }

    private static function applyMisspellHints(string $hay): string
    {
        $map = [
            'saket' => 'sakit',
            'sakittt' => 'sakit',
            'kasaket' => 'kasakit',
            'masaket' => 'masakit',
            'matta' => 'mata',
            'mataa' => 'mata',
            'olo' => 'ulo',
            'uloo' => 'ulo',
            'tyan' => 'tiyan',
            'tian' => 'tiyan',
            'ginauboo' => 'ginaubo',
            'ginaubo' => 'ginaubo',
            'hubagg' => 'hubag',
            'dugoo' => 'dugo',
        ];
        $words = preg_split('/\s+/u', $hay, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];
        foreach ($words as $w) {
            // Collapse repeated letters (sakitt → sakit) lightly.
            $collapsed = preg_replace('/(.)\1{2,}/u', '$1$1', $w) ?? $w;
            $out[] = $map[$collapsed] ?? $map[$w] ?? $collapsed;
        }
        return implode(' ', $out);
    }

    private static function normalize(string $text): string
    {
        $t = mb_strtolower(trim($text), 'UTF-8');
        $t = preg_replace('/[^\p{L}\p{N}\s\'-]/u', ' ', $t) ?? $t;
        return trim(preg_replace('/\s+/u', ' ', $t) ?? $t);
    }

    /** @param list<array{type:string,value:string}> $signals */
    private static function hasSignalType(array $signals, string $type): bool
    {
        foreach ($signals as $s) {
            if (($s['type'] ?? '') === $type) {
                return true;
            }
        }
        return false;
    }

    /** @param list<array{type:string,value:string}> $signals */
    private static function signalExists(array $signals, string $type, string $value): bool
    {
        $v = mb_strtolower($value);
        foreach ($signals as $s) {
            if (($s['type'] ?? '') === $type && mb_strtolower((string) ($s['value'] ?? '')) === $v) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<array{type:string,value:string}> $signals
     * @param list<string> $relationships
     * @return array{
     *   domain: string,
     *   confidence: string,
     *   score: float,
     *   health_related: bool,
     *   routing: string,
     *   signals: list<array{type:string,value:string}>,
     *   relationships: list<string>,
     *   normalized: string,
     *   reason: string
     * }
     */
    private static function pack(
        string $domain,
        string $confidence,
        float $score,
        bool $healthRelated,
        string $routing,
        array $signals,
        array $relationships,
        string $normalized,
        string $reason
    ): array {
        return [
            'domain' => $domain,
            'confidence' => $confidence,
            'score' => round($score, 2),
            'health_related' => $healthRelated,
            'routing' => $routing,
            'signals' => array_values($signals),
            'relationships' => array_values($relationships),
            'normalized' => $normalized,
            'reason' => $reason,
        ];
    }
}
