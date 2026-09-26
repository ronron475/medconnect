<?php
/**
 * Production adaptive interview policy.
 *
 * Selects the next clinically useful follow-up from the shared question bank
 * using the COMPLETE accumulated case (facts + transcript + prior answers).
 * Does not diagnose and does not replace ClinicalTriageEngine.
 *
 * Selection is universal: domain tags from ClinicalInterviewContextResolver
 * gate relevance; open clinical gaps + bank metadata decide priority.
 * Symptom-category hardcoding is intentionally avoided.
 */
final class ClinicalInterviewAdaptivePolicy
{
    /**
     * Pick the next triage-relevant question slot, or null when none needed.
     *
     * @param array<string, mixed> $context ClinicalInterviewEngine context
     * @param array<string, mixed> $assessment Assessment payload (optional)
     * @return array{question_id:string,clinical_purpose:string,red_flag_related:bool,priority:int,source?:string}|null
     */
    public static function selectNextSlot(array $context, string $transcript, array $assessment = []): ?array
    {
        $queue = self::listCandidateSlots($context, $transcript, $assessment);

        return $queue[0] ?? null;
    }

    /**
     * Ranked allow-list of next-question candidates (for Gemini adaptive select + fallback).
     *
     * @param array<string, mixed> $context
     * @param array<string, mixed> $assessment
     * @return list<array{
     *   question_id:string,
     *   clinical_purpose:string,
     *   red_flag_related:bool,
     *   priority:int,
     *   source?:string,
     *   target_finding?:string,
     *   parent_question_id?:string,
     *   bank_template?:string
     * }>
     */
    public static function listCandidateSlots(array $context, string $transcript, array $assessment = []): array
    {
        $facts = is_array($context['facts'] ?? null) ? $context['facts'] : [];
        $asked = array_map('strtoupper', array_map('strval', (array) ($context['questions_asked'] ?? [])));
        $findingsAsked = array_map('strtolower', array_map('strval', (array) ($context['findings_asked'] ?? [])));
        $complaints = is_array($context['chief_complaints'] ?? null) ? $context['chief_complaints'] : [];
        if ($complaints === [] && $assessment !== []) {
            $complaints = ClinicalInterviewContextResolver::deriveComplaints($assessment, $transcript, $facts);
        }
        $caseHaystack = self::fullCaseHaystack($context, $transcript, $facts);
        $concepts = ClinicalInterviewContextResolver::deriveFamilies($complaints, $transcript, $facts);
        // Enrich from full case (incl. meaning bridge) so multilingual gloss can
        // surface pain without expanding per-dialect word lists.
        $concepts = self::enrichConcepts($concepts, $facts, $transcript, $caseHaystack);
        $concepts = array_values(array_unique(array_filter(array_map(
            static fn ($c): string => strtolower(trim((string) $c)),
            $concepts
        ))));

        $gaps = self::openClinicalGaps($facts, $transcript, $concepts, $caseHaystack);

        $queue = [];
        foreach (ClinicalFollowUpQuestionBank::questions() as $question) {
            if (!is_array($question)) {
                continue;
            }
            $qid = strtoupper(trim((string) ($question['question_id'] ?? '')));
            if ($qid === '') {
                continue;
            }
            // Keep re-asking associated detail until a named symptom is captured.
            // Also re-ask any slot that was posed but never persisted as a clinical fact
            // (e.g. bleeding_continuing wiped before save) — "asked" ≠ "answered".
            $askedBlock = in_array($qid, $asked, true);
            $assocDetailPending = $qid === 'ASSOCIATED_DETAIL' && (
                !empty($facts['needs_associated_detail'])
                || (($facts['has_other_symptoms'] ?? null) === true && self::associatedSymptomNames($facts) === [])
            );
            if ($askedBlock && !$assocDetailPending
                && self::alreadyAnswered($qid, $facts, $transcript, $concepts, $caseHaystack)
            ) {
                continue;
            }
            $when = array_map('strtolower', (array) ($question['required_when'] ?? []));
            // Empty required_when = always eligible (e.g. ASSOCIATED_DETAIL after yes).
            if ($when !== [] && array_intersect($when, $concepts) === []) {
                continue;
            }
            // Family tag match is not enough — purpose must have supporting clinical evidence.
            if (!self::questionEvidenceAllows($qid, $concepts, $facts, $caseHaystack)) {
                continue;
            }
            if (self::alreadyAnswered($qid, $facts, $transcript, $concepts, $caseHaystack)) {
                continue;
            }
            if (self::purposeAlreadyKnown($question, $caseHaystack, $facts)) {
                continue;
            }
            $impact = self::scoreQuestionPriority($question, $qid, $gaps, $facts, $concepts);
            if ($impact === null) {
                continue;
            }

            $lang = strtolower(trim((string) ($context['question_language'] ?? 'english')));
            $bankTemplate = class_exists('ClinicalFollowUpQuestionBank')
                ? ClinicalFollowUpQuestionBank::textForLanguage($question, $lang !== '' ? $lang : 'english')
                : (string) ($question['english'] ?? '');

            $atomic = self::atomicFindingCandidates(
                $qid,
                (string) ($question['clinical_purpose'] ?? ''),
                (bool) ($question['red_flag_related'] ?? false),
                $impact,
                $bankTemplate,
                $facts,
                $caseHaystack,
                $findingsAsked
            );
            if (self::isBundledQuestion($qid)) {
                if ($atomic === []) {
                    // All atomic findings known or asked — do not re-ask the OR-bundle.
                    continue;
                }
                foreach ($atomic as $row) {
                    $queue[] = $row;
                }
                continue;
            }
            if ($atomic !== []) {
                foreach ($atomic as $row) {
                    $queue[] = $row;
                }
                continue;
            }

            $queue[] = [
                'question_id' => $qid,
                'clinical_purpose' => (string) ($question['clinical_purpose'] ?? ''),
                'red_flag_related' => (bool) ($question['red_flag_related'] ?? false),
                'priority' => $impact,
                'source' => 'adaptive_policy',
                'target_finding' => self::defaultTargetFinding($qid),
                'bank_template' => $bankTemplate,
            ];
        }

        if ($queue === []) {
            return [];
        }

        usort($queue, static function (array $a, array $b): int {
            $p = ((int) $a['priority']) <=> ((int) $b['priority']);
            if ($p !== 0) {
                return $p;
            }
            // Prefer red-flag probes when priorities tie.
            $ra = !empty($a['red_flag_related']) ? 0 : 1;
            $rb = !empty($b['red_flag_related']) ? 0 : 1;

            return $ra <=> $rb;
        });

        return array_values($queue);
    }

    /**
     * Universal evidence gate: a bank slot is eligible only when supporting clinical
     * evidence matches its purpose (not merely a broad medical category / coarse alias).
     *
     * @param list<string> $concepts
     * @param array<string, mixed> $facts
     */
    private static function questionEvidenceAllows(
        string $qid,
        array $concepts,
        array $facts,
        string $caseHaystack
    ): bool {
        $qid = strtoupper(trim($qid));
        if (str_contains($qid, '__')) {
            $qid = explode('__', $qid, 2)[0];
        }

        // Pain location / severity require pain evidence (language or pain-class family).
        if (in_array($qid, ['PAIN_LOCATION', 'PAIN_SEVERITY'], true)) {
            return self::caseSuggestsPainIntensity($concepts, $facts, $caseHaystack);
        }

        // Chest radiation / associated chest danger signs require chest-pain evidence.
        if (in_array($qid, ['CHEST_RADIATION', 'CHEST_SWEATING'], true)) {
            return array_intersect($concepts, ['chest_pain']) !== []
                || (bool) preg_match(
                    '/\b(chest\s*pain|chest\s*discomfort|sakit\s+(?:sa\s+)?(?:dughan|dibdib)|angina|pleuritic)\b/iu',
                    $caseHaystack
                );
        }

        // Abdominal associated danger bundle requires abdominal-pain evidence.
        if ($qid === 'ABDOMINAL_ASSOCIATED') {
            return array_intersect($concepts, ['abdominal_pain']) !== []
                || (bool) preg_match(
                    '/\b(abdominal\s*pain|stomach\s*pain|belly\s*pain|sakit\s+(?:sa\s+|sang\s+)?(?:tiyan|tyan|tian))\b/iu',
                    $caseHaystack
                );
        }

        // Breathing severity requires dyspnea / airway evidence (not mild URI alone).
        if ($qid === 'BREATHING_SEVERITY') {
            if (($facts['breathing_difficulty'] ?? null) === true) {
                return true;
            }
            if (array_intersect($concepts, ['breathing', 'chest_pain']) !== []) {
                return true;
            }

            return (bool) preg_match(
                '/\b(difficulty\s+breath|shortness\s+of\s+breath|dyspnea|cannot\s+breathe|can\'t\s+breathe|'
                . 'budlay.{0,20}ginhawa|hirap.{0,24}huminga|makahinga|makaginhawa|wheez|stridor|cyanosis|'
                . 'air\s+hunger|blue\s+lips)\b/iu',
                $caseHaystack
            );
        }

        return true;
    }

    private static function isBundledQuestion(string $qid): bool
    {
        return in_array(strtoupper($qid), ['ABDOMINAL_ASSOCIATED', 'CHEST_SWEATING', 'URINARY_DETAIL'], true);
    }

    /**
     * Split bundled bank purposes into one-finding candidates (Gemini picks one).
     *
     * @param list<string> $findingsAsked
     * @param array<string, mixed> $facts
     * @return list<array<string, mixed>>
     */
    private static function atomicFindingCandidates(
        string $qid,
        string $purpose,
        bool $redFlag,
        int $priority,
        string $bankTemplate,
        array $facts,
        string $caseHaystack,
        array $findingsAsked
    ): array {
        $bundles = [
            'ABDOMINAL_ASSOCIATED' => [
                ['finding' => 'vomiting', 'purpose' => 'Ask only whether the patient is vomiting', 'skip' => 'vomit|suka|nagsusuka|retch|emesis|throwing up|throw up'],
                ['finding' => 'fever_with_abdomen', 'purpose' => 'Ask only whether the patient has fever with the abdominal pain', 'skip' => 'fever|lagnat|hilanat|febrile|pyrexia'],
                ['finding' => 'bleeding_with_abdomen', 'purpose' => 'Ask only whether there is any bleeding with the abdominal pain', 'skip' => 'bleed|blood|dugo|nagadugo|dumudugo'],
            ],
            'CHEST_SWEATING' => [
                ['finding' => 'sweating_with_chest', 'purpose' => 'Ask only whether the patient is sweating a lot with chest pain', 'skip' => 'sweat|singot|pinagpapawisan|diaphore'],
                ['finding' => 'dizziness_with_chest', 'purpose' => 'Ask only whether the patient feels dizzy or like fainting with chest pain', 'skip' => 'dizz|faint|lipong|hilo|punaw|lightheaded|light-headed|woozy|vertigo'],
            ],
            'URINARY_DETAIL' => [
                ['finding' => 'urinary_burning', 'purpose' => 'Ask only whether urination burns or hurts', 'skip' => 'burn|hapdi|masakit.*(ihi|urine)'],
                ['finding' => 'urinary_blood', 'purpose' => 'Ask only whether there is blood in the urine', 'skip' => 'blood|dugo'],
                ['finding' => 'urinary_fever', 'purpose' => 'Ask only whether there is fever with urinary symptoms', 'skip' => 'fever|lagnat|hilanat|febrile|pyrexia'],
            ],
        ];
        if (!isset($bundles[$qid])) {
            return [];
        }

        $out = [];
        foreach ($bundles[$qid] as $i => $atom) {
            $finding = strtolower((string) $atom['finding']);
            if (in_array($finding, $findingsAsked, true)) {
                continue;
            }
            if (self::findingAlreadyKnown($finding, $facts, $caseHaystack, (string) $atom['skip'])) {
                continue;
            }
            $out[] = [
                'question_id' => $qid . '__' . strtoupper($finding),
                'parent_question_id' => $qid,
                'target_finding' => $finding,
                'clinical_purpose' => (string) $atom['purpose'],
                'red_flag_related' => $redFlag,
                'priority' => $priority + $i,
                'source' => 'adaptive_policy_atomic',
                'bank_template' => $bankTemplate,
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $facts
     */
    private static function findingAlreadyKnown(string $finding, array $facts, string $hay, string $skipPattern): bool
    {
        $map = is_array($facts['finding_status'] ?? null) ? $facts['finding_status'] : [];
        if (isset($map[$finding]) && $map[$finding] !== null && $map[$finding] !== '') {
            return true;
        }
        if ($finding === 'vomiting'
            && (
                self::symptomListHas($facts, 'vomit')
                || self::symptomListHas($facts, 'suka')
                || self::symptomListHas($facts, 'retch')
                || self::symptomListHas($facts, 'emesis')
                || self::symptomListHas($facts, 'throwing up')
                || self::symptomListHas($facts, 'throw up')
            )
        ) {
            return true;
        }
        if ($finding === 'sweating_with_chest' && (
            ($facts['sweating'] ?? null) !== null
            || self::symptomListHas($facts, 'sweat')
            || self::symptomListHas($facts, 'diaphore')
            || self::symptomListHas($facts, 'singot')
        )) {
            return true;
        }
        // dizziness_with_chest is scoped via finding_status + haystack evidence only —
        // shared dizziness (e.g. from BLEEDING_DIZZY) must not suppress the chest atom.
        if (self::haystackIndicatesFinding($finding, $hay, $skipPattern)) {
            // Affirmed or denied in free text / meaning bridge — do not re-ask.
            return true;
        }

        return false;
    }

    /**
     * True when case haystack already states this atomic finding (inflected forms + synonyms).
     */
    private static function haystackIndicatesFinding(string $finding, string $hay, string $skipPattern): bool
    {
        $hay = mb_strtolower(trim($hay));
        if ($hay === '') {
            return false;
        }
        if ($skipPattern !== '' && self::skipPatternMatchesHay($hay, $skipPattern)) {
            return true;
        }
        foreach (self::findingSynonymPatterns($finding) as $re) {
            if ($re !== '' && (bool) preg_match('/' . $re . '/iu', $hay)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Clinical paraphrases for atomic findings (complements bank skip stems; not a second fact store).
     *
     * @return list<string> PCRE fragments (no delimiters)
     */
    private static function findingSynonymPatterns(string $finding): array
    {
        return match (strtolower(trim($finding))) {
            'vomiting' => [
                'throw(?:ing)?\s+up',
                '\bretch(?:ing|es|ed)?\b',
                '\bemesis\b',
            ],
            'sweating_with_chest' => [
                '\bdiaphore(?:sis|tic)?\b',
                '\bperspir(?:e|es|ed|ing|ation)\b',
            ],
            'dizziness_with_chest' => [
                '\blight[- ]?head(?:ed)?\b',
                '\bwooz(?:y|iness)?\b',
                '\bvertigo\b',
                'about\s+to\s+faint',
            ],
            'fever_with_abdomen', 'urinary_fever' => [
                '\bfebril(?:e|ity)?\b',
                '\bpyrexia\b',
            ],
            default => [],
        };
    }

    /**
     * Match skip alternatives with inflectional suffixes (vomit→vomiting, sweat→sweating, dizz→dizzy).
     * Preserves regex fragments and parenthesized groups already present in bank skip patterns.
     */
    private static function skipPatternMatchesHay(string $hay, string $skipPattern): bool
    {
        foreach (self::splitSkipAlternatives($skipPattern) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            // Existing regex fragment (e.g. masakit.*(ihi|urine), light[- ]?head).
            if ((bool) preg_match('/[\[\]().*+?\\\\]/', $part)) {
                if ((bool) preg_match('/(?:' . $part . ')/iu', $hay)) {
                    return true;
                }
                continue;
            }
            // Multi-word phrase (e.g. "throwing up" if added to skip lists).
            if ((bool) preg_match('/\s/u', $part)) {
                $escaped = preg_replace('/\s+/u', '\\s+', preg_quote($part, '/')) ?? preg_quote($part, '/');
                if ((bool) preg_match('/\b' . $escaped . '\b/iu', $hay)) {
                    return true;
                }
                continue;
            }
            // Simple stem: allow word-internal inflectional continuation.
            $stem = preg_quote($part, '/');
            if ((bool) preg_match('/\b' . $stem . '\w*\b/iu', $hay)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Split a|-alternation on top-level pipes only (respects parentheses).
     *
     * @return list<string>
     */
    private static function splitSkipAlternatives(string $pattern): array
    {
        $parts = [];
        $buf = '';
        $depth = 0;
        $len = strlen($pattern);
        for ($i = 0; $i < $len; $i++) {
            $ch = $pattern[$i];
            if ($ch === '(') {
                $depth++;
                $buf .= $ch;
                continue;
            }
            if ($ch === ')') {
                $depth = max(0, $depth - 1);
                $buf .= $ch;
                continue;
            }
            if ($ch === '|' && $depth === 0) {
                $parts[] = $buf;
                $buf = '';
                continue;
            }
            $buf .= $ch;
        }
        if ($buf !== '') {
            $parts[] = $buf;
        }

        return $parts;
    }

    /**
     * @param array<string, mixed> $facts
     */
    private static function symptomListHas(array $facts, string $needle): bool
    {
        $needle = mb_strtolower($needle);
        foreach (['symptoms', 'associated_symptoms', 'negative_symptoms'] as $key) {
            foreach ((array) ($facts[$key] ?? []) as $item) {
                if (str_contains(mb_strtolower((string) $item), $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function defaultTargetFinding(string $qid): string
    {
        return match (strtoupper($qid)) {
            'PAIN_SEVERITY' => 'pain_severity',
            'PAIN_LOCATION', 'UNWELL_WHAT', 'SPECIFIC_LOCATION' => 'pain_location',
            'ONSET' => 'onset',
            'DURATION' => 'duration',
            'NEURO_WEAKNESS' => 'weakness',
            'NEURO_SPEECH' => 'speech_difficulty',
            'NEURO_VISION', 'EYE_VISION' => 'vision_change',
            'BREATHING_SEVERITY' => 'breathing_difficulty',
            'BLEEDING_CONTINUING' => 'bleeding_continuing',
            'BLEEDING_HEAVY' => 'bleeding_heavy',
            'BLEEDING_DIZZY' => 'dizziness',
            'CHEST_RADIATION' => 'chest_radiation',
            'FEVER_CONFIRM' => 'fever_confirmed',
            'ASSOCIATED_SYMPTOMS' => 'has_other_symptoms',
            'ASSOCIATED_DETAIL' => 'associated_detail',
            default => strtolower($qid),
        };
    }

    /**
     * True when collected facts are enough to classify without more bank questions.
     *
     * @param array<string, mixed> $context
     * @param array<string, mixed> $assessment
     */
    public static function isTriageSufficient(array $context, string $transcript, array $assessment = []): bool
    {
        $facts = is_array($context['facts'] ?? null) ? $context['facts'] : [];
        $complaints = is_array($context['chief_complaints'] ?? null) ? $context['chief_complaints'] : [];
        if ($complaints === [] && $assessment !== []) {
            $complaints = ClinicalInterviewContextResolver::deriveComplaints($assessment, $transcript, $facts);
        }
        $concepts = ClinicalInterviewContextResolver::deriveFamilies($complaints, $transcript, $facts);
        $caseHaystack = self::fullCaseHaystack($context, $transcript, $facts);
        $concepts = self::enrichConcepts($concepts, $facts, $transcript, $caseHaystack);
        $gaps = self::openClinicalGaps($facts, $transcript, $concepts, $caseHaystack);

        // Critical gaps that should block early finalize.
        if (!empty($gaps['associated_detail'])) {
            return false;
        }
        if (!empty($gaps['severity'])) {
            return false;
        }
        if (!empty($gaps['timing']) && self::timingIsMaterial($concepts, $facts)) {
            return false;
        }
        if (!empty($gaps['location']) && self::locationIsMaterial($concepts, $facts)) {
            return false;
        }
        // Open red-flag probes that still match this case domain.
        if (!empty($gaps['red_flag'])) {
            return false;
        }
        if (!empty($gaps['associated']) && self::associatedIsMaterial($concepts, $facts, $transcript)) {
            return false;
        }
        // Clinically relevant clarify (urinary atoms / fever confirm) — not optional enrichment.
        if (!empty($gaps['clarify'])) {
            return false;
        }

        return true;
    }

    /**
     * Build one text blob of everything already known about the case.
     *
     * @param array<string, mixed> $context
     * @param array<string, mixed> $facts
     */
    public static function fullCaseHaystack(array $context, string $transcript, array $facts): string
    {
        $parts = [];
        $chief = trim((string) ($context['chief_complaint'] ?? ''));
        if ($chief !== '') {
            $parts[] = $chief;
        }
        $parts[] = $transcript;
        foreach ((array) ($context['questions_answered'] ?? []) as $qa) {
            if (!is_array($qa)) {
                continue;
            }
            $q = trim((string) ($qa['question'] ?? $qa['text'] ?? ''));
            $a = trim((string) ($qa['answer'] ?? $qa['patient_answer'] ?? ''));
            if ($q !== '') {
                $parts[] = $q;
            }
            if ($a !== '') {
                $parts[] = $a;
            }
        }
        foreach ((array) ($context['patient_turns'] ?? []) as $turn) {
            $t = is_string($turn) ? trim($turn) : trim((string) (is_array($turn) ? ($turn['text'] ?? '') : ''));
            if ($t !== '') {
                $parts[] = $t;
            }
        }
        $parts[] = self::factsSummary($facts);

        // Meaning bridge is interpretation aid only — never replaces patient speech,
        // but lets existing English pain/concept gates fire for local wording.
        $bridge = is_array($context['semantic_bridge'] ?? null) ? $context['semantic_bridge'] : [];
        foreach ([
            'ollama_meaning',
            'gemini_concept',
            'nlp_text',
            'python_gloss',
            'domain_normalized',
            'domain_meaning',
        ] as $key) {
            $bit = trim((string) ($bridge[$key] ?? ''));
            if ($bit !== '') {
                $parts[] = $bit;
            }
        }
        foreach ((array) ($bridge['python_symptoms'] ?? []) as $sym) {
            $s = trim((string) $sym);
            if ($s !== '') {
                $parts[] = $s;
            }
        }
        foreach ((array) ($bridge['domain_relationships'] ?? []) as $rel) {
            $r = strtoupper(trim((string) $rel));
            if ($r !== '') {
                $parts[] = $r;
            }
        }

        return mb_strtolower(trim(implode("\n", array_filter($parts, static fn ($p): bool => trim((string) $p) !== ''))));
    }

    /**
     * @param array<string, mixed> $facts
     */
    private static function factsSummary(array $facts): string
    {
        $bits = [];
        foreach (['body_locations', 'symptoms', 'associated_symptoms', 'negative_symptoms', 'red_flags', 'risk_factors'] as $key) {
            $list = is_array($facts[$key] ?? null) ? $facts[$key] : [];
            foreach ($list as $item) {
                $s = trim((string) $item);
                if ($s !== '') {
                    $bits[] = $key . ':' . $s;
                }
            }
        }
        if (($facts['pain_score'] ?? null) !== null && $facts['pain_score'] !== '') {
            $bits[] = 'pain_score:' . (int) $facts['pain_score'];
        }
        foreach (['onset', 'duration_label', 'pain_qualifier'] as $key) {
            $v = trim((string) ($facts[$key] ?? ''));
            if ($v !== '') {
                $bits[] = $key . ':' . $v;
            }
        }
        foreach ([
            'breathing_difficulty', 'weakness', 'speech_difficulty', 'vision_change',
            'bleeding_continuing', 'bleeding_heavy', 'dizziness', 'chest_radiation',
            'sweating', 'abdominal_associated', 'has_other_symptoms', 'denied_associated',
            'needs_associated_detail',
        ] as $flag) {
            if (array_key_exists($flag, $facts) && $facts[$flag] !== null && $facts[$flag] !== '') {
                $bits[] = $flag . ':' . (is_bool($facts[$flag]) ? ($facts[$flag] ? 'yes' : 'no') : (string) $facts[$flag]);
            }
        }

        return implode('; ', $bits);
    }

    /**
     * Universal open gaps for the current case (not symptom-category specific).
     *
     * @param array<string, mixed> $facts
     * @param list<string> $concepts
     * @return array<string, bool>
     */
    private static function openClinicalGaps(array $facts, string $transcript, array $concepts, string $caseHaystack): array
    {
        $gaps = [
            'severity' => false,
            'location' => false,
            'timing' => false,
            'associated' => false,
            'associated_detail' => false,
            'red_flag' => false,
            'clarify' => false,
        ];

        $hasTiming = ClinicalFeatureExtractors::hasTimingInformation($transcript, $facts);
        $assocDone = self::associatedSymptomsResolved($facts);
        $sev = $facts['pain_score'] ?? null;
        $sev = $sev !== null ? (int) $sev : null;
        $locs = self::bodyLocations($facts);
        if ($locs === []) {
            $locs = ClinicalFeatureExtractors::extractBodyLocations($transcript);
        }

        // Numeric 1–10 only closes severity — qualitative intensifiers (grabe/severe)
        // must not skip PAIN_SEVERITY.
        if (self::caseSuggestsPainIntensity($concepts, $facts, $caseHaystack) && $sev === null) {
            $gaps['severity'] = true;
        }
        if (self::locationIsMaterial($concepts, $facts) && $locs === []) {
            $gaps['location'] = true;
        }
        if (!empty($facts['needs_location_clarification'])) {
            $gaps['location'] = true;
        }
        if (!$hasTiming && self::timingIsMaterial($concepts, $facts)) {
            $gaps['timing'] = true;
        }
        if (!empty($facts['needs_associated_detail'])
            || (($facts['has_other_symptoms'] ?? null) === true && self::associatedSymptomNames($facts) === [] && empty($facts['denied_associated']))
        ) {
            $gaps['associated_detail'] = true;
        }
        if (!$assocDone && self::associatedIsMaterial($concepts, $facts, $transcript)) {
            $gaps['associated'] = true;
        }

        // Any red-flag bank item whose domain tags match and whose slot fact is unknown.
        foreach (ClinicalFollowUpQuestionBank::questions() as $question) {
            if (!is_array($question) || empty($question['red_flag_related'])) {
                continue;
            }
            $qid = strtoupper(trim((string) ($question['question_id'] ?? '')));
            $when = array_map('strtolower', (array) ($question['required_when'] ?? []));
            if ($when !== [] && array_intersect($when, $concepts) === []) {
                continue;
            }
            if (self::alreadyAnswered($qid, $facts, $transcript, $concepts, $caseHaystack)) {
                continue;
            }
            $gaps['red_flag'] = true;
            break;
        }

        // Narrow clinically relevant clarify only (not COUGH_TYPE / DIZZINESS_TYPE).
        // Urinary atoms remain material until the full bundle is resolved.
        if (in_array('urinary', $concepts, true)
            && !self::bundledAtomsResolved('URINARY_DETAIL', $facts, $caseHaystack)
        ) {
            $gaps['clarify'] = true;
        }
        // Fever confirmation when domain-eligible and not yet answered.
        if (!$gaps['clarify']
            && array_intersect($concepts, ['fever', 'cough', 'respiratory']) !== []
            && !self::alreadyAnswered('FEVER_CONFIRM', $facts, $transcript, $concepts, $caseHaystack)
        ) {
            $gaps['clarify'] = true;
        }

        return $gaps;
    }

    /**
     * @param list<string> $concepts
     * @param array<string, mixed> $facts
     */
    private static function caseSuggestsPainIntensity(array $concepts, array $facts, string $caseHaystack): bool
    {
        if (array_intersect($concepts, [
            'pain', 'pain_unspecified', 'pain_no_location', 'headache', 'chest_pain',
            'abdominal_pain', 'nose_pain', 'eye', 'eye_pain', 'dental_pain', 'musculoskeletal',
        ]) !== []) {
            return true;
        }
        // Universal: complaint language indicates pain/discomfort without relying on one disease label.
        return (bool) preg_match(
            '/\b(sakit|masakit|kasakit|gasakit|hapdi|pain|hurt|aching|cramp|cramping|throbbing|stinging|'
            . 'broken|fractured?|fracture|snapped|cracked|broke|nabali|bali|injury|injured|samad)\b/u',
            $caseHaystack
        );
    }

    /**
     * @param list<string> $concepts
     * @param array<string, mixed> $facts
     */
    private static function locationIsMaterial(array $concepts, array $facts): bool
    {
        // System-implied complaints already locate the problem clinically.
        if (array_intersect($concepts, [
            'urinary', 'cough', 'respiratory', 'breathing', 'fever', 'dizziness', 'bleeding',
            'gastrointestinal', 'cardiovascular', 'clinical_finding',
        ]) !== []) {
            return false;
        }

        return array_intersect($concepts, [
            'pain_unspecified', 'pain_no_location', 'general_unwell', 'needs_specific_location', 'skin',
        ]) !== []
            || (($facts['body_locations'] ?? []) === [] && array_intersect($concepts, ['pain', 'skin', 'injury']) !== []);
    }

    /**
     * @param list<string> $concepts
     * @param array<string, mixed> $facts
     */
    private static function timingIsMaterial(array $concepts, array $facts): bool
    {
        // Almost all clinical presentations benefit from onset/duration when unknown.
        if ($concepts === []) {
            return true;
        }
        if (array_intersect($concepts, ['general_unwell']) !== [] && ($facts['pain_score'] ?? null) === null) {
            return true;
        }

        return true;
    }

    /**
     * @param list<string> $concepts
     * @param array<string, mixed> $facts
     */
    private static function associatedIsMaterial(array $concepts, array $facts, string $transcript): bool
    {
        if (!empty($facts['denied_associated']) || ($facts['has_other_symptoms'] ?? null) === false) {
            return false;
        }
        $sev = $facts['pain_score'] ?? null;
        $sev = $sev !== null ? (int) $sev : null;
        $onset = mb_strtolower(trim((string) ($facts['onset'] ?? '')));
        $sudden = (bool) preg_match('/\b(sudden|gulpi|bigla|abrupt)\b/u', $onset . ' ' . mb_strtolower($transcript));
        // Seek associated symptoms when acuity cues exist or when core complaint facts are sparse.
        if ($sudden || ($sev !== null && $sev >= 5)) {
            return true;
        }
        if (array_intersect($concepts, ClinicalInterviewContextResolver::acuityRedFlagFamilies()) !== []) {
            return true;
        }
        // Non-pain system complaints still benefit from one associated probe when unresolved.
        if (array_intersect($concepts, [
            'respiratory', 'cough', 'gastrointestinal', 'cardiovascular', 'fever', 'skin', 'urinary', 'ear',
            'clinical_finding',
        ]) !== []) {
            return true;
        }
        // After severity+timing known, one associated probe is still useful when none recorded.
        $hasTiming = ClinicalFeatureExtractors::hasTimingInformation($transcript, $facts);

        return $hasTiming && ($sev !== null || self::bodyLocations($facts) !== []);
    }

    /**
     * Score a bank question using open gaps + bank priority (universal, not disease-specific).
     *
     * @param array<string, mixed> $question
     * @param array<string, bool> $gaps
     * @param array<string, mixed> $facts
     * @param list<string> $concepts
     */
    private static function scoreQuestionPriority(
        array $question,
        string $qid,
        array $gaps,
        array $facts,
        array $concepts
    ): ?int {
        $qid = strtoupper($qid);
        $bankPriority = (int) ($question['priority'] ?? 99);
        $gapKey = self::questionGapKey($qid);
        $redFlag = !empty($question['red_flag_related']);

        // Associated detail is mandatory once the patient said there are other symptoms.
        if ($qid === 'ASSOCIATED_DETAIL') {
            return !empty($gaps['associated_detail']) ? 1 : null;
        }

        if ($gapKey === 'severity' && empty($gaps['severity'])) {
            return null;
        }
        if ($gapKey === 'location' && empty($gaps['location'])) {
            return null;
        }
        if ($gapKey === 'timing' && empty($gaps['timing'])) {
            return null;
        }
        if ($gapKey === 'associated' && empty($gaps['associated'])) {
            return null;
        }
        if ($gapKey === 'associated_detail' && empty($gaps['associated_detail'])) {
            return null;
        }

        // Gap-driven boosts: for pain cases, numeric severity is first required
        // follow-up; then location; then timing / associated. Red-flags follow
        // severity when the scale is still open (domain-filtered only).
        if ($gapKey === 'severity' && !empty($gaps['severity'])) {
            return 2;
        }
        if ($gapKey === 'location' && !empty($gaps['location'])) {
            return 3;
        }
        if ($gapKey === 'associated_detail' && !empty($gaps['associated_detail'])) {
            return 3;
        }
        if ($gapKey === 'timing' && !empty($gaps['timing'])) {
            // Prefer ONSET slightly over DURATION when both open.
            return $qid === 'ONSET' ? 5 : 6;
        }
        if ($gapKey === 'associated' && !empty($gaps['associated'])) {
            return 10;
        }

        // Red-flag probes: only when domain-relevant (already filtered) and slot unknown.
        if ($redFlag) {
            $acuity = self::acuityCueScore($facts, $concepts);
            $boosted = max(1, min(25, $bankPriority - 25 - $acuity));
            // Keep PAIN_SEVERITY ahead while the 1–10 score is still missing.
            if (!empty($gaps['severity'])) {
                return max(3, $boosted);
            }

            return $boosted;
        }

        // Clarifying / character questions (cough type, dizziness type, urinary detail, etc.):
        // ask only when their domain tags matched and no higher gap still open for core triage.
        if (in_array($gapKey, ['clarify', 'detail'], true)) {
            if (!empty($gaps['severity']) || !empty($gaps['timing']) || !empty($gaps['associated_detail']) || !empty($gaps['red_flag'])) {
                return null;
            }

            return $bankPriority;
        }

        return $bankPriority;
    }

    /**
     * Extra priority boost for red-flag probes when the case already shows acuity cues.
     *
     * @param array<string, mixed> $facts
     * @param list<string> $concepts
     */
    private static function acuityCueScore(array $facts, array $concepts): int
    {
        $score = 0;
        $onset = mb_strtolower(trim((string) ($facts['onset'] ?? '')));
        if ((bool) preg_match('/\b(sudden|gulpi|bigla|abrupt|kalit)\b/u', $onset)) {
            $score += 3;
        }
        $sev = $facts['pain_score'] ?? null;
        if ($sev !== null && (int) $sev >= 7) {
            $score += 2;
        }
        if (array_intersect($concepts, ClinicalInterviewContextResolver::acuityRedFlagFamilies()) !== []) {
            $score += 2;
        }
        if (($facts['breathing_difficulty'] ?? null) === true
            || ($facts['weakness'] ?? null) === true
            || ($facts['speech_difficulty'] ?? null) === true
            || ($facts['bleeding_heavy'] ?? null) === true
        ) {
            $score += 3;
        }

        return min(8, $score);
    }

    private static function questionGapKey(string $qid): string
    {
        $qid = strtoupper($qid);

        return match ($qid) {
            'PAIN_SEVERITY' => 'severity',
            'PAIN_LOCATION', 'UNWELL_WHAT', 'SPECIFIC_LOCATION', 'EYE_LATERALITY', 'SKIN_SITE', 'NOSE_PAIN_WHERE' => 'location',
            'ONSET', 'DURATION' => 'timing',
            'ASSOCIATED_SYMPTOMS' => 'associated',
            'ASSOCIATED_DETAIL' => 'associated_detail',
            'BREATHING_SEVERITY', 'NEURO_WEAKNESS', 'NEURO_SPEECH', 'NEURO_VISION', 'EYE_VISION',
            'BLEEDING_CONTINUING', 'BLEEDING_HEAVY', 'BLEEDING_DIZZY',
            'CHEST_RADIATION', 'CHEST_SWEATING', 'ABDOMINAL_ASSOCIATED', 'FEVER_CONFIRM' => 'red_flag',
            'COUGH_TYPE', 'DIZZINESS_TYPE', 'URINARY_DETAIL' => 'clarify',
            default => 'detail',
        };
    }

    /**
     * Semantic/contextual: clinical purpose already covered by the full case text.
     *
     * @param array<string, mixed> $question
     * @param array<string, mixed> $facts
     */
    private static function purposeAlreadyKnown(array $question, string $caseHaystack, array $facts): bool
    {
        $purpose = mb_strtolower(trim((string) ($question['clinical_purpose'] ?? '')));
        $qid = strtoupper(trim((string) ($question['question_id'] ?? '')));
        if ($purpose === '' && $qid === '') {
            return false;
        }

        // Purpose keyword → evidence already present in the case haystack / facts.
        // Scope generic "associated" to ASSOCIATED_* slots only — do not treat
        // "Abdominal associated danger signs" as answered by denied_associated.
        $checks = [
            'pain score' => ($facts['pain_score'] ?? null) !== null,
            'numeric pain' => ($facts['pain_score'] ?? null) !== null,
            'locate' => self::bodyLocations($facts) !== [],
            'location' => self::bodyLocations($facts) !== [],
            'where' => self::bodyLocations($facts) !== [],
            'onset' => trim((string) ($facts['onset'] ?? '')) !== ''
                || ClinicalFeatureExtractors::hasTimingInformation($caseHaystack, $facts),
            'how long' => ClinicalFeatureExtractors::hasTimingInformation($caseHaystack, $facts),
            'duration' => ClinicalFeatureExtractors::hasTimingInformation($caseHaystack, $facts),
            'sudden versus gradual' => ClinicalFeatureExtractors::hasTimingInformation($caseHaystack, $facts),
            // Meaning-bridge / complaint language ("difficulty breathing") is NOT severity.
            // Only a structured breathing_difficulty fact closes BREATHING_SEVERITY.
            'breathing' => ($facts['breathing_difficulty'] ?? null) !== null,
            'weakness' => ($facts['weakness'] ?? null) !== null
                || (bool) preg_match('/\b(kaluya|panghihina|weakness|numbness|pamamanhid)\b/u', $caseHaystack),
            'speech' => ($facts['speech_difficulty'] ?? null) !== null,
            'vision' => ($facts['vision_change'] ?? null) !== null
                || (bool) preg_match('/\b(panulok|paningin|blurry|double vision|vision loss)\b/u', $caseHaystack),
            'other symptoms' => ($facts['has_other_symptoms'] ?? null) !== null || !empty($facts['denied_associated']),
            'bleeding is ongoing' => ($facts['bleeding_continuing'] ?? null) !== null,
            'heavy' => ($facts['bleeding_heavy'] ?? null) !== null,
            // Presence of dizziness must not satisfy DIZZINESS_TYPE ("Clarify dizziness character").
            'dizzy' => $qid !== 'DIZZINESS_TYPE' && ($facts['dizziness'] ?? null) !== null,
            'fever' => (bool) preg_match('/\b(fever|lagnat|hilanat|wala\s+(sang\s+)?(lagnat|hilanat)|no fever)\b/u', $caseHaystack),
        ];
        if ($qid === 'ASSOCIATED_SYMPTOMS' || $qid === 'ASSOCIATED_DETAIL') {
            $checks['associated'] = self::associatedSymptomsResolved($facts);
        }

        // Severity slots: only structured follow-up answers close them — never complaint gloss.
        if ($qid === 'PAIN_SEVERITY') {
            return ($facts['pain_score'] ?? null) !== null;
        }
        if ($qid === 'BREATHING_SEVERITY') {
            // Presence inferred from the complaint must not mark this purpose known.
            return false;
        }

        foreach ($checks as $needle => $known) {
            if ($known && str_contains($purpose, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $concepts
     * @param array<string, mixed> $facts
     * @return list<string>
     */
    private static function enrichConcepts(
        array $concepts,
        array $facts,
        string $transcript,
        string $caseHaystack = ''
    ): array {
        // Prefer full-case probe (patient text + meaning bridge) over raw transcript alone.
        $low = mb_strtolower(trim($caseHaystack !== '' ? $caseHaystack : $transcript));
        if (($facts['breathing_difficulty'] ?? null) === true
            || preg_match(
                '/\b(budlay|ginhawa|dyspnea|hirap\s+huminga|cannot\s+breathe|can\'t\s+breathe|'
                . 'difficulty\s+breathing|shortness\s+of\s+breath|breathing_difficulty)\b/u',
                $low
            )
        ) {
            $concepts[] = 'breathing';
            $concepts[] = 'respiratory';
        }
        if (preg_match('/\b(ginaubo|nagauubo|cough|ubo)\b/u', $low)) {
            $concepts[] = 'cough';
            $concepts[] = 'respiratory';
        }
        if (preg_match('/\b(fever|lagnat|hilanat)\b/u', $low)) {
            $concepts[] = 'fever';
        }
        $locs = self::bodyLocations($facts);
        // Do not invent "unspecified pain location" when the complaint already implies
        // a system/site (urinary, respiratory, skin, etc.) — avoids irrelevant PAIN_LOCATION.
        $siteImplied = array_intersect($concepts, [
            'urinary', 'cough', 'respiratory', 'breathing', 'fever', 'skin', 'bleeding',
            'dizziness', 'eye', 'nose_pain', 'chest_pain', 'abdominal_pain', 'headache',
            'gastrointestinal', 'cardiovascular', 'ear', 'dental_pain', 'clinical_finding',
        ]) !== [];
        // Pain families already derived from datasets/concepts also count (semantic path).
        $painFamily = array_intersect($concepts, [
            'pain', 'pain_unspecified', 'pain_no_location', 'headache', 'chest_pain',
            'abdominal_pain', 'nose_pain', 'eye', 'dental_pain', 'ear', 'musculoskeletal',
        ]) !== [];
        // Offline lexical safety + English gloss from meaning bridge (via caseHaystack).
        // Do not grow per-dialect example lists here — bridge supplies local expressions.
        $hasPainLanguage = $painFamily || (bool) preg_match(
            '/\b(sakit|masakit|pain|hapdi|kasakit|gasakit|hurts?)\b/u',
            $low
        );
        // Always tag pain when indicated — including when the site is already known —
        // so PAIN_SEVERITY stays eligible.
        if ($hasPainLanguage) {
            $concepts[] = 'pain';
            if ($locs === [] && !$siteImplied) {
                $concepts[] = 'pain_unspecified';
                $concepts[] = 'pain_no_location';
            }
        }
        // Ear / clinical finding with pain language → allow pain severity/location bank tags.
        if ((in_array('ear', $concepts, true) || in_array('clinical_finding', $concepts, true))
            && $hasPainLanguage
        ) {
            $concepts[] = 'pain';
        }
        // Health-related but no established symptom/site → clarify first (never assume pain).
        if (!$siteImplied && !$hasPainLanguage && (
            in_array('general_unwell', $concepts, true)
            || (class_exists('ClinicalFeatureExtractors') && ClinicalFeatureExtractors::isVagueComplaint($transcript))
        )) {
            $concepts[] = 'general_unwell';
        }
        if (in_array('eye', $locs, true) || preg_match('/\b(mata|eye)\b/u', $low)) {
            $concepts[] = 'eye';
            $concepts[] = 'needs_laterality';
        }
        if ((bool) preg_match('/\b(burn|burns|nasunog|paso|scald|hubag|gahabok|gahubag|swelling|swollen|pamamaga)\b/u', $low)) {
            $concepts[] = 'skin';
        }
        if (in_array('abdomen', $locs, true) || in_array('chest', $locs, true)) {
            $concepts[] = 'needs_specific_location';
        }

        return $concepts;
    }

    /**
     * @param array<string, mixed> $facts
     * @param list<string> $concepts
     */
    private static function alreadyAnswered(
        string $qid,
        array $facts,
        string $transcript,
        array $concepts,
        string $caseHaystack = ''
    ): bool {
        $qid = strtoupper($qid);
        $hay = $caseHaystack !== '' ? $caseHaystack : mb_strtolower($transcript);
        $hasTiming = ClinicalFeatureExtractors::hasTimingInformation($transcript, $facts)
            || ClinicalFeatureExtractors::hasTimingInformation($hay, $facts);
        // Numeric 1–10 only completes severity — intensifiers alone do not.
        $hasSeverity = ($facts['pain_score'] ?? null) !== null;
        $assocYesPendingDetail = !empty($facts['needs_associated_detail'])
            || (($facts['has_other_symptoms'] ?? null) === true && self::associatedSymptomNames($facts) === []);
        $findingStatus = is_array($facts['finding_status'] ?? null) ? $facts['finding_status'] : [];
        $targetFinding = self::defaultTargetFinding($qid);
        if (($findingStatus[$targetFinding] ?? '') === 'uncertain') {
            return true;
        }
        // Locations from patient speech / facts only — never from bank question text in $hay
        // (e.g. NEURO_WEAKNESS wording mentions kamot/tiil and must not invent arm/leg facts).
        $locs = self::bodyLocations($facts);
        if ($locs === []) {
            $locs = ClinicalFeatureExtractors::extractBodyLocations($transcript);
        }
        $patientHay = mb_strtolower(trim($transcript));

        return match ($qid) {
            'PAIN_LOCATION', 'UNWELL_WHAT' => $locs !== []
                || (
                    !in_array('pain_unspecified', $concepts, true)
                    && !in_array('general_unwell', $concepts, true)
                    && !in_array('pain_no_location', $concepts, true)
                ),
            'PAIN_SEVERITY' => $hasSeverity,
            'ONSET', 'DURATION' => $hasTiming,
            'NEURO_WEAKNESS' => ($facts['weakness'] ?? null) !== null
                || (bool) preg_match('/\b(no|wala|hindi|indi|without)\s+(weakness|numbness|pamamanhid|numb|kaluya)\b/u', $patientHay)
                || (bool) preg_match('/nangaluya|kaluya|one[- ]sided|wala nga kamot|weakness in one/u', $patientHay),
            // Each neuro probe is independent — answering weakness must not suppress speech/vision.
            'NEURO_SPEECH' => ($facts['speech_difficulty'] ?? null) !== null
                || (bool) preg_match('/\b(slurred|speech\s+difficult|indi\s+makahambal|hindi\s+makapagsalita|cannot\s+speak)\b/u', $patientHay),
            'NEURO_VISION', 'EYE_VISION' => ($facts['vision_change'] ?? null) !== null
                || (bool) preg_match('/\b(sudden\s+vision|vision\s+loss|double\s+vision|blurry\s+vision|nawala\s+panulok|malabo\s+(ang\s+)?paningin)\b/u', $patientHay),
            'BREATHING_SEVERITY' => ($facts['breathing_difficulty'] ?? null) !== null,
            'BLEEDING_CONTINUING' => ($facts['bleeding_continuing'] ?? null) !== null,
            'BLEEDING_HEAVY' => ($facts['bleeding_heavy'] ?? null) !== null,
            'BLEEDING_DIZZY' => ($facts['dizziness'] ?? null) !== null,
            // Chest findings are independent of breathing answers and generic "no other symptoms".
            'CHEST_RADIATION' => ($facts['chest_radiation'] ?? null) !== null,
            // CHEST_SWEATING completes only when every atom is known — a lone sweating fact
            // (e.g. from sweating_with_chest) must not suppress dizziness_with_chest.
            'CHEST_SWEATING' => self::bundledAtomsResolved('CHEST_SWEATING', $facts, $caseHaystack),
            // Abdominal RF atoms: parent boolean only when set by a non-atomic answer;
            // otherwise require every atom (atomic answers no longer set the parent flag).
            'ABDOMINAL_ASSOCIATED' => ($facts['abdominal_associated'] ?? null) !== null
                || self::bundledAtomsResolved('ABDOMINAL_ASSOCIATED', $facts, $caseHaystack),
            'ASSOCIATED_SYMPTOMS' => ($facts['has_other_symptoms'] ?? null) !== null
                || !empty($facts['denied_associated']),
            'ASSOCIATED_DETAIL' => !$assocYesPendingDetail,
            'EYE_LATERALITY' => (bool) preg_match('/\b(left|right|tuo|wala|both|duha|kaliwa|kanan)\b/u', $hay) || $locs !== [],
            'SPECIFIC_LOCATION' => (bool) preg_match('/\b(upper|lower|tuo|wala|left|right|pusod|center|tunga)\b/u', $hay),
            'NOSE_PAIN_WHERE' => (bool) preg_match('/\b(bridge|tip|nostril|tuod|pungos)\b/u', $hay),
            'SKIN_SITE' => $locs !== [],
            'FEVER_CONFIRM' => (bool) preg_match('/\b(fever|lagnat|hilanat|wala\s+(sang\s+)?(lagnat|hilanat)|no fever)\b/u', $hay),
            // Character/type only — shared dizziness presence (incl. chest atom) is not enough.
            'DIZZINESS_TYPE' => ($findingStatus['dizziness_type'] ?? '') !== ''
                || (bool) preg_match(
                    '/\b(spin(?:ning)?|vertigo|tuyok|light[- ]?headed|gaan\s+(ang\s+)?ulo|unsteady|indi\s+ka\s+stabile|room\s+spin)\b/u',
                    $hay
                ),
            // Bundle completes only when every urinary atom is known (per-atom haystack/status).
            'URINARY_DETAIL' => self::bundledAtomsResolved('URINARY_DETAIL', $facts, $caseHaystack),
            default => false,
        };
    }

    /**
     * @param array<string, mixed> $facts
     */
    private static function bundledAtomsResolved(string $qid, array $facts, string $caseHaystack): bool
    {
        $atoms = match (strtoupper($qid)) {
            'ABDOMINAL_ASSOCIATED' => [
                ['finding' => 'vomiting', 'skip' => 'vomit|suka|nagsusuka|retch|emesis|throwing up|throw up'],
                ['finding' => 'fever_with_abdomen', 'skip' => 'fever|lagnat|hilanat|febrile|pyrexia'],
                ['finding' => 'bleeding_with_abdomen', 'skip' => 'bleed|blood|dugo|nagadugo|dumudugo'],
            ],
            'CHEST_SWEATING' => [
                ['finding' => 'sweating_with_chest', 'skip' => 'sweat|singot|pinagpapawisan|diaphore'],
                ['finding' => 'dizziness_with_chest', 'skip' => 'dizz|faint|lipong|hilo|punaw|lightheaded|light-headed|woozy|vertigo'],
            ],
            'URINARY_DETAIL' => [
                ['finding' => 'urinary_burning', 'skip' => 'burn|hapdi'],
                ['finding' => 'urinary_blood', 'skip' => 'blood|dugo'],
                ['finding' => 'urinary_fever', 'skip' => 'fever|lagnat|hilanat|febrile|pyrexia'],
            ],
            default => [],
        };
        if ($atoms === []) {
            return false;
        }
        foreach ($atoms as $atom) {
            if (!self::findingAlreadyKnown((string) $atom['finding'], $facts, $caseHaystack, (string) $atom['skip'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $facts
     */
    private static function associatedSymptomsResolved(array $facts): bool
    {
        if (!empty($facts['denied_associated']) || ($facts['has_other_symptoms'] ?? null) === false) {
            return true;
        }
        if (!empty($facts['needs_associated_detail'])) {
            return false;
        }
        if (($facts['has_other_symptoms'] ?? null) === true && self::associatedSymptomNames($facts) !== []) {
            return true;
        }

        return ($facts['has_other_symptoms'] ?? null) !== null
            && self::associatedSymptomNames($facts) !== [];
    }

    /**
     * @param array<string, mixed> $facts
     * @return list<string>
     */
    private static function associatedSymptomNames(array $facts): array
    {
        $names = is_array($facts['associated_symptoms'] ?? null) ? $facts['associated_symptoms'] : [];

        return array_values(array_filter(array_map(
            static fn ($v): string => trim((string) $v),
            $names
        ), static fn (string $v): bool => $v !== ''));
    }

    /**
     * @param array<string, mixed> $facts
     * @return list<string>
     */
    private static function bodyLocations(array $facts): array
    {
        $locs = is_array($facts['body_locations'] ?? null) ? $facts['body_locations'] : [];

        return array_values(array_filter(array_map(
            static fn ($v): string => strtolower(trim((string) $v)),
            $locs
        )));
    }
}
