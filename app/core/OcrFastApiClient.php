<?php
/**
 * HTTP client for National ID OCR on the unified FastAPI service (Railway / local).
 *
 * Hostinger PHP calls MEDCONNECT_AI_SERVICE_URL over HTTPS with
 * MEDCONNECT_AI_SERVICE_TOKEN. No secrets are hardcoded here.
 */

final class OcrFastApiClient
{
    public static function isEnabled(): bool
    {
        return defined('OCR_USE_FASTAPI') && OCR_USE_FASTAPI === true;
    }

    /**
     * Railway OCR routes require the shared service token.
     */
    public static function isConfigured(): bool
    {
        require_once dirname(__DIR__) . '/includes/ai_service_auth.php';
        return medconnect_ai_service_token() !== '';
    }

    /**
     * Same TLS policy as the AI service client (AI_SSL_VERIFY plus the project CA bundle).
     *
     * @return array<int, mixed>
     */
    public static function curlSslOptions(): array
    {
        $raw = getenv('AI_SSL_VERIFY');
        if ($raw === false || $raw === '') {
            $raw = $_ENV['AI_SSL_VERIFY'] ?? 'true';
        }
        $verify = !in_array(strtolower(trim((string) $raw)), ['0', 'false', 'no', 'off'], true);
        if (!$verify) {
            return [
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
            ];
        }

        $options = [
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        $ca = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'cacert.pem';
        if (!is_readable($ca)) {
            $ca = (string) (ini_get('curl.cainfo') ?: ini_get('openssl.cafile') ?: '');
        }
        if ($ca !== '' && is_readable($ca)) {
            $options[CURLOPT_CAINFO] = $ca;
        }

        return $options;
    }

    public static function baseUrl(): string
    {
        if (defined('OCR_FASTAPI_URL') && OCR_FASTAPI_URL !== '') {
            return rtrim((string) OCR_FASTAPI_URL, '/');
        }
        if (defined('AI_SERVICE_BASE_URL') && AI_SERVICE_BASE_URL !== '') {
            return rtrim((string) AI_SERVICE_BASE_URL, '/');
        }
        $envUrl = getenv('MEDCONNECT_AI_SERVICE_URL');
        if ($envUrl === false || trim((string) $envUrl) === '') {
            $envUrl = $_ENV['MEDCONNECT_AI_SERVICE_URL'] ?? '';
        }
        $envUrl = trim((string) $envUrl);
        if ($envUrl !== '') {
            return rtrim($envUrl, '/');
        }
        return 'http://127.0.0.1:8765';
    }

    /**
     * Safe multipart filename — never send the original upload name (may contain PII).
     */
    public static function safeUploadFilename(string $mime, string $originalName = ''): string
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'pdf'];
        if (!in_array($ext, $allowed, true)) {
            $ext = match (strtolower($mime)) {
                'image/png' => 'png',
                'application/pdf' => 'pdf',
                default => 'jpg',
            };
        }
        return 'national_id.' . $ext;
    }

    public static function health(int $timeout = 2): ?array
    {
        if (!self::isConfigured()) {
            return null;
        }
        $url = self::baseUrl() . '/ocr/health';
        $body = self::httpGet($url, $timeout);
        if ($body === null) {
            $body = self::httpGet(self::baseUrl() . '/health', $timeout);
            if ($body === null) {
                return null;
            }
        }
        $data = json_decode($body, true);
        return is_array($data) ? $data : null;
    }

    public static function isHealthyResponse(?array $health): bool
    {
        if (!is_array($health)) {
            return false;
        }
        $status = strtolower(trim((string) ($health['status'] ?? '')));
        $legacy = strtolower(trim((string) ($health['legacy_status'] ?? '')));
        $success = !empty($health['success']);

        if ($status === 'ok') {
            return true;
        }
        if ($status === 'online' && ($legacy === 'ok' || $success)) {
            return true;
        }

        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function extract(string $filePath, string $mime, string $filename): ?array
    {
        if (!is_readable($filePath) || !self::isConfigured()) {
            return null;
        }

        $url = self::baseUrl() . '/ocr/extract';
        $curl = curl_init();
        $post = [
            'national_id_image' => new CURLFile(
                $filePath,
                $mime,
                self::safeUploadFilename($mime, $filename)
            ),
        ];

        require_once dirname(__DIR__) . '/includes/ai_service_auth.php';
        curl_setopt_array($curl, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $post,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => defined('OCR_FASTAPI_TIMEOUT') ? (int) OCR_FASTAPI_TIMEOUT : 90,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => medconnect_ai_service_auth_headers(),
        ] + self::curlSslOptions());

        $response = curl_exec($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlErrno = (int) curl_errno($curl);
        curl_close($curl);

        if ($response === false || $curlErrno !== 0) {
            // Never log filenames, tokens, or response bodies.
            error_log('OCR FastAPI request failed: connection/timeout (errno ' . $curlErrno . ')');
            return null;
        }

        if ($httpCode === 0) {
            error_log('OCR FastAPI request failed: empty HTTP status');
            return null;
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            error_log('OCR FastAPI request failed: invalid JSON (HTTP ' . $httpCode . ')');
            return null;
        }

        if ($httpCode === 422 && isset($data['detail'])) {
            $detail = is_array($data['detail']) ? ($data['detail'][0]['msg'] ?? 'OCR failed') : (string) $data['detail'];
            return ['success' => false, 'message' => $detail];
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            error_log('OCR FastAPI request failed: HTTP ' . $httpCode);
            return ['success' => false, 'message' => 'OCR service error'];
        }

        return $data;
    }

    private static function httpGet(string $url, int $timeout): ?string
    {
        $curl = curl_init();
        require_once dirname(__DIR__) . '/includes/ai_service_auth.php';
        $headers = function_exists('medconnect_ai_service_auth_headers')
            ? medconnect_ai_service_auth_headers()
            : [];

        curl_setopt_array($curl, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_HTTPHEADER     => $headers,
        ] + self::curlSslOptions());
        $response = curl_exec($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlErrno = (int) curl_errno($curl);
        curl_close($curl);
        if ($response === false || $curlErrno !== 0 || $httpCode < 200 || $httpCode >= 300) {
            error_log(
                'OCR FastAPI health failed: '
                . ($curlErrno !== 0 ? ('connection/timeout errno ' . $curlErrno) : ('HTTP ' . $httpCode))
            );
            return null;
        }

        return $response;
    }
}
