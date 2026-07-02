<?php
/**
 * System preferences controller.
 */
require_once dirname(__DIR__, 3) . '/app/models/SystemPreferencesModel.php';
require_once dirname(__DIR__, 3) . '/app/includes/provider_settings.php';

class SystemPreferencesController
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

    public static function getPreferences(PDO $pdo, int $providerId): array
    {
        return SystemPreferencesModel::getPreferences($pdo, $providerId);
    }

    public static function savePreferences(PDO $pdo, int $providerId, array $input): array
    {
        return SystemPreferencesModel::savePreferences($pdo, $providerId, $input);
    }

    public static function applyPreferences(array $prefs): void
    {
        SystemPreferencesModel::applyPreferences($prefs);
    }

    public static function autoLogout(PDO $pdo, int $providerId): int
    {
        return SystemPreferencesModel::autoLogoutMinutes($pdo, $providerId);
    }
}
