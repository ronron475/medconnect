<?php
/**
 * Theme preference model.
 */
require_once dirname(__DIR__) . '/includes/theme_preferences.php';

class ThemeModel
{
    public static function ensureSchema(PDO $pdo): void
    {
        theme_preferences_ensure_schema($pdo);
    }

    public static function get(PDO $pdo, int $userId, string $userType): array
    {
        return theme_preferences_get($pdo, $userId, $userType);
    }

    public static function save(PDO $pdo, int $userId, string $userType, string $theme): array
    {
        return theme_preferences_save($pdo, $userId, $userType, $theme);
    }

    public static function syncSession(PDO $pdo, int $userId, string $role): void
    {
        theme_preferences_sync_session($pdo, $userId, $role);
    }
}
