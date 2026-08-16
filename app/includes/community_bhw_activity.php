<?php
/**
 * Read-only BHW activity for a patient (provider consultation / patient My Health).
 * Does not require a BHW session. Does not create or change clinical records.
 */

function community_bhw_activity_load(PDO $pdo, int $patientId): array
{
    $empty = [
        'documents' => [],
        'visits'    => [],
        'referrals' => [],
        'total'     => 0,
    ];
    if ($patientId <= 0) {
        return $empty;
    }

    $documents = community_bhw_activity_documents($pdo, $patientId);
    $visits = community_bhw_activity_visits($pdo, $patientId);
    $referrals = community_bhw_activity_referrals($pdo, $patientId);

    return [
        'documents' => $documents,
        'visits'    => $visits,
        'referrals' => $referrals,
        'total'     => count($documents) + count($visits) + count($referrals),
    ];
}

function community_bhw_visit_type_label(string $type): string
{
    return match ($type) {
        'follow_up' => 'Follow-up',
        'monitoring' => 'Monitoring',
        'emergency_check' => 'Emergency check',
        'other' => 'Other',
        default => ucwords(str_replace('_', ' ', $type)),
    };
}

function community_bhw_patient_status_label(string $status): string
{
    return match ($status) {
        'improving' => 'Improving',
        'stable' => 'Stable',
        'worsening' => 'Worsening',
        'referred' => 'Referred',
        'unknown' => 'Unknown',
        default => ucwords(str_replace('_', ' ', $status)),
    };
}

function community_bhw_activity_date_label(?string $value, string $format = 'M j, Y'): string
{
    $value = trim((string) $value);
    if ($value === '' || $value === '0000-00-00' || str_starts_with($value, '0000-00-00')) {
        return '—';
    }
    $ts = strtotime($value);
    return $ts ? date($format, $ts) : '—';
}

/**
 * @return list<array<string, mixed>>
 */
function community_bhw_activity_documents(PDO $pdo, int $patientId): array
{
    try {
        if ($pdo->query("SHOW TABLES LIKE 'residency_documents'")->rowCount() === 0) {
            return [];
        }
        $cols = $pdo->query('SHOW COLUMNS FROM residency_documents')->fetchAll(PDO::FETCH_COLUMN);
        $select = 'id, original_name, file_name, status, uploaded_at';
        $hasType = in_array('document_type', $cols, true);
        if ($hasType) {
            $select .= ', document_type, document_title, description';
        }
        $stmt = $pdo->prepare("
            SELECT {$select}
            FROM residency_documents
            WHERE patient_id = ?
              AND file_name LIKE 'bhw_%'
            ORDER BY uploaded_at DESC
            LIMIT 30
        ");
        $stmt->execute([$patientId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return [];
    }

    $bhwNames = community_bhw_activity_uploader_names($pdo, $patientId);

    $out = [];
    foreach ($rows as $row) {
        $title = trim((string) ($row['document_title'] ?? ''));
        if ($title === '') {
            $title = trim((string) ($row['original_name'] ?? '')) ?: 'Document';
        }
        $uploadedAt = (string) ($row['uploaded_at'] ?? '');
        $out[] = [
            'id'          => (int) ($row['id'] ?? 0),
            'title'       => $title,
            'type'        => trim((string) ($row['document_type'] ?? '')) ?: 'Document',
            'description' => trim((string) ($row['description'] ?? '')),
            'status'      => trim((string) ($row['status'] ?? '')),
            'date_label'  => community_bhw_activity_date_label($uploadedAt),
            'bhw_name'    => community_bhw_activity_match_uploader($bhwNames, $uploadedAt, (string) ($row['original_name'] ?? '')),
        ];
    }
    return $out;
}

/**
 * @return list<array{at: string, file: string, name: string}>
 */
function community_bhw_activity_uploader_names(PDO $pdo, int $patientId): array
{
    try {
        if ($pdo->query("SHOW TABLES LIKE 'patient_audit_logs'")->rowCount() === 0) {
            return [];
        }
        $stmt = $pdo->prepare("
            SELECT al.created_at, al.meta,
                   TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS bhw_name
            FROM patient_audit_logs al
            LEFT JOIN users u ON u.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(al.meta, '$.bhw_id')) AS UNSIGNED)
            WHERE al.patient_id = ?
              AND al.action_type = 'bhw_document_uploaded'
            ORDER BY al.created_at DESC
            LIMIT 50
        ");
        $stmt->execute([$patientId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return [];
    }

    $out = [];
    foreach ($rows as $row) {
        $meta = json_decode((string) ($row['meta'] ?? ''), true);
        $out[] = [
            'at'   => (string) ($row['created_at'] ?? ''),
            'file' => (string) ($meta['file'] ?? ''),
            'name' => trim((string) ($row['bhw_name'] ?? '')),
        ];
    }
    return $out;
}

/**
 * @param list<array{at: string, file: string, name: string}> $uploaders
 */
function community_bhw_activity_match_uploader(array $uploaders, string $uploadedAt, string $originalName): string
{
    foreach ($uploaders as $item) {
        if ($item['file'] !== '' && $originalName !== '' && strcasecmp($item['file'], $originalName) === 0) {
            return $item['name'];
        }
    }
    if ($uploadedAt !== '') {
        $target = strtotime($uploadedAt);
        foreach ($uploaders as $item) {
            if ($item['at'] === '' || $item['name'] === '') {
                continue;
            }
            $at = strtotime($item['at']);
            if ($target && $at && abs($target - $at) <= 120) {
                return $item['name'];
            }
        }
    }
    return '';
}

/**
 * @return list<array<string, mixed>>
 */
function community_bhw_activity_visits(PDO $pdo, int $patientId): array
{
    try {
        if ($pdo->query("SHOW TABLES LIKE 'bhw_home_visits'")->rowCount() === 0) {
            return [];
        }
        $stmt = $pdo->prepare("
            SELECT hv.visit_date, hv.visit_type, hv.patient_status, hv.notes,
                   TRIM(CONCAT(COALESCE(b.first_name, ''), ' ', COALESCE(b.last_name, ''))) AS bhw_name
            FROM bhw_home_visits hv
            LEFT JOIN users b ON b.id = hv.bhw_id
            WHERE hv.patient_id = ?
            ORDER BY hv.visit_date DESC, hv.id DESC
            LIMIT 30
        ");
        $stmt->execute([$patientId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return [];
    }

    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'date_label' => community_bhw_activity_date_label((string) ($row['visit_date'] ?? '')),
            'type_label' => community_bhw_visit_type_label((string) ($row['visit_type'] ?? '')),
            'status'     => community_bhw_patient_status_label((string) ($row['patient_status'] ?? '')),
            'notes'      => trim((string) ($row['notes'] ?? '')),
            'bhw_name'   => trim((string) ($row['bhw_name'] ?? '')),
        ];
    }
    return $out;
}

/**
 * @return list<array<string, mixed>>
 */
function community_bhw_activity_referrals(PDO $pdo, int $patientId): array
{
    $bhwReferralIds = community_bhw_activity_referral_ids($pdo, $patientId);
    if ($bhwReferralIds === []) {
        return [];
    }

    try {
        if ($pdo->query("SHOW TABLES LIKE 'digital_referrals'")->rowCount() === 0) {
            return [];
        }
        $destCol = $pdo->query("SHOW COLUMNS FROM digital_referrals LIKE 'facility_name'")->fetch()
            ? 'facility_name'
            : 'destination_facility';
        $placeholders = implode(',', array_fill(0, count($bhwReferralIds), '?'));
        $stmt = $pdo->prepare("
            SELECT dr.id, dr.referral_type, dr.reason, dr.status, dr.created_at,
                   COALESCE(dr.{$destCol}, '') AS facility_display
            FROM digital_referrals dr
            WHERE dr.patient_id = ?
              AND dr.id IN ({$placeholders})
            ORDER BY dr.created_at DESC
            LIMIT 30
        ");
        $stmt->execute(array_merge([$patientId], $bhwReferralIds));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return [];
    }

    $namesById = community_bhw_activity_referral_bhw_names($pdo, $patientId);

    $out = [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        $out[] = [
            'id'         => $id,
            'type'       => trim((string) ($row['referral_type'] ?? '')) ?: 'Referral',
            'reason'     => trim((string) ($row['reason'] ?? '')),
            'facility'   => trim((string) ($row['facility_display'] ?? '')),
            'status'     => ucfirst(trim((string) ($row['status'] ?? 'pending'))),
            'date_label' => community_bhw_activity_date_label((string) ($row['created_at'] ?? '')),
            'bhw_name'   => $namesById[$id] ?? '',
        ];
    }
    return $out;
}

/**
 * @return list<int>
 */
function community_bhw_activity_referral_ids(PDO $pdo, int $patientId): array
{
    try {
        if ($pdo->query("SHOW TABLES LIKE 'patient_audit_logs'")->rowCount() === 0) {
            return [];
        }
        $stmt = $pdo->prepare("
            SELECT description, meta
            FROM patient_audit_logs
            WHERE patient_id = ?
              AND action_type IN ('bhw_referral_created', 'bhw_emergency_referral')
            ORDER BY created_at DESC
            LIMIT 80
        ");
        $stmt->execute([$patientId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return [];
    }

    $ids = [];
    foreach ($rows as $row) {
        $id = 0;
        if (preg_match('/referral #(\d+)/i', (string) ($row['description'] ?? ''), $m)) {
            $id = (int) $m[1];
        }
        if ($id <= 0) {
            $meta = json_decode((string) ($row['meta'] ?? ''), true);
            $id = (int) ($meta['referral_id'] ?? 0);
        }
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    return array_values($ids);
}

/**
 * @return array<int, string>
 */
function community_bhw_activity_referral_bhw_names(PDO $pdo, int $patientId): array
{
    try {
        $stmt = $pdo->prepare("
            SELECT al.description, al.meta,
                   TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS bhw_name
            FROM patient_audit_logs al
            LEFT JOIN users u ON u.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(al.meta, '$.bhw_id')) AS UNSIGNED)
            WHERE al.patient_id = ?
              AND al.action_type IN ('bhw_referral_created', 'bhw_emergency_referral')
            ORDER BY al.created_at DESC
            LIMIT 80
        ");
        $stmt->execute([$patientId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return [];
    }

    $map = [];
    foreach ($rows as $row) {
        $id = 0;
        if (preg_match('/referral #(\d+)/i', (string) ($row['description'] ?? ''), $m)) {
            $id = (int) $m[1];
        }
        if ($id <= 0) {
            $meta = json_decode((string) ($row['meta'] ?? ''), true);
            $id = (int) ($meta['referral_id'] ?? 0);
        }
        $name = trim((string) ($row['bhw_name'] ?? ''));
        if ($id > 0 && $name !== '' && !isset($map[$id])) {
            $map[$id] = $name;
        }
    }
    return $map;
}
