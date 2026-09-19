<?php
/**
 * Multi-complaint interview support for ClinicalInterviewEngine.
 *
 * Detects multiple clinical units in one patient message, keeps per-complaint
 * facts/Q&A tracks, and picks one active complaint at a time.
 * Does not triage. Does not replace ClinicalTriageEngine.
 * Single-complaint messages keep one track (backward compatible).
 */
final class ClinicalInterviewMultiComplaint
{
    /**
     * Ensure complaint tracks exist from the opening message / derived families.
     *
     * @param array<string, mixed> $context
     * @param array<string, mixed> $assessment
     * @return array<string, mixed>
     */
    public static function syncTracks(array $context, array $assessment, string $clinicalText): array
    {
        $original = trim((string) ($context['chief_complaint'] ?? ''));
        $hay = $clinicalText !== '' ? $clinicalText : $original;
        if ($hay === '') {
            return $context;
        }

        $existing = is_array($context['complaints'] ?? null) ? $context['complaints'] : [];
        if ($existing === []) {
            $context['complaints'] = self::buildTracks($assessment, $hay, $original, $context);
        } else {
            $context['complaints'] = self::refreshFamilies($existing, $assessment, $hay, $context);
        }

        if (($context['active_complaint_id'] ?? '') === ''
            || self::findTrack($context['complaints'], (string) $context['active_complaint_id']) === null
        ) {
            $context['active_complaint_id'] = self::pickActiveId($context['complaints'], $hay);
        }

        return self::hydrateActiveFacts($context);
    }

    /**
     * Write current context facts back into the active complaint track.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function persistActiveFacts(array $context): array
    {
        $activeId = (string) ($context['active_complaint_id'] ?? '');
        if ($activeId === '' || !is_array($context['complaints'] ?? null)) {
            return $context;
        }
        foreach ($context['complaints'] as $i => $track) {
            if (!is_array($track) || (string) ($track['id'] ?? '') !== $activeId) {
                continue;
            }
            $context['complaints'][$i]['facts'] = is_array($context['facts'] ?? null) ? $context['facts'] : [];
            $context['complaints'][$i]['questions_asked'] = array_values(array_map(
                'strval',
                (array) ($context['questions_asked'] ?? [])
            ));
            $context['complaints'][$i]['questions_answered'] = is_array($context['questions_answered'] ?? null)
                ? $context['questions_answered']
                : [];
            $context['complaints'][$i]['awaiting_question_id'] = (string) ($context['awaiting_question_id'] ?? '');
            break;
        }

        return $context;
    }

    /**
     * Choose next active complaint and expose its facts on context for adaptive policy.
     *
     * @param array<string, mixed> $context
     * @param array<string, mixed> $assessment
     * @return array<string, mixed>
     */
    public static function prepareForNextQuestion(array $context, array $assessment, string $clinicalText): array
    {
        $context = self::persistActiveFacts($context);
        $tracks = is_array($context['complaints'] ?? null) ? $context['complaints'] : [];
        if ($tracks === []) {
            return $context;
        }

        $context['active_complaint_id'] = self::pickActiveId($tracks, $clinicalText, $assessment, $context);
        $context = self::hydrateActiveFacts($context);

        $active = self::findTrack($context['complaints'], (string) ($context['active_complaint_id'] ?? ''));
        if (is_array($active)) {
            // Scope asked-slots to the active track so severity/timing of one
            // complaint cannot suppress questions for another.
            $union = [];
            foreach ($context['complaints'] as $track) {
                if (!is_array($track)) {
                    continue;
                }
                foreach ((array) ($track['questions_asked'] ?? []) as $qid) {
                    $qid = strtoupper(trim((string) $qid));
                    if ($qid !== '') {
                        $union[$qid] = $qid;
                    }
                }
            }
            $context['_questions_asked_union'] = array_values($union);
            $asked = [];
            foreach ((array) ($active['questions_asked'] ?? []) as $q) {
                $q = strtoupper(trim((string) $q));
                if ($q !== '') {
                    $asked[] = $q;
                }
            }
            $context['questions_asked'] = array_values($asked);
            $context['questions_answered'] = is_array($active['questions_answered'] ?? null)
                ? $active['questions_answered']
                : [];
            $context['awaiting_question_id'] = '';

            $families = self::stringFamilyKeys($active['family_keys'] ?? []);
            $scoped = [];
            foreach ((array) ($context['chief_complaints'] ?? []) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $fk = strtolower((string) ($row['family_key'] ?? ''));
                if ($fk !== '' && in_array($fk, $families, true)) {
                    $scoped[] = $row;
                }
            }
            if ($scoped === [] && $families !== []) {
                foreach ($families as $fk) {
                    $scoped[] = [
                        'id' => strtoupper($fk),
                        'name' => $fk,
                        'family_key' => $fk,
                    ];
                }
            }
            if ($scoped !== []) {
                $context['chief_complaints'] = $scoped;
            }
            $span = trim((string) ($active['text_span'] ?? ''));
            if ($span !== '') {
                $context['_active_complaint_span'] = $span;
            }
        }

        return $context;
    }

    /**
     * True when every complaint track is clinically sufficient (or only one shared track).
     *
     * @param array<string, mixed> $context
     * @param array<string, mixed> $assessment
     */
    public static function allTracksSufficient(array $context, string $clinicalText, array $assessment = []): bool
    {
        $tracks = is_array($context['complaints'] ?? null) ? $context['complaints'] : [];
        if (count($tracks) <= 1) {
            return ClinicalInterviewAdaptivePolicy::isTriageSufficient($context, $clinicalText, $assessment);
        }

        foreach ($tracks as $track) {
            if (!is_array($track)) {
                continue;
            }
            $scoped = $context;
            $scoped['facts'] = is_array($track['facts'] ?? null) ? $track['facts'] : [];
            $scoped['questions_asked'] = array_values(array_map('strval', (array) ($track['questions_asked'] ?? [])));
            $scoped['awaiting_question_id'] = (string) ($track['awaiting_question_id'] ?? '');
            $families = self::stringFamilyKeys($track['family_keys'] ?? []);
            $scoped['chief_complaints'] = [];
            foreach ($families as $fk) {
                $scoped['chief_complaints'][] = [
                    'id' => strtoupper($fk),
                    'name' => $fk,
                    'family_key' => $fk,
                ];
            }
            $span = trim((string) ($track['text_span'] ?? ''));
            $hay = $span !== '' ? $span : $clinicalText;
            if (!ClinicalInterviewAdaptivePolicy::isTriageSufficient($scoped, $hay, $assessment)) {
                return false;
            }
            $still = ClinicalInterviewAdaptivePolicy::selectNextSlot($scoped, $hay, $assessment);
            if (is_array($still) && ($still['question_id'] ?? '') !== '') {
                $pri = (int) ($still['priority'] ?? 99);
                if (!empty($still['red_flag_related']) || $pri <= 3) {
                    return false;
                }
                $qid = strtoupper((string) ($still['question_id'] ?? ''));
                if ($qid === 'ASSOCIATED_DETAIL' || $qid === 'ASSOCIATED_SYMPTOMS' || $qid === 'UNWELL_WHAT') {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Build triage haystack fragments labeled by complaint span.
     *
     * @param array<string, mixed> $context
     * @return list<string>
     */
    public static function multiFactsHaystackParts(array $context): array
    {
        $tracks = is_array($context['complaints'] ?? null) ? $context['complaints'] : [];
        if (count($tracks) <= 1) {
            return [];
        }
        $parts = [];
        foreach ($tracks as $track) {
            if (!is_array($track)) {
                continue;
            }
            $span = trim((string) ($track['text_span'] ?? ''));
            $facts = is_array($track['facts'] ?? null) ? $track['facts'] : [];
            $label = $span !== '' ? $span : (string) ($track['id'] ?? 'complaint');
            $chunk = [];
            if (($facts['pain_score'] ?? null) !== null && $facts['pain_score'] !== '') {
                $chunk[] = 'pain ' . (int) $facts['pain_score'] . '/10';
            }
            foreach ((array) ($facts['body_locations'] ?? []) as $loc) {
                $loc = trim((string) $loc);
                if ($loc !== '') {
                    $chunk[] = $loc;
                }
            }
            foreach ((array) ($facts['symptoms'] ?? []) as $sym) {
                $sym = trim((string) $sym);
                if ($sym !== '') {
                    $chunk[] = $sym;
                }
            }
            foreach ((array) ($facts['associated_symptoms'] ?? []) as $sym) {
                $sym = trim((string) $sym);
                if ($sym !== '') {
                    $chunk[] = $sym;
                }
            }
            foreach ((array) ($facts['negative_symptoms'] ?? []) as $sym) {
                $sym = trim((string) $sym);
                if ($sym !== '') {
                    $chunk[] = 'no ' . $sym;
                }
            }
            $onset = trim((string) ($facts['onset'] ?? ''));
            if ($onset !== '') {
                $chunk[] = $onset . ' onset';
            }
            $dur = trim((string) ($facts['duration_label'] ?? ''));
            if ($dur !== '') {
                $chunk[] = 'duration ' . $dur;
            }
            if (!empty($facts['denied_associated'])) {
                $chunk[] = 'no other associated symptoms';
            }
            if (($facts['weakness'] ?? null) === true) {
                $chunk[] = 'weakness present';
            } elseif (($facts['weakness'] ?? null) === false) {
                $chunk[] = 'no weakness';
            }
            if (($facts['breathing_difficulty'] ?? null) === true) {
                $chunk[] = 'difficulty breathing';
            } elseif (($facts['breathing_difficulty'] ?? null) === false) {
                $chunk[] = 'no difficulty breathing';
            }
            if (($facts['bleeding_continuing'] ?? null) === true) {
                $chunk[] = 'ongoing bleeding';
            } elseif (($facts['bleeding_continuing'] ?? null) === false) {
                $chunk[] = 'no ongoing bleeding';
            }
            if (($facts['bleeding_heavy'] ?? null) === true) {
                $chunk[] = 'heavy bleeding';
            } elseif (($facts['bleeding_heavy'] ?? null) === false) {
                $chunk[] = 'no heavy bleeding';
            }
            if (($facts['dizziness'] ?? null) === true) {
                $chunk[] = 'dizziness';
            } elseif (($facts['dizziness'] ?? null) === false) {
                $chunk[] = 'no dizziness';
            }
            foreach ((array) ($track['questions_answered'] ?? []) as $qa) {
                if (!is_array($qa)) {
                    continue;
                }
                $qid = trim((string) ($qa['question_id'] ?? ''));
                $ans = trim((string) ($qa['answer'] ?? $qa['patient_answer'] ?? ''));
                if ($ans !== '') {
                    $chunk[] = ($qid !== '' ? $qid . '=' : '') . $ans;
                }
            }
            if ($chunk !== []) {
                $parts[] = 'For "' . $label . '": ' . implode(', ', $chunk);
            } elseif ($span !== '') {
                $parts[] = $span;
            }
        }

        return $parts;
    }

    /**
     * Complete-case text for ClinicalTriageEngine (authoritative final input).
     * Never computes MAX urgency; the engine decides from this haystack.
     *
     * @param array<string, mixed> $context
     */
    public static function completeCaseHaystack(array $context, string $transcript = ''): string
    {
        $parts = [];
        $original = trim((string) ($context['chief_complaint'] ?? ''));
        if ($original !== '') {
            $parts[] = $original;
        }
        $bridgeOriginal = trim((string) (($context['semantic_bridge']['original'] ?? '') ?: ''));
        if ($bridgeOriginal !== '' && mb_stripos(implode(' ', $parts), $bridgeOriginal) === false) {
            $parts[] = $bridgeOriginal;
        }
        $ollama = trim((string) (($context['semantic_bridge']['ollama_meaning'] ?? '') ?: ''));
        if ($ollama !== '') {
            $parts[] = $ollama;
        }
        $geminiConcept = trim((string) (($context['semantic_bridge']['gemini_concept'] ?? '') ?: ''));
        if ($geminiConcept !== '') {
            $parts[] = $geminiConcept;
        }
        $transcript = trim($transcript);
        if ($transcript !== '' && mb_stripos(implode(' ', $parts), $transcript) === false) {
            $parts[] = $transcript;
        }
        // Always include structured polarity facts (single- and multi-complaint).
        // Bare yes/no turns are stripped from clinicalTranscript; WHO needs these phrases.
        $facts = is_array($context['facts'] ?? null) ? $context['facts'] : [];
        if ($facts !== [] && class_exists('ClinicalInterviewEngine')) {
            $factsText = trim(ClinicalInterviewEngine::factsHaystack($facts));
            if ($factsText !== '') {
                $parts[] = $factsText;
            }
        }
        foreach (self::multiFactsHaystackParts($context) as $chunk) {
            $chunk = trim((string) $chunk);
            if ($chunk !== '') {
                $parts[] = $chunk;
            }
        }

        return trim(implode('. ', array_values(array_unique(array_filter($parts)))));
    }

    /**
     * Optional per-complaint provisional engine reads (supporting metadata only).
     * Does NOT determine the final EMERGENCY/URGENT/NON-URGENT class.
     *
     * @param array<string, mixed> $context
     * @return list<array<string, mixed>>
     */
    public static function provisionalTrackAssessments(array $context): array
    {
        $tracks = is_array($context['complaints'] ?? null) ? $context['complaints'] : [];
        if ($tracks === [] || !class_exists('ClinicalTriageEngine')) {
            return [];
        }
        $out = [];
        foreach ($tracks as $track) {
            if (!is_array($track)) {
                continue;
            }
            $id = (string) ($track['id'] ?? '');
            $span = trim((string) ($track['text_span'] ?? ''));
            $facts = is_array($track['facts'] ?? null) ? $track['facts'] : [];
            $local = $span;
            $factBits = [];
            if (($facts['pain_score'] ?? null) !== null) {
                $factBits[] = 'pain ' . (int) $facts['pain_score'] . '/10';
            }
            foreach ((array) ($facts['body_locations'] ?? []) as $loc) {
                if (trim((string) $loc) !== '') {
                    $factBits[] = (string) $loc;
                }
            }
            if ($factBits !== []) {
                $local = trim($local . '. ' . implode(', ', $factBits));
            }
            if ($local === '') {
                continue;
            }
            try {
                $raw = ClinicalTriageEngine::assess($local, $local);
                $display = strtoupper((string) ($raw['triage_display'] ?? 'NON-URGENT'));
                if (!in_array($display, ['EMERGENCY', 'URGENT', 'NON-URGENT'], true)) {
                    $display = 'NON-URGENT';
                }
                $out[] = [
                    'complaint_id' => $id,
                    'text_span' => $span,
                    'family_keys' => self::stringFamilyKeys($track['family_keys'] ?? []),
                    'provisional_triage_display' => $display,
                    'provisional_only' => true,
                    'not_final_authority' => true,
                    'engine' => 'ClinicalTriageEngine',
                    'note' => 'Supporting per-complaint read only; final class comes from complete-case ClinicalTriageEngine.',
                ];
            } catch (Throwable $e) {
                error_log('Multi-complaint provisional assess skipped: ' . $e->getMessage());
            }
        }

        return $out;
    }

    /**
     * Mark tracks completed when the case finalizes (interview bookkeeping only).
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function markTracksCompleted(array $context): array
    {
        if (!is_array($context['complaints'] ?? null)) {
            return $context;
        }
        foreach ($context['complaints'] as $i => $track) {
            if (!is_array($track)) {
                continue;
            }
            $context['complaints'][$i]['status'] = 'completed';
            $context['complaints'][$i]['awaiting_question_id'] = '';
        }

        return $context;
    }

    /**
     * @param list<array<string, mixed>> $tracks
     * @param array<string, mixed> $assessment
     * @param array<string, mixed> $context
     */
    private static function pickActiveId(
        array $tracks,
        string $clinicalText,
        array $assessment = [],
        array $context = []
    ): string {
        if ($tracks === []) {
            return '';
        }
        if (count($tracks) === 1) {
            return (string) ($tracks[0]['id'] ?? 'c1');
        }

        $scored = [];
        foreach ($tracks as $track) {
            if (!is_array($track)) {
                continue;
            }
            $id = (string) ($track['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $scoped = $context !== [] ? $context : ['facts' => [], 'chief_complaints' => [], 'questions_asked' => []];
            $scoped['facts'] = is_array($track['facts'] ?? null) ? $track['facts'] : [];
            $scoped['questions_asked'] = array_values(array_map('strval', (array) ($track['questions_asked'] ?? [])));
            $families = self::stringFamilyKeys($track['family_keys'] ?? []);
            $scoped['chief_complaints'] = [];
            foreach ($families as $fk) {
                $scoped['chief_complaints'][] = [
                    'id' => strtoupper($fk),
                    'name' => $fk,
                    'family_key' => $fk,
                ];
            }
            $span = trim((string) ($track['text_span'] ?? ''));
            $hay = $span !== '' ? $span : $clinicalText;
            $slot = null;
            try {
                $slot = ClinicalInterviewAdaptivePolicy::selectNextSlot($scoped, $hay, $assessment);
            } catch (Throwable) {
                $slot = null;
            }
            $priority = 50;
            if (is_array($slot) && ($slot['question_id'] ?? '') !== '') {
                $priority = (int) ($slot['priority'] ?? 50);
                if (!empty($slot['red_flag_related'])) {
                    $priority -= 40;
                }
                if (strtoupper((string) ($slot['question_id'] ?? '')) === 'UNWELL_WHAT') {
                    $priority -= 5;
                }
            } else {
                // No open slot → deprioritize (already sufficient).
                $priority += 100;
            }
            // Prefer specific families over general_unwell when priorities tie.
            if (array_intersect($families, ['general_unwell', 'pain_unspecified']) !== []
                && array_diff($families, ['general_unwell', 'pain_unspecified', 'pain', 'pain_no_location']) === []
            ) {
                $priority += 8;
            }
            $scored[] = ['id' => $id, 'priority' => $priority];
        }
        if ($scored === []) {
            return (string) ($tracks[0]['id'] ?? 'c1');
        }
        usort($scored, static fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);

        return (string) $scored[0]['id'];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function hydrateActiveFacts(array $context): array
    {
        $active = self::findTrack(
            is_array($context['complaints'] ?? null) ? $context['complaints'] : [],
            (string) ($context['active_complaint_id'] ?? '')
        );
        if (!is_array($active)) {
            return $context;
        }
        $trackFacts = is_array($active['facts'] ?? null) ? $active['facts'] : [];
        $ctxFacts = is_array($context['facts'] ?? null) ? $context['facts'] : [];
        // Merge track + live context facts. Never wholesale-replace with a stale track:
        // that wiped yes/no polarity (e.g. bleeding_continuing) needed by WHO/IITT.
        $merged = class_exists('ClinicalInterviewEngine')
            ? ClinicalInterviewEngine::mergeFacts($trackFacts, $ctxFacts)
            : array_merge($trackFacts, array_filter(
                $ctxFacts,
                static fn ($v): bool => $v !== null && $v !== '' && $v !== []
            ));
        $context['facts'] = $merged;
        $activeId = (string) ($active['id'] ?? '');
        foreach ($context['complaints'] as $i => $track) {
            if (is_array($track) && (string) ($track['id'] ?? '') === $activeId) {
                $context['complaints'][$i]['facts'] = $merged;
                break;
            }
        }
        if (($context['awaiting_question_id'] ?? '') === '' && ($active['awaiting_question_id'] ?? '') !== '') {
            $context['awaiting_question_id'] = (string) $active['awaiting_question_id'];
        }

        return $context;
    }

    /** @param array<string, mixed> $facts */
    private static function factsLookEmpty(array $facts): bool
    {
        if (($facts['pain_score'] ?? null) !== null && $facts['pain_score'] !== '') {
            return false;
        }
        foreach (['body_locations', 'symptoms', 'associated_symptoms', 'red_flags'] as $key) {
            if (!empty($facts[$key]) && is_array($facts[$key])) {
                return false;
            }
        }
        foreach (['onset', 'duration_label', 'pain_qualifier'] as $key) {
            if (trim((string) ($facts[$key] ?? '')) !== '') {
                return false;
            }
        }
        foreach ([
            'weakness', 'speech_difficulty', 'vision_change', 'breathing_difficulty',
            'bleeding_continuing', 'bleeding_heavy', 'dizziness', 'chest_radiation',
            'sweating', 'abdominal_associated', 'has_other_symptoms', 'denied_associated',
            'fever_confirmed', 'blood_in_stool', 'pregnancy',
        ] as $key) {
            if (($facts[$key] ?? null) !== null && $facts[$key] !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<array<string, mixed>> $tracks
     * @return array<string, mixed>|null
     */
    private static function findTrack(array $tracks, string $id): ?array
    {
        if ($id === '') {
            return null;
        }
        foreach ($tracks as $track) {
            if (is_array($track) && (string) ($track['id'] ?? '') === $id) {
                return $track;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $assessment
     * @param array<string, mixed> $context
     * @return list<array<string, mixed>>
     */
    private static function buildTracks(array $assessment, string $hay, string $original, array $context): array
    {
        $seedFacts = is_array($context['facts'] ?? null) ? $context['facts'] : [];
        $units = self::detectUnits($assessment, $hay, $seedFacts);
        if ($units === []) {
            $units = [[
                'text_span' => $original !== '' ? $original : $hay,
                'family_keys' => ['general_unwell'],
                'complaints' => [],
            ]];
        }

        $tracks = [];
        $n = 1;
        foreach ($units as $unit) {
            if (!is_array($unit)) {
                continue;
            }
            $span = trim((string) ($unit['text_span'] ?? ''));
            $families = [];
            foreach ((array) ($unit['family_keys'] ?? []) as $v) {
                $fk = strtolower(trim((string) $v));
                if ($fk !== '') {
                    $families[] = $fk;
                }
            }
            $families = array_values(array_unique($families));
            if ($span === '' && $families === []) {
                continue;
            }
            // Seed location from family when the span itself names a site.
            $facts = ClinicalInterviewEngine::normalizeContext(['facts' => []])['facts'];
            $tracks[] = [
                'id' => 'c' . $n,
                'text_span' => $span !== '' ? $span : ($original !== '' ? $original : $hay),
                'family_keys' => $families !== [] ? $families : ['general_unwell'],
                'status' => 'pending',
                'facts' => $facts,
                'questions_asked' => [],
                'questions_answered' => [],
                'awaiting_question_id' => '',
                'red_flag_priority' => self::familySuggestsAcuity($families),
            ];
            $n++;
        }

        return $tracks !== [] ? $tracks : [[
            'id' => 'c1',
            'text_span' => $original !== '' ? $original : $hay,
            'family_keys' => ['general_unwell'],
            'status' => 'pending',
            'facts' => ClinicalInterviewEngine::normalizeContext(['facts' => []])['facts'],
            'questions_asked' => [],
            'questions_answered' => [],
            'awaiting_question_id' => '',
            'red_flag_priority' => false,
        ]];
    }

    /**
     * @param list<array<string, mixed>> $existing
     * @param array<string, mixed> $assessment
     * @param array<string, mixed> $context
     * @return list<array<string, mixed>>
     */
    private static function refreshFamilies(array $existing, array $assessment, string $hay, array $context): array
    {
        // Do not reshuffle established tracks mid-interview; only enrich empty family lists.
        foreach ($existing as $i => $track) {
            if (!is_array($track)) {
                continue;
            }
            $families = self::stringFamilyKeys($track['family_keys'] ?? []);
            if ($families !== [] && $families !== ['general_unwell']) {
                continue;
            }
            $span = trim((string) ($track['text_span'] ?? $hay));
            $facts = is_array($track['facts'] ?? null) ? $track['facts'] : [];
            $derived = ClinicalInterviewContextResolver::deriveComplaints($assessment, $span, $facts);
            $keys = [];
            foreach ($derived as $row) {
                $fk = strtolower((string) ($row['family_key'] ?? ''));
                if ($fk !== '') {
                    $keys[] = $fk;
                }
            }
            if ($keys !== []) {
                $existing[$i]['family_keys'] = array_values(array_unique($keys));
                $existing[$i]['red_flag_priority'] = self::familySuggestsAcuity($keys);
            }
        }
        unset($context);

        return $existing;
    }

    /**
     * Universal multi-unit detection: split on conjunctions, then family-match each span.
     * Falls back to one track per distinct family on the full text.
     *
     * @param array<string, mixed> $assessment
     * @param array<string, mixed> $facts
     * @return list<array{text_span:string,family_keys:list<string>,complaints:list<array<string,mixed>>}>
     */
    private static function detectUnits(array $assessment, string $hay, array $facts): array
    {
        $spans = self::splitSpans($hay);
        $units = [];
        $seenFamilies = [];

        foreach ($spans as $span) {
            $span = trim($span);
            if ($span === '' || mb_strlen($span) < 2) {
                continue;
            }
            $derived = ClinicalInterviewContextResolver::deriveComplaints($assessment, $span, $facts);
            $keys = [];
            foreach ($derived as $row) {
                $fk = strtolower((string) ($row['family_key'] ?? ''));
                if ($fk === '' || $fk === 'pain' || $fk === 'pain_no_location') {
                    continue;
                }
                $keys[] = $fk;
            }
            $keys = array_values(array_unique($keys));
            // Skip non-clinical conjunction fragments.
            if ($keys === [] && count($spans) > 1) {
                continue;
            }
            if ($keys === []) {
                $keys = ClinicalFeatureExtractors::isVagueComplaint($span)
                    ? ['general_unwell']
                    : [];
            }
            if ($keys === []) {
                continue;
            }
            // Dedupe identical family sets already captured.
            $sig = implode('|', $keys);
            if (isset($seenFamilies[$sig])) {
                continue;
            }
            $seenFamilies[$sig] = true;
            $units[] = [
                'text_span' => $span,
                'family_keys' => $keys,
                'complaints' => $derived,
            ];
        }

        if (count($units) >= 2) {
            return $units;
        }

        // Fallback: multiple families on the full utterance → one unit per family.
        $derivedAll = ClinicalInterviewContextResolver::deriveComplaints($assessment, $hay, $facts);
        $byFamily = [];
        foreach ($derivedAll as $row) {
            $fk = strtolower((string) ($row['family_key'] ?? ''));
            if ($fk === '' || in_array($fk, ['pain', 'pain_no_location'], true)) {
                continue;
            }
            if (in_array($fk, ['general_unwell', 'pain_unspecified'], true) && count($derivedAll) > 1) {
                continue;
            }
            if (!isset($byFamily[$fk])) {
                $byFamily[$fk] = [
                    'text_span' => self::spanForFamily($hay, $row),
                    'family_keys' => [$fk],
                    'complaints' => [$row],
                ];
            }
        }
        if (count($byFamily) >= 2) {
            return array_values($byFamily);
        }

        if ($units !== []) {
            return $units;
        }

        $keys = array_keys($byFamily);
        if ($keys !== []) {
            return [[
                'text_span' => $hay,
                'family_keys' => $keys,
                'complaints' => $derivedAll,
            ]];
        }

        return [[
            'text_span' => $hay,
            'family_keys' => ClinicalFeatureExtractors::isVagueComplaint($hay) ? ['general_unwell'] : ['general_unwell'],
            'complaints' => $derivedAll,
        ]];
    }

    /**
     * @return list<string>
     */
    private static function splitSpans(string $hay): array
    {
        $text = trim($hay);
        if ($text === '') {
            return [];
        }
        // Universal conjunction / list separators (EN / TL / HIL / punctuation).
        $parts = preg_split(
            '/\s*(?:,|;|\band\b|\balso\b|\bplus\b|\bkag\b|\bat\b|\btsaka\b|\bsaka\b|\bat saka\b|\bpati\b|\bas well as\b)\s*/iu',
            $text
        );
        if (!is_array($parts) || count($parts) < 2) {
            return [$text];
        }
        $out = [];
        foreach ($parts as $p) {
            $p = trim((string) $p);
            // Drop leading "I have" remnants after split when leftover is still clinical.
            $p = trim((string) preg_replace('/^(i\s+have|i\'?ve\s+got|may|mayroon)\s+/iu', '', $p));
            if ($p !== '') {
                $out[] = $p;
            }
        }

        return $out !== [] ? $out : [$text];
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function spanForFamily(string $hay, array $row): string
    {
        $hayLow = mb_strtolower($hay);
        foreach ((array) ($row['text_regex'] ?? []) as $pattern) {
            // patterns live in JSON config; row from deriveComplaints may not include regex.
            unset($pattern);
        }
        $label = trim((string) ($row['name'] ?? $row['family_key'] ?? ''));
        if ($label !== '' && str_contains($hayLow, mb_strtolower($label))) {
            return $label;
        }
        $fk = strtolower((string) ($row['family_key'] ?? ''));
        // Best-effort: return full hay; split path usually supplies better spans.
        if ($fk !== '' && str_contains($hayLow, str_replace('_', ' ', $fk))) {
            return str_replace('_', ' ', $fk);
        }

        return $hay;
    }

    /** @param list<string> $families */
    private static function familySuggestsAcuity(array $families): bool
    {
        return array_intersect($families, [
            'chest_pain', 'breathing', 'bleeding', 'neuro', 'abdominal_pain', 'eye',
        ]) !== [];
    }

    /**
     * @param mixed $raw
     * @return list<string>
     */
    private static function stringFamilyKeys(mixed $raw): array
    {
        $out = [];
        foreach ((array) $raw as $v) {
            $fk = strtolower(trim((string) $v));
            if ($fk !== '') {
                $out[] = $fk;
            }
        }

        return array_values(array_unique($out));
    }
}
