<?php
/**
 * Shared JSON API helpers for app/api endpoints.
 */

final class Api
{
    public static function wantsJson(): bool
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        if (str_contains($uri, '/app/api/')) {
            return true;
        }
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
        ) {
            return true;
        }
        $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
        return stripos($accept, 'application/json') !== false;
    }

    public static function startJson(): void
    {
        if (ob_get_level() === 0) {
            ob_start();
        }
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
    }

    public static function json(int $status, array $payload): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function success(array $data = [], string $message = 'OK', int $status = 200): void
    {
        self::json($status, array_merge(['success' => true, 'message' => $message], $data));
    }

    public static function error(string $message, int $status = 400, array $data = []): void
    {
        self::json($status, array_merge(['success' => false, 'message' => $message], $data));
    }

    public static function requirePost(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            self::error('Method not allowed.', 405);
        }
    }

    public static function requireRole(string $role): void
    {
        self::requireAnyRole([$role]);
    }

    /** @param string[] $roles */
    public static function requireAnyRole(array $roles): void
    {
        self::requireAuth();
        if (!in_array($_SESSION['user_role'] ?? '', $roles, true)) {
            self::error('Forbidden.', 403);
        }
    }

    public static function requireAuth(): void
    {
        if (empty($_SESSION['user_id'])) {
            self::error('Unauthorized.', 401);
        }
    }

    public static function requireCsrf(): void
    {
        require_once dirname(__DIR__) . '/includes/auth_guard.php';
        auth_csrf_require();
    }

    public static function requirePatientReady(PDO $pdo): void
    {
        self::requireRole('patient');
        require_once dirname(__DIR__) . '/includes/patient_account_security.php';
        if (patient_requires_account_setup($pdo, (int) $_SESSION['user_id'])) {
            self::error('Please complete account setup before continuing.', 403, [
                'code' => 'account_setup_required',
                'redirect' => (defined('ASSET_BASE') ? ASSET_BASE : '') . '/views/patient/account_setup.php',
            ]);
        }
    }
}
