<?php
/**
 * Provider consultation history — grouped by patient_id (not patient name).
 */
declare(strict_types=1);

require_once __DIR__ . '/provider_patient_access.php';
require_once __DIR__ . '/consultation_video_history.php';

/** Statuses that count toward total visits. */
function provider_consultation_history_visit_statuses(): array
{
    return ['pending', 'scheduled', 'in_consultation', 'completed'];
}

/**
 * @return list<string>
 */
function provider_consultation_history_allowed_filters(): array
{
    return ['all', 'completed', 'scheduled', 'cancelled', 'active'];
}

/**
 * Grouped patient rows for provider consultation history.
 *
 * @return list<array<string,mixed>>
 */
function provider_consultation_history_patients(PDO $pdo, int $providerId, array $options = []): array
{
    if ($providerId <= 0) {
        return [];
    }

    $filter = strtolower(trim((string) ($options['filter'] ?? 'all')));
    if (!in_array($filter, provider_consultation_history_allowed_filters(), true)) {
        $filter = 'all';
    }

    $visitStatuses = provider_consultation_history_visit_statuses();
    if ($filter === 'cancelled') {
        $visitStatuses = ['cancelled'];
    }
    $placeholders = implode(',', array_fill(0, count($visitStatuses), '?'));

    $sql = "
        SELECT
            agg.patient_id,
            agg.total_visits,
            agg.latest_consultation_id,
            u.first_name,
            u.last_name,
            CONCAT(u.first_name, ' ', u.last_name) AS patient_name,
            CONCAT('MC-', LPAD(u.id, 6, '0')) AS patient_number,
            COALESCE(pr.age, '') AS age,
            COALESCE(pr.gender, '') AS sex,
            COALESCE(pr.contact_number, '') AS contact,
            lc.consult_date,
            lc.consult_time,
            lc.consult_type,
            lc.status AS latest_status,
            lc.provider_name,
            COALESCE(NULLIF(cn.diagnosis, ''), NULLIF(lc.diagnosis, ''), '') AS last_diagnosis,
            COALESCE(NULLIF(cn.subjective, ''), lc.consult_type, '') AS last_complaint
        FROM (
            SELECT
                c.patient_id,
                COUNT(*) AS total_visits,
                SUBSTRING_INDEX(
                    GROUP_CONCAT(c.id ORDER BY c.consult_date DESC, c.consult_time DESC, c.id DESC),
                    ',',
                    1
                ) AS latest_consultation_id
            FROM consultations c
            WHERE c.provider_id = ?
              AND c.status IN ($placeholders)
            GROUP BY c.patient_id
        ) agg
        JOIN consultations lc ON lc.id = agg.latest_consultation_id
        JOIN users u ON u.id = agg.patient_id AND u.role = 'patient'
        LEFT JOIN patient_registrations pr ON pr.user_id = u.id
        LEFT JOIN clinical_notes cn ON cn.consultation_id = lc.id
        WHERE 1 = 1
    ";

    $params = array_merge([$providerId], $visitStatuses);

    if ($filter === 'completed') {
        $sql .= " AND lc.status = 'completed'";
    } elseif ($filter === 'scheduled') {
        $sql .= " AND lc.status IN ('scheduled', 'pending')";
    } elseif ($filter === 'cancelled') {
        /* latest row already limited to cancelled consultations */
    } elseif ($filter === 'active') {
        $sql .= " AND lc.status IN ('scheduled', 'pending', 'in_consultation')";
    }

    $sql .= ' ORDER BY lc.consult_date DESC, lc.consult_time DESC, agg.patient_id DESC';

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        error_log('provider_consultation_history_patients: ' . $e->getMessage());
        return [];
    }
}

/**
 * All consultations for one patient with this provider (newest first).
 *
 * @return array{patient: ?array, consultations: list<array>}
 */
function provider_consultation_history_patient_detail(PDO $pdo, int $providerId, int $patientId): array
{
    $empty = ['patient' => null, 'consultations' => []];
    if ($providerId <= 0 || $patientId <= 0) {
        return $empty;
    }

    $access = provider_patient_assert_access($pdo, $providerId, $patientId, 0);
    if (!$access['allowed']) {
        return $empty;
    }

    try {
        $pStmt = $pdo->prepare("
            SELECT
                u.id,
                u.first_name,
                u.last_name,
                CONCAT(u.first_name, ' ', u.last_name) AS patient_name,
                CONCAT('MC-', LPAD(u.id, 6, '0')) AS patient_number,
                COALESCE(pr.age, '') AS age,
                COALESCE(pr.gender, '') AS sex,
                COALESCE(pr.contact_number, '') AS contact,
                COALESCE(CONCAT_WS(', ',
                    NULLIF(pr.barangay, ''),
                    NULLIF(pr.city_municipality, ''),
                    NULLIF(pr.province, '')
                ), '') AS address
            FROM users u
            LEFT JOIN patient_registrations pr ON pr.user_id = u.id
            WHERE u.id = ? AND u.role = 'patient'
            LIMIT 1
        ");
        $pStmt->execute([$patientId]);
        $patient = $pStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$patient) {
            return $empty;
        }

        $hasCompletedAt = false;
        try {
            $colCheck = $pdo->query("SHOW COLUMNS FROM consultations LIKE 'completed_at'");
            $hasCompletedAt = (bool) ($colCheck && $colCheck->fetch(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            $hasCompletedAt = false;
        }
        $completedAtSelect = $hasCompletedAt ? 'c.completed_at,' : 'NULL AS completed_at,';

        $cStmt = $pdo->prepare("
            SELECT
                c.id,
                c.patient_id,
                c.provider_id,
                c.consult_date,
                c.consult_time,
                c.consult_type,
                c.status,
                {$completedAtSelect}
                COALESCE(NULLIF(c.provider_name, ''), CONCAT(pu.first_name, ' ', pu.last_name), '—') AS doctor_name,
                COALESCE(NULLIF(cn.diagnosis, ''), NULLIF(c.diagnosis, ''), '') AS diagnosis,
                COALESCE(NULLIF(cn.subjective, ''), c.consult_type, '') AS chief_complaint,
                cn.id AS clinical_note_id,
                cn.signature_data AS clinical_note_signature,
                cn.finalized_at AS clinical_note_finalized_at,
                s.slot_date,
                s.start_time,
                s.end_time
            FROM consultations c
            LEFT JOIN users pu ON pu.id = c.provider_id
            LEFT JOIN clinical_notes cn ON cn.consultation_id = c.id
            LEFT JOIN appointment_slots s ON s.consultation_id = c.id AND s.status = 'booked'
            WHERE c.patient_id = ? AND c.provider_id = ?
            ORDER BY c.consult_date DESC, c.consult_time DESC, c.id DESC
        ");
        $cStmt->execute([$patientId, $providerId]);
        $consultations = $cStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        require_once __DIR__ . '/provider_clinical_support.php';
        require_once __DIR__ . '/patient_consultation_records.php';
        consultation_video_history_enrich_rows(
            $pdo,
            $consultations,
            'doctor_name',
            (string) ($patient['patient_name'] ?? '')
        );
        foreach ($consultations as &$consultRow) {
            $cid = (int) ($consultRow['id'] ?? 0);
            if ($cid <= 0) {
                continue;
            }
            $consultRow['clinical_note_finalized'] = patient_consultation_is_finalized(
                (string) ($consultRow['status'] ?? ''),
                isset($consultRow['clinical_note_signature']) ? (string) $consultRow['clinical_note_signature'] : '',
                isset($consultRow['clinical_note_finalized_at']) ? (string) $consultRow['clinical_note_finalized_at'] : null
            );
            $support = provider_consultation_clinical_support($pdo, $cid, $patientId);
            $aiBucket = provider_clinical_support_normalize_bucket((string) ($support['ai_urgency_bucket'] ?? ''));
            $finalBucket = provider_clinical_support_normalize_bucket((string) ($support['risk_bucket'] ?? 'unknown'));
            $consultRow['ai_classification'] = $aiBucket !== 'unknown'
                ? provider_clinical_support_urgency_label($aiBucket)
                : '';
            $consultRow['final_classification'] = $finalBucket !== 'unknown'
                ? (trim((string) ($support['final_urgency'] ?? '')) ?: provider_clinical_support_urgency_label($finalBucket))
                : '';
        }
        unset($consultRow);

        return ['patient' => $patient, 'consultations' => $consultations];
    } catch (PDOException $e) {
        error_log('provider_consultation_history_patient_detail: ' . $e->getMessage());
        return $empty;
    }
}

function provider_consultation_status_label(string $status): string
{
    return match ($status) {
        'completed' => 'Completed',
        'scheduled' => 'Scheduled',
        'pending' => 'Pending',
        'in_consultation' => 'In consultation',
        'cancelled' => 'Cancelled',
        default => ucfirst(str_replace('_', ' ', $status)),
    };
}

function provider_consultation_status_chip_class(string $status): string
{
    return match ($status) {
        'completed' => 'pch-chip--completed',
        'scheduled', 'pending' => 'pch-chip--scheduled',
        'in_consultation' => 'pch-chip--active',
        'cancelled' => 'pch-chip--cancelled',
        default => 'pch-chip--neutral',
    };
}
