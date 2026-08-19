<?php
declare(strict_types=1);

require_once __DIR__ . '/case_reports_schema.php';
require_once __DIR__ . '/audit_log.php';
require_once __DIR__ . '/notification_events.php';
require_once __DIR__ . '/provider_patient_access.php';
require_once __DIR__ . '/triage_assessment_schema.php';
require_once __DIR__ . '/patient_consultation_cancel.php';

function triage_case_is_emergency_row(array $row): bool
{
    $level = strtolower(trim((string) ($row['triage_level'] ?? $row['triage_classification'] ?? '')));
    if ($level === 'emergency') {
        return true;
    }
    return stripos((string) ($row['urgency_label'] ?? ''), 'emergency') !== false;
}

function triage_case_is_terminated_row(array $row): bool
{
    return strtolower(trim((string) ($row['outcome'] ?? ''))) === 'terminated';
}

/**
 * @return array<string, mixed>|null
 */
function triage_case_fetch(PDO $pdo, int $triageId): ?array
{
    triage_assessment_ensure_schema($pdo);
    $stmt = $pdo->prepare('
        SELECT tr.*, u.first_name, u.last_name, u.account_status
        FROM triage_results tr
        JOIN users u ON u.id = tr.patient_id
        WHERE tr.id = ?
        LIMIT 1
    ');
    $stmt->execute([$triageId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function case_report_has_active(PDO $pdo, int $triageId): bool
{
    case_reports_ensure_schema($pdo);
    $statuses = case_report_active_statuses();
    $placeholders = implode(',', array_fill(0, count($statuses), '?'));
    $params = array_merge([$triageId, case_report_source_case()], $statuses);
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM case_reports
        WHERE triage_id = ?
          AND source_type = ?
          AND status IN ({$placeholders})
    ");
    $stmt->execute($params);
    return (int) $stmt->fetchColumn() > 0;
}

function case_report_has_active_consultation(PDO $pdo, int $consultationId): bool
{
    case_reports_ensure_schema($pdo);
    $statuses = case_report_active_statuses();
    $placeholders = implode(',', array_fill(0, count($statuses), '?'));
    $params = array_merge([$consultationId, case_report_source_video()], $statuses);
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM case_reports
        WHERE consultation_id = ?
          AND source_type = ?
          AND status IN ({$placeholders})
    ");
    $stmt->execute($params);
    return (int) $stmt->fetchColumn() > 0;
}

function case_report_validate_notes_for_reason(string $reason, string $notes): ?string
{
    if (strtolower(trim($reason)) !== 'other') {
        return null;
    }
    $notes = trim($notes);
    if ($notes === '') {
        return 'Please describe what happened when selecting Other.';
    }
    if (mb_strlen($notes) < 10) {
        return 'Please provide a description of at least 10 characters for Other.';
    }
    return null;
}

/**
 * @return array{success: bool, message: string, report_id?: int}
 */
function case_report_submit(
    PDO $pdo,
    int $triageId,
    int $providerId,
    string $reason,
    string $notes = ''
): array {
    case_reports_ensure_schema($pdo);

    $reason = strtolower(trim($reason));
    if (!in_array($reason, case_report_valid_case_reasons(), true)) {
        return ['success' => false, 'message' => 'Please select a valid report reason.'];
    }

    $notesError = case_report_validate_notes_for_reason($reason, $notes);
    if ($notesError !== null) {
        return ['success' => false, 'message' => $notesError];
    }

    $case = triage_case_fetch($pdo, $triageId);
    if (!$case) {
        return ['success' => false, 'message' => 'Case not found.'];
    }

    $patientId = (int) ($case['patient_id'] ?? 0);
    $access = provider_patient_assert_access($pdo, $providerId, $patientId, 0);
    if (!$access['allowed']) {
        return ['success' => false, 'message' => 'Access denied.'];
    }

    if (case_report_has_active($pdo, $triageId)) {
        return [
            'success' => false,
            'message' => 'This case has already been reported and is currently under review.',
            'code'    => 'duplicate_report',
        ];
    }

    $notes = trim($notes);
    $stmt = $pdo->prepare('
        INSERT INTO case_reports (
            source_type, triage_id, patient_id, reported_by, reason, notes, status
        ) VALUES (?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        case_report_source_case(),
        $triageId,
        $patientId,
        $providerId,
        $reason,
        $notes !== '' ? $notes : null,
        'pending',
    ]);
    $reportId = (int) $pdo->lastInsertId();

    audit_log($pdo, [
        'patient_id'  => $patientId,
        'action_type' => AuditAction::REPORT_CREATED,
        'description' => 'Provider reported triage case #' . $triageId . ' for administrative review.',
        'meta'        => [
            'source_type' => case_report_source_case(),
            'case_id'     => $triageId,
            'report_id'   => $reportId,
            'reason'      => $reason,
            'reported_by' => $providerId,
        ],
    ]);

    NotificationEvents::caseReportSubmittedForAdmin($pdo, $reportId, $triageId, $patientId, $providerId, $reason);

    return [
        'success'   => true,
        'message'   => 'Case report submitted. An administrator will review it.',
        'report_id' => $reportId,
    ];
}

/**
 * @return array<string, mixed>|null
 */
function consultation_violation_fetch(PDO $pdo, int $consultationId): ?array
{
    $stmt = $pdo->prepare('
        SELECT c.*, u.first_name, u.last_name, u.account_status,
               s.id AS appointment_slot_id
        FROM consultations c
        JOIN users u ON u.id = c.patient_id
        LEFT JOIN appointment_slots s ON s.consultation_id = c.id AND s.status IN (\'booked\', \'completed\')
        WHERE c.id = ?
        LIMIT 1
    ');
    $stmt->execute([$consultationId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Report a possible violation during a live video consultation.
 *
 * @return array{success: bool, message: string, report_id?: int, ended?: bool}
 */
function consultation_violation_report_submit(
    PDO $pdo,
    int $consultationId,
    int $providerId,
    string $reason,
    string $notes = '',
    bool $endConsultation = false
): array {
    case_reports_ensure_schema($pdo);

    $reason = strtolower(trim($reason));
    if (!in_array($reason, case_report_valid_video_reasons(), true)) {
        return ['success' => false, 'message' => 'Please select a valid violation reason.'];
    }

    $notesError = case_report_validate_notes_for_reason($reason, $notes);
    if ($notesError !== null) {
        return ['success' => false, 'message' => $notesError];
    }

    $consult = consultation_violation_fetch($pdo, $consultationId);
    if (!$consult) {
        return ['success' => false, 'message' => 'Consultation not found.'];
    }

    if ((int) ($consult['provider_id'] ?? 0) !== $providerId) {
        return ['success' => false, 'message' => 'Access denied.'];
    }

    $patientId = (int) ($consult['patient_id'] ?? 0);
    $access = provider_patient_assert_access($pdo, $providerId, $patientId, $consultationId);
    if (!$access['allowed']) {
        return ['success' => false, 'message' => 'Access denied.'];
    }

    if (case_report_has_active_consultation($pdo, $consultationId)) {
        return [
            'success' => false,
            'message' => 'This consultation has already been reported and is currently under review.',
            'code'    => 'duplicate_report',
        ];
    }

    $consultStatus = (string) ($consult['status'] ?? '');
    $triageId = null;
    $consultCols = $pdo->query('SHOW COLUMNS FROM consultations')->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('triage_result_id', $consultCols, true) && !empty($consult['triage_result_id'])) {
        $triageId = (int) $consult['triage_result_id'];
    }

    $notes = trim($notes);
    $stmt = $pdo->prepare('
        INSERT INTO case_reports (
            source_type, triage_id, consultation_id, appointment_id,
            patient_id, reported_by, reason, notes,
            consultation_status_at_report, status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        case_report_source_video(),
        $triageId > 0 ? $triageId : null,
        $consultationId,
        !empty($consult['appointment_slot_id']) ? (int) $consult['appointment_slot_id'] : null,
        $patientId,
        $providerId,
        $reason,
        $notes !== '' ? $notes : null,
        $consultStatus !== '' ? $consultStatus : null,
        'pending',
    ]);
    $reportId = (int) $pdo->lastInsertId();

    audit_log($pdo, [
        'patient_id'  => $patientId,
        'action_type' => AuditAction::REPORT_CREATED,
        'description' => 'Provider reported possible violation during consultation #' . $consultationId . '.',
        'meta'        => [
            'source_type'      => case_report_source_video(),
            'consultation_id'  => $consultationId,
            'report_id'        => $reportId,
            'reason'           => $reason,
            'reported_by'      => $providerId,
            'consultation_status' => $consultStatus,
        ],
    ]);

    NotificationEvents::consultationViolationReportedForAdmin(
        $pdo,
        $reportId,
        $consultationId,
        $patientId,
        $providerId,
        $reason
    );

    $ended = false;
    if ($endConsultation) {
        $endResult = consultation_end_from_violation($pdo, $consultationId, $providerId, 'Ended after possible violation report.');
        $ended = !empty($endResult['success']);
    }

    return [
        'success'   => true,
        'message'   => $endConsultation
            ? 'Possible violation reported. The current consultation has been ended.'
            : 'Possible violation reported. An administrator will review it.',
        'report_id' => $reportId,
        'ended'     => $ended,
    ];
}

/**
 * End the current video consultation session only — preserves records and account.
 *
 * @return array{success: bool, message: string}
 */
function consultation_end_from_violation(
    PDO $pdo,
    int $consultationId,
    int $providerId,
    string $reason = ''
): array {
    $consult = consultation_violation_fetch($pdo, $consultationId);
    if (!$consult) {
        return ['success' => false, 'message' => 'Consultation not found.'];
    }
    if ((int) ($consult['provider_id'] ?? 0) !== $providerId) {
        return ['success' => false, 'message' => 'Access denied.'];
    }

    $patientId = (int) ($consult['patient_id'] ?? 0);
    $previousStatus = (string) ($consult['status'] ?? '');

    try {
        $pdo->beginTransaction();

        $pdo->prepare("
            UPDATE video_sessions
            SET status = 'ended', ended_at = NOW()
            WHERE consultation_id = ? AND status = 'active'
        ")->execute([$consultationId]);

        if (in_array($previousStatus, ['pending', 'scheduled', 'in_consultation', 'waiting'], true)) {
            $pdo->prepare("
                UPDATE consultations SET status = 'completed' WHERE id = ? AND status NOT IN ('completed', 'cancelled')
            ")->execute([$consultationId]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('consultation_end_from_violation: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Unable to end consultation. Please try again.'];
    }

    audit_log($pdo, [
        'patient_id'  => $patientId,
        'action_type' => AuditAction::CONSULTATION_TERMINATED,
        'description' => 'Provider ended consultation #' . $consultationId . ' after a possible violation.',
        'meta'        => [
            'consultation_id'  => $consultationId,
            'terminated_by'    => $providerId,
            'reason'           => trim($reason),
            'previous_status'  => $previousStatus,
            'new_status'       => 'completed',
        ],
    ]);

    NotificationEvents::consultationEndedForPatient($pdo, $patientId, $consultationId, $providerId);

    return [
        'success' => true,
        'message' => 'Consultation ended. Medical records and the patient account were not affected.',
    ];
}

/**
 * Terminate the current case only — never affects the patient account.
 *
 * @return array{success: bool, message: string}
 */
function case_terminate(
    PDO $pdo,
    int $triageId,
    int $providerId,
    string $reason
): array {
    triage_assessment_ensure_schema($pdo);
    case_reports_ensure_schema($pdo);

    $reason = trim($reason);
    if ($reason === '' || mb_strlen($reason) < 5) {
        return ['success' => false, 'message' => 'Please provide a reason (at least 5 characters).'];
    }

    $case = triage_case_fetch($pdo, $triageId);
    if (!$case) {
        return ['success' => false, 'message' => 'Case not found.'];
    }

    $patientId = (int) ($case['patient_id'] ?? 0);
    $access = provider_patient_assert_access($pdo, $providerId, $patientId, 0);
    if (!$access['allowed']) {
        return ['success' => false, 'message' => 'Access denied.'];
    }

    if (triage_case_is_emergency_row($case)) {
        return [
            'success' => false,
            'message' => 'Emergency cases cannot be terminated through this workflow. Use the existing emergency clinical pathway.',
        ];
    }

    if (triage_case_is_terminated_row($case)) {
        return ['success' => false, 'message' => 'This case has already been terminated.'];
    }

    $previousOutcome = (string) ($case['outcome'] ?? '');
    $previousStatus = (string) ($case['status'] ?? '');

    try {
        $pdo->beginTransaction();

        $pdo->prepare("
            UPDATE triage_results
            SET outcome = 'terminated',
                status = 'completed',
                recommendation_status = CASE
                    WHEN recommendation_status IN ('pending_approval', 'hidden') THEN 'rejected'
                    ELSE recommendation_status
                END
            WHERE id = ?
        ")->execute([$triageId]);

        $consultCols = $pdo->query('SHOW COLUMNS FROM consultations')->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('triage_result_id', $consultCols, true)) {
            $consults = $pdo->prepare("
                SELECT id, patient_id, consult_date, consult_time
                FROM consultations
                WHERE triage_result_id = ?
                  AND status NOT IN ('completed', 'cancelled')
            ");
            $consults->execute([$triageId]);
            foreach ($consults->fetchAll(PDO::FETCH_ASSOC) as $consult) {
                $cid = (int) ($consult['id'] ?? 0);
                if ($cid <= 0) {
                    continue;
                }
                $pdo->prepare("UPDATE consultations SET status = 'cancelled' WHERE id = ?")->execute([$cid]);
                consultation_release_booked_slots(
                    $pdo,
                    $cid,
                    (int) ($consult['patient_id'] ?? $patientId),
                    (string) ($consult['consult_date'] ?? ''),
                    (string) ($consult['consult_time'] ?? '')
                );
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('case_terminate: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Unable to terminate case. Please try again.'];
    }

    try {
        require_once __DIR__ . '/patient_slot_waitlist.php';
        patient_slot_waitlist_cancel_for_triage($pdo, $triageId);
        patient_slot_waitlist_after_slots_changed($pdo);
    } catch (Throwable $e) {
        error_log('case_terminate waitlist: ' . $e->getMessage());
    }

    audit_log($pdo, [
        'patient_id'  => $patientId,
        'action_type' => AuditAction::CASE_TERMINATED,
        'description' => 'Provider terminated triage case #' . $triageId . '.',
        'meta'        => [
            'case_id'          => $triageId,
            'terminated_by'    => $providerId,
            'reason'           => $reason,
            'previous_outcome' => $previousOutcome,
            'previous_status'  => $previousStatus,
            'new_outcome'      => 'terminated',
            'new_status'       => 'completed',
        ],
    ]);

    NotificationEvents::caseTerminatedForPatient($pdo, $patientId, $triageId, $providerId);

    return [
        'success' => true,
        'message' => 'Case terminated. The patient account and medical records were not affected.',
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function case_reports_admin_list(PDO $pdo, ?string $statusFilter = null): array
{
    case_reports_ensure_schema($pdo);
    triage_assessment_ensure_schema($pdo);

    $sql = "
        SELECT
            cr.*,
            tr.chief_complaint, tr.triage_level, tr.triage_classification, tr.status AS triage_status,
            tr.outcome AS triage_outcome, tr.assessed_at,
            c.status AS consultation_status, c.consult_date, c.consult_time,
            pu.first_name AS patient_first, pu.last_name AS patient_last,
            pu.account_status AS patient_account_status,
            ru.first_name AS reporter_first, ru.last_name AS reporter_last,
            au.first_name AS reviewer_first, au.last_name AS reviewer_last
        FROM case_reports cr
        LEFT JOIN triage_results tr ON tr.id = cr.triage_id
        LEFT JOIN consultations c ON c.id = cr.consultation_id
        JOIN users pu ON pu.id = cr.patient_id
        JOIN users ru ON ru.id = cr.reported_by
        LEFT JOIN users au ON au.id = cr.reviewed_by
    ";
    $params = [];
    if ($statusFilter !== null && $statusFilter !== '' && $statusFilter !== 'all') {
        $sql .= ' WHERE cr.status = ?';
        $params[] = strtolower(trim($statusFilter));
    }
    $sql .= ' ORDER BY cr.created_at DESC LIMIT 500';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $row['patient_name'] = trim(($row['patient_first'] ?? '') . ' ' . ($row['patient_last'] ?? ''));
        $row['reporter_name'] = trim(($row['reporter_first'] ?? '') . ' ' . ($row['reporter_last'] ?? ''));
        $row['reviewer_name'] = trim(($row['reviewer_first'] ?? '') . ' ' . ($row['reviewer_last'] ?? ''));
        $row['reason_label'] = case_report_reason_label((string) ($row['reason'] ?? ''));
        $row['source_label'] = case_report_source_label((string) ($row['source_type'] ?? 'case'));
        $row['status_label'] = case_report_status_label((string) ($row['status'] ?? 'pending'));
        $row['consultation_ref'] = case_report_consultation_ref(
            !empty($row['consultation_id']) ? (int) $row['consultation_id'] : null
        );
        $row['case_terminated'] = strtolower((string) ($row['triage_outcome'] ?? '')) === 'terminated';
        $row['consultation_status_display'] = (string) (
            $row['consultation_status_at_report']
            ?? $row['consultation_status']
            ?? ''
        );
    }
    unset($row);

    return $rows;
}

/**
 * @return array<string, mixed>|null
 */
function case_report_admin_detail(PDO $pdo, int $reportId): ?array
{
    case_reports_ensure_schema($pdo);
    $stmt = $pdo->prepare('
        SELECT
            cr.*,
            tr.chief_complaint, tr.symptoms, tr.triage_level, tr.triage_classification,
            tr.urgency_label, tr.recommendations, tr.status AS triage_status,
            tr.outcome AS triage_outcome, tr.assessed_at, tr.detected_symptoms_json,
            c.status AS consultation_status, c.consult_date, c.consult_time, c.consult_type,
            pu.first_name AS patient_first, pu.last_name AS patient_last,
            pu.account_status AS patient_account_status, pu.email AS patient_email,
            ru.first_name AS reporter_first, ru.last_name AS reporter_last
        FROM case_reports cr
        LEFT JOIN triage_results tr ON tr.id = cr.triage_id
        LEFT JOIN consultations c ON c.id = cr.consultation_id
        JOIN users pu ON pu.id = cr.patient_id
        JOIN users ru ON ru.id = cr.reported_by
        WHERE cr.id = ?
        LIMIT 1
    ');
    $stmt->execute([$reportId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $row['patient_name'] = trim(($row['patient_first'] ?? '') . ' ' . ($row['patient_last'] ?? ''));
    $row['reporter_name'] = trim(($row['reporter_first'] ?? '') . ' ' . ($row['reporter_last'] ?? ''));
    $row['reason_label'] = case_report_reason_label((string) ($row['reason'] ?? ''));
    $row['source_label'] = case_report_source_label((string) ($row['source_type'] ?? 'case'));
    $row['status_label'] = case_report_status_label((string) ($row['status'] ?? 'pending'));
    $row['consultation_ref'] = case_report_consultation_ref(
        !empty($row['consultation_id']) ? (int) $row['consultation_id'] : null
    );
    $row['case_terminated'] = strtolower((string) ($row['triage_outcome'] ?? '')) === 'terminated';
    $row['consultation_status_display'] = (string) (
        $row['consultation_status_at_report']
        ?? $row['consultation_status']
        ?? ''
    );
    return $row;
}

/**
 * @return array{success: bool, message: string}
 */
function case_report_admin_review(
    PDO $pdo,
    int $reportId,
    int $adminId,
    string $action,
    string $adminNote = '',
    bool $isSuperadmin = false
): array {
    case_reports_ensure_schema($pdo);

    $action = strtolower(trim($action));
    $allowed = ['dismiss', 'confirm', 'escalate', 'under_review'];
    if (!in_array($action, $allowed, true)) {
        return ['success' => false, 'message' => 'Invalid review action.'];
    }

    $report = case_report_admin_detail($pdo, $reportId);
    if (!$report) {
        return ['success' => false, 'message' => 'Report not found.'];
    }

    $previousStatus = (string) ($report['status'] ?? '');
    $patientId = (int) ($report['patient_id'] ?? 0);
    $triageId = (int) ($report['triage_id'] ?? 0);

    $newStatus = match ($action) {
        'dismiss'      => 'dismissed',
        'confirm'      => 'confirmed',
        'escalate'     => 'escalated',
        'under_review' => 'under_review',
        default        => $previousStatus,
    };

    if ($action === 'escalate' && !$isSuperadmin) {
        // Admin may escalate; superadmin reviews escalated queue.
    }

    if (in_array($previousStatus, ['dismissed', 'confirmed'], true) && $action !== 'escalate') {
        return ['success' => false, 'message' => 'This report has already been finalized.'];
    }

    $adminNote = trim($adminNote);
    $pdo->prepare('
        UPDATE case_reports
        SET status = ?, reviewed_by = ?, reviewed_at = NOW(), admin_note = COALESCE(?, admin_note)
        WHERE id = ?
    ')->execute([
        $newStatus,
        $adminId,
        $adminNote !== '' ? $adminNote : null,
        $reportId,
    ]);

    $auditAction = match ($action) {
        'dismiss'  => AuditAction::REPORT_DISMISSED,
        'confirm'  => AuditAction::VIOLATION_CONFIRMED,
        'escalate' => AuditAction::REPORT_ESCALATED,
        default    => AuditAction::REPORT_CREATED,
    };

    audit_log($pdo, [
        'patient_id'  => $patientId,
        'action_type' => $auditAction,
        'description' => 'Report #' . $reportId . ' marked as ' . $newStatus . '.',
        'meta'        => [
            'report_id'         => $reportId,
            'source_type'       => (string) ($report['source_type'] ?? ''),
            'case_id'           => $triageId,
            'consultation_id'   => (int) ($report['consultation_id'] ?? 0),
            'reviewed_by'       => $adminId,
            'previous_status'   => $previousStatus,
            'new_status'        => $newStatus,
        ],
    ]);

    if ($action === 'escalate') {
        NotificationEvents::caseReportEscalatedForSuperadmin(
            $pdo,
            $reportId,
            $triageId > 0 ? $triageId : (int) ($report['consultation_id'] ?? 0),
            $patientId,
            $adminId
        );
    }

    return [
        'success' => true,
        'message' => match ($action) {
            'dismiss'  => 'Report dismissed. Clinical records were not changed.',
            'confirm'  => 'Possible violation confirmed. You may apply account restrictions if appropriate.',
            'escalate' => 'Report escalated for Super Administrator review.',
            default    => 'Report updated.',
        },
        'new_status' => $newStatus,
    ];
}

function case_reports_pending_count(PDO $pdo): int
{
    case_reports_ensure_schema($pdo);
    try {
        return (int) $pdo->query("
            SELECT COUNT(*) FROM case_reports WHERE status IN ('pending', 'under_review')
        ")->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Active report meta for provider triage list (one query).
 *
 * @param list<int> $triageIds
 * @return array<int, array{has_active: bool, status: string}>
 */
function case_reports_active_map(PDO $pdo, array $triageIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $triageIds))));
    if ($ids === []) {
        return [];
    }
    case_reports_ensure_schema($pdo);
    $statuses = case_report_active_statuses();
    $phIds = implode(',', array_fill(0, count($ids), '?'));
    $phSt = implode(',', array_fill(0, count($statuses), '?'));
    $stmt = $pdo->prepare("
        SELECT triage_id, status
        FROM case_reports
        WHERE triage_id IN ({$phIds})
          AND source_type = ?
          AND status IN ({$phSt})
        ORDER BY created_at DESC
    ");
    $stmt->execute(array_merge($ids, [case_report_source_case()], $statuses));
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $tid = (int) ($row['triage_id'] ?? 0);
        if ($tid > 0 && !isset($map[$tid])) {
            $map[$tid] = ['has_active' => true, 'status' => (string) ($row['status'] ?? 'pending')];
        }
    }
    return $map;
}
