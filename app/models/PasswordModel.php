<?php
/**
 * Provider password model.
 */
require_once dirname(__DIR__) . '/includes/provider_password.php';

class PasswordModel
{
    public static function ensureSchema(PDO $pdo): void
    {
        provider_password_ensure_schema($pdo);
    }

    public static function checkLock(PDO $pdo, int $providerId): array
    {
        return provider_password_check_lock($pdo, $providerId);
    }

    public static function validateRules(string $password): array
    {
        return provider_password_validate_rules($password);
    }

    public static function changePassword(
        PDO $pdo,
        int $providerId,
        string $current,
        string $newPassword,
        string $confirm
    ): array {
        return provider_password_change($pdo, $providerId, $current, $newPassword, $confirm);
    }

    public static function logActivity(PDO $pdo, int $providerId, string $action): void
    {
        provider_password_log_activity($pdo, $providerId, $action);
    }
}
