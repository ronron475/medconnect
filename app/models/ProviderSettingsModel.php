<?php
/**
 * Provider settings data model (delegates to shared include).
 */
require_once dirname(__DIR__) . '/includes/provider_settings.php';

class ProviderSettingsModel
{
    public static function ensureSchema(PDO $pdo): void
    {
        provider_settings_ensure_schema($pdo);
    }

    public static function load(PDO $pdo, int $userId): array
    {
        return provider_settings_load($pdo, $userId);
    }

    public static function saveProfile(PDO $pdo, int $userId, array $input): array
    {
        return provider_settings_save_profile($pdo, $userId, $input);
    }

    public static function changePassword(PDO $pdo, int $userId, string $current, string $new, string $confirm): array
    {
        return provider_settings_change_password($pdo, $userId, $current, $new, $confirm);
    }

    public static function saveNotifications(PDO $pdo, int $userId, array $input): array
    {
        return provider_settings_save_notifications($pdo, $userId, $input);
    }

    public static function saveSystem(PDO $pdo, int $userId, array $input): array
    {
        return provider_settings_save_system($pdo, $userId, $input);
    }
}
