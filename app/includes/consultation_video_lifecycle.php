<?php
/**
 * Video consultation lifecycle helpers.
 * Ends the WebRTC room and persists consultation completion without finalizing SOAP.
 */
declare(strict_types=1);

/**
 * Provider ends an active (or already-ended) video room and auto-completes the consultation.
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
    $newlyCompleted = false;

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

        if (in_array($consultStatus, ['in_consultation', 'scheduled', 'pending'], true)) {
            $hasCompletedAt = false;
            try {
                $col = $pdo->query("SHOW COLUMNS FROM consultations LIKE 'completed_at'");
                $hasCompletedAt = (bool) ($col && $col->fetch(PDO::FETCH_ASSOC));
            } catch (Throwable $e) {
                $hasCompletedAt = false;
            }

            if ($hasCompletedAt) {
                $complete = $pdo->prepare("
                    UPDATE consultations
                    SET status = 'completed',
                        completed_at = COALESCE(completed_at, NOW())
                    WHERE id = ?
                      AND provider_id = ?
                      AND status IN ('in_consultation', 'scheduled', 'pending')
                ");
            } else {
                $complete = $pdo->prepare("
                    UPDATE consultations
                    SET status = 'completed'
                    WHERE id = ?
                      AND provider_id = ?
                      AND status IN ('in_consultation', 'scheduled', 'pending')
                ");
            }
            $complete->execute([$consultationId, $providerId]);
            $newlyCompleted = $complete->rowCount() > 0;
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('consultation_provider_end_video_session: ' . $e->getMessage());
        return $fail('Could not end video session.');
    }

    if ($newlyCompleted) {
        try {
            require_once __DIR__ . '/appointment_slots.php';
            appointment_slot_set_consultation_status($pdo, $consultationId, 'completed');
        } catch (Throwable $e) {
            error_log('appointment_slot_set_consultation_status after video end: ' . $e->getMessage());
        }

        try {
            require_once __DIR__ . '/bhw_patient_workflow.php';
            if ($patientId > 0) {
                BhwPatientWorkflow::onConsultationCompleted($pdo, $patientId, 'video_session_ended');
            }
        } catch (Throwable $e) {
            error_log('BhwPatientWorkflow after video end: ' . $e->getMessage());
        }

        try {
            require_once __DIR__ . '/patient_booking_status.php';
            patient_triage_close_cases_for_consultation($pdo, $consultationId);
        } catch (Throwable $e) {
            error_log('patient_triage_close_cases_for_consultation after video end: ' . $e->getMessage());
        }
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
        'message' => $newlyCompleted
            ? 'Consultation ended and saved to history.'
            : 'Video session closed.',
        'consultation_id' => $consultationId,
        'patient_id' => $patientId,
        'video_status' => (string) ($live['video_status'] ?? 'ended'),
        'consultation_status' => (string) ($live['consultation_status'] ?? $consultStatus),
        'started_at' => $live['started_at'] ?? $row['started_at'] ?? null,
        'ended_at' => $live['ended_at'] ?? $row['ended_at'] ?? null,
        'newly_ended' => $newlyEnded,
        'newly_completed' => $newlyCompleted,
    ];
}
