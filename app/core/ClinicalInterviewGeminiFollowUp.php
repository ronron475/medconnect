<?php
/**
 * Gemini adaptive follow-up: selects ONE allow-listed finding/slot and phrases it.
 *
 * PHP (AdaptivePolicy + question bank) builds the candidate allow-list.
 * Gemini chooses among those candidates and writes ONE atomic question.
 * Gemini does not invent the clinical agenda outside the allow-list, does not
 * classify triage, and does not replace ClinicalTriageEngine.
 * If Gemini is unavailable or invalid, bank/policy fallback is used.
 *
 * For Hiligaynon/local complaints, the prompt receives BOTH the original patient text
 * and optional Ollama meaning support so wording stays faithful without dropping either.
 */
final class ClinicalInterviewGeminiFollowUp
{
    private const DEFAULT_MODEL = 'gemini-3.5-flash';
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';
    private const MAX_QUESTION_CHARS = 280;

    private static string $lastError = '';

    public static function lastError(): string
    {
        return self::$lastError;
    }

    /** True when Gemini validly chose to end the interview (do not use bank fallback). */
    public static function isFinishDecision(): bool
    {
        return self::$lastError === 'continue_interview_false';
    }

    /**
     * Select ONE next question from PHP allow-list candidates.
     *
     * @param list<array<string, mixed>> $candidates
     * @param array<string, mixed> $context
     * @return array{
     *   question_id:string,
     *   target_finding:string,
     *   clinical_purpose:string,
     *   red_flag_related:bool,
     *   priority:int,
     *   text:string,
     *   language:string,
     *   complaint_id:string,
     *   source:string,
     *   parent_question_id?:string
     * }|null
     */
    public static function selectNext(array $candidates, array $context, string $transcript): ?array
    {
        if (!self::enabled() || $candidates === []) {
            self::$lastError = $candidates === [] ? 'no_candidates' : 'disabled';

            return null;
        }
        if (self::envFlag('MEDCONNECT_SKIP_GEMINI_SELECT', false)) {
            self::$lastError = 'select_disabled';

            return null;
        }

        $indexed = [];
        foreach ($candidates as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $qid = strtoupper(trim((string) ($row['question_id'] ?? '')));
            if ($qid === '') {
                continue;
            }
            $indexed[] = [
                'index' => count($indexed),
                'question_id' => $qid,
                'target_finding' => strtolower(trim((string) ($row['target_finding'] ?? $qid))),
                'clinical_purpose' => trim((string) ($row['clinical_purpose'] ?? '')),
                'red_flag_related' => !empty($row['red_flag_related']),
                'priority' => (int) ($row['priority'] ?? 99),
                'parent_question_id' => strtoupper(trim((string) ($row['parent_question_id'] ?? ''))),
                'bank_template' => trim((string) ($row['bank_template'] ?? '')),
            ];
        }
        if ($indexed === []) {
            self::$lastError = 'no_valid_candidates';

            return null;
        }

        // Cap prompt size — keep highest-priority candidates.
        $indexed = array_slice($indexed, 0, 8);

        try {
            $raw = self::complete(self::selectUserPrompt($indexed, $context, $transcript));
            $parsed = self::parseSelectPayload($raw);
            if ($parsed === null) {
                self::$lastError = 'invalid_select_json: ' . mb_substr(trim($raw), 0, 160);

                return null;
            }

            // Explicit finish: honor before allow-list resolution so bank fallback is skipped.
            if (array_key_exists('continue_interview', $parsed) && $parsed['continue_interview'] === false) {
                self::$lastError = 'continue_interview_false';

                return null;
            }

            $pick = self::resolveSelectedCandidate($parsed, $indexed);
            if ($pick === null) {
                self::$lastError = 'select_not_in_allow_list';

                return null;
            }

            // PHP safety gate: reject if AdaptivePolicy would treat the slot as already answered.
            if (class_exists('ClinicalInterviewAdaptivePolicy')) {
                $stillOpen = ClinicalInterviewAdaptivePolicy::listCandidateSlots($context, $transcript, []);
                $openIds = [];
                foreach ($stillOpen as $row) {
                    if (is_array($row)) {
                        $openIds[] = strtoupper(trim((string) ($row['question_id'] ?? '')));
                    }
                }
                $pickedId = strtoupper(trim((string) ($pick['question_id'] ?? '')));
                if ($pickedId === '' || !in_array($pickedId, $openIds, true)) {
                    self::$lastError = 'php_gate_rejected_already_known';

                    return null;
                }
            }

            $question = self::sanitizeQuestion((string) ($parsed['question'] ?? ''));
            if ($question === '') {
                // Prefer bank template wording if Gemini text was rejected.
                $question = self::sanitizeQuestion((string) ($pick['bank_template'] ?? ''));
            }
            if ($question === '' || self::looksBundledOrMultiQuestion($question)) {
                self::$lastError = 'select_question_rejected';

                return null;
            }
            if (self::looksLikeTriageOrDiagnosis($question)) {
                self::$lastError = 'select_triage_or_diagnosis';

                return null;
            }

            $lang = strtoupper((string) ($context['question_language'] ?? 'ENGLISH'));
            if ($lang === 'TAGALOG' || $lang === 'FILIPINO') {
                $language = 'TAGALOG';
            } elseif ($lang === 'HILIGAYNON' || $lang === 'ILONGGO') {
                $language = 'HILIGAYNON';
            } else {
                $language = 'ENGLISH';
            }

            self::$lastError = '';

            return [
                'question_id' => (string) $pick['question_id'],
                'target_finding' => (string) $pick['target_finding'],
                'clinical_purpose' => (string) $pick['clinical_purpose'],
                'red_flag_related' => !empty($pick['red_flag_related']),
                'priority' => (int) $pick['priority'],
                'text' => $question,
                'language' => $language,
                'complaint_id' => trim((string) ($parsed['complaint_id'] ?? ($context['active_complaint_id'] ?? ''))),
                'source' => 'gemini_adaptive_select',
                'parent_question_id' => (string) ($pick['parent_question_id'] ?? ''),
            ];
        } catch (Throwable $e) {
            self::$lastError = $e->getMessage();
            error_log('ClinicalInterviewGeminiFollowUp::selectNext: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * @param array<string, mixed> $slot
     * @param array<string, mixed> $context
     */
    public static function phrase(array $slot, array $context, string $transcript, string $bankTemplate = ''): string
    {
        if (!self::enabled()) {
            self::$lastError = 'disabled';
            return '';
        }

        try {
            $raw = self::complete(self::userPrompt($slot, $context, $transcript, $bankTemplate));
            $text = self::sanitizeQuestion($raw);
            if ($text === '') {
                self::$lastError = 'rejected: ' . mb_substr(trim($raw), 0, 180);
            } else {
                self::$lastError = '';
            }
            return $text;
        } catch (Throwable $e) {
            self::$lastError = $e->getMessage();
            error_log('ClinicalInterviewGeminiFollowUp: ' . $e->getMessage());
            return '';
        }
    }

    public static function enabled(): bool
    {
        if (self::envFlag('MEDCONNECT_PHP_NLP_ONLY', false) || self::envFlag('MEDCONNECT_SKIP_GEMINI_FOLLOWUP', false)) {
            return false;
        }
        if (!self::envFlag('AI_ENABLED', true)) {
            return false;
        }
        $provider = strtolower(trim(self::envString('AI_PROVIDER', 'gemini')));
        if ($provider !== '' && $provider !== 'gemini') {
            return false;
        }

        return self::apiKey() !== '';
    }

    /**
     * @param array<string, mixed> $slot
     * @param array<string, mixed> $context
     */
    private static function userPrompt(array $slot, array $context, string $transcript, string $bankTemplate = ''): string
    {
        $lang = strtoupper((string) ($slot['language'] ?? $context['question_language'] ?? 'HILIGAYNON'));
        $langLine = match ($lang) {
            'TAGALOG', 'FILIPINO' => 'Tagalog/Filipino',
            'ENGLISH' => 'English',
            default => 'Hiligaynon/Ilonggo',
        };
        $complaints = [];
        foreach ((array) ($context['chief_complaints'] ?? []) as $row) {
            if (is_array($row)) {
                $complaints[] = trim((string) ($row['name'] ?? $row['id'] ?? ''));
            } elseif (is_string($row) && trim($row) !== '') {
                $complaints[] = trim($row);
            }
        }
        $facts = is_array($context['facts'] ?? null) ? $context['facts'] : [];
        $known = [];
        if (class_exists('ClinicalInterviewAdaptivePolicy')) {
            $summary = ClinicalInterviewAdaptivePolicy::fullCaseHaystack($context, $transcript, $facts);
            if ($summary !== '') {
                $known[] = 'case=' . mb_substr($summary, 0, 900);
            }
        }
        foreach ($facts['body_locations'] ?? [] as $loc) {
            if (is_string($loc) && $loc !== '') {
                $known[] = 'location=' . $loc;
            }
        }
        if (($facts['pain_score'] ?? null) !== null && $facts['pain_score'] !== '') {
            $known[] = 'pain_score=' . (int) $facts['pain_score'] . ' (scale 1-10)';
        }
        if (($facts['onset'] ?? '') !== '') {
            $known[] = 'onset=' . (string) $facts['onset'];
        }
        if (($facts['duration_label'] ?? '') !== '') {
            $known[] = 'duration=' . (string) $facts['duration_label'];
        }
        if (!empty($facts['denied_associated'])) {
            $known[] = 'patient_denied_other_symptoms=true';
        }
        foreach ((array) ($facts['associated_symptoms'] ?? []) as $sym) {
            $s = trim((string) $sym);
            if ($s !== '') {
                $known[] = 'associated=' . $s;
            }
        }
        $asked = array_values(array_filter(array_map('strval', (array) ($context['questions_asked'] ?? []))));
        $answered = [];
        foreach ((array) ($context['questions_answered'] ?? []) as $qa) {
            if (!is_array($qa)) {
                continue;
            }
            $qid = trim((string) ($qa['question_id'] ?? ''));
            $a = trim((string) ($qa['answer'] ?? $qa['patient_answer'] ?? ''));
            if ($qid !== '' || $a !== '') {
                $answered[] = trim($qid . '=' . $a);
            }
        }
        $bankTemplate = trim($bankTemplate);
        $qid = strtoupper(trim((string) ($slot['question_id'] ?? '')));
        $painRule = $qid === 'PAIN_SEVERITY'
            ? "If asking pain intensity, ALWAYS use a 1 to 10 scale (1=very mild, 10=worst). Never use 0–10.\n"
            : '';

        $bridge = is_array($context['semantic_bridge'] ?? null) ? $context['semantic_bridge'] : [];
        $originalComplaint = trim((string) (
            ($bridge['original'] ?? '')
            ?: ($context['chief_complaint'] ?? '')
            ?: (($context['complaint_text_cleaner']['original'] ?? '') ?: '')
        ));
        $ollamaMeaning = trim((string) ($bridge['ollama_meaning'] ?? ''));
        if ($ollamaMeaning !== '' && $originalComplaint !== ''
            && mb_strtolower($ollamaMeaning) === mb_strtolower($originalComplaint)
        ) {
            $ollamaMeaning = '';
        }

        $activeId = trim((string) ($context['active_complaint_id'] ?? ''));
        $activeSpan = '';
        $activeFamilies = [];
        foreach ((array) ($context['complaints'] ?? []) as $track) {
            if (!is_array($track) || (string) ($track['id'] ?? '') !== $activeId) {
                continue;
            }
            $activeSpan = trim((string) ($track['text_span'] ?? ''));
            $activeFamilies = array_values(array_filter(array_map('strval', (array) ($track['family_keys'] ?? []))));
            break;
        }
        if ($activeSpan === '') {
            $activeSpan = trim((string) ($context['_active_complaint_span'] ?? ''));
        }

        return "Write one follow-up question a nurse would say out loud.\n"
            . "Language: {$langLine} only.\n"
            . 'Clinical purpose (chosen by existing NLP allow-list — phrase this purpose only): '
            . trim((string) ($slot['clinical_purpose'] ?? 'clarify the complaint')) . "\n"
            . 'Question slot id: ' . ($qid !== '' ? $qid : '(none)') . "\n"
            . $painRule
            . ($bankTemplate !== '' ? "Keep the same meaning as this template: {$bankTemplate}\n" : '')
            . 'Detected complaints: ' . ($complaints !== [] ? implode(', ', $complaints) : '(unspecified)') . "\n"
            . 'Active complaint id: ' . ($activeId !== '' ? $activeId : '(single)') . "\n"
            . 'Active complaint focus (answer applies ONLY to this): '
            . ($activeSpan !== '' ? mb_substr($activeSpan, 0, 240) : '(full case)') . "\n"
            . 'Active complaint families: ' . ($activeFamilies !== [] ? implode(', ', $activeFamilies) : '(none)') . "\n"
            . self::knownInformationBlock($context, $transcript, $facts)
            . 'AI meaning support (secondary; not patient speech): '
            . ($ollamaMeaning !== '' ? mb_substr($ollamaMeaning, 0, 240) : '(none)') . "\n"
            . "Do not assume facts from a different complaint apply to the active one.\n"
            . "Do not ask for information already listed as known.\n"
            . "Reply with the question only. No preamble.";
    }

    private static function complete(string $userPrompt): string
    {
        $payload = self::requestPayload($userPrompt, true);
        try {
            return self::generateFromPayload($payload);
        } catch (RuntimeException $e) {
            if (!str_contains($e->getMessage(), 'Gemini HTTP 400')) {
                throw $e;
            }
            return self::generateFromPayload(self::requestPayload($userPrompt, false));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function requestPayload(string $userPrompt, bool $withThinkingConfig): array
    {
        $config = [
            'temperature' => 0.3,
            'maxOutputTokens' => 1024,
        ];
        if ($withThinkingConfig) {
            $config['thinkingConfig'] = ['thinkingBudget' => 0];
        }

        return [
            'systemInstruction' => [
                'parts' => [['text' => self::systemPrompt()]],
            ],
            'contents' => [[
                'role' => 'user',
                'parts' => [['text' => $userPrompt]],
            ]],
            'generationConfig' => $config,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function generateFromPayload(array $payload): string
    {
        $url = sprintf(self::ENDPOINT, rawurlencode(self::model()));
        $data = self::httpPostJson($url, $payload, [
            'x-goog-api-key: ' . self::apiKey(),
        ]);
        $parts = $data['candidates'][0]['content']['parts'] ?? [];
        $out = '';
        if (is_array($parts)) {
            foreach ($parts as $part) {
                if (is_array($part) && isset($part['text'])) {
                    $out .= (string) $part['text'];
                }
            }
        }
        $out = trim($out);
        if ($out === '') {
            throw new RuntimeException('empty Gemini follow-up');
        }

        return $out;
    }

    private static function sanitizeQuestion(string $raw): string
    {
        $text = self::extractQuestionText($raw);
        $text = trim(strip_tags($text));
        $text = trim($text, " \t\n\r\0\x0B\"'`“”");
        $text = preg_replace('/^(question|follow-up|follow up)\s*:\s*/iu', '', $text) ?? $text;
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($text === '' || mb_strlen($text) > self::MAX_QUESTION_CHARS) {
            return '';
        }
        $words = preg_split('/\s+/u', $text) ?: [];
        if (count($words) < 4 || str_contains($text, '**') || str_contains($text, '```')) {
            return '';
        }
        if (preg_match('/^(rules|instructions|system|output|format)\b/iu', $text)) {
            return '';
        }
        $low = mb_strtolower($text);
        if (preg_match('/\b(json|gemini|nlp|triage|as an ai|here is the|as requested|follow-up slot)\b/u', $low)) {
            return '';
        }
        if (str_contains($low, 'you have') && preg_match('/\b(diagnosis|diagnosed|definitely)\b/u', $low)) {
            return '';
        }
        if (preg_match('/\b(EMERGENCY|URGENT|NON-URGENT|NON_URGENT)\b/u', $text) && !str_contains($low, '?')) {
            return '';
        }
        if (!str_contains($text, '?') && !preg_match('/^(diin|saan|where|ano|what|when|san-o|kailan|gaano|how|does|do you|is the|may|wala)/iu', $text)) {
            $text .= '?';
        }

        return $text;
    }

    private static function extractQuestionText(string $raw): string
    {
        $raw = trim(str_replace("\r\n", "\n", $raw));
        $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw) ?? $raw;
        $raw = preg_replace('/\s*```$/', '', $raw) ?? $raw;
        $raw = trim($raw);

        $parsed = json_decode($raw, true);
        if (is_array($parsed)) {
            $text = trim((string) ($parsed['text'] ?? $parsed['question'] ?? ''));
            if ($text !== '') {
                return $text;
            }
        }
        if (preg_match('/\{[\s\S]*\}/', $raw, $jsonMatch)) {
            $decoded = json_decode($jsonMatch[0], true);
            if (is_array($decoded)) {
                $text = trim((string) ($decoded['text'] ?? $decoded['question'] ?? ''));
                if ($text !== '') {
                    return $text;
                }
            }
        }

        return $raw;
    }

    private static function systemPrompt(): string
    {
        return <<<'PROMPT'
You phrase ONE follow-up question for medConnect preliminary triage.

Existing NLP (adaptive policy + question bank) already chose the clinical purpose / slot.
You only write the spoken wording for that purpose. You do NOT invent a different clinical agenda.
You do NOT classify urgency. You do NOT diagnose. You do NOT invent symptoms, pain scores, vitals, or history.
Do NOT ask for information already present in the case (primary complaint, prior answers, or extracted facts).
Do NOT ask a generic fixed questionnaire. Ask only what is still unknown and clinically useful for THIS case.

When both an original patient complaint and an Ollama/local meaning support are provided:
- Treat the original patient wording as authoritative.
- Use the Ollama meaning only as secondary understanding help.
- Do not replace or discard the original language meaning.
- Do not invent clinical facts that appear in neither source.

CRITICAL — never assume unsupported clinical facts:
- Do not assume pain, body location, severity, duration, associated symptoms, or risk factors unless the patient already stated them.
- If the clinical purpose is to clarify a vague/unspecific complaint, ask what symptoms they feel / what is wrong — not a pain-location question unless pain is already established.
- Match the question to the clinical purpose and the patient's actual wording.

Rules:
- Ask exactly one simple spoken question that collects the required clinical purpose.
- Use the patient's language only (Hiligaynon/Ilonggo, Tagalog/Filipino, or English).
- Do not switch languages.
- Do not use medical jargon.
- Do not repeat facts the patient already provided.
- For pain intensity, always use a 1–10 scale (1=very mild, 10=worst). Never use 0–10.
- Do not mention datasets, JSON, NLP, Gemini, Ollama, or triage colors.
- Output only the spoken question.
PROMPT;
    }

    private static function apiKey(): string
    {
        return trim(self::envString('GEMINI_API_KEY', self::envString('GOOGLE_API_KEY', self::envString('AI_API_KEY'))));
    }

    private static function model(): string
    {
        $model = trim(self::envString('AI_MODEL', self::DEFAULT_MODEL));

        return $model !== '' ? $model : self::DEFAULT_MODEL;
    }

    private static function timeout(): int
    {
        $raw = (int) self::envString('AI_TIMEOUT', '10');

        return max(4, min(12, $raw > 0 ? $raw : 10));
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $extraHeaders
     * @return array<string, mixed>
     */
    private static function httpPostJson(string $url, array $payload, array $extraHeaders): array
    {
        $headers = array_merge(['Content-Type: application/json'], $extraHeaders);
        $verifySsl = self::envFlag('AI_SSL_VERIFY', true);
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::timeout(),
            CURLOPT_CONNECTTIMEOUT => min(6, self::timeout()),
            // Windows/libcurl: honor TIMEOUT during DNS/SSL (avoids multi-minute hangs).
            CURLOPT_NOSIGNAL => true,
            CURLOPT_SSL_VERIFYPEER => $verifySsl,
            CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
        ]);
        // Only attach a CA bundle when verification is on — avoid forcing bundled CAINFO when verify is off.
        if ($verifySsl) {
            $ca = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'cacert.pem';
            if (!is_readable($ca)) {
                $ca = (string) (ini_get('curl.cainfo') ?: ini_get('openssl.cafile') ?: '');
            }
            if ($ca !== '' && is_readable($ca)) {
                curl_setopt($ch, CURLOPT_CAINFO, $ca);
            }
        }
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $errno !== 0) {
            throw new RuntimeException('Gemini HTTP error: ' . ($error !== '' ? $error : 'errno ' . $errno));
        }
        $data = json_decode((string) $raw, true);
        if (!is_array($data) || $code >= 400) {
            throw new RuntimeException('Gemini HTTP ' . $code);
        }

        return $data;
    }

    private static function envString(string $key, string $default = ''): string
    {
        $raw = getenv($key);
        if ($raw === false || $raw === '') {
            $raw = $_ENV[$key] ?? $default;
        }

        return is_string($raw) ? $raw : $default;
    }

    private static function envFlag(string $key, bool $default): bool
    {
        $raw = getenv($key);
        if ($raw === false || $raw === '') {
            $raw = $_ENV[$key] ?? null;
        }
        if ($raw === null || $raw === '') {
            return $default;
        }

        return !in_array(strtolower(trim((string) $raw)), ['0', 'false', 'no', 'off'], true);
    }

    /**
     * @param list<array<string, mixed>> $indexed
     * @param array<string, mixed> $context
     */
    private static function selectUserPrompt(array $indexed, array $context, string $transcript): string
    {
        $lang = strtoupper((string) ($context['question_language'] ?? 'HILIGAYNON'));
        $langLine = match ($lang) {
            'TAGALOG', 'FILIPINO' => 'Tagalog/Filipino',
            'ENGLISH' => 'English',
            default => 'Hiligaynon/Ilonggo',
        };
        $facts = is_array($context['facts'] ?? null) ? $context['facts'] : [];
        $active = self::activeComplaintContext($context);
        $whoNeeds = self::whoInformationNeeds(
            class_exists('ClinicalInterviewAdaptivePolicy')
                ? ClinicalInterviewAdaptivePolicy::fullCaseHaystack($context, $transcript, $facts)
                : mb_strtolower(trim($transcript)),
            $indexed,
            $active['families'],
            $active['span']
        );
        $candidatesJson = json_encode($indexed, JSON_UNESCAPED_UNICODE);
        $findingStatus = is_array($facts['finding_status'] ?? null) ? $facts['finding_status'] : [];

        return "Select ONE next follow-up question for medConnect preliminary triage.\n"
            . "Language for the spoken question: {$langLine} only.\n"
            . "Decision order (mandatory):\n"
            . "1) Review the ACTIVE COMPLAINT (id/span/families).\n"
            . "2) Review patient-authored evidence, structured facts, symptoms, locations, prior answers.\n"
            . "3) Identify only genuinely MISSING clinical information for that active complaint.\n"
            . "4) Choose ONE candidate from the eligible allow-list that targets that missing information.\n"
            . "You MUST pick exactly one candidate from this allow-list (JSON):\n{$candidatesJson}\n"
            . 'Active complaint id: ' . ($active['id'] !== '' ? $active['id'] : '(single)') . "\n"
            . 'Active complaint span (focus ONLY on this): '
            . ($active['span'] !== '' ? mb_substr($active['span'], 0, 240) : '(full case)') . "\n"
            . 'Active complaint families/concepts: '
            . ($active['families'] !== [] ? implode(', ', $active['families']) : '(none)') . "\n"
            . self::knownInformationBlock($context, $transcript, $facts)
            . "Finding status map: " . json_encode($findingStatus, JSON_UNESCAPED_UNICODE) . "\n"
            . "Filtered WHO/IITT information hints (only missing + relevant to active complaint/candidates; NOT triage):\n"
            . ($whoNeeds !== '' ? $whoNeeds : '(none — rely on allow-list + known facts)') . "\n"
            . "Accumulated case text (may include enrichment; patient-authored evidence above is authoritative):\n"
            . mb_substr(trim($transcript), 0, 700) . "\n\n"
            . "Return ONLY compact JSON with keys:\n"
            . "{\"index\":0,\"question_id\":\"...\",\"target_finding\":\"...\",\"complaint_id\":\"...\",\"question\":\"...\",\"continue_interview\":true}\n"
            . "Rules:\n"
            . "- FIRST review active complaint + known facts + prior answers. Do not re-ask known information.\n"
            . "- Prefer the candidate most clinically relevant to the ACTIVE complaint and still genuinely missing.\n"
            . "- Do not pick an unrelated generic screening question when a more complaint-relevant open candidate exists.\n"
            . "- index must match one allow-list item. Never invent a new question_id.\n"
            . "- question_id and target_finding must match that same allow-list item.\n"
            . "- Ask exactly ONE atomic question for that one finding only.\n"
            . "- Never ask A or B or C in one question.\n"
            . "- Never diagnose. Never say EMERGENCY, URGENT, or NON-URGENT.\n"
            . "- Never invent symptoms the patient did not state.\n"
            . "- Question relevance to the active complaint is required; repeating the patient's exact words is optional.\n"
            . "- If nothing important is missing for this case, set continue_interview=false (finish interview).\n"
            . "- Output JSON only.";
    }

    /**
     * @param array<string, mixed> $context
     * @return array{id:string,span:string,families:list<string>}
     */
    private static function activeComplaintContext(array $context): array
    {
        $activeId = trim((string) ($context['active_complaint_id'] ?? ''));
        $activeSpan = '';
        $activeFamilies = [];
        foreach ((array) ($context['complaints'] ?? []) as $track) {
            if (!is_array($track)) {
                continue;
            }
            if ($activeId !== '' && (string) ($track['id'] ?? '') !== $activeId) {
                continue;
            }
            $activeSpan = trim((string) ($track['text_span'] ?? ''));
            $activeFamilies = array_values(array_filter(array_map('strval', (array) ($track['family_keys'] ?? []))));
            if ($activeId === '') {
                $activeId = trim((string) ($track['id'] ?? ''));
            }
            break;
        }
        if ($activeSpan === '') {
            $activeSpan = trim((string) ($context['_active_complaint_span'] ?? ''));
        }
        if ($activeSpan === '') {
            $activeSpan = trim((string) ($context['chief_complaint'] ?? ''));
        }
        if ($activeFamilies === [] && class_exists('ClinicalInterviewContextResolver')) {
            try {
                $facts = is_array($context['facts'] ?? null) ? $context['facts'] : [];
                $complaints = is_array($context['chief_complaints'] ?? null) ? $context['chief_complaints'] : [];
                $derived = ClinicalInterviewContextResolver::deriveFamilies(
                    $complaints,
                    $activeSpan !== '' ? $activeSpan : (string) ($context['chief_complaint'] ?? ''),
                    $facts
                );
                $activeFamilies = array_values(array_filter(array_map(
                    static fn ($c): string => strtolower(trim((string) $c)),
                    is_array($derived) ? $derived : []
                )));
            } catch (Throwable) {
                $activeFamilies = [];
            }
        }

        return [
            'id' => $activeId,
            'span' => $activeSpan,
            'families' => $activeFamilies,
        ];
    }

    /**
     * Compact known-information block for Gemini select/phrase prompts.
     *
     * @param array<string, mixed> $context
     * @param array<string, mixed> $facts
     */
    private static function knownInformationBlock(array $context, string $transcript, array $facts): string
    {
        $patientEvidence = '';
        if (class_exists('ClinicalInterviewEngine') && method_exists('ClinicalInterviewEngine', 'patientEvidenceText')) {
            try {
                $patientEvidence = trim((string) ClinicalInterviewEngine::patientEvidenceText($context));
            } catch (Throwable) {
                $patientEvidence = '';
            }
        }
        if ($patientEvidence === '') {
            $patientEvidence = trim((string) (
                ($context['chief_complaint'] ?? '')
                ?: (($context['complaint_text_cleaner']['original'] ?? '') ?: '')
            ));
            $turns = [];
            foreach ((array) ($context['patient_turns'] ?? []) as $t) {
                if (is_string($t) && trim($t) !== '') {
                    $turns[] = trim($t);
                }
            }
            if ($turns !== []) {
                $patientEvidence = trim($patientEvidence . '. ' . implode('. ', $turns));
            }
        }

        $knownCase = '';
        if (class_exists('ClinicalInterviewAdaptivePolicy')) {
            $knownCase = ClinicalInterviewAdaptivePolicy::fullCaseHaystack($context, $transcript, $facts);
        }

        $polarity = [];
        foreach ([
            'weakness', 'speech_difficulty', 'vision_change', 'breathing_difficulty',
            'bleeding_continuing', 'bleeding_heavy', 'dizziness', 'chest_radiation',
            'sweating', 'abdominal_associated', 'fever_confirmed', 'blood_in_stool', 'pregnancy',
        ] as $key) {
            if (array_key_exists($key, $facts) && ($facts[$key] === true || $facts[$key] === false)) {
                $polarity[] = $key . '=' . ($facts[$key] === true ? 'true' : 'false');
            }
        }

        $structured = [];
        if (($facts['pain_score'] ?? null) !== null && $facts['pain_score'] !== '') {
            $structured[] = 'pain_score=' . (int) $facts['pain_score'];
        }
        if (trim((string) ($facts['pain_qualifier'] ?? '')) !== '') {
            $structured[] = 'pain_qualifier=' . trim((string) $facts['pain_qualifier']);
        }
        if (trim((string) ($facts['onset'] ?? '')) !== '') {
            $structured[] = 'onset=' . trim((string) $facts['onset']);
        }
        if (trim((string) ($facts['duration_label'] ?? '')) !== '') {
            $structured[] = 'duration=' . trim((string) $facts['duration_label']);
        }

        $locations = [];
        foreach ((array) ($facts['body_locations'] ?? []) as $loc) {
            if (is_string($loc) && trim($loc) !== '') {
                $locations[] = trim($loc);
                $structured[] = 'location=' . trim($loc);
            }
        }

        $symptoms = [];
        foreach (['symptoms_patient', 'symptoms', 'associated_symptoms'] as $symKey) {
            foreach ((array) ($facts[$symKey] ?? []) as $s) {
                if (is_string($s) && trim($s) !== '') {
                    $symptoms[] = trim($s);
                }
            }
        }
        $symptoms = array_values(array_unique($symptoms));

        $answered = [];
        foreach ((array) ($context['questions_answered'] ?? []) as $qa) {
            if (!is_array($qa)) {
                continue;
            }
            $qid = trim((string) ($qa['question_id'] ?? ''));
            $a = trim((string) ($qa['answer'] ?? $qa['patient_answer'] ?? ''));
            if ($qid !== '' || $a !== '') {
                $answered[] = trim($qid . '=' . $a);
            }
        }
        $asked = array_values(array_filter(array_map('strval', (array) ($context['questions_asked'] ?? []))));

        $aiMeaning = trim((string) (($context['semantic_bridge']['ollama_meaning'] ?? '') ?: ''));
        $aiConcept = trim((string) (($context['semantic_bridge']['gemini_concept'] ?? '') ?: ''));
        $aiSymptoms = [];
        foreach ((array) ($facts['symptoms_ai'] ?? []) as $s) {
            if (is_string($s) && trim($s) !== '') {
                $aiSymptoms[] = trim($s);
            }
        }

        return 'Original patient complaint / patient-authored evidence (do not treat AI gloss as patient speech): '
            . mb_substr($patientEvidence !== '' ? $patientEvidence : '(none)', 0, 500) . "\n"
            . 'Known body locations: '
            . ($locations !== [] ? implode(', ', $locations) : '(none)') . "\n"
            . 'Known symptoms (patient/structured; not AI-only): '
            . ($symptoms !== [] ? implode(', ', $symptoms) : '(none)') . "\n"
            . 'Structured clinical facts already known: '
            . ($structured !== [] ? implode('; ', $structured) : '(none)') . "\n"
            . 'Structured polarity already known: '
            . ($polarity !== [] ? implode('; ', $polarity) : '(none)') . "\n"
            . 'Complete-case known summary: '
            . mb_substr($knownCase !== '' ? $knownCase : '(none)', 0, 900) . "\n"
            . 'Previous patient answers: '
            . ($answered !== [] ? implode(' | ', $answered) : '(none)') . "\n"
            . 'Previously answered / asked interview slots: '
            . ($asked !== [] ? implode(', ', $asked) : '(none)') . "\n"
            . 'AI-derived meaning (NOT patient-authored; do not re-ask as if unknown patient speech): '
            . mb_substr($aiMeaning !== '' ? $aiMeaning : '(none)', 0, 240) . "\n"
            . 'AI-derived concept/symptoms (NOT patient-authored): '
            . trim(($aiConcept !== '' ? $aiConcept . '; ' : '') . ($aiSymptoms !== [] ? implode(', ', $aiSymptoms) : '(none)')) . "\n";
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function parseSelectPayload(string $raw): ?array
    {
        $raw = trim(str_replace("\r\n", "\n", $raw));
        $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw) ?? $raw;
        $raw = preg_replace('/\s*```$/', '', $raw) ?? $raw;
        $decoded = json_decode(trim($raw), true);
        if (!is_array($decoded) && preg_match('/\{[\s\S]*\}/', $raw, $m)) {
            $decoded = json_decode($m[0], true);
        }
        if (!is_array($decoded)) {
            return null;
        }
        if (!isset($decoded['question']) && isset($decoded['text'])) {
            $decoded['question'] = $decoded['text'];
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $parsed
     * @param list<array<string, mixed>> $indexed
     * @return array<string, mixed>|null
     */
    private static function resolveSelectedCandidate(array $parsed, array $indexed): ?array
    {
        if (isset($parsed['index'])) {
            $i = (int) $parsed['index'];
            if (isset($indexed[$i])) {
                return $indexed[$i];
            }
        }
        $qid = strtoupper(trim((string) ($parsed['question_id'] ?? '')));
        $finding = strtolower(trim((string) ($parsed['target_finding'] ?? '')));
        foreach ($indexed as $row) {
            if ($qid !== '' && $qid === (string) $row['question_id']) {
                if ($finding === '' || $finding === (string) $row['target_finding']) {
                    return $row;
                }
            }
        }
        foreach ($indexed as $row) {
            if ($finding !== '' && $finding === (string) $row['target_finding']) {
                return $row;
            }
        }

        return null;
    }

    private static function looksBundledOrMultiQuestion(string $text): bool
    {
        $low = mb_strtolower($text);
        if (substr_count($text, '?') > 1) {
            return true;
        }
        if (preg_match('/\b(or|ukon|o|at|and|kag)\b/u', $low)
            && preg_match('/\b(vomit|fever|bleed|sweat|dizz|blood|suka|hilanat|lagnat|dugo)\b/u', $low)
            && preg_match('/\b(vomit|fever|bleed|sweat|dizz|blood|suka|hilanat|lagnat|dugo).{0,40}\b(or|ukon|o)\b.{0,40}\b(vomit|fever|bleed|sweat|dizz|blood|suka|hilanat|lagnat|dugo)/u', $low)
        ) {
            return true;
        }
        if (preg_match('/\b(first|second|third|1\)|2\)|3\)|a\)|b\)|c\))\b/u', $low)) {
            return true;
        }

        return false;
    }

    private static function looksLikeTriageOrDiagnosis(string $text): bool
    {
        return (bool) preg_match(
            '/\b(EMERGENCY|URGENT|NON-URGENT|diagnos|prescription|you have (a |an )?[a-z]{4,}\s+disease)\b/iu',
            $text
        );
    }

    /**
     * Filtered WHO/IITT information hints only (never triage).
     * Keeps signs that appear still missing, relevant to active complaint/families,
     * and compatible with the current eligible candidate set. Does not change WHO rules.
     *
     * @param list<array<string, mixed>> $candidates
     * @param list<string> $activeFamilies
     */
    private static function whoInformationNeeds(
        string $caseHaystack,
        array $candidates = [],
        array $activeFamilies = [],
        string $activeSpan = ''
    ): string {
        if (!class_exists('WhoIittTriageRulesLoader')) {
            return '';
        }

        $relevanceParts = [];
        foreach ($activeFamilies as $fam) {
            $fam = strtolower(trim((string) $fam));
            if ($fam !== '') {
                $relevanceParts[] = $fam;
            }
        }
        $spanLow = mb_strtolower(trim($activeSpan));
        if ($spanLow !== '') {
            $relevanceParts[] = $spanLow;
        }
        foreach ($candidates as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach (['target_finding', 'clinical_purpose', 'question_id', 'bank_template'] as $k) {
                $v = strtolower(trim((string) ($row[$k] ?? '')));
                if ($v !== '') {
                    $relevanceParts[] = $v;
                }
            }
        }
        $relevanceBlob = trim(implode(' ', $relevanceParts));
        // No active/candidate relevance context → do not dump unrestricted WHO signs.
        if ($relevanceBlob === '') {
            return '';
        }

        $hay = mb_strtolower($caseHaystack);
        try {
            $lines = [];
            foreach (WhoIittTriageRulesLoader::rules() as $rule) {
                if (!is_array($rule)) {
                    continue;
                }
                $sign = trim((string) ($rule['clinical_sign'] ?? ''));
                $level = strtoupper((string) ($rule['triage_level'] ?? ''));
                $id = trim((string) ($rule['rule_id'] ?? ''));
                if ($sign === '' || $id === '') {
                    continue;
                }
                if (!in_array($level, ['EMERGENCY', 'URGENT'], true)) {
                    continue;
                }
                $signLow = mb_strtolower($sign);
                // Already reflected in known case text → not "still missing".
                if ($hay !== '' && self::whoSignAlreadyKnown($signLow, $hay)) {
                    continue;
                }
                // Must relate to active complaint/families or an open candidate purpose.
                if (!self::whoSignRelevantToContext($signLow, $relevanceBlob)) {
                    continue;
                }
                $lines[] = $id . ' [' . ($level === 'EMERGENCY' ? 'RED info' : 'YELLOW info') . ']: ' . $sign;
                if (count($lines) >= 5) {
                    break;
                }
            }

            return implode("\n", $lines);
        } catch (Throwable) {
            return '';
        }
    }

    private static function whoSignAlreadyKnown(string $signLow, string $hayLow): bool
    {
        $tokens = preg_split('/[^a-z0-9]+/u', $signLow) ?: [];
        $meaningful = [];
        foreach ($tokens as $t) {
            $t = trim((string) $t);
            if (mb_strlen($t) < 4) {
                continue;
            }
            if (in_array($t, ['with', 'without', 'from', 'that', 'this', 'have', 'been', 'severe', 'acute'], true)) {
                continue;
            }
            $meaningful[] = $t;
        }
        if ($meaningful === []) {
            return false;
        }
        $hits = 0;
        foreach ($meaningful as $t) {
            if (str_contains($hayLow, $t)) {
                $hits++;
            }
        }

        return $hits >= max(1, (int) ceil(count($meaningful) * 0.5));
    }

    private static function whoSignRelevantToContext(string $signLow, string $relevanceBlob): bool
    {
        $tokens = preg_split('/[^a-z0-9_]+/u', $signLow) ?: [];
        foreach ($tokens as $t) {
            $t = trim((string) $t);
            if (mb_strlen($t) < 4) {
                continue;
            }
            if (in_array($t, ['with', 'without', 'from', 'that', 'this', 'have', 'been', 'severe', 'acute', 'pain'], true)) {
                continue;
            }
            if (str_contains($relevanceBlob, $t)) {
                return true;
            }
        }
        // Family/candidate tokens that appear inside the sign text.
        $relTokens = preg_split('/[^a-z0-9_]+/u', $relevanceBlob) ?: [];
        foreach ($relTokens as $t) {
            $t = trim((string) $t);
            if (mb_strlen($t) < 4) {
                continue;
            }
            if (str_contains($signLow, $t)) {
                return true;
            }
        }

        return false;
    }
}
