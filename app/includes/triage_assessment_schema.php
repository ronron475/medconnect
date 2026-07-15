<?php
/**
 * Ensures extended triage_results columns for AI assessment persistence.
 */

function triage_assessment_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $tableCheck = $pdo->query("SHOW TABLES LIKE 'triage_results'");
    if (!$tableCheck || $tableCheck->rowCount() === 0) {
        return;
    }

    $columns = [
        'confidence_score'        => 'DECIMAL(5,2) NULL',
        'severity'                => 'VARCHAR(20) NULL',
        'triage_level'            => "VARCHAR(20) NULL COMMENT 'GIS severity: non_urgent|urgent|emergency'",
        'triage_classification' => 'VARCHAR(20) NULL',
        'english_complaint'       => 'TEXT NULL',
        'detected_symptoms_json'  => 'JSON NULL',
        'possible_conditions_json'=> 'JSON NULL',
        'recommendations'         => 'TEXT NULL',
        'recommendation_status'   => "VARCHAR(32) NOT NULL DEFAULT 'hidden' COMMENT 'hidden|pending_approval|approved|rejected'",
        'recommendation_approved_by' => 'INT UNSIGNED NULL',
        'recommendation_approved_at' => 'DATETIME NULL',
        'recommendation_patient_ack_at' => 'DATETIME NULL',
        'assessment_payload'      => 'JSON NULL',
        'engine'                  => 'VARCHAR(50) NULL',
        'outcome'                 => "VARCHAR(40) NULL COMMENT 'emergency_referral|consultation_booked|…'",
    ];

    $existing = [];
    $stmt = $pdo->query('SHOW COLUMNS FROM triage_results');
    if ($stmt) {
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $existing[(string) $row['Field']] = true;
        }
    }

    foreach ($columns as $name => $definition) {
        if (isset($existing[$name])) {
            continue;
        }
        $pdo->exec("ALTER TABLE triage_results ADD COLUMN `{$name}` {$definition}");
    }

    triage_assessment_backfill_triage_level($pdo);
    triage_assessment_backfill_recommendation_gates($pdo);

    $done = true;
}

/**
 * Backfill triage_level for legacy rows (one-time data migration, not GIS logic).
 */
function triage_assessment_backfill_triage_level(PDO $pdo): void
{
    static $backfilled = false;
    if ($backfilled) {
        return;
    }

    $tableCheck = $pdo->query("SHOW TABLES LIKE 'triage_results'");
    if (!$tableCheck || $tableCheck->rowCount() === 0) {
        return;
    }

    $colCheck = $pdo->query("SHOW COLUMNS FROM triage_results LIKE 'triage_level'");
    if (!$colCheck || $colCheck->rowCount() === 0) {
        return;
    }

    require_once dirname(__DIR__) . '/core/TriageLevelService.php';

    $stmt = $pdo->query("
        SELECT id, triage_level, triage_classification, level
        FROM triage_results
        WHERE triage_level IS NULL OR TRIM(triage_level) = ''
        LIMIT 2000
    ");
    if (!$stmt) {
        $backfilled = true;
        return;
    }

    $update = $pdo->prepare('UPDATE triage_results SET triage_level = ? WHERE id = ?');
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $level = TriageLevelService::resolve([
            'triage_classification' => $row['triage_classification'] ?? '',
            'level'                 => $row['level'] ?? '',
        ]);
        $update->execute([$level, (int) $row['id']]);
    }

    $backfilled = true;
}

/**
 * True when triage was submitted on a calendar day before today (no longer acceptable).
 */
function triage_case_is_expired(?string $assessedAt): bool
{
    if ($assessedAt === null || trim($assessedAt) === '') {
        return true;
    }

    $tz = defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Asia/Manila';
    try {
        $submitted = new DateTimeImmutable($assessedAt, new DateTimeZone($tz));
        $today     = new DateTimeImmutable('today', new DateTimeZone($tz));

        return $submitted < $today;
    } catch (Exception $e) {
        return true;
    }
}

function triage_case_can_accept(?string $assessedAt, string $status = 'pending'): bool
{
    return $status === 'pending' && !triage_case_is_expired($assessedAt);
}

/**
 * Initial patient-facing recommendation gate after NLP assessment save.
 * No chief complaint => never show NLP remedies to the patient.
 * Non-urgent with remedies => pending provider approval.
 */
function triage_recommendation_status_for_insert(
    string $triageLevel,
    string $chiefComplaint,
    string $recommendationsText,
    string $classification = ''
): string {
    if (trim($chiefComplaint) === '') {
        return 'hidden';
    }
    if (trim($recommendationsText) === '') {
        return 'hidden';
    }

    $level = strtolower(trim($triageLevel));
    $class = strtoupper(trim(str_replace(['-', ' '], '_', $classification)));

    $isNonUrgent = in_array($level, ['non_urgent', 'non-urgent', 'low'], true)
        || in_array($class, ['NON_URGENT', 'NONURGENT'], true);

    return $isNonUrgent ? 'pending_approval' : 'hidden';
}

/**
 * @return list<string>
 */
function triage_recommendations_to_list(?string $text): array
{
    $raw = trim((string) $text);
    if ($raw === '') {
        return [];
    }
    $parts = preg_split('/\r\n|\r|\n/', $raw) ?: [];
    $out = [];
    foreach ($parts as $line) {
        $line = trim((string) $line);
        if ($line !== '') {
            $out[] = $line;
        }
    }
    return array_values(array_unique($out));
}

function triage_recommendations_from_list(array $items): string
{
    $clean = [];
    foreach ($items as $item) {
        $line = trim((string) $item);
        if ($line !== '') {
            $clean[] = $line;
        }
    }
    return implode("\n", array_values(array_unique($clean)));
}

/**
 * Build symptom-specific self-care text for non-urgent cases (CSV-backed).
 *
 * @param list<string|array<string, mixed>> $detectedSymptoms
 */
function triage_build_self_care_recommendations_text(
    string $chiefComplaint,
    string $englishText = '',
    array $detectedSymptoms = [],
    array $possibleConditions = []
): string {
    require_once dirname(__DIR__) . '/core/MedicalRecommendationEngine.php';

    $lines = MedicalRecommendationEngine::buildRecommendations(
        [
            'triage_classification' => 'NON_URGENT',
            'recommended_action' => 'Monitor symptoms and schedule a routine consultation if symptoms persist.',
        ],
        $possibleConditions,
        $chiefComplaint,
        $englishText,
        $detectedSymptoms
    );

    return triage_recommendations_from_list($lines);
}

/**
 * True when stored tips predate the CSV self-care library (generic one-liners).
 */
function triage_recommendations_need_self_care_refresh(?string $text): bool
{
    $raw = trim((string) $text);
    if ($raw === '') {
        return true;
    }
    if (stripos($raw, 'You may follow these tips on your own') !== false) {
        return false;
    }
    if (stripos($raw, 'Self-care focus:') !== false) {
        return false;
    }

    $lines = triage_recommendations_to_list($raw);
    if (count($lines) <= 2) {
        return true;
    }

    return stripos($raw, 'Monitor symptoms and schedule a routine consultation') !== false
        && stripos($raw, 'Rest in a quiet') === false
        && stripos($raw, 'Drink extra fluids') === false;
}

/**
 * Upgrade legacy non-urgent rows to pending_approval and refresh CSV tips when needed.
 */
function triage_assessment_backfill_recommendation_gates(PDO $pdo): void
{
    static $backfilled = false;
    if ($backfilled) {
        return;
    }

    $colCheck = $pdo->query("SHOW COLUMNS FROM triage_results LIKE 'recommendation_status'");
    if (!$colCheck || $colCheck->rowCount() === 0) {
        $backfilled = true;
        return;
    }

    $stmt = $pdo->query("
        SELECT id, chief_complaint, english_complaint, recommendations, detected_symptoms_json,
               possible_conditions_json, recommendation_status, triage_level, triage_classification
        FROM triage_results
        WHERE TRIM(COALESCE(chief_complaint, '')) <> ''
          AND (
                LOWER(COALESCE(triage_level, '')) IN ('non_urgent', 'non-urgent', 'low')
             OR UPPER(REPLACE(REPLACE(COALESCE(triage_classification, ''), '-', '_'), ' ', '_')) IN ('NON_URGENT', 'NONURGENT')
          )
          AND COALESCE(recommendation_status, 'hidden') IN ('hidden', 'pending_approval')
        ORDER BY id DESC
        LIMIT 500
    ");
    if (!$stmt) {
        $backfilled = true;
        return;
    }

    $updateTips = $pdo->prepare('UPDATE triage_results SET recommendations = ? WHERE id = ?');
    $updateStatus = $pdo->prepare("
        UPDATE triage_results
        SET recommendation_status = 'pending_approval'
        WHERE id = ?
          AND recommendation_status = 'hidden'
    ");

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }

        $complaint = trim((string) ($row['chief_complaint'] ?? ''));
        $tips = (string) ($row['recommendations'] ?? '');
        if (triage_recommendations_need_self_care_refresh($tips)) {
            $detected = [];
            $decodedSym = json_decode((string) ($row['detected_symptoms_json'] ?? ''), true);
            if (is_array($decodedSym)) {
                $detected = $decodedSym;
            }
            $conditions = [];
            $decodedCond = json_decode((string) ($row['possible_conditions_json'] ?? ''), true);
            if (is_array($decodedCond)) {
                $conditions = $decodedCond;
            }
            $tips = triage_build_self_care_recommendations_text(
                $complaint,
                trim((string) ($row['english_complaint'] ?? '')),
                $detected,
                $conditions
            );
            if ($tips !== '') {
                $updateTips->execute([$tips, $id]);
            }
        }

        if (trim($tips) !== '' && (string) ($row['recommendation_status'] ?? 'hidden') === 'hidden') {
            $updateStatus->execute([$id]);
        }
    }

    $backfilled = true;
}

/**
 * Resolve a provider id for patient emergency referrals (last consult, else any active provider).
 */
function patient_resolve_provider_id(PDO $pdo, int $patientId): int
{
    $stmt = $pdo->prepare('SELECT provider_id FROM consultations WHERE patient_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$patientId]);
    $id = (int) ($stmt->fetchColumn() ?: 0);
    if ($id > 0) {
        return $id;
    }

    return (int) ($pdo->query("SELECT id FROM users WHERE role = 'provider' AND is_active = 1 ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 0);
}

/**
 * Create a hospital ER referral for emergency triage (patient portal, aligned with BHW).
 */
function patient_create_emergency_hospital_referral(PDO $pdo, int $patientId, int $providerId, string $reason): int
{
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'digital_referrals'");
    if (!$tableCheck || $tableCheck->rowCount() === 0) {
        return 0;
    }

    $destCol = $pdo->query("SHOW COLUMNS FROM digital_referrals LIKE 'facility_name'")->fetch()
        ? 'facility_name'
        : 'destination_facility';

    $pdo->prepare("
        INSERT INTO digital_referrals (patient_id, provider_id, referral_type, reason, {$destCol}, status, created_at)
        VALUES (?, ?, 'Hospital', ?, 'Nearest hospital / ER — emergency triage', 'pending', NOW())
    ")->execute([$patientId, $providerId, $reason]);

    return (int) $pdo->lastInsertId();
}

/**
 * True when a scheduled/pending consult is on a calendar day after today (BHW multi-day booking).
 */
function consultation_is_future_day(?string $consultDate): bool
{
    $date = trim((string) $consultDate);
    if ($date === '') {
        return false;
    }

    require_once __DIR__ . '/appointment_slots.php';
    $today = appointment_now()->format('Y-m-d');

    return $date > $today;
}

function patient_emergency_referral_reason(array $assessment, string $complaint, array $symptoms): string
{
    require_once __DIR__ . '/bhw_clinical.php';

    return bhw_triage_emergency_reason($assessment, $complaint, $symptoms);
}

/**
 * Persist registration complaint / urgency on patient_registrations (schema auto-ensure).
 */
function patient_registration_ensure_complaint_columns(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $tableCheck = $pdo->query("SHOW TABLES LIKE 'patient_registrations'");
    if (!$tableCheck || $tableCheck->rowCount() === 0) {
        return;
    }

    $existing = [];
    $stmt = $pdo->query('SHOW COLUMNS FROM patient_registrations');
    if ($stmt) {
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $existing[(string) $row['Field']] = true;
        }
    }

    $columns = [
        'pending_chief_complaint' => 'TEXT NULL',
        'pending_nlp_json'        => 'MEDIUMTEXT NULL',
        'registration_urgency'    => 'VARCHAR(32) NULL',
    ];
    foreach ($columns as $name => $definition) {
        if (isset($existing[$name])) {
            continue;
        }
        try {
            $pdo->exec("ALTER TABLE patient_registrations ADD COLUMN `{$name}` {$definition}");
        } catch (PDOException $e) { /* non-fatal */ }
    }

    $done = true;
}

function patient_registration_save_complaint(
    PDO $pdo,
    int $registrationId,
    string $complaint,
    string $urgency,
    ?string $nlpJson
): void {
    patient_registration_ensure_complaint_columns($pdo);
    try {
        $pdo->prepare('
            UPDATE patient_registrations
            SET pending_chief_complaint = ?,
                pending_nlp_json = ?,
                registration_urgency = ?
            WHERE id = ?
        ')->execute([
            $complaint !== '' ? $complaint : null,
            ($nlpJson !== null && trim($nlpJson) !== '') ? $nlpJson : null,
            $urgency !== '' ? $urgency : null,
            $registrationId,
        ]);
    } catch (PDOException $e) { /* non-fatal */ }
}

/**
 * Load persisted registration complaint for the patient portal booking form.
 *
 * @return array{complaint:string,urgency:string,nlp_json:string}
 */
function patient_registration_load_pending_complaint(PDO $pdo, int $patientUserId): array
{
    patient_registration_ensure_complaint_columns($pdo);
    $out = ['complaint' => '', 'urgency' => '', 'nlp_json' => ''];
    try {
        $stmt = $pdo->prepare('
            SELECT pending_chief_complaint, pending_nlp_json, registration_urgency
            FROM patient_registrations
            WHERE user_id = ?
            ORDER BY id DESC
            LIMIT 1
        ');
        $stmt->execute([$patientUserId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return $out;
        }
        $out['complaint'] = trim((string) ($row['pending_chief_complaint'] ?? ''));
        $out['urgency'] = strtoupper(trim((string) ($row['registration_urgency'] ?? '')));
        $out['nlp_json'] = trim((string) ($row['pending_nlp_json'] ?? ''));
    } catch (PDOException $e) { /* non-fatal */ }

    return $out;
}

/**
 * Create emergency triage_results + hospital referral (shared by portal book + register).
 *
 * @param list<string> $symptoms
 * @return array{triage_id:int,referral_id:int}
 */
function patient_create_emergency_triage_record(
    PDO $pdo,
    int $patientId,
    string $complaint,
    array $assessment = [],
    array $symptoms = []
): array {
    triage_assessment_ensure_schema($pdo);
    require_once dirname(__DIR__) . '/core/TriageLevelService.php';
    require_once dirname(__DIR__) . '/core/MedicalAssessmentEngine.php';

    if ($assessment === []) {
        $assessment = MedicalAssessmentEngine::assess($complaint, $symptoms);
    }

    $level = '1';
    $label = 'Emergency';
    $triageLevel = TriageLevelService::EMERGENCY;
    $assessment['severity']['severity'] = 'emergency';
    $assessment['triage']['triage_classification'] = 'EMERGENCY';
    $assessment['db_level'] = $level;
    $assessment['urgency_label'] = $label;

    $stmt = $pdo->prepare("
        INSERT INTO triage_results
            (patient_id, symptoms, chief_complaint, level, urgency_label, status, assessed_at,
             confidence_score, severity, triage_level, triage_classification, english_complaint,
             detected_symptoms_json, possible_conditions_json, recommendations,
             assessment_payload, engine)
        VALUES (?, ?, ?, ?, ?, 'completed', NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $patientId,
        json_encode(array_values($symptoms)),
        $complaint,
        $level,
        $label,
        (int) ($assessment['confidence']['score'] ?? 0),
        'emergency',
        $triageLevel,
        'EMERGENCY',
        (string) ($assessment['english_translation'] ?? ''),
        json_encode($assessment['detected_symptoms'] ?? [], JSON_UNESCAPED_UNICODE),
        json_encode($assessment['possible_conditions'] ?? [], JSON_UNESCAPED_UNICODE),
        implode("\n", $assessment['recommendations'] ?? []),
        json_encode($assessment, JSON_UNESCAPED_UNICODE),
        (string) ($assessment['engine'] ?? MedicalAssessmentEngine::VERSION),
    ]);
    $triageId = (int) $pdo->lastInsertId();

    try {
        $pdo->prepare("UPDATE triage_results SET outcome = 'emergency_referral', recommendation_status = 'hidden' WHERE id = ?")
            ->execute([$triageId]);
    } catch (PDOException $e) {
        $pdo->prepare("UPDATE triage_results SET recommendation_status = 'hidden' WHERE id = ?")
            ->execute([$triageId]);
    }

    $providerId = patient_resolve_provider_id($pdo, $patientId);
    $reason = patient_emergency_referral_reason($assessment, $complaint, $symptoms);
    $referralId = 0;
    if ($providerId > 0) {
        $referralId = patient_create_emergency_hospital_referral($pdo, $patientId, $providerId, $reason);
    }

    return [
        'triage_id'   => $triageId,
        'referral_id' => $referralId,
        'provider_id' => $providerId,
        'label'       => $label,
        'assessment'  => $assessment,
    ];
}
