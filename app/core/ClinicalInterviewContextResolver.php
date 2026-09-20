<?php
/**
 * Dataset-driven complaint and follow-up family resolution for the universal interview gate.
 * Signals live in data/nlp/clinical_interview_families.json — not hard-coded per example complaint.
 *
 * Concept promotion reuses SymptomKnowledgeBase, MedicalConceptRegistry, and MedicalDictionary
 * so recognized clinical findings map into families even when exact phrasing is absent from regexes.
 */

final class ClinicalInterviewContextResolver
{
    private const PATH = BASE_PATH . '/data/nlp/clinical_interview_families.json';

    /** @var array<string, mixed>|null */
    private static ?array $config = null;

    /**
     * @param array<string, mixed> $assessment
     * @param array<string, mixed> $facts
     * @return list<array{id:string,name:string,family_key:string}>
     */
    public static function deriveComplaints(array $assessment, string $transcript, array $facts): array
    {
        $config = self::config();
        $concepts = self::collectMatchedConcepts($assessment, $transcript);
        $hay = self::buildHaystack($assessment, $transcript, $concepts);
        $found = [];

        foreach ((array) ($config['complaints'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (!self::matchesComplaint($row, $hay, $assessment, $facts, $transcript, $concepts)) {
                continue;
            }
            $found[] = [
                'id'          => strtoupper((string) ($row['complaint_id'] ?? '')),
                'name'        => (string) ($row['label'] ?? ''),
                'family_key'  => strtolower((string) ($row['family_key'] ?? '')),
            ];
        }

        $hasPainToken = (bool) preg_match('/\b(sakit|masakit|pain|hurts|hapdi|discomfort)\b/u', mb_strtolower($transcript));
        $hasLocation = ($facts['body_locations'] ?? []) !== []
            || ClinicalFeatureExtractors::extractBodyLocations($transcript) !== [];
        $hasHealthcare = self::hasHealthcareSignal($transcript, $concepts);

        foreach ((array) ($config['fallback_complaints'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (!self::matchesFallback($row, $found, $transcript, $hasPainToken, $hasLocation, $hasHealthcare)) {
                continue;
            }
            $id = strtoupper((string) ($row['complaint_id'] ?? ''));
            if (self::hasComplaintId($found, $id)) {
                continue;
            }
            $found[] = [
                'id'         => $id,
                'name'       => (string) ($row['label'] ?? ''),
                'family_key' => strtolower((string) ($row['family_key'] ?? '')),
            ];
        }

        return $found;
    }

    /**
     * @param list<array{id?:string,name?:string,family_key?:string}> $complaints
     * @param array<string, mixed> $facts
     * @return list<string>
     */
    public static function deriveFamilies(array $complaints, string $transcript, array $facts): array
    {
        $keys = [];
        foreach ($complaints as $row) {
            $family = strtolower((string) ($row['family_key'] ?? ''));
            if ($family === '') {
                $family = strtolower(str_replace(' ', '_', (string) ($row['id'] ?? '')));
            }
            if ($family !== '') {
                $keys[] = $family;
            }
        }

        foreach ((array) (self::config()['derived_families'] ?? []) as $derived) {
            if (!is_array($derived)) {
                continue;
            }
            $key = strtolower((string) ($derived['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $whenAny = array_map('strtolower', (array) ($derived['add_when_family_any'] ?? []));
            if ($whenAny !== [] && array_intersect($whenAny, $keys) !== []) {
                $keys[] = $key;
            }
            if (!empty($derived['add_when_no_body_location'])
                && ($facts['body_locations'] ?? []) === []
                && array_intersect($whenAny, $keys) !== []
            ) {
                $keys[] = $key;
            }
            if (!empty($derived['add_when_vague_or_unlocated_pain'])) {
                // Only when pain is already indicated — never invent pain from vague unwell.
                $painIndicated = array_intersect($keys, ['pain', 'pain_unspecified', 'pain_no_location']) !== [];
                if ($painIndicated
                    && (
                        ClinicalFeatureExtractors::isVagueComplaint($transcript)
                        || (in_array('pain_unspecified', $keys, true) && ($facts['body_locations'] ?? []) === [])
                    )
                ) {
                    $keys[] = $key;
                }
            }
        }

        if ($keys === []) {
            // Never assume pain when the patient did not express pain language.
            $hasPainToken = (bool) preg_match(
                '/\b(sakit|masakit|pain|hurts|hapdi|discomfort|kasakit|gasakit)\b/u',
                mb_strtolower($transcript)
            );
            if ($hasPainToken) {
                $keys[] = 'pain_unspecified';
            } elseif (self::hasHealthcareSignal($transcript, self::collectMatchedConcepts([], $transcript))) {
                $keys[] = 'general_unwell';
            }
            // Non-health / greeting language: leave empty rather than invent a medical family.
        }

        if (($facts['body_locations'] ?? []) === []
            && (
                in_array('pain_unspecified', $keys, true)
                || (
                    ClinicalFeatureExtractors::isVagueComplaint($transcript)
                    && in_array('pain_unspecified', $keys, true)
                )
            )
        ) {
            $keys[] = 'pain_no_location';
        }

        return array_values(array_unique($keys));
    }

    public static function lowPriorityThreshold(): int
    {
        return max(1, (int) (self::config()['low_priority_threshold'] ?? 40));
    }

    /**
     * Generic body-region specificity rules (general location ≠ specific site).
     *
     * @return array<string, mixed>
     */
    public static function locationSpecificityRules(): array
    {
        return is_array(self::config()['location_specificity'] ?? null)
            ? self::config()['location_specificity']
            : [];
    }

    /**
     * Concept tags where red-flag bank questions remain clinically indicated
     * even when acuity is low/uncertain.
     *
     * @return list<string>
     */
    public static function acuityRedFlagFamilies(): array
    {
        $rows = (array) (self::config()['acuity_red_flag_families'] ?? [
            'chest_pain', 'breathing', 'bleeding', 'neuro', 'abdominal_pain', 'eye',
        ]);

        return array_values(array_filter(array_map(
            static fn ($v): string => strtolower(trim((string) $v)),
            $rows
        )));
    }

    /**
     * Collect canonical clinical concepts from triage KB hits + local KB/dictionary matching.
     *
     * @param array<string, mixed> $assessment
     * @return list<array{id:string,medical_category:string,symptom_name:string}>
     */
    public static function collectMatchedConcepts(array $assessment, string $transcript): array
    {
        $out = [];
        $seen = [];

        $add = static function (string $id, string $category, string $name) use (&$out, &$seen): void {
            $id = strtolower(trim($id));
            if ($id === '' || isset($seen[$id])) {
                return;
            }
            // Dictionary hits: promote only genuine clinical findings/conditions —
            // never bare anatomy and never ultra-generic tokens (e.g. dict_pain, dict_hello).
            if (str_starts_with($id, 'dict_')) {
                $promoted = self::promoteDictionaryClinicalFinding($id, $category, $name);
                if ($promoted === null) {
                    return;
                }
                $id = $promoted['id'];
                $category = $promoted['medical_category'];
                $name = $promoted['symptom_name'];
                if ($id === '' || isset($seen[$id])) {
                    return;
                }
            }
            $seen[$id] = true;
            $out[] = [
                'id'               => $id,
                'medical_category' => strtolower(trim($category)),
                'symptom_name'     => $name,
            ];
        };

        $triage = is_array($assessment['triage'] ?? null) ? $assessment['triage'] : [];
        foreach ((array) ($triage['kb_matched_symptoms'] ?? []) as $sym) {
            if (!is_array($sym)) {
                continue;
            }
            $add(
                (string) ($sym['id'] ?? ''),
                (string) ($sym['medical_category'] ?? ''),
                (string) ($sym['symptom_name'] ?? $sym['matched_term'] ?? '')
            );
        }

        foreach ((array) ($assessment['detected_symptoms'] ?? []) as $item) {
            if (!is_string($item) || trim($item) === '') {
                continue;
            }
            $resolved = class_exists('MedicalConceptRegistry')
                ? MedicalConceptRegistry::resolve($item)
                : null;
            if ($resolved !== null) {
                $add(
                    (string) $resolved['concept_id'],
                    (string) $resolved['medical_category'],
                    (string) $resolved['canonical_name']
                );
            } else {
                $add('detected_' . md5(mb_strtolower(trim($item))), '', trim($item));
            }
        }

        if ($transcript !== '' && class_exists('SymptomKnowledgeBase')) {
            // matchSymptoms already folds dictionary/lexicon synonyms; do not expand
            // every dictionary substring into the haystack (causes false multi-family hits).
            foreach (SymptomKnowledgeBase::matchSymptoms($transcript) as $sym) {
                if (!is_array($sym)) {
                    continue;
                }
                $add(
                    (string) ($sym['id'] ?? ''),
                    (string) ($sym['medical_category'] ?? ''),
                    (string) ($sym['symptom_name'] ?? $sym['matched_term'] ?? '')
                );
            }
        }

        // Drop placeholder detected_* entries that never resolved.
        return array_values(array_filter(
            $out,
            static fn (array $row): bool => !str_starts_with($row['id'], 'detected_')
        ));
    }

    /**
     * Promote a dictionary clinical hit into a concept when it is a genuine finding/condition.
     * Rejects body-only anatomy and ultra-generic words that are not findings.
     *
     * @return array{id:string,medical_category:string,symptom_name:string}|null
     */
    private static function promoteDictionaryClinicalFinding(string $dictId, string $category, string $name): ?array
    {
        $en = strtolower(trim((string) preg_replace('/^dict_/u', '', $dictId)));
        $en = strtolower(trim(str_replace('_', ' ', $en)));
        $label = trim($name);
        if ($label !== '') {
            $enFromName = strtolower($label);
            if ($enFromName !== '') {
                $en = $enFromName;
            }
        }
        if ($en === '') {
            return null;
        }

        $cat = strtolower(trim($category));
        // Body-part dictionary rows stay location evidence only.
        if (str_contains($cat, 'body') && !self::englishLooksLikePhysicalFinding($en)) {
            return null;
        }

        // Ultra-generic tokens that previously caused false family promotion.
        $rejectExact = [
            'pain', 'hello', 'hi', 'thanks', 'thank you', 'sick', 'ill', 'unwell', 'condition',
            'symptom', 'disease', 'problem', 'issue', 'feeling', 'feel',
            // Bare organ / anatomy nouns are location evidence, not clinical findings.
            // (dict_heart / "Heart" must not promote heart_failure_symptoms → CARDIOVASCULAR)
            'heart', 'lung', 'lungs', 'kidney', 'kidneys', 'liver', 'stomach', 'bladder',
            'brain', 'bone', 'bones', 'muscle', 'muscles', 'skin', 'blood', 'urine',
        ];
        if (in_array($en, $rejectExact, true)) {
            return null;
        }

        $clinicalCat = str_contains($cat, 'condition')
            || str_contains($cat, 'finding')
            || str_contains($cat, 'sign')
            || str_contains($cat, 'symptom')
            || str_contains($cat, 'disease');

        if (!$clinicalCat && !self::englishLooksLikePhysicalFinding($en)) {
            return null;
        }

        // Prefer an exact KB concept id when present (e.g. swelling → swelling).
        $candidateId = strtolower((string) preg_replace('/\s+/u', '_', $en));
        if (class_exists('MedicalConceptRegistry')) {
            $byId = MedicalConceptRegistry::concepts();
            if (isset($byId[$candidateId]) && is_array($byId[$candidateId])) {
                return [
                    'id'               => $candidateId,
                    'medical_category' => (string) ($byId[$candidateId]['medical_category'] ?? 'finding'),
                    'symptom_name'     => (string) ($byId[$candidateId]['canonical_name'] ?? ($label !== '' ? $label : ucwords($en))),
                ];
            }
        }

        // Physical findings / masses / lesions → generic finding concept path.
        if (self::englishLooksLikePhysicalFinding($en)) {
            return [
                'id'               => 'finding_' . $candidateId,
                'medical_category' => 'finding',
                'symptom_name'     => $label !== '' ? $label : ucwords($en),
            ];
        }

        // Other clinical conditions from the dictionary (non-generic) → finding/condition concept.
        if ($clinicalCat) {
            return [
                'id'               => 'finding_' . $candidateId,
                'medical_category' => $cat !== '' ? $cat : 'condition',
                'symptom_name'     => $label !== '' ? $label : ucwords($en),
            ];
        }

        return null;
    }

    /** Category-level physical finding vocabulary (not complaint-specific phrases). */
    private static function englishLooksLikePhysicalFinding(string $english): bool
    {
        return (bool) preg_match(
            '/\b(mass|lump|swelling|swollen|swell|lesion|nodule|abscess|cyst|tumor|tumour|'
            . 'goiter|goitre|wart|ulcer|bruise|hematoma|haematoma|blister|boil|bump|growth|'
            . 'rash|hives|pustule|papule|wheal)\b/u',
            strtolower($english)
        );
    }

    /**
     * Clinical-evidence haystack for family matching.
     * Intentionally excludes body-part / location tokens so anatomical evidence
     * cannot invent a symptom family by itself.
     *
     * @param array<string, mixed> $assessment
     * @param list<array{id:string,medical_category:string,symptom_name:string}> $concepts
     */
    private static function buildHaystack(array $assessment, string $transcript, array $concepts = []): string
    {
        $parts = [mb_strtolower($transcript)];
        foreach ((array) ($assessment['detected_symptoms'] ?? []) as $item) {
            if (is_string($item) && trim($item) !== '') {
                $parts[] = mb_strtolower(trim($item));
            }
        }
        $triage = is_array($assessment['triage'] ?? null) ? $assessment['triage'] : [];
        foreach ((array) ($triage['kb_matched_symptoms'] ?? []) as $sym) {
            if (!is_array($sym)) {
                continue;
            }
            foreach (['symptom_name', 'matched_term', 'id'] as $field) {
                $v = trim((string) ($sym[$field] ?? ''));
                if ($v !== '') {
                    $parts[] = mb_strtolower($v);
                }
            }
        }
        foreach ($concepts as $concept) {
            foreach (['symptom_name', 'id'] as $field) {
                $v = trim((string) ($concept[$field] ?? ''));
                if ($v !== '') {
                    $parts[] = mb_strtolower(str_replace('_', ' ', $v));
                }
            }
        }

        return implode(' ', $parts);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $assessment
     * @param array<string, mixed> $facts
     * @param list<array{id:string,medical_category:string,symptom_name:string}> $concepts
     */
    private static function matchesComplaint(
        array $row,
        string $hay,
        array $assessment,
        array $facts,
        string $transcript,
        array $concepts = []
    ): bool {
        foreach ((array) ($row['text_regex_exclude'] ?? []) as $pattern) {
            if (is_string($pattern) && $pattern !== '' && preg_match('/' . $pattern . '/iu', $hay)) {
                return false;
            }
        }

        $conceptIds = [];
        foreach ($concepts as $concept) {
            $cid = strtolower((string) ($concept['id'] ?? ''));
            if ($cid !== '') {
                $conceptIds[] = $cid;
            }
        }

        $triage = is_array($assessment['triage'] ?? null) ? $assessment['triage'] : [];
        foreach ((array) ($triage['kb_matched_symptoms'] ?? []) as $sym) {
            if (is_array($sym)) {
                $conceptIds[] = mb_strtolower((string) ($sym['id'] ?? ''));
            }
        }
        $conceptIds = array_values(array_unique(array_filter($conceptIds)));

        $clinicalEvidence = self::hasClinicalFindingEvidence($transcript, $concepts, $assessment);

        // 1) Concept IDs — complaint-specific findings only.
        // Broad medical_categories alone must NOT promote sibling families
        // (e.g. eye_pain/sensory → EAR, nausea/GI → false GI with dizziness).
        foreach ((array) ($row['symptom_ids'] ?? []) as $sid) {
            $sid = mb_strtolower(trim((string) $sid));
            if ($sid !== '' && in_array($sid, $conceptIds, true)) {
                return true;
            }
        }

        // 2) Named clinical phrases / text signals on the clinical haystack.
        // Anatomical aliases alone (eye, mata, chest, ulo, …) must not invent a family.
        $nameHit = false;
        foreach ((array) ($row['symptom_name_contains'] ?? []) as $needle) {
            $needle = mb_strtolower(trim((string) $needle));
            if ($needle !== '' && str_contains($hay, $needle)) {
                $nameHit = true;
                break;
            }
        }
        $textHit = false;
        foreach ((array) ($row['text_regex'] ?? []) as $pattern) {
            if (is_string($pattern) && $pattern !== '' && preg_match('/' . $pattern . '/iu', $hay)) {
                $textHit = true;
                break;
            }
        }
        if ($nameHit || $textHit) {
            if ($clinicalEvidence || !self::isPrimarilyAnatomicalUtterance($transcript, $facts)) {
                return true;
            }
        }

        // 3) Body location may refine a clinically supported complaint — never create one alone.
        // Require the anatomical site to appear in the patient transcript (not only in facts that
        // may contain false laterality→site aliases such as left/wala/fever → abdomen).
        if ($clinicalEvidence
            && self::matchesBodyPartEvidence($row, $facts, $triage)
            && self::bodyPartMentionedInTranscript($row, $transcript)
        ) {
            return true;
        }

        if (!empty($row['requires_nose_pain_context'])
            && $clinicalEvidence
            && preg_match('/sakit|masakit|pain|hurts|ilong|nose/u', $transcript)
        ) {
            return preg_match('/' . implode('|', (array) ($row['text_regex'] ?? ['\\bilong\\b'])) . '/iu', $hay) === 1;
        }

        return false;
    }

    /**
     * True when symptom/finding/condition evidence exists (not location alone).
     *
     * @param list<array{id:string,medical_category:string,symptom_name:string}> $concepts
     * @param array<string, mixed> $assessment
     */
    private static function hasClinicalFindingEvidence(string $transcript, array $concepts, array $assessment): bool
    {
        if ($concepts !== []) {
            return true;
        }
        foreach ((array) ($assessment['detected_symptoms'] ?? []) as $item) {
            if (is_string($item) && trim($item) !== '') {
                return true;
            }
        }
        $triage = is_array($assessment['triage'] ?? null) ? $assessment['triage'] : [];
        foreach ((array) ($triage['kb_matched_symptoms'] ?? []) as $sym) {
            if (is_array($sym) && trim((string) ($sym['id'] ?? '')) !== '') {
                return true;
            }
        }

        // Generic clinical finding language (multilingual symptom/finding cues),
        // not anatomical nouns or laterality alone.
        $low = mb_strtolower($transcript);
        if (preg_match(
            '/\b(sakit|masakit|kasakit|gasakit|pain|hurts?|aching|hapdi|discomfort|'
            . 'fever|lagnat|hilanat|pyrexia|cough|ubo|gahika|sip-?on|'
            . 'bleed|dugo|hemorrhag|vomit|suka|nausea|diarrh|tae|pagtatae|'
            . 'dizzy|dizziness|hilo|malipong|rash|itch|kati|hubag|swell|'
            . 'weak(?:ness)?|nangaluya|nanghihina|panghihina|numb|pamamanhid|nanlalata|seizure|kombulsiyon|'
            . 'breath|ginhawa|hinga|wheez|dyspn|burn|paso|dysuria|'
            . 'headache|migraine|palpitation|arrhythmia|'
            . 'broken|fractured?|fracture|snapped|cracked|broke|nabali|bali|'
            . 'injury|injured|samad|wound|trauma|napilasan|naligli)\b/u',
            $low
        )) {
            return true;
        }

        return false;
    }

    /**
     * True when the utterance is essentially anatomical/location language
     * (body part, alias, laterality) without residual clinical content.
     *
     * @param array<string, mixed> $facts
     */
    private static function isPrimarilyAnatomicalUtterance(string $transcript, array $facts): bool
    {
        $locs = [];
        foreach ((array) ($facts['body_locations'] ?? []) as $loc) {
            $loc = mb_strtolower(trim((string) $loc));
            if ($loc !== '') {
                $locs[] = $loc;
            }
        }
        if ($locs === []) {
            $locs = ClinicalFeatureExtractors::extractBodyLocations($transcript);
        }
        if ($locs === []) {
            return false;
        }

        $residual = mb_strtolower(trim($transcript));
        $residual = (string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $residual);
        $residual = (string) preg_replace('/\s+/u', ' ', $residual);

        // Strip laterality / filler grammar — not clinical findings.
        $residual = (string) preg_replace(
            '/\b(left|right|both|bilateral|upper|lower|side|sa|sang|ng|ang|the|my|ako|ko|'
            . 'tuo|wala|kaliwa|kanan|duha|both|isa|part|parte|area|region)\b/u',
            ' ',
            $residual
        );

        // Strip recognized location labels and common anatomical aliases.
        $anatomical = array_merge($locs, [
            'head', 'ulo', 'olo', 'chest', 'dughan', 'dibdib', 'abdomen', 'tiyan', 'tyan', 'tian',
            'stomach', 'belly', 'eye', 'mata', 'nose', 'ilong', 'ear', 'tenga', 'dulunggan', 'dalunggan',
            'tooth', 'teeth', 'ngipon', 'gum', 'throat', 'lalamunan', 'tutunlan', 'back', 'likod',
            'arm', 'leg', 'hand', 'foot', 'skin', 'panit', 'heart',
        ]);
        foreach ($anatomical as $term) {
            $term = mb_strtolower(trim((string) $term));
            if ($term === '') {
                continue;
            }
            $residual = (string) preg_replace('/\b' . preg_quote($term, '/') . '\b/u', ' ', $residual);
        }
        $residual = trim((string) preg_replace('/\s+/u', ' ', $residual));

        return $residual === '';
    }

    /**
     * True when the patient transcript mentions an anatomical token for this complaint's body_parts.
     *
     * @param array<string, mixed> $row
     */
    private static function bodyPartMentionedInTranscript(array $row, string $transcript): bool
    {
        $low = mb_strtolower($transcript);
        if ($low === '') {
            return false;
        }
        $aliases = [
            'abdomen' => ['abdomen', 'abdominal', 'stomach', 'belly', 'tummy', 'tiyan', 'tyan', 'tian', 'pusod', 'sikmura'],
            'stomach' => ['stomach', 'belly', 'tummy', 'tiyan', 'tyan', 'tian', 'sikmura', 'abdomen'],
            'chest' => ['chest', 'dughan', 'dibdib', 'thorax', 'breastbone'],
            'head' => ['head', 'ulo', 'olo', 'forehead', 'temple', 'cranial'],
            'eye' => ['eye', 'eyes', 'mata', 'panulok', 'paningin'],
            'ear' => ['ear', 'ears', 'dulunggan', 'tenga'],
            'nose' => ['nose', 'ilong', 'nasal'],
            'tooth' => ['tooth', 'teeth', 'ngipon', 'ngipin', 'gum', 'gums'],
            'teeth' => ['tooth', 'teeth', 'ngipon', 'ngipin'],
            'gum' => ['gum', 'gums', 'gilang'],
        ];
        foreach ((array) ($row['body_parts'] ?? []) as $part) {
            $part = mb_strtolower(trim((string) $part));
            if ($part === '') {
                continue;
            }
            $tokens = $aliases[$part] ?? [$part];
            foreach ($tokens as $token) {
                $token = trim((string) $token);
                if ($token !== '' && preg_match('/\b' . preg_quote($token, '/') . '\b/u', $low)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Location evidence only — used to refine a clinically supported family.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $facts
     * @param array<string, mixed> $triage
     */
    private static function matchesBodyPartEvidence(array $row, array $facts, array $triage): bool
    {
        foreach ((array) ($row['body_parts'] ?? []) as $part) {
            $part = mb_strtolower(trim((string) $part));
            if ($part === '') {
                continue;
            }
            foreach ((array) ($facts['body_locations'] ?? []) as $loc) {
                if (self::locationMatchesPart(mb_strtolower((string) $loc), $part)) {
                    return true;
                }
            }
            foreach ((array) ($triage['detected_body_parts'] ?? []) as $loc) {
                if (self::locationMatchesPart(mb_strtolower((string) $loc), $part)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Whole-token location match — avoid substring false positives (e.g. heart contains "ear"). */
    private static function locationMatchesPart(string $location, string $part): bool
    {
        $location = trim($location);
        $part = trim($part);
        if ($location === '' || $part === '') {
            return false;
        }
        if ($location === $part) {
            return true;
        }

        return (bool) preg_match('/\b' . preg_quote($part, '/') . '\b/u', $location);
    }

    /**
     * @param list<array{id?:string}> $found
     */
    private static function matchesFallback(
        array $row,
        array $found,
        string $transcript,
        bool $hasPainToken,
        bool $hasLocation,
        bool $hasHealthcare = true
    ): bool {
        if (!empty($row['requires_no_matched_complaint']) && $found !== []) {
            return false;
        }
        if (!empty($row['requires_pain_token']) && !$hasPainToken) {
            return false;
        }
        if (!empty($row['requires_no_pain_token']) && $hasPainToken) {
            return false;
        }
        if (!empty($row['requires_body_location']) && !$hasLocation) {
            return false;
        }
        if (!empty($row['requires_no_body_location']) && $hasLocation) {
            return false;
        }
        if (!empty($row['requires_vague_complaint']) && !ClinicalFeatureExtractors::isVagueComplaint($transcript)) {
            return false;
        }
        if (!empty($row['requires_healthcare_signal']) && !$hasHealthcare) {
            return false;
        }

        return true;
    }

    /**
     * @param list<array{id:string,medical_category:string,symptom_name:string}> $concepts
     */
    private static function hasHealthcareSignal(string $transcript, array $concepts): bool
    {
        if ($concepts !== []) {
            return true;
        }
        if (ClinicalFeatureExtractors::isVagueComplaint($transcript)) {
            return true;
        }
        if (class_exists('FaqChatbotDomainScope') && FaqChatbotDomainScope::isHealthcareRelated($transcript)) {
            return true;
        }

        return false;
    }

    /**
     * @param list<array{id?:string}> $found
     */
    private static function hasComplaintId(array $found, string $id): bool
    {
        foreach ($found as $row) {
            if (strtoupper((string) ($row['id'] ?? '')) === $id) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private static function config(): array
    {
        if (self::$config !== null) {
            return self::$config;
        }
        self::$config = [];
        if (!is_readable(self::PATH)) {
            return self::$config;
        }
        $decoded = json_decode((string) file_get_contents(self::PATH), true);
        self::$config = is_array($decoded) ? $decoded : [];

        return self::$config;
    }
}
