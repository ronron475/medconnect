<?php
/**
 * Auto-end consultations after their scheduled slot time has passed.
 */
require_once __DIR__ . '/patient_booking_status.php';

/**
 * Cancel a visit the patient never joined after the slot ends.
 * An in-progress consultation stays open until the provider ends it and saves SOAP.
 *
 * @return int Number of consultations updated
 */
function consultations_auto_expire(PDO $pdo, ?int $patient_id = null, ?int $provider_id = null): int
{
    $scope  = '';
    $params = [];

    if ($patient_id !== null) {
        $scope   .= ' AND c.patient_id = ?';
        $params[] = $patient_id;
    }
    if ($provider_id !== null) {
        $scope   .= ' AND c.provider_id = ?';
        $params[] = $provider_id;
    }

    $stmt = $pdo->prepare("
        SELECT
            c.id,
            c.status,
            TIMESTAMP(
                COALESCE(s.slot_date, c.consult_date),
                COALESCE(
                    s.end_time,
                    ADDTIME(COALESCE(s.start_time, c.consult_time, '00:00:00'), '00:30:00')
                )
            ) AS session_end_at
        FROM consultations c
        LEFT JOIN appointment_slots s
            ON s.consultation_id = c.id
           AND s.status IN ('booked', 'blocked')
        WHERE c.status IN ('pending', 'scheduled', 'in_consultation')
          {$scope}
        HAVING session_end_at <= NOW()
    ");
    $stmt->execute($params);
    $expired = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$expired) {
        return 0;
    }

    $updated = 0;

    $cancel = $pdo->prepare("
        UPDATE consultations
        SET status = 'cancelled'
        WHERE id = ?
          AND status IN ('pending', 'scheduled')
    ");
    $end_video = $pdo->prepare("
        UPDATE video_sessions
        SET status = 'ended', ended_at = NOW()
        WHERE consultation_id = ?
          AND status = 'active'
    ");

    foreach ($expired as $row) {
        $id = (int) $row['id'];
        $status = (string) $row['status'];

        if ($status === 'in_consultation') {
            // A live or in-progress visit stays open past the slot. Only provider
            // End plus SOAP finalize may complete it.
            continue;
        } else {
            $cancel->execute([$id]);
            $updated += $cancel->rowCount();
            if ($cancel->rowCount() > 0) {
                require_once __DIR__ . '/patient_consultation_cancel.php';
                consultation_release_booked_slots($pdo, $id);
                // Close linked care-tips / chief-complaint cases so a cancelled
                // visit never keeps the dashboard stuck on "Doctor reviewing"
                // or a locked previous complaint.
                patient_triage_close_cases_for_consultation($pdo, $id);
            }
        }

        $end_video->execute([$id]);
    }

    if ($updated > 0) {
        try {
            require_once __DIR__ . '/patient_slot_waitlist.php';
            patient_slot_waitlist_after_slots_changed($pdo);
        } catch (Throwable $e) {
            error_log('consultations_auto_expire waitlist: ' . $e->getMessage());
        }
    }

    return $updated;
}
