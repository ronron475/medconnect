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
require_once __DIR__ . '/bhw_patient_workflow.php';
require_once dirname(__DIR__) . '/core/TriageLevelService.php';
require_once dirname(__DIR__) . '/core/MedicalAssessmentEngine.php';

final class BhwWorkflows
{
    public static function listPatients(PDO $pdo, array $ctx, string $search = ''): array
    {
        [$clause, $params] = bhw_patient_sector_clause($pdo, $ctx, 'pr');
        $join = bhw_pr_user_join('pr', 'u');
        $sql = "
            SELECT u.id, u.first_name, u.last_name, u.email, u.is_active, u.created_at,
                   pr.contact_number, pr.barangay, pr.purok, pr.age, pr.gender, pr.patient_code,
                   COALESCE(NULLIF(pr.workflow_status, ''), 'registered') AS workflow_status,
                   (SELECT MAX(c.consult_date) FROM consultations c WHERE c.patient_id = u.id) AS last_consult,
                   (SELECT tr.urgency_label FROM triage_results tr WHERE tr.patient_id = u.id ORDER BY tr.assessed_at DESC LIMIT 1) AS risk_level,
                   (SELECT CONCAT(prv.first_name, ' ', prv.last_name) FROM consultations c
                    JOIN users prv ON prv.id = c.provider_id WHERE c.patient_id = u.id ORDER BY c.id DESC LIMIT 1) AS provider_name
            FROM users u
            INNER JOIN patient_registrations pr ON {$join}
            WHERE u.role = 'patient' AND {$clause}
        ";
        if ($search !== '') {
            $sql .= " AND (
                u.first_name LIKE ?
                OR u.last_name LIKE ?
                OR CONCAT(u.first_name, ' ', u.last_name) LIKE ?
                OR u.email LIKE ?
                OR pr.contact_number LIKE ?
                OR pr.patient_code LIKE ?
            )";
            $s = '%' . $search . '%';
            $params = array_merge($params, [$s, $s, $s, $s, $s, $s]);
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
        $join = bhw_pr_user_join('pr', 'u');
        // pr.* is selected first so the trailing user columns win the name clash on
        // `id` — callers pass a user id and must get the same id back.
        $stmt = $pdo->prepare("
            SELECT pr.*, pr.id AS registration_id,
                   u.id, u.first_name, u.last_name, u.email, u.is_active
            FROM users u
            LEFT JOIN patient_registrations pr ON {$join}
            WHERE u.id = ? LIMIT 1
        ");
        $stmt->execute([$patientId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function registerPatient(PDO $pdo, array $ctx, array $data): array
    {
        unset($pdo, $ctx, $data);
        throw new RuntimeException('BHWs cannot register new patients. Patients must complete the main registration flow.');
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

        // BHW cannot reassign patient barangay (Admin/Superadmin only).
        unset($data['barangay'], $data['barangay_id']);

        $email = trim($data['email'] ?? $patient['email'] ?? '');
        $contact = trim($data['contact_number'] ?? '');
        $blood = trim($data['blood_type'] ?? $patient['blood_type'] ?? 'Unknown');
        $conditions = trim($data['existing_conditions'] ?? '');
        $allergies = trim($data['allergies'] ?? '');
        $medications = trim($data['medications'] ?? $data['current_medications'] ?? '');

        require_once __DIR__ . '/contact_validation.php';
        $email = mc_normalize_email($email);
        if ($emailErr = mc_email_validation_error($email, true)) {
            throw new InvalidArgumentException($emailErr);
        }
        if ($phoneErr = mc_phone_validation_error($contact, true)) {
            throw new InvalidArgumentException($phoneErr);
        }
        $contact = mc_canonical_ph_mobile($contact);

        $oldEmail = (string) $patient['email'];
        if (strcasecmp($email, $oldEmail) !== 0) {
            if (mc_users_email_exists($pdo, $email, $patientId)) {
                throw new InvalidArgumentException(MC_MSG_EMAIL_DUP);
            }
        }

        $bhwId = (int) ($_SESSION['user_id'] ?? 0);
        if ($bhwId <= 0) {
            throw new InvalidArgumentException('BHW session required.');
        }

        require_once __DIR__ . '/patient_settings.php';
        patient_settings_ensure_schema($pdo);

        $pdo->beginTransaction();
        try {
            if (strcasecmp($email, $oldEmail) !== 0) {
                $pdo->prepare('UPDATE users SET email = ? WHERE id = ?')->execute([$email, $patientId]);
                $pdo->prepare('UPDATE patient_registrations SET email = ?, contact_number = ?, blood_type = ?, existing_conditions = ?, allergies = ?, current_medications = ?, medical_profile_updated_at = NOW(), medical_profile_updated_by = ? WHERE email = ? OR user_id = ?')
                    ->execute([$email, $contact, $blood ?: 'Unknown', $conditions ?: null, $allergies ?: null, $medications ?: null, $bhwId, $oldEmail, $patientId]);
            } else {
                $pdo->prepare('UPDATE patient_registrations SET contact_number = ?, blood_type = ?, existing_conditions = ?, allergies = ?, current_medications = ?, medical_profile_updated_at = NOW(), medical_profile_updated_by = ? WHERE email = ? OR user_id = ?')
                    ->execute([$contact, $blood ?: 'Unknown', $conditions ?: null, $allergies ?: null, $medications ?: null, $bhwId, $oldEmail, $patientId]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        bhw_audit($pdo, $patientId, 'bhw_patient_updated', 'BHW updated patient contact and medical profile.', [
            'email' => $email,
            'contact' => $contact,
            'blood_type' => $blood,
            'bhw_id' => $bhwId,
        ]);
        bhw_notify($pdo, $patientId, 'system', 'Profile Updated', 'Your contact or medical information was updated by your BHW.', ASSET_BASE . '/views/patient/profile.php');

        require_once __DIR__ . '/notification_events.php';
        NotificationEvents::bhwPatientProfileUpdated($pdo, $patientId, $bhwId);
    }

    public static function assessTriage(string $complaint, array $symptoms = []): array
    {
        if (trim($complaint) === '') {
            throw new InvalidArgumentException('Describe the patient\'s health concern.');
        }
        require_once __DIR__ . '/bhw_triage_nlp.php';
        $pipeline = bhw_run_chief_complaint_nlp($complaint);
        return bhw_map_nlp_pipeline_to_assessment($pipeline, $complaint);
    }

    /**
     * @return array{assessment: array<string, mixed>, pipeline: array<string, mixed>}
     */
    public static function assessTriageWithPipeline(string $complaint, int $patientId = 0): array
    {
        if (trim($complaint) === '') {
            throw new InvalidArgumentException('Describe the patient\'s health concern.');
        }
        if ($patientId <= 0) {
            throw new InvalidArgumentException('Select a patient before running triage.');
        }
        require_once __DIR__ . '/bhw_triage_nlp.php';
        $pipeline = bhw_run_chief_complaint_nlp($complaint);
        $assessment = bhw_map_nlp_pipeline_to_assessment($pipeline, $complaint);

        return [
            'assessment' => $assessment,
            'pipeline'   => bhw_format_pipeline_for_ui($pipeline),
            'routing'    => bhw_triage_routing_meta($assessment),
            'assessment_token' => self::storeAssessmentSession($complaint, $assessment, $patientId),
        ];
    }

    public static function storeAssessmentSession(string $complaint, array $assessment, int $patientId = 0): string
    {
        $token = bin2hex(random_bytes(16));
        if (!isset($_SESSION['bhw_triage_tokens']) || !is_array($_SESSION['bhw_triage_tokens'])) {
            $_SESSION['bhw_triage_tokens'] = [];
        }
        $_SESSION['bhw_triage_tokens'][$token] = [
            'patient_id' => $patientId,
            'complaint'  => trim($complaint),
            'assessment' => $assessment,
            'tier'       => bhw_triage_resolve_tier($assessment),
            'expires'    => time() + 900,
        ];

        return $token;
    }

    public static function consumeAssessmentSession(string $token, string $complaint, int $patientId = 0): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }
        $store = $_SESSION['bhw_triage_tokens'] ?? [];
        if (!is_array($store) || !isset($store[$token])) {
            return null;
        }
        $entry = $store[$token];
        unset($_SESSION['bhw_triage_tokens'][$token]);
        if ((int) ($entry['expires'] ?? 0) < time()) {
            return null;
        }
        if (trim($complaint) !== trim((string) ($entry['complaint'] ?? ''))) {
            return null;
        }
        if ($patientId > 0 && (int) ($entry['patient_id'] ?? 0) !== $patientId) {
            return null;
        }

        return is_array($entry['assessment'] ?? null) ? $entry['assessment'] : null;
    }

    public static function submitTriageAndBook(
        PDO $pdo,
        array $ctx,
        int $patientId,
        array $symptoms,
        string $complaint,
        int $slotId,
        bool $teleconsultConsent = false,
        string $assessmentToken = ''
    ): array {
        if (!bhw_assert_patient_in_sector($pdo, $ctx, $patientId)) {
            throw new InvalidArgumentException('Patient not in your assigned barangay.');
        }
        bhw_clinical_ensure_schema($pdo);
        BhwPatientWorkflow::ensure_schema($pdo);
        triage_assessment_ensure_schema($pdo);
        consultations_auto_expire($pdo, $patientId);

        BhwPatientWorkflow::setStatus($pdo, $patientId, BhwPatientWorkflow::AI_PROCESSING, [
            'action' => 'triage_submit',
        ]);

        $assessment = self::consumeAssessmentSession($assessmentToken, $complaint, $patientId);
        if ($assessment === null) {
            $assessment = self::assessTriage($complaint);
        }

        $routing = bhw_triage_routing_meta($assessment);
        $triageTier = (string) $routing['tier'];
        $symptomList = array_values(array_filter(array_map('trim', (array) ($assessment['detected_symptoms'] ?? []))));
        $level = (string) ($assessment['db_level'] ?? '3');
        $label = (string) ($assessment['urgency_label'] ?? 'Routine');
        $consult_type = $complaint !== '' ? $complaint : ($symptomList !== [] ? implode(', ', $symptomList) : 'General Consultation');
        $bhwId = (int) ($_SESSION['user_id'] ?? 0);

        bhw_audit($pdo, $patientId, 'bhw_ai_classification', 'AI triage classification recorded (BHW cannot override).', [
            'tier'           => $triageTier,
            'urgency_label'  => $label,
            'db_level'       => $level,
            'routing_mode'   => $routing['mode'],
            'chief_complaint'=> $complaint,
        ]);

        BhwPatientWorkflow::setStatus($pdo, $patientId, BhwPatientWorkflow::fromTriageTier($triageTier), [
            'triage_tier' => $triageTier,
        ]);

        $pdo->beginTransaction();
        try {
            $triageCols = [];
            try {
                $triageCols = $pdo->query('SHOW COLUMNS FROM triage_results')->fetchAll(PDO::FETCH_COLUMN) ?: [];
            } catch (PDOException $e) {
                $triageCols = [];
            }
            $triageInsertCols = [
                'patient_id', 'symptoms', 'chief_complaint', 'level', 'urgency_label', 'status', 'assessed_at',
                'confidence_score', 'severity', 'triage_level', 'triage_classification', 'english_complaint',
                'detected_symptoms_json', 'possible_conditions_json', 'recommendations',
                'assessment_payload', 'engine',
            ];
            $triageInsertVals = [
                $patientId, json_encode($symptomList), $complaint, $level, $label, 'pending', date('Y-m-d H:i:s'),
                (int) ($assessment['confidence']['score'] ?? 0),
                (string) ($assessment['severity']['severity'] ?? ''),
                $triageTier,
                (string) ($assessment['triage']['triage_classification'] ?? ''),
                (string) ($assessment['english_translation'] ?? ''),
                json_encode($assessment['detected_symptoms'] ?? [], JSON_UNESCAPED_UNICODE),
                json_encode($assessment['possible_conditions'] ?? [], JSON_UNESCAPED_UNICODE),
                implode("\n", $assessment['recommendations'] ?? []),
                json_encode($assessment, JSON_UNESCAPED_UNICODE),
                (string) ($assessment['engine'] ?? MedicalAssessmentEngine::VERSION),
            ];
            if (in_array('barangay_id', $triageCols, true) && (int) ($ctx['barangay_id'] ?? 0) > 0) {
                $triageInsertCols[] = 'barangay_id';
                $triageInsertVals[] = (int) $ctx['barangay_id'];
            }
            $triagePh = implode(',', array_fill(0, count($triageInsertCols), '?'));
            $pdo->prepare(
                'INSERT INTO triage_results (' . implode(',', $triageInsertCols) . ') VALUES (' . $triagePh . ')'
            )->execute($triageInsertVals);
            $triageResultId = (int) $pdo->lastInsertId();

            $recText = implode("\n", $assessment['recommendations'] ?? []);
            $recStatus = triage_recommendation_status_for_insert(
                (string) $triageTier,
                $complaint,
                $recText,
                (string) ($assessment['triage']['triage_classification'] ?? '')
            );
            $pdo->prepare('UPDATE triage_results SET recommendation_status = ? WHERE id = ?')
                ->execute([$recStatus, $triageResultId]);

            if ($triageTier === TriageLevelService::EMERGENCY) {
                $pdo->prepare("UPDATE triage_results SET outcome = 'emergency_referral', status = 'completed' WHERE id = ?")
                    ->execute([$triageResultId]);

                $reason = bhw_triage_emergency_reason($assessment, $complaint, $symptomList);
                $providerId = self::resolveProviderForPatient($pdo, $patientId);
                $referralId = 0;
                if ($providerId > 0) {
                    $destCol = self::referralDestColumn($pdo);
                    // Auto emergency referral is issued immediately — not awaiting acceptance.
                    $pdo->prepare("
                        INSERT INTO digital_referrals (patient_id, provider_id, referral_type, reason, {$destCol}, status, created_at)
                        VALUES (?, ?, 'Hospital', ?, 'Nearest hospital / ER — emergency triage', 'completed', NOW())
                    ")->execute([$patientId, $providerId, $reason]);
                    $referralId = (int) $pdo->lastInsertId();
                }

                $pdo->commit();

                BhwPatientWorkflow::setStatus($pdo, $patientId, BhwPatientWorkflow::REFERRAL_GENERATED, [
                    'referral_id' => $referralId,
                    'triage_id'   => $triageResultId,
                ]);

                bhw_audit($pdo, $patientId, 'bhw_emergency_referral', $referralId > 0
                    ? "Emergency triage — referral #{$referralId} created (no teleconsult)."
                    : 'Emergency triage recorded (no teleconsult); no active provider for digital referral.', [
                    'triage_id' => $triageResultId,
                    'level' => $level,
                    'label' => $label,
                    'referral_id' => $referralId,
                ]);

                require_once __DIR__ . '/notification_events.php';
                $pstmt = $pdo->prepare('SELECT CONCAT(first_name, " ", last_name) FROM users WHERE id = ? LIMIT 1');
                $pstmt->execute([$patientId]);
                $pName = (string) ($pstmt->fetchColumn() ?: 'Patient');
                // highRiskPatient only — aiTriageCompleted would duplicate the emergency alert.
                NotificationEvents::highRiskPatient($pdo, $patientId, $pName, $label, $bhwId);
                if ($referralId > 0) {
                    NotificationEvents::referralCreated($pdo, $referralId, $patientId, $providerId, $bhwId);
                }

                $msg = 'Emergency triage detected. Teleconsult booking skipped — direct patient to the nearest hospital / ER.';
                if ($referralId > 0) {
                    $msg = 'Emergency triage detected. Patient referred to hospital — teleconsult booking skipped.';
                } elseif ($providerId <= 0) {
                    $msg .= ' No active provider was available to attach a digital referral.';
                }

                return [
                    'emergency'     => true,
                    'triage_tier'   => $triageTier,
                    'routing'       => $routing,
                    'referral_id'   => $referralId,
                    'triage_id'     => $triageResultId,
                    'level'         => $level,
                    'label'         => $label,
                    'message'       => $msg,
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
            require_once __DIR__ . '/triage_provider_assignment.php';
            require_once __DIR__ . '/notification_events.php';
            if ($recStatus === 'pending_approval' && $triageTier === TriageLevelService::NON_URGENT) {
                triage_assert_patient_may_book_provider($pdo, $patientId, $provider_id);
            }
            if ($triageTier !== TriageLevelService::EMERGENCY) {
                triage_bind_assigned_provider($pdo, $triageResultId, $provider_id);
            }
            if ($recStatus === 'pending_approval' && $triageTier === TriageLevelService::NON_URGENT && $provider_id > 0) {
                $pstmt = $pdo->prepare('SELECT CONCAT(first_name, " ", last_name) FROM users WHERE id = ? LIMIT 1');
                $pstmt->execute([$patientId]);
                $pName = trim((string) ($pstmt->fetchColumn() ?: 'Patient'));
                NotificationEvents::aiSelfCareReviewRequired($pdo, $provider_id, $patientId, $pName, $triageResultId, $bhwId);
            }

            $consult_date = (string) $slot['slot_date'];
            $consult_time = (string) $slot['start_time'];
            $provider_name = (string) $slot['provider_name'];

            $consultPriority = $triageTier === TriageLevelService::URGENT ? 'urgent' : 'standard';
            $consultCols = $pdo->query('SHOW COLUMNS FROM consultations')->fetchAll(PDO::FETCH_COLUMN);
            $hasConsentCols = in_array('teleconsult_consent', $consultCols, true);
            $hasPriorityCol = in_array('consult_priority', $consultCols, true);

            if ($hasConsentCols && $hasPriorityCol) {
                $ins = $pdo->prepare("
                    INSERT INTO consultations
                        (patient_id, provider_id, provider_name, consult_type, consult_date, consult_time, status,
                         consult_priority, teleconsult_consent, teleconsult_consent_at, teleconsult_consent_by,
                         booked_by_bhw_id, triage_result_id, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, 'scheduled', ?, 1, NOW(), ?, ?, ?, NOW())
                ");
                $ins->execute([
                    $patientId, $provider_id, $provider_name, $consult_type, $consult_date, $consult_time,
                    $consultPriority, $bhwId, $bhwId, $triageResultId,
                ]);
            } elseif ($hasConsentCols) {
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

            try {
                require_once __DIR__ . '/consultation_recorded_data.php';
                consultation_recorded_data_attach_pending_to_consultation($pdo, $patientId, $consultation_id);
                consultation_recorded_data_snapshot_from_triage(
                    $pdo,
                    $patientId,
                    $consultation_id,
                    $triageResultId,
                    $bhwId,
                    'bhw'
                );
            } catch (Throwable $e) {
                error_log('bhw recorded_data snapshot: ' . $e->getMessage());
            }

            BhwPatientWorkflow::setStatus($pdo, $patientId, BhwPatientWorkflow::APPOINTMENT_SCHEDULED, [
                'consultation_id' => $consultation_id,
                'triage_id'       => $triageResultId,
                'priority'        => $consultPriority,
            ]);

            bhw_audit($pdo, $patientId, 'bhw_triage_submitted', "BHW submitted triage and booked consultation #{$consultation_id} with teleconsult consent.", [
                'level' => $level,
                'tier'  => $triageTier,
                'priority' => $consultPriority,
                'slot_id' => $slotId,
                'teleconsult_consent' => true,
                'triage_id' => $triageResultId,
            ]);

            require_once __DIR__ . '/notification_events.php';
            $when = bhw_format_slot_label($consult_date, $consult_time);
            NotificationEvents::appointmentCreated($pdo, $consultation_id, $patientId, $provider_id, $when, $bhwId);
            NotificationEvents::aiTriageCompleted($pdo, $patientId, $label, $bhwId);
            if ($recStatus === 'pending_approval' && $triageTier === TriageLevelService::NON_URGENT) {
                $pstmt = $pdo->prepare('SELECT CONCAT(first_name, " ", last_name) FROM users WHERE id = ? LIMIT 1');
                $pstmt->execute([$patientId]);
                $pName = (string) ($pstmt->fetchColumn() ?: 'Patient');
                NotificationEvents::aiSelfCareReviewRequired(
                    $pdo,
                    $provider_id,
                    $patientId,
                    $pName,
                    $triageResultId,
                    $bhwId
                );
            }

            return [
                'emergency'        => false,
                'triage_tier'      => $triageTier,
                'routing'          => $routing,
                'is_urgent'        => $triageTier === TriageLevelService::URGENT,
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

    /**
     * Barangay-scoped read-only list of doctor clinical referrals.
     * BHW may view only — never create, edit, or follow up on referrals here.
     */
    public static function listReferrals(PDO $pdo, array $ctx): array
    {
        [$clause, $params] = bhw_patient_sector_clause($pdo, $ctx, 'pr');
        $dest = self::referralDestColumn($pdo);
        $join = bhw_pr_user_join('pr', 'p');
        $sql = "
            SELECT dr.*, CONCAT(p.first_name,' ',p.last_name) AS patient_name,
                   COALESCE(dr.{$dest}, '') AS facility_display,
                   TRIM(CONCAT(COALESCE(prov.first_name, ''), ' ', COALESCE(prov.last_name, ''))) AS provider_name
            FROM digital_referrals dr
            JOIN users p ON p.id = dr.patient_id
            JOIN patient_registrations pr ON {$join}
            LEFT JOIN users prov ON prov.id = dr.provider_id
            WHERE {$clause}
            ORDER BY dr.created_at DESC LIMIT 200
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @deprecated BHW must not create clinical referrals. Doctors own digital_referrals.
     */
    public static function createReferral(PDO $pdo, array $ctx, int $patientId, string $type, string $reason, ?int $facilityId, ?string $facilityName): int
    {
        throw new InvalidArgumentException('BHW cannot create clinical referrals. View barangay referrals only.');
    }

    /**
     * @deprecated BHW must not mutate digital_referrals.status.
     */
    public static function updateReferralStatus(PDO $pdo, array $ctx, int $referralId, string $status, string $note = ''): void
    {
        throw new InvalidArgumentException('BHW cannot change referral status. View only.');
    }

    /**
     * Barangay-scoped doctor follow-ups (shared `followups` table — no BHW copies).
     *
     * @return list<array<string, mixed>>
     */
    public static function listFollowups(PDO $pdo, array $ctx, ?string $status = null): array
    {
        require_once __DIR__ . '/consultation_followup.php';
        consultation_followup_ensure_schema($pdo);
        bhw_clinical_ensure_schema($pdo);

        [$clause, $params] = bhw_patient_sector_clause($pdo, $ctx, 'pr');
        $join = bhw_pr_user_join('pr', 'p');

        $hasSlots = false;
        try {
            $hasSlots = (bool) $pdo->query("SHOW TABLES LIKE 'appointment_slots'")->rowCount();
        } catch (Throwable $e) {
            $hasSlots = false;
        }

        $slotSelect = $hasSlots
            ? ', s.start_time AS slot_start_time, s.end_time AS slot_end_time'
            : ', NULL AS slot_start_time, NULL AS slot_end_time';
        $slotJoin = $hasSlots
            ? 'LEFT JOIN appointment_slots s ON s.id = f.slot_id'
            : '';

        $sql = "
            SELECT f.*,
                   CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
                   p.email AS patient_email,
                   TRIM(CONCAT(COALESCE(prov.first_name, ''), ' ', COALESCE(prov.last_name, ''))) AS provider_name,
                   (SELECT COUNT(*) FROM bhw_home_visits hv WHERE hv.followup_id = f.id) AS home_visit_count,
                   (SELECT MAX(hv.visit_date) FROM bhw_home_visits hv WHERE hv.followup_id = f.id) AS last_home_visit
                   {$slotSelect}
            FROM followups f
            INNER JOIN users p ON p.id = f.patient_id AND p.role = 'patient'
            INNER JOIN patient_registrations pr ON {$join}
            LEFT JOIN users prov ON prov.id = f.provider_id
            {$slotJoin}
            WHERE {$clause}
        ";
        if ($status === 'upcoming') {
            $sql .= " AND f.status = 'scheduled' AND f.followup_date IS NOT NULL AND f.followup_date >= CURDATE()";
        } elseif ($status === 'missed') {
            $sql .= " AND (
                f.status = 'missed'
                OR (f.status = 'scheduled' AND f.followup_date IS NOT NULL AND f.followup_date < CURDATE())
            )";
        } elseif ($status === 'completed') {
            $sql .= " AND f.status = 'completed'";
        } elseif ($status === 'unscheduled') {
            $sql .= " AND f.status = 'unscheduled'";
        }
        $sql .= ' ORDER BY (f.followup_date IS NULL) ASC, f.followup_date ASC, f.id DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as &$row) {
            $row = self::normalizeFollowupRow($row);
        }
        unset($row);

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalizeFollowupRow(array $row): array
    {
        $raw = strtolower(trim((string) ($row['status'] ?? '')));
        $date = trim((string) ($row['followup_date'] ?? ''));
        $isPast = $date !== '' && strtotime($date) < strtotime('today');

        if ($raw === 'completed') {
            $row['display_status'] = 'Completed';
            $row['display_status_key'] = 'completed';
        } elseif ($raw === 'missed' || ($raw === 'scheduled' && $isPast)) {
            $row['display_status'] = 'Missed';
            $row['display_status_key'] = 'missed';
        } elseif ($raw === 'cancelled' || $raw === 'canceled') {
            $row['display_status'] = 'Cancelled';
            $row['display_status_key'] = 'cancelled';
        } elseif ($raw === 'unscheduled') {
            $row['display_status'] = 'Unscheduled';
            $row['display_status_key'] = 'unscheduled';
        } elseif ($raw === 'scheduled') {
            $row['display_status'] = 'Upcoming';
            $row['display_status_key'] = 'upcoming';
        } else {
            $row['display_status'] = $raw !== '' ? ucfirst($raw) : 'Unknown';
            $row['display_status_key'] = $raw !== '' ? $raw : 'unknown';
        }

        $row['home_visit_count'] = (int) ($row['home_visit_count'] ?? 0);
        $row['id'] = (int) ($row['id'] ?? 0);
        $row['patient_id'] = (int) ($row['patient_id'] ?? 0);
        $row['provider_id'] = (int) ($row['provider_id'] ?? 0);
        $row['consultation_id'] = (int) ($row['consultation_id'] ?? 0);

        $start = trim((string) ($row['slot_start_time'] ?? ''));
        if ($date !== '' && $start !== '') {
            $row['followup_datetime_label'] = date('M j, Y', strtotime($date))
                . ' · ' . date('g:i A', strtotime($date . ' ' . $start));
        } elseif ($date !== '') {
            $row['followup_datetime_label'] = date('M j, Y', strtotime($date));
        } else {
            $row['followup_datetime_label'] = 'Date TBD';
        }

        $visits = (int) $row['home_visit_count'];
        $last = trim((string) ($row['last_home_visit'] ?? ''));
        if ($visits <= 0) {
            $row['home_visit_label'] = 'No home visits yet';
        } elseif ($last !== '') {
            $row['home_visit_label'] = $visits . ' visit' . ($visits === 1 ? '' : 's')
                . ' · last ' . date('M j, Y', strtotime($last));
        } else {
            $row['home_visit_label'] = $visits . ' visit' . ($visits === 1 ? '' : 's');
        }

        return $row;
    }

    /**
     * Unified barangay appointment + follow-up queue for BHW (no clinical extras).
     *
     * @return list<array{
     *   patient_id:int,
     *   patient_name:string,
     *   appointment_date:string,
     *   appointment_time:string,
     *   status:string,
     *   status_key:string,
     *   source:string,
     *   sort_key:string
     * }>
     */
    public static function listAppointmentFollowupQueue(PDO $pdo, array $ctx): array
    {
        require_once __DIR__ . '/consultation_followup.php';
        consultation_followup_ensure_schema($pdo);

        [$clause, $params] = bhw_patient_sector_clause($pdo, $ctx, 'pr');
        $join = bhw_pr_user_join('pr', 'p');
        $rows = [];

        // Consultations / appointments for sector patients (recent past → upcoming window).
        try {
            $sql = "
                SELECT c.id, c.patient_id, c.consult_date, c.consult_time, c.status,
                       CONCAT(p.first_name, ' ', p.last_name) AS patient_name
                FROM consultations c
                INNER JOIN users p ON p.id = c.patient_id AND p.role = 'patient'
                INNER JOIN patient_registrations pr ON {$join}
                WHERE {$clause}
                  AND c.consult_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                  AND c.consult_date <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)
                  AND LOWER(COALESCE(c.status, '')) NOT IN ('cancelled', 'canceled')
                ORDER BY c.consult_date ASC, c.consult_time ASC
                LIMIT 300
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $c) {
                $date = trim((string) ($c['consult_date'] ?? ''));
                $time = trim((string) ($c['consult_time'] ?? ''));
                $raw = strtolower(trim((string) ($c['status'] ?? '')));
                $statusKey = $raw !== '' ? $raw : 'scheduled';
                $statusLabel = match ($statusKey) {
                    'scheduled' => 'Scheduled',
                    'in_consultation' => 'In consultation',
                    'completed' => 'Completed',
                    'pending' => 'Pending',
                    default => $raw !== '' ? ucwords(str_replace('_', ' ', $raw)) : 'Scheduled',
                };
                $sortTime = $time !== '' ? $time : '00:00:00';
                $rows[] = [
                    'patient_id' => (int) ($c['patient_id'] ?? 0),
                    'patient_name' => trim((string) ($c['patient_name'] ?? '')) ?: '—',
                    'appointment_date' => $date !== '' ? date('M j, Y', strtotime($date)) : '—',
                    'appointment_time' => $time !== '' ? date('g:i A', strtotime('1970-01-01 ' . $time)) : '—',
                    'status' => $statusLabel,
                    'status_key' => $statusKey,
                    'source' => 'consultation',
                    'sort_key' => ($date !== '' ? $date : '9999-99-99') . ' ' . $sortTime,
                ];
            }
        } catch (Throwable $e) {
            // Keep queue usable if consultations query fails.
        }

        // Doctor follow-ups for the same barangay patients.
        $followups = self::listFollowups($pdo, $ctx, null);
        foreach ($followups as $f) {
            $rawKey = (string) ($f['display_status_key'] ?? 'unknown');
            if (in_array($rawKey, ['completed', 'cancelled'], true)) {
                // Still show recent completed? User wants queue — skip completed/cancelled to keep lean.
                continue;
            }
            $date = trim((string) ($f['followup_date'] ?? ''));
            $start = trim((string) ($f['slot_start_time'] ?? ''));
            $statusLabel = (string) ($f['display_status'] ?? 'Follow-up');
            if ($rawKey === 'upcoming' || $rawKey === 'scheduled') {
                $statusLabel = 'Follow-up';
                $rawKey = 'follow_up';
            } elseif ($rawKey === 'unscheduled') {
                $statusLabel = 'Pending';
                $rawKey = 'pending';
            } elseif ($rawKey === 'missed') {
                $statusLabel = 'Missed';
            }
            $sortTime = $start !== '' ? $start : '00:00:00';
            $rows[] = [
                'patient_id' => (int) ($f['patient_id'] ?? 0),
                'patient_name' => trim((string) ($f['patient_name'] ?? '')) ?: '—',
                'appointment_date' => $date !== '' ? date('M j, Y', strtotime($date)) : 'Date TBD',
                'appointment_time' => $start !== '' ? date('g:i A', strtotime('1970-01-01 ' . $start)) : '—',
                'status' => $statusLabel,
                'status_key' => $rawKey,
                'source' => 'followup',
                'sort_key' => ($date !== '' ? $date : '9999-99-99') . ' ' . $sortTime,
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            return strcmp((string) $a['sort_key'], (string) $b['sort_key']);
        });

        return $rows;
    }

    /**
     * Single doctor follow-up for BHW (read-only clinical fields + home-visit activity).
     *
     * @return array{followup: array<string, mixed>, visits: list<array<string, mixed>>}
     */
    public static function getFollowup(PDO $pdo, array $ctx, int $followupId): array
    {
        require_once __DIR__ . '/consultation_followup.php';
        consultation_followup_ensure_schema($pdo);
        bhw_clinical_ensure_schema($pdo);

        if ($followupId <= 0) {
            throw new InvalidArgumentException('Follow-up ID required.');
        }

        [$clause, $params] = bhw_patient_sector_clause($pdo, $ctx, 'pr');
        $join = bhw_pr_user_join('pr', 'p');

        $hasSlots = false;
        try {
            $hasSlots = (bool) $pdo->query("SHOW TABLES LIKE 'appointment_slots'")->rowCount();
        } catch (Throwable $e) {
            $hasSlots = false;
        }
        $slotSelect = $hasSlots
            ? ', s.start_time AS slot_start_time, s.end_time AS slot_end_time'
            : ', NULL AS slot_start_time, NULL AS slot_end_time';
        $slotJoin = $hasSlots
            ? 'LEFT JOIN appointment_slots s ON s.id = f.slot_id'
            : '';

        $sql = "
            SELECT f.*,
                   CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
                   p.email AS patient_email,
                   TRIM(CONCAT(COALESCE(prov.first_name, ''), ' ', COALESCE(prov.last_name, ''))) AS provider_name,
                   (SELECT COUNT(*) FROM bhw_home_visits hv WHERE hv.followup_id = f.id) AS home_visit_count,
                   (SELECT MAX(hv.visit_date) FROM bhw_home_visits hv WHERE hv.followup_id = f.id) AS last_home_visit
                   {$slotSelect}
            FROM followups f
            INNER JOIN users p ON p.id = f.patient_id AND p.role = 'patient'
            INNER JOIN patient_registrations pr ON {$join}
            LEFT JOIN users prov ON prov.id = f.provider_id
            {$slotJoin}
            WHERE f.id = ? AND {$clause}
            LIMIT 1
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$followupId], $params));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new InvalidArgumentException('Follow-up not found in your barangay.');
        }

        $followup = self::normalizeFollowupRow($row);

        $vStmt = $pdo->prepare("
            SELECT hv.*,
                   CONCAT(b.first_name, ' ', b.last_name) AS bhw_name
            FROM bhw_home_visits hv
            LEFT JOIN users b ON b.id = hv.bhw_id
            WHERE hv.followup_id = ?
            ORDER BY hv.visit_date DESC, hv.id DESC
            LIMIT 50
        ");
        $vStmt->execute([$followupId]);
        $visits = $vStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return ['followup' => $followup, 'visits' => $visits];
    }

    /**
     * Email a follow-up reminder to the patient's registered Gmail/email.
     *
     * @return array{success: bool, message: string, email?: string}
     */
    public static function sendFollowupReminder(PDO $pdo, array $ctx, int $followupId): array
    {
        require_once __DIR__ . '/consultation_followup.php';
        require_once __DIR__ . '/mailer.php';
        consultation_followup_ensure_schema($pdo);

        if ($followupId <= 0) {
            throw new InvalidArgumentException('Follow-up ID required.');
        }

        [$clause, $params] = bhw_patient_sector_clause($pdo, $ctx, 'pr');
        $join = bhw_pr_user_join('pr', 'p');
        $stmt = $pdo->prepare("
            SELECT f.*,
                   p.id AS pid,
                   p.email AS patient_email,
                   CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
                   COALESCE(pr.contact_number, f.contact_number, '') AS contact_number
            FROM followups f
            INNER JOIN users p ON p.id = f.patient_id AND p.role = 'patient'
            INNER JOIN patient_registrations pr ON {$join}
            WHERE f.id = ? AND {$clause}
            LIMIT 1
        ");
        $stmt->execute(array_merge([$followupId], $params));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new InvalidArgumentException('Follow-up not found in your barangay.');
        }

        $email = trim((string) ($row['patient_email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Patient has no valid registered email on file.');
        }

        // Prevent accidental duplicate sends within 15 minutes for the same follow-up.
        try {
            if ($pdo->query("SHOW TABLES LIKE 'patient_audit_logs'")->rowCount()) {
                $dup = $pdo->prepare("
                    SELECT id
                    FROM patient_audit_logs
                    WHERE patient_id = ?
                      AND action_type = 'bhw_followup_reminder'
                      AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
                      AND (
                        meta LIKE ?
                        OR meta LIKE ?
                      )
                    ORDER BY id DESC
                    LIMIT 1
                ");
                $dup->execute([
                    (int) $row['patient_id'],
                    '%"followup_id":' . $followupId . '%',
                    '%"followup_id": ' . $followupId . '%',
                ]);
                if ($dup->fetchColumn()) {
                    throw new InvalidArgumentException(
                        'A reminder was already sent for this follow-up in the last 15 minutes. Please wait before sending again.'
                    );
                }
            }
        } catch (InvalidArgumentException $e) {
            throw $e;
        } catch (Throwable $e) {
            // non-fatal — continue to send
        }

        $date = trim((string) ($row['followup_date'] ?? ''));
        if ($date === '') {
            throw new InvalidArgumentException('This follow-up has no scheduled date yet, so an email reminder cannot be sent.');
        }

        $patientName = trim((string) ($row['patient_name'] ?? 'Patient'));
        $note = trim((string) ($row['message'] ?? $row['notes'] ?? ''));
        $refLine = 'Follow-up reference #' . $followupId;
        $noteForEmail = $note !== '' ? ($note . "\n" . $refLine) : $refLine;
        $contact = trim((string) ($row['contact_number'] ?? ''));

        $sent = sendFollowUpReminderEmail($email, $patientName, $date, $noteForEmail, $contact);
        $ok = !empty($sent['success']);
        $mailMsg = trim((string) ($sent['message'] ?? ''));

        bhw_audit($pdo, (int) $row['patient_id'], $ok ? 'bhw_followup_reminder' : 'bhw_followup_reminder_failed',
            $ok
                ? 'BHW sent follow-up reminder email to ' . $email . '.'
                : ('BHW follow-up reminder email failed: ' . ($mailMsg !== '' ? $mailMsg : 'unknown error')),
            [
                'followup_id' => $followupId,
                'email' => $email,
                'followup_date' => $date,
                'email_success' => $ok,
                'email_message' => $mailMsg,
            ]
        );

        if (!$ok) {
            throw new RuntimeException($mailMsg !== '' ? $mailMsg : 'Failed to send reminder email.');
        }

        // In-app notice only after the email actually succeeds.
        bhw_notify(
            $pdo,
            (int) $row['patient_id'],
            'followup',
            'Follow-Up Reminder',
            'Reminder: follow-up on ' . date('M j, Y', strtotime($date)) . '. Ref #' . $followupId . '.',
            ASSET_BASE . '/views/patient/dashboard.php#action-items'
        );

        return [
            'success' => true,
            'message' => 'Reminder email sent to ' . $email . '.',
            'email' => $email,
        ];
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
        require_once __DIR__ . '/consultation_followup.php';
        consultation_followup_ensure_schema($pdo);

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

        if ($followupId && $followupId > 0) {
            $chk = $pdo->prepare('SELECT f.id, f.patient_id FROM followups f WHERE f.id = ? LIMIT 1');
            $chk->execute([$followupId]);
            $fu = $chk->fetch(PDO::FETCH_ASSOC);
            if (!$fu || (int) $fu['patient_id'] !== $patientId) {
                throw new InvalidArgumentException('Follow-up not found for this patient.');
            }
            if (!bhw_assert_patient_in_sector($pdo, $ctx, (int) $fu['patient_id'])) {
                throw new InvalidArgumentException('Follow-up not found in your barangay.');
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

        // Doctor follow-up record stays read-only — do not mutate clinical status here.

        bhw_audit($pdo, $patientId, 'bhw_home_visit_logged', 'BHW logged home visit.', [
            'visit_id' => $visitId,
            'followup_id' => $followupId,
            'visit_type' => $visitType,
            'patient_status' => $patientStatus,
        ]);

        BhwPatientWorkflow::onFollowUpMonitoring($pdo, $patientId);

        bhw_notify($pdo, $patientId, 'followup', 'Home Visit Completed',
            'Your BHW completed a home follow-up visit on ' . date('M j, Y', strtotime($visitDate)) . '.',
            ASSET_BASE . '/views/patient/dashboard.php#action-items');

        return $visitId;
    }

    public static function listHomeVisits(PDO $pdo, array $ctx, ?int $patientId = null): array
    {
        bhw_clinical_ensure_schema($pdo);
        [$clause, $params] = bhw_patient_sector_clause($pdo, $ctx, 'pr');
        $join = bhw_pr_user_join('pr', 'p');
        $sql = "
            SELECT hv.*, CONCAT(p.first_name,' ',p.last_name) AS patient_name,
                   CONCAT(b.first_name,' ',b.last_name) AS bhw_name
            FROM bhw_home_visits hv
            JOIN users p ON p.id = hv.patient_id
            JOIN patient_registrations pr ON {$join}
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

    public static function parseDashboardFilters(array $input): array
    {
        $days = (int) ($input['days'] ?? 7);
        $allowed = [1, 7, 14, 30, 90];
        if (!in_array($days, $allowed, true)) {
            $days = 7;
        }

        return [
            'days'  => $days,
            'purok' => trim((string) ($input['purok'] ?? '')),
        ];
    }

    /**
     * Barangay sector (+ optional purok) SQL clause for dashboard aggregates.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private static function patientScopeWhere(PDO $pdo, array $ctx, array $filters, string $prAlias = 'pr'): array
    {
        $f = self::parseDashboardFilters($filters);
        [$clause, $params] = bhw_patient_scope_clause($pdo, $ctx, [], $prAlias);
        if ($f['purok'] !== '' && in_array('purok', bhw_pr_columns($pdo), true)) {
            $clause .= ' AND LOWER(TRIM(' . $prAlias . '.purok)) = LOWER(?)';
            $params[] = $f['purok'];
        }

        return [$clause, $params];
    }

    public static function getDashboardMetrics(PDO $pdo, array $ctx, array $filters = []): array
    {
        BhwPatientWorkflow::ensure_schema($pdo);
        [$clause, $params] = self::patientScopeWhere($pdo, $ctx, $filters);

        $metrics = [
            'todays_patients'        => 0,
            'pending_registrations'  => 0,
            'waiting_ai_triage'    => 0,
            'emergency_cases'        => 0,
            'urgent_cases'           => 0,
            'non_urgent_cases'       => 0,
            'upcoming_consultations' => 0,
            'completed_consultations'=> 0,
            'referrals'              => 0,
            'followups'              => 0,
            // legacy keys
            'total_households'       => 0,
            'pending_triage'         => 0,
            'scheduled_calls'        => 0,
            'high_risk_flags'        => 0,
        ];

        $q = $pdo->prepare("SELECT COUNT(*) FROM patient_registrations pr WHERE {$clause}");
        $q->execute($params);
        $metrics['total_households'] = (int) $q->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM patient_registrations pr WHERE {$clause} AND DATE(pr.created_at) = CURDATE()");
        $stmt->execute($params);
        $metrics['todays_patients'] = (int) $stmt->fetchColumn();

        $wf = $pdo->prepare("
            SELECT pr.workflow_status, COUNT(*) AS cnt
            FROM patient_registrations pr
            WHERE {$clause}
            GROUP BY pr.workflow_status
        ");
        $wf->execute($params);
        foreach ($wf->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $status = (string) ($row['workflow_status'] ?? '');
            $cnt = (int) ($row['cnt'] ?? 0);
            match ($status) {
                BhwPatientWorkflow::REGISTERED,
                BhwPatientWorkflow::AWAITING_COMPLAINT => $metrics['pending_registrations'] += $cnt,
                BhwPatientWorkflow::AI_PROCESSING      => $metrics['waiting_ai_triage'] += $cnt,
                BhwPatientWorkflow::EMERGENCY          => $metrics['emergency_cases'] += $cnt,
                BhwPatientWorkflow::URGENT             => $metrics['urgent_cases'] += $cnt,
                BhwPatientWorkflow::NON_URGENT         => $metrics['non_urgent_cases'] += $cnt,
                default => null,
            };
        }

        $tq = $pdo->prepare("
            SELECT COUNT(*) FROM triage_results tr
            JOIN users u ON u.id = tr.patient_id
            JOIN patient_registrations pr ON pr.email = u.email
            WHERE {$clause} AND tr.status = 'pending'
        ");
        $tq->execute($params);
        $metrics['pending_triage'] = (int) $tq->fetchColumn();
        $metrics['waiting_ai_triage'] += $metrics['pending_triage'];

        $eq = $pdo->prepare("
            SELECT COUNT(*) FROM triage_results tr
            JOIN users u ON u.id = tr.patient_id
            JOIN patient_registrations pr ON pr.email = u.email
            WHERE {$clause} AND tr.triage_level = 'emergency'
              AND tr.assessed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
        $eq->execute($params);
        $metrics['emergency_cases'] = max($metrics['emergency_cases'], (int) $eq->fetchColumn());

        $uq = $pdo->prepare("
            SELECT COUNT(*) FROM triage_results tr
            JOIN users u ON u.id = tr.patient_id
            JOIN patient_registrations pr ON pr.email = u.email
            WHERE {$clause} AND tr.triage_level = 'urgent'
              AND tr.assessed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
        $uq->execute($params);
        $metrics['urgent_cases'] = max($metrics['urgent_cases'], (int) $uq->fetchColumn());

        $nq = $pdo->prepare("
            SELECT COUNT(*) FROM triage_results tr
            JOIN users u ON u.id = tr.patient_id
            JOIN patient_registrations pr ON pr.email = u.email
            WHERE {$clause} AND tr.triage_level = 'non_urgent'
              AND tr.assessed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
        $nq->execute($params);
        $metrics['non_urgent_cases'] = max($metrics['non_urgent_cases'], (int) $nq->fetchColumn());

        $hq = $pdo->prepare("
            SELECT COUNT(*) FROM triage_results tr
            JOIN users u ON u.id = tr.patient_id
            JOIN patient_registrations pr ON pr.email = u.email
            WHERE {$clause} AND tr.triage_level IN ('emergency','urgent')
        ");
        $hq->execute($params);
        $metrics['high_risk_flags'] = (int) $hq->fetchColumn();

        $cq = $pdo->prepare("
            SELECT COUNT(*) FROM consultations c
            JOIN users u ON u.id = c.patient_id
            JOIN patient_registrations pr ON pr.email = u.email
            WHERE {$clause} AND c.consult_date = CURDATE()
              AND c.status IN ('scheduled','pending','in_consultation')
        ");
        $cq->execute($params);
        $metrics['scheduled_calls'] = (int) $cq->fetchColumn();
        $metrics['upcoming_consultations'] = $metrics['scheduled_calls'];

        $up = $pdo->prepare("
            SELECT COUNT(*) FROM consultations c
            JOIN users u ON u.id = c.patient_id
            JOIN patient_registrations pr ON pr.email = u.email
            WHERE {$clause} AND c.consult_date >= CURDATE()
              AND c.status IN ('scheduled','pending')
        ");
        $up->execute($params);
        $metrics['upcoming_consultations'] = (int) $up->fetchColumn();

        $done = $pdo->prepare("
            SELECT COUNT(*) FROM consultations c
            JOIN users u ON u.id = c.patient_id
            JOIN patient_registrations pr ON pr.email = u.email
            WHERE {$clause} AND c.status = 'completed'
              AND c.consult_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        ");
        $done->execute($params);
        $metrics['completed_consultations'] = (int) $done->fetchColumn();

        $rq = $pdo->prepare("
            SELECT COUNT(*) FROM digital_referrals dr
            JOIN users u ON u.id = dr.patient_id
            JOIN patient_registrations pr ON " . bhw_pr_user_join('pr', 'u') . "
            WHERE {$clause} AND dr.status = 'pending'
        ");
        $rq->execute($params);
        $metrics['referrals'] = (int) $rq->fetchColumn();

        $fq = $pdo->prepare("
            SELECT COUNT(*) FROM followups f
            JOIN users u ON u.id = f.patient_id
            JOIN patient_registrations pr ON " . bhw_pr_user_join('pr', 'u') . "
            WHERE {$clause} AND f.status = 'scheduled'
        ");
        $fq->execute($params);
        $metrics['followups'] = (int) $fq->fetchColumn();

        return $metrics;
    }

    /**
     * Chart series for BHW dashboard (barangay-scoped).
     *
     * @return array{
     *   consultations_week: list<array{label:string,count:int,is_today:bool}>,
     *   registrations_week: list<array{label:string,count:int,is_today:bool}>,
     *   triage_mix: list<array{label:string,value:int}>,
     *   workflow_pipeline: list<array{label:string,value:int}>
     * }
     */
    public static function getDashboardCharts(PDO $pdo, array $ctx, array $filters = []): array
    {
        BhwPatientWorkflow::ensure_schema($pdo);
        [$clause, $params] = self::patientScopeWhere($pdo, $ctx, $filters);
        $f = self::parseDashboardFilters($filters);
        $days = $f['days'];

        $consultWeek = [];
        $regWeek = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $isToday = ($i === 0);

            $cStmt = $pdo->prepare("
                SELECT COUNT(*) FROM consultations c
                JOIN users u ON u.id = c.patient_id
                JOIN patient_registrations pr ON pr.email = u.email
                WHERE {$clause} AND c.consult_date = ?
            ");
            $cStmt->execute(array_merge($params, [$date]));
            $consultWeek[] = [
                'label'    => $days === 1 ? 'Today' : ($days > 14 ? date('M j', strtotime($date)) : date('D', strtotime($date))),
                'count'    => (int) $cStmt->fetchColumn(),
                'is_today' => $isToday,
            ];

            $rStmt = $pdo->prepare("
                SELECT COUNT(*) FROM patient_registrations pr
                WHERE {$clause} AND DATE(pr.created_at) = ?
            ");
            $rStmt->execute(array_merge($params, [$date]));
            $regWeek[] = [
                'label'    => $days === 1 ? 'Today' : ($days > 14 ? date('M j', strtotime($date)) : date('D', strtotime($date))),
                'count'    => (int) $rStmt->fetchColumn(),
                'is_today' => $isToday,
            ];
        }

        $metrics = self::getDashboardMetrics($pdo, $ctx, $filters);
        $triageMix = [
            ['label' => 'Emergency', 'value' => (int) ($metrics['emergency_cases'] ?? 0)],
            ['label' => 'Urgent', 'value' => (int) ($metrics['urgent_cases'] ?? 0)],
            ['label' => 'Non-urgent', 'value' => (int) ($metrics['non_urgent_cases'] ?? 0)],
            ['label' => 'Awaiting triage', 'value' => (int) ($metrics['waiting_ai_triage'] ?? 0)],
        ];

        $workflowLabels = [
            BhwPatientWorkflow::REGISTERED          => 'Registered',
            BhwPatientWorkflow::AWAITING_COMPLAINT => 'Awaiting complaint',
            BhwPatientWorkflow::AI_PROCESSING       => 'AI processing',
            BhwPatientWorkflow::EMERGENCY           => 'Emergency',
            BhwPatientWorkflow::URGENT              => 'Urgent',
            BhwPatientWorkflow::NON_URGENT          => 'Non-urgent',
        ];

        $wf = $pdo->prepare("
            SELECT pr.workflow_status, COUNT(*) AS cnt
            FROM patient_registrations pr
            WHERE {$clause}
            GROUP BY pr.workflow_status
        ");
        $wf->execute($params);
        $workflowPipeline = [];
        foreach ($wf->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $status = (string) ($row['workflow_status'] ?? '');
            $cnt = (int) ($row['cnt'] ?? 0);
            if ($cnt <= 0) {
                continue;
            }
            $workflowPipeline[] = [
                'label' => $workflowLabels[$status] ?? ucwords(str_replace('_', ' ', $status)),
                'value' => $cnt,
            ];
        }
        usort($workflowPipeline, static fn ($a, $b) => $b['value'] <=> $a['value']);

        return [
            'days'               => $days,
            'consultations_week' => $consultWeek,
            'registrations_week' => $regWeek,
            'triage_mix'         => $triageMix,
            'workflow_pipeline'  => $workflowPipeline,
        ];
    }

    public static function getTriageQueue(PDO $pdo, array $ctx, int $limit = 15, array $filters = []): array
    {
        [$clause, $params] = self::patientScopeWhere($pdo, $ctx, $filters);
        $sql = "
            SELECT p.id AS patient_id, p.first_name, p.last_name, pr.purok,
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
