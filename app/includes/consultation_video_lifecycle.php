<?php
/**
 * Video consultation lifecycle helpers.
 * Ends the WebRTC room. Consultation completion requires doctor Final Assessment
 * (SOAP finalize + explicit final urgency) via save_clinical_notes.php.
 */
declare(strict_types=1);

/**
 * Provider ends an active (or already-ended) video room.
 * Does NOT mark the consultation completed — that happens only after the doctor
 * submits the Final Assessment (SOAP finalize with final_urgency_bucket).
 * Idempotent: safe if called multiple times for the same room token.
 *
 * @return array{
 *   success: bool,
 *   message: string,
 *   consultation_id: int,
 *   patient_id: int,
 *   video_status: string,
 *   consultation_status: string,
 *   started_at: ?string,
 *   ended_at: ?string,
 *   newly_ended: bool,
 *   newly_completed: bool
 * }
 */
function consultation_provider_end_video_session(PDO $pdo, string $roomToken, int $providerId): array
{
    $fail = static function (string $message): array {
        return [
            'success' => false,
            'message' => $message,
            'consultation_id' => 0,
            'patient_id' => 0,
            'video_status' => '',
            'consultation_status' => '',
            'started_at' => null,
            'ended_at' => null,
            'newly_ended' => false,
            'newly_completed' => false,
        ];
    };

    $roomToken = trim($roomToken);
    if ($roomToken === '' || $providerId <= 0) {
        return $fail('Invalid video session request.');
    }

    require_once __DIR__ . '/patient_consultation_records.php';
    require_once __DIR__ . '/clinical_tables.php';
    clinical_tables_ensure($pdo);
    patient_consultation_records_schema_ensure($pdo);

    $stmt = $pdo->prepare("
        SELECT
            vs.id AS video_session_id,
            vs.status AS video_status,
            vs.started_at,
            vs.ended_at,
            c.id AS consultation_id,
            c.patient_id,
            c.provider_id,
            c.status AS consultation_status
        FROM video_sessions vs
        JOIN consultations c ON c.id = vs.consultation_id
        WHERE vs.room_token = ?
        LIMIT 1
    ");
    $stmt->execute([$roomToken]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return $fail('Video session not found.');
    }

    if ((int) ($row['provider_id'] ?? 0) !== $providerId) {
        return $fail('Unauthorized.');
    }

    $consultationId = (int) ($row['consultation_id'] ?? 0);
    $patientId = (int) ($row['patient_id'] ?? 0);
    $videoSessionId = (int) ($row['video_session_id'] ?? 0);
    $videoStatus = strtolower(trim((string) ($row['video_status'] ?? '')));
    $consultStatus = strtolower(trim((string) ($row['consultation_status'] ?? '')));
    $newlyEnded = false;

    try {
        $pdo->beginTransaction();

        if ($videoStatus === 'active') {
            $endVs = $pdo->prepare("
                UPDATE video_sessions
                SET status = 'ended',
                    started_at = COALESCE(started_at, NOW()),
                    ended_at = COALESCE(ended_at, NOW())
                WHERE id = ?
                  AND status = 'active'
            ");
            $endVs->execute([$videoSessionId]);
            $newlyEnded = $endVs->rowCount() > 0;
        } else {
            // Ensure ended_at exists even if a prior path closed the room without a timestamp.
            $pdo->prepare("
                UPDATE video_sessions
                SET started_at = COALESCE(started_at, NOW()),
                    ended_at = COALESCE(ended_at, NOW()),
                    status = 'ended'
                WHERE id = ?
                  AND (ended_at IS NULL OR status <> 'ended')
            ")->execute([$videoSessionId]);
        }

        // Also close any other active rooms for the same consultation (should be rare).
        $pdo->prepare("
            UPDATE video_sessions
            SET status = 'ended',
                started_at = COALESCE(started_at, NOW()),
                ended_at = COALESCE(ended_at, NOW())
            WHERE consultation_id = ?
              AND status = 'active'
        ")->execute([$consultationId]);

        // Keep consultation open until the doctor submits Final Assessment
        // (SOAP finalize + final_urgency_bucket). Do not set status=completed here.

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('consultation_provider_end_video_session: ' . $e->getMessage());
        return $fail('Could not end video session.');
    }

    $fresh = $pdo->prepare("
        SELECT vs.status AS video_status, vs.started_at, vs.ended_at, c.status AS consultation_status
        FROM video_sessions vs
        JOIN consultations c ON c.id = vs.consultation_id
        WHERE vs.id = ?
        LIMIT 1
    ");
    $fresh->execute([$videoSessionId]);
    $live = $fresh->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'success' => true,
        'message' => $newlyEnded
            ? 'Video call ended. Submit the Final Assessment to complete this consultation.'
            : 'Video session closed. Final Assessment is still required if not yet submitted.',
        'consultation_id' => $consultationId,
        'patient_id' => $patientId,
        'video_status' => (string) ($live['video_status'] ?? 'ended'),
        'consultation_status' => (string) ($live['consultation_status'] ?? $consultStatus),
        'started_at' => $live['started_at'] ?? $row['started_at'] ?? null,
        'ended_at' => $live['ended_at'] ?? $row['ended_at'] ?? null,
        'newly_ended' => $newlyEnded,
        'newly_completed' => false,
    ];
}

/**
 * Provider opens or resumes the live room for a consultation they own.
 * Does not finalize SOAP and does not mark the consultation completed.
 *
 * Business-rule refusals use HTTP 200 so the consultation page can show the message.
 * The caller still rejects a missing session, the wrong role, and a bad CSRF token.
 *
 * @return array{
 *   success:bool,
 *   message:string,
 *   code:string,
 *   http_status:int,
 *   token:string,
 *   consultation_id:int,
 *   patient_id:int,
 *   consultation_status:string,
 *   created:bool
 * }
 */
function consultation_provider_start_video_session(PDO $pdo, int $consultationId, int $providerId): array
{
    $fail = static function (string $message, string $code, int $status = 200): array {
        return [
            'success' => false,
            'message' => $message,
            'code' => $code,
            'http_status' => $status,
            'token' => '',
            'consultation_id' => 0,
            'patient_id' => 0,
            'consultation_status' => '',
            'created' => false,
        ];
    };

    if ($consultationId <= 0 || $providerId <= 0) {
        return $fail('Consultation ID required.', 'consultation_required');
    }

    require_once __DIR__ . '/clinical_tables.php';
    require_once __DIR__ . '/consultation_queue_timing.php';
    require_once dirname(__DIR__, 2) . '/resources/views/provider/partials/queue_helpers.php';

    clinical_tables_ensure($pdo);
    consultation_timing_ensure_schema($pdo);
    consultation_timing_close_missed($pdo, null, $providerId);

    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $lock = $driver === 'mysql' ? ' FOR UPDATE' : '';
    $joinedSql = consultation_timing_latest_patient_joined_sql('c');

    $stmt = $pdo->prepare("
        SELECT c.id, c.patient_id, c.provider_id, c.consult_date, c.consult_time, c.status,
               c.early_start_response,
               s.slot_date, s.start_time AS slot_start, s.end_time AS slot_end,
               {$joinedSql} AS patient_joined_at
        FROM consultations c
        LEFT JOIN appointment_slots s ON s.consultation_id = c.id AND s.status = 'booked'
        WHERE c.id = ? AND c.provider_id = ?
        LIMIT 1
    ");
    $stmt->execute([$consultationId, $providerId]);
    $consultation = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$consultation) {
        return $fail('Consultation not found or access denied.', 'not_owner', 403);
    }

    $consultStatus = strtolower(trim((string) ($consultation['status'] ?? '')));
    if (in_array($consultStatus, ['completed', 'cancelled', 'canceled', 'ended', 'closed'], true)) {
        return $fail(
            'This consultation has already ended. You can only view its historical record.',
            'consultation_ended'
        );
    }

    $consultation = consultation_timing_decorate_row($pdo, $consultation);
    $otherVideo = consultation_timing_other_active_video_id($pdo, $providerId, $consultationId);
    $otherOpen = consultation_timing_other_open_consultation_id($pdo, $providerId, $consultationId);
    $startDecision = consultation_timing_provider_start_decision(
        time(),
        isset($consultation['timing_slot_start']) ? $consultation['timing_slot_start'] : null,
        isset($consultation['timing_slot_end']) ? $consultation['timing_slot_end'] : null,
        $consultStatus,
        $otherVideo > 0,
        !empty($consultation['patient_ever_joined']),
        !empty($consultation['timing_blocked_by_earlier']),
        (string) ($consultation['early_start_response'] ?? ''),
        false,
        $otherOpen > 0
    );
    if (!$startDecision['allowed']) {
        return $fail((string) $startDecision['reason'], (string) $startDecision['code']);
    }

    $sessionAccess = queue_session_access($consultation);
    if (!$sessionAccess['allowed']) {
        return $fail((string) $sessionAccess['reason'], 'schedule_window');
    }

    try {
        $pdo->beginTransaction();

        $pdo->prepare('SELECT id FROM users WHERE id = ? LIMIT 1' . $lock)->execute([$providerId]);
        $lockedOpen = consultation_timing_other_open_consultation_id($pdo, $providerId, $consultationId);
        if ($lockedOpen > 0) {
            $pdo->rollBack();
            return $fail(
                'Finish the open consultation, including the SOAP note and final assessment, before starting the next patient.',
                'clinical_active'
            );
        }

        $lockedStmt = $pdo->prepare('
            SELECT id, patient_id, status
            FROM consultations
            WHERE id = ? AND provider_id = ?
            LIMIT 1' . $lock);
        $lockedStmt->execute([$consultationId, $providerId]);
        $locked = $lockedStmt->fetch(PDO::FETCH_ASSOC);
        if (!$locked || (int) ($locked['patient_id'] ?? 0) !== (int) $consultation['patient_id']) {
            $pdo->rollBack();
            return $fail('Consultation not found or access denied.', 'not_owner', 403);
        }

        $lockedStatus = strtolower(trim((string) ($locked['status'] ?? '')));
        if (in_array($lockedStatus, ['completed', 'cancelled', 'canceled', 'ended', 'closed'], true)) {
            $pdo->rollBack();
            return $fail(
                'This consultation has already ended. You can only view its historical record.',
                'consultation_ended'
            );
        }

        $roomStmt = $pdo->prepare('
            SELECT id, room_token
            FROM video_sessions
            WHERE consultation_id = ? AND status = \'active\'
            ORDER BY id ASC
            LIMIT 1' . $lock);
        $roomStmt->execute([$consultationId]);
        $room = $roomStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $created = false;
        if ($room) {
            $token = (string) $room['room_token'];
            $pdo->prepare("
                UPDATE consultations
                SET status = 'in_consultation'
                WHERE id = ? AND provider_id = ? AND status IN ('scheduled', 'pending')
            ")->execute([$consultationId, $providerId]);
            $pdo->prepare("
                UPDATE video_sessions
                SET started_at = COALESCE(started_at, NOW())
                WHERE id = ? AND status = 'active'
            ")->execute([(int) $room['id']]);
        } else {
            if ($lockedStatus === 'in_consultation') {
                $flagStmt = $pdo->prepare('SELECT c.status, ' . queue_documentation_flags_sql('c') . ' FROM consultations c WHERE c.id = ? LIMIT 1');
                $flagStmt->execute([$consultationId]);
                $flags = $flagStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                if (queue_is_documentation_pending(['status' => $lockedStatus, 'room_token' => ''] + $flags)) {
                    $pdo->rollBack();
                    return $fail(
                        'Final Assessment is required before starting another video session.',
                        'final_assessment_required'
                    );
                }
            }

            $token = '';
            $inserted = false;
            $lastError = null;
            for ($attempt = 0; $attempt < 3 && !$inserted; $attempt++) {
                $token = bin2hex(random_bytes(16));
                try {
                    $pdo->prepare("
                        INSERT INTO video_sessions (consultation_id, room_token, status, started_at)
                        VALUES (?, ?, 'active', NOW())
                    ")->execute([$consultationId, $token]);
                    $inserted = true;
                } catch (PDOException $e) {
                    $driverCode = (int) ($e->errorInfo[1] ?? 0);
                    $duplicate = (string) $e->getCode() === '23000' || $driverCode === 1062 || $driverCode === 19;
                    if (!$duplicate) {
                        throw $e;
                    }
                    $lastError = $e;
                }
            }
            if (!$inserted) {
                throw $lastError ?? new RuntimeException('Could not store a video room.');
            }

            $pdo->prepare("
                UPDATE consultations
                SET status = 'in_consultation'
                WHERE id = ? AND provider_id = ? AND status IN ('scheduled', 'pending')
            ")->execute([$consultationId, $providerId]);
            $created = true;
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('consultation_provider_start_video_session: ' . $e->getMessage());
        return $fail('Could not start video session.', 'start_failed', 500);
    }

    $fresh = $pdo->prepare('SELECT status FROM consultations WHERE id = ? AND provider_id = ? LIMIT 1');
    $fresh->execute([$consultationId, $providerId]);

    return [
        'success' => true,
        'message' => 'Video room started. Patient can now join.',
        'code' => $created ? 'created' : 'resumed',
        'http_status' => 200,
        'token' => $token,
        'consultation_id' => $consultationId,
        'patient_id' => (int) $consultation['patient_id'],
        'consultation_status' => (string) ($fresh->fetchColumn() ?: 'in_consultation'),
        'created' => $created,
    ];
}

function consultation_video_sessions_ensure_patient_left_column(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM video_sessions')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (!in_array('patient_left_at', $cols, true)) {
            $pdo->exec('ALTER TABLE video_sessions ADD COLUMN patient_left_at DATETIME NULL DEFAULT NULL AFTER ended_at');
        }
    } catch (Throwable $e) {
        error_log('consultation_video_sessions_ensure_patient_left_column: ' . $e->getMessage());
    }
}

/**
 * Patient temporarily left the room. Does not end video_sessions or complete the consultation.
 *
 * @return array{success:bool,consultation_id:int,marked:bool,video_status:string,consultation_status:string}
 */
function consultation_patient_mark_temporarily_left(PDO $pdo, string $roomToken, int $patientId): array
{
    $empty = [
        'success' => true,
        'consultation_id' => 0,
        'marked' => false,
        'video_status' => '',
        'consultation_status' => '',
    ];
    $roomToken = trim($roomToken);
    if ($roomToken === '' || $patientId <= 0) {
        return $empty;
    }

    consultation_video_sessions_ensure_patient_left_column($pdo);

    try {
        $stmt = $pdo->prepare("
            SELECT vs.id, vs.consultation_id, vs.status AS video_status, c.status AS consultation_status
            FROM video_sessions vs
            JOIN consultations c ON c.id = vs.consultation_id
            WHERE vs.room_token = ?
              AND c.patient_id = ?
            LIMIT 1
        ");
        $stmt->execute([$roomToken, $patientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return $empty;
        }

        $videoStatus = strtolower(trim((string) ($row['video_status'] ?? '')));
        if ($videoStatus === 'active') {
            $pdo->prepare("
                UPDATE video_sessions
                SET patient_left_at = NOW()
                WHERE id = ?
                  AND status = 'active'
            ")->execute([(int) ($row['id'] ?? 0)]);
        }

        return [
            'success' => true,
            'consultation_id' => (int) ($row['consultation_id'] ?? 0),
            'marked' => $videoStatus === 'active',
            'video_status' => $videoStatus,
            'consultation_status' => (string) ($row['consultation_status'] ?? ''),
        ];
    } catch (Throwable $e) {
        error_log('consultation_patient_mark_temporarily_left: ' . $e->getMessage());

        return $empty;
    }
}

function consultation_patient_clear_temporarily_left(PDO $pdo, string $roomToken, int $patientId): void
{
    $roomToken = trim($roomToken);
    if ($roomToken === '' || $patientId <= 0) {
        return;
    }
    try {
        consultation_video_sessions_ensure_patient_left_column($pdo);
        $pdo->prepare("
            UPDATE video_sessions vs
            INNER JOIN consultations c ON c.id = vs.consultation_id
            SET vs.patient_left_at = NULL
            WHERE vs.room_token = ?
              AND c.patient_id = ?
              AND vs.status = 'active'
        ")->execute([$roomToken, $patientId]);
    } catch (Throwable $e) {
        error_log('consultation_patient_clear_temporarily_left: ' . $e->getMessage());
    }
}
