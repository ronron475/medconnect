<?php
/**
 * Provider settings controller — validation entry points for API handlers.
 */
require_once dirname(__DIR__, 3) . '/app/models/ProviderSettingsModel.php';

class ProviderSettingsController
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

    public static function load(PDO $pdo, int $userId): array
    {
        return ProviderSettingsModel::load($pdo, $userId);
    }

    public static function saveProfile(PDO $pdo, int $userId, array $input): array
    {
        return ProviderSettingsModel::saveProfile($pdo, $userId, $input);
    }

    public static function changePassword(PDO $pdo, int $userId, array $input): array
    {
        return ProviderSettingsModel::changePassword(
            $pdo,
            $userId,
            (string) ($input['current_password'] ?? ''),
            (string) ($input['new_password'] ?? ''),
            (string) ($input['confirm_password'] ?? '')
        );
    }

    public static function saveNotifications(PDO $pdo, int $userId, array $input): array
    {
        return ProviderSettingsModel::saveNotifications($pdo, $userId, $input);
    }

    public static function saveSystem(PDO $pdo, int $userId, array $input): array
    {
        return ProviderSettingsModel::saveSystem($pdo, $userId, $input);
    }
}
