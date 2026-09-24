<?php
/**
 * Cohere Rerank client — ranks existing documents only.
 *
 * Does NOT generate, rewrite, diagnose, prescribe, or modify Care Tip text.
 * Used only as a semantic ranking fallback over curated CSV candidates.
 */

final class CohereRerankClient
{
    private const ENDPOINT = 'https://api.cohere.com/v2/rerank';
    private const DEFAULT_MODEL = 'rerank-v3.5';
    private const TIMEOUT = 12;

    private static string $lastError = '';

    public static function lastError(): string
    {
        return self::$lastError;
    }

    public static function enabled(): bool
    {
        $raw = getenv('COHERE_RERANK_ENABLED');
        if ($raw === false || $raw === '') {
            $raw = $_ENV['COHERE_RERANK_ENABLED'] ?? '1';
        }
        if (in_array(strtolower(trim((string) $raw)), ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        // Prefer Railway (key stays on Python service) or local direct key.
        return self::shouldUseRailway() || self::apiKey() !== '';
    }

    public static function minScore(): float
    {
        $raw = getenv('COHERE_RERANK_MIN_SCORE');
        if ($raw === false || $raw === '') {
            $raw = $_ENV['COHERE_RERANK_MIN_SCORE'] ?? '0.45';
        }
        $score = is_numeric($raw) ? (float) $raw : 0.45;

        return max(0.0, min(1.0, $score));
    }

    /**
     * Rank documents for a query. Returns list of {index, score} sorted best-first.
     *
     * @param list<string> $documents
     * @return list<array{index:int,score:float}>
     */
    public static function rerank(string $query, array $documents, int $topN = 3): array
    {
        self::$lastError = '';
        $query = trim($query);
        if ($query === '' || $documents === [] || !self::enabled()) {
            if ($query !== '' && $documents !== [] && !self::enabled()) {
                self::$lastError = 'cohere_disabled_or_missing_key';
            }

            return [];
        }

        $docs = [];
        foreach ($documents as $doc) {
            $text = trim((string) $doc);
            if ($text !== '') {
                $docs[] = $text;
            }
        }
        if ($docs === []) {
            return [];
        }

        $topN = max(1, min(count($docs), $topN));
        $model = trim((string) (getenv('COHERE_RERANK_MODEL') ?: ($_ENV['COHERE_RERANK_MODEL'] ?? self::DEFAULT_MODEL)));
        if ($model === '') {
            $model = self::DEFAULT_MODEL;
        }

        // Production / configured AI service: keep Cohere key on Railway.
        if (self::shouldUseRailway()) {
            try {
                if (!class_exists('AiServiceClient')) {
                    throw new RuntimeException('AiServiceClient missing');
                }
                $ranked = AiServiceClient::careTipsRerank($query, $docs, $topN, $model, self::TIMEOUT);
                if (is_array($ranked)) {
                    return $ranked;
                }
                self::$lastError = 'empty railway cohere reply';
            } catch (Throwable $e) {
                self::$lastError = $e->getMessage();
                error_log('CohereRerankClient railway: ' . $e->getMessage());
                // Fall through to direct Cohere only when a local key exists.
                if (self::apiKey() === '') {
                    return [];
                }
            }
        }

        if (self::apiKey() === '') {
            self::$lastError = self::$lastError !== '' ? self::$lastError : 'cohere_api_key_missing';

            return [];
        }

        $payload = [
            'model' => $model,
            'query' => mb_substr($query, 0, 1000),
            'documents' => array_map(
                static fn (string $d): array => ['text' => mb_substr($d, 0, 2000)],
                $docs
            ),
            'top_n' => $topN,
        ];

        try {
            $data = self::httpPostJson(self::ENDPOINT, $payload, [
                'Authorization: Bearer ' . self::apiKey(),
                'Accept: application/json',
            ]);
        } catch (Throwable $e) {
            self::$lastError = $e->getMessage();
            error_log('CohereRerankClient: ' . $e->getMessage());

            return [];
        }

        $results = is_array($data['results'] ?? null) ? $data['results'] : [];
        $out = [];
        foreach ($results as $row) {
            if (!is_array($row)) {
                continue;
            }
            $index = isset($row['index']) ? (int) $row['index'] : -1;
            $score = isset($row['relevance_score']) ? (float) $row['relevance_score'] : -1.0;
            if ($index < 0 || $index >= count($docs) || $score < 0) {
                continue;
            }
            $out[] = ['index' => $index, 'score' => $score];
        }

        usort($out, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return $out;
    }

    private static function shouldUseRailway(): bool
    {
        if (!defined('AI_SERVICE_ENABLED') || !AI_SERVICE_ENABLED) {
            return false;
        }
        if (!defined('AI_SERVICE_BASE_URL') || !is_string(AI_SERVICE_BASE_URL) || AI_SERVICE_BASE_URL === '') {
            return false;
        }

        return class_exists('AiServiceClient');
    }

    private static function apiKey(): string
    {
        foreach (['COHERE_API_KEY', 'COHERE_KEY'] as $envKey) {
            $val = trim((string) (getenv($envKey) ?: ($_ENV[$envKey] ?? '')));
            if ($val !== '') {
                return $val;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $extraHeaders
     * @return array<string, mixed>
     */
    private static function httpPostJson(string $url, array $payload, array $extraHeaders): array
    {
        $headers = array_merge(['Content-Type: application/json'], $extraHeaders);
        $verifySsl = true;
        $rawSsl = getenv('AI_SSL_VERIFY');
        if ($rawSsl === false || $rawSsl === '') {
            $rawSsl = $_ENV['AI_SSL_VERIFY'] ?? null;
        }
        if ($rawSsl !== null && $rawSsl !== '') {
            $verifySsl = !in_array(strtolower(trim((string) $rawSsl)), ['0', 'false', 'no', 'off'], true);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => min(5, self::TIMEOUT),
            CURLOPT_SSL_VERIFYPEER => $verifySsl,
            CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
        ]);
        $ca = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'cacert.pem';
        if (!is_readable($ca)) {
            $ca = (string) (ini_get('curl.cainfo') ?: ini_get('openssl.cafile') ?: '');
        }
        if ($ca !== '' && is_readable($ca)) {
            curl_setopt($ch, CURLOPT_CAINFO, $ca);
        }
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $errno !== 0) {
            throw new RuntimeException('Cohere HTTP error: ' . ($error !== '' ? $error : 'errno ' . $errno));
        }
        $data = json_decode((string) $raw, true);
        if (!is_array($data) || $code >= 400) {
            $msg = is_array($data) ? (string) ($data['message'] ?? json_encode($data)) : (string) $raw;
            throw new RuntimeException('Cohere HTTP ' . $code . ': ' . mb_substr($msg, 0, 180));
        }

        return $data;
    }
}
