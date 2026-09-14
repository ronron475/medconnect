<?php
/**
 * medConnect — shared email + Philippine mobile validation.
 *
 * Canonical mobile storage format: 09XXXXXXXXX (11 digits).
 * Accepted input equivalents: 09XXXXXXXXX, +639XXXXXXXXX, 639XXXXXXXXX, 9XXXXXXXXX.
 *
 * Patient self-registration requires Gmail; other roles accept general valid emails.
 */

declare(strict_types=1);

if (!defined('MC_CONTACT_VALIDATION_LOADED')) {
    define('MC_CONTACT_VALIDATION_LOADED', true);
}

/** User-facing messages (keep consistent across roles). */
const MC_MSG_PHONE_INVALID = 'Please enter a valid Philippine mobile number.';
const MC_MSG_PHONE_REQUIRED = 'Contact number is required.';
const MC_MSG_PHONE_DIGITS = 'Phone number must contain digits only.';
const MC_MSG_PHONE_LENGTH = 'Phone number must be exactly 11 digits (e.g. 09171234567).';
const MC_MSG_PHONE_PREFIX = 'Enter a valid Philippine mobile number starting with 09.';
const MC_MSG_PHONE_DUP = 'This mobile number is already registered.';
const MC_MSG_EMAIL_INVALID = 'Please enter a valid email address.';
const MC_MSG_EMAIL_REQUIRED = 'Email address is required.';
const MC_MSG_GMAIL_INVALID = 'Please enter a valid Gmail address.';
const MC_MSG_EMAIL_DUP = 'This email address is already registered.';

function mc_normalize_phone_digits(string $phone): string
{
    return preg_replace('/\D+/', '', $phone) ?? '';
}

/**
 * Canonical PH mobile for storage and duplicate checks (09XXXXXXXXX).
 */
function mc_canonical_ph_mobile(string $phone): string
{
    $digits = mc_normalize_phone_digits($phone);
    if (preg_match('/^639\d{9}$/', $digits)) {
        return '0' . substr($digits, 2);
    }
    if (preg_match('/^9\d{9}$/', $digits) && strlen($digits) === 10) {
        return '0' . $digits;
    }
    return $digits;
}

function mc_is_valid_ph_mobile(string $phone): bool
{
    return (bool) preg_match('/^09\d{9}$/', mc_canonical_ph_mobile($phone));
}

/**
 * Human-readable PH mobile error, or null when valid.
 * Empty values return a required message when $required is true; otherwise null.
 */
function mc_phone_validation_error(string $phone, bool $required = true): ?string
{
    $raw = trim($phone);
    if ($raw === '') {
        return $required ? MC_MSG_PHONE_REQUIRED : null;
    }

    $digits = mc_normalize_phone_digits($raw);
    if ($digits === '') {
        return MC_MSG_PHONE_DIGITS;
    }

    $canonical = mc_canonical_ph_mobile($raw);
    if (!preg_match('/^\d+$/', $canonical)) {
        return MC_MSG_PHONE_DIGITS;
    }
    if (strlen($canonical) !== 11) {
        return MC_MSG_PHONE_LENGTH;
    }
    if (!preg_match('/^09\d{9}$/', $canonical)) {
        return MC_MSG_PHONE_PREFIX;
    }

    return null;
}

function mc_normalize_email(string $email): string
{
    return strtolower(trim($email));
}

function mc_is_valid_email(string $email): bool
{
    $email = mc_normalize_email($email);
    if ($email === '' || preg_match('/\s/', $email)) {
        return false;
    }
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
}

/** Patient registration Gmail allowlist (matches send_otp / check_email). */
function mc_is_valid_gmail(string $email): bool
{
    $email = mc_normalize_email($email);
    if (!mc_is_valid_email($email)) {
        return false;
    }
    return (bool) preg_match('/^[A-Za-z0-9._%+\-]+@gmail\.com$/i', $email);
}

function mc_email_validation_error(string $email, bool $required = true): ?string
{
    $email = trim($email);
    if ($email === '') {
        return $required ? MC_MSG_EMAIL_REQUIRED : null;
    }
    if (!mc_is_valid_email($email)) {
        return MC_MSG_EMAIL_INVALID;
    }
    return null;
}

function mc_gmail_validation_error(string $email, bool $required = true): ?string
{
    $email = trim($email);
    if ($email === '') {
        return $required ? MC_MSG_EMAIL_REQUIRED : null;
    }
    if (!mc_is_valid_gmail($email)) {
        return MC_MSG_GMAIL_INVALID;
    }
    return null;
}

/**
 * Duplicate email on users table (case-insensitive).
 */
function mc_users_email_exists(PDO $pdo, string $email, ?int $excludeUserId = null): bool
{
    $email = mc_normalize_email($email);
    if ($email === '') {
        return false;
    }
    if ($excludeUserId !== null && $excludeUserId > 0) {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(?) AND id != ? LIMIT 1');
        $stmt->execute([$email, $excludeUserId]);
    } else {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1');
        $stmt->execute([$email]);
    }
    return (bool) $stmt->fetch();
}

/**
 * Duplicate phone on users.phone using canonical last-10 matching when possible.
 */
function mc_users_phone_exists(PDO $pdo, string $phone, ?int $excludeUserId = null): bool
{
    $canonical = mc_canonical_ph_mobile($phone);
    if (!preg_match('/^09\d{9}$/', $canonical)) {
        return false;
    }

    $columns = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('phone', $columns, true)) {
        return false;
    }

    $last10 = substr($canonical, -10);
    $sql = 'SELECT id FROM users
            WHERE REPLACE(REPLACE(REPLACE(IFNULL(phone, ""), " ", ""), "-", ""), "+", "") LIKE ?
               OR phone = ?';
    $params = ['%' . $last10, $canonical];
    if ($excludeUserId !== null && $excludeUserId > 0) {
        $sql .= ' AND id != ?';
        $params[] = $excludeUserId;
    }
    $sql .= ' LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (bool) $stmt->fetch();
}

function mc_email_duplicate_error(PDO $pdo, string $email, ?int $excludeUserId = null): ?string
{
    return mc_users_email_exists($pdo, $email, $excludeUserId) ? MC_MSG_EMAIL_DUP : null;
}

function mc_phone_duplicate_error(PDO $pdo, string $phone, ?int $excludeUserId = null): ?string
{
    return mc_users_phone_exists($pdo, $phone, $excludeUserId) ? MC_MSG_PHONE_DUP : null;
}
