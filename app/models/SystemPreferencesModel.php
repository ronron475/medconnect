<?php
/**
 * System preferences model.
 */
require_once dirname(__DIR__) . '/includes/system_preferences.php';

class SystemPreferencesModel
{
    public static function ensureSchema(PDO $pdo): void
    {
        system_preferences_ensure_schema($pdo);
    }

    public static function getPreferences(PDO $pdo, int $providerId): array
    {
        return system_preferences_get($pdo, $providerId);
    }

    public static function savePreferences(PDO $pdo, int $providerId, array $input): array
    {
        return system_preferences_save($pdo, $providerId, $input);
    }

    public static function applyPreferences(array $prefs): void
    {
        system_preferences_apply_to_session($prefs);
    }

    public static function autoLogoutMinutes(PDO $pdo, int $providerId): int
    {
        $prefs = self::getPreferences($pdo, $providerId);
        return (int) ($prefs['auto_logout_duration'] ?? 30);
    }
}
