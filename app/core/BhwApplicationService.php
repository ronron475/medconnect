<?php
/**
 * Barangay Health Worker account applications — Maker-Checker workflow.
 *
 * Maker: Administrator creates and submits applications.
 * Checker: Super Administrator approves, rejects, or requests additional documents.
 */
final class BhwApplicationService
{
    public const STATUS_DRAFT              = 'draft';
    public const STATUS_PENDING            = 'pending_approval';
    public const STATUS_APPROVED           = 'approved';
    public const STATUS_ACTIVE             = 'active';
    public const STATUS_REJECTED           = 'rejected';
    public const STATUS_REQUIRES_DOCUMENTS = 'requires_documents';

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

    /** @var list<string> */
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
     * @param array<string, mixed> $input
     * @return array{valid: bool, errors: array<string, string>, normalized: array<string>
     */
    public function validateApplicationInput(array $input, bool $forSubmit = false): array
    {
        $errors = [];
        $normalized = [
            'first_name'       => trim((string) ($input['first_name'] ?? '')),
            'middle_name'      => trim((string) ($input['middle_name'] ?? '')),
            'last_name'        => trim((string) ($input['last_name'] ?? '')),
            'email'            => trim((string) ($input['email'] ?? '')),
            'phone'            => trim((string) ($input['phone'] ?? '')),
            'password'         => (string) ($input['password'] ?? ''),
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

        if ($forSubmit) {
            if ($normalized['password'] === '') {
                $errors['password'] = 'Initial password is required.';
            } elseif ($err = $this->validatePasswordStrength($normalized['password'])) {
                $errors['password'] = $err;
            }
            if ($dup = $this->assertNoDuplicateEmail($normalized['email'])) {
                $errors['email'] = $dup;
            }
            if ($dup = $this->assertNoDuplicatePhone($normalized['phone'])) {
                $errors['phone'] = $dup;
            }
        } elseif ($normalized['password'] !== '' && ($err = $this->validatePasswordStrength($normalized['password']))) {
            $errors['password'] = $err;
        }

        return ['valid' => $errors === [], 'errors' => $errors, 'normalized' => $normalized];
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

        if ($applicationId) {
            $sql = "
                UPDATE bhw_applications SET
                    first_name = ?, middle_name = ?, last_name = ?, email = ?, phone = ?,
                    barangay_id = ?, appointment_date = ?,
                    password_hash = COALESCE(?, password_hash),
                    status = CASE WHEN status IN ('rejected', 'requires_documents') THEN 'draft' ELSE status END,
                    updated_at = NOW()
                WHERE id = ?
            ";
            $this->pdo->prepare($sql)->execute([
                $n['first_name'], $n['middle_name'] ?: null, $n['last_name'], $n['email'], $n['phone'],
                $n['barangay_id'], $n['appointment_date'], $passwordHash, $applicationId,
            ]);
            $id = $applicationId;
        } else {
            $stmt = $this->pdo->prepare("
                INSERT INTO bhw_applications
                    (status, first_name, middle_name, last_name, email, phone, password_hash,
                     barangay_id, appointment_date, created_by, created_at, updated_at)
                VALUES ('draft', ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $stmt->execute([
                $n['first_name'], $n['middle_name'] ?: null, $n['last_name'], $n['email'], $n['phone'],
                $passwordHash, $n['barangay_id'], $n['appointment_date'], $adminId,
            ]);
            $id = (int) $this->pdo->lastInsertId();
        }

        $this->audit($adminId, 'bhw_application_draft_saved', "Administrator saved BHW application draft for {$n['first_name']} {$n['last_name']}.", [
            'application_id' => $id,
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

        $validation = $this->validateApplicationInput([
            'first_name'       => $app['first_name'],
            'middle_name'      => $app['middle_name'],
            'last_name'        => $app['last_name'],
            'email'            => $app['email'],
            'phone'            => $app['phone'],
            'password'         => '',
            'barangay_id'      => $app['barangay_id'],
            'appointment_date' => $app['appointment_date'],
        ], false);

        if (empty($app['password_hash'])) {
            $validation['errors']['password'] = 'Initial password is required before submission.';
            $validation['valid'] = false;
        }

        if (!$validation['valid']) {
            return ['success' => false, 'message' => reset($validation['errors']), 'errors' => $validation['errors']];
        }

        $docErr = $this->validateRequiredDocuments($applicationId);
        if ($docErr) {
            return ['success' => false, 'message' => $docErr];
        }

        if ($dup = $this->assertNoDuplicateEmail($app['email'])) {
            return ['success' => false, 'message' => $dup];
        }
        if ($dup = $this->assertNoDuplicatePhone((string) $app['phone'])) {
            return ['success' => false, 'message' => $dup];
        }

        $this->pdo->prepare("
            UPDATE bhw_applications SET
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
        $this->audit($adminId, 'bhw_application_submitted', "Administrator {$makerName} submitted a Barangay Health Worker application for {$name}.", [
            'application_id' => $applicationId,
            'submitted_by'   => $adminId,
            'applicant_name' => $name,
        ]);

        require_once dirname(__DIR__) . '/includes/notification_events.php';
        NotificationEvents::bhwApplicationSubmitted($this->pdo, $applicationId, $name, $adminId);

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

        if ($dup = $this->assertNoDuplicateEmail($app['email'])) {
            return ['success' => false, 'message' => $dup];
        }
        if ($dup = $this->assertNoDuplicatePhone((string) $app['phone'])) {
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
                'user_id'          => $userId,
                'approved_by'      => $superAdminId,
                'submitted_by'     => $makerId,
                'checklist'        => $checklist,
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
            return ['success' => false, 'message' => 'You cannot reject an application you submitted.'];
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
                updated_at = NOW()
            WHERE id = ?
        ")->execute([$superAdminId, $now, $superAdminId, $now, $reason, $applicationId]);

        $makerId = (int) ($app['submitted_by'] ?? $app['created_by']);
        $name = $this->displayName($app);
        $checker = $this->userDisplayName($superAdminId);

        $this->audit($superAdminId, 'bhw_application_rejected', "Super Administrator {$checker} rejected the BHW application for {$name}. Reason: {$reason}", [
            'application_id'  => $applicationId,
            'rejected_by'     => $superAdminId,
            'rejection_reason'=> $reason,
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

        $this->pdo->prepare("
            UPDATE bhw_applications SET
                status = 'requires_documents',
                reviewed_by = ?,
                reviewed_at = NOW(),
                additional_docs_note = ?,
                updated_at = NOW()
            WHERE id = ?
        ")->execute([$superAdminId, $note, $applicationId]);

        $makerId = (int) ($app['submitted_by'] ?? $app['created_by']);
        $name = $this->displayName($app);

        $this->audit($superAdminId, 'bhw_application_docs_requested', "Additional documents requested for BHW application ({$name}).", [
            'application_id' => $applicationId,
            'note'           => $note,
        ]);

        require_once dirname(__DIR__) . '/includes/notification_events.php';
        NotificationEvents::bhwApplicationDocsRequested($this->pdo, $applicationId, $name, $makerId, $superAdminId, $note);

        return ['success' => true, 'message' => 'Administrator notified to provide additional documents.'];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getApplication(int $id): ?array
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
                       a.appointment_date, a.submitted_at, a.created_at,
                       b.name AS barangay_name,
                       CONCAT(s.first_name, ' ', s.last_name) AS submitted_by_name
                FROM bhw_applications a
                LEFT JOIN barangays b ON b.id = a.barangay_id
                LEFT JOIN users s ON s.id = a.submitted_by
                ORDER BY FIELD(a.status, 'pending_approval', 'requires_documents', 'draft', 'rejected', 'active'),
                         a.updated_at DESC
            ");
        } else {
            $stmt = $this->pdo->prepare("
                SELECT a.id, a.status, a.first_name, a.middle_name, a.last_name, a.email,
                       a.appointment_date, a.submitted_at, a.created_at,
                       b.name AS barangay_name,
                       CONCAT(s.first_name, ' ', s.last_name) AS submitted_by_name
                FROM bhw_applications a
                LEFT JOIN barangays b ON b.id = a.barangay_id
                LEFT JOIN users s ON s.id = a.submitted_by
                WHERE a.created_by = ? OR a.submitted_by = ?
                ORDER BY a.updated_at DESC
            ");
            $stmt->execute([$adminId, $adminId]);
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $row['display_name'] = $this->displayName($row);
            $row['status_label'] = $this->statusLabel((string) $row['status']);
            $row['document_count'] = $this->countDocuments((int) $row['id']);
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
                   a.appointment_date, a.submitted_at, a.created_at,
                   b.name AS barangay_name,
                   CONCAT(s.first_name, ' ', s.last_name) AS submitted_by_name,
                   a.submitted_by
            FROM bhw_applications a
            LEFT JOIN barangays b ON b.id = a.barangay_id
            LEFT JOIN users s ON s.id = a.submitted_by
            WHERE a.status IN ('pending_approval', 'requires_documents')
            ORDER BY a.submitted_at ASC
        ");

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $row['display_name'] = $this->displayName($row);
            $row['status_label'] = $this->statusLabel((string) $row['status']);
            $row['document_count'] = $this->countDocuments((int) $row['id']);
        }
        unset($row);

        return $rows;
    }

    public function handleDocumentUpload(int $adminId, int $applicationId, string $docType, array $file): array
    {
        $app = $this->getApplication($applicationId);
        if (!$app || !$this->canAdminEdit($adminId, $app)) {
            return ['success' => false, 'message' => 'Cannot upload documents for this application.'];
        }

        if (!in_array($docType, ['appointment_letter', 'government_id', 'cho_endorsement', 'other'], true)) {
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
            $adminId,
        ]);

        return ['success' => true, 'message' => 'Document uploaded.', 'document_id' => (int) $this->pdo->lastInsertId()];
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
                return 'Upload required documents: Barangay Appointment Letter and Government-issued ID.';
            }
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

    private function countDocuments(int $applicationId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM bhw_application_documents WHERE application_id = ?');
        $stmt->execute([$applicationId]);

        return (int) $stmt->fetchColumn();
    }

    private function uploadDir(int $applicationId): string
    {
        return dirname(__DIR__, 2) . '/storage/uploads/bhw_applications/' . $applicationId;
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

    private function assertNoDuplicateEmail(string $email): ?string
    {
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1');
        $stmt->execute([trim($email)]);
        if ($stmt->fetch()) {
            return 'An account with this email already exists.';
        }

        $stmt = $this->pdo->prepare("
            SELECT id FROM bhw_applications
            WHERE LOWER(email) = LOWER(?)
              AND status IN ('pending_approval', 'active', 'approved')
            LIMIT 1
        ");
        $stmt->execute([trim($email)]);
        if ($stmt->fetch()) {
            return 'This email is already used in a pending or active BHW application.';
        }

        return null;
    }

    private function assertNoDuplicatePhone(string $phone): ?string
    {
        $columns = $this->pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('phone', $columns, true)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone);
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE phone = ? LIMIT 1');
        $stmt->execute([$digits]);
        if ($stmt->fetch()) {
            return 'An account with this mobile number already exists.';
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
