<?php
/**
 * Auto-end consultations after their scheduled slot time has passed.
 */
require_once __DIR__ . '/bhw_patient_workflow.php';

/**
 * Mark overdue consultations ended and close any stale video sessions.
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
           AND s.status = 'booked'
        WHERE c.status IN ('pending', 'scheduled', 'in_consultation')
          {$scope}
        HAVING session_end_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)
    ");
    $stmt->execute($params);
    $expired = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$expired) {
        return 0;
    }

    $updated = 0;

    $complete = $pdo->prepare("
        UPDATE consultations
        SET status = 'completed'
        WHERE id = ?
          AND status = 'in_consultation'
    ");
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
            $complete->execute([$id]);
            $updated += $complete->rowCount();
            if ($complete->rowCount() > 0) {
                $pidStmt = $pdo->prepare('SELECT patient_id FROM consultations WHERE id = ? LIMIT 1');
                $pidStmt->execute([$id]);
                $pid = (int) ($pidStmt->fetchColumn() ?: 0);
                if ($pid > 0) {
                    BhwPatientWorkflow::onConsultationCompleted($pdo, $pid, 'session_expired');
                }
            }
        } else {
            $cancel->execute([$id]);
            $updated += $cancel->rowCount();
        }

        $end_video->execute([$id]);
    }

    return $updated;
}
