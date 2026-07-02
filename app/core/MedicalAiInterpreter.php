<?php
/**
 * AI-powered Hiligaynon/Ilonggo medical language understanding for Step 2 translation.
 * Provider priority: Groq → OpenAI → local Llama (Ollama).
 */

final class MedicalAiInterpreter
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
You are a medical language assistant for a Philippine telemedicine system.
Patients may write in Hiligaynon/Ilonggo, English, or mixed dialect with slang, misspellings, abbreviations, and incomplete sentences.

Your job is to produce a natural English medical interpretation and extract structured medical concepts ONLY from what the patient wrote.

STRICT RULES:
- Do NOT diagnose diseases or invent conditions not implied by the input.
- Do NOT create new medical condition names — use common English medical terms (symptoms, conditions, allergies).
- Do NOT bypass validation — output terms that can be checked against standard medical datasets.
- Improve translation and clarify intent; extract symptoms, conditions, allergies, body parts, severity, and duration when present.
- If unsure, lower confidence rather than guessing.

Respond with JSON only (no markdown):
{
  "english_interpretation": "natural English summary of patient input",
  "confidence_score": 0-100,
  "concepts": [
    {
      "term": "english medical term",
      "type": "symptom|condition|allergy|body_part|severity|duration",
      "body_part": "optional body part or null",
      "severity": "optional mild|moderate|severe or null",
      "duration": "optional duration phrase or null",
      "confidence": 0-100
    }
  ],
  "notes": "brief note on dialect/slang handling or empty string"
}
PROMPT;

    /** @return list<string> */
    public static function providerChain(): array
    {
        $order = getenv('MEDCONNECT_AI_PROVIDER_ORDER') ?: 'groq,openai,local';
        $names = array_values(array_filter(array_map('trim', explode(',', (string) $order))));
        return $names !== [] ? $names : ['groq', 'openai', 'local'];
    }

    /** @return array<string, mixed> */
    public static function providerStatus(): array
    {
        return [
            'enabled'           => AI_INTERPRETER_ENABLED,
            'groq_configured'   => GROQ_API_KEY !== '',
            'openai_configured' => OPENAI_API_KEY !== '',
            'local_llama_url'   => LOCAL_LLAMA_URL,
            'local_llama_model' => LOCAL_LLAMA_MODEL,
            'primary_model'     => GROQ_MODEL,
            'provider_order'    => self::providerChain(),
        ];
    }

    /**
     * @param list<string> $dictionaryTerms
     * @return array<string, mixed>
     */
    public static function interpretMedicalText(
        string $fieldLabel,
        string $originalText,
        string $dictionaryEnglish = '',
        array $dictionaryTerms = [],
        ?array $pipelineContext = null
    ): array {
        $originalText = trim($originalText);
        if (!AI_INTERPRETER_ENABLED) {
            return self::emptyResult('disabled', $dictionaryEnglish ?: $originalText, 'AI interpreter disabled via MEDCONNECT_AI_INTERPRETER=0');
        }

        if ($originalText === '' && $dictionaryEnglish === '') {
            return self::emptyResult('skipped', '', 'No input text');
        }

        $terms = $dictionaryTerms !== [] ? implode(', ', $dictionaryTerms) : '(none)';
        $ctx = is_array($pipelineContext) ? $pipelineContext : [];
        $stages = is_array($ctx['stages'] ?? null) ? $ctx['stages'] : [];
        $dictMatches = is_array($ctx['dictionary_matches'] ?? null)
            ? $ctx['dictionary_matches']
            : (is_array($stages['medical_dictionary']['matches'] ?? null) ? $stages['medical_dictionary']['matches'] : []);
        $datasetMatches = is_array($ctx['dataset_matches'] ?? null)
            ? $ctx['dataset_matches']
            : (is_array($stages['hiligaynon_dataset']['matches'] ?? null) ? $stages['hiligaynon_dataset']['matches'] : []);
        $keywords = is_array($ctx['keywords'] ?? null)
            ? $ctx['keywords']
            : (is_array($stages['keyword_extraction']['keywords'] ?? null) ? $stages['keyword_extraction']['keywords'] : []);
        $keywordText = $keywords !== [] ? implode(', ', $keywords) : '(none)';

        $userPrompt = "Field: {$fieldLabel}\n"
            . "Original patient input:\n" . ($originalText !== '' ? $originalText : '(empty)') . "\n\n"
            . "PIPELINE CONTEXT (use this to improve interpretation — do not invent terms):\n"
            . "1. Medical Dictionary matches:\n" . self::formatMatchLines($dictMatches) . "\n\n"
            . "2. Hiligaynon Dataset matches:\n" . self::formatMatchLines($datasetMatches) . "\n\n"
            . "3. Extracted keywords: {$keywordText}\n\n"
            . "4. Dictionary translation so far:\n" . ($dictionaryEnglish !== '' ? $dictionaryEnglish : '(none)') . "\n"
            . "5. Dictionary-matched English terms: {$terms}\n\n"
            . 'Perform contextual language understanding on the original Hiligaynon/Ilonggo input. '
            . 'Resolve slang, misspellings, and mixed dialect using the pipeline context above. '
            . 'Produce a natural English medical interpretation and extract implied medical concepts '
            . 'for downstream fuzzy matching and dataset validation.';

        $lastError = '';
        foreach (self::providerChain() as $provider) {
            try {
                [$content, $usedProvider, $usedModel] = self::chatCompletion($provider, $userPrompt);
                $parsed = self::extractJson($content);
                $concepts = self::normalizeConcepts($parsed);
                $score = max(0, min(100, (int) ($parsed['confidence_score'] ?? 0)));
                if ($score === 0 && $concepts !== []) {
                    $sum = array_sum(array_column($concepts, 'confidence'));
                    $score = (int) round($sum / count($concepts));
                }

                return [
                    'status'                 => 'complete',
                    'provider'               => $usedProvider,
                    'model'                  => $usedModel,
                    'english_interpretation' => trim((string) ($parsed['english_interpretation'] ?? $dictionaryEnglish ?: $originalText)),
                    'confidence_score'       => $score,
                    'concepts'               => $concepts,
                    'notes'                  => trim((string) ($parsed['notes'] ?? '')),
                ];
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
            }
        }

        return self::emptyResult('unavailable', $dictionaryEnglish ?: $originalText, $lastError ?: 'No AI provider available');
    }

    /**
     * @param array<string, mixed> $preprocessing
     * @param array<string, mixed> $translation
     * @return array<string, mixed>
     */
    public static function enrichTranslationWithAi(
        array $preprocessing,
        array $translation,
        string $conditionsOriginal = '',
        string $allergiesOriginal = ''
    ): array {
        $conditionsBlock = is_array($translation['conditions'] ?? null) ? $translation['conditions'] : [];
        $allergiesBlock = is_array($translation['allergies'] ?? null) ? $translation['allergies'] : [];
        $prepConditions = is_array($preprocessing['conditions'] ?? null) ? $preprocessing['conditions'] : [];
        $prepAllergies = is_array($preprocessing['allergies'] ?? null) ? $preprocessing['allergies'] : [];

        $conditionsOriginal = $conditionsOriginal !== '' ? $conditionsOriginal : (string) ($prepConditions['original'] ?? '');
        $allergiesOriginal = $allergiesOriginal !== '' ? $allergiesOriginal : (string) ($prepAllergies['original'] ?? '');

        $aiConditions = self::interpretMedicalText(
            'Medical conditions & symptoms',
            $conditionsOriginal,
            (string) ($conditionsBlock['english_text'] ?? ''),
            self::fieldDictionaryTerms($conditionsBlock)
        );
        $aiAllergies = self::interpretMedicalText(
            'Known allergies',
            $allergiesOriginal,
            (string) ($allergiesBlock['english_text'] ?? ''),
            self::fieldDictionaryTerms($allergiesBlock)
        );

        $conditionsBlock = self::mergeAiIntoField($conditionsBlock, $aiConditions, 'condition', $conditionsOriginal);
        $allergiesBlock = self::mergeAiIntoField($allergiesBlock, $aiAllergies, 'allergy', $allergiesOriginal);

        $combined = array_filter([
            (string) ($conditionsBlock['english_text'] ?? ''),
            (string) ($allergiesBlock['english_text'] ?? ''),
        ]);
        $combinedEnglish = implode(' | ', $combined);

        $totalM = (int) ($conditionsBlock['matched_count'] ?? 0) + (int) ($allergiesBlock['matched_count'] ?? 0);
        $totalU = (int) ($conditionsBlock['unmatched_count'] ?? 0) + (int) ($allergiesBlock['unmatched_count'] ?? 0);
        $total = (int) ($conditionsBlock['total_count'] ?? 0) + (int) ($allergiesBlock['total_count'] ?? 0);
        $overall = MedicalTranslator::overallStatusPublic($totalM, $totalU, $total);

        $active = $aiConditions['status'] === 'complete' ? $aiConditions : $aiAllergies;
        if ($aiConditions['status'] === 'complete' && $aiAllergies['status'] === 'complete') {
            $active = $aiConditions;
        }

        $scores = array_values(array_filter([
            (int) ($aiConditions['confidence_score'] ?? 0),
            (int) ($aiAllergies['confidence_score'] ?? 0),
        ], static fn (int $s): bool => $s > 0));
        $overallConfidence = $scores !== [] ? (int) round(array_sum($scores) / count($scores)) : 0;

        $translation['conditions'] = $conditionsBlock;
        $translation['allergies'] = $allergiesBlock;
        $translation['combined_english'] = $combinedEnglish;
        $translation['overall_status'] = $overall;
        $translation['overall_status_label'] = MedicalTranslator::statusLabelPublic($overall, $totalM, $total);
        $translation['ai_interpretation'] = [
            'status'                        => $active['status'] ?? 'unavailable',
            'provider'                      => $active['provider'] ?? null,
            'model'                         => $active['model'] ?? null,
            'overall_confidence'            => $overallConfidence,
            'english_interpretation'        => $combinedEnglish,
            'conditions'                    => $aiConditions,
            'allergies'                     => $aiAllergies,
            'detected_concepts'             => array_merge(
                is_array($aiConditions['concepts'] ?? null) ? $aiConditions['concepts'] : [],
                is_array($aiAllergies['concepts'] ?? null) ? $aiAllergies['concepts'] : []
            ),
            'policy'                        => 'Groq contextual analysis improves translation only. '
                . 'All concepts must pass fuzzy matching (Step 3) and dataset validation (Step 4).',
            'concepts_queued_for_validation' => (int) ($conditionsBlock['ai_concepts_added'] ?? 0)
                + (int) ($allergiesBlock['ai_concepts_added'] ?? 0),
            'primary_provider'              => 'groq',
        ];

        return $translation;
    }

    /**
     * @param array<string, mixed> $fieldBlock
     * @param array<string, mixed>|null $pipelineContext
     * @return array<string, mixed>
     */
    public static function enrichFieldWithAi(
        array $fieldBlock,
        string $originalText,
        string $expectedCategory,
        ?array $pipelineContext = null
    ): array {
        $fieldLabel = $expectedCategory === 'allergy' ? 'Known allergies' : 'Medical conditions & symptoms';
        $aiResult = self::interpretMedicalText(
            $fieldLabel,
            $originalText,
            (string) ($fieldBlock['english_text'] ?? ''),
            self::fieldDictionaryTerms($fieldBlock),
            $pipelineContext
        );

        if (($aiResult['status'] ?? '') !== 'complete' && trim($originalText) !== '') {
            $ctx = is_array($pipelineContext) ? $pipelineContext : [];
            $fallback = NlpDictionaryFallback::buildInterpretation(
                $originalText,
                is_array($ctx['dictionary_matches'] ?? null) ? $ctx['dictionary_matches'] : [],
                is_array($ctx['dataset_matches'] ?? null) ? $ctx['dataset_matches'] : [],
                is_array($ctx['keywords'] ?? null) ? $ctx['keywords'] : [],
            );
            if ((int) ($fallback['confidence_score'] ?? 0) > 0) {
                $aiResult = $fallback;
            }
        }

        if (trim($originalText) !== '') {
            error_log('[NLP Step2] Groq status=' . ($aiResult['status'] ?? 'unknown')
                . ' provider=' . ($aiResult['provider'] ?? 'none')
                . ' confidence=' . ($aiResult['confidence_score'] ?? 0)
                . ' input=' . substr($originalText, 0, 80));
        }

        return self::mergeAiIntoField($fieldBlock, $aiResult, $expectedCategory, $originalText);
    }

    /** @param list<array<string, mixed>> $matches */
    private static function formatMatchLines(array $matches): string
    {
        if ($matches === []) {
            return '(none)';
        }
        $lines = [];
        $limit = min(count($matches), 12);
        for ($i = 0; $i < $limit; $i++) {
            $row = $matches[$i];
            $local = (string) ($row['local_term'] ?? $row['matched_term'] ?? '?');
            $english = (string) ($row['english_term'] ?? $row['english'] ?? '?');
            $lines[] = "  - {$local} → {$english}";
        }
        if (count($matches) > 12) {
            $lines[] = '  … and ' . (count($matches) - 12) . ' more';
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $fieldBlock
     * @return list<string>
     */
    private static function fieldDictionaryTerms(array $fieldBlock): array
    {
        $terms = [];
        foreach ($fieldBlock['items'] ?? [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $en = trim((string) ($item['english_term'] ?? ''));
            if ($en !== '') {
                $terms[] = $en;
            }
        }
        return $terms;
    }

    /** @param array<string, mixed> $fieldBlock
     * @param array<string, mixed> $aiResult
     * @return array<string, mixed>
     */
    private static function mergeAiIntoField(
        array $fieldBlock,
        array $aiResult,
        string $expectedCategory,
        string $originalText
    ): array {
        $items = is_array($fieldBlock['items'] ?? null) ? $fieldBlock['items'] : [];
        $queue = is_array($fieldBlock['validation_queue'] ?? null) ? $fieldBlock['validation_queue'] : [];
        $seen = [];
        foreach ($queue as $q) {
            if (!is_array($q)) {
                continue;
            }
            $key = mb_strtolower(trim((string) ($q['match_term'] ?? $q['english_term'] ?? '')));
            if ($key !== '') {
                $seen[$key] = true;
            }
        }

        $matched = (int) ($fieldBlock['matched_count'] ?? 0);
        $unmatched = (int) ($fieldBlock['unmatched_count'] ?? 0);
        $aiAdded = 0;

        foreach ($aiResult['concepts'] ?? [] as $concept) {
            if (!is_array($concept)) {
                continue;
            }
            $term = trim((string) ($concept['term'] ?? ''));
            if ($term === '') {
                continue;
            }
            $ctype = mb_strtolower((string) ($concept['type'] ?? $expectedCategory));
            if (in_array($ctype, ['severity', 'duration', 'body_part'], true)) {
                continue;
            }
            $category = match ($ctype) {
                'symptom'   => 'symptom',
                'condition' => 'condition',
                'allergy'   => 'allergy',
                default     => $expectedCategory,
            };

            if (isset($seen[mb_strtolower($term)])) {
                continue;
            }

            $item = MedicalTranslator::translateEnglishConcept($term, $originalText !== '' ? $originalText : $term, $category, 'ai_interpreter');
            $item['ai_confidence'] = max(0, min(100, (int) ($concept['confidence'] ?? 0)));
            $item['ai_body_part'] = $concept['body_part'] ?? null;
            $item['ai_severity'] = $concept['severity'] ?? null;
            $item['ai_duration'] = $concept['duration'] ?? null;
            $item['source'] = 'ai_interpreter';
            $items[] = $item;

            $matchTerm = trim((string) ($item['match_term'] ?? $item['english_term'] ?? ''));
            if ($matchTerm !== '' && !empty($item['ready_for_validation'])) {
                $seen[mb_strtolower($matchTerm)] = true;
                $queue[] = [
                    'local_term'     => $item['local_term'] ?? $originalText,
                    'english_term'   => $item['english_term'] ?? $matchTerm,
                    'match_term'     => $matchTerm,
                    'category'       => $item['category'] ?? $category,
                    'status'         => $item['status'] ?? 'unmatched',
                    'input_language' => $item['input_language'] ?? 'english',
                    'was_translated' => $item['was_translated'] ?? true,
                    'source'         => 'ai_interpreter',
                    'ai_confidence'  => $item['ai_confidence'] ?? 0,
                ];
                $aiAdded++;
                if (($item['status'] ?? '') === 'matched') {
                    $matched++;
                } else {
                    $unmatched++;
                }
            }
        }

        $englishText = trim((string) ($aiResult['english_interpretation'] ?? $fieldBlock['english_text'] ?? ''));
        $total = count($queue) > 0 ? count($queue) : (int) ($fieldBlock['total_count'] ?? 0);
        $status = MedicalTranslator::overallStatusPublic($matched, $unmatched, max($total, 1));

        $fieldBlock['items'] = $items;
        $fieldBlock['validation_queue'] = $queue;
        $fieldBlock['english_text'] = $englishText !== '' ? $englishText : (string) ($fieldBlock['english_text'] ?? '');
        $fieldBlock['matched_count'] = $matched;
        $fieldBlock['unmatched_count'] = $unmatched;
        $fieldBlock['total_count'] = max($total, count($queue));
        $fieldBlock['status'] = $status;
        $fieldBlock['status_label'] = MedicalTranslator::statusLabelPublic($status, $matched, max($total, 1));
        $fieldBlock['ai_interpretation'] = $aiResult;
        $fieldBlock['ai_concepts_added'] = $aiAdded;

        return $fieldBlock;
    }

    /** @return array{0:string,1:string,2:string} */
    private static function chatCompletion(string $provider, string $userPrompt): array
    {
        if ($provider === 'groq') {
            if (GROQ_API_KEY === '') {
                throw new RuntimeException('Groq API key not configured');
            }
            $data = self::httpPostJson(
                'https://api.groq.com/openai/v1/chat/completions',
                [
                    'model'           => GROQ_MODEL,
                    'temperature'     => 0.1,
                    'response_format' => ['type' => 'json_object'],
                    'messages'        => [
                        ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                ],
                ['Authorization: Bearer ' . GROQ_API_KEY]
            );
            return [$data['choices'][0]['message']['content'], 'groq', GROQ_MODEL];
        }

        if ($provider === 'openai') {
            if (OPENAI_API_KEY === '') {
                throw new RuntimeException('OpenAI API key not configured');
            }
            $data = self::httpPostJson(
                'https://api.openai.com/v1/chat/completions',
                [
                    'model'           => OPENAI_MODEL,
                    'temperature'     => 0.1,
                    'response_format' => ['type' => 'json_object'],
                    'messages'        => [
                        ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                ],
                ['Authorization: Bearer ' . OPENAI_API_KEY]
            );
            return [$data['choices'][0]['message']['content'], 'openai', OPENAI_MODEL];
        }

        if ($provider === 'local') {
            $data = self::httpPostJson(
                LOCAL_LLAMA_URL . '/api/chat',
                [
                    'model'    => LOCAL_LLAMA_MODEL,
                    'stream'   => false,
                    'format'   => 'json',
                    'messages' => [
                        ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                ],
                []
            );
            $content = (string) ($data['message']['content'] ?? '');
            return [$content, 'local_llama', LOCAL_LLAMA_MODEL];
        }

        throw new RuntimeException('Unknown provider: ' . $provider);
    }

    /** @param array<string, mixed> $payload
     * @param list<string> $extraHeaders
     * @return array<string, mixed>
     */
    private static function httpPostJson(string $url, array $payload, array $extraHeaders): array
    {
        $headers = array_merge(['Content-Type: application/json'], $extraHeaders);
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init failed');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => AI_INTERPRETER_TIMEOUT,
        ]);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || $raw === false) {
            throw new RuntimeException($error !== '' ? $error : 'HTTP request failed');
        }
        if ($code >= 400) {
            throw new RuntimeException('HTTP ' . $code . ': ' . substr((string) $raw, 0, 200));
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid JSON response from AI provider');
        }

        return $decoded;
    }

    /** @return array<string, mixed> */
    private static function extractJson(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        if (preg_match('/\{[\s\S]*\}/', $text, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    }

    /** @param array<string, mixed> $raw
     * @return list<array<string, mixed>>
     */
    private static function normalizeConcepts(array $raw): array
    {
        $concepts = [];
        foreach ($raw['concepts'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $term = trim((string) ($row['term'] ?? ''));
            if ($term === '') {
                continue;
            }
            $ctype = mb_strtolower((string) ($row['type'] ?? 'symptom'));
            if (in_array($ctype, ['severity', 'duration', 'body_part'], true)) {
                continue;
            }
            if (!in_array($ctype, ['symptom', 'condition', 'allergy'], true)) {
                $ctype = 'symptom';
            }
            $concepts[] = [
                'term'       => $term,
                'type'       => $ctype,
                'body_part'  => $row['body_part'] ?? null,
                'severity'   => $row['severity'] ?? null,
                'duration'   => $row['duration'] ?? null,
                'confidence' => max(0, min(100, (int) ($row['confidence'] ?? 0))),
            ];
        }
        return $concepts;
    }

    /** @return array<string, mixed> */
    private static function emptyResult(string $status, string $english, string $notes): array
    {
        return [
            'status'                 => $status,
            'provider'               => null,
            'model'                  => null,
            'english_interpretation' => $english,
            'confidence_score'       => 0,
            'concepts'               => [],
            'notes'                  => $notes,
        ];
    }
}
