<?php
/**
 * HTTP client for National ID OCR on the unified FastAPI service (port 8765).
 */

final class OcrFastApiClient
{
    public static function isEnabled(): bool
    {
        return defined('OCR_USE_FASTAPI') && OCR_USE_FASTAPI === true;
    }

    public static function baseUrl(): string
    {
        if (defined('OCR_FASTAPI_URL')) {
            return rtrim((string) OCR_FASTAPI_URL, '/');
        }
        if (defined('AI_SERVICE_BASE_URL')) {
            return rtrim((string) AI_SERVICE_BASE_URL, '/');
        }
        return 'http://127.0.0.1:8765';
    }

    public static function health(int $timeout = 2): ?array
    {
        $url = self::baseUrl() . '/health';
        $body = self::httpGet($url, $timeout);
        if ($body === null) {
            return null;
        }
        $data = json_decode($body, true);
        return is_array($data) ? $data : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function extract(string $filePath, string $mime, string $filename): ?array
    {
        if (!is_readable($filePath)) {
            return null;
        }

        $url = self::baseUrl() . '/ocr/extract';
        $curl = curl_init();
        $post = [
            'national_id_image' => new CURLFile($filePath, $mime, $filename),
        ];

        curl_setopt_array($curl, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $post,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => defined('OCR_FASTAPI_TIMEOUT') ? (int) OCR_FASTAPI_TIMEOUT : 90,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($curl);
        curl_close($curl);

        if ($response === false || $curlErr !== '') {
            return null;
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            return null;
        }

        if ($httpCode === 422 && isset($data['detail'])) {
            $detail = is_array($data['detail']) ? ($data['detail'][0]['msg'] ?? 'OCR failed') : (string) $data['detail'];
            return ['success' => false, 'message' => $detail];
        }

        if ($httpCode >= 400) {
            $msg = $data['detail'] ?? $data['message'] ?? 'OCR service error';
            return ['success' => false, 'message' => is_string($msg) ? $msg : 'OCR service error'];
        }

        return $data;
    }

    private static function httpGet(string $url, int $timeout): ?string
    {
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($curl);
        curl_close($curl);
        return ($response === false) ? null : $response;
    }
}
