<?php
/**
 * Doctor account applications — Maker-Checker workflow.
 *
 * Maker: Administrator prepares and submits applications.
 * Checker: Super Administrator approves, rejects, or requests additional documents.
 */
final class DoctorApplicationService
{
    public const STATUS_DRAFT              = 'draft';
    public const STATUS_PENDING            = 'pending_approval';
    public const STATUS_APPROVED           = 'approved';
    public const STATUS_ACTIVE             = 'active';
    public const STATUS_REJECTED           = 'rejected';
    public const STATUS_REQUIRES_DOCUMENTS = 'requires_documents';

    /** @var list<string> */
    public const REQUIRED_CHECKLIST = [
        'prc_license_verified',
        'prc_id_matches_applicant',
        'government_id_matches_applicant',
        'license_status_active',
        'license_not_expired',
        'profession_physician',
        'facility_valid',
        'email_correct',
        'no_duplicate_prc',
        'no_duplicate_doctor',
    ];

    /** @var list<string> */
    public const REQUIRED_DOC_TYPES = [
        'prc_id',
        'government_id',
    ];

    public function __construct(private PDO $pdo)
    {
        require_once dirname(__DIR__) . '/includes/doctor_application_schema.php';
        doctor_application_ensure_schema($this->pdo);
        require_once dirname(__DIR__) . '/includes/provider_verification.php';
        provider_verification_ensure_schema($this->pdo);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{valid: bool, errors: array<string, string>, normalized: array<string, mixed>}
     */
    public function validateApplicationInput(array $input, bool $forSubmit = false): array
    {
        require_once dirname(__DIR__) . '/core/PRCVerificationService.php';

        $requirePrcConfirm = $forSubmit;
        $validation = PRCVerificationService::validateDoctorCreatePayload($this->pdo, $input, $requirePrcConfirm);

        if (!$forSubmit) {
            unset($validation['errors']['prc_verification']);
            if ($validation['errors'] === []) {
                $validation['valid'] = true;
            }
            if (($input['password'] ?? '') === '') {
                unset($validation['errors']['password']);
                if ($validation['errors'] === []) {
                    $validation['valid'] = true;
                }
            }
        }

        return $validation;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function saveDraft(int $adminId, array $data, ?int $applicationId = null): array
    {
        $validation = $this->validateApplicationInput($data, false);
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

        $passwordHash = null;
        if ($n['password'] !== '') {
            $passwordHash = password_hash($n['password'], PASSWORD_BCRYPT, ['cost' => 12]);
        } elseif ($applicationId) {
            $existing = $this->getApplication($applicationId);
            $passwordHash = $existing['password_hash'] ?? null;
        }

        $prcConfirmed = !empty($n['prc_confirmed']) ? 1 : 0;

        if ($applicationId) {
            $sql = "
                UPDATE doctor_applications SET
                    first_name = ?, middle_name = ?, last_name = ?, birthdate = ?,
                    email = ?, phone = ?, prc_license_number = ?, specialization = ?, facility = ?,
                    prc_verification_confirmed = ?,
                    password_hash = COALESCE(?, password_hash),
                    status = CASE WHEN status IN ('rejected', 'requires_documents') THEN 'draft' ELSE status END,
                    updated_at = NOW()
                WHERE id = ?
            ";
            $this->pdo->prepare($sql)->execute([
                $n['first_name'], $n['middle_name'] ?: null, $n['last_name'], $n['birthdate'],
                $n['email'], $n['phone'], $n['prc_license_number'], $n['specialization'],
                $n['facility'] !== '' ? $n['facility'] : null,
                $prcConfirmed, $passwordHash, $applicationId,
            ]);
            $id = $applicationId;
        } else {
            $stmt = $this->pdo->prepare("
                INSERT INTO doctor_applications
                    (status, first_name, middle_name, last_name, birthdate, email, phone, password_hash,
                     prc_license_number, specialization, facility, prc_verification_confirmed, created_by, created_at, updated_at)
                VALUES ('draft', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $stmt->execute([
                $n['first_name'], $n['middle_name'] ?: null, $n['last_name'], $n['birthdate'],
                $n['email'], $n['phone'], $passwordHash, $n['prc_license_number'], $n['specialization'],
                $n['facility'] !== '' ? $n['facility'] : null, $prcConfirmed, $adminId,
            ]);
            $id = (int) $this->pdo->lastInsertId();
        }

        $name = $this->displayNameFromParts($n['first_name'], $n['middle_name'], $n['last_name']);
        $makerName = $this->userDisplayName($adminId);
        $this->audit($adminId, 'doctor_application_draft_saved', "Administrator {$makerName} saved a Doctor application draft for Dr. {$name}.", [
            'application_id' => $id,
            'prc_license_number' => $n['prc_license_number'],
        ]);

        return ['success' => true, 'message' => 'Draft saved.', 'application_id' => $id];
    }

    public function submit(int $adminId, int $applicationId): array
    {
        $app = $this->getApplication($applicationId);
        if (!$app) {
            return ['success' => false, 'message' => 'Application not found.'];
        }
        if (!$this->canAdminEdit($adminId, $app)) {
            return ['success' => false, 'message' => 'This application cannot be submitted.'];
        }

        if (empty($app['password_hash'])) {
            return ['success' => false, 'message' => 'Initial password is required before submission.'];
        }

        if (empty($app['prc_verification_confirmed'])) {
            return ['success' => false, 'message' => 'You must confirm PRC license verification before submitting.'];
        }

        $validation = $this->validateApplicationInput([
            'first_name'                 => $app['first_name'],
            'middle_name'                => $app['middle_name'],
            'last_name'                  => $app['last_name'],
            'birthdate'                  => $app['birthdate'],
            'email'                      => $app['email'],
            'phone'                      => $app['phone'],
            'password'                   => 'Placeholder1!',
            'prc_license_number'         => $app['prc_license_number'],
            'specialization'             => $app['specialization'],
            'facility'                   => $app['facility'],
            'prc_verification_confirmed' => '1',
        ], true);

        if (!$validation['valid']) {
            return ['success' => false, 'message' => reset($validation['errors']), 'errors' => $validation['errors']];
        }

        $docErr = $this->validateRequiredDocuments($applicationId);
        if ($docErr) {
            return ['success' => false, 'message' => $docErr];
        }

        if ($dup = $this->assertNoDuplicateEmail($app['email'], $applicationId)) {
            return ['success' => false, 'message' => $dup];
        }
        if ($dup = $this->assertNoDuplicatePhone((string) $app['phone'], $applicationId)) {
            return ['success' => false, 'message' => $dup];
        }
        if ($dup = $this->assertNoDuplicatePrc($app['prc_license_number'], $applicationId)) {
            return ['success' => false, 'message' => $dup];
        }

        $this->pdo->prepare("
            UPDATE doctor_applications SET
                status = 'pending_approval',
                submitted_by = ?,
                submitted_at = NOW(),
                rejection_reason = NULL,
                additional_docs_note = NULL,
                updated_at = NOW()
            WHERE id = ?
        ")->execute([$adminId, $applicationId]);

        $name = $this->displayName($app);
        $makerName = $this->userDisplayName($adminId);
        $this->audit($adminId, 'doctor_application_submitted', "Administrator {$makerName} submitted a Doctor Account application for Dr. {$name}.", [
            'application_id'     => $applicationId,
            'submitted_by'       => $adminId,
            'applicant_name'     => $name,
            'prc_license_number' => $app['prc_license_number'],
        ]);

        require_once dirname(__DIR__) . '/includes/notification_events.php';
        NotificationEvents::doctorApplicationSubmitted($this->pdo, $applicationId, $name, $adminId);

        return ['success' => true, 'message' => 'Application submitted for Super Administrator approval.'];
    }

    /**
     * @param array<string, bool> $checklist
     */
    public function approve(int $superAdminId, int $applicationId, array $checklist): array
    {
        if (!$this->isSuperAdmin($superAdminId)) {
            return ['success' => false, 'message' => 'Only Super Administrators can approve Doctor applications.'];
        }

        $app = $this->getApplication($applicationId);
        if (!$app || $app['status'] !== self::STATUS_PENDING) {
            return ['success' => false, 'message' => 'Application is not pending approval.'];
        }

        if ((int) ($app['submitted_by'] ?? 0) === $superAdminId) {
            return ['success' => false, 'message' => 'You cannot approve an application you submitted. Maker-Checker separation is required.'];
        }

        $checkErr = $this->validateApprovalChecklist($checklist);
        if ($checkErr) {
            return ['success' => false, 'message' => $checkErr];
        }

        if ($dup = $this->assertNoDuplicateEmail($app['email'], $applicationId)) {
            return ['success' => false, 'message' => $dup];
        }
        if ($dup = $this->assertNoDuplicatePhone((string) $app['phone'], $applicationId)) {
            return ['success' => false, 'message' => $dup];
        }
        if ($dup = $this->assertNoDuplicatePrc($app['prc_license_number'], $applicationId)) {
            return ['success' => false, 'message' => $dup];
        }

        $this->pdo->beginTransaction();
        try {
            $userId = $this->createProviderUser($app, $superAdminId);
            $now = date('Y-m-d H:i:s');

            $this->pdo->prepare("
                UPDATE doctor_applications SET
                    user_id = ?,
                    status = 'active',
                    reviewed_by = ?,
                    reviewed_at = ?,
                    approved_by = ?,
                    approved_at = ?,
                    checklist_json = ?,
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

            $this->audit($superAdminId, 'doctor_application_approved', "Super Administrator {$checker} approved the Doctor Account for Dr. {$name} after confirming PRC verification and supporting documents.", [
                'application_id'     => $applicationId,
                'user_id'            => $userId,
                'approved_by'        => $superAdminId,
                'submitted_by'       => $makerId,
                'prc_license_number' => $app['prc_license_number'],
                'checklist'          => $checklist,
            ]);

            require_once dirname(__DIR__) . '/includes/notification_events.php';
            NotificationEvents::doctorApplicationApproved($this->pdo, $applicationId, $userId, $name, $makerId, $superAdminId);

            return ['success' => true, 'message' => 'Doctor account approved and activated.', 'user_id' => $userId];
        } catch (Throwable $e) {
            $this->pdo->rollBack();

            return ['success' => false, 'message' => 'Approval failed: ' . $e->getMessage()];
        }
    }

    public function reject(int $superAdminId, int $applicationId, string $reason): array
    {
        if (!$this->isSuperAdmin($superAdminId)) {
            return ['success' => false, 'message' => 'Only Super Administrators can reject Doctor applications.'];
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
            return ['success' => false, 'message' => 'You cannot reject an application you submitted.'];
        }

        $now = date('Y-m-d H:i:s');
        $this->pdo->prepare("
            UPDATE doctor_applications SET
                status = 'rejected',
                reviewed_by = ?,
                reviewed_at = ?,
                rejected_by = ?,
                rejected_at = ?,
                rejection_reason = ?,
                updated_at = NOW()
            WHERE id = ?
        ")->execute([$superAdminId, $now, $superAdminId, $now, $reason, $applicationId]);

        $makerId = (int) ($app['submitted_by'] ?? $app['created_by']);
        $name = $this->displayName($app);
        $checker = $this->userDisplayName($superAdminId);

        $this->audit($superAdminId, 'doctor_application_rejected', "Super Administrator {$checker} rejected the Doctor Account application for Dr. {$name}. Reason: {$reason}", [
            'application_id'     => $applicationId,
            'rejected_by'        => $superAdminId,
            'rejection_reason'   => $reason,
            'prc_license_number' => $app['prc_license_number'],
        ]);

        require_once dirname(__DIR__) . '/includes/notification_events.php';
        NotificationEvents::doctorApplicationRejected($this->pdo, $applicationId, $name, $makerId, $superAdminId, $reason, $app['email']);

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

        $this->pdo->prepare("
            UPDATE doctor_applications SET
                status = 'requires_documents',
                reviewed_by = ?,
                reviewed_at = NOW(),
                additional_docs_note = ?,
                updated_at = NOW()
            WHERE id = ?
        ")->execute([$superAdminId, $note, $applicationId]);

        $makerId = (int) ($app['submitted_by'] ?? $app['created_by']);
        $name = $this->displayName($app);

        $this->audit($superAdminId, 'doctor_application_docs_requested', "Additional documents requested for Doctor application (Dr. {$name}).", [
            'application_id' => $applicationId,
            'note'           => $note,
        ]);

        require_once dirname(__DIR__) . '/includes/notification_events.php';
        NotificationEvents::doctorApplicationDocsRequested($this->pdo, $applicationId, $name, $makerId, $superAdminId, $note);

        return ['success' => true, 'message' => 'Administrator notified to provide additional documents.'];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getApplication(int $id): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT a.*,
                   CONCAT(m.first_name, ' ', m.last_name) AS submitted_by_name,
                   CONCAT(c.first_name, ' ', c.last_name) AS created_by_name
            FROM doctor_applications a
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
        $row['verification_status'] = $this->verificationStatusLabel($row);

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
                       a.prc_license_number, a.specialization, a.facility,
                       a.submitted_at, a.created_at,
                       CONCAT(s.first_name, ' ', s.last_name) AS submitted_by_name
                FROM doctor_applications a
                LEFT JOIN users s ON s.id = a.submitted_by
                ORDER BY FIELD(a.status, 'pending_approval', 'requires_documents', 'draft', 'rejected', 'active'),
                         a.updated_at DESC
            ");
        } else {
            $stmt = $this->pdo->prepare("
                SELECT a.id, a.status, a.first_name, a.middle_name, a.last_name, a.email,
                       a.prc_license_number, a.specialization, a.facility,
                       a.submitted_at, a.created_at,
                       CONCAT(s.first_name, ' ', s.last_name) AS submitted_by_name
                FROM doctor_applications a
                LEFT JOIN users s ON s.id = a.submitted_by
                WHERE a.created_by = ? OR a.submitted_by = ?
                ORDER BY a.updated_at DESC
            ");
            $stmt->execute([$adminId, $adminId]);
        }

        return $this->enrichListRows($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listPendingForChecker(): array
    {
        $stmt = $this->pdo->query("
            SELECT a.id, a.status, a.first_name, a.middle_name, a.last_name, a.email,
                   a.prc_license_number, a.specialization, a.facility,
                   a.submitted_at, a.created_at, a.prc_verification_confirmed,
                   CONCAT(s.first_name, ' ', s.last_name) AS submitted_by_name,
                   a.submitted_by
            FROM doctor_applications a
            LEFT JOIN users s ON s.id = a.submitted_by
            WHERE a.status IN ('pending_approval', 'requires_documents')
            ORDER BY a.submitted_at ASC
        ");

        return $this->enrichListRows($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function handleDocumentUpload(int $adminId, int $applicationId, string $docType, array $file): array
    {
        $app = $this->getApplication($applicationId);
        if (!$app || !$this->canAdminEdit($adminId, $app)) {
            return ['success' => false, 'message' => 'Cannot upload documents for this application.'];
        }

        if (!in_array($docType, ['prc_id', 'government_id', 'facility_id', 'other'], true)) {
            return ['success' => false, 'message' => 'Invalid document type.'];
        }

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
            INSERT INTO doctor_application_documents
                (application_id, document_type, original_name, stored_name, mime_type, file_size, uploaded_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $applicationId,
            $docType,
            basename((string) ($file['name'] ?? $stored)),
            $stored,
            $mime,
            (int) ($file['size'] ?? 0),
            $adminId,
        ]);

        return ['success' => true, 'message' => 'Document uploaded.', 'document_id' => (int) $this->pdo->lastInsertId()];
    }

    /**
     * @return array{path: string, name: string, mime: string}|null
     */
    public function getDocumentFile(int $documentId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM doctor_application_documents WHERE id = ? LIMIT 1');
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

    public function pendingCount(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM doctor_applications WHERE status = 'pending_approval'")->fetchColumn();
    }

    /**
     * @param array<string, mixed> $app
     */
    private function canAdminEdit(int $adminId, array $app): bool
    {
        $status = (string) ($app['status'] ?? '');
        $owner = (int) ($app['created_by'] ?? 0) === $adminId || (int) ($app['submitted_by'] ?? 0) === $adminId;

        return $owner && in_array($status, [self::STATUS_DRAFT, self::STATUS_REJECTED, self::STATUS_REQUIRES_DOCUMENTS], true);
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

    private function validateRequiredDocuments(int $applicationId): ?string
    {
        $docs = $this->getDocuments($applicationId);
        $types = array_column($docs, 'document_type');
        foreach (self::REQUIRED_DOC_TYPES as $required) {
            if (!in_array($required, $types, true)) {
                return 'Upload required documents: PRC ID and Government-issued ID.';
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getDocuments(int $applicationId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM doctor_application_documents WHERE application_id = ? ORDER BY uploaded_at ASC');
        $stmt->execute([$applicationId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function countDocuments(int $applicationId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM doctor_application_documents WHERE application_id = ?');
        $stmt->execute([$applicationId]);

        return (int) $stmt->fetchColumn();
    }

    private function uploadDir(int $applicationId): string
    {
        return dirname(__DIR__, 2) . '/storage/uploads/doctor_applications/' . $applicationId;
    }

    /**
     * @param array<string, mixed> $app
     */
    private function createProviderUser(array $app, int $verifiedBy): int
    {
        $columns = $this->pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
        $fields = ['first_name', 'last_name', 'email', 'password', 'role', 'is_active'];
        $values = [
            $app['first_name'],
            $app['last_name'],
            $app['email'],
            $app['password_hash'],
            'provider',
            1,
        ];

        if (in_array('phone', $columns, true)) {
            $fields[] = 'phone';
            $values[] = $app['phone'];
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
        $userId = (int) $this->pdo->lastInsertId();

        $profileCols = ['user_id', 'prc_license_number', 'verification_status', 'verified_by', 'verified_at', 'created_by'];
        $profileVals = [$userId, $app['prc_license_number'], 'verified', $verifiedBy, date('Y-m-d H:i:s'), (int) ($app['submitted_by'] ?? $app['created_by'])];
        $profilePlace = array_fill(0, count($profileCols), '?');

        $optionalProfile = [
            'middle_name' => !empty($app['middle_name']) ? $app['middle_name'] : null,
            'birthdate'   => !empty($app['birthdate']) ? $app['birthdate'] : null,
            'specialty'   => !empty($app['specialization']) ? $app['specialization'] : null,
            'facility'    => !empty($app['facility']) ? $app['facility'] : null,
        ];

        $profileColumnCheck = $this->pdo->query('SHOW COLUMNS FROM provider_profiles')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($optionalProfile as $col => $val) {
            if (in_array($col, $profileColumnCheck, true)) {
                $profileCols[] = $col;
                $profileVals[] = $val;
                $profilePlace[] = '?';
            }
        }

        $sqlProfile = 'INSERT INTO provider_profiles (' . implode(', ', $profileCols) . ') VALUES (' . implode(', ', $profilePlace) . ')';
        $this->pdo->prepare($sqlProfile)->execute($profileVals);

        return $userId;
    }

    private function assertNoDuplicateEmail(string $email, ?int $excludeAppId = null): ?string
    {
        require_once dirname(__DIR__) . '/core/PRCVerificationService.php';
        if ($dup = PRCVerificationService::assertNoDuplicateEmail($this->pdo, $email)) {
            return $dup;
        }

        $sql = "
            SELECT id FROM doctor_applications
            WHERE LOWER(email) = LOWER(?)
              AND status IN ('pending_approval', 'active', 'approved')
        ";
        $params = [trim($email)];
        if ($excludeAppId) {
            $sql .= ' AND id != ?';
            $params[] = $excludeAppId;
        }
        $sql .= ' LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetch() ? 'This email is already used in a pending or active Doctor application.' : null;
    }

    private function assertNoDuplicatePhone(string $phone, ?int $excludeAppId = null): ?string
    {
        require_once dirname(__DIR__) . '/core/PRCVerificationService.php';
        if ($dup = PRCVerificationService::assertNoDuplicatePhone($this->pdo, $phone)) {
            return $dup;
        }

        return null;
    }

    private function assertNoDuplicatePrc(string $prc, ?int $excludeAppId = null): ?string
    {
        require_once dirname(__DIR__) . '/core/PRCVerificationService.php';
        if ($dup = PRCVerificationService::assertNoDuplicatePrc($this->pdo, $prc)) {
            return $dup;
        }

        $prc = provider_verification_normalize_prc($prc);
        $sql = "
            SELECT id FROM doctor_applications
            WHERE prc_license_number = ?
              AND status IN ('pending_approval', 'active', 'approved')
        ";
        $params = [$prc];
        if ($excludeAppId) {
            $sql .= ' AND id != ?';
            $params[] = $excludeAppId;
        }
        $sql .= ' LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetch() ? 'This PRC license number is already used in a pending or active Doctor application.' : null;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function enrichListRows(array $rows): array
    {
        foreach ($rows as &$row) {
            $row['display_name'] = $this->displayName($row);
            $row['status_label'] = $this->statusLabel((string) $row['status']);
            $row['document_count'] = $this->countDocuments((int) $row['id']);
            $row['verification_status'] = $this->verificationStatusLabel($row);
        }
        unset($row);

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function displayName(array $row): string
    {
        return $this->displayNameFromParts(
            (string) ($row['first_name'] ?? ''),
            (string) ($row['middle_name'] ?? ''),
            (string) ($row['last_name'] ?? '')
        );
    }

    private function displayNameFromParts(string $first, string $middle, string $last): string
    {
        $middle = trim($middle);

        return trim($first . ($middle !== '' ? ' ' . $middle : '') . ' ' . $last);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function verificationStatusLabel(array $row): string
    {
        if (!empty($row['prc_verification_confirmed'])) {
            return 'PRC Verified (Maker)';
        }

        return 'Pending PRC Verification';
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
            self::STATUS_PENDING            => 'Pending Approval',
            self::STATUS_ACTIVE             => 'Active',
            self::STATUS_APPROVED           => 'Approved',
            self::STATUS_REJECTED           => 'Rejected',
            self::STATUS_REQUIRES_DOCUMENTS => 'Requires Additional Documents',
            default                         => 'Draft',
        };
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function audit(int $actorId, string $actionType, string $description, array $meta = []): void
    {
        try {
            require_once dirname(__DIR__) . '/includes/audit_log.php';
            audit_log($this->pdo, [
                'patient_id'  => $actorId,
                'action_type' => $actionType,
                'description' => $description,
                'meta'        => $meta,
            ]);
        } catch (Throwable $e) {
            // non-fatal
        }
    }
}
