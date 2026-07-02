<?php
/**
 * GIS Dashboard data layer — patient locations, summaries, and area analytics.
 *
 * Triage severity is read from triage_results.triage_level only (via TriageLevelService).
 * The GIS layer never classifies patients; AI/NLP and manual reassessment write triage_level.
 */
final class GisDashboardService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureTriageSchema();
    }

    private function appBasePath(): string
    {
        return defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
    }

    private function ensureTriageSchema(): void
    {
        $schemaPath = $this->appBasePath() . '/app/includes/triage_assessment_schema.php';
        if (!is_file($schemaPath)) {
            return;
        }
        require_once $schemaPath;
        triage_assessment_ensure_schema($this->pdo);
    }

    public function tableExists(string $table): bool
    {
        $table = preg_replace('/[^a-z0-9_]/i', '', $table);
        if ($table === '') {
            return false;
        }

        // MariaDB/MySQL do not allow bound parameters in SHOW TABLES LIKE.
        $stmt = $this->pdo->query(
            'SELECT 1 FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = '
            . $this->pdo->quote($table)
            . ' LIMIT 1'
        );

        return (bool) $stmt?->fetchColumn();
    }

    public function ensureSchema(): void
    {
        if ($this->tableExists('patient_locations')) {
            return;
        }

        $path = $this->appBasePath() . '/database/migrations/2026_06_23_gis_patient_locations.sql';
        if (!is_file($path)) {
            return;
        }

        $sql = file_get_contents($path);
        if ($sql === false) {
            return;
        }

        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            if ($statement === '' || stripos($statement, 'ADD COLUMN IF NOT EXISTS') !== false) {
                continue;
            }
            try {
                $this->pdo->exec($statement);
            } catch (Throwable $e) {
                // Non-fatal for partial MySQL versions without IF NOT EXISTS on ALTER.
            }
        }

        if (!$this->tableExists('patient_locations')) {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS patient_locations (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    patient_id INT UNSIGNED NOT NULL,
                    province VARCHAR(120) NULL,
                    city_municipality VARCHAR(120) NULL,
                    barangay VARCHAR(120) NULL,
                    address VARCHAR(255) NULL,
                    latitude DECIMAL(10,8) NULL,
                    longitude DECIMAL(11,8) NULL,
                    location_source ENUM('gps','manual','barangay_centroid','imported') NOT NULL DEFAULT 'barangay_centroid',
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_patient_locations_patient (patient_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }
    }

    public function syncMissingLocations(): void
    {
        $this->ensureSchema();
        if (!$this->tableExists('patient_registrations') || !$this->tableExists('users')) {
            return;
        }

        $sql = "
            SELECT u.id AS patient_id, pr.province, pr.city_municipality, pr.barangay,
                   COALESCE(pr.full_address, pr.address, '') AS address, u.created_at
            FROM users u
            INNER JOIN patient_registrations pr ON pr.email = u.email
            LEFT JOIN patient_locations pl ON pl.patient_id = u.id
            WHERE u.role = 'patient' AND pl.id IS NULL
            LIMIT 500
        ";

        $stmt = $this->pdo->query($sql);
        if (!$stmt) {
            return;
        }

        $insert = $this->pdo->prepare("
            INSERT INTO patient_locations
                (patient_id, province, city_municipality, barangay, address, latitude, longitude, location_source, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'barangay_centroid', ?, NOW())
        ");

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $coords = $this->resolveCoordinates(
                (string) ($row['barangay'] ?? ''),
                (string) ($row['city_municipality'] ?? ''),
                null,
                null
            );
            $insert->execute([
                (int) $row['patient_id'],
                $row['province'] ?? null,
                $row['city_municipality'] ?? null,
                $row['barangay'] ?? null,
                $row['address'] ?? null,
                $coords['lat'],
                $coords['lng'],
                $row['created_at'] ?? date('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * @return array{lat: float, lng: float, source: string}
     */
    public function resolveCoordinates(
        string $barangay,
        string $city,
        ?float $latitude,
        ?float $longitude
    ): array {
        if ($latitude !== null && $longitude !== null && $this->validCoordinate($latitude, $longitude)) {
            return ['lat' => $latitude, 'lng' => $longitude, 'source' => 'gps'];
        }

        if ($this->tableExists('barangays') && $barangay !== '') {
            $stmt = $this->pdo->prepare(
                'SELECT latitude, longitude FROM barangays WHERE LOWER(name) = LOWER(?) LIMIT 1'
            );
            $stmt->execute([preg_replace('/\s*\(Pob\.?\)\s*/i', '', $barangay)]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && $row['latitude'] !== null && $row['longitude'] !== null) {
                return [
                    'lat'  => (float) $row['latitude'],
                    'lng'  => (float) $row['longitude'],
                    'source' => 'barangay_centroid',
                ];
            }
        }

        $centroid = BagoBarangayCentroids::resolve($barangay, $city);

        return ['lat' => $centroid['lat'], 'lng' => $centroid['lng'], 'source' => 'barangay_centroid'];
    }

    /**
     * @return array<string, mixed>
     */
    public function getSummary(): array
    {
        $this->syncMissingLocations();

        $totalPatients = $this->scalar("SELECT COUNT(*) FROM users WHERE role = 'patient'");
        $today = $this->scalar(
            "SELECT COUNT(*) FROM users WHERE role = 'patient' AND DATE(created_at) = CURDATE()"
        );
        $week = $this->scalar(
            "SELECT COUNT(*) FROM users WHERE role = 'patient' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)"
        );
        $month = $this->scalar(
            "SELECT COUNT(*) FROM users WHERE role = 'patient' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)"
        );
        $emergency = $this->countEmergencyCases();
        $triageStats = $this->getTriageStats();
        $activeConsults = $this->scalar(
            "SELECT COUNT(*) FROM consultations WHERE status IN ('scheduled','in_consultation','pending')"
        );

        $topMunicipality = $this->topValue('city_municipality');
        $topBarangay = $triageStats['top_barangay']['all']['name'] ?? $this->topValue('barangay');

        return [
            'total_patients'          => $totalPatients,
            'patients_today'          => $today,
            'patients_week'           => $week,
            'patients_month'          => $month,
            'emergency_cases'         => $emergency,
            'active_consultations'    => $activeConsults,
            'top_municipality'        => $topMunicipality,
            'top_barangay'            => $topBarangay,
            'triage_stats'            => $triageStats,
            'last_updated'            => date('c'),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function getPatientRecords(array $filters = []): array
    {
        $this->syncMissingLocations();

        $where = ["u.role = 'patient'"];
        $params = [];

        if (!empty($filters['province'])) {
            $where[] = 'COALESCE(pl.province, pr.province) LIKE ?';
            $params[] = '%' . $filters['province'] . '%';
        }
        if (!empty($filters['municipality'])) {
            $where[] = 'COALESCE(pl.city_municipality, pr.city_municipality) LIKE ?';
            $params[] = '%' . $filters['municipality'] . '%';
        }
        if (!empty($filters['barangay'])) {
            $where[] = 'COALESCE(pl.barangay, pr.barangay) LIKE ?';
            $params[] = '%' . $filters['barangay'] . '%';
        }
        if (!empty($filters['status'])) {
            $where[] = 'COALESCE(u.is_active, 1) = ?';
            $params[] = $filters['status'] === 'inactive' ? 0 : 1;
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'DATE(u.created_at) >= ?';
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'DATE(u.created_at) <= ?';
            $params[] = $filters['date_to'];
        }
        if (!empty($filters['search'])) {
            $where[] = "(CONCAT(u.first_name, ' ', u.last_name) LIKE ? OR u.email LIKE ? OR CAST(u.id AS CHAR) LIKE ?)";
            $term = '%' . $filters['search'] . '%';
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }
        if (!empty($filters['triage_level']) && $this->tableExists('triage_results')) {
            require_once $this->appBasePath() . '/app/core/TriageLevelService.php';
            $level = strtolower(trim((string) $filters['triage_level']));
            if (TriageLevelService::isValid($level)) {
                $where[] = $this->triageLevelSelectSql('u.id') . ' = ?';
                $params[] = $level;
            }
        }
        if (!empty($filters['patient_ids']) && is_array($filters['patient_ids'])) {
            $ids = array_values(array_filter(array_map('intval', $filters['patient_ids'])));
            if ($ids !== []) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $where[] = 'u.id IN (' . $placeholders . ')';
                foreach ($ids as $id) {
                    $params[] = $id;
                }
            }
        }

        $sql = "
            SELECT
                u.id AS patient_id,
                CONCAT(u.first_name, ' ', u.last_name) AS patient_name,
                COALESCE(pl.barangay, pr.barangay, '') AS barangay,
                COALESCE(pl.city_municipality, pr.city_municipality, '') AS municipality,
                COALESCE(pl.province, pr.province, 'Negros Occidental') AS province,
                COALESCE(pl.address, pr.full_address, pr.address, '') AS address,
                pl.latitude,
                pl.longitude,
                pl.location_source,
                pl.updated_at AS location_updated_at,
                u.created_at AS registration_date,
                CASE WHEN COALESCE(u.is_active, 1) = 1 THEN 'Active' ELSE 'Inactive' END AS patient_status,
                " . $this->triageLevelSelectSql('u.id') . " AS triage_level,
                " . $this->triageLabelSelectSql('u.id') . " AS triage_label,
                " . $this->triageUpdatedSelectSql('u.id') . " AS triage_updated_at,
                " . $this->emergencySelectSql('u.id') . " AS is_emergency,
                " . $this->assignedBhwSelectSql() . " AS assigned_bhw,
                " . $this->assignedDoctorSelectSql() . " AS assigned_doctor,
                (SELECT COUNT(*) FROM consultations c WHERE c.patient_id = u.id
                    AND c.status IN ('scheduled','in_consultation','pending')) AS active_consultations
            FROM users u
            LEFT JOIN patient_registrations pr ON pr.email = u.email
            LEFT JOIN patient_locations pl ON pl.patient_id = u.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY u.created_at DESC
            LIMIT 2000
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as &$row) {
            $coords = $this->resolveCoordinates(
                (string) ($row['barangay'] ?? ''),
                (string) ($row['municipality'] ?? ''),
                isset($row['latitude']) ? (float) $row['latitude'] : null,
                isset($row['longitude']) ? (float) $row['longitude'] : null
            );
            $row['latitude'] = $coords['lat'];
            $row['longitude'] = $coords['lng'];
            $row['location_source'] = $row['location_source'] ?: $coords['source'];
            $row['triage_level'] = $this->normalizeStoredTriageLevel((string) ($row['triage_level'] ?? ''));
            $row['is_emergency'] = $row['triage_level'] === 'emergency';
            $row['registration_date_display'] = !empty($row['registration_date'])
                ? date('M j, Y', strtotime((string) $row['registration_date']))
                : '';
            $row['data_version'] = $this->patientDataVersion($row);
        }
        unset($row);

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAnalytics(): array
    {
        return [
            'by_province'      => $this->aggregateByField('province'),
            'by_municipality'  => $this->aggregateByField('city_municipality'),
            'by_barangay'      => $this->aggregateByField('barangay'),
            'consultations'    => $this->consultationsByBarangay(),
            'emergencies'      => $this->emergenciesByBarangay(),
            'symptoms'         => $this->symptomsByBarangay(),
            'conditions'       => $this->conditionsByBarangay(),
        ];
    }

    /**
     * Live triage severity totals and most-affected barangay per layer.
     *
     * @return array{
     *   counts: array{non_urgent:int,urgent:int,emergency:int},
     *   top_barangay: array<string, array{name:string,count:int,display:string}>,
     *   last_updated: string
     * }
     */
    public function getTriageStats(): array
    {
        require_once $this->appBasePath() . '/app/core/TriageLevelService.php';

        $counts = [
            TriageLevelService::NON_URGENT => 0,
            TriageLevelService::URGENT     => 0,
            TriageLevelService::EMERGENCY  => 0,
        ];
        $barangayTotals = [
            'all'         => [],
            'non_urgent'  => [],
            'urgent'      => [],
            'emergency'   => [],
        ];

        foreach ($this->getPatientRecords([]) as $row) {
            $level = (string) ($row['triage_level'] ?? TriageLevelService::NON_URGENT);
            if (!isset($counts[$level])) {
                $level = TriageLevelService::NON_URGENT;
            }
            $counts[$level]++;

            $barangay = trim((string) ($row['barangay'] ?? '')) ?: 'Unknown';
            $barangayTotals['all'][$barangay] = ($barangayTotals['all'][$barangay] ?? 0) + 1;
            $barangayTotals[$level][$barangay] = ($barangayTotals[$level][$barangay] ?? 0) + 1;
        }

        $topBarangay = [];
        foreach ($barangayTotals as $layer => $map) {
            $topBarangay[$layer] = $this->resolveTopBarangay($map);
        }

        return [
            'counts'       => $counts,
            'top_barangay' => $topBarangay,
            'last_updated' => date('c'),
        ];
    }

    /**
     * Event-driven sync payload — returns patients changed since $sinceIso.
     *
     * Detects registration, location update, triage reassessment, and new consultations.
     *
     * @param array<string, mixed> $filters
     * @return array{
     *   changed: list<array<string,mixed>>,
     *   summary: array<string,mixed>,
     *   triage_stats: array<string,mixed>,
     *   server_ts: string
     * }
     */
    public function getSyncChanges(string $sinceIso, array $filters = []): array
    {
        $since = date('Y-m-d H:i:s', strtotime($sinceIso) ?: time() - 60);
        $changedIds = $this->findChangedPatientIds($since, $filters);

        if ($changedIds === []) {
            return [
                'changed'      => [],
                'summary'      => $this->getSummary(),
                'triage_stats' => $this->getTriageStats(),
                'server_ts'    => date('c'),
            ];
        }

        $filters['patient_ids'] = $changedIds;

        return [
            'changed'      => $this->getPatientRecords($filters),
            'summary'      => $this->getSummary(),
            'triage_stats' => $this->getTriageStats(),
            'server_ts'    => date('c'),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     * @deprecated Use getSyncChanges() — retained for backward compatibility.
     */
    public function getUpdatesSince(string $sinceIso, array $filters = []): array
    {
        return $this->getSyncChanges($sinceIso, $filters)['changed'];
    }

    public function savePatientLocation(
        int $patientId,
        string $province,
        string $city,
        string $barangay,
        string $address,
        ?float $latitude,
        ?float $longitude,
        string $source = 'gps'
    ): void {
        $this->ensureSchema();
        $coords = $this->resolveCoordinates($barangay, $city, $latitude, $longitude);
        if ($latitude !== null && $longitude !== null && $this->validCoordinate($latitude, $longitude)) {
            $coords = ['lat' => $latitude, 'lng' => $longitude, 'source' => $source];
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO patient_locations
                (patient_id, province, city_municipality, barangay, address, latitude, longitude, location_source)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                province = VALUES(province),
                city_municipality = VALUES(city_municipality),
                barangay = VALUES(barangay),
                address = VALUES(address),
                latitude = VALUES(latitude),
                longitude = VALUES(longitude),
                location_source = VALUES(location_source),
                updated_at = NOW()
        ");
        $stmt->execute([
            $patientId,
            $province ?: null,
            $city ?: null,
            $barangay ?: null,
            $address ?: null,
            $coords['lat'],
            $coords['lng'],
            $coords['source'],
        ]);
    }

    /**
     * @return list<array{label: string, count: int}>
     */
    private function aggregateByField(string $field): array
    {
        if (!$this->tableExists('patient_registrations')) {
            return [];
        }

        $column = $field === 'city_municipality' ? 'city_municipality' : $field;
        $sql = "
            SELECT COALESCE(NULLIF(TRIM(pr.$column), ''), 'Unknown') AS label, COUNT(*) AS count
            FROM users u
            INNER JOIN patient_registrations pr ON pr.email = u.email
            WHERE u.role = 'patient'
            GROUP BY label
            ORDER BY count DESC
            LIMIT 15
        ";
        $stmt = $this->pdo->query($sql);

        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    /**
     * @return list<array{barangay: string, count: int}>
     */
    private function consultationsByBarangay(): array
    {
        if (!$this->tableExists('consultations') || !$this->tableExists('patient_registrations')) {
            return [];
        }

        $sql = "
            SELECT COALESCE(NULLIF(TRIM(pr.barangay), ''), 'Unknown') AS barangay, COUNT(*) AS count
            FROM consultations c
            INNER JOIN users u ON u.id = c.patient_id
            INNER JOIN patient_registrations pr ON pr.email = u.email
            GROUP BY barangay
            ORDER BY count DESC
            LIMIT 12
        ";
        $stmt = $this->pdo->query($sql);

        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    /**
     * @return list<array{barangay: string, count: int}>
     */
    private function emergenciesByBarangay(): array
    {
        if (!$this->tableExists('triage_results') || !$this->tableExists('patient_registrations')) {
            return [];
        }

        $sql = "
            SELECT COALESCE(NULLIF(TRIM(pr.barangay), ''), 'Unknown') AS barangay, COUNT(*) AS count
            FROM triage_results tr
            INNER JOIN users u ON u.id = tr.patient_id
            INNER JOIN patient_registrations pr ON pr.email = u.email
            WHERE " . $this->emergencyWhereSql('tr') . "
            GROUP BY barangay
            ORDER BY count DESC
            LIMIT 12
        ";
        $stmt = $this->pdo->query($sql);

        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    /**
     * @return list<array{barangay: string, label: string, count: int}>
     */
    private function symptomsByBarangay(): array
    {
        if (!$this->tableExists('triage_results') || !$this->tableExists('patient_registrations')) {
            return [];
        }

        $sql = "
            SELECT COALESCE(NULLIF(TRIM(pr.barangay), ''), 'Unknown') AS barangay,
                   COALESCE(NULLIF(TRIM(tr.chief_complaint), ''), 'General symptoms') AS label,
                   COUNT(*) AS count
            FROM triage_results tr
            INNER JOIN users u ON u.id = tr.patient_id
            INNER JOIN patient_registrations pr ON pr.email = u.email
            GROUP BY barangay, label
            ORDER BY count DESC
            LIMIT 20
        ";
        $stmt = $this->pdo->query($sql);

        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    /**
     * @return list<array{barangay: string, label: string, count: int}>
     */
    private function conditionsByBarangay(): array
    {
        if (!$this->tableExists('patient_registrations')) {
            return [];
        }

        $sql = "
            SELECT COALESCE(NULLIF(TRIM(pr.barangay), ''), 'Unknown') AS barangay,
                   COALESCE(NULLIF(TRIM(SUBSTRING_INDEX(pr.existing_conditions, ',', 1)), ''), 'Unspecified') AS label,
                   COUNT(*) AS count
            FROM patient_registrations pr
            INNER JOIN users u ON u.email = pr.email
            WHERE u.role = 'patient'
              AND COALESCE(pr.existing_conditions, '') <> ''
            GROUP BY barangay, label
            ORDER BY count DESC
            LIMIT 20
        ";
        $stmt = $this->pdo->query($sql);

        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    private function countEmergencyCases(): int
    {
        if (!$this->tableExists('triage_results')) {
            return 0;
        }

        if ($this->columnExists('triage_results', 'triage_level')) {
            return (int) $this->scalar(
                'SELECT COUNT(DISTINCT tr.patient_id) FROM triage_results tr
                 INNER JOIN (
                    SELECT patient_id, MAX(assessed_at) AS max_at
                    FROM triage_results GROUP BY patient_id
                 ) latest ON latest.patient_id = tr.patient_id AND latest.max_at = tr.assessed_at
                 WHERE COALESCE(NULLIF(TRIM(tr.triage_level), \'\'), \'non_urgent\') = \'emergency\''
            );
        }

        return (int) $this->scalar(
            'SELECT COUNT(DISTINCT tr.patient_id) FROM triage_results tr WHERE ' . $this->emergencyWhereSql('tr')
        );
    }

    private function triageLevelSelectSql(string $patientIdExpr): string
    {
        if (!$this->tableExists('triage_results') || !$this->columnExists('triage_results', 'triage_level')) {
            return '\'non_urgent\'';
        }

        return "(SELECT COALESCE(NULLIF(TRIM(tr.triage_level), ''), 'non_urgent')
            FROM triage_results tr
            WHERE tr.patient_id = {$patientIdExpr}
            ORDER BY tr.assessed_at DESC, tr.id DESC LIMIT 1)";
    }

    private function triageUpdatedSelectSql(string $patientIdExpr): string
    {
        if (!$this->tableExists('triage_results')) {
            return 'NULL';
        }

        return "(SELECT tr.assessed_at FROM triage_results tr
            WHERE tr.patient_id = {$patientIdExpr}
            ORDER BY tr.assessed_at DESC, tr.id DESC LIMIT 1)";
    }

    private function emergencySelectSql(string $patientIdExpr): string
    {
        if (!$this->tableExists('triage_results')) {
            return '0';
        }

        return "(SELECT CASE WHEN " . $this->triageLevelSelectSql($patientIdExpr) . " = 'emergency' THEN 1 ELSE 0 END)";
    }

    private function triageLabelSelectSql(string $patientIdExpr): string
    {
        if (!$this->tableExists('triage_results')) {
            return "''";
        }

        return "(SELECT COALESCE(tr.urgency_label, '') FROM triage_results tr
            WHERE tr.patient_id = {$patientIdExpr}
            ORDER BY tr.assessed_at DESC, tr.id DESC LIMIT 1)";
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<int>
     */
    private function findChangedPatientIds(string $since, array $filters = []): array
    {
        if (!$this->tableExists('users')) {
            return [];
        }

        $where = ["u.role = 'patient'"];
        $params = [];
        $changeParts = ['u.created_at >= ?'];
        $params[] = $since;

        if ($this->tableExists('patient_locations')) {
            $changeParts[] = 'pl.updated_at >= ?';
            $params[] = $since;
        }

        if ($this->tableExists('triage_results')) {
            $changeParts[] = 'EXISTS (
                SELECT 1 FROM triage_results tr
                WHERE tr.patient_id = u.id AND tr.assessed_at >= ?
            )';
            $params[] = $since;
        }

        if ($this->tableExists('consultations')) {
            $changeParts[] = 'EXISTS (
                SELECT 1 FROM consultations c
                WHERE c.patient_id = u.id AND c.created_at >= ?
            )';
            $params[] = $since;
        }

        $where[] = '(' . implode(' OR ', $changeParts) . ')';

        if (!empty($filters['province'])) {
            $where[] = 'COALESCE(pl.province, pr.province) LIKE ?';
            $params[] = '%' . $filters['province'] . '%';
        }
        if (!empty($filters['municipality'])) {
            $where[] = 'COALESCE(pl.city_municipality, pr.city_municipality) LIKE ?';
            $params[] = '%' . $filters['municipality'] . '%';
        }
        if (!empty($filters['barangay'])) {
            $where[] = 'COALESCE(pl.barangay, pr.barangay) LIKE ?';
            $params[] = '%' . $filters['barangay'] . '%';
        }
        if (!empty($filters['status'])) {
            $where[] = 'COALESCE(u.is_active, 1) = ?';
            $params[] = $filters['status'] === 'inactive' ? 0 : 1;
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'DATE(u.created_at) >= ?';
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'DATE(u.created_at) <= ?';
            $params[] = $filters['date_to'];
        }
        if (!empty($filters['search'])) {
            $where[] = "(CONCAT(u.first_name, ' ', u.last_name) LIKE ? OR u.email LIKE ? OR CAST(u.id AS CHAR) LIKE ?)";
            $term = '%' . $filters['search'] . '%';
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        $joinPr = $this->tableExists('patient_registrations')
            ? 'LEFT JOIN patient_registrations pr ON pr.email = u.email'
            : '';
        $joinPl = $this->tableExists('patient_locations')
            ? 'LEFT JOIN patient_locations pl ON pl.patient_id = u.id'
            : '';

        $sql = "
            SELECT DISTINCT u.id
            FROM users u
            {$joinPr}
            {$joinPl}
            WHERE " . implode(' AND ', $where) . '
            ORDER BY u.id DESC
            LIMIT 500
        ';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    private function normalizeStoredTriageLevel(string $level): string
    {
        require_once $this->appBasePath() . '/app/core/TriageLevelService.php';
        $level = strtolower(trim($level));

        return TriageLevelService::isValid($level) ? $level : TriageLevelService::NON_URGENT;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function patientDataVersion(array $row): string
    {
        $parts = [
            (string) ($row['registration_date'] ?? ''),
            (string) ($row['location_updated_at'] ?? ''),
            (string) ($row['triage_updated_at'] ?? ''),
            (string) ($row['triage_level'] ?? ''),
            (string) ($row['latitude'] ?? ''),
            (string) ($row['longitude'] ?? ''),
            (string) ($row['barangay'] ?? ''),
            (string) ($row['active_consultations'] ?? ''),
        ];

        return sha1(implode('|', $parts));
    }

    /**
     * @param array<string, int> $counts
     * @return array{name: string, count: int, display: string}
     */
    private function resolveTopBarangay(array $counts): array
    {
        if ($counts === []) {
            return ['name' => '—', 'count' => 0, 'display' => '—'];
        }

        arsort($counts);
        $name = (string) array_key_first($counts);
        $count = (int) $counts[$name];

        return [
            'name'    => $name,
            'count'   => $count,
            'display' => $name . ' (' . number_format($count) . ')',
        ];
    }

    private function emergencyWhereSql(string $alias = 'tr'): string
    {
        if ($this->columnExists('triage_results', 'triage_level')) {
            return "COALESCE(NULLIF(TRIM({$alias}.triage_level), ''), 'non_urgent') = 'emergency'";
        }

        return "(
            {$alias}.level IN ('1','2','high','emergency','EMERGENCY','HIGH','URGENT')
            OR LOWER(COALESCE({$alias}.urgency_label, '')) REGEXP 'emerg|urgent|critical|high'
        )";
    }

    private function assignedBhwSelectSql(): string
    {
        if (!$this->tableExists('patient_registrations') || !$this->columnExists('patient_registrations', 'registered_by_bhw_id')) {
            return "''";
        }

        return "(SELECT CONCAT(b.first_name, ' ', b.last_name) FROM users b
            WHERE b.id = pr.registered_by_bhw_id LIMIT 1)";
    }

    private function assignedDoctorSelectSql(): string
    {
        if (!$this->tableExists('consultations')) {
            return "''";
        }

        return "(SELECT CONCAT(d.first_name, ' ', d.last_name) FROM consultations c
            INNER JOIN users d ON d.id = c.provider_id
            WHERE c.patient_id = u.id AND c.provider_id IS NOT NULL AND c.provider_id > 0
            ORDER BY c.consult_date DESC, c.id DESC LIMIT 1)";
    }

    private function columnExists(string $table, string $column): bool
    {
        $table = preg_replace('/[^a-z0-9_]/i', '', $table);
        $column = preg_replace('/[^a-z0-9_]/i', '', $column);
        if ($table === '' || $column === '') {
            return false;
        }

        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1'
        );
        $stmt->execute([$table, $column]);

        return (bool) $stmt->fetchColumn();
    }

    private function topValue(string $field): string
    {
        if (!$this->tableExists('patient_registrations')) {
            return '—';
        }

        $column = $field === 'city_municipality' ? 'city_municipality' : $field;
        $sql = "
            SELECT COALESCE(NULLIF(TRIM(pr.$column), ''), '') AS label, COUNT(*) AS total
            FROM users u
            INNER JOIN patient_registrations pr ON pr.email = u.email
            WHERE u.role = 'patient'
            GROUP BY label
            HAVING label <> ''
            ORDER BY total DESC
            LIMIT 1
        ";
        $stmt = $this->pdo->query($sql);
        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;

        return is_array($row) && !empty($row['label']) ? (string) $row['label'] : '—';
    }

    private function scalar(string $sql): int
    {
        try {
            $value = $this->pdo->query($sql)?->fetchColumn();

            return (int) $value;
        } catch (Throwable $e) {
            return 0;
        }
    }

    private function validCoordinate(float $lat, float $lng): bool
    {
        return $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180
            && !($lat == 0.0 && $lng == 0.0);
    }
}
