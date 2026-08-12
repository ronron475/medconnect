<?php
/**
 * Patient-visible consultation records — one consultation row, finalized SOAP only.
 */

function patient_consultation_records_schema_ensure(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    try {
        $cols = $pdo->query('SHOW COLUMNS FROM consultations')->fetchAll(PDO::FETCH_COLUMN);
        if (is_array($cols) && !in_array('completed_at', $cols, true)) {
            $pdo->exec('ALTER TABLE consultations ADD COLUMN completed_at DATETIME NULL DEFAULT NULL AFTER status');
        }
    } catch (PDOException $e) { /* non-fatal */ }

    try {
        $cols = $pdo->query('SHOW COLUMNS FROM clinical_notes')->fetchAll(PDO::FETCH_COLUMN);
        if (is_array($cols) && !in_array('finalized_at', $cols, true)) {
            $pdo->exec('ALTER TABLE clinical_notes ADD COLUMN finalized_at DATETIME NULL DEFAULT NULL AFTER signature_data');
        }
    } catch (PDOException $e) { /* non-fatal */ }

    $done = true;
}

function patient_consultation_is_finalized(string $consultationStatus, ?string $signatureData): bool
{
    return strtolower(trim($consultationStatus)) === 'completed'
        && trim((string) $signatureData) !== '';
}

function patient_consultation_record_visible_sql(string $consultAlias = 'c', string $noteAlias = 'cn'): string
{
    return "{$consultAlias}.status = 'completed'
        AND {$noteAlias}.signature_data IS NOT NULL
        AND TRIM({$noteAlias}.signature_data) <> ''";
}

function patient_consultation_detail_url(int $consultationId): string
{
    $base = defined('ASSET_BASE') ? (string) ASSET_BASE : '';
    return $base . '/views/patient/consultation_detail.php?id=' . $consultationId;
}

/** Patient My Health → Health Files tab (optional consultation anchor). */
function patient_health_files_url(?int $consultationId = null): string
{
    $base = defined('ASSET_BASE') ? (string) ASSET_BASE : '';
    $url = $base . '/views/patient/my_health.php?tab=files';
    if ($consultationId !== null && $consultationId > 0) {
        $url .= '#health-file-' . $consultationId;
    }
    return $url;
}

/**
 * Display label for doctor final case level (NON-URGENT / URGENT / EMERGENCY).
 */
function patient_case_level_label(string $bucket): string
{
    require_once __DIR__ . '/provider_clinical_support.php';
    $normalized = provider_clinical_support_normalize_bucket($bucket);
    return match ($normalized) {
        'emergency' => 'EMERGENCY',
        'urgent' => 'URGENT',
        'non_urgent' => 'NON-URGENT',
        default => '',
    };
}

function patient_case_level_chip_class(string $bucket): string
{
    require_once __DIR__ . '/provider_clinical_support.php';
    $normalized = provider_clinical_support_normalize_bucket($bucket);
    return match ($normalized) {
        'emergency' => 'pmh-case-level--emergency',
        'urgent' => 'pmh-case-level--urgent',
        'non_urgent' => 'pmh-case-level--non-urgent',
        default => 'pmh-case-level--unknown',
    };
}

/**
 * Doctor final case classification for a patient-owned consultation.
 * Reads the SAME consultation_clinical_support row keyed by consultation_id.
 *
 * @return array<string, mixed>|null
 */
function patient_consultation_clinical_outcome(
    PDO $pdo,
    int $consultationId,
    int $patientId,
    bool $requireFinalized = true
): ?array {
    if ($consultationId <= 0 || $patientId <= 0) {
        return null;
    }

    require_once __DIR__ . '/provider_clinical_support.php';
    provider_clinical_support_ensure_schema($pdo);

    $cStmt = $pdo->prepare('SELECT status, patient_id, provider_id FROM consultations WHERE id = ? AND patient_id = ? LIMIT 1');
    $cStmt->execute([$consultationId, $patientId]);
    $consult = $cStmt->fetch(PDO::FETCH_ASSOC);
    if (!$consult) {
        return null;
    }

    if ($requireFinalized) {
        $nStmt = $pdo->prepare('SELECT signature_data FROM clinical_notes WHERE consultation_id = ? AND patient_id = ? LIMIT 1');
        $nStmt->execute([$consultationId, $patientId]);
        $note = $nStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!patient_consultation_is_finalized((string) ($consult['status'] ?? ''), $note['signature_data'] ?? '')) {
            return null;
        }
    }

    $support = provider_consultation_clinical_support($pdo, $consultationId, $patientId);
    if (empty($support['available'])) {
        return null;
    }

    $bucket = provider_clinical_support_normalize_bucket((string) ($support['risk_bucket'] ?? 'unknown'));
    if ($bucket === 'unknown') {
        return null;
    }

    $aiBucket = provider_clinical_support_normalize_bucket((string) ($support['ai_urgency_bucket'] ?? ''));
    $finalLabel = trim((string) ($support['final_urgency'] ?? ''));
    if ($finalLabel === '') {
        $finalLabel = provider_clinical_support_urgency_label($bucket);
    }

    return [
        'consultation_id' => $consultationId,
        'patient_id' => $patientId,
        'provider_id' => (int) ($consult['provider_id'] ?? 0),
        'final_case_bucket' => $bucket,
        'final_case_level' => patient_case_level_label($bucket),
        'final_case_display' => $finalLabel,
        'ai_case_bucket' => $aiBucket !== 'unknown' ? $aiBucket : '',
        'ai_case_level' => $aiBucket !== 'unknown' ? patient_case_level_label($aiBucket) : '',
        'ai_case_display' => trim((string) ($support['ai_urgency'] ?? '')),
        'is_doctor_override' => !empty($support['manual_urgency']),
        'recommended_actions' => is_array($support['recommended_actions'] ?? null) ? $support['recommended_actions'] : [],
        'emergency_warning_signs' => is_array($support['emergency_warning_signs'] ?? null) ? $support['emergency_warning_signs'] : [],
    ];
}

function patient_provider_display_name(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return 'Healthcare Provider';
    }
    if (preg_match('/^dr\.?\s+/i', $name)) {
        return $name;
    }

    return 'Dr. ' . $name;
}

/**
 * Real video-call duration from video_sessions.started_at / ended_at.
 * Empty string when either timestamp is missing (do not invent a duration).
 */
function patient_format_call_duration(?string $startedAt, ?string $endedAt): string
{
    $start = $startedAt !== null && $startedAt !== '' ? strtotime($startedAt) : false;
    $end = $endedAt !== null && $endedAt !== '' ? strtotime($endedAt) : false;
    if ($start === false || $end === false || $end < $start) {
        return '';
    }

    $sec = (int) ($end - $start);
    $hours = intdiv($sec, 3600);
    $minutes = intdiv($sec % 3600, 60);
    $seconds = $sec % 60;

    $parts = [];
    if ($hours > 0) {
        $parts[] = $hours . ' hr';
    }
    if ($minutes > 0 || $hours > 0) {
        $parts[] = $minutes . ' min';
    }
    $parts[] = $seconds . ' sec';

    return implode(' ', $parts);
}

/**
 * Patient-visible chief complaint for a consultation (existing complaint text only).
 */
function patient_session_chief_complaint(PDO $pdo, int $patientId, array $consult): string
{
    $consultationId = (int) ($consult['id'] ?? 0);
    if ($patientId <= 0 || $consultationId <= 0) {
        return '';
    }

    require_once __DIR__ . '/patient_chief_complaints.php';
    $pcc = patient_chief_complaint_for_consultation($pdo, $consultationId);
    $fromPcc = trim((string) ($pcc['complaint'] ?? ''));
    if ($fromPcc !== '') {
        return $fromPcc;
    }

    $triageId = (int) ($consult['triage_result_id'] ?? 0);
    if ($triageId > 0) {
        try {
            $stmt = $pdo->prepare('
                SELECT chief_complaint
                FROM triage_results
                WHERE id = ? AND patient_id = ?
                LIMIT 1
            ');
            $stmt->execute([$triageId, $patientId]);
            $fromTriage = trim((string) ($stmt->fetchColumn() ?: ''));
            if ($fromTriage !== '') {
                return $fromTriage;
            }
        } catch (Throwable $e) {
            // optional link
        }
    }

    $consultType = trim((string) ($consult['consult_type'] ?? ''));
    if ($consultType !== '' && strcasecmp($consultType, 'General Consultation') !== 0) {
        return $consultType;
    }

    return '';
}
