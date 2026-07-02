<?php
/**
 * BHW barangay-scoped operational reports.
 */
require_once __DIR__ . '/bhw_scope.php';
require_once __DIR__ . '/bhw_clinical.php';

final class BhwReports
{
    public static function ensureSchema(PDO $pdo): void
    {
        require_once __DIR__ . '/bhw_activity.php';
        bhw_activity_ensure_schema($pdo);
        bhw_clinical_ensure_schema($pdo);
    }

    /** @return array<string, mixed> */
    public static function parseFilters(array $input): array
    {
        return [
            'date_from'           => trim($input['date_from'] ?? ''),
            'date_to'             => trim($input['date_to'] ?? ''),
            'month'               => trim($input['month'] ?? ''),
            'year'                => trim($input['year'] ?? ''),
            'purok'               => trim($input['purok'] ?? ''),
            'gender'              => strtolower(trim($input['gender'] ?? '')),
            'age_group'           => trim($input['age_group'] ?? ''),
            'consultation_status' => trim($input['consultation_status'] ?? ''),
            'referral_status'     => trim($input['referral_status'] ?? ''),
        ];
    }

    private static function patientWhere(PDO $pdo, array $ctx, array $f, array &$params, string $pr = 'pr'): string
    {
        [$clause, $p] = bhw_patient_sector_clause($pdo, $ctx, $pr);
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
        if ($f['age_group'] === 'children') {
            $sql .= " AND CAST({$pr}.age AS UNSIGNED) BETWEEN 0 AND 12 ";
        } elseif ($f['age_group'] === 'teens') {
            $sql .= " AND CAST({$pr}.age AS UNSIGNED) BETWEEN 13 AND 17 ";
        } elseif ($f['age_group'] === 'adults') {
            $sql .= " AND CAST({$pr}.age AS UNSIGNED) BETWEEN 18 AND 59 ";
        } elseif ($f['age_group'] === 'seniors') {
            $sql .= " AND CAST({$pr}.age AS UNSIGNED) >= 60 ";
        }

        if ($f['date_from'] !== '') {
            $sql .= " AND {$pr}.created_at >= ? ";
            $params[] = $f['date_from'] . ' 00:00:00';
        }
        if ($f['date_to'] !== '') {
            $sql .= " AND {$pr}.created_at <= ? ";
            $params[] = $f['date_to'] . ' 23:59:59';
        }
        if ($f['month'] !== '' && preg_match('/^\d{4}-\d{2}$/', $f['month'])) {
            $sql .= " AND DATE_FORMAT({$pr}.created_at, '%Y-%m') = ? ";
            $params[] = $f['month'];
        } elseif ($f['year'] !== '' && preg_match('/^\d{4}$/', $f['year'])) {
            $sql .= " AND YEAR({$pr}.created_at) = ? ";
            $params[] = (int) $f['year'];
        }

        return $sql;
    }

    private static function consultDateWhere(array $f, array &$params, string $col = 'c.consult_date'): string
    {
        $sql = '';
        if ($f['date_from'] !== '') {
            $sql .= " AND {$col} >= ? ";
            $params[] = $f['date_from'];
        }
        if ($f['date_to'] !== '') {
            $sql .= " AND {$col} <= ? ";
            $params[] = $f['date_to'];
        }
        if ($f['month'] !== '' && preg_match('/^\d{4}-\d{2}$/', $f['month'])) {
            $sql .= " AND DATE_FORMAT({$col}, '%Y-%m') = ? ";
            $params[] = $f['month'];
        } elseif ($f['year'] !== '' && preg_match('/^\d{4}$/', $f['year'])) {
            $sql .= " AND YEAR({$col}) = ? ";
            $params[] = (int) $f['year'];
        }
        return $sql;
    }

    public static function getSummary(PDO $pdo, array $ctx, array $filters = []): array
    {
        self::ensureSchema($pdo);
        $f = self::parseFilters($filters);
        $params = [];
        $pw = self::patientWhere($pdo, $ctx, $f, $params);

        $totalPatients = self::scalar($pdo, "
            SELECT COUNT(*) FROM patient_registrations pr
            JOIN users u ON u.email = pr.email AND u.role = 'patient'
            WHERE {$pw}
        ", $params);

        $pMonth = $params;
        $newMonth = self::scalar($pdo, "
            SELECT COUNT(*) FROM patient_registrations pr
            JOIN users u ON u.email = pr.email AND u.role = 'patient'
            WHERE {$pw} AND DATE_FORMAT(pr.created_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
        ", $pMonth);

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

        $seniors = self::scalar($pdo, "
            SELECT COUNT(*) FROM patient_registrations pr
            JOIN users u ON u.email = pr.email AND u.role = 'patient'
            WHERE {$pw} AND CAST(pr.age AS UNSIGNED) >= 60
        ", $params);

        $children = self::scalar($pdo, "
            SELECT COUNT(*) FROM patient_registrations pr
            JOIN users u ON u.email = pr.email AND u.role = 'patient'
            WHERE {$pw} AND CAST(pr.age AS UNSIGNED) BETWEEN 0 AND 12
        ", $params);

        $cp = [];
        [$cClause, $cParams] = bhw_patient_sector_clause($pdo, $ctx, 'pr');
        $cp = $cParams;
        $cExtra = self::consultDateWhere($f, $cp);

        $highRisk = self::scalar($pdo, "
            SELECT COUNT(DISTINCT tr.id) FROM triage_results tr
            JOIN users u ON u.id = tr.patient_id
            JOIN patient_registrations pr ON pr.email = u.email
            WHERE {$cClause}
              AND (tr.level IN ('1','2') OR LOWER(tr.urgency_label) LIKE '%high%' OR LOWER(tr.urgency_label) LIKE '%urgent%')
              {$cExtra}
        ", $cp);

        $emergency = self::scalar($pdo, "
            SELECT COUNT(DISTINCT tr.id) FROM triage_results tr
            JOIN users u ON u.id = tr.patient_id
            JOIN patient_registrations pr ON pr.email = u.email
            WHERE {$cClause}
              AND (tr.outcome = 'emergency_referral' OR tr.level = '1')
              {$cExtra}
        ", $cp);

        $consPending = self::scalar($pdo, "
            SELECT COUNT(*) FROM consultations c
            JOIN users u ON u.id = c.patient_id
            JOIN patient_registrations pr ON pr.email = u.email
            WHERE {$cClause} AND c.status IN ('scheduled','pending')
              {$cExtra}
        ", $cp);

        $consCompleted = self::scalar($pdo, "
            SELECT COUNT(*) FROM consultations c
            JOIN users u ON u.id = c.patient_id
            JOIN patient_registrations pr ON pr.email = u.email
            WHERE {$cClause} AND c.status = 'completed'
              {$cExtra}
        ", $cp);

        $consCancelled = self::scalar($pdo, "
            SELECT COUNT(*) FROM consultations c
            JOIN users u ON u.id = c.patient_id
            JOIN patient_registrations pr ON pr.email = u.email
            WHERE {$cClause} AND c.status = 'cancelled'
              {$cExtra}
        ", $cp);

        $refPending = self::scalar($pdo, "
            SELECT COUNT(*) FROM digital_referrals dr
            JOIN users u ON u.id = dr.patient_id
            JOIN patient_registrations pr ON pr.email = u.email
            WHERE {$cClause} AND dr.status = 'pending'
        ", $cParams);

        $refCompleted = self::scalar($pdo, "
            SELECT COUNT(*) FROM digital_referrals dr
            JOIN users u ON u.id = dr.patient_id
            JOIN patient_registrations pr ON pr.email = u.email
            WHERE {$cClause} AND dr.status IN ('completed','accepted')
        ", $cParams);

        $homeVisits = self::scalar($pdo, "
            SELECT COUNT(*) FROM bhw_home_visits hv
            JOIN users u ON u.id = hv.patient_id
            JOIN patient_registrations pr ON pr.email = u.email
            WHERE {$cClause}
        ", $cParams);

        $overdue = self::scalar($pdo, "
            SELECT COUNT(*) FROM followups fu
            JOIN users u ON u.id = fu.patient_id
            JOIN patient_registrations pr ON pr.email = u.email
            WHERE {$cClause}
              AND fu.status IN ('scheduled','missed') AND fu.followup_date < CURDATE()
        ", $cParams);

        return [
            'total_patients'        => $totalPatients,
            'new_patients_month'    => $newMonth,
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
        self::ensureSchema($pdo);
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

        $ageDist = self::rows($pdo, "
            SELECT
              CASE
                WHEN CAST(pr.age AS UNSIGNED) BETWEEN 0 AND 12 THEN 'Children (0-12)'
                WHEN CAST(pr.age AS UNSIGNED) BETWEEN 13 AND 17 THEN 'Teens (13-17)'
                WHEN CAST(pr.age AS UNSIGNED) BETWEEN 18 AND 59 THEN 'Adults (18-59)'
                ELSE 'Seniors (60+)'
              END AS label,
              COUNT(*) AS value
            FROM patient_registrations pr
            JOIN users u ON u.email = pr.email AND u.role = 'patient'
            WHERE {$pw}
            GROUP BY label
        ", $params);

        $genderDist = self::rows($pdo, "
            SELECT CONCAT(UPPER(LEFT(pr.gender,1)), LOWER(SUBSTRING(pr.gender,2))) AS label, COUNT(*) AS value
            FROM patient_registrations pr
            JOIN users u ON u.email = pr.email AND u.role = 'patient'
            WHERE {$pw} AND pr.gender IS NOT NULL AND TRIM(pr.gender) != ''
            GROUP BY LOWER(TRIM(pr.gender))
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
        self::ensureSchema($pdo);
        $f = self::parseFilters($filters);
        $params = [];
        [$clause, $params] = bhw_patient_sector_clause($pdo, $ctx, 'pr');
        $extra = self::consultDateWhere($f, $params);
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
        self::ensureSchema($pdo);
        $f = self::parseFilters($filters);
        $params = [];
        [$clause, $params] = bhw_patient_sector_clause($pdo, $ctx, 'pr');
        $extra = '';
        if ($f['date_from'] !== '') { $extra .= ' AND tr.assessed_at >= ? '; $params[] = $f['date_from'] . ' 00:00:00'; }
        if ($f['date_to'] !== '') { $extra .= ' AND tr.assessed_at <= ? '; $params[] = $f['date_to'] . ' 23:59:59'; }

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
        self::ensureSchema($pdo);
        $f = self::parseFilters($filters);
        $params = [];
        [$clause, $params] = bhw_patient_sector_clause($pdo, $ctx, 'pr');
        $extra = '';
        if ($f['referral_status'] !== '') {
            $extra .= ' AND dr.status = ? ';
            $params[] = $f['referral_status'];
        }
        if ($f['date_from'] !== '') { $extra .= ' AND dr.created_at >= ? '; $params[] = $f['date_from'] . ' 00:00:00'; }
        if ($f['date_to'] !== '') { $extra .= ' AND dr.created_at <= ? '; $params[] = $f['date_to'] . ' 23:59:59'; }

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
        self::ensureSchema($pdo);
        $f = self::parseFilters($filters);
        $params = [];
        [$clause, $params] = bhw_patient_sector_clause($pdo, $ctx, 'pr');

        $homeVisits = self::scalar($pdo, "
            SELECT COUNT(*) FROM bhw_home_visits hv
            JOIN users u ON u.id = hv.patient_id
            JOIN patient_registrations pr ON pr.email = u.email
            WHERE {$clause}
        ", $params);

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

        $requiring = self::rows($pdo, "
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
            WHERE {$clause}
            GROUP BY hv.visit_type
        ", $params);

        return compact('homeVisits', 'completed', 'pending', 'overdue', 'requiring', 'visitTypes');
    }

    public static function getDiseaseStats(PDO $pdo, array $ctx, array $filters = []): array
    {
        self::ensureSchema($pdo);
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

        $params2 = [];
        [$clause, $params2] = bhw_patient_sector_clause($pdo, $ctx, 'pr');
        $symptoms = self::rows($pdo, "
            SELECT tr.chief_complaint AS label, COUNT(*) AS value
            FROM triage_results tr
            JOIN users u ON u.id = tr.patient_id
            JOIN patient_registrations pr ON pr.email = u.email
            WHERE {$clause}
            GROUP BY tr.chief_complaint ORDER BY value DESC LIMIT 10
        ", $params2);

        $ageGroups = self::rows($pdo, "
            SELECT
              CASE
                WHEN CAST(pr.age AS UNSIGNED) BETWEEN 0 AND 12 THEN 'Children'
                WHEN CAST(pr.age AS UNSIGNED) BETWEEN 13 AND 17 THEN 'Teens'
                WHEN CAST(pr.age AS UNSIGNED) BETWEEN 18 AND 59 THEN 'Adults'
                ELSE 'Seniors'
              END AS label, COUNT(*) AS value
            FROM patient_registrations pr
            JOIN users u ON u.email = pr.email AND u.role = 'patient'
            WHERE {$pw}
            GROUP BY label
        ", $params);

        $diagnoses = self::rows($pdo, "
            SELECT COALESCE(NULLIF(TRIM(c.diagnosis), ''), 'Pending') AS label, COUNT(*) AS value
            FROM consultations c
            JOIN users u ON u.id = c.patient_id
            JOIN patient_registrations pr ON pr.email = u.email
            WHERE {$clause} AND c.diagnosis IS NOT NULL AND TRIM(c.diagnosis) != ''
            GROUP BY label ORDER BY value DESC LIMIT 10
        ", $params2);

        $monthly = self::rows($pdo, "
            SELECT DATE_FORMAT(tr.assessed_at, '%Y-%m') AS label, COUNT(*) AS value
            FROM triage_results tr
            JOIN users u ON u.id = tr.patient_id
            JOIN patient_registrations pr ON pr.email = u.email
            WHERE {$clause}
            GROUP BY label ORDER BY label ASC LIMIT 12
        ", $params2);

        return [
            'top_diseases'    => $conditions,
            'top_symptoms'    => $symptoms,
            'age_groups'      => $ageGroups,
            'diagnoses'       => $diagnoses,
            'monthly_trends'  => $monthly,
        ];
    }

    public static function listPuroks(PDO $pdo, array $ctx): array
    {
        if (!in_array('purok', bhw_pr_columns($pdo), true)) {
            return [];
        }
        $params = [];
        $pw = self::patientWhere($pdo, $ctx, self::parseFilters([]), $params);
        return self::rows($pdo, "
            SELECT DISTINCT TRIM(pr.purok) AS purok FROM patient_registrations pr
            JOIN users u ON u.email = pr.email AND u.role = 'patient'
            WHERE {$pw} AND pr.purok IS NOT NULL AND TRIM(pr.purok) != ''
            ORDER BY purok ASC
        ", $params);
    }

    public static function logExport(PDO $pdo, array $ctx, string $type, string $format, array $filters): void
    {
        self::ensureSchema($pdo);
        $bhwId = (int) ($_SESSION['user_id'] ?? 0);
        try {
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
        } catch (PDOException $e) {
            // non-fatal
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
