<?php
/**
 * BHW barangay-scoped operational reports.
 */
require_once __DIR__ . '/bhw_scope.php';
require_once __DIR__ . '/bhw_clinical.php';
require_once __DIR__ . '/bago_barangay_puroks.php';

final class BhwReports
{
    private static bool $queryFailed = false;

    /**
     * Installation helper only. Report pages, refreshes, and CSV exports must not call this.
     * Apply database/migrations/2026_10_17_bhw_reports_read_only.sql instead.
     */
    public static function ensureSchema(PDO $pdo): void
    {
        require_once __DIR__ . '/bhw_activity.php';
        bhw_activity_ensure_schema($pdo);
        bhw_clinical_ensure_schema($pdo);
    }

    public static function beginQueries(): void
    {
        self::$queryFailed = false;
    }

    public static function queryFailed(): bool
    {
        return self::$queryFailed;
    }

    /** Healthcare reports are limited to an assigned BHW. */
    public static function accessAllowed(string $role, int $barangayId): bool
    {
        return $role === 'bhw' && $barangayId > 0;
    }

    /** @return list<string> */
    public static function consultationStatuses(): array
    {
        return ['pending', 'scheduled', 'in_consultation', 'completed', 'cancelled'];
    }

    /** @return list<string> */
    public static function referralStatuses(): array
    {
        return ['pending', 'accepted', 'completed', 'rejected', 'cancelled', 'expired'];
    }

    /**
     * Date column used by each filtered dataset.
     *
     * @return array{0:string,1:bool} column, whether it is a datetime
     */
    public static function metricDateColumn(string $metric): array
    {
        return match ($metric) {
            'consultations' => ['c.consult_date', false],
            'triage', 'high_risk', 'emergency' => ['tr.assessed_at', true],
            'referrals' => ['dr.created_at', true],
            'followups' => ['fu.followup_date', false],
            'home_visits' => ['hv.visit_date', false],
            default => ['pr.created_at', true],
        };
    }

    /** @return array<string, mixed> */
    public static function parseFilters(array $input): array
    {
        $gender = strtolower(trim((string) ($input['gender'] ?? '')));
        $age = trim((string) ($input['age_group'] ?? ''));
        $purok = trim(preg_replace('/[\x00-\x1F\x7F]/', '', (string) ($input['purok'] ?? '')) ?? '');

        return [
            'date_from'           => self::validDate((string) ($input['date_from'] ?? '')),
            'date_to'             => self::validDate((string) ($input['date_to'] ?? '')),
            'month'               => self::validMonth((string) ($input['month'] ?? '')),
            'year'                => self::validYear((string) ($input['year'] ?? '')),
            'purok'               => function_exists('mb_substr') ? mb_substr($purok, 0, 80) : substr($purok, 0, 80),
            'gender'              => in_array($gender, ['male', 'female'], true) ? $gender : '',
            'age_group'           => in_array($age, ['children', 'teens', 'adults', 'seniors'], true) ? $age : '',
            'consultation_status' => self::allow((string) ($input['consultation_status'] ?? ''), self::consultationStatuses()),
            'referral_status'     => self::allow((string) ($input['referral_status'] ?? ''), self::referralStatuses()),
        ];
    }

    public static function supportsPurok(PDO $pdo): bool
    {
        return in_array('purok', bhw_pr_columns($pdo), true);
    }

    private static function allow(string $value, array $allowed): string
    {
        $value = strtolower(trim($value));

        return in_array($value, $allowed, true) ? $value : '';
    }

    private static function validDate(string $value): string
    {
        $value = trim($value);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return '';
        }
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return ($dt && $dt->format('Y-m-d') === $value) ? $value : '';
    }

    private static function validMonth(string $value): string
    {
        $value = trim($value);
        if (!preg_match('/^\d{4}-\d{2}$/', $value)) {
            return '';
        }
        $month = (int) substr($value, 5, 2);

        return ($month >= 1 && $month <= 12) ? $value : '';
    }

    private static function validYear(string $value): string
    {
        $value = trim($value);

        return preg_match('/^\d{4}$/', $value) ? $value : '';
    }

    private static function patientWhere(PDO $pdo, array $ctx, array $f, array &$params, string $pr = 'pr', bool $withCalendar = true): string
    {
        [$clause, $p] = bhw_patient_sector_clause($pdo, $ctx, $pr, false);
        $params = array_merge($params, $p);
        $sql = " {$clause} ";

        if ($f['purok'] !== '' && in_array('purok', bhw_pr_columns($pdo), true)) {
            $sql .= " AND LOWER(TRIM({$pr}.purok)) = LOWER(?) ";
            $params[] = $f['purok'];
        }
        if (in_array($f['gender'], ['male', 'female'], true)) {
            $sql .= " AND LOWER(TRIM({$pr}.gender)) = ? ";
            $params[] = $f['gender'];
        }
        $ageExpr = bhw_pr_age_sql($pr);
        if ($f['age_group'] === 'children') {
            $sql .= " AND {$ageExpr} BETWEEN 0 AND 12 ";
        } elseif ($f['age_group'] === 'teens') {
            $sql .= " AND {$ageExpr} BETWEEN 13 AND 17 ";
        } elseif ($f['age_group'] === 'adults') {
            $sql .= " AND {$ageExpr} BETWEEN 18 AND 59 ";
        } elseif ($f['age_group'] === 'seniors') {
            $sql .= " AND {$ageExpr} >= 60 ";
        }

        if ($withCalendar) {
            $sql .= self::filterCalendarSql($f, $params, $pr . '.created_at', true);
        }

        return $sql;
    }

    /**
     * Barangay scope plus the demographic filters that patient-linked records support.
     *
     * @return array{0:string,1:list<mixed>}
     */
    private static function eventScope(PDO $pdo, array $ctx, array $f, string $dateColumn, bool $dateTime): array
    {
        [$clause, $params] = bhw_patient_sector_clause($pdo, $ctx, 'pr', false);
        $clause .= self::demographicSql($pdo, $f, $params, 'pr');
        $clause .= self::filterCalendarSql($f, $params, $dateColumn, $dateTime);

        return [$clause, $params];
    }

    /**
     * Inclusive from/to plus month and year together.
     * An explicit year uses that year; the month control then supplies only the month number.
     *
     * @param list<mixed> $params
     */
    public static function filterCalendarSql(array $f, array &$params, string $column, bool $dateTime): string
    {
        if (!preg_match('/^[a-z]{1,3}\.[a-z_]{2,40}$/', $column)) {
            return '';
        }
        $from = self::validDate((string) ($f['date_from'] ?? ''));
        $to = self::validDate((string) ($f['date_to'] ?? ''));
        if ($from !== '' && $to !== '' && $from > $to) {
            [$from, $to] = [$to, $from];
        }
        $year = self::validYear((string) ($f['year'] ?? ''));
        $month = self::validMonth((string) ($f['month'] ?? ''));
        $monthNumber = $month !== '' ? (int) substr($month, 5, 2) : 0;
        if ($year === '' && $month !== '') {
            $year = substr($month, 0, 4);
        }

        $sql = '';
        if ($from !== '') {
            $sql .= " AND {$column} >= ? ";
            $params[] = $dateTime ? $from . ' 00:00:00' : $from;
        }
        if ($to !== '') {
            $sql .= " AND {$column} <= ? ";
            $params[] = $dateTime ? $to . ' 23:59:59' : $to;
        }
        if ($year !== '' && $monthNumber >= 1 && $monthNumber <= 12 && (self::validYear((string) ($f['year'] ?? '')) !== '' || $month !== '')) {
            if (self::validYear((string) ($f['year'] ?? '')) !== '' && $month !== '') {
                $sql .= " AND YEAR({$column}) = ? AND MONTH({$column}) = ? ";
                $params[] = (int) $year;
                $params[] = $monthNumber;
            } elseif ($month !== '') {
                $sql .= " AND DATE_FORMAT({$column}, '%Y-%m') = ? ";
                $params[] = $month;
            } else {
                $sql .= " AND YEAR({$column}) = ? ";
                $params[] = (int) $year;
            }
        } elseif ($year !== '') {
            $sql .= " AND YEAR({$column}) = ? ";
            $params[] = (int) $year;
        }

        return $sql;
    }

    /**
     * @param list<mixed> $params
     */
    private static function demographicSql(PDO $pdo, array $f, array &$params, string $pr = 'pr'): string
    {
        $sql = '';
        if (($f['purok'] ?? '') !== '' && self::supportsPurok($pdo)) {
            $sql .= " AND LOWER(TRIM({$pr}.purok)) = LOWER(?) ";
            $params[] = $f['purok'];
        }
        if (in_array($f['gender'] ?? '', ['male', 'female'], true)) {
            $sql .= " AND LOWER(TRIM({$pr}.gender)) = ? ";
            $params[] = $f['gender'];
        }
        $ageExpr = bhw_pr_age_sql($pr);
        $age = (string) ($f['age_group'] ?? '');
        if ($age === 'children') {
            $sql .= " AND {$ageExpr} BETWEEN 0 AND 12 ";
        } elseif ($age === 'teens') {
            $sql .= " AND {$ageExpr} BETWEEN 13 AND 17 ";
        } elseif ($age === 'adults') {
            $sql .= " AND {$ageExpr} BETWEEN 18 AND 59 ";
        } elseif ($age === 'seniors') {
            $sql .= " AND {$ageExpr} >= 60 ";
        }

        return $sql;
    }

    public static function getSummary(PDO $pdo, array $ctx, array $filters = []): array
    {
        self::beginQueries();
        $f = self::parseFilters($filters);
        $params = [];
        $pw = self::patientWhere($pdo, $ctx, $f, $params);

        $totalPatients = self::scalar($pdo, "
            SELECT COUNT(*) FROM patient_registrations pr
            JOIN users u ON u.email = pr.email AND u.role = 'patient'
            WHERE {$pw}
        ", $params);

        $monthParams = [];
        $monthWhere = self::patientWhere($pdo, $ctx, $f, $monthParams, 'pr', false);
        $newMonth = self::scalar($pdo, "
            SELECT COUNT(*) FROM patient_registrations pr
            JOIN users u ON u.email = pr.email AND u.role = 'patient'
            WHERE {$monthWhere} AND DATE_FORMAT(pr.created_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
        ", $monthParams);

        $male = self::scalar($pdo, "
            SELECT COUNT(*) FROM patient_registrations pr
            JOIN users u ON u.email = pr.email AND u.role = 'patient'
            WHERE {$pw} AND LOWER(TRIM(pr.gender)) = 'male'
        ", $params);

        $female = self::scalar($pdo, "
            SELECT COUNT(*) FROM patient_registrations pr
            JOIN users u ON u.email = pr.email AND u.role = 'patient'
            WHERE {$pw} AND LOWER(TRIM(pr.gender)) = 'female'
        ", $params);

        $ageExpr = bhw_pr_age_sql('pr');
        $seniors = self::scalar($pdo, "
            SELECT COUNT(*) FROM patient_registrations pr
            JOIN users u ON u.email = pr.email AND u.role = 'patient'
            WHERE {$pw} AND {$ageExpr} >= 60
        ", $params);

        $children = self::scalar($pdo, "
            SELECT COUNT(*) FROM patient_registrations pr
            JOIN users u ON u.email = pr.email AND u.role = 'patient'
            WHERE {$pw} AND {$ageExpr} BETWEEN 0 AND 12
        ", $params);

        [$triageCol, $triageIsDateTime] = self::metricDateColumn('high_risk');
        [$riskClause, $riskParams] = self::eventScope($pdo, $ctx, $f, $triageCol, $triageIsDateTime);
        $highRisk = self::scalar($pdo, "
            SELECT COUNT(DISTINCT tr.id) FROM triage_results tr
            JOIN users u ON u.id = tr.patient_id
            JOIN patient_registrations pr ON pr.email = u.email
            WHERE {$riskClause}
              AND (tr.level IN ('1','2') OR LOWER(tr.urgency_label) LIKE '%high%' OR LOWER(tr.urgency_label) LIKE '%urgent%')
        ", $riskParams);

        [$emergencyClause, $emergencyParams] = self::eventScope($pdo, $ctx, $f, $triageCol, $triageIsDateTime);
        $emergency = self::scalar($pdo, "
            SELECT COUNT(DISTINCT tr.id) FROM triage_results tr
            JOIN users u ON u.id = tr.patient_id
            JOIN patient_registrations pr ON pr.email = u.email
            WHERE {$emergencyClause}
              AND (tr.outcome = 'emergency_referral' OR tr.level = '1')
        ", $emergencyParams);

        [$consultCol, $consultIsDate] = self::metricDateColumn('consultations');
        $consultStatusSql = $f['consultation_status'] !== '' ? ' AND c.status = ? ' : '';
        [$pendingClause, $pendingParams] = self::eventScope($pdo, $ctx, $f, $consultCol, $consultIsDate);
        if ($consultStatusSql !== '') {
            $pendingParams[] = $f['consultation_status'];
        }
        $consPending = self::scalar($pdo, "
            SELECT COUNT(*) FROM consultations c
            JOIN users u ON u.id = c.patient_id
            JOIN patient_registrations pr ON pr.email = u.email
            WHERE {$pendingClause} AND c.status IN ('scheduled','pending')
              {$consultStatusSql}
        ", $pendingParams);

        [$doneClause, $doneParams] = self::eventScope($pdo, $ctx, $f, $consultCol, $consultIsDate);
        if ($consultStatusSql !== '') {
            $doneParams[] = $f['consultation_status'];
        }
        $consCompleted = self::scalar($pdo, "
            SELECT COUNT(*) FROM consultations c
            JOIN users u ON u.id = c.patient_id
            JOIN patient_registrations pr ON pr.email = u.email
            WHERE {$doneClause} AND c.status = 'completed'
              {$consultStatusSql}
        ", $doneParams);

        [$cancelClause, $cancelParams] = self::eventScope($pdo, $ctx, $f, $consultCol, $consultIsDate);
        if ($consultStatusSql !== '') {
            $cancelParams[] = $f['consultation_status'];
        }
        $consCancelled = self::scalar($pdo, "
            SELECT COUNT(*) FROM consultations c
            JOIN users u ON u.id = c.patient_id
            JOIN patient_registrations pr ON pr.email = u.email
            WHERE {$cancelClause} AND c.status = 'cancelled'
              {$consultStatusSql}
        ", $cancelParams);

        [$refCol, $refIsDateTime] = self::metricDateColumn('referrals');
        $refStatusSql = $f['referral_status'] !== '' ? ' AND dr.status = ? ' : '';
        [$refPendingClause, $refPendingParams] = self::eventScope($pdo, $ctx, $f, $refCol, $refIsDateTime);
        if ($refStatusSql !== '') {
            $refPendingParams[] = $f['referral_status'];
        }
        $refPending = self::scalar($pdo, "
            SELECT COUNT(*) FROM digital_referrals dr
            JOIN users u ON u.id = dr.patient_id
            JOIN patient_registrations pr ON pr.email = u.email
            WHERE {$refPendingClause} AND dr.status = 'pending'
              {$refStatusSql}
        ", $refPendingParams);

        [$refDoneClause, $refDoneParams] = self::eventScope($pdo, $ctx, $f, $refCol, $refIsDateTime);
        if ($refStatusSql !== '') {
            $refDoneParams[] = $f['referral_status'];
        }
        $refCompleted = self::scalar($pdo, "
            SELECT COUNT(*) FROM digital_referrals dr
            JOIN users u ON u.id = dr.patient_id
            JOIN patient_registrations pr ON pr.email = u.email
            WHERE {$refDoneClause} AND dr.status IN ('completed','accepted')
              {$refStatusSql}
        ", $refDoneParams);

        [$visitCol, $visitIsDateTime] = self::metricDateColumn('home_visits');
        [$visitClause, $visitParams] = self::eventScope($pdo, $ctx, $f, $visitCol, $visitIsDateTime);
        $homeVisits = self::scalar($pdo, "
            SELECT COUNT(*) FROM bhw_home_visits hv
            JOIN users u ON u.id = hv.patient_id
            JOIN patient_registrations pr ON pr.email = u.email
            WHERE {$visitClause}
        ", $visitParams);

        [$followCol, $followIsDateTime] = self::metricDateColumn('followups');
        [$overdueClause, $overdueParams] = self::eventScope($pdo, $ctx, $f, $followCol, $followIsDateTime);
        $overdue = self::scalar($pdo, "
            SELECT COUNT(*) FROM followups fu
            JOIN users u ON u.id = fu.patient_id
            JOIN patient_registrations pr ON pr.email = u.email
            WHERE {$overdueClause}
              AND fu.status IN ('scheduled','missed') AND fu.followup_date < CURDATE()
        ", $overdueParams);

        return [
            'total_patients'        => $totalPatients,
            'new_patients_month'    => $newMonth,
            'new_patients_month_note' => 'Current calendar month in this barangay. The report date range does not change this count.',
            'male_patients'         => $male,
            'female_patients'       => $female,
            'senior_citizens'       => $seniors,
            'children'              => $children,
            'high_risk_patients'    => $highRisk,
            'ai_emergency_cases'    => $emergency,
            'pending_consultations' => $consPending,
            'completed_consultations'=> $consCompleted,
            'cancelled_consultations'=> $consCancelled,
            'pending_referrals'     => $refPending,
            'completed_referrals'   => $refCompleted,
            'home_visits_completed' => $homeVisits,
            'overdue_followups'     => $overdue,
            'barangay'              => $ctx['barangay_name'] ?? '',
        ];
    }

    public static function getPatientRegistration(PDO $pdo, array $ctx, array $filters = []): array
    {
        self::beginQueries();
        $f = self::parseFilters($filters);
        $params = [];
        $pw = self::patientWhere($pdo, $ctx, $f, $params);

        $total = self::scalar($pdo, "
            SELECT COUNT(*) FROM patient_registrations pr
            JOIN users u ON u.email = pr.email AND u.role = 'patient' WHERE {$pw}
        ", $params);

        $monthly = self::rows($pdo, "
            SELECT DATE_FORMAT(pr.created_at, '%Y-%m') AS label, COUNT(*) AS value
            FROM patient_registrations pr
            JOIN users u ON u.email = pr.email AND u.role = 'patient'
            WHERE {$pw}
            GROUP BY label ORDER BY label ASC LIMIT 24
        ", $params);

        $ageExpr = bhw_pr_age_sql('pr');
        $ageDist = self::rows($pdo, "
            SELECT
              CASE
                WHEN {$ageExpr} BETWEEN 0 AND 12 THEN 'Children (0-12)'
                WHEN {$ageExpr} BETWEEN 13 AND 17 THEN 'Teens (13-17)'
                WHEN {$ageExpr} BETWEEN 18 AND 59 THEN 'Adults (18-59)'
                ELSE 'Seniors (60+)'
              END AS label,
              COUNT(*) AS value
            FROM patient_registrations pr
            JOIN users u ON u.email = pr.email AND u.role = 'patient'
            WHERE {$pw} AND {$ageExpr} IS NOT NULL
            GROUP BY label
        ", $params);

        $genderDist = self::rows($pdo, "
            SELECT CONCAT(UPPER(LEFT(TRIM(pr.gender),1)), LOWER(SUBSTRING(TRIM(pr.gender),2))) AS label, COUNT(*) AS value
            FROM patient_registrations pr
            JOIN users u ON u.email = pr.email AND u.role = 'patient'
            WHERE {$pw} AND pr.gender IS NOT NULL AND TRIM(pr.gender) != ''
            GROUP BY label
        ", $params);

        $purokCol = in_array('purok', bhw_pr_columns($pdo), true) ? 'pr.purok' : 'pr.barangay';
        $purokDist = self::rows($pdo, "
            SELECT COALESCE(NULLIF(TRIM({$purokCol}), ''), 'Unspecified') AS label, COUNT(*) AS value
            FROM patient_registrations pr
            JOIN users u ON u.email = pr.email AND u.role = 'patient'
            WHERE {$pw}
            GROUP BY label ORDER BY value DESC LIMIT 15
        ", $params);

        return compact('total', 'monthly', 'ageDist', 'genderDist', 'purokDist');
    }

    public static function getConsultations(PDO $pdo, array $ctx, array $filters = []): array
    {
        self::beginQueries();
        $f = self::parseFilters($filters);
        [$consultCol, $consultIsDate] = self::metricDateColumn('consultations');
        [$clause, $params] = self::eventScope($pdo, $ctx, $f, $consultCol, $consultIsDate);
        $extra = '';
        if ($f['consultation_status'] !== '') {
            $extra .= ' AND c.status = ? ';
            $params[] = $f['consultation_status'];
        }

        $byStatus = self::rows($pdo, "
            SELECT c.status AS label, COUNT(*) AS value
            FROM consultations c
            JOIN users u ON u.id = c.patient_id
            JOIN patient_registrations pr ON pr.email = u.email
            WHERE {$clause} {$extra}
            GROUP BY c.status
        ", $params);

        $upcoming = self::scalar($pdo, "
            SELECT COUNT(*) FROM consultations c
            JOIN users u ON u.id = c.patient_id
            JOIN patient_registrations pr ON pr.email = u.email
            WHERE {$clause} AND c.status = 'scheduled' AND c.consult_date >= CURDATE() {$extra}
        ", $params);

        $providerDist = self::rows($pdo, "
            SELECT COALESCE(NULLIF(TRIM(c.provider_name), ''), 'Unassigned') AS label, COUNT(*) AS value
            FROM consultations c
            JOIN users u ON u.id = c.patient_id
            JOIN patient_registrations pr ON pr.email = u.email
            WHERE {$clause} {$extra}
            GROUP BY label ORDER BY value DESC LIMIT 10
        ", $params);

        $monthly = self::rows($pdo, "
            SELECT DATE_FORMAT(c.consult_date, '%Y-%m') AS label, COUNT(*) AS value
            FROM consultations c
            JOIN users u ON u.id = c.patient_id
            JOIN patient_registrations pr ON pr.email = u.email
            WHERE {$clause} {$extra}
            GROUP BY label ORDER BY label ASC LIMIT 12
        ", $params);

        return [
            'by_status'      => $byStatus,
            'upcoming'       => $upcoming,
            'provider_dist'  => $providerDist,
            'monthly_trend'  => $monthly,
            'scheduled'      => self::statusCount($byStatus, 'scheduled'),
            'completed'      => self::statusCount($byStatus, 'completed'),
            'cancelled'      => self::statusCount($byStatus, 'cancelled'),
            'in_consultation'=> self::statusCount($byStatus, 'in_consultation'),
            'pending'        => self::statusCount($byStatus, 'pending'),
        ];
    }

    public static function getTriage(PDO $pdo, array $ctx, array $filters = []): array
    {
        self::beginQueries();
        $f = self::parseFilters($filters);
        [$triageCol, $triageIsDateTime] = self::metricDateColumn('triage');
        [$clause, $params] = self::eventScope($pdo, $ctx, $f, $triageCol, $triageIsDateTime);
        $extra = '';

        $byUrgency = self::rows($pdo, "
            SELECT COALESCE(NULLIF(TRIM(tr.urgency_label), ''), 'Unknown') AS label, COUNT(*) AS value
            FROM triage_results tr
            JOIN users u ON u.id = tr.patient_id
            JOIN patient_registrations pr ON pr.email = u.email
            WHERE {$clause} {$extra}
            GROUP BY label ORDER BY value DESC
        ", $params);

        $byLevel = self::rows($pdo, "
            SELECT CONCAT('Level ', tr.level) AS label, COUNT(*) AS value
            FROM triage_results tr
            JOIN users u ON u.id = tr.patient_id
            JOIN patient_registrations pr ON pr.email = u.email
            WHERE {$clause} {$extra}
            GROUP BY tr.level ORDER BY tr.level
        ", $params);

        $symptoms = self::rows($pdo, "
            SELECT tr.chief_complaint AS label, COUNT(*) AS value
            FROM triage_results tr
            JOIN users u ON u.id = tr.patient_id
            JOIN patient_registrations pr ON pr.email = u.email
            WHERE {$clause} {$extra} AND tr.chief_complaint IS NOT NULL AND TRIM(tr.chief_complaint) != ''
            GROUP BY tr.chief_complaint ORDER BY value DESC LIMIT 10
        ", $params);

        $classifications = self::rows($pdo, "
            SELECT COALESCE(NULLIF(TRIM(tr.triage_classification), ''), tr.urgency_label, 'Unclassified') AS label, COUNT(*) AS value
            FROM triage_results tr
            JOIN users u ON u.id = tr.patient_id
            JOIN patient_registrations pr ON pr.email = u.email
            WHERE {$clause} {$extra}
            GROUP BY label ORDER BY value DESC LIMIT 10
        ", $params);

        $low = $moderate = $high = $emergency = 0;
        foreach ($byUrgency as $row) {
            $l = strtolower($row['label']);
            if (str_contains($l, 'emergency') || str_contains($l, 'critical')) {
                $emergency += (int) $row['value'];
            } elseif (str_contains($l, 'high') || str_contains($l, 'urgent')) {
                $high += (int) $row['value'];
            } elseif (str_contains($l, 'moderate') || str_contains($l, 'medium')) {
                $moderate += (int) $row['value'];
            } else {
                $low += (int) $row['value'];
            }
        }

        return [
            'low_risk'        => $low,
            'moderate_risk'   => $moderate,
            'high_risk'       => $high,
            'emergency'       => $emergency,
            'by_urgency'      => $byUrgency,
            'by_level'        => $byLevel,
            'top_symptoms'    => $symptoms,
            'classifications' => $classifications,
        ];
    }

    public static function getReferrals(PDO $pdo, array $ctx, array $filters = []): array
    {
        self::beginQueries();
        $f = self::parseFilters($filters);
        [$refCol, $refIsDateTime] = self::metricDateColumn('referrals');
        [$clause, $params] = self::eventScope($pdo, $ctx, $f, $refCol, $refIsDateTime);
        $extra = '';
        if ($f['referral_status'] !== '') {
            $extra .= ' AND dr.status = ? ';
            $params[] = $f['referral_status'];
        }

        $byType = self::rows($pdo, "
            SELECT COALESCE(NULLIF(TRIM(dr.referral_type), ''), 'Other') AS label, COUNT(*) AS value
            FROM digital_referrals dr
            JOIN users u ON u.id = dr.patient_id
            JOIN patient_registrations pr ON pr.email = u.email
            WHERE {$clause} {$extra}
            GROUP BY label ORDER BY value DESC
        ", $params);

        $byStatus = self::rows($pdo, "
            SELECT dr.status AS label, COUNT(*) AS value
            FROM digital_referrals dr
            JOIN users u ON u.id = dr.patient_id
            JOIN patient_registrations pr ON pr.email = u.email
            WHERE {$clause} {$extra}
            GROUP BY dr.status
        ", $params);

        $monthly = self::rows($pdo, "
            SELECT DATE_FORMAT(dr.created_at, '%Y-%m') AS label, COUNT(*) AS value
            FROM digital_referrals dr
            JOIN users u ON u.id = dr.patient_id
            JOIN patient_registrations pr ON pr.email = u.email
            WHERE {$clause} {$extra}
            GROUP BY label ORDER BY label ASC LIMIT 12
        ", $params);

        return [
            'by_type'   => $byType,
            'by_status' => $byStatus,
            'monthly'   => $monthly,
            'hospital'  => self::typeCount($byType, 'Hospital'),
            'specialist'=> self::typeCount($byType, 'Specialist'),
            'laboratory'=> self::typeCount($byType, 'Laboratory'),
            'pending'   => self::statusCount($byStatus, 'pending'),
            'accepted'  => self::statusCount($byStatus, 'accepted'),
            'completed' => self::statusCount($byStatus, 'completed'),
            'rejected'  => self::statusCount($byStatus, 'rejected'),
            'cancelled' => self::statusCount($byStatus, 'cancelled'),
        ];
    }

    public static function getFollowups(PDO $pdo, array $ctx, array $filters = []): array
    {
        self::beginQueries();
        $f = self::parseFilters($filters);
        [$visitCol, $visitIsDateTime] = self::metricDateColumn('home_visits');
        [$visitClause, $visitParams] = self::eventScope($pdo, $ctx, $f, $visitCol, $visitIsDateTime);
        [$followCol, $followIsDateTime] = self::metricDateColumn('followups');
        [$clause, $params] = self::eventScope($pdo, $ctx, $f, $followCol, $followIsDateTime);

        $homeVisits = self::scalar($pdo, "
            SELECT COUNT(*) FROM bhw_home_visits hv
            JOIN users u ON u.id = hv.patient_id
            JOIN patient_registrations pr ON pr.email = u.email
            WHERE {$visitClause}
        ", $visitParams);

        $completed = self::scalar($pdo, "
            SELECT COUNT(*) FROM followups fu
            JOIN users u ON u.id = fu.patient_id
            JOIN patient_registrations pr ON pr.email = u.email
            WHERE {$clause} AND fu.status = 'completed'
        ", $params);

        $pending = self::scalar($pdo, "
            SELECT COUNT(*) FROM followups fu
            JOIN users u ON u.id = fu.patient_id
            JOIN patient_registrations pr ON pr.email = u.email
            WHERE {$clause} AND fu.status = 'scheduled' AND fu.followup_date >= CURDATE()
        ", $params);

        $overdue = self::scalar($pdo, "
            SELECT COUNT(*) FROM followups fu
            JOIN users u ON u.id = fu.patient_id
            JOIN patient_registrations pr ON pr.email = u.email
            WHERE {$clause} AND fu.status IN ('scheduled','missed') AND fu.followup_date < CURDATE()
        ", $params);

        $requiring = self::recordRows($pdo, "
            SELECT CONCAT(u.first_name, ' ', u.last_name) AS patient_name, fu.followup_date, fu.status
            FROM followups fu
            JOIN users u ON u.id = fu.patient_id
            JOIN patient_registrations pr ON pr.email = u.email
            WHERE {$clause} AND fu.status IN ('scheduled','missed')
            ORDER BY fu.followup_date ASC LIMIT 20
        ", $params);

        $visitTypes = self::rows($pdo, "
            SELECT hv.visit_type AS label, COUNT(*) AS value
            FROM bhw_home_visits hv
            JOIN users u ON u.id = hv.patient_id
            JOIN patient_registrations pr ON pr.email = u.email
            WHERE {$visitClause}
            GROUP BY hv.visit_type
        ", $visitParams);

        return compact('homeVisits', 'completed', 'pending', 'overdue', 'requiring', 'visitTypes');
    }

    public static function getDiseaseStats(PDO $pdo, array $ctx, array $filters = []): array
    {
        self::beginQueries();
        $f = self::parseFilters($filters);
        $params = [];
        $pw = self::patientWhere($pdo, $ctx, $f, $params);

        $conditions = self::rows($pdo, "
            SELECT COALESCE(NULLIF(TRIM(pr.existing_conditions), ''), 'None reported') AS label, COUNT(*) AS value
            FROM patient_registrations pr
            JOIN users u ON u.email = pr.email AND u.role = 'patient'
            WHERE {$pw} AND pr.existing_conditions IS NOT NULL AND TRIM(pr.existing_conditions) != ''
            GROUP BY label ORDER BY value DESC LIMIT 10
        ", $params);

        [$triageCol, $triageIsDateTime] = self::metricDateColumn('triage');
        [$triageClause, $triageParams] = self::eventScope($pdo, $ctx, $f, $triageCol, $triageIsDateTime);
        $symptoms = self::rows($pdo, "
            SELECT tr.chief_complaint AS label, COUNT(*) AS value
            FROM triage_results tr
            JOIN users u ON u.id = tr.patient_id
            JOIN patient_registrations pr ON pr.email = u.email
            WHERE {$triageClause} AND tr.chief_complaint IS NOT NULL AND TRIM(tr.chief_complaint) != ''
            GROUP BY tr.chief_complaint ORDER BY value DESC LIMIT 10
        ", $triageParams);

        $ageExpr = bhw_pr_age_sql('pr');
        $ageGroups = self::rows($pdo, "
            SELECT
              CASE
                WHEN {$ageExpr} BETWEEN 0 AND 12 THEN 'Children'
                WHEN {$ageExpr} BETWEEN 13 AND 17 THEN 'Teens'
                WHEN {$ageExpr} BETWEEN 18 AND 59 THEN 'Adults'
                ELSE 'Seniors'
              END AS label, COUNT(*) AS value
            FROM patient_registrations pr
            JOIN users u ON u.email = pr.email AND u.role = 'patient'
            WHERE {$pw} AND {$ageExpr} IS NOT NULL
            GROUP BY label
        ", $params);

        [$consultCol, $consultIsDate] = self::metricDateColumn('consultations');
        [$consultClause, $consultParams] = self::eventScope($pdo, $ctx, $f, $consultCol, $consultIsDate);
        if ($f['consultation_status'] !== '') {
            $consultClause .= ' AND c.status = ? ';
            $consultParams[] = $f['consultation_status'];
        }
        $diagnoses = self::rows($pdo, "
            SELECT COALESCE(NULLIF(TRIM(c.diagnosis), ''), 'Pending') AS label, COUNT(*) AS value
            FROM consultations c
            JOIN users u ON u.id = c.patient_id
            JOIN patient_registrations pr ON pr.email = u.email
            WHERE {$consultClause} AND c.diagnosis IS NOT NULL AND TRIM(c.diagnosis) != ''
            GROUP BY label ORDER BY value DESC LIMIT 10
        ", $consultParams);

        $monthly = self::rows($pdo, "
            SELECT DATE_FORMAT(tr.assessed_at, '%Y-%m') AS label, COUNT(*) AS value
            FROM triage_results tr
            JOIN users u ON u.id = tr.patient_id
            JOIN patient_registrations pr ON pr.email = u.email
            WHERE {$triageClause}
            GROUP BY label ORDER BY label ASC LIMIT 12
        ", $triageParams);

        return [
            'top_diseases'    => $conditions,
            'top_symptoms'    => $symptoms,
            'age_groups'      => $ageGroups,
            'diagnoses'       => $diagnoses,
            'monthly_trends'  => $monthly,
        ];
    }

    public static function listPuroks(PDO $pdo, array $ctx, array $filters = []): array
    {
        if (!in_array('purok', bhw_pr_columns($pdo), true)) {
            return [];
        }

        $params = [];
        $f = self::parseFilters($filters);
        $pw = self::patientWhere($pdo, $ctx, $f, $params);
        $rows = [];
        try {
            $stmt = $pdo->prepare("
                SELECT DISTINCT TRIM(pr.purok) AS purok FROM patient_registrations pr
                WHERE {$pw} AND pr.purok IS NOT NULL AND TRIM(pr.purok) != ''
                ORDER BY purok ASC
            ");
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log('bhw_reports purok list failed: ' . $e->getMessage());
            $rows = [];
        }

        $labels = [];
        $barangayId = (int) ($ctx['barangay_id'] ?? 0);
        $barangayName = trim((string) ($ctx['barangay_name'] ?? ''));
        if ($barangayName !== '') {
            foreach (BagoBarangayPuroks::referenceLabels($barangayName) as $ref) {
                $labels[$ref] = true;
            }
        }
        foreach (self::patientPuroksByBarangayName($pdo, $barangayId, $barangayName) as $ref) {
            $labels[$ref] = true;
        }

        foreach ($rows as $row) {
            $p = trim((string) ($row['purok'] ?? ''));
            if ($p === '') {
                continue;
            }
            $labels[$p] = true;
        }

        $sorted = array_keys($labels);
        usort($sorted, static function (string $a, string $b): int {
            return strnatcasecmp($a, $b);
        });

        return self::formatPurokRows($sorted);
    }

    /**
     * Distinct puroks recorded for a barangay (registration data), city-wide scope.
     *
     * @return list<string>
     */
    private static function patientPuroksByBarangayName(PDO $pdo, int $barangayId, string $barangayName): array
    {
        $cols = bhw_pr_columns($pdo);
        if (!in_array('purok', $cols, true)) {
            return [];
        }
        $name = trim($barangayName);
        if ($name === '') {
            return [];
        }
        $params = [$name];
        $sql = "
            SELECT DISTINCT TRIM(pr.purok) AS purok
            FROM patient_registrations pr
            WHERE pr.purok IS NOT NULL AND TRIM(pr.purok) != ''
              AND LOWER(TRIM(pr.barangay)) = LOWER(?)
        ";
        if (in_array('barangay_id', $cols, true) && $barangayId > 0) {
            $sql = "
                SELECT DISTINCT TRIM(pr.purok) AS purok
                FROM patient_registrations pr
                WHERE pr.purok IS NOT NULL AND TRIM(pr.purok) != ''
                  AND (
                    pr.barangay_id = ?
                    OR (
                      pr.barangay_id IS NULL
                      AND LOWER(TRIM(CONVERT(pr.barangay USING utf8mb4))) COLLATE utf8mb4_unicode_ci
                        = LOWER(TRIM(CONVERT(? USING utf8mb4))) COLLATE utf8mb4_unicode_ci
                    )
                  )
            ";
            $params = [$barangayId, $name];
        }
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        } catch (PDOException $e) {
            error_log('bhw_reports purok lookup failed: ' . $e->getMessage());

            return [];
        }
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $p) {
            $p = trim((string) $p);
            if ($p !== '') {
                $out[] = $p;
            }
        }

        return $out;
    }

    /**
     * @param list<string> $labels
     * @return list<array{purok: string}>
     */
    private static function formatPurokRows(array $labels): array
    {
        $out = [];
        foreach ($labels as $label) {
            $p = trim((string) $label);
            if ($p === '') {
                continue;
            }
            $out[] = ['purok' => $p];
        }

        return $out;
    }

    /** @return list<array{id: int, name: string}> */
    public static function listBarangays(PDO $pdo): array
    {
        return bhw_list_barangay_options($pdo);
    }

    /**
     * Aggregate rows for the active report. Patient names and diagnosis labels are omitted.
     *
     * @param array<string, mixed> $report
     * @return array{headers:list<string>,rows:list<list<string>>}
     */
    public static function exportTable(string $type, array $report, string $barangay, array $filters = []): array
    {
        $headers = ['Section', 'Label', 'Count'];
        $rows = [['Barangay', $barangay !== '' ? $barangay : 'Assigned barangay', '']];
        $rows[] = ['Filters', self::filterCaption($filters), ''];
        $series = self::exportSeries($type, $report);
        if ($series === []) {
            $rows[] = ['Report', 'No matching totals', '0'];
        }
        foreach ($series as $section => $items) {
            if ($items === []) {
                $rows[] = [$section, 'None', '0'];
                continue;
            }
            foreach ($items as $item) {
                $rows[] = [$section, (string) ($item['label'] ?? ''), (string) (int) ($item['value'] ?? 0)];
            }
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * @param array<string, mixed> $filters
     */
    public static function filterCaption(array $filters): string
    {
        $f = self::parseFilters($filters);
        $parts = [];
        foreach ([
            'date_from' => 'From',
            'date_to' => 'To',
            'month' => 'Month',
            'year' => 'Year',
            'gender' => 'Gender',
            'age_group' => 'Age',
            'purok' => 'Purok',
            'consultation_status' => 'Consultation status',
            'referral_status' => 'Referral status',
        ] as $key => $label) {
            if (($f[$key] ?? '') !== '') {
                $parts[] = $label . ' ' . $f[$key];
            }
        }

        return $parts === [] ? 'None' : implode('; ', $parts);
    }

    /**
     * @param array<string, mixed> $report
     * @return array<string, list<array{label:string,value:int}>>
     */
    private static function exportSeries(string $type, array $report): array
    {
        $pairs = static function (array $rows): array {
            $out = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $out[] = [
                    'label' => (string) ($row['label'] ?? ''),
                    'value' => (int) ($row['value'] ?? $row['count'] ?? 0),
                ];
            }

            return $out;
        };
        $counts = static function (array $map): array {
            $out = [];
            foreach ($map as $label => $value) {
                if (is_array($value) || !is_numeric($value)) {
                    continue;
                }
                $out[] = ['label' => (string) $label, 'value' => (int) $value];
            }

            return $out;
        };

        return match ($type) {
            'summary' => ['Summary' => $counts($report)],
            'patients' => [
                'Total' => [['label' => 'Registered patients', 'value' => (int) ($report['total'] ?? 0)]],
                'Monthly registration' => $pairs($report['monthly'] ?? []),
                'Age' => $pairs($report['ageDist'] ?? []),
                'Gender' => $pairs($report['genderDist'] ?? []),
                'Purok' => $pairs($report['purokDist'] ?? []),
            ],
            'consultations' => [
                'Status' => $pairs($report['by_status'] ?? []),
                'Upcoming' => [['label' => 'Upcoming scheduled', 'value' => (int) ($report['upcoming'] ?? 0)]],
                'Provider' => $pairs($report['provider_dist'] ?? []),
                'Monthly' => $pairs($report['monthly_trend'] ?? []),
            ],
            'triage' => [
                'Risk' => $counts([
                    'Low' => (int) ($report['low_risk'] ?? 0),
                    'Moderate' => (int) ($report['moderate_risk'] ?? 0),
                    'High' => (int) ($report['high_risk'] ?? 0),
                    'Emergency' => (int) ($report['emergency'] ?? 0),
                ]),
                'Urgency' => $pairs($report['by_urgency'] ?? []),
                'Level' => $pairs($report['by_level'] ?? []),
                'Classification' => $pairs($report['classifications'] ?? []),
            ],
            'referrals' => [
                'Type' => $pairs($report['by_type'] ?? []),
                'Status' => $pairs($report['by_status'] ?? []),
                'Monthly' => $pairs($report['monthly'] ?? []),
            ],
            'followups' => [
                'Totals' => $counts([
                    'Home visits' => (int) ($report['homeVisits'] ?? 0),
                    'Completed follow-ups' => (int) ($report['completed'] ?? 0),
                    'Pending follow-ups' => (int) ($report['pending'] ?? 0),
                    'Overdue follow-ups' => (int) ($report['overdue'] ?? 0),
                ]),
                'Home visit type' => $pairs($report['visitTypes'] ?? []),
            ],
            'disease' => [
                'Age' => $pairs($report['age_groups'] ?? []),
                'Monthly triage' => $pairs($report['monthly_trends'] ?? []),
            ],
            default => [],
        };
    }

    public static function logExport(PDO $pdo, array $ctx, string $type, string $format, array $filters): void
    {
        $bhwId = (int) ($_SESSION['user_id'] ?? 0);
        try {
            $ready = $pdo->prepare(
                'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
            );
            $ready->execute(['bhw_report_exports']);
            if (!$ready->fetchColumn()) {
                error_log('bhw_reports export log skipped: bhw_report_exports is not installed');
            } else {
            $pdo->prepare('
                INSERT INTO bhw_report_exports (bhw_id, barangay_id, barangay_name, report_type, export_format, filters_json, ip_address)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ')->execute([
                $bhwId,
                $ctx['barangay_id'] ?? null,
                $ctx['barangay_name'] ?? null,
                $type,
                $format,
                json_encode($filters, JSON_UNESCAPED_UNICODE),
                $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
            }
        } catch (PDOException $e) {
            error_log('bhw_reports export log failed: ' . $e->getMessage());
        }
        bhw_activity_log($pdo, 'bhw_report_exported', "BHW exported {$type} report as {$format}.", [
            'report_type' => $type,
            'format'      => $format,
        ]);
    }

    private static function scalar(PDO $pdo, string $sql, array $params): int
    {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            self::$queryFailed = true;
            error_log('bhw_reports query failed: ' . $e->getMessage());

            return 0;
        }
    }

    /** @return list<array{label: string, value: int}> */
    private static function rows(PDO $pdo, string $sql, array $params): array
    {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return array_map(static fn ($r) => ['label' => (string) ($r['label'] ?? ''), 'value' => (int) ($r['value'] ?? 0)], $rows);
        } catch (PDOException $e) {
            self::$queryFailed = true;
            error_log('bhw_reports query failed: ' . $e->getMessage());

            return [];
        }
    }

    /** @return list<array<string, mixed>> Full rows (rows() keeps only label/value). */
    private static function recordRows(PDO $pdo, string $sql, array $params): array
    {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            self::$queryFailed = true;
            error_log('bhw_reports query failed: ' . $e->getMessage());

            return [];
        }
    }

    private static function statusCount(array $rows, string $status): int
    {
        foreach ($rows as $r) {
            if (strtolower($r['label']) === strtolower($status)) {
                return (int) $r['value'];
            }
        }
        return 0;
    }

    private static function typeCount(array $rows, string $type): int
    {
        foreach ($rows as $r) {
            if (strcasecmp($r['label'], $type) === 0) {
                return (int) $r['value'];
            }
        }
        return 0;
    }
}
