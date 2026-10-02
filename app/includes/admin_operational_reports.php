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
            'headers' => ['ID', 'Patient', 'Doctor', 'Patient Complaint', 'Date', 'Time', 'Status'],
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
            'description' => 'Breakdown of registered patients by age, gender, and barangay sector.',
            'headers' => ['ID', 'First Name', 'Last Name', 'Email', 'Role', 'Status', 'Joined'],
            'sql' => 'SELECT id, first_name, last_name, email, role, is_active, created_at
                      FROM users
                      ORDER BY id ASC',
            'table' => 'users',
        ],
    ];
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
        $row['is_active'] = !empty($row['is_active']) ? 'Active' : 'Inactive';
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
function admin_operational_report_all(PDO $pdo, string $type): array
{
    $type = admin_operational_report_resolve($type);
    $definition = admin_operational_report_definition($type);
    if (!admin_operational_report_table_exists($pdo, $definition['table'])) {
        return [];
    }

    $stmt = $pdo->query($definition['sql']);
    $rows = [];
    foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
        $rows[] = admin_operational_report_values($type, $row);
    }

    return $rows;
}

/**
 * One table page from the same dataset as the CSV.
 *
 * @return array{type:string,title:string,description:string,headers:list<string>,rows:list<list<string>>,total:int,page:int,per_page:int,error:?string}
 */
function admin_operational_report_page(PDO $pdo, string $type, int $page, ?int $perPage = null): array
{
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
 * @param array<string, int> $pages
 */
function admin_operational_report_page_href(string $basePath, string $type, int $page, array $pages): string
{
    $params = [];
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
