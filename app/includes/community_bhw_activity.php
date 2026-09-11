<?php
/**
 * Read-only BHW activity for a patient (provider consultation / patient My Health).
 * Does not require a BHW session. Does not create or change clinical records.
 */

function community_bhw_activity_load(PDO $pdo, int $patientId): array
{
    $empty = [
        'documents'       => [],
        'visits'          => [],
        'referrals'       => [],
        'health_entries'  => [],
        'external_visits' => [],
        'barangay'        => '',
        'total'           => 0,
    ];
    if ($patientId <= 0) {
        return $empty;
    }

    $barangay = '';
    try {
        require_once __DIR__ . '/bhw_scope.php';
        $barangay = bhw_patient_registered_barangay($pdo, $patientId);
    } catch (Throwable $e) {
        $barangay = '';
    }

    $documents = community_bhw_activity_documents($pdo, $patientId, $barangay);
    $visits = community_bhw_activity_visits($pdo, $patientId, $barangay);
    $referrals = community_bhw_activity_referrals($pdo, $patientId, $barangay);
    $healthEntries = community_bhw_activity_health_entries($pdo, $patientId, $barangay);
    $externalVisits = community_bhw_activity_external_visits($pdo, $patientId, $barangay);

    return [
        'documents'       => $documents,
        'visits'          => $visits,
        'referrals'       => $referrals,
        'health_entries'  => $healthEntries,
        'external_visits' => $externalVisits,
        'barangay'        => $barangay,
        'total'           => count($documents) + count($visits) + count($referrals)
            + count($healthEntries) + count($externalVisits),
    ];
}

/**
 * Shared attribution block for patient/provider health activity UI.
 *
 * @return array{added_by: string, role: string, role_label: string, date_label: string, time_label: string, barangay_label: string, recorded_at: ?string}
 */
function community_bhw_activity_attribution(string $name, string $role, ?string $recordedAt, string $barangay = ''): array
{
    $roleKey = strtolower(trim($role));
    $roleLabel = match ($roleKey) {
        'bhw' => 'Barangay Health Worker (BHW)',
        'provider' => 'Provider',
        'patient' => 'Patient',
        default => $roleKey !== '' ? ucwords(str_replace('_', ' ', $roleKey)) : 'Unknown',
    };
    $ts = ($recordedAt !== null && trim($recordedAt) !== '') ? strtotime($recordedAt) : false;
    $barangayLabel = trim($barangay);

    return [
        'added_by'       => trim($name) !== '' ? trim($name) : 'Unknown',
        'role'           => $roleKey,
        'role_label'     => $roleLabel,
        'date_label'     => $ts ? date('F j, Y', $ts) : '—',
        'time_label'     => $ts ? date('g:i A', $ts) : '—',
        'barangay_label' => $barangayLabel !== '' ? $barangayLabel : '—',
        'recorded_at'    => $recordedAt,
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
 * Vitals / visit-intake rows from consultation_recorded_data (patient + BHW).
 *
 * @return list<array<string, mixed>>
 */
function community_bhw_activity_health_entries(PDO $pdo, int $patientId, string $barangay = ''): array
{
    try {
        require_once __DIR__ . '/consultation_recorded_data.php';
        consultation_recorded_data_ensure_schema($pdo);
        $rows = consultation_recorded_data_history_for_patient($pdo, $patientId, 40);
    } catch (Throwable $e) {
        return [];
    }

    $out = [];
    foreach ($rows as $row) {
        $fields = consultation_recorded_data_field_list($row);
        if ($fields === []) {
            continue;
        }
        foreach ($fields as &$field) {
            if (($field['key'] ?? '') === 'temperature_c') {
                $field['value'] = preg_replace('/°C\s*$/u', ' °C', (string) ($field['value'] ?? '')) ?? (string) ($field['value'] ?? '');
            }
        }
        unset($field);

        $role = strtolower((string) ($row['recorder_role'] ?? 'patient'));
        $attr = community_bhw_activity_attribution(
            (string) ($row['recorder_name'] ?? ''),
            $role,
            isset($row['recorded_at']) ? (string) $row['recorded_at'] : null,
            $barangay
        );
        $status = strtolower((string) ($row['status'] ?? ''));
        $out[] = array_merge($attr, [
            'id'              => (int) ($row['id'] ?? 0),
            'category'        => 'Health measurements',
            'fields'          => $fields,
            'status'          => $status,
            'status_label'    => $status === 'pending' ? 'Pre-consultation' : 'On record',
            'consultation_id' => (int) ($row['consultation_id'] ?? 0),
            'source'          => 'consultation_recorded_data',
        ]);
    }

    return $out;
}

/**
 * External facility visits from the existing patient_external_healthcare_visits table.
 *
 * @return list<array<string, mixed>>
 */
function community_bhw_activity_external_visits(PDO $pdo, int $patientId, string $barangay = ''): array
{
    try {
        require_once __DIR__ . '/patient_external_healthcare_visits.php';
        $visits = patient_external_healthcare_visits_for_display($pdo, $patientId, 30);
    } catch (Throwable $e) {
        return [];
    }

    $out = [];
    foreach ($visits as $visit) {
        $role = strtolower((string) ($visit['recorder_role'] ?? 'bhw'));
        $name = (string) ($visit['recorded_by_label'] ?? '');
        if (preg_match('/\((.+)\)\s*$/u', $name, $m)) {
            $name = trim($m[1]);
        } elseif (str_starts_with($name, 'BHW ')) {
            $name = trim(substr($name, 4));
        } elseif (str_starts_with($name, 'Provider ')) {
            $name = trim(substr($name, 9));
        } elseif (str_starts_with($name, 'Patient ')) {
            $name = trim(substr($name, 8));
        }
        $attr = community_bhw_activity_attribution(
            $name,
            $role,
            isset($visit['recorded_at']) ? (string) $visit['recorded_at'] : null,
            $barangay
        );
        $fields = [];
        if (!empty($visit['facility_type_label']) || !empty($visit['facility_name'])) {
            $facility = trim((string) ($visit['facility_type_label'] ?? ''));
            if (!empty($visit['facility_name'])) {
                $facility = trim($facility . ($facility !== '' ? ' — ' : '') . (string) $visit['facility_name']);
            }
            if ($facility !== '') {
                $fields[] = ['label' => 'Facility', 'value' => $facility, 'key' => 'facility'];
            }
        }
        if (!empty($visit['visit_date_label'])) {
            $fields[] = ['label' => 'Visit date', 'value' => (string) $visit['visit_date_label'], 'key' => 'visit_date'];
        }
        if (!empty($visit['reason'])) {
            $fields[] = ['label' => 'Reason', 'value' => (string) $visit['reason'], 'key' => 'reason'];
        }
        if (!empty($visit['reported_diagnosis'])) {
            $fields[] = ['label' => 'Reported diagnosis', 'value' => (string) $visit['reported_diagnosis'], 'key' => 'diagnosis'];
        }
        if (!empty($visit['reported_treatment'])) {
            $fields[] = ['label' => 'Reported treatment', 'value' => (string) $visit['reported_treatment'], 'key' => 'treatment'];
        }
        if (!empty($visit['notes'])) {
            $fields[] = ['label' => 'Notes', 'value' => (string) $visit['notes'], 'key' => 'notes'];
        }
        if ($fields === []) {
            continue;
        }
        $out[] = array_merge($attr, [
            'id'       => (int) ($visit['id'] ?? 0),
            'category' => 'External healthcare visit',
            'fields'   => $fields,
            'source'   => 'patient_external_healthcare_visits',
        ]);
    }

    return $out;
}

/**
 * @return list<array<string, mixed>>
 */
function community_bhw_activity_documents(PDO $pdo, int $patientId, string $barangay = ''): array
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
        $bhwName = community_bhw_activity_match_uploader($bhwNames, $uploadedAt, (string) ($row['original_name'] ?? ''));
        $attr = community_bhw_activity_attribution($bhwName, 'bhw', $uploadedAt !== '' ? $uploadedAt : null, $barangay);
        $out[] = array_merge($attr, [
            'id'          => (int) ($row['id'] ?? 0),
            'title'       => $title,
            'type'        => trim((string) ($row['document_type'] ?? '')) ?: 'Document',
            'description' => trim((string) ($row['description'] ?? '')),
            'status'      => trim((string) ($row['status'] ?? '')),
            'date_label'  => $attr['date_label'],
            'bhw_name'    => $attr['added_by'] !== 'Unknown' ? $attr['added_by'] : '',
            'time_label'  => $attr['time_label'],
            'role_label'  => $attr['role_label'],
            'barangay_label' => $attr['barangay_label'],
        ]);
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
function community_bhw_activity_visits(PDO $pdo, int $patientId, string $barangay = ''): array
{
    try {
        if ($pdo->query("SHOW TABLES LIKE 'bhw_home_visits'")->rowCount() === 0) {
            return [];
        }
        $stmt = $pdo->prepare("
            SELECT hv.visit_date, hv.visit_type, hv.patient_status, hv.notes, hv.created_at,
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
        $when = (string) ($row['created_at'] ?? '');
        if ($when === '' || str_starts_with($when, '0000-00-00')) {
            $when = (string) ($row['visit_date'] ?? '');
        }
        $attr = community_bhw_activity_attribution(
            (string) ($row['bhw_name'] ?? ''),
            'bhw',
            $when !== '' ? $when : null,
            $barangay
        );
        if (!empty($row['visit_date'])) {
            $attr['date_label'] = community_bhw_activity_date_label((string) $row['visit_date'], 'F j, Y');
        }
        $out[] = array_merge($attr, [
            'date_label' => $attr['date_label'],
            'type_label' => community_bhw_visit_type_label((string) ($row['visit_type'] ?? '')),
            'status'     => community_bhw_patient_status_label((string) ($row['patient_status'] ?? '')),
            'notes'      => trim((string) ($row['notes'] ?? '')),
            'bhw_name'   => $attr['added_by'] !== 'Unknown' ? $attr['added_by'] : '',
            'time_label' => $attr['time_label'],
            'role_label' => $attr['role_label'],
        ]);
    }
    return $out;
}

/**
 * @return list<array<string, mixed>>
 */
function community_bhw_activity_referrals(PDO $pdo, int $patientId, string $barangay = ''): array
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
        $createdAt = (string) ($row['created_at'] ?? '');
        $attr = community_bhw_activity_attribution(
            (string) ($namesById[$id] ?? ''),
            'bhw',
            $createdAt !== '' ? $createdAt : null,
            $barangay
        );
        $out[] = array_merge($attr, [
            'id'         => $id,
            'type'       => trim((string) ($row['referral_type'] ?? '')) ?: 'Referral',
            'reason'     => trim((string) ($row['reason'] ?? '')),
            'facility'   => trim((string) ($row['facility_display'] ?? '')),
            'status'     => ucfirst(trim((string) ($row['status'] ?? 'pending'))),
            'date_label' => $attr['date_label'],
            'bhw_name'   => $attr['added_by'] !== 'Unknown' ? $attr['added_by'] : '',
            'time_label' => $attr['time_label'],
            'role_label' => $attr['role_label'],
        ]);
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
