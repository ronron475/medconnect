<?php
/**
 * BHW workflow business logic — shared across API endpoints.
 */
require_once __DIR__ . '/bhw_scope.php';
require_once __DIR__ . '/appointment_slots.php';
require_once __DIR__ . '/bhw_clinical.php';
require_once __DIR__ . '/consultation_expiry.php';
require_once __DIR__ . '/triage_assessment_schema.php';
require_once __DIR__ . '/patient_account_security.php';
require_once dirname(__DIR__) . '/core/TriageLevelService.php';
require_once dirname(__DIR__) . '/core/MedicalAssessmentEngine.php';

final class BhwWorkflows
{
    public static function listPatients(PDO $pdo, array $ctx, string $search = ''): array
    {
        [$clause, $params] = bhw_patient_sector_clause($pdo, $ctx, 'pr');
        $sql = "
            SELECT u.id, u.first_name, u.last_name, u.email, u.is_active, u.created_at,
                   pr.contact_number, pr.barangay, pr.purok, pr.age, pr.gender, pr.patient_code,
                   (SELECT MAX(c.consult_date) FROM consultations c WHERE c.patient_id = u.id) AS last_consult,
                   (SELECT tr.urgency_label FROM triage_results tr WHERE tr.patient_id = u.id ORDER BY tr.assessed_at DESC LIMIT 1) AS risk_level,
                   (SELECT CONCAT(prv.first_name, ' ', prv.last_name) FROM consultations c
                    JOIN users prv ON prv.id = c.provider_id WHERE c.patient_id = u.id ORDER BY c.id DESC LIMIT 1) AS provider_name
            FROM users u
            INNER JOIN patient_registrations pr ON pr.email = u.email
            WHERE u.role = 'patient' AND {$clause}
        ";
        if ($search !== '') {
            $sql .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR pr.contact_number LIKE ?)";
            $s = '%' . $search . '%';
            $params = array_merge($params, [$s, $s, $s, $s]);
        }
        $sql .= ' ORDER BY u.last_name, u.first_name LIMIT 500';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getPatient(PDO $pdo, array $ctx, int $patientId): ?array
    {
        if (!bhw_assert_patient_in_sector($pdo, $ctx, $patientId)) {
            return null;
        }
        $stmt = $pdo->prepare("
            SELECT u.id, u.first_name, u.last_name, u.email, u.is_active,
                   pr.*
            FROM users u
            LEFT JOIN patient_registrations pr ON pr.email = u.email
            WHERE u.id = ? LIMIT 1
        ");
        $stmt->execute([$patientId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function registerPatient(PDO $pdo, array $ctx, array $data): array
    {
        patient_security_ensure_schema($pdo);

        $first    = trim($data['first_name'] ?? '');
        $middle   = trim($data['middle_name'] ?? '');
        $last     = trim($data['last_name'] ?? '');
        $suffix   = trim($data['suffix'] ?? '');
        $email    = trim($data['email'] ?? '');
        $dob      = trim($data['date_of_birth'] ?? '');
        $gender   = strtolower(trim($data['gender'] ?? ''));
        $contact  = trim($data['contact_number'] ?? '');
        $civil    = trim($data['civil_status'] ?? '');
        $address  = trim($data['address'] ?? '');
        $purok    = trim($data['purok'] ?? '');
        $blood    = trim($data['blood_type'] ?? 'Unknown');
        $conditions = trim($data['existing_conditions'] ?? '');
        $allergies  = trim($data['allergies'] ?? '');
        $medications = trim($data['medications'] ?? $data['current_medications'] ?? '');
        $ecName   = trim($data['emergency_contact_name'] ?? '');
        $ecPhone  = trim($data['emergency_contact_phone'] ?? '');
        $ecRel    = trim($data['emergency_contact_relation'] ?? '');
        $consent  = !empty($data['consent_given']);
        $bhwId    = (int) ($_SESSION['user_id'] ?? 0);
        $bhwName  = trim(($_SESSION['user_name'] ?? '') ?: (($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')));

        if ($first === '' || $last === '' || $email === '' || $dob === '' || $gender === '' || $contact === '') {
            throw new InvalidArgumentException('Required fields: first name, last name, email, date of birth, gender, and contact number.');
        }
        if (!in_array($gender, ['male', 'female'], true)) {
            throw new InvalidArgumentException('Gender must be Male or Female.');
        }
        if (!$consent) {
            throw new InvalidArgumentException('Patient consent under RA 10173 is required.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid email address.');
        }
        if (!patient_is_valid_ph_mobile($contact)) {
            throw new InvalidArgumentException('Contact number must be a valid Philippine mobile (09XXXXXXXXX).');
        }

        $dup = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $dup->execute([$email]);
        if ($dup->fetch()) {
            throw new InvalidArgumentException('Email already registered.');
        }

        $normContact = patient_normalize_phone($contact);
        $contactDup = $pdo->prepare('SELECT id FROM patient_registrations WHERE REPLACE(REPLACE(REPLACE(contact_number, " ", ""), "-", ""), "+", "") LIKE ? LIMIT 1');
        $contactDup->execute(['%' . substr($normContact, -10)]);
        if ($contactDup->fetch()) {
            throw new InvalidArgumentException('Contact number already registered.');
        }

        $dobDate = DateTime::createFromFormat('Y-m-d', $dob);
        if (!$dobDate || $dobDate->format('Y-m-d') !== $dob) {
            throw new InvalidArgumentException('Invalid date of birth.');
        }
        $age = (new DateTime())->diff($dobDate)->y;
        if ($age < 0 || $age > 120) {
            throw new InvalidArgumentException('Date of birth must represent a valid age (0–120).');
        }

        $barangay = $ctx['barangay_name'];
        $city = 'Bago City';
        $province = 'Negros Occidental';
        $region = 'Western Visayas';
        $fullName = trim(implode(' ', array_filter([$first, $middle, $last, $suffix])));
        $nationalHash = hash('sha256', 'bhw-' . $bhwId . '-' . $email . '-' . time());
        $patientCode = patient_generate_code($pdo);
        $tempPassword = patient_generate_temp_password();
        $setup = patient_generate_setup_token();
        $passwordHash = patient_hash_password($tempPassword);

        $pdo->beginTransaction();
        try {
            $pdo->prepare("
                INSERT INTO users (
                    first_name, last_name, email, password, role, is_active, is_email_verified,
                    must_change_password, account_status, password_setup_token, password_setup_expiry, created_at
                ) VALUES (?, ?, ?, ?, 'patient', 1, 0, 1, 'active', ?, ?, NOW())
            ")->execute([
                $first,
                $last,
                $email,
                $passwordHash,
                $setup['token'],
                $setup['expiry'],
            ]);
            $patientId = (int) $pdo->lastInsertId();

            $cols = bhw_pr_columns($pdo);
            $insertCols = [
                'first_name', 'last_name', 'full_name', 'email', 'date_of_birth', 'age', 'gender',
                'barangay', 'city_municipality', 'province', 'region', 'contact_number', 'blood_type', 'status',
            ];
            $insertVals = [
                $first, $last, $fullName, $email, $dob, $age, $gender,
                $barangay, $city, $province, $region, $contact, $blood, 'verified',
            ];

            $optional = [
                'middle_name'                => $middle ?: null,
                'suffix'                     => $suffix ?: null,
                'civil_status'               => $civil ?: null,
                'purok'                      => $purok ?: null,
                'full_address'               => $address ?: null,
                'address'                    => $address ?: null,
                'existing_conditions'        => $conditions ?: null,
                'allergies'                  => $allergies ?: null,
                'current_medications'        => $medications ?: null,
                'emergency_contact_name'     => $ecName ?: null,
                'emergency_contact_phone'    => $ecPhone ?: null,
                'emergency_contact_relation' => $ecRel ?: null,
                'consent_given'              => 1,
                'national_id'                => $nationalHash,
                'barangay_id'                => (int) $ctx['barangay_id'],
                'registered_by_bhw_id'       => $bhwId,
                'user_id'                    => $patientId,
                'patient_code'               => $patientCode,
            ];
            foreach ($optional as $col => $val) {
                if (in_array($col, $cols, true)) {
                    $insertCols[] = $col;
                    $insertVals[] = $val;
                }
            }

            $ph = implode(',', array_fill(0, count($insertCols), '?'));
            $pdo->prepare('INSERT INTO patient_registrations (' . implode(',', $insertCols) . ') VALUES (' . $ph . ')')
                ->execute($insertVals);

            bhw_sync_gis($pdo, $patientId, $ctx, $address !== '' ? $address . ', Brgy. ' . $barangay : null);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        require_once BASE_PATH . '/app/includes/mailer.php';
        $emailSent = false;
        if (defined('MAIL_USERNAME') && MAIL_USERNAME !== '') {
            $mailResult = sendPatientWelcomeEmail($email, $fullName, $patientCode, $setup['token']);
            $emailSent = !empty($mailResult['success']);
        }

        require_once BASE_PATH . '/app/includes/audit_log.php';
        audit_log($pdo, [
            'patient_id'  => $patientId,
            'action_type' => AuditAction::PATIENT_REGISTERED,
            'description' => 'Registered New Patient',
            'meta'        => [
                'bhw_id'       => $bhwId,
                'bhw_name'     => $bhwName,
                'patient_name' => $fullName,
                'patient_code' => $patientCode,
                'barangay'     => $barangay,
                'email'        => $email,
                'email_sent'   => $emailSent,
                'registered_at'=> date('Y-m-d H:i:s'),
            ],
        ]);
            bhw_audit($pdo, $patientId, 'bhw_patient_registered', "BHW registered patient {$fullName} ({$patientCode}) in Brgy. {$barangay}.", [
            'email' => $email,
            'patient_code' => $patientCode,
            'bhw_name' => $bhwName,
            'patient_name' => $fullName,
        ]);
        require_once __DIR__ . '/notification_events.php';
        NotificationEvents::patientRegistered($pdo, $patientId, $fullName, $bhwId);
        NotificationEvents::bhwPatientRegistered($pdo, $bhwId, $patientId, $fullName, $bhwId);

        $result = [
            'patient_id'   => $patientId,
            'patient_code' => $patientCode,
            'email'        => $email,
            'account_status' => 'Active',
            'email_sent'   => $emailSent,
        ];
        if (!$emailSent) {
            $result['temporary_password'] = $tempPassword;
            $result['password_delivery'] = 'manual';
        } else {
            $result['password_delivery'] = 'email';
        }

        return $result;
    }

    public static function updatePatient(PDO $pdo, array $ctx, int $patientId, array $data): void
    {
        if (!bhw_assert_patient_in_sector($pdo, $ctx, $patientId)) {
            throw new InvalidArgumentException('Patient not in your assigned barangay.');
        }
        $patient = self::getPatient($pdo, $ctx, $patientId);
        if (!$patient) {
            throw new InvalidArgumentException('Patient not found.');
        }

        $email = trim($data['email'] ?? $patient['email'] ?? '');
        $contact = trim($data['contact_number'] ?? '');

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('A valid email address is required.');
        }
        if ($contact === '') {
            throw new InvalidArgumentException('Contact number is required.');
        }

        $oldEmail = (string) $patient['email'];
        if (strcasecmp($email, $oldEmail) !== 0) {
            $dup = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1');
            $dup->execute([$email, $patientId]);
            if ($dup->fetch()) {
                throw new InvalidArgumentException('That email is already registered to another account.');
            }
        }

        $pdo->beginTransaction();
        try {
            if (strcasecmp($email, $oldEmail) !== 0) {
                $pdo->prepare('UPDATE users SET email = ? WHERE id = ?')->execute([$email, $patientId]);
                $pdo->prepare('UPDATE patient_registrations SET email = ?, contact_number = ? WHERE email = ?')
                    ->execute([$email, $contact, $oldEmail]);
            } else {
                $pdo->prepare('UPDATE patient_registrations SET contact_number = ? WHERE email = ?')
                    ->execute([$contact, $oldEmail]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        bhw_audit($pdo, $patientId, 'bhw_patient_updated', 'BHW updated patient email/contact.', [
            'email' => $email,
            'contact' => $contact,
        ]);
        bhw_notify($pdo, $patientId, 'system', 'Contact Updated', 'Your email or contact number was updated by your BHW.', ASSET_BASE . '/views/patient/dashboard.php#view-profile');
    }

    public static function assessTriage(string $complaint, array $symptoms): array
    {
        $symptomList = array_values(array_filter(array_map('trim', $symptoms)));
        if ($complaint === '' && $symptomList === []) {
            throw new InvalidArgumentException('Provide symptoms or chief complaint.');
        }
        return MedicalAssessmentEngine::assess($complaint, $symptomList);
    }

    public static function submitTriageAndBook(
        PDO $pdo,
        array $ctx,
        int $patientId,
        array $symptoms,
        string $complaint,
        int $slotId,
        bool $teleconsultConsent = false
    ): array {
        if (!bhw_assert_patient_in_sector($pdo, $ctx, $patientId)) {
            throw new InvalidArgumentException('Patient not in your assigned barangay.');
        }
        bhw_clinical_ensure_schema($pdo);
        triage_assessment_ensure_schema($pdo);
        consultations_auto_expire($pdo, $patientId);

        $assessment = self::assessTriage($complaint, $symptoms);
        $symptomList = array_values(array_filter(array_map('trim', $symptoms)));
        $level = (string) ($assessment['db_level'] ?? '3');
        $label = (string) ($assessment['urgency_label'] ?? 'Routine');
        $triageLevel = TriageLevelService::fromAssessment($assessment);
        $consult_type = $complaint !== '' ? $complaint : ($symptomList !== [] ? implode(', ', $symptomList) : 'General Consultation');
        $bhwId = (int) ($_SESSION['user_id'] ?? 0);

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("
                INSERT INTO triage_results
                    (patient_id, symptoms, chief_complaint, level, urgency_label, status, assessed_at,
                     confidence_score, severity, triage_level, triage_classification, english_complaint,
                     detected_symptoms_json, possible_conditions_json, recommendations,
                     assessment_payload, engine)
                VALUES (?, ?, ?, ?, ?, 'pending', NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $patientId, json_encode($symptomList), $complaint, $level, $label,
                (int) ($assessment['confidence']['score'] ?? 0),
                (string) ($assessment['severity']['severity'] ?? ''),
                $triageLevel,
                (string) ($assessment['triage']['triage_classification'] ?? ''),
                (string) ($assessment['english_translation'] ?? ''),
                json_encode($assessment['detected_symptoms'] ?? [], JSON_UNESCAPED_UNICODE),
                json_encode($assessment['possible_conditions'] ?? [], JSON_UNESCAPED_UNICODE),
                implode("\n", $assessment['recommendations'] ?? []),
                json_encode($assessment, JSON_UNESCAPED_UNICODE),
                (string) ($assessment['engine'] ?? MedicalAssessmentEngine::VERSION),
            ]);
            $triageResultId = (int) $pdo->lastInsertId();

            if (bhw_triage_is_emergency($assessment)) {
                $pdo->prepare("UPDATE triage_results SET outcome = 'emergency_referral', status = 'completed' WHERE id = ?")
                    ->execute([$triageResultId]);

                $reason = bhw_triage_emergency_reason($assessment, $complaint, $symptomList);
                $providerId = self::resolveProviderForPatient($pdo, $patientId);
                $destCol = self::referralDestColumn($pdo);
                $pdo->prepare("
                    INSERT INTO digital_referrals (patient_id, provider_id, referral_type, reason, {$destCol}, status, created_at)
                    VALUES (?, ?, 'Hospital', ?, 'Nearest hospital / ER — emergency triage', 'pending', NOW())
                ")->execute([$patientId, $providerId, $reason]);
                $referralId = (int) $pdo->lastInsertId();

                $pdo->commit();

                bhw_audit($pdo, $patientId, 'bhw_emergency_referral', "Emergency triage — referral #{$referralId} created (no teleconsult).", [
                    'triage_id' => $triageResultId,
                    'level' => $level,
                    'label' => $label,
                ]);

                require_once __DIR__ . '/notification_events.php';
                $pstmt = $pdo->prepare('SELECT CONCAT(first_name, " ", last_name) FROM users WHERE id = ? LIMIT 1');
                $pstmt->execute([$patientId]);
                $pName = (string) ($pstmt->fetchColumn() ?: 'Patient');
                NotificationEvents::highRiskPatient($pdo, $patientId, $pName, $label, $bhwId);
                NotificationEvents::referralCreated($pdo, $referralId, $patientId, $providerId > 0 ? $providerId : null, $bhwId);
                NotificationEvents::aiTriageCompleted($pdo, $patientId, $label, $bhwId);

                return [
                    'emergency'     => true,
                    'referral_id'   => $referralId,
                    'triage_id'     => $triageResultId,
                    'level'         => $level,
                    'label'         => $label,
                    'message'       => 'Emergency triage detected. Patient referred to hospital — teleconsult booking skipped.',
                    'redirect'      => ASSET_BASE . '/views/bhw/referral/status.php',
                    'assessment'    => $assessment,
                ];
            }

            if (!$teleconsultConsent) {
                throw new InvalidArgumentException('Teleconsult consent is required before booking a video consultation.');
            }

            if ($slotId <= 0) {
                throw new InvalidArgumentException('Select an appointment slot.');
            }

            $slot_stmt = $pdo->prepare("
                SELECT s.id, s.provider_id, s.slot_date, s.start_time, s.end_time, s.status,
                       CONCAT(u.first_name, ' ', u.last_name) AS provider_name
                FROM appointment_slots s
                JOIN users u ON u.id = s.provider_id
                WHERE s.id = ? LIMIT 1 FOR UPDATE
            ");
            $slot_stmt->execute([$slotId]);
            $slot = $slot_stmt->fetch(PDO::FETCH_ASSOC);
            if (!$slot || $slot['status'] !== 'available') {
                throw new RuntimeException('Appointment slot unavailable.');
            }
            if (!appointment_slot_is_bookable_bhw((string) $slot['slot_date'], (string) $slot['start_time'], (string) $slot['end_time'])) {
                throw new RuntimeException('Selected slot is no longer bookable. Choose a future date and time.');
            }

            $provider_id = (int) $slot['provider_id'];
            $consult_date = (string) $slot['slot_date'];
            $consult_time = (string) $slot['start_time'];
            $provider_name = (string) $slot['provider_name'];

            $consultCols = $pdo->query('SHOW COLUMNS FROM consultations')->fetchAll(PDO::FETCH_COLUMN);
            $hasConsentCols = in_array('teleconsult_consent', $consultCols, true);

            if ($hasConsentCols) {
                $ins = $pdo->prepare("
                    INSERT INTO consultations
                        (patient_id, provider_id, provider_name, consult_type, consult_date, consult_time, status,
                         teleconsult_consent, teleconsult_consent_at, teleconsult_consent_by, booked_by_bhw_id, triage_result_id, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, 'scheduled', 1, NOW(), ?, ?, ?, NOW())
                ");
                $ins->execute([
                    $patientId, $provider_id, $provider_name, $consult_type, $consult_date, $consult_time,
                    $bhwId, $bhwId, $triageResultId,
                ]);
            } else {
                $ins = $pdo->prepare("
                    INSERT INTO consultations (patient_id, provider_id, provider_name, consult_type, consult_date, consult_time, status, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, 'scheduled', NOW())
                ");
                $ins->execute([$patientId, $provider_id, $provider_name, $consult_type, $consult_date, $consult_time]);
            }
            $consultation_id = (int) $pdo->lastInsertId();

            $pdo->prepare("UPDATE appointment_slots SET status = 'booked', patient_id = ?, consultation_id = ? WHERE id = ? AND status = 'available'")
                ->execute([$patientId, $consultation_id, $slotId]);

            $pdo->prepare("UPDATE triage_results SET outcome = 'consultation_booked', status = 'accepted' WHERE id = ?")
                ->execute([$triageResultId]);

            $pdo->commit();

            bhw_audit($pdo, $patientId, 'bhw_triage_submitted', "BHW submitted triage and booked consultation #{$consultation_id} with teleconsult consent.", [
                'level' => $level,
                'slot_id' => $slotId,
                'teleconsult_consent' => true,
                'triage_id' => $triageResultId,
            ]);

            require_once __DIR__ . '/notification_events.php';
            $when = bhw_format_slot_label($consult_date, $consult_time);
            NotificationEvents::appointmentCreated($pdo, $consultation_id, $patientId, $provider_id, $when, $bhwId);
            NotificationEvents::aiTriageCompleted($pdo, $patientId, $label, $bhwId);

            return [
                'emergency'        => false,
                'consultation_id'  => $consultation_id,
                'triage_id'        => $triageResultId,
                'level'            => $level,
                'label'            => $label,
                'provider_name'    => $provider_name,
                'consult_time'     => $consult_time,
                'consult_date'     => $consult_date,
                'assessment'       => $assessment,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function listConsultations(PDO $pdo, array $ctx, ?string $date = null, ?string $status = null): array
    {
        [$clause, $params] = bhw_patient_sector_clause($pdo, $ctx, 'pr');
        $date = $date ?: date('Y-m-d');
        $sql = "
            SELECT c.*, CONCAT(p.first_name,' ',p.last_name) AS patient_name,
                   CONCAT(prv.first_name,' ',prv.last_name) AS provider_name,
                   vs.room_token, vs.status AS video_status
            FROM consultations c
            JOIN users p ON p.id = c.patient_id
            JOIN patient_registrations pr ON pr.email = p.email
            LEFT JOIN users prv ON prv.id = c.provider_id
            LEFT JOIN video_sessions vs ON vs.consultation_id = c.id AND vs.status = 'active'
            WHERE {$clause} AND c.consult_date = ?
        ";
        if ($status !== null && $status !== '') {
            $sql .= ' AND c.status = ?';
            $params = array_merge($params, [$date, $status]);
        } else {
            $params = array_merge($params, [$date]);
        }
        $sql .= ' ORDER BY c.consult_time ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function listReferrals(PDO $pdo, array $ctx): array
    {
        [$clause, $params] = bhw_patient_sector_clause($pdo, $ctx, 'pr');
        $dest = self::referralDestColumn($pdo);
        $sql = "
            SELECT dr.*, CONCAT(p.first_name,' ',p.last_name) AS patient_name,
                   COALESCE(dr.{$dest}, '') AS facility_display
            FROM digital_referrals dr
            JOIN users p ON p.id = dr.patient_id
            JOIN patient_registrations pr ON pr.email = p.email
            WHERE {$clause}
            ORDER BY dr.created_at DESC LIMIT 200
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function createReferral(PDO $pdo, array $ctx, int $patientId, string $type, string $reason, ?int $facilityId, ?string $facilityName): int
    {
        if (!bhw_assert_patient_in_sector($pdo, $ctx, $patientId)) {
            throw new InvalidArgumentException('Patient not in your barangay.');
        }
        $providerId = self::resolveProviderForPatient($pdo, $patientId);
        $destCol = self::referralDestColumn($pdo);
        if ($facilityId > 0) {
            $fs = $pdo->prepare('SELECT facility_name FROM facilities WHERE id = ? AND status = \'active\' LIMIT 1');
            $fs->execute([$facilityId]);
            $facilityName = (string) ($fs->fetchColumn() ?: $facilityName);
        }
        $sql = "INSERT INTO digital_referrals (patient_id, provider_id, referral_type, reason, {$destCol}, status, created_at) VALUES (?, ?, ?, ?, ?, 'pending', NOW())";
        $pdo->prepare($sql)->execute([$patientId, $providerId, $type, $reason, $facilityName ?: null]);
        $id = (int) $pdo->lastInsertId();
        bhw_audit($pdo, $patientId, 'bhw_referral_created', "BHW created referral #{$id}.", ['type' => $type]);
        require_once __DIR__ . '/notification_events.php';
        NotificationEvents::referralCreated($pdo, $id, $patientId, $providerId > 0 ? $providerId : null, (int) ($_SESSION['user_id'] ?? 0));
        return $id;
    }

    public static function listFollowups(PDO $pdo, array $ctx, ?string $status = null): array
    {
        bhw_clinical_ensure_schema($pdo);
        [$clause, $params] = bhw_patient_sector_clause($pdo, $ctx, 'pr');
        $sql = "
            SELECT f.*, CONCAT(p.first_name,' ',p.last_name) AS patient_name,
                   (SELECT COUNT(*) FROM bhw_home_visits hv WHERE hv.followup_id = f.id) AS home_visit_count,
                   (SELECT MAX(hv.visit_date) FROM bhw_home_visits hv WHERE hv.followup_id = f.id) AS last_home_visit
            FROM followups f
            JOIN users p ON p.id = f.patient_id
            JOIN patient_registrations pr ON pr.email = p.email
            WHERE {$clause}
        ";
        if ($status === 'upcoming') {
            $sql .= " AND f.status = 'scheduled' AND f.followup_date >= CURDATE()";
        } elseif ($status === 'missed') {
            $sql .= " AND f.status IN ('scheduled','missed') AND f.followup_date < CURDATE()";
        } elseif ($status === 'completed') {
            $sql .= " AND f.status = 'completed'";
        }
        $sql .= ' ORDER BY f.followup_date ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function sendFollowupReminder(PDO $pdo, array $ctx, int $followupId): void
    {
        $stmt = $pdo->prepare('SELECT f.*, p.id AS pid FROM followups f JOIN users p ON p.id = f.patient_id WHERE f.id = ? LIMIT 1');
        $stmt->execute([$followupId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || !bhw_assert_patient_in_sector($pdo, $ctx, (int) $row['pid'])) {
            throw new InvalidArgumentException('Follow-up not found in your sector.');
        }
        bhw_notify($pdo, (int) $row['patient_id'], 'followup', 'Follow-Up Reminder',
            'Reminder: follow-up on ' . date('M j, Y', strtotime($row['followup_date'])) . '.',
            ASSET_BASE . '/views/patient/followup.php');
        bhw_audit($pdo, (int) $row['patient_id'], 'bhw_followup_reminder', 'BHW sent follow-up reminder.', ['followup_id' => $followupId]);
    }

    public static function logHomeVisit(
        PDO $pdo,
        array $ctx,
        int $patientId,
        ?int $followupId,
        string $visitDate,
        string $visitType,
        string $patientStatus,
        string $notes = ''
    ): int {
        bhw_clinical_ensure_schema($pdo);
        if (!bhw_assert_patient_in_sector($pdo, $ctx, $patientId)) {
            throw new InvalidArgumentException('Patient not in your barangay.');
        }

        $bhwId = (int) ($_SESSION['user_id'] ?? 0);
        $visitDateObj = DateTime::createFromFormat('Y-m-d', $visitDate);
        if (!$visitDateObj || $visitDateObj->format('Y-m-d') !== $visitDate) {
            throw new InvalidArgumentException('Invalid visit date.');
        }

        $allowedTypes = ['follow_up', 'monitoring', 'emergency_check', 'other'];
        if (!in_array($visitType, $allowedTypes, true)) {
            $visitType = 'follow_up';
        }

        $allowedStatus = ['improving', 'stable', 'worsening', 'referred', 'unknown'];
        if (!in_array($patientStatus, $allowedStatus, true)) {
            $patientStatus = 'stable';
        }

        if ($followupId > 0) {
            $chk = $pdo->prepare('SELECT f.id, f.patient_id FROM followups f WHERE f.id = ? LIMIT 1');
            $chk->execute([$followupId]);
            $fu = $chk->fetch(PDO::FETCH_ASSOC);
            if (!$fu || (int) $fu['patient_id'] !== $patientId) {
                throw new InvalidArgumentException('Follow-up not found for this patient.');
            }
        } else {
            $followupId = null;
        }

        $stmt = $pdo->prepare("
            INSERT INTO bhw_home_visits (followup_id, patient_id, bhw_id, visit_date, visit_type, notes, patient_status)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $followupId,
            $patientId,
            $bhwId,
            $visitDate,
            $visitType,
            $notes !== '' ? $notes : null,
            $patientStatus,
        ]);
        $visitId = (int) $pdo->lastInsertId();

        if ($followupId) {
            $pdo->prepare("UPDATE followups SET status = 'completed' WHERE id = ? AND status = 'scheduled'")
                ->execute([$followupId]);
        }

        bhw_audit($pdo, $patientId, 'bhw_home_visit_logged', 'BHW logged home visit.', [
            'visit_id' => $visitId,
            'followup_id' => $followupId,
            'visit_type' => $visitType,
            'patient_status' => $patientStatus,
        ]);

        bhw_notify($pdo, $patientId, 'followup', 'Home Visit Completed',
            'Your BHW completed a home follow-up visit on ' . date('M j, Y', strtotime($visitDate)) . '.',
            ASSET_BASE . '/views/patient/followup.php');

        return $visitId;
    }

    public static function listHomeVisits(PDO $pdo, array $ctx, ?int $patientId = null): array
    {
        bhw_clinical_ensure_schema($pdo);
        [$clause, $params] = bhw_patient_sector_clause($pdo, $ctx, 'pr');
        $sql = "
            SELECT hv.*, CONCAT(p.first_name,' ',p.last_name) AS patient_name,
                   CONCAT(b.first_name,' ',b.last_name) AS bhw_name
            FROM bhw_home_visits hv
            JOIN users p ON p.id = hv.patient_id
            JOIN patient_registrations pr ON pr.email = p.email
            JOIN users b ON b.id = hv.bhw_id
            WHERE {$clause}
        ";
        if ($patientId > 0) {
            $sql .= ' AND hv.patient_id = ?';
            $params[] = $patientId;
        }
        $sql .= ' ORDER BY hv.visit_date DESC, hv.id DESC LIMIT 200';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getDashboardMetrics(PDO $pdo, array $ctx): array
    {
        [$clause, $params] = bhw_patient_sector_clause($pdo, $ctx, 'pr');
        $metrics = [
            'total_households' => 0,
            'pending_triage' => 0,
            'scheduled_calls' => 0,
            'high_risk_flags' => 0,
        ];
        $q = $pdo->prepare("SELECT COUNT(*) FROM patient_registrations pr WHERE {$clause}");
        $q->execute($params);
        $metrics['total_households'] = (int) $q->fetchColumn();

        $tq = $pdo->prepare("
            SELECT COUNT(*) FROM triage_results tr
            JOIN users u ON u.id = tr.patient_id
            JOIN patient_registrations pr ON pr.email = u.email
            WHERE {$clause} AND tr.status = 'pending'
        ");
        $tq->execute($params);
        $metrics['pending_triage'] = (int) $tq->fetchColumn();

        $hq = $pdo->prepare("
            SELECT COUNT(*) FROM triage_results tr
            JOIN users u ON u.id = tr.patient_id
            JOIN patient_registrations pr ON pr.email = u.email
            WHERE {$clause} AND (tr.level IN ('1','2') OR LOWER(tr.urgency_label) LIKE '%urgent%' OR LOWER(tr.urgency_label) = 'high')
        ");
        $hq->execute($params);
        $metrics['high_risk_flags'] = (int) $hq->fetchColumn();

        $cq = $pdo->prepare("
            SELECT COUNT(*) FROM consultations c
            JOIN users u ON u.id = c.patient_id
            JOIN patient_registrations pr ON pr.email = u.email
            WHERE {$clause} AND c.consult_date = CURDATE()
        ");
        $cq->execute($params);
        $metrics['scheduled_calls'] = (int) $cq->fetchColumn();

        return $metrics;
    }

    public static function getTriageQueue(PDO $pdo, array $ctx, int $limit = 15): array
    {
        [$clause, $params] = bhw_patient_sector_clause($pdo, $ctx, 'pr');
        $sql = "
            SELECT p.id AS patient_id, p.first_name, p.last_name, pr.barangay AS purok,
                   tr.urgency_label, tr.status, tr.id AS triage_id
            FROM triage_results tr
            JOIN users p ON p.id = tr.patient_id
            JOIN patient_registrations pr ON pr.email = p.email
            WHERE {$clause}
            ORDER BY CASE WHEN LOWER(tr.urgency_label) IN ('high', 'urgent') THEN 1
                          WHEN LOWER(tr.urgency_label) = 'moderate' THEN 2 ELSE 3 END,
                     tr.assessed_at DESC
            LIMIT " . (int) $limit;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function referralDestColumn(PDO $pdo): string
    {
        return $pdo->query("SHOW COLUMNS FROM digital_referrals LIKE 'facility_name'")->fetch()
            ? 'facility_name' : 'destination_facility';
    }

    private static function resolveProviderForPatient(PDO $pdo, int $patientId): int
    {
        $s = $pdo->prepare("SELECT provider_id FROM consultations WHERE patient_id = ? ORDER BY id DESC LIMIT 1");
        $s->execute([$patientId]);
        $id = (int) ($s->fetchColumn() ?: 0);
        if ($id > 0) {
            return $id;
        }
        return (int) ($pdo->query("SELECT id FROM users WHERE role = 'provider' AND is_active = 1 ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 0);
    }
}
