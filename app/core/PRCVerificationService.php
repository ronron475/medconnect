<?php
/**
 * PRC license verification workflow for admin-created doctor accounts.
 *
 * The Professional Regulation Commission does not provide a public API.
 * This service assists administrators with manual verification only.
 * When an official PRC API becomes available, extend verifyViaApi() here.
 */
final class PRCVerificationService
{
    /** Official PRC Verification Portal — manual lookup only. */
    public const PORTAL_URL = 'https://verification.prc.gov.ph/';

    public const STATUS_NOT_VERIFIED = 'NOT_VERIFIED';
    public const STATUS_VERIFIED     = 'VERIFIED';

    /**
     * Future API hook. Returns unavailable until PRC publishes an official API.
     *
     * @param array<string, mixed> $credentials
     * @return array{available: bool, verified: bool, message: string, raw?: mixed}
     */
    public static function verifyViaApi(array $credentials): array
    {
        return [
            'available' => false,
            'verified'  => false,
            'message'   => 'Automated PRC verification is not available. Please use the official PRC Verification Portal.',
        ];
    }

    public static function portalUrl(): string
    {
        return self::PORTAL_URL;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{valid: bool, errors: array<string, string>, normalized: array<string, mixed>}
     */
    public static function validateDoctorCreatePayload(PDO $pdo, array $input, bool $requireManualConfirmation = true): array
    {
        require_once dirname(__DIR__) . '/includes/provider_verification.php';

        $errors = [];
        $normalized = [
            'first_name'          => trim((string) ($input['first_name'] ?? '')),
            'middle_name'         => trim((string) ($input['middle_name'] ?? '')),
            'last_name'           => trim((string) ($input['last_name'] ?? '')),
            'birthdate'           => trim((string) ($input['birthdate'] ?? $input['date_of_birth'] ?? '')),
            'email'               => trim((string) ($input['email'] ?? '')),
            'phone'               => trim((string) ($input['phone'] ?? $input['mobile_number'] ?? '')),
            'password'            => (string) ($input['password'] ?? ''),
            'prc_license_number'  => trim((string) ($input['prc_license_number'] ?? '')),
            'specialization'      => trim((string) ($input['specialization'] ?? $input['specialty'] ?? '')),
            'facility'            => trim((string) ($input['facility'] ?? $input['hospital_clinic'] ?? '')),
            'prc_confirmed'       => !empty($input['prc_verification_confirmed'])
                || (string) ($input['prc_verification_confirmed'] ?? '') === '1',
        ];

        if ($normalized['first_name'] === '') {
            $errors['first_name'] = 'First name is required.';
        }
        if ($normalized['last_name'] === '') {
            $errors['last_name'] = 'Last name is required.';
        }

        if ($err = self::validateBirthdate($normalized['birthdate'])) {
            $errors['birthdate'] = $err;
        } else {
            $normalized['birthdate'] = self::normalizeBirthdate($normalized['birthdate']);
        }

        if ($normalized['specialization'] === '') {
            $errors['specialization'] = 'Specialization is required.';
        }

        $prcErr = provider_verification_validate_prc($normalized['prc_license_number']);
        if ($prcErr) {
            $errors['prc_license_number'] = $prcErr;
        } else {
            $normalized['prc_license_number'] = provider_verification_normalize_prc($normalized['prc_license_number']);
        }

        if (!filter_var($normalized['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'A valid email address is required.';
        } else {
            require_once dirname(__DIR__) . '/includes/contact_validation.php';
            $normalized['email'] = mc_normalize_email($normalized['email']);
        }

        if ($err = self::validateMobileRequired($normalized['phone'])) {
            $errors['phone'] = $err;
        } else {
            $normalized['phone'] = self::normalizeMobile($normalized['phone']);
        }

        if ($err = self::validatePasswordStrength($normalized['password'])) {
            $errors['password'] = $err;
        }

        if ($requireManualConfirmation && !$normalized['prc_confirmed']) {
            $errors['prc_verification'] = 'You must confirm that you have personally verified this doctor\'s PRC license using the official PRC Verification Portal.';
        }

        if ($normalized['email'] !== '' && ($dup = self::assertNoDuplicateEmail($pdo, $normalized['email']))) {
            $errors['email'] = $dup;
        }
        if ($normalized['phone'] !== '' && ($dup = self::assertNoDuplicatePhone($pdo, $normalized['phone']))) {
            $errors['phone'] = $dup;
        }
        if ($normalized['prc_license_number'] !== '' && ($dup = self::assertNoDuplicatePrc($pdo, $normalized['prc_license_number']))) {
            $errors['prc_license_number'] = $dup;
        }

        return [
            'valid'      => $errors === [],
            'errors'     => $errors,
            'normalized' => $normalized,
        ];
    }

    public static function validateBirthdate(string $birthdate): ?string
    {
        $birthdate = trim($birthdate);
        if ($birthdate === '') {
            return 'Birthdate is required for PRC verification.';
        }

        $dt = DateTime::createFromFormat('Y-m-d', $birthdate);
        if (!$dt || $dt->format('Y-m-d') !== $birthdate) {
            return 'Enter a valid birthdate (YYYY-MM-DD).';
        }

        $today = new DateTime('today');
        if ($dt > $today) {
            return 'Birthdate cannot be in the future.';
        }

        $age = $dt->diff($today)->y;
        if ($age < 21 || $age > 100) {
            return 'Birthdate must indicate a physician age between 21 and 100 years.';
        }

        return null;
    }

    public static function normalizeBirthdate(string $birthdate): string
    {
        $dt = DateTime::createFromFormat('Y-m-d', trim($birthdate));

        return $dt ? $dt->format('Y-m-d') : trim($birthdate);
    }

    public static function validateMobileRequired(string $phone): ?string
    {
        require_once dirname(__DIR__) . '/includes/contact_validation.php';
        return mc_phone_validation_error($phone, true);
    }

    public static function normalizeMobile(string $phone): string
    {
        require_once dirname(__DIR__) . '/includes/contact_validation.php';
        return mc_canonical_ph_mobile($phone);
    }

    public static function validatePasswordStrength(string $password): ?string
    {
        if (strlen($password) < 12) {
            return 'Password must be at least 12 characters.';
        }
        if (!preg_match('/[A-Z]/', $password)) {
            return 'Password must include at least one uppercase letter.';
        }
        if (!preg_match('/[a-z]/', $password)) {
            return 'Password must include at least one lowercase letter.';
        }
        if (!preg_match('/[0-9]/', $password)) {
            return 'Password must include at least one number.';
        }
        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            return 'Password must include at least one special character.';
        }

        return null;
    }

    public static function assertNoDuplicateEmail(PDO $pdo, string $email): ?string
    {
        require_once dirname(__DIR__) . '/includes/contact_validation.php';
        return mc_email_duplicate_error($pdo, $email);
    }

    public static function assertNoDuplicatePhone(PDO $pdo, string $phone): ?string
    {
        require_once dirname(__DIR__) . '/includes/contact_validation.php';
        return mc_phone_duplicate_error($pdo, $phone);
    }

    public static function assertNoDuplicatePrc(PDO $pdo, string $prc): ?string
    {
        require_once dirname(__DIR__) . '/includes/provider_verification.php';
        provider_verification_ensure_schema($pdo);

        $prc = provider_verification_normalize_prc($prc);
        $stmt = $pdo->prepare('SELECT user_id FROM provider_profiles WHERE prc_license_number = ? LIMIT 1');
        $stmt->execute([$prc]);

        return $stmt->fetch() ? 'This PRC license number is already registered to another doctor.' : null;
    }

    public static function buildManualVerificationAuditMessage(
        string $adminName,
        string $doctorName,
        string $prcLicense
    ): string {
        return sprintf(
            'Administrator %s manually verified the PRC License of Dr. %s (%s) before creating the account.',
            $adminName,
            $doctorName,
            $prcLicense
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function verificationGuideFields(array $normalized): array
    {
        return [
            'first_name'         => $normalized['first_name'] ?? '',
            'last_name'          => $normalized['last_name'] ?? '',
            'birthdate'          => $normalized['birthdate'] ?? '',
            'prc_license_number' => $normalized['prc_license_number'] ?? '',
        ];
    }
}
