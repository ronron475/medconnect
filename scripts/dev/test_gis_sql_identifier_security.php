<?php
/**
 * Focused GIS SQL identifier allowlist security tests (no live DB required).
 *
 * Run: php scripts/dev/test_gis_sql_identifier_security.php
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$failures = 0;

function pass(string $m): void
{
    echo "PASS  {$m}\n";
}

function fail(string $m, string $d = ''): void
{
    global $failures;
    $failures++;
    echo "FAIL  {$m}" . ($d !== '' ? " — {$d}" : '') . "\n";
}

require_once $root . '/app/core/GisDashboardService.php';

// ── Allowed aggregation columns ───────────────────────────────
$allowedAgg = ['province', 'city_municipality', 'barangay'];
$allAggOk = true;
foreach ($allowedAgg as $col) {
    if (GisDashboardService::resolveAggregationColumn($col) !== $col) {
        $allAggOk = false;
        fail('allowed aggregation column', $col);
    }
}
if ($allAggOk) {
    pass('allows aggregation columns: province, city_municipality, barangay');
}

// ── Rejected aggregation / injection payloads ─────────────────
$rejectedAgg = [
    'email',
    'password',
    'id; DROP TABLE users--',
    'barangay) UNION SELECT password FROM users--',
    'city_municipality;--',
    '1=1',
    'pr.email',
    '',
    'province`',
    "barangay' OR '1'='1",
];
$rejOk = true;
foreach ($rejectedAgg as $payload) {
    if (GisDashboardService::resolveAggregationColumn($payload) !== null) {
        $rejOk = false;
        fail('rejects aggregation payload', $payload);
    }
}
if ($rejOk) {
    pass('rejects non-allowlisted / injection aggregation identifiers');
}

// ── Registration column allowlist ─────────────────────────────
$allowedReg = ['purok', 'sitio', 'house_number', 'street', 'age', 'gender', 'registered_by_bhw_id'];
$regOk = true;
foreach ($allowedReg as $col) {
    if (GisDashboardService::resolveRegistrationColumn($col) !== $col) {
        $regOk = false;
        fail('allowed registration column', $col);
    }
}
if ($regOk) {
    pass('allows known registration select columns');
}

$rejectedReg = ['email', 'national_id', 'password', 'purok;--', 'age) OR 1=1--', 'users'];
$regRej = true;
foreach ($rejectedReg as $payload) {
    if (GisDashboardService::resolveRegistrationColumn($payload) !== null) {
        $regRej = false;
        fail('rejects registration payload', $payload);
    }
}
if ($regRej) {
    pass('rejects non-allowlisted registration columns');
}

// ── Patient location column allowlist ─────────────────────────
$allowedPl = ['location_accuracy', 'address_confidence', 'canonical_barangay'];
$plOk = true;
foreach ($allowedPl as $col) {
    if (GisDashboardService::resolvePatientLocationColumn($col) !== $col) {
        $plOk = false;
        fail('allowed patient_locations column', $col);
    }
}
if ($plOk) {
    pass('allows known patient_locations select columns');
}

$plRej = true;
foreach (['latitude;--', 'password', 'patient_id) UNION SELECT 1--', 'location_source'] as $payload) {
    if (GisDashboardService::resolvePatientLocationColumn($payload) !== null) {
        $plRej = false;
        fail('rejects patient_locations payload', $payload);
    }
}
if ($plRej) {
    pass('rejects non-allowlisted patient_locations columns');
}

// ── Table name allowlist ──────────────────────────────────────
if (GisDashboardService::resolveTableName('patient_locations') === 'patient_locations'
    && GisDashboardService::resolveTableName('users') === 'users'
    && GisDashboardService::resolveTableName('triage_results') === 'triage_results'
) {
    pass('allows known GIS tables');
} else {
    fail('allows known GIS tables');
}

$badTables = ['users; DROP TABLE users--', 'mysql.user', 'information_schema.tables', '', 'x'];
$badTblOk = true;
foreach ($badTables as $t) {
    if (GisDashboardService::resolveTableName($t) !== null) {
        $badTblOk = false;
        fail('rejects table name', $t);
    }
}
if ($badTblOk) {
    pass('rejects non-allowlisted table names');
}

// ── Patient id expression allowlist ───────────────────────────
if (GisDashboardService::resolvePatientIdExpr('u.id') === 'u.id'
    && GisDashboardService::resolvePatientIdExpr('42') === '42'
    && GisDashboardService::resolvePatientIdExpr('0') === '0'
) {
    pass('allows u.id and numeric patient id expressions');
} else {
    fail('allows u.id and numeric patient id expressions');
}

$badPid = ['u.id;--', '1 OR 1=1', 'u.id UNION SELECT', '(SELECT password FROM users)', 'u.email', ''];
$badPidOk = true;
foreach ($badPid as $p) {
    if (GisDashboardService::resolvePatientIdExpr($p) !== null) {
        $badPidOk = false;
        fail('rejects patient id expr', $p);
    }
}
if ($badPidOk) {
    pass('rejects unsafe patient id expressions');
}

// ── Table alias allowlist ─────────────────────────────────────
if (GisDashboardService::resolveTableAlias('tr') === 'tr'
    && GisDashboardService::resolveTableAlias('pr') === 'pr'
) {
    pass('allows known GIS table aliases');
} else {
    fail('allows known GIS table aliases');
}

$badAlias = ['tr;--', 'users', 'x', 'tr UNION', ''];
$badAliasOk = true;
foreach ($badAlias as $a) {
    if (GisDashboardService::resolveTableAlias($a) !== null) {
        $badAliasOk = false;
        fail('rejects table alias', $a);
    }
}
if ($badAliasOk) {
    pass('rejects non-allowlisted table aliases');
}

// ── aggregateByField / topValue must not interpolate rejects ──
final class GisIdentifierProbePdo extends PDO
{
    /** @var list<string> */
    public array $queries = [];

    public function __construct()
    {
        // Intentionally do not connect.
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->queries[] = $query;

        return false;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->queries[] = $query;

        return false;
    }

    public function exec(string $statement): int|false
    {
        $this->queries[] = $statement;

        return 0;
    }
}

$pdoProbe = new GisIdentifierProbePdo();
$refClass = new ReflectionClass(GisDashboardService::class);
$gis = $refClass->newInstanceWithoutConstructor();
$pdoProp = $refClass->getProperty('pdo');
$pdoProp->setAccessible(true);
$pdoProp->setValue($gis, $pdoProbe);
$regColsProp = $refClass->getProperty('patientRegistrationColumns');
$regColsProp->setAccessible(true);
$regColsProp->setValue($gis, []);

$aggMethod = $refClass->getMethod('aggregateByField');
$aggMethod->setAccessible(true);
$topMethod = $refClass->getMethod('topValue');
$topMethod->setAccessible(true);

$before = count($pdoProbe->queries);
$result = $aggMethod->invoke($gis, 'email); DROP TABLE users--');
$after = count($pdoProbe->queries);
if ($result === [] && $after === $before) {
    pass('aggregateByField rejects injection without issuing SQL');
} else {
    fail('aggregateByField rejects injection without issuing SQL', 'queries=' . ($after - $before));
}

$pdoProbe->queries = [];
$top = $topMethod->invoke($gis, "barangay) UNION SELECT password--");
if ($top === '—' && $pdoProbe->queries === []) {
    pass('topValue rejects injection without issuing SQL');
} else {
    fail('topValue rejects injection without issuing SQL', json_encode($pdoProbe->queries) ?: '');
}

// Source audit: no raw $column identifier interpolation
$src = (string) file_get_contents($root . '/app/core/GisDashboardService.php');
if (!preg_match('/pr\.\$column/', $src)
    && !preg_match('/TRIM\(pr\.\$/', $src)
    && str_contains($src, 'resolveAggregationColumn')
    && str_contains($src, 'ALLOWED_AGGREGATION_COLUMNS')
) {
    pass('GisDashboardService no longer interpolates raw $column into pr.$column');
} else {
    fail('GisDashboardService no longer interpolates raw $column into pr.$column');
}

if (GisDashboardService::resolveAggregationColumn('province') === 'province'
    && GisDashboardService::resolveAggregationColumn('city_municipality') === 'city_municipality'
    && GisDashboardService::resolveAggregationColumn('barangay') === 'barangay'
) {
    pass('getAnalytics aggregation fields remain allowlisted');
} else {
    fail('getAnalytics aggregation fields remain allowlisted');
}

echo "\n" . ($failures === 0 ? 'ALL PASS' : "FAILED ({$failures})") . "\n";
exit($failures === 0 ? 0 : 1);
