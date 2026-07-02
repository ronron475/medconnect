<?php
/**
 * HTTP client for the local Python FastAPI service (ai_service/app/main.py).
 */

final class AiServiceClient
{
    public static function analyzeTranscript(string $transcript, int $consultationId = 0): ?array
    {
        $payload = ['transcript' => $transcript];
        if ($consultationId > 0) {
            $payload['consultation_id'] = $consultationId;
        }

        $response = self::postJson(
            AI_SERVICE_BASE_URL . '/analyze',
            $payload,
            AI_SERVICE_TIMEOUT_ANALYZE
        );

        return self::extractData($response);
    }

    public static function analyzeMedicalProfile(string $allergies, string $currentMedications): ?array
    {
        $response = self::postJson(
            AI_SERVICE_BASE_URL . '/analyze-medical-profile',
            [
                'allergies'            => $allergies,
                'current_medications'  => $currentMedications,
            ],
            AI_SERVICE_TIMEOUT_ANALYZE
        );

        return self::extractData($response);
    }

    public static function recognizeSymptoms(string $text): ?array
    {
        $response = self::postJson(
            AI_SERVICE_BASE_URL . '/recognize-symptoms',
            ['text' => $text],
            AI_SERVICE_TIMEOUT_ANALYZE
        );

        return self::extractData($response);
    }

    public static function analyzeMedicalText(string $text): ?array
    {
        $response = self::postJson(
            AI_SERVICE_BASE_URL . '/analyze-medical-text',
            ['text' => $text],
            AI_SERVICE_TIMEOUT_ANALYZE
        );

        return self::extractData($response);
    }

    /**
     * @param list<string> $symptoms
     * @param list<string> $urgentFlags
     */
    public static function predictDisease(string $text, array $symptoms = [], array $urgentFlags = []): ?array
    {
        $response = self::postJson(
            AI_SERVICE_BASE_URL . '/predict-disease',
            [
                'text'          => $text,
                'symptoms'      => array_values($symptoms),
                'urgent_flags'  => array_values($urgentFlags),
            ],
            AI_SERVICE_TIMEOUT_ANALYZE
        );

        return self::extractData($response);
    }

    /**
     * @param string $filePath Local temp path
     * @param string $fieldName  "audio" or "video"
     */
    public static function transcribeFile(
        string $filePath,
        string $mimeType,
        string $originalName,
        string $fieldName = 'audio',
        int $timeout = AI_SERVICE_TIMEOUT_TRANSCRIBE
    ): ?array {
        if (!function_exists('curl_init')) {
            return null;
        }

        $curl = curl_init(AI_SERVICE_BASE_URL . '/transcribe');
        curl_setopt_array($curl, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_POSTFIELDS     => [
                $fieldName => new CURLFile($filePath, $mimeType, $originalName),
            ],
        ]);

        $body = curl_exec($curl);
        curl_close($curl);

        if ($body === false || $body === '') {
            return null;
        }

        return self::extractData(json_decode((string) $body, true));
    }

    /**
     * @param array<string, mixed> $translation
     * @return array<string, mixed>|null
     */
    public static function fuzzyMatchProfile(array $translation): ?array
    {
        $response = self::postJson(
            AI_SERVICE_BASE_URL . '/fuzzy/match-profile',
            ['translation' => $translation],
            AI_SERVICE_TIMEOUT_ANALYZE
        );

        return is_array($response) ? $response : null;
    }

    /**
     * @param list<array<string, mixed>> $queue
     * @return array<string, mixed>|null
     */
    public static function fuzzyMatchTextQueue(array $queue): ?array
    {
        $response = self::postJson(
            AI_SERVICE_BASE_URL . '/fuzzy/match-text-queue',
            ['text_queue' => array_values($queue)],
            AI_SERVICE_TIMEOUT_ANALYZE
        );

        return is_array($response) ? $response : null;
    }

    public static function isHealthy(int $timeoutSeconds = 3): bool
    {
        if (!AI_SERVICE_ENABLED) {
            return false;
        }
        $timeout = max(1, min($timeoutSeconds, AI_SERVICE_TIMEOUT_HEALTH));
        $body = self::httpGet(AI_SERVICE_BASE_URL . '/health', $timeout);
        if ($body === null) {
            return false;
        }
        $decoded = json_decode($body, true);
        return is_array($decoded) && !empty($decoded['success']);
    }

    /** @return array<string, mixed> */
    public static function connectionStatus(): array
    {
        $diag = AiServiceLauncher::diagnostics();
        $online = (bool) ($diag['online'] ?? false);

        return [
            'online'            => $online,
            'url'               => (string) ($diag['url'] ?? AI_SERVICE_BASE_URL),
            'message'           => $online
                ? 'Python AI service is online.'
                : (string) ($diag['reason'] ?? 'Cannot reach AI service.'),
            'status'            => $online ? 'online' : 'offline',
            'service'           => (string) ($diag['service'] ?? ''),
            'port'              => (int) ($diag['port'] ?? 8765),
            'port_open'         => (bool) ($diag['port_open'] ?? false),
            'reason'            => (string) ($diag['reason'] ?? ''),
            'engine'            => (string) ($diag['engine'] ?? 'php-validation-workflow'),
            'groq'              => (string) ($diag['groq'] ?? 'missing'),
            'groq_configured'   => (bool) ($diag['groq_configured'] ?? false),
            'groq_connected'    => (bool) ($diag['groq_connected'] ?? false),
            'groq_error'        => (string) ($diag['groq_error'] ?? ''),
            'model'             => (string) ($diag['model'] ?? GROQ_MODEL),
            'python_executable' => $diag['python_executable'] ?? null,
            'venv_valid'        => (bool) ($diag['venv_valid'] ?? false),
            'venv_broken'       => (bool) ($diag['venv_broken'] ?? false),
            'dependencies_ok'   => (bool) ($diag['dependencies_ok'] ?? false),
            'diagnostics'       => $diag,
        ];
    }

    private static function postJson(string $url, array $payload, int $timeout): ?array
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return null;
        }

        $body = self::httpPost($url, $json, $timeout);
        if ($body === null) {
            return null;
        }

        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function httpGet(string $url, int $timeout): ?string
    {
        $timeout = max(1, min($timeout, AI_SERVICE_TIMEOUT_HEALTH));

        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            if ($curl !== false) {
                curl_setopt_array($curl, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => min(2, $timeout),
                    CURLOPT_TIMEOUT        => $timeout,
                ]);
                $body = curl_exec($curl);
                $code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
                curl_close($curl);
                if ($body !== false && $code >= 200 && $code < 300) {
                    return (string) $body;
                }
            }
        }

        return null;
    }

    private static function httpPost(string $url, string $jsonBody, int $timeout): ?string
    {
        if (!AI_SERVICE_ENABLED) {
            return null;
        }

        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            if ($curl !== false) {
                curl_setopt_array($curl, [
                    CURLOPT_POST           => true,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
                    CURLOPT_TIMEOUT        => $timeout,
                    CURLOPT_HTTPHEADER     => [
                        'Content-Type: application/json',
                        'Accept: application/json',
                    ],
                    CURLOPT_POSTFIELDS     => $jsonBody,
                ]);
                $body = curl_exec($curl);
                $code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
                curl_close($curl);
                if ($body !== false && $code >= 200 && $code < 300) {
                    return (string) $body;
                }
            }
        }

        return null;
    }

    private static function extractData(?array $response): ?array
    {
        if (!is_array($response) || empty($response['success']) || !isset($response['data'])) {
            return null;
        }
        return is_array($response['data']) ? $response['data'] : null;
    }
}
