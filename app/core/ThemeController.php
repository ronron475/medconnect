<?php
/**
 * Theme preference controller.
 */
require_once __DIR__ . '/../models/ThemeModel.php';

class ThemeController
{
    public static function jsonHeaders(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
    }

    public static function authorize(): array
    {
        return theme_preferences_require_auth();
    }

    public static function verifyCsrf(): void
    {
        theme_preferences_verify_csrf();
    }

    public static function getTheme(PDO $pdo, int $userId, string $userType): array
    {
        ThemeModel::ensureSchema($pdo);
        $prefs = ThemeModel::get($pdo, $userId, $userType);
        return [
            'status' => 'success',
            'success' => true,
            'data' => $prefs,
        ];
    }

    public static function saveTheme(PDO $pdo, int $userId, string $userType, array $input): array
    {
        ThemeModel::ensureSchema($pdo);
        $theme = (string) ($input['theme_preference'] ?? $input['theme'] ?? '');
        if ($theme === '') {
            return [
                'status' => 'error',
                'success' => false,
                'message' => 'Theme preference is required.',
            ];
        }
        return ThemeModel::save($pdo, $userId, $userType, $theme);
    }
}
