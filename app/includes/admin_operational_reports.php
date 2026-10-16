<?php
/**
 * Shared Admin / SuperAdmin operational reports.
 * The Operational Reports tables and the CSV export both read these queries.
 */

declare(strict_types=1);

function admin_operational_report_page_size(): int
{
    return 25;
}

/**
 * @return array<string, array{title:string,description:string,headers:list<string>,sql:string,table:?string}>
 */
function admin_operational_report_catalog(): array
{
    return [
        'appointments' => [
            'title' => 'Consultation & Appointment Report',
            'description' => 'Overview of consultations, assigned providers, appointment dates, and consultation status. Date presets use Asia/Manila and the week starts on Monday. Custom from and to apply when Date is Custom range.',
            'headers' => ['Consultation ID', 'Patient', 'Doctor', 'Patient Complaint', 'Date', 'Time', 'Status'],
            // Appointments are built by admin_consultation_report_statements() so filters stay parameterized.
            'sql' => '',
            'table' => 'consultations',
        ],
        'users' => [
            'title' => 'User Demographics',
            'description' => 'Age and sex profile of registered patients in a BHW-assigned barangay.',
            'headers' => ['Patient Code', 'Name', 'Age', 'Age Group', 'Sex', 'Purok'],
            // Built per barangay by admin_demographics_sql().
            'sql' => '',
            'table' => 'patient_registrations',
        ],
    ];
}

function admin_operational_report_export_allowed(string $role): bool
{
    return in_array($role, ['admin', 'superadmin'], true);
}

/** @return array<string, string> */
function admin_consultation_report_presets(): array
{
    return [
        'all' => 'All dates',
        'today' => 'Today',
        'yesterday' => 'Yesterday',
        'this_week' => 'This week',
        'last_week' => 'Last week',
        'this_month' => 'This month',
        'last_month' => 'Last month',
        'this_year' => 'This year',
        'custom' => 'Custom range',
    ];
}

/** @return list<string> */
function admin_consultation_report_statuses(): array
{
    return ['pending', 'scheduled', 'in_consultation', 'completed', 'cancelled'];
}

/** @return list<string> */
function admin_consultation_report_slot_statuses(): array
{
    return ['available', 'booked', 'blocked', 'completed', 'cancelled', 'expired'];
}

function admin_consultation_report_timezone(): DateTimeZone
{
    $name = defined('APP_TIMEZONE') ? (string) APP_TIMEZONE : 'Asia/Manila';

    return new DateTimeZone($name);
}

function admin_consultation_report_clock(?DateTimeImmutable $now = null): DateTimeImmutable
{
    $tz = admin_consultation_report_timezone();
    if ($now === null) {
        return new DateTimeImmutable('now', $tz);
    }

    return $now->setTimezone($tz);
}

function admin_consultation_report_valid_date(string $value): string
{
    $value = trim($value);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return '';
    }
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $value, admin_consultation_report_timezone());
    if (!$dt || $dt->format('Y-m-d') !== $value) {
        return '';
    }

    return $value;
}

/**
 * Inclusive calendar bounds. Week starts Monday. Presets other than custom ignore the typed range.
 *
 * @return array{period:string,from:?string,to:?string}
 */
function admin_consultation_report_date_bounds(string $preset, string $fromInput = '', string $toInput = '', ?DateTimeImmutable $now = null): array
{
    $preset = strtolower(trim($preset));
    if (!isset(admin_consultation_report_presets()[$preset])) {
        $preset = 'all';
    }
    $today = admin_consultation_report_clock($now)->setTime(0, 0);
    if ($preset === 'all') {
        return ['period' => 'all', 'from' => null, 'to' => null];
    }
    if ($preset === 'custom') {
        $from = admin_consultation_report_valid_date($fromInput);
        $to = admin_consultation_report_valid_date($toInput);
        if ($from !== '' && $to !== '' && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [
            'period' => 'custom',
            'from' => $from !== '' ? $from : null,
            'to' => $to !== '' ? $to : null,
        ];
    }
    if ($preset === 'today') {
        $day = $today->format('Y-m-d');

        return ['period' => 'today', 'from' => $day, 'to' => $day];
    }
    if ($preset === 'yesterday') {
        $day = $today->modify('-1 day')->format('Y-m-d');

        return ['period' => 'yesterday', 'from' => $day, 'to' => $day];
    }
    if ($preset === 'this_week' || $preset === 'last_week') {
        $monday = $today->modify('-' . ((int) $today->format('N') - 1) . ' days');
        if ($preset === 'last_week') {
            $monday = $monday->modify('-7 days');
        }
        $sunday = $monday->modify('+6 days');

        return [
            'period' => $preset,
            'from' => $monday->format('Y-m-d'),
            'to' => $sunday->format('Y-m-d'),
        ];
    }
    if ($preset === 'this_month') {
        return [
            'period' => 'this_month',
            'from' => $today->modify('first day of this month')->format('Y-m-d'),
            'to' => $today->modify('last day of this month')->format('Y-m-d'),
        ];
    }
    if ($preset === 'last_month') {
        $first = $today->modify('first day of last month');

        return [
            'period' => 'last_month',
            'from' => $first->format('Y-m-d'),
            'to' => $first->modify('last day of this month')->format('Y-m-d'),
        ];
    }

    $year = $today->format('Y');

    return ['period' => 'this_year', 'from' => $year . '-01-01', 'to' => $year . '-12-31'];
}

function admin_consultation_report_whitelist(string $value, array $allowed): string
{
    $value = strtolower(trim($value));

    return in_array($value, $allowed, true) ? $value : '';
}

function admin_consultation_report_yes_no(string $value): string
{
    return admin_consultation_report_whitelist($value, ['yes', 'no']);
}

function admin_consultation_report_type_text(string $value): string
{
    $value = trim(preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? '');
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, 80);
    }

    return substr($value, 0, 80);
}

function admin_consultation_report_patient_code(int $patientId): string
{
    return 'MC-' . str_pad((string) max(0, $patientId), 6, '0', STR_PAD_LEFT);
}

function admin_consultation_report_patient_id_from_query(string $query): ?int
{
    if (preg_match('/^MC-0*(\d{1,10})$/i', trim($query), $match)) {
        $id = (int) $match[1];

        return $id > 0 ? $id : null;
    }
    if (preg_match('/^\d{1,10}$/', trim($query))) {
        $id = (int) trim($query);

        return $id > 0 ? $id : null;
    }

    return null;
}

function admin_consultation_report_like_pattern(string $value): string
{
    $value = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);

    return '%' . $value . '%';
}

/**
 * @param array<string, mixed> $query
 * @return array{period:string,from:?string,to:?string,from_input:string,to_input:string,q:string,provider_id:int,bhw_id:int,status:string,type:string,slot_status:string,prescription:string,referral:string}
 */
function admin_consultation_report_parse_filters(array $query, ?DateTimeImmutable $now = null): array
{
    $fromInput = admin_consultation_report_valid_date((string) ($query['appt_from'] ?? ''));
    $toInput = admin_consultation_report_valid_date((string) ($query['appt_to'] ?? ''));
    $bounds = admin_consultation_report_date_bounds(
        (string) ($query['appt_period'] ?? 'all'),
        $fromInput,
        $toInput,
        $now
    );

    return [
        'period' => $bounds['period'],
        'from' => $bounds['from'],
        'to' => $bounds['to'],
        'from_input' => $fromInput,
        'to_input' => $toInput,
        'q' => admin_consultation_report_type_text((string) ($query['appt_q'] ?? '')),
        'provider_id' => max(0, (int) ($query['appt_provider'] ?? 0)),
        'bhw_id' => max(0, (int) ($query['appt_bhw'] ?? 0)),
        'status' => admin_consultation_report_whitelist((string) ($query['appt_status'] ?? ''), admin_consultation_report_statuses()),
        'type' => admin_consultation_report_type_text((string) ($query['appt_type'] ?? '')),
        'slot_status' => admin_consultation_report_whitelist((string) ($query['appt_slot'] ?? ''), admin_consultation_report_slot_statuses()),
        'prescription' => admin_consultation_report_yes_no((string) ($query['appt_rx'] ?? '')),
        'referral' => admin_consultation_report_yes_no((string) ($query['appt_ref'] ?? '')),
    ];
}

/**
 * Query-string values that should survive pagination and live refresh.
 *
 * @param array<string, mixed> $filters
 * @return array<string, int|string>
 */
function admin_consultation_report_query_params(array $filters): array
{
    $params = [];
    $period = (string) ($filters['period'] ?? 'all');
    if ($period !== '' && $period !== 'all') {
        $params['appt_period'] = $period;
    }
    if (($filters['from_input'] ?? '') !== '') {
        $params['appt_from'] = (string) $filters['from_input'];
    }
    if (($filters['to_input'] ?? '') !== '') {
        $params['appt_to'] = (string) $filters['to_input'];
    }
    if (($filters['q'] ?? '') !== '') {
        $params['appt_q'] = (string) $filters['q'];
    }
    if ((int) ($filters['provider_id'] ?? 0) > 0) {
        $params['appt_provider'] = (int) $filters['provider_id'];
    }
    if ((int) ($filters['bhw_id'] ?? 0) > 0) {
        $params['appt_bhw'] = (int) $filters['bhw_id'];
    }
    if (($filters['status'] ?? '') !== '') {
        $params['appt_status'] = (string) $filters['status'];
    }
    if (($filters['type'] ?? '') !== '') {
        $params['appt_type'] = (string) $filters['type'];
    }
    if (($filters['slot_status'] ?? '') !== '') {
        $params['appt_slot'] = (string) $filters['slot_status'];
    }
    if (($filters['prescription'] ?? '') !== '') {
        $params['appt_rx'] = (string) $filters['prescription'];
    }
    if (($filters['referral'] ?? '') !== '') {
        $params['appt_ref'] = (string) $filters['referral'];
    }

    return $params;
}

/**
 * @return array{page:int,offset:int,per_page:int,pages:int}
 */
function admin_consultation_report_page_bounds(int $total, int $page, int $perPage): array
{
    $perPage = max(1, $perPage);
    $pages = max(1, (int) ceil(max(0, $total) / $perPage));
    $page = min(max(1, $page), $pages);

    return [
        'page' => $page,
        'offset' => ($page - 1) * $perPage,
        'per_page' => $perPage,
        'pages' => $pages,
    ];
}

function admin_report_has_column(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
        return $cache[$key] = false;
    }
    $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1'
    );
    $stmt->execute([$table, $column]);

    return $cache[$key] = (bool) $stmt->fetchColumn();
}

/**
 * Optional relationships are queried only when the column already exists.
 * This does not add columns.
 *
 * @return array{booked_by_bhw:bool,appointment_slots:bool,prescriptions:bool,referrals:bool}
 */
function admin_consultation_report_capabilities(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    return $cache = [
        'booked_by_bhw' => admin_report_has_column($pdo, 'consultations', 'booked_by_bhw_id'),
        'appointment_slots' => admin_operational_report_table_exists($pdo, 'appointment_slots')
            && admin_report_has_column($pdo, 'appointment_slots', 'consultation_id'),
        'prescriptions' => admin_operational_report_table_exists($pdo, 'prescriptions')
            && admin_report_has_column($pdo, 'prescriptions', 'consultation_id'),
        'referrals' => admin_operational_report_table_exists($pdo, 'digital_referrals')
            && admin_report_has_column($pdo, 'digital_referrals', 'consultation_id'),
    ];
}

/**
 * @param array<string, mixed> $filters
 * @param array{booked_by_bhw?:bool,appointment_slots?:bool,prescriptions?:bool,referrals?:bool} $capabilities
 * @return array{select_sql:string,count_sql:string,params:list<mixed>}
 */
function admin_consultation_report_statements(array $filters, array $capabilities = [], ?DateTimeImmutable $now = null): array
{
    if (!isset($filters['period'])) {
        $filters = admin_consultation_report_parse_filters($filters, $now);
    }

    $nameExpr = "TRIM(CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(p.last_name, '')))";
    $from = 'FROM consultations c
             LEFT JOIN users p ON p.id = c.patient_id
             LEFT JOIN users d ON d.id = c.provider_id';
    $where = [];
    $params = [];

    if (($filters['from'] ?? null) !== null && $filters['from'] !== '') {
        $where[] = 'c.consult_date >= ?';
        $params[] = (string) $filters['from'];
    }
    if (($filters['to'] ?? null) !== null && $filters['to'] !== '') {
        $where[] = 'c.consult_date <= ?';
        $params[] = (string) $filters['to'];
    }

    $search = trim((string) ($filters['q'] ?? ''));
    if ($search !== '') {
        $patientId = admin_consultation_report_patient_id_from_query($search);
        $like = admin_consultation_report_like_pattern($search);
        if ($patientId !== null) {
            $where[] = '(c.patient_id = ? OR ' . $nameExpr . ' LIKE ? ESCAPE \'!\')';
            $params[] = $patientId;
            $params[] = $like;
        } else {
            $where[] = $nameExpr . ' LIKE ? ESCAPE \'!\'';
            $params[] = $like;
        }
    }

    if ((int) ($filters['provider_id'] ?? 0) > 0) {
        $where[] = 'c.provider_id = ?';
        $params[] = (int) $filters['provider_id'];
    }
    if (!empty($capabilities['booked_by_bhw']) && (int) ($filters['bhw_id'] ?? 0) > 0) {
        $where[] = 'c.booked_by_bhw_id = ?';
        $params[] = (int) $filters['bhw_id'];
    }
    if (($filters['status'] ?? '') !== '') {
        $where[] = 'c.status = ?';
        $params[] = (string) $filters['status'];
    }
    if (($filters['type'] ?? '') !== '') {
        $where[] = 'c.consult_type LIKE ? ESCAPE \'!\'';
        $params[] = admin_consultation_report_like_pattern((string) $filters['type']);
    }
    if (!empty($capabilities['appointment_slots']) && ($filters['slot_status'] ?? '') !== '') {
        $where[] = 'EXISTS (SELECT 1 FROM appointment_slots s WHERE s.consultation_id = c.id AND s.status = ?)';
        $params[] = (string) $filters['slot_status'];
    }
    if (!empty($capabilities['prescriptions']) && ($filters['prescription'] ?? '') === 'yes') {
        $where[] = 'EXISTS (SELECT 1 FROM prescriptions rx WHERE rx.consultation_id = c.id)';
    } elseif (!empty($capabilities['prescriptions']) && ($filters['prescription'] ?? '') === 'no') {
        $where[] = 'NOT EXISTS (SELECT 1 FROM prescriptions rx WHERE rx.consultation_id = c.id)';
    }
    if (!empty($capabilities['referrals']) && ($filters['referral'] ?? '') === 'yes') {
        $where[] = 'EXISTS (SELECT 1 FROM digital_referrals dr WHERE dr.consultation_id = c.id)';
    } elseif (!empty($capabilities['referrals']) && ($filters['referral'] ?? '') === 'no') {
        $where[] = 'NOT EXISTS (SELECT 1 FROM digital_referrals dr WHERE dr.consultation_id = c.id)';
    }

    $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
    $select = "SELECT c.id AS consultation_id,
                      c.patient_id,
                      {$nameExpr} AS patient_name,
                      COALESCE(
                          NULLIF(TRIM(c.provider_name), ''),
                          TRIM(CONCAT(COALESCE(d.first_name, ''), ' ', COALESCE(d.last_name, '')))
                      ) AS doctor_name,
                      c.consult_type AS patient_complaint,
                      c.consult_date,
                      c.consult_time,
                      c.status
               {$from}{$whereSql}
               ORDER BY c.consult_date ASC, c.consult_time ASC, c.id ASC";

    return [
        'select_sql' => $select,
        'count_sql' => 'SELECT COUNT(*) ' . $from . $whereSql,
        'params' => $params,
    ];
}

/**
 * @return list<array{id:int,name:string}>
 */
function admin_consultation_report_people(PDO $pdo, string $role): array
{
    if (!in_array($role, ['provider', 'bhw'], true)) {
        return [];
    }
    try {
        $stmt = $pdo->prepare(
            "SELECT id, TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))) AS name
             FROM users
             WHERE role = ?
             ORDER BY name ASC, id ASC
             LIMIT 500"
        );
        $stmt->execute([$role]);
        $people = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            $people[] = [
                'id' => $id,
                'name' => $name !== '' ? $name : ($role === 'bhw' ? 'BHW #' . $id : 'Provider #' . $id),
            ];
        }

        return $people;
    } catch (Throwable $e) {
        error_log('admin_consultation_report_people: ' . $e->getMessage());

        return [];
    }
}

/**
 * @param array<string, mixed> $filters
 * @param array{booked_by_bhw:bool,appointment_slots:bool,prescriptions:bool,referrals:bool} $capabilities
 * @return array<string, mixed>
 */
function admin_consultation_report_view(PDO $pdo, array $filters, array $capabilities): array
{
    return [
        'filters' => $filters,
        'presets' => admin_consultation_report_presets(),
        'statuses' => admin_consultation_report_statuses(),
        'providers' => admin_consultation_report_people($pdo, 'provider'),
        'bhws' => !empty($capabilities['booked_by_bhw']) ? admin_consultation_report_people($pdo, 'bhw') : null,
        'slot_statuses' => !empty($capabilities['appointment_slots']) ? admin_consultation_report_slot_statuses() : null,
        'supports_prescription' => !empty($capabilities['prescriptions']),
        'supports_referral' => !empty($capabilities['referrals']),
    ];
}

function admin_demographics_bootstrap(PDO $pdo): void
{
    require_once __DIR__ . '/barangays_bago.php';
    require_once __DIR__ . '/bhw_scope.php';
    patient_registrations_ensure_barangay_id($pdo);
}

/**
 * Barangays with at least one BHW account assigned — the only sectors this report covers.
 *
 * @return list<array{id:int,name:string,patients:int}>
 */
function admin_demographics_barangays(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    admin_demographics_bootstrap($pdo);

    $cache = [];
    try {
        $userCols = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (!in_array('barangay_id', $userCols, true)) {
            return $cache;
        }
        $stmt = $pdo->query("
            SELECT DISTINCT b.id, b.name
            FROM barangays b
            INNER JOIN users u ON u.barangay_id = b.id AND u.role = 'bhw'
            ORDER BY b.name ASC
        ");
        foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
            $option = ['id' => (int) $row['id'], 'name' => trim((string) $row['name'])];
            [$sql, $params] = admin_demographics_sql($pdo, $option);
            $count = $pdo->prepare('SELECT COUNT(*) FROM (' . $sql . ') d');
            $count->execute($params);
            $option['patients'] = (int) $count->fetchColumn();
            if ($option['patients'] > 0) {
                $cache[] = $option;
            }
        }
    } catch (Throwable $e) {
        error_log('admin_demographics_barangays: ' . $e->getMessage());
    }

    return $cache;
}

/**
 * Requested barangay when it has an assigned BHW and patients, otherwise the one with the most patients.
 *
 * @return array{id:int,name:string,patients:int}|null
 */
function admin_demographics_selected_barangay(PDO $pdo, int $requestedId): ?array
{
    $options = admin_demographics_barangays($pdo);
    $fallback = null;
    foreach ($options as $option) {
        if ($option['id'] === $requestedId) {
            return $option;
        }
        if ($fallback === null || $option['patients'] > $fallback['patients']) {
            $fallback = $option;
        }
    }

    return $fallback;
}

/**
 * Patient-only demographic rows for one barangay, using the same sector rule as BHW screens.
 *
 * @param array{id:int,name:string,patients?:int} $barangay
 * @return array{0:string,1:list<mixed>}
 */
function admin_demographics_sql(PDO $pdo, array $barangay): array
{
    admin_demographics_bootstrap($pdo);
    [$clause, $params] = bhw_patient_sector_clause($pdo, [
        'barangay_id' => $barangay['id'],
        'barangay_name' => $barangay['name'],
    ], 'pr');
    $cols = bhw_pr_columns($pdo);
    $purok = in_array('purok', $cols, true) ? 'pr.purok' : 'NULL';
    $address = in_array('address', $cols, true) ? 'pr.address' : 'NULL';
    $fullAddress = in_array('full_address', $cols, true) ? 'pr.full_address' : 'NULL';
    $join = bhw_pr_user_join('pr', 'u');
    $age = bhw_pr_age_sql('pr');

    $sql = "SELECT u.id AS patient_id,
                   u.first_name,
                   u.last_name,
                   {$age} AS age,
                   pr.gender,
                   {$purok} AS purok,
                   {$address} AS address,
                   {$fullAddress} AS full_address
            FROM users u
            INNER JOIN patient_registrations pr ON {$join}
            WHERE u.role = 'patient' AND {$clause}";

    return [$sql, $params];
}

function admin_demographics_order_sql(): string
{
    return ' ORDER BY d.last_name ASC, d.first_name ASC, d.patient_id ASC';
}

/**
 * @param array{id:int,name:string} $barangay
 * @return array{total:int,age_groups:array<string,int>,gender:array<string,int>}
 */
function admin_demographics_summary(PDO $pdo, array $barangay): array
{
    [$sql, $params] = admin_demographics_sql($pdo, $barangay);
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total,
               SUM(d.age BETWEEN 0 AND 12) AS children,
               SUM(d.age BETWEEN 13 AND 17) AS teens,
               SUM(d.age BETWEEN 18 AND 59) AS adults,
               SUM(d.age >= 60) AS seniors,
               SUM(LOWER(TRIM(d.gender)) IN ('male', 'm')) AS male,
               SUM(LOWER(TRIM(d.gender)) IN ('female', 'f')) AS female
        FROM ({$sql}) d
    ");
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $total = (int) ($row['total'] ?? 0);
    $male = (int) ($row['male'] ?? 0);
    $female = (int) ($row['female'] ?? 0);

    return [
        'total' => $total,
        'age_groups' => [
            'Children (0–12)' => (int) ($row['children'] ?? 0),
            'Teens (13–17)' => (int) ($row['teens'] ?? 0),
            'Adults (18–59)' => (int) ($row['adults'] ?? 0),
            'Seniors (60+)' => (int) ($row['seniors'] ?? 0),
        ],
        'gender' => [
            'Male' => $male,
            'Female' => $female,
        ],
    ];
}

function admin_demographics_age_group(?int $age): string
{
    if ($age === null) {
        return '—';
    }
    if ($age <= 12) {
        return 'Children';
    }
    if ($age <= 17) {
        return 'Teens';
    }
    if ($age <= 59) {
        return 'Adults';
    }

    return 'Seniors';
}

/**
 * @param array<string, mixed> $row
 * @return list<string>
 */
function admin_demographics_values(array $row): array
{
    $age = isset($row['age']) && $row['age'] !== '' ? (int) $row['age'] : null;
    $name = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
    $code = 'MC-' . str_pad((string) (int) ($row['patient_id'] ?? 0), 6, '0', STR_PAD_LEFT);
    $gender = strtolower(trim((string) ($row['gender'] ?? '')));
    $sex = match ($gender) {
        'male', 'm' => 'Male',
        'female', 'f' => 'Female',
        default => '—',
    };

    return [
        $code,
        $name !== '' ? $name : 'Patient #' . (int) ($row['patient_id'] ?? 0),
        $age !== null ? (string) $age : '—',
        admin_demographics_age_group($age),
        $sex,
        admin_demographics_purok($row),
    ];
}

/**
 * Stored purok, else the "Purok …" part of the address (self-registered patients have no purok field).
 *
 * @param array<string, mixed> $row
 */
function admin_demographics_purok(array $row): string
{
    $purok = trim((string) ($row['purok'] ?? ''));
    if ($purok !== '') {
        return $purok;
    }
    require_once BASE_PATH . '/app/core/PatientAddressFormatter.php';
    foreach (['address', 'full_address'] as $field) {
        $text = trim((string) ($row[$field] ?? ''));
        if ($text === '') {
            continue;
        }
        foreach (PatientAddressFormatter::parts(['address' => $text]) as $part) {
            if (preg_match('/^purok\s+/i', $part)) {
                return $part;
            }
        }
    }

    return '—';
}

function admin_operational_report_resolve(string $type): string
{
    $type = strtolower(trim($type));
    $catalog = admin_operational_report_catalog();

    return isset($catalog[$type]) ? $type : 'appointments';
}

/**
 * @return array{title:string,description:string,headers:list<string>,sql:string,table:?string}
 */
function admin_operational_report_definition(string $type): array
{
    $catalog = admin_operational_report_catalog();

    return $catalog[admin_operational_report_resolve($type)];
}

function admin_operational_report_table_exists(PDO $pdo, ?string $table): bool
{
    if ($table === null || $table === '') {
        return true;
    }
    $stmt = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1');
    $stmt->execute([$table]);

    return (bool) $stmt->fetchColumn();
}

/**
 * @param array<string, mixed> $row
 * @return list<string>
 */
function admin_operational_report_values(string $type, array $row): array
{
    if ($type === 'appointments') {
        return admin_operational_report_appointment_values($row);
    }

    if ($type === 'users') {
        return admin_demographics_values($row);
    }

    $values = [];
    foreach ($row as $value) {
        $values[] = $value === null ? '' : (string) $value;
    }

    return $values;
}

/**
 * @param array<string, mixed> $row
 * @return list<string>
 */
function admin_operational_report_appointment_values(array $row): array
{
    $patientId = (int) ($row['patient_id'] ?? 0);
    $patient = trim((string) ($row['patient_name'] ?? ''));
    if ($patient === '') {
        $patient = $patientId > 0 ? 'Patient #' . $patientId : '—';
    }
    if ($patientId > 0) {
        $patient = admin_consultation_report_patient_code($patientId) . ' · ' . $patient;
    }

    $doctor = trim((string) ($row['doctor_name'] ?? ''));
    if ($doctor === '') {
        $doctor = 'Unassigned';
    } elseif (!preg_match('/^dr\.?\s/i', $doctor)) {
        $doctor = 'Dr. ' . $doctor;
    }

    $complaint = trim(preg_replace('/\s+/', ' ', (string) ($row['patient_complaint'] ?? '')) ?? '');

    $date = trim((string) ($row['consult_date'] ?? ''));
    $dateTs = $date !== '' ? strtotime($date) : false;
    $time = trim((string) ($row['consult_time'] ?? ''));
    $timeTs = $time !== '' ? strtotime('1970-01-01 ' . $time) : false;

    $status = trim((string) ($row['status'] ?? ''));

    return [
        (string) (int) ($row['consultation_id'] ?? 0),
        $patient,
        $doctor,
        $complaint !== '' ? $complaint : '—',
        $dateTs !== false ? date('M j, Y', $dateTs) : ($date !== '' ? $date : '—'),
        $timeTs !== false ? date('g:i A', $timeTs) : ($time !== '' ? $time : '—'),
        $status !== '' ? ucwords(str_replace('_', ' ', strtolower($status))) : '—',
    ];
}

/**
 * Full report dataset used by CSV.
 *
 * @return list<list<string>>
 */
function admin_operational_report_all(PDO $pdo, string $type, int $barangayId = 0, array $filters = []): array
{
    $type = admin_operational_report_resolve($type);
    $definition = admin_operational_report_definition($type);
    if (!admin_operational_report_table_exists($pdo, $definition['table'])) {
        return [];
    }

    if ($type === 'users') {
        $barangay = admin_demographics_selected_barangay($pdo, $barangayId);
        if ($barangay === null) {
            return [];
        }
        [$sql, $params] = admin_demographics_sql($pdo, $barangay);
        $stmt = $pdo->prepare('SELECT * FROM (' . $sql . ') d' . admin_demographics_order_sql());
        $stmt->execute($params);
    } else {
        $built = admin_consultation_report_statements(
            $filters,
            admin_consultation_report_capabilities($pdo)
        );
        $stmt = $pdo->prepare($built['select_sql']);
        $stmt->execute($built['params']);
    }
    $rows = [];
    foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
        $rows[] = admin_operational_report_values($type, $row);
    }

    return $rows;
}

/**
 * Stream a report CSV. Appointment exports use the same filters as the table and include every match.
 *
 * @param array<string, mixed> $query
 */
function admin_operational_report_send_csv(PDO $pdo, array $query): void
{
    require_once __DIR__ . '/csv_export.php';

    $role = function_exists('portal_role') ? portal_role() : (string) ($_SESSION['user_role'] ?? '');
    if (!admin_operational_report_export_allowed($role)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Unauthorized.';
        return;
    }

    $type = strtolower(trim((string) ($query['type'] ?? '')));
    $catalog = admin_operational_report_catalog();
    if (!isset($catalog[$type])) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Unknown report.';
        return;
    }

    if ($type === 'appointments') {
        $rows = admin_operational_report_all($pdo, 'appointments', 0, admin_consultation_report_parse_filters($query));
        $filename = 'consultation-appointment-report.csv';
    } else {
        $barangayId = max(0, (int) ($query['barangay_id'] ?? $query['users_barangay'] ?? 0));
        $rows = admin_operational_report_all($pdo, 'users', $barangayId);
        $filename = 'user-demographics.csv';
    }

    $out = csv_export_begin($filename);
    csv_export_row($out, $catalog[$type]['headers']);
    foreach ($rows as $row) {
        csv_export_row($out, $row);
    }
    fclose($out);
}

/**
 * One table page from the same dataset as the CSV.
 *
 * @return array{type:string,title:string,description:string,headers:list<string>,rows:list<list<string>>,total:int,page:int,per_page:int,error:?string,demographics?:array}
 */
function admin_operational_report_page(PDO $pdo, string $type, int $page, ?int $perPage = null, int $barangayId = 0, array $filters = []): array
{
    if (admin_operational_report_resolve($type) === 'users') {
        return admin_demographics_page($pdo, $page, $perPage, $barangayId);
    }

    $type = admin_operational_report_resolve($type);
    $definition = admin_operational_report_definition($type);
    $perPage = max(1, $perPage ?? admin_operational_report_page_size());
    $capabilities = admin_consultation_report_capabilities($pdo);
    if (!array_key_exists('period', $filters)) {
        $filters = admin_consultation_report_parse_filters($filters);
    }
    $result = [
        'type' => $type,
        'title' => $definition['title'],
        'description' => $definition['description'],
        'headers' => $definition['headers'],
        'rows' => [],
        'total' => 0,
        'page' => 1,
        'per_page' => $perPage,
        'error' => null,
        'consultation' => admin_consultation_report_view($pdo, $filters, $capabilities),
    ];

    try {
        if (!admin_operational_report_table_exists($pdo, $definition['table'])) {
            return $result;
        }

        $built = admin_consultation_report_statements($filters, $capabilities);
        $countStmt = $pdo->prepare($built['count_sql']);
        $countStmt->execute($built['params']);
        $total = (int) $countStmt->fetchColumn();
        $bounds = admin_consultation_report_page_bounds($total, $page, $perPage);
        $sql = $built['select_sql'] . ' LIMIT ' . (int) $bounds['per_page'] . ' OFFSET ' . (int) $bounds['offset'];
        $stmt = $pdo->prepare($sql);
        $stmt->execute($built['params']);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = admin_operational_report_values($type, $row);
        }

        $result['rows'] = $rows;
        $result['total'] = $total;
        $result['page'] = $bounds['page'];
        $result['per_page'] = $bounds['per_page'];
    } catch (Throwable $e) {
        error_log('admin_operational_report_page: ' . $e->getMessage());
        $result['error'] = 'Unable to load this report.';
    }

    return $result;
}

/**
 * Demographics table page plus barangay options and summary counts.
 *
 * @return array{type:string,title:string,description:string,headers:list<string>,rows:list<list<string>>,total:int,page:int,per_page:int,error:?string,demographics:array{barangays:list<array{id:int,name:string}>,barangay:?array{id:int,name:string},summary:array{total:int,age_groups:array<string,int>,gender:array<string,int>}}}
 */
function admin_demographics_page(PDO $pdo, int $page, ?int $perPage, int $barangayId): array
{
    $definition = admin_operational_report_definition('users');
    $perPage = max(1, $perPage ?? admin_operational_report_page_size());
    $result = [
        'type' => 'users',
        'title' => $definition['title'],
        'description' => $definition['description'],
        'headers' => $definition['headers'],
        'rows' => [],
        'total' => 0,
        'page' => 1,
        'per_page' => $perPage,
        'error' => null,
        'demographics' => [
            'barangays' => [],
            'barangay' => null,
            'summary' => [
                'total' => 0,
                'age_groups' => [],
                'gender' => [],
            ],
        ],
    ];

    try {
        if (!admin_operational_report_table_exists($pdo, $definition['table'])) {
            return $result;
        }
        $result['demographics']['barangays'] = admin_demographics_barangays($pdo);
        $barangay = admin_demographics_selected_barangay($pdo, $barangayId);
        $result['demographics']['barangay'] = $barangay;
        if ($barangay === null) {
            return $result;
        }

        $summary = admin_demographics_summary($pdo, $barangay);
        $result['demographics']['summary'] = $summary;
        $total = $summary['total'];
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $pages);
        $offset = ($page - 1) * $perPage;

        [$sql, $params] = admin_demographics_sql($pdo, $barangay);
        $stmt = $pdo->prepare('SELECT * FROM (' . $sql . ') d' . admin_demographics_order_sql()
            . ' LIMIT ' . $perPage . ' OFFSET ' . $offset);
        $stmt->execute($params);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = admin_demographics_values($row);
        }

        $result['rows'] = $rows;
        $result['total'] = $total;
        $result['page'] = $page;
    } catch (Throwable $e) {
        error_log('admin_demographics_page: ' . $e->getMessage());
        $result['error'] = 'Unable to load this report.';
    }

    return $result;
}

/**
 * Rendered report cards keyed by type, read fresh from the database.
 * Shared by the Operational Reports page and its live-refresh endpoint.
 *
 * @param array<string, mixed> $query Page query (<type>_page, users_barangay, appt_* filters).
 * @return array<string, string>
 */
function admin_operational_reports_render(PDO $pdo, array $query, string $reportBasePath): array
{
    $reportExportBase = ASSET_BASE . '/app/api/admin/operational_reports_live.php';
    $usersBarangayId = max(0, (int) ($query['users_barangay'] ?? 0));
    $appointmentFilters = admin_consultation_report_parse_filters($query);
    $reportExtraParams = $usersBarangayId > 0 ? ['users_barangay' => $usersBarangayId] : [];
    $reportExtraParams = array_merge($reportExtraParams, admin_consultation_report_query_params($appointmentFilters));
    $reportPages = [];
    foreach (array_keys(admin_operational_report_catalog()) as $reportType) {
        $reportPages[$reportType] = max(1, (int) ($query[$reportType . '_page'] ?? 1));
    }

    $html = [];
    foreach ($reportPages as $reportType => $reportPageNumber) {
        $reportModule = admin_operational_report_page(
            $pdo,
            $reportType,
            $reportPageNumber,
            null,
            $usersBarangayId,
            $reportType === 'appointments' ? $appointmentFilters : []
        );
        $reportPages[$reportType] = (int) $reportModule['page'];
        ob_start();
        require VIEWS_PATH . '/admin/partials/operational_report_module.php';
        $html[$reportType] = (string) ob_get_clean();
    }

    return $html;
}

/**
 * @param array<string, int> $pages
 * @param array<string, int|string> $extra Query params kept across pagination (e.g. users_barangay).
 */
function admin_operational_report_page_href(string $basePath, string $type, int $page, array $pages, array $extra = []): string
{
    $params = $extra;
    foreach (array_keys(admin_operational_report_catalog()) as $key) {
        $value = $key === $type ? $page : (int) ($pages[$key] ?? 1);
        if ($value > 1) {
            $params[$key . '_page'] = $value;
        }
    }
    $query = http_build_query($params);
    $href = $basePath . ($query !== '' ? '?' . $query : '');

    return $href . '#report-' . rawurlencode($type);
}
