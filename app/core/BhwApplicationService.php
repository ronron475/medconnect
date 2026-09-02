<?php
/**
 * Barangay Health Worker applications — invite / self-onboarding / Maker-Checker.
 *
 * Admin: creates assignment + sends invite (no password, no personal ID uploads).
 * BHW: activates account, sets password, completes profile, uploads personal docs.
 * Superadmin: reviews, approves, rejects, or requests corrections.
 */
final class BhwApplicationService
{
    public const STATUS_DRAFT              = 'draft';
    public const STATUS_INVITED            = 'invited';
    public const STATUS_ONBOARDING         = 'onboarding';
    public const STATUS_PENDING            = 'pending_approval';
    public const STATUS_APPROVED           = 'approved';
    public const STATUS_ACTIVE             = 'active';
    public const STATUS_REJECTED           = 'rejected';
    public const STATUS_REQUIRES_DOCUMENTS = 'requires_documents';

    public const INVITE_TTL_HOURS = 72;
    public const ONBOARDING_TTL_DAYS = 14;

    /** @var list<string> */
    public const REQUIRED_CHECKLIST = [
        'identity_verified',
        'barangay_assignment_confirmed',
        'appointment_letter_verified',
        'government_id_verified',
        'no_duplicate_record',
    ];

    /** @var list<string> */
    public const OPTIONAL_CHECKLIST = [
        'cho_endorsement_verified',
    ];

    /** Admin may upload institutional docs only. */
    public const ADMIN_DOC_TYPES = [
        'appointment_letter',
        'cho_endorsement',
        'other',
    ];

    /** BHW personal / supporting docs. */
    public const BHW_DOC_TYPES = [
        'government_id',
        'other',
    ];

    /** Required before Superadmin can approve (must exist on application). */
    public const REQUIRED_DOC_TYPES = [
        'appointment_letter',
        'government_id',
    ];

    public function __construct(private PDO $pdo)
    {
        require_once dirname(__DIR__) . '/includes/bhw_application_schema.php';
        bhw_application_ensure_schema($this->pdo);
    }

    /**
     * Admin invite / draft fields only — no password.
     *
     * @param array<string, mixed> $input
     * @return array{valid: bool, errors: array<string, string>, normalized: array<string, mixed>}
     */
    public function validateInviteInput(array $input, bool $forInvite = false): array
    {
        $errors = [];
        $normalized = [
            'first_name'       => trim((string) ($input['first_name'] ?? '')),
            'middle_name'      => trim((string) ($input['middle_name'] ?? '')),
            'last_name'        => trim((string) ($input['last_name'] ?? '')),
            'email'            => trim((string) ($input['email'] ?? '')),
            'phone'            => trim((string) ($input['phone'] ?? '')),
            'barangay_id'      => (int) ($input['barangay_id'] ?? 0),
            'appointment_date' => trim((string) ($input['appointment_date'] ?? '')),
        ];

        if ($normalized['first_name'] === '') {
            $errors['first_name'] = 'First name is required.';
        }
        if ($normalized['last_name'] === '') {
            $errors['last_name'] = 'Last name is required.';
        }
        if (!filter_var($normalized['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'A valid email address is required.';
        }
        if ($normalized['phone'] === '') {
            $errors['phone'] = 'Mobile number is required.';
        } elseif (!preg_match('/^09\d{9}$/', preg_replace('/\D+/', '', $normalized['phone']))) {
            $errors['phone'] = 'Enter a valid Philippine mobile number (e.g. 09171234567).';
        } else {
            $digits = preg_replace('/\D+/', '', $normalized['phone']);
            if (str_starts_with($digits, '639')) {
                $digits = '0' . substr($digits, 2);
            }
            $normalized['phone'] = $digits;
        }
        if ($normalized['barangay_id'] <= 0) {
            $errors['barangay_id'] = 'Assigned barangay is required.';
        }
        if ($normalized['appointment_date'] === '') {
            $errors['appointment_date'] = 'Appointment date is required.';
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $normalized['appointment_date'])) {
            $errors['appointment_date'] = 'Enter a valid appointment date.';
        }

        if ($forInvite) {
            if ($dup = $this->assertNoDuplicateEmail($normalized['email'])) {
                $errors['email'] = $dup;
            }
            if ($dup = $this->assertNoDuplicatePhone($normalized['phone'])) {
                $errors['phone'] = $dup;
            }
        }

        return ['valid' => $errors === [], 'errors' => $errors, 'normalized' => $normalized];
    }

    /**
     * BHW onboarding personal fields.
     *
     * @param array<string, mixed> $input
     * @return array{valid: bool, errors: array<string, string>, normalized: array<string, mixed>}
     */
    public function validateOnboardingInput(array $input): array
    {
        $errors = [];
        $normalized = [
            'first_name'  => trim((string) ($input['first_name'] ?? '')),
            'middle_name' => trim((string) ($input['middle_name'] ?? '')),
            'last_name'   => trim((string) ($input['last_name'] ?? '')),
            'phone'       => trim((string) ($input['phone'] ?? '')),
        ];

        if ($normalized['first_name'] === '') {
            $errors['first_name'] = 'First name is required.';
        }
        if ($normalized['last_name'] === '') {
            $errors['last_name'] = 'Last name is required.';
        }
        if ($normalized['phone'] === '') {
            $errors['phone'] = 'Mobile number is required.';
        } elseif (!preg_match('/^09\d{9}$/', preg_replace('/\D+/', '', $normalized['phone']))) {
            $errors['phone'] = 'Enter a valid Philippine mobile number (e.g. 09171234567).';
        } else {
            $digits = preg_replace('/\D+/', '', $normalized['phone']);
            if (str_starts_with($digits, '639')) {
                $digits = '0' . substr($digits, 2);
            }
            $normalized['phone'] = $digits;
        }

        return ['valid' => $errors === [], 'errors' => $errors, 'normalized' => $normalized];
    }

    /** @deprecated Use validateInviteInput */
    public function validateApplicationInput(array $input, bool $forSubmit = false): array
    {
        return $this->validateInviteInput($input, $forSubmit);
    }

    public function validatePasswordStrength(string $password): ?string
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

    /**
     * @param array<string, mixed> $data
     */
    public function saveDraft(int $adminId, array $data, ?int $applicationId = null): array
    {
        $validation = $this->validateInviteInput($data, false);
        if (!$validation['valid']) {
            return ['success' => false, 'message' => reset($validation['errors']), 'errors' => $validation['errors']];
        }

        $n = $validation['normalized'];
        if ($applicationId) {
            $app = $this->getApplication($applicationId);
            if (!$app) {
                return ['success' => false, 'message' => 'Application not found.'];
            }
            if (!$this->canAdminEdit($adminId, $app)) {
                return ['success' => false, 'message' => 'This application cannot be edited in its current status.'];
            }
        }

        if ($applicationId) {
            $sql = "
                UPDATE bhw_applications SET
                    first_name = ?, middle_name = ?, last_name = ?, email = ?, phone = ?,
                    barangay_id = ?, appointment_date = ?,
                    status = CASE WHEN status = 'rejected' THEN 'draft' ELSE status END,
                    updated_at = NOW()
                WHERE id = ?
            ";
            $this->pdo->prepare($sql)->execute([
                $n['first_name'], $n['middle_name'] ?: null, $n['last_name'], $n['email'], $n['phone'],
                $n['barangay_id'], $n['appointment_date'], $applicationId,
            ]);
            $id = $applicationId;
        } else {
            $stmt = $this->pdo->prepare("
                INSERT INTO bhw_applications
                    (status, first_name, middle_name, last_name, email, phone,
                     barangay_id, appointment_date, created_by, created_at, updated_at)
                VALUES ('draft', ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $stmt->execute([
                $n['first_name'], $n['middle_name'] ?: null, $n['last_name'], $n['email'], $n['phone'],
                $n['barangay_id'], $n['appointment_date'], $adminId,
            ]);
            $id = (int) $this->pdo->lastInsertId();
        }

        $this->audit($adminId, 'bhw_application_draft_saved', "Administrator saved BHW invite draft for {$n['first_name']} {$n['last_name']}.", [
            'application_id' => $id,
        ]);

        return ['success' => true, 'message' => 'Draft saved.', 'application_id' => $id];
    }

    /**
     * Admin sends (or re-sends) invite email. Replaces legacy submit-for-approval.
     */
    public function sendInvite(int $adminId, int $applicationId): array
    {
        $app = $this->getApplication($applicationId);
        if (!$app) {
            return ['success' => false, 'message' => 'Application not found.'];
        }
        if (!$this->canAdminInvite($adminId, $app)) {
            return ['success' => false, 'message' => 'This application cannot be invited in its current status.'];
        }

        $validation = $this->validateInviteInput([
            'first_name'       => $app['first_name'],
            'middle_name'      => $app['middle_name'],
            'last_name'        => $app['last_name'],
            'email'            => $app['email'],
            'phone'            => $app['phone'],
            'barangay_id'      => $app['barangay_id'],
            'appointment_date' => $app['appointment_date'],
        ], true);

        if (!$validation['valid']) {
            return ['success' => false, 'message' => reset($validation['errors']), 'errors' => $validation['errors']];
        }

        if ($docErr = $this->validateAdminInstitutionalDocs($applicationId)) {
            return ['success' => false, 'message' => $docErr];
        }

        $token = bin2hex(random_bytes(32));
        $expires = (new DateTimeImmutable('+' . self::INVITE_TTL_HOURS . ' hours'))->format('Y-m-d H:i:s');

        $this->pdo->prepare("
            UPDATE bhw_applications SET
                status = 'invited',
                invite_token = ?,
                invite_expires_at = ?,
                invited_at = NOW(),
                submitted_by = ?,
                submitted_at = NOW(),
                password_hash = NULL,
                rejection_reason = NULL,
                additional_docs_note = NULL,
                activated_at = NULL,
                bhw_submitted_at = NULL,
                updated_at = NOW()
            WHERE id = ?
        ")->execute([$token, $expires, $adminId, $applicationId]);

        $name = $this->displayName($app);
        $mailResult = $this->sendInviteEmail((string) $app['email'], $name, $token, (string) ($app['barangay_name'] ?? ''));

        $this->audit($adminId, 'bhw_invite_sent', "Administrator invited BHW {$name} to activate their account.", [
            'application_id' => $applicationId,
            'email_sent'     => !empty($mailResult['success']),
        ]);

        $msg = !empty($mailResult['success'])
            ? 'Invitation sent. The BHW must activate their account and complete onboarding.'
            : 'Invitation created, but the email could not be sent. Use Resend Invite or share the activation link manually.';

        return [
            'success'        => true,
            'message'        => $msg,
            'application_id' => $applicationId,
            'email_sent'     => !empty($mailResult['success']),
            'activate_url'   => $this->activateUrl($token),
        ];
    }

    /** Alias kept for older clients — maps to sendInvite. */
    public function submit(int $adminId, int $applicationId): array
    {
        return $this->sendInvite($adminId, $applicationId);
    }

    public function resendInvite(int $adminId, int $applicationId): array
    {
        $app = $this->getApplication($applicationId);
        if (!$app) {
            return ['success' => false, 'message' => 'Application not found.'];
        }

        $status = (string) ($app['status'] ?? '');
        $owner = (int) ($app['created_by'] ?? 0) === $adminId || (int) ($app['submitted_by'] ?? 0) === $adminId;
        if (!$owner || !in_array($status, [self::STATUS_INVITED, self::STATUS_ONBOARDING], true)) {
            return ['success' => false, 'message' => 'Invite can only be resent for invited or in-progress onboarding applications.'];
        }

        $token = bin2hex(random_bytes(32));
        $expires = (new DateTimeImmutable('+' . self::INVITE_TTL_HOURS . ' hours'))->format('Y-m-d H:i:s');
        $newStatus = $status === self::STATUS_ONBOARDING ? self::STATUS_ONBOARDING : self::STATUS_INVITED;

        $this->pdo->prepare("
            UPDATE bhw_applications SET
                status = ?,
                invite_token = ?,
                invite_expires_at = ?,
                invited_at = COALESCE(invited_at, NOW()),
                updated_at = NOW()
            WHERE id = ?
        ")->execute([$newStatus, $token, $expires, $applicationId]);

        $name = $this->displayName($app);
        $mailResult = $this->sendInviteEmail((string) $app['email'], $name, $token, (string) ($app['barangay_name'] ?? ''), $status === self::STATUS_ONBOARDING);

        $this->audit($adminId, 'bhw_invite_resent', "Administrator resent BHW invite for {$name}.", [
            'application_id' => $applicationId,
        ]);

        return [
            'success'      => true,
            'message'      => !empty($mailResult['success']) ? 'Invitation resent.' : 'New link created, but email failed to send.',
            'email_sent'   => !empty($mailResult['success']),
            'activate_url' => $this->activateUrl($token),
        ];
    }

    public function findByInviteToken(string $token): ?array
    {
        $token = trim($token);
        if ($token === '' || strlen($token) < 32) {
            return null;
        }
        $stmt = $this->pdo->prepare("
            SELECT a.*, b.name AS barangay_name
            FROM bhw_applications a
            LEFT JOIN barangays b ON b.id = a.barangay_id
            WHERE a.invite_token = ?
              AND a.status IN ('invited', 'onboarding', 'requires_documents')
              AND (a.invite_expires_at IS NULL OR a.invite_expires_at > NOW())
            LIMIT 1
        ");
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) {
            return null;
        }
        $row['documents'] = $this->getDocuments((int) $row['id']);
        $row['display_name'] = $this->displayName($row);
        $row['status_label'] = $this->statusLabel((string) $row['status']);

        return $row;
    }

    public function activateInvite(string $token, string $password, string $passwordConfirm = ''): array
    {
        $app = $this->findByInviteToken($token);
        if (!$app || (string) $app['status'] !== self::STATUS_INVITED) {
            return ['success' => false, 'message' => 'This activation link is invalid or has expired.'];
        }

        if ($passwordConfirm !== '' && $password !== $passwordConfirm) {
            return ['success' => false, 'message' => 'Passwords do not match.', 'errors' => ['password' => 'Passwords do not match.']];
        }
        if ($err = $this->validatePasswordStrength($password)) {
            return ['success' => false, 'message' => $err, 'errors' => ['password' => $err]];
        }

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $expires = (new DateTimeImmutable('+' . self::ONBOARDING_TTL_DAYS . ' days'))->format('Y-m-d H:i:s');

        $this->pdo->prepare("
            UPDATE bhw_applications SET
                password_hash = ?,
                status = 'onboarding',
                activated_at = NOW(),
                invite_expires_at = ?,
                updated_at = NOW()
            WHERE id = ?
        ")->execute([$hash, $expires, (int) $app['id']]);

        $this->audit((int) ($app['created_by'] ?? 0), 'bhw_activated', 'BHW activated invite and set password for ' . $this->displayName($app) . '.', [
            'application_id' => (int) $app['id'],
        ]);

        return [
            'success'        => true,
            'message'        => 'Password saved. Complete your profile and upload required documents.',
            'application_id' => (int) $app['id'],
            'token'          => $token,
            'onboarding_url' => $this->onboardingUrl($token),
        ];
    }

    public function saveOnboarding(string $token, array $data): array
    {
        $app = $this->findByInviteToken($token);
        if (!$app || !$this->canBhwEdit($app)) {
            return ['success' => false, 'message' => 'Onboarding is not available for this application.'];
        }

        $validation = $this->validateOnboardingInput($data);
        if (!$validation['valid']) {
            return ['success' => false, 'message' => reset($validation['errors']), 'errors' => $validation['errors']];
        }
        $n = $validation['normalized'];

        if ($dup = $this->assertNoDuplicatePhone($n['phone'], (int) $app['id'])) {
            return ['success' => false, 'message' => $dup, 'errors' => ['phone' => $dup]];
        }

        $this->pdo->prepare("
            UPDATE bhw_applications SET
                first_name = ?, middle_name = ?, last_name = ?, phone = ?,
                updated_at = NOW()
            WHERE id = ?
        ")->execute([
            $n['first_name'], $n['middle_name'] ?: null, $n['last_name'], $n['phone'], (int) $app['id'],
        ]);

        $this->audit(0, 'bhw_onboarding_saved', 'BHW updated onboarding profile for application #' . (int) $app['id'] . '.', [
            'application_id' => (int) $app['id'],
        ]);

        return ['success' => true, 'message' => 'Profile saved.', 'application_id' => (int) $app['id']];
    }

    public function submitForApproval(string $token): array
    {
        $app = $this->findByInviteToken($token);
        if (!$app || !$this->canBhwEdit($app)) {
            return ['success' => false, 'message' => 'You cannot submit this application right now.'];
        }

        if (empty($app['password_hash'])) {
            return ['success' => false, 'message' => 'Set your password before submitting.'];
        }

        $validation = $this->validateOnboardingInput([
            'first_name'  => $app['first_name'],
            'middle_name' => $app['middle_name'],
            'last_name'   => $app['last_name'],
            'phone'       => $app['phone'],
        ]);
        if (!$validation['valid']) {
            return ['success' => false, 'message' => reset($validation['errors']), 'errors' => $validation['errors']];
        }

        if ($docErr = $this->validateRequiredDocuments((int) $app['id'])) {
            return ['success' => false, 'message' => $docErr];
        }
        if ($dup = $this->assertNoDuplicateEmail((string) $app['email'], (int) $app['id'])) {
            return ['success' => false, 'message' => $dup];
        }
        if ($dup = $this->assertNoDuplicatePhone((string) $app['phone'], (int) $app['id'])) {
            return ['success' => false, 'message' => $dup];
        }

        $makerId = (int) ($app['submitted_by'] ?? $app['created_by']);

        $this->pdo->prepare("
            UPDATE bhw_applications SET
                status = 'pending_approval',
                bhw_submitted_at = NOW(),
                submitted_at = NOW(),
                invite_token = NULL,
                invite_expires_at = NULL,
                rejection_reason = NULL,
                additional_docs_note = NULL,
                updated_at = NOW()
            WHERE id = ?
        ")->execute([(int) $app['id']]);

        $name = $this->displayName($app);
        $this->audit(0, 'bhw_submitted_by_applicant', "BHW {$name} submitted their application for Super Administrator review.", [
            'application_id' => (int) $app['id'],
            'submitted_by'   => $makerId,
        ]);

        require_once dirname(__DIR__) . '/includes/notification_events.php';
        NotificationEvents::bhwApplicationSubmitted($this->pdo, (int) $app['id'], $name, $makerId);

        return ['success' => true, 'message' => 'Application submitted for Super Administrator approval.'];
    }

    /**
     * @param array<string, bool> $checklist
     */
    public function approve(int $superAdminId, int $applicationId, array $checklist): array
    {
        if (!$this->isSuperAdmin($superAdminId)) {
            return ['success' => false, 'message' => 'Only Super Administrators can approve BHW applications.'];
        }

        $app = $this->getApplicationForAuth($applicationId);
        if (!$app || $app['status'] !== self::STATUS_PENDING) {
            return ['success' => false, 'message' => 'Application is not pending approval.'];
        }

        if ((int) ($app['submitted_by'] ?? 0) === $superAdminId) {
            return ['success' => false, 'message' => 'You cannot approve an application you invited. Maker-Checker separation is required.'];
        }

        if (empty($app['password_hash'])) {
            return ['success' => false, 'message' => 'BHW has not set a password yet.'];
        }

        $checkErr = $this->validateApprovalChecklist($checklist);
        if ($checkErr) {
            return ['success' => false, 'message' => $checkErr];
        }

        if ($docErr = $this->validateRequiredDocuments($applicationId)) {
            return ['success' => false, 'message' => $docErr];
        }

        if ($dup = $this->assertNoDuplicateEmail($app['email'], $applicationId)) {
            return ['success' => false, 'message' => $dup];
        }
        if ($dup = $this->assertNoDuplicatePhone((string) $app['phone'], $applicationId)) {
            return ['success' => false, 'message' => $dup];
        }

        $this->pdo->beginTransaction();
        try {
            $userId = $this->createBhwUser($app);
            $now = date('Y-m-d H:i:s');

            $this->pdo->prepare("
                UPDATE bhw_applications SET
                    user_id = ?,
                    status = 'active',
                    reviewed_by = ?,
                    reviewed_at = ?,
                    approved_by = ?,
                    approved_at = ?,
                    checklist_json = ?,
                    invite_token = NULL,
                    invite_expires_at = NULL,
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([
                $userId,
                $superAdminId,
                $now,
                $superAdminId,
                $now,
                json_encode($checklist, JSON_UNESCAPED_UNICODE),
                $applicationId,
            ]);

            $this->pdo->commit();

            $makerId = (int) ($app['submitted_by'] ?? $app['created_by']);
            $name = $this->displayName($app);
            $checker = $this->userDisplayName($superAdminId);

            $this->audit($superAdminId, 'bhw_application_approved', "Super Administrator {$checker} approved the BHW application for {$name} after verifying all required documents.", [
                'application_id' => $applicationId,
                'user_id'        => $userId,
                'approved_by'    => $superAdminId,
                'submitted_by'   => $makerId,
                'checklist'      => $checklist,
            ]);

            require_once dirname(__DIR__) . '/includes/notification_events.php';
            NotificationEvents::bhwApplicationApproved($this->pdo, $applicationId, $userId, $name, $makerId, $superAdminId);

            return ['success' => true, 'message' => 'BHW account approved and activated.', 'user_id' => $userId];
        } catch (Throwable $e) {
            $this->pdo->rollBack();

            return ['success' => false, 'message' => 'Approval failed: ' . $e->getMessage()];
        }
    }

    public function reject(int $superAdminId, int $applicationId, string $reason): array
    {
        if (!$this->isSuperAdmin($superAdminId)) {
            return ['success' => false, 'message' => 'Only Super Administrators can reject BHW applications.'];
        }

        $reason = trim($reason);
        if ($reason === '') {
            return ['success' => false, 'message' => 'Rejection reason is required.'];
        }

        $app = $this->getApplication($applicationId);
        if (!$app || $app['status'] !== self::STATUS_PENDING) {
            return ['success' => false, 'message' => 'Application is not pending approval.'];
        }

        if ((int) ($app['submitted_by'] ?? 0) === $superAdminId) {
            return ['success' => false, 'message' => 'You cannot reject an application you invited.'];
        }

        $now = date('Y-m-d H:i:s');
        $this->pdo->prepare("
            UPDATE bhw_applications SET
                status = 'rejected',
                reviewed_by = ?,
                reviewed_at = ?,
                rejected_by = ?,
                rejected_at = ?,
                rejection_reason = ?,
                invite_token = NULL,
                invite_expires_at = NULL,
                updated_at = NOW()
            WHERE id = ?
        ")->execute([$superAdminId, $now, $superAdminId, $now, $reason, $applicationId]);

        $makerId = (int) ($app['submitted_by'] ?? $app['created_by']);
        $name = $this->displayName($app);
        $checker = $this->userDisplayName($superAdminId);

        $this->audit($superAdminId, 'bhw_application_rejected', "Super Administrator {$checker} rejected the BHW application for {$name}. Reason: {$reason}", [
            'application_id'   => $applicationId,
            'rejected_by'      => $superAdminId,
            'rejection_reason' => $reason,
        ]);

        require_once dirname(__DIR__) . '/includes/notification_events.php';
        NotificationEvents::bhwApplicationRejected($this->pdo, $applicationId, $name, $makerId, $superAdminId, $reason);

        return ['success' => true, 'message' => 'Application rejected.'];
    }

    public function requestAdditionalDocuments(int $superAdminId, int $applicationId, string $note): array
    {
        if (!$this->isSuperAdmin($superAdminId)) {
            return ['success' => false, 'message' => 'Only Super Administrators can request additional documents.'];
        }

        $note = trim($note);
        if ($note === '') {
            return ['success' => false, 'message' => 'Please specify what additional documents are required.'];
        }

        $app = $this->getApplication($applicationId);
        if (!$app || $app['status'] !== self::STATUS_PENDING) {
            return ['success' => false, 'message' => 'Application is not pending approval.'];
        }

        $token = bin2hex(random_bytes(32));
        $expires = (new DateTimeImmutable('+' . self::ONBOARDING_TTL_DAYS . ' days'))->format('Y-m-d H:i:s');

        $this->pdo->prepare("
            UPDATE bhw_applications SET
                status = 'requires_documents',
                reviewed_by = ?,
                reviewed_at = NOW(),
                additional_docs_note = ?,
                invite_token = ?,
                invite_expires_at = ?,
                updated_at = NOW()
            WHERE id = ?
        ")->execute([$superAdminId, $note, $token, $expires, $applicationId]);

        $makerId = (int) ($app['submitted_by'] ?? $app['created_by']);
        $name = $this->displayName($app);
        $bhwUserId = (int) ($app['user_id'] ?? 0);

        $this->audit($superAdminId, 'bhw_application_docs_requested', "Additional documents requested for BHW application ({$name}).", [
            'application_id' => $applicationId,
            'note'           => $note,
        ]);

        $this->sendCorrectionEmail((string) $app['email'], $name, $token, $note);

        require_once dirname(__DIR__) . '/includes/notification_events.php';
        NotificationEvents::bhwApplicationDocsRequested(
            $this->pdo,
            $applicationId,
            $name,
            $makerId,
            $superAdminId,
            $note,
            $bhwUserId > 0 ? $bhwUserId : null
        );

        return ['success' => true, 'message' => 'BHW notified to provide additional documents.', 'onboarding_url' => $this->onboardingUrl($token)];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getApplication(int $id): ?array
    {
        $row = $this->getApplicationForAuth($id);
        if (!$row) {
            return null;
        }
        unset($row['invite_token'], $row['password_hash']);

        return $row;
    }

    /**
     * Internal fetch including password_hash (never expose via API).
     *
     * @return array<string, mixed>|null
     */
    private function getApplicationForAuth(int $id): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT a.*,
                   b.name AS barangay_name,
                   CONCAT(m.first_name, ' ', m.last_name) AS submitted_by_name,
                   CONCAT(c.first_name, ' ', c.last_name) AS created_by_name
            FROM bhw_applications a
            LEFT JOIN barangays b ON b.id = a.barangay_id
            LEFT JOIN users m ON m.id = a.submitted_by
            LEFT JOIN users c ON c.id = a.created_by
            WHERE a.id = ?
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $row['documents'] = $this->getDocuments($id);
        $row['display_name'] = $this->displayName($row);
        $row['status_label'] = $this->statusLabel((string) $row['status']);

        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForAdmin(int $adminId, bool $isSuperAdmin = false): array
    {
        if ($isSuperAdmin) {
            $stmt = $this->pdo->query("
                SELECT a.id, a.status, a.first_name, a.middle_name, a.last_name, a.email,
                       a.phone, a.barangay_id, a.appointment_date, a.submitted_at, a.invited_at,
                       a.bhw_submitted_at, a.created_by, a.submitted_by,
                       b.name AS barangay_name,
                       (SELECT COUNT(*) FROM bhw_application_documents d WHERE d.application_id = a.id) AS document_count
                FROM bhw_applications a
                LEFT JOIN barangays b ON b.id = a.barangay_id
                ORDER BY FIELD(a.status, 'pending_approval', 'requires_documents', 'onboarding', 'invited', 'draft', 'rejected', 'active'),
                         COALESCE(a.bhw_submitted_at, a.submitted_at, a.updated_at) DESC
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } else {
            $stmt = $this->pdo->prepare("
                SELECT a.id, a.status, a.first_name, a.middle_name, a.last_name, a.email,
                       a.phone, a.barangay_id, a.appointment_date, a.submitted_at, a.invited_at,
                       a.bhw_submitted_at, a.created_by, a.submitted_by,
                       b.name AS barangay_name,
                       (SELECT COUNT(*) FROM bhw_application_documents d WHERE d.application_id = a.id) AS document_count
                FROM bhw_applications a
                LEFT JOIN barangays b ON b.id = a.barangay_id
                WHERE a.created_by = ? OR a.submitted_by = ?
                ORDER BY a.updated_at DESC
            ");
            $stmt->execute([$adminId, $adminId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        foreach ($rows as &$row) {
            $row['display_name'] = $this->displayName($row);
            $row['status_label'] = $this->statusLabel((string) $row['status']);
        }
        unset($row);

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listPendingForChecker(): array
    {
        $stmt = $this->pdo->query("
            SELECT a.id, a.status, a.first_name, a.middle_name, a.last_name, a.email,
                   a.phone, a.barangay_id, a.appointment_date, a.submitted_at, a.bhw_submitted_at,
                   a.submitted_by, a.created_by, a.additional_docs_note,
                   b.name AS barangay_name,
                   CONCAT(m.first_name, ' ', m.last_name) AS submitted_by_name,
                   (SELECT COUNT(*) FROM bhw_application_documents d WHERE d.application_id = a.id) AS document_count
            FROM bhw_applications a
            LEFT JOIN barangays b ON b.id = a.barangay_id
            LEFT JOIN users m ON m.id = a.submitted_by
            WHERE a.status IN ('pending_approval', 'requires_documents')
            ORDER BY FIELD(a.status, 'pending_approval', 'requires_documents'),
                     COALESCE(a.bhw_submitted_at, a.submitted_at) ASC
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $row['display_name'] = $this->displayName($row);
            $row['status_label'] = $this->statusLabel((string) $row['status']);
        }
        unset($row);

        return $rows;
    }

    public function handleDocumentUpload(int $actorId, int $applicationId, string $docType, array $file, string $actorRole = 'admin'): array
    {
        $app = $this->getApplication($applicationId);
        if (!$app) {
            return ['success' => false, 'message' => 'Application not found.'];
        }

        if ($actorRole === 'bhw') {
            // Token-authenticated path uses actorId = 0; re-fetch with token elsewhere.
            return ['success' => false, 'message' => 'Use token-based upload for BHW documents.'];
        }

        if (!$this->canAdminEdit($actorId, $app) && !$this->canAdminUploadDocs($actorId, $app)) {
            return ['success' => false, 'message' => 'Cannot upload documents for this application.'];
        }

        if (!in_array($docType, self::ADMIN_DOC_TYPES, true)) {
            return ['success' => false, 'message' => 'Administrators may only upload institutional documents (appointment letter, CHO endorsement). Government ID must be uploaded by the BHW.'];
        }

        return $this->storeDocument($applicationId, $docType, $file, $actorId);
    }

    public function handleBhwDocumentUpload(string $token, string $docType, array $file): array
    {
        $app = $this->findByInviteToken($token);
        if (!$app || !$this->canBhwEdit($app)) {
            return ['success' => false, 'message' => 'Cannot upload documents for this application.'];
        }

        if (!in_array($docType, self::BHW_DOC_TYPES, true)) {
            return ['success' => false, 'message' => 'You may upload a Government-issued ID or other supporting personal documents.'];
        }

        return $this->storeDocument((int) $app['id'], $docType, $file, (int) ($app['created_by'] ?? 0));
    }

    /**
     * @return array{path: string, name: string, mime: string}|null
     */
    public function getDocumentFile(int $documentId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM bhw_application_documents WHERE id = ? LIMIT 1');
        $stmt->execute([$documentId]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$doc) {
            return null;
        }

        $path = $this->uploadDir((int) $doc['application_id']) . DIRECTORY_SEPARATOR . $doc['stored_name'];
        if (!is_file($path)) {
            return null;
        }

        return [
            'path' => $path,
            'name' => (string) $doc['original_name'],
            'mime' => (string) ($doc['mime_type'] ?: 'application/octet-stream'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getBarangays(): array
    {
        require_once dirname(__DIR__) . '/includes/barangays_bago.php';

        try {
            return barangays_list_bago_city($this->pdo);
        } catch (Throwable $e) {
            return [];
        }
    }

    public function pendingCount(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM bhw_applications WHERE status = 'pending_approval'")->fetchColumn();
    }

    /**
     * @param array<string, mixed> $app
     */
    private function canAdminEdit(int $adminId, array $app): bool
    {
        $status = (string) ($app['status'] ?? '');
        $owner = (int) ($app['created_by'] ?? 0) === $adminId || (int) ($app['submitted_by'] ?? 0) === $adminId;

        return $owner && in_array($status, [self::STATUS_DRAFT, self::STATUS_REJECTED], true);
    }

    /**
     * @param array<string, mixed> $app
     */
    private function canAdminInvite(int $adminId, array $app): bool
    {
        $status = (string) ($app['status'] ?? '');
        $owner = (int) ($app['created_by'] ?? 0) === $adminId || (int) ($app['submitted_by'] ?? 0) === $adminId;

        return $owner && in_array($status, [self::STATUS_DRAFT, self::STATUS_REJECTED], true);
    }

    /**
     * Allow institutional doc upload on draft/rejected before invite.
     *
     * @param array<string, mixed> $app
     */
    private function canAdminUploadDocs(int $adminId, array $app): bool
    {
        return $this->canAdminEdit($adminId, $app) || $this->canAdminInvite($adminId, $app);
    }

    /**
     * @param array<string, mixed> $app
     */
    private function canBhwEdit(array $app): bool
    {
        return in_array((string) ($app['status'] ?? ''), [self::STATUS_ONBOARDING, self::STATUS_REQUIRES_DOCUMENTS], true);
    }

    private function isSuperAdmin(int $userId): bool
    {
        $stmt = $this->pdo->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);

        return $stmt->fetchColumn() === 'superadmin';
    }

    /**
     * @param array<string, bool> $checklist
     */
    private function validateApprovalChecklist(array $checklist): ?string
    {
        foreach (self::REQUIRED_CHECKLIST as $key) {
            if (empty($checklist[$key])) {
                return 'Complete all required approval checklist items before approving.';
            }
        }

        return null;
    }

    private function validateAdminInstitutionalDocs(int $applicationId): ?string
    {
        $docs = $this->getDocuments($applicationId);
        $types = array_column($docs, 'document_type');
        if (!in_array('appointment_letter', $types, true)) {
            return 'Upload the Barangay Appointment Letter / Resolution before sending the invite.';
        }

        return null;
    }

    private function validateRequiredDocuments(int $applicationId): ?string
    {
        $docs = $this->getDocuments($applicationId);
        $types = array_column($docs, 'document_type');
        $missing = [];
        if (!in_array('appointment_letter', $types, true)) {
            $missing[] = 'Barangay Appointment Letter';
        }
        if (!in_array('government_id', $types, true)) {
            $missing[] = 'Government-issued ID';
        }
        if ($missing) {
            return 'Missing required documents: ' . implode(' and ', $missing) . '.';
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getDocuments(int $applicationId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM bhw_application_documents WHERE application_id = ? ORDER BY uploaded_at ASC');
        $stmt->execute([$applicationId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function uploadDir(int $applicationId): string
    {
        return dirname(__DIR__, 2) . '/storage/uploads/bhw_applications/' . $applicationId;
    }

    /**
     * @param array<string, mixed> $file
     */
    private function storeDocument(int $applicationId, string $docType, array $file, int $uploadedBy): array
    {
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return ['success' => false, 'message' => 'No file uploaded.'];
        }

        $allowed = [
            'application/pdf' => 'pdf',
            'image/jpeg'      => 'jpg',
            'image/png'       => 'png',
            'image/webp'      => 'webp',
        ];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : '';
        if ($finfo) {
            finfo_close($finfo);
        }
        if (!isset($allowed[$mime])) {
            return ['success' => false, 'message' => 'Allowed formats: PDF, JPEG, PNG, or WebP.'];
        }
        if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
            return ['success' => false, 'message' => 'File must be 5 MB or smaller.'];
        }

        $dir = $this->uploadDir($applicationId);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['success' => false, 'message' => 'Upload directory unavailable.'];
        }

        $stored = $docType . '_' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
        $dest = $dir . DIRECTORY_SEPARATOR . $stored;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            return ['success' => false, 'message' => 'Failed to store uploaded file.'];
        }

        $this->pdo->prepare("
            INSERT INTO bhw_application_documents
                (application_id, document_type, original_name, stored_name, mime_type, file_size, uploaded_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $applicationId,
            $docType,
            basename((string) ($file['name'] ?? $stored)),
            $stored,
            $mime,
            (int) ($file['size'] ?? 0),
            max(0, $uploadedBy),
        ]);

        return ['success' => true, 'message' => 'Document uploaded.', 'document_id' => (int) $this->pdo->lastInsertId()];
    }

    /**
     * @param array<string, mixed> $app
     */
    private function createBhwUser(array $app): int
    {
        $columns = $this->pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
        $fields = ['first_name', 'last_name', 'email', 'password', 'role', 'is_active'];
        $values = [
            $app['first_name'],
            $app['last_name'],
            $app['email'],
            $app['password_hash'],
            'bhw',
            1,
        ];

        if (in_array('phone', $columns, true)) {
            $fields[] = 'phone';
            $values[] = $app['phone'];
        }
        if (in_array('barangay_id', $columns, true)) {
            $fields[] = 'barangay_id';
            $values[] = (int) $app['barangay_id'];
        }
        if (in_array('is_email_verified', $columns, true)) {
            $fields[] = 'is_email_verified';
            $fields[] = 'email_verified_at';
            $values[] = 1;
            $values[] = date('Y-m-d H:i:s');
        }
        if (in_array('created_at', $columns, true)) {
            $fields[] = 'created_at';
            $values[] = date('Y-m-d H:i:s');
        }

        $placeholders = implode(', ', array_fill(0, count($fields), '?'));
        $this->pdo->prepare('INSERT INTO users (' . implode(', ', $fields) . ') VALUES (' . $placeholders . ')')->execute($values);

        return (int) $this->pdo->lastInsertId();
    }

    private function assertNoDuplicateEmail(string $email, ?int $excludeAppId = null): ?string
    {
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1');
        $stmt->execute([trim($email)]);
        if ($stmt->fetch()) {
            return 'An account with this email already exists.';
        }

        $sql = "
            SELECT id FROM bhw_applications
            WHERE LOWER(email) = LOWER(?)
              AND status IN ('invited', 'onboarding', 'pending_approval', 'requires_documents', 'active', 'approved')
        ";
        $params = [trim($email)];
        if ($excludeAppId) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeAppId;
        }
        $sql .= ' LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        if ($stmt->fetch()) {
            return 'This email is already used in another BHW application.';
        }

        return null;
    }

    private function assertNoDuplicatePhone(string $phone, ?int $excludeAppId = null): ?string
    {
        $columns = $this->pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
        $digits = preg_replace('/\D+/', '', $phone);

        if (in_array('phone', $columns, true)) {
            $stmt = $this->pdo->prepare('SELECT id FROM users WHERE phone = ? LIMIT 1');
            $stmt->execute([$digits]);
            if ($stmt->fetch()) {
                return 'An account with this mobile number already exists.';
            }
        }

        $sql = "
            SELECT id FROM bhw_applications
            WHERE phone = ?
              AND status IN ('invited', 'onboarding', 'pending_approval', 'requires_documents', 'active', 'approved')
        ";
        $params = [$digits];
        if ($excludeAppId) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeAppId;
        }
        $sql .= ' LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        if ($stmt->fetch()) {
            return 'This mobile number is already used in another BHW application.';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function displayName(array $row): string
    {
        $middle = trim((string) ($row['middle_name'] ?? ''));

        return trim($row['first_name'] . ($middle !== '' ? ' ' . $middle : '') . ' ' . $row['last_name']);
    }

    private function userDisplayName(int $userId): string
    {
        $stmt = $this->pdo->prepare('SELECT first_name, last_name FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return trim(($row['first_name'] ?? 'User') . ' ' . ($row['last_name'] ?? ''));
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_INVITED            => 'Invited',
            self::STATUS_ONBOARDING         => 'Onboarding',
            self::STATUS_PENDING            => 'Pending Approval',
            self::STATUS_ACTIVE             => 'Active',
            self::STATUS_APPROVED           => 'Approved',
            self::STATUS_REJECTED           => 'Rejected',
            self::STATUS_REQUIRES_DOCUMENTS => 'Requires Additional Documents',
            default                         => 'Draft',
        };
    }

    private function activateUrl(string $token): string
    {
        $base = defined('BASE_URL') ? rtrim((string) BASE_URL, '/') : '';

        return $base . '/public/bhw_activate.php?token=' . urlencode($token);
    }

    private function onboardingUrl(string $token): string
    {
        $base = defined('BASE_URL') ? rtrim((string) BASE_URL, '/') : '';

        return $base . '/public/bhw_onboarding.php?token=' . urlencode($token);
    }

    private function sendInviteEmail(string $to, string $fullName, string $token, string $barangay, bool $isReminder = false): array
    {
        require_once dirname(__DIR__) . '/includes/mailer.php';
        if (!function_exists('sendBhwInviteEmail')) {
            return ['success' => false, 'message' => 'Mailer unavailable.'];
        }

        return sendBhwInviteEmail($to, $fullName, $token, $barangay, $isReminder);
    }

    private function sendCorrectionEmail(string $to, string $fullName, string $token, string $note): array
    {
        require_once dirname(__DIR__) . '/includes/mailer.php';
        if (!function_exists('sendBhwCorrectionEmail')) {
            return ['success' => false, 'message' => 'Mailer unavailable.'];
        }

        return sendBhwCorrectionEmail($to, $fullName, $token, $note);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function audit(int $actorId, string $actionType, string $description, array $meta = []): void
    {
        try {
            require_once dirname(__DIR__) . '/includes/audit_log.php';
            audit_log($this->pdo, [
                'patient_id'  => $actorId > 0 ? $actorId : null,
                'action_type' => $actionType,
                'description' => $description,
                'meta'        => $meta,
            ]);
        } catch (Throwable $e) {
            // non-fatal
        }
    }
}
