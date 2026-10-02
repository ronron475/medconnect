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
            'description' => 'Overview of consultations, assigned providers, appointment dates, and consultation status.',
            'headers' => ['Consultation ID', 'Patient', 'Doctor', 'Patient Complaint', 'Date', 'Time', 'Status'],
            'sql' => "SELECT c.id AS consultation_id,
                             c.patient_id,
                             TRIM(CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(p.last_name, ''))) AS patient_name,
                             COALESCE(
                                 NULLIF(TRIM(c.provider_name), ''),
                                 TRIM(CONCAT(COALESCE(d.first_name, ''), ' ', COALESCE(d.last_name, '')))
                             ) AS doctor_name,
                             c.consult_type AS patient_complaint,
                             c.consult_date,
                             c.consult_time,
                             c.status
                      FROM consultations c
                      LEFT JOIN users p ON p.id = c.patient_id
                      LEFT JOIN users d ON d.id = c.provider_id
                      ORDER BY c.id ASC",
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
function admin_operational_report_all(PDO $pdo, string $type, int $barangayId = 0): array
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
        $stmt = $pdo->query($definition['sql']);
    }
    $rows = [];
    foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
        $rows[] = admin_operational_report_values($type, $row);
    }

    return $rows;
}

/**
 * One table page from the same dataset as the CSV.
 *
 * @return array{type:string,title:string,description:string,headers:list<string>,rows:list<list<string>>,total:int,page:int,per_page:int,error:?string,demographics?:array}
 */
function admin_operational_report_page(PDO $pdo, string $type, int $page, ?int $perPage = null, int $barangayId = 0): array
{
    if (admin_operational_report_resolve($type) === 'users') {
        return admin_demographics_page($pdo, $page, $perPage, $barangayId);
    }

    $type = admin_operational_report_resolve($type);
    $definition = admin_operational_report_definition($type);
    $perPage = $perPage ?? admin_operational_report_page_size();
    $perPage = max(1, $perPage);
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
    ];

    try {
        if (!admin_operational_report_table_exists($pdo, $definition['table'])) {
            return $result;
        }

        $countStmt = $pdo->query('SELECT COUNT(*) FROM (' . $definition['sql'] . ') report_count');
        $total = (int) ($countStmt ? $countStmt->fetchColumn() : 0);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $pages);
        $offset = ($page - 1) * $perPage;
        $sql = 'SELECT * FROM (' . $definition['sql'] . ') report_page LIMIT ' . $perPage . ' OFFSET ' . $offset;
        $stmt = $pdo->query($sql);
        $rows = [];
        foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
            $rows[] = admin_operational_report_values($type, $row);
        }

        $result['rows'] = $rows;
        $result['total'] = $total;
        $result['page'] = $page;
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
 * @param array<string, mixed> $query Page query (<type>_page, users_barangay).
 * @return array<string, string>
 */
function admin_operational_reports_render(PDO $pdo, array $query, string $reportBasePath): array
{
    $reportExportBase = ASSET_BASE . '/app/api/admin/export_report.php';
    $usersBarangayId = max(0, (int) ($query['users_barangay'] ?? 0));
    $reportExtraParams = $usersBarangayId > 0 ? ['users_barangay' => $usersBarangayId] : [];
    $reportPages = [];
    foreach (array_keys(admin_operational_report_catalog()) as $reportType) {
        $reportPages[$reportType] = max(1, (int) ($query[$reportType . '_page'] ?? 1));
    }

    $html = [];
    foreach ($reportPages as $reportType => $reportPageNumber) {
        $reportModule = admin_operational_report_page($pdo, $reportType, $reportPageNumber, null, $usersBarangayId);
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
