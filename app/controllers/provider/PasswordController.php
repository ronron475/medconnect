<?php
/**
 * Provider password change controller.
 */
require_once dirname(__DIR__, 3) . '/app/models/PasswordModel.php';
require_once dirname(__DIR__, 3) . '/app/includes/provider_settings.php';

class PasswordController
{
    public static function jsonHeaders(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
    }

    public static function authorize(): int
    {
        return provider_settings_require_provider();
    }

    public static function verifyCsrf(): void
    {
        provider_settings_verify_csrf();
    }

    public static function sanitizeInput(array $input): array
    {
        return [
            'current_password' => (string) ($input['current_password'] ?? ''),
            'new_password' => (string) ($input['new_password'] ?? ''),
            'confirm_password' => (string) ($input['confirm_password'] ?? ''),
        ];
    }

    public static function changePassword(PDO $pdo, int $providerId, array $input): array
    {
        PasswordModel::ensureSchema($pdo);

        $lock = PasswordModel::checkLock($pdo, $providerId);
        if ($lock['locked']) {
            return [
                'status' => 'error',
                'success' => false,
                'message' => $lock['message'],
            ];
        }

        $data = self::sanitizeInput($input);

        if ($data['current_password'] === '' || $data['new_password'] === '' || $data['confirm_password'] === '') {
            return [
                'status' => 'error',
                'success' => false,
                'message' => 'All password fields are required.',
            ];
        }

        return PasswordModel::changePassword(
            $pdo,
            $providerId,
            $data['current_password'],
            $data['new_password'],
            $data['confirm_password']
        );
    }

    public static function validateStrength(string $password): array
    {
        return PasswordModel::validateRules($password);
    }
}
