<?php
/**
 * Read-only admin patient case list.
 * Returns only columns the case viewer displays. Passwords, ID hashes, and OCR payloads stay out.
 */

/**
 * @param array<string, mixed> $query
 * @return array{
 *   q:string,barangay:string,mobile:string,status:string,sort:string,consult_date:string,page:int,per_page:int
 * }
 */
function admin_patient_records_normalize(array $query): array
{
    $status = strtolower(trim((string) ($query['status'] ?? 'all')));
    if (!in_array($status, ['all', 'active', 'inactive'], true)) {
        $status = 'all';
    }
    $sort = strtolower(trim((string) ($query['sort'] ?? 'name')));
    if (!in_array($sort, ['name', 'name_desc', 'newest'], true)) {
        $sort = 'name';
    }
    $consultDate = trim((string) ($query['consult_date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $consultDate)) {
        $consultDate = '';
    }
    $page = max(1, (int) ($query['page'] ?? 1));
    $perPage = (int) ($query['per_page'] ?? 20);
    if ($perPage < 1) {
        $perPage = 20;
    }
    if ($perPage > 50) {
        $perPage = 50;
    }

    return [
        'q' => mb_substr(trim((string) ($query['q'] ?? '')), 0, 80),
        'barangay' => mb_substr(trim((string) ($query['barangay'] ?? '')), 0, 80),
        'mobile' => mb_substr(trim((string) ($query['mobile'] ?? '')), 0, 20),
        'status' => $status,
        'sort' => $sort,
        'consult_date' => $consultDate,
        'page' => $page,
        'per_page' => $perPage,
    ];
}

/**
 * @param array<string, mixed> $query
 * @return array{patients:list<array<string,mixed>>,total:int,page:int,per_page:int,filters:array<string,mixed>}
 */
function admin_patient_records_list(PDO $pdo, array $query): array
{
    $filters = admin_patient_records_normalize($query);
    $where = ["u.role = 'patient'"];
    $params = [];

    if ($filters['q'] !== '') {
        $where[] = '(u.first_name LIKE ? OR u.last_name LIKE ? OR CONCAT(u.first_name, \' \', u.last_name) LIKE ? OR u.email LIKE ? OR pr.contact_number LIKE ?)';
        $like = '%' . $filters['q'] . '%';
        array_push($params, $like, $like, $like, $like, $like);
    }
    if ($filters['barangay'] !== '') {
        $where[] = 'pr.barangay LIKE ?';
        $params[] = '%' . $filters['barangay'] . '%';
    }
    if ($filters['mobile'] !== '') {
        $where[] = 'pr.contact_number LIKE ?';
        $params[] = '%' . preg_replace('/\s+/', '', $filters['mobile']) . '%';
    }
    if ($filters['status'] === 'active') {
        $where[] = 'u.is_active = 1';
    } elseif ($filters['status'] === 'inactive') {
        $where[] = 'u.is_active = 0';
    }
    if ($filters['consult_date'] !== '') {
        $where[] = 'EXISTS (SELECT 1 FROM consultations cf WHERE cf.patient_id = u.id AND cf.consult_date = ?)';
        $params[] = $filters['consult_date'];
    }

    $order = match ($filters['sort']) {
        'name_desc' => 'u.last_name DESC, u.first_name DESC',
        'newest' => 'u.created_at DESC, u.id DESC',
        default => 'u.last_name ASC, u.first_name ASC',
    };
    $offset = ($filters['page'] - 1) * $filters['per_page'];
    $from = 'FROM users u LEFT JOIN patient_registrations pr ON ' . admin_patient_records_registration_join() . ' WHERE ' . implode(' AND ', $where);

    $count = $pdo->prepare('SELECT COUNT(*) ' . $from);
    $count->execute($params);
    $total = (int) $count->fetchColumn();

    $sql = '
        SELECT u.id, u.first_name, u.last_name, u.email, u.is_active,
               pr.contact_number, pr.barangay
        ' . $from . '
        ORDER BY ' . $order . '
        LIMIT ' . (int) $filters['per_page'] . ' OFFSET ' . (int) $offset;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return [
        'patients' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
        'total' => $total,
        'page' => $filters['page'],
        'per_page' => $filters['per_page'],
        'filters' => $filters,
    ];
}

/**
 * @return array{profile:?array<string,mixed>,consults:list<array<string,mixed>>,triage:list<array<string,mixed>>,referrals:list<array<string,mixed>>,followups:list<array<string,mixed>>,prescriptions:list<array<string,mixed>>,location:array<string,mixed>}
 */
function admin_patient_records_detail(PDO $pdo, int $patientId, string $consultDate = ''): array
{
    $empty = [
        'profile' => null,
        'consults' => [],
        'triage' => [],
        'referrals' => [],
        'followups' => [],
        'prescriptions' => [],
        'location' => [],
    ];
    if ($patientId <= 0) {
        return $empty;
    }

    $join = admin_patient_records_registration_join();
    $profileStmt = $pdo->prepare('
        SELECT u.id, u.first_name, u.last_name, u.email, u.is_active,
               pr.contact_number, pr.barangay, pr.purok, pr.date_of_birth AS birthdate
        FROM users u
        LEFT JOIN patient_registrations pr ON ' . $join . '
        WHERE u.id = ? AND u.role = \'patient\'
        ORDER BY pr.id DESC
        LIMIT 1
    ');
    try {
        $profileStmt->execute([$patientId]);
        $profile = $profileStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $fallback = $pdo->prepare("SELECT id, first_name, last_name, email, is_active, NULL AS contact_number, NULL AS barangay, NULL AS purok, NULL AS birthdate FROM users WHERE id = ? AND role = 'patient' LIMIT 1");
        $fallback->execute([$patientId]);
        $profile = $fallback->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$profile) {
        return $empty;
    }

    $consultSql = 'SELECT id, consult_date, consult_time, consult_type, status, provider_name, diagnosis FROM consultations WHERE patient_id = ?';
    $consultParams = [$patientId];
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $consultDate)) {
        $consultSql .= ' AND consult_date = ?';
        $consultParams[] = $consultDate;
    }
    $consultSql .= ' ORDER BY consult_date DESC, consult_time DESC LIMIT 20';
    $consults = admin_patient_records_rows($pdo, $consultSql, $consultParams);
    $triage = admin_patient_records_rows($pdo, 'SELECT id, urgency_label, level, assessed_at, chief_complaint, symptoms FROM triage_results WHERE patient_id = ? ORDER BY assessed_at DESC LIMIT 20', [$patientId]);
    $referrals = admin_patient_records_rows($pdo, 'SELECT id, reason, destination_facility, status, created_at FROM digital_referrals WHERE patient_id = ? ORDER BY created_at DESC LIMIT 20', [$patientId]);
    $followups = admin_patient_records_rows($pdo, 'SELECT id, followup_date, status, message, notes FROM followups WHERE patient_id = ? ORDER BY followup_date DESC LIMIT 20', [$patientId]);
    $prescriptions = admin_patient_records_rows($pdo, 'SELECT id, medication_name, dosage, frequency, duration, created_at FROM prescriptions WHERE patient_id = ? ORDER BY created_at DESC LIMIT 20', [$patientId]);
    $location = [];
    try {
        $loc = $pdo->prepare('SELECT latitude, longitude, barangay FROM patient_locations WHERE patient_id = ? LIMIT 1');
        $loc->execute([$patientId]);
        $location = $loc->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $location = [];
    }

    return [
        'profile' => $profile,
        'consults' => $consults,
        'triage' => $triage,
        'referrals' => $referrals,
        'followups' => $followups,
        'prescriptions' => $prescriptions,
        'location' => $location,
    ];
}

function admin_patient_records_registration_join(): string
{
    return 'pr.id = (
        SELECT pr2.id
        FROM patient_registrations pr2
        WHERE pr2.user_id = u.id OR (pr2.user_id IS NULL AND pr2.email = u.email)
        ORDER BY pr2.id DESC
        LIMIT 1
    )';
}

/**
 * @param list<mixed> $params
 * @return list<array<string, mixed>>
 */
function admin_patient_records_rows(PDO $pdo, string $sql, array $params): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}
