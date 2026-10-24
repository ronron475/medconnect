<?php
if (!defined('BASE_PATH')) {
    $d = __DIR__;
    while ($d !== dirname($d)) {
        if (is_file($d . '/mc_load.php')) {
            require_once $d . '/mc_load.php';
            break;
        }
        $d = dirname($d);
    }
}
require_once BASE_PATH . '/app/includes/patient_portal_bootstrap.php';
require_once BASE_PATH . '/app/includes/patient_health_summary.php';
require_once BASE_PATH . '/app/includes/triage_assessment_schema.php';
require_once BASE_PATH . '/app/includes/provider_clinical_support.php';
require_once BASE_PATH . '/app/includes/patient_consultation_records.php';
require_once VIEWS_PATH . '/patient/partials/triage_helpers.php';

/**
 * Sort key for assessment / consultation rows (newest first).
 *
 * @param array<string, mixed> $row
 */
$phs_row_time = static function (array $row): int {
    return strtotime((string) ($row['assessed_at'] ?? '')) ?: 0;
};

/**
 * Attach the newest consultation linked to a triage row (patient-scoped).
 *
 * @param array<string, mixed> $trRow
 * @return array<string, mixed>
 */
$phs_attach_consult = static function (PDO $pdo, int $patientId, array $trRow): array {
    $triageId = (int) ($trRow['id'] ?? 0);
    $trRow['consultation_id'] = 0;
    $name = trim((string) ($trRow['reviewer_name'] ?? ''));
    if ($name === '') {
        $name = trim((string) ($trRow['assigned_name'] ?? ''));
    }
    $providerId = (int) ($trRow['recommendation_approved_by'] ?? 0);
    if ($providerId <= 0) {
        $providerId = (int) ($trRow['assigned_provider_id'] ?? 0);
    }

    if ($triageId > 0) {
        try {
            $cLookup = $pdo->prepare("
                SELECT c.id, c.provider_id,
                       COALESCE(NULLIF(TRIM(c.provider_name), ''),
                                TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')))) AS provider_display
                FROM consultations c
                LEFT JOIN users u ON u.id = c.provider_id
                WHERE c.patient_id = ? AND c.triage_result_id = ?
                ORDER BY c.id DESC
                LIMIT 1
            ");
            $cLookup->execute([$patientId, $triageId]);
            $cRow = $cLookup->fetch(PDO::FETCH_ASSOC) ?: [];
            $consultId = (int) ($cRow['id'] ?? 0);
            $trRow['consultation_id'] = $consultId;
            if ($consultId > 0) {
                $trRow['finalized_by_name'] = provider_clinical_support_finalized_by_label(
                    $pdo,
                    $consultId,
                    (int) ($cRow['provider_id'] ?? $providerId),
                    (string) ($cRow['provider_display'] ?? $name)
                );
            }
        } catch (Throwable $e) {
            $trRow['consultation_id'] = 0;
        }
    }

    if (empty($trRow['finalized_by_name'])) {
        $trRow['finalized_by_name'] = $name !== ''
            ? patient_provider_display_name($name)
            : ($providerId > 0
                ? provider_clinical_support_finalized_by_label($pdo, 0, $providerId, '')
                : 'Doctor');
    }

    return $trRow;
};

/**
 * Build a history row for one consultation without mutating the source triage.
 * Preserves AI fields (triage_classification / assessment_payload); applies
 * consultation-specific doctor override only onto doctor-facing fields.
 *
 * @param array<string, mixed>|null $triageRow
 * @param array<string, mixed> $cRow
 * @return array<string, mixed>
 */
$phs_history_from_consult = static function (
    PDO $pdo,
    int $patientId,
    ?array $triageRow,
    array $cRow
): array {
    $cid = (int) ($cRow['id'] ?? 0);
    $tid = (int) ($cRow['triage_result_id'] ?? 0);

    $primaryConsultId = is_array($triageRow) ? (int) ($triageRow['consultation_id'] ?? 0) : 0;
    $ownsTriageDoctor = $cid > 0 && $primaryConsultId > 0 && $cid === $primaryConsultId;

    if (is_array($triageRow)) {
        $entry = $triageRow;
        // Shared triage: do not show another visit's doctor final on this consultation.
        // Keep AI columns (triage_classification / assessment_payload) intact.
        if (!$ownsTriageDoctor) {
            $entry['urgency_label'] = '';
            $entry['level'] = '';
            $entry['triage_level'] = '';
            $entry['recommendation_approved_by'] = 0;
            unset($entry['doctor_override'], $entry['manual_urgency'], $entry['finalized_by_name']);
        }
    } else {
        $entry = [
            'id' => $tid > 0 ? $tid : (0 - $cid),
            'chief_complaint' => '',
            'assessed_at' => '',
            'triage_classification' => '',
            'level' => '',
            'urgency_label' => '',
            'triage_level' => '',
            'assessment_payload' => '',
            'outcome' => '',
            'recommendation_status' => '',
            'recommendation_approved_by' => 0,
            'assigned_provider_id' => (int) ($cRow['provider_id'] ?? 0),
            'assessment_status' => '',
        ];
        $aiPack = provider_clinical_support_original_ai($pdo, $cid, $patientId);
        if (!empty($aiPack['row']) && is_array($aiPack['row'])) {
            // AI source fields only — never copy doctor-synced level/urgency as AI.
            foreach (['triage_classification', 'assessment_payload'] as $aiField) {
                if (array_key_exists($aiField, $aiPack['row'])) {
                    $entry[$aiField] = $aiPack['row'][$aiField];
                }
            }
            if (!empty($aiPack['row']['id'])) {
                $entry['id'] = (int) $aiPack['row']['id'];
            }
        }
    }

    $entry['consultation_id'] = $cid;
    $entry['_history_kind'] = 'consultation';

    $cDate = trim((string) ($cRow['consult_date'] ?? ''));
    $cTime = trim((string) ($cRow['consult_time'] ?? ''));
    if ($cDate !== '') {
        $entry['assessed_at'] = $cDate . ($cTime !== '' ? (' ' . $cTime) : ' 00:00:00');
    } elseif (!empty($cRow['created_at'])) {
        $entry['assessed_at'] = (string) $cRow['created_at'];
    }

    if (trim((string) ($entry['chief_complaint'] ?? '')) === '') {
        try {
            $complaint = patient_session_chief_complaint($pdo, $patientId, [
                'id' => $cid,
                'triage_result_id' => $tid,
                'consult_type' => (string) ($cRow['consult_type'] ?? ''),
            ]);
            if ($complaint !== '') {
                $entry['chief_complaint'] = $complaint;
            }
        } catch (Throwable $e) {
            // keep empty — UI shows a generic label
        }
    }

    // Consultation-specific doctor assessment (does not overwrite AI classification columns).
    $ovr = provider_clinical_support_latest_override_row($pdo, $cid);
    if (is_array($ovr)) {
        $bucket = provider_clinical_support_normalize_bucket((string) (
            $ovr['doctor_urgency_bucket'] ?? $ovr['urgency_bucket'] ?? ''
        ));
        if (in_array($bucket, ['emergency', 'urgent', 'non_urgent'], true)) {
            $entry['doctor_override'] = true;
            $entry['manual_urgency'] = $bucket;
            $entry['urgency_label'] = provider_clinical_support_urgency_label($bucket);
            $entry['triage_level'] = $bucket;
            $entry['level'] = $bucket === 'emergency' ? '1' : ($bucket === 'urgent' ? '2' : '3');
        }
    }

    $entry['finalized_by_name'] = provider_clinical_support_finalized_by_label(
        $pdo,
        $cid,
        (int) ($cRow['provider_id'] ?? 0),
        (string) ($cRow['provider_display'] ?? '')
    );

    return $entry;
};

$latest_triage = null;
$assessment_history = [];
$triage_history = []; // [latest] + history — kept for any legacy partials

try {
    triage_assessment_ensure_schema($pdo);
    $patientId = (int) $uid;

    $triageById = [];
    if ($pdo->query("SHOW TABLES LIKE 'triage_results'")->rowCount()) {
        // Keep rows with a complaint or urgency signal. Include cancelled finished
        // assessments — starting a new complaint often marks older rows CANCELLED.
        $s = $pdo->prepare("
            SELECT tr.id, tr.chief_complaint, tr.assessed_at, tr.triage_classification, tr.level, tr.urgency_label,
                   tr.triage_level, tr.assessment_payload, tr.outcome, tr.recommendation_status,
                   tr.recommendation_approved_by, tr.assigned_provider_id, tr.assessment_status,
                   TRIM(CONCAT(COALESCE(rev.first_name, ''), ' ', COALESCE(rev.last_name, ''))) AS reviewer_name,
                   TRIM(CONCAT(COALESCE(asn.first_name, ''), ' ', COALESCE(asn.last_name, ''))) AS assigned_name
            FROM triage_results tr
            LEFT JOIN users rev ON rev.id = tr.recommendation_approved_by
            LEFT JOIN users asn ON asn.id = tr.assigned_provider_id
            WHERE tr.patient_id = ?
              AND (
                TRIM(COALESCE(tr.chief_complaint, '')) <> ''
                OR TRIM(COALESCE(tr.triage_classification, '')) <> ''
                OR TRIM(COALESCE(tr.urgency_label, '')) <> ''
                OR TRIM(COALESCE(tr.level, '')) <> ''
              )
            ORDER BY tr.assessed_at DESC, tr.id DESC
            LIMIT 40
        ");
        $s->execute([$patientId]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $triageById[$id] = $phs_attach_consult($pdo, $patientId, $row);
            }
        }
    }

    // Newest triage case = Current Assessment (actual DB AI + doctor fields).
    $triageSorted = array_values($triageById);
    usort($triageSorted, static function (array $a, array $b) use ($phs_row_time): int {
        $ta = $phs_row_time($a);
        $tb = $phs_row_time($b);
        if ($ta === $tb) {
            return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
        }
        return $tb <=> $ta;
    });
    $latest_triage = $triageSorted[0] ?? null;
    $latestTriageId = (int) ($latest_triage['id'] ?? 0);
    $latestConsultId = (int) ($latest_triage['consultation_id'] ?? 0);

    // All patient consultations (My Health parity).
    $pastConsultRows = [];
    try {
        if ($pdo->query("SHOW TABLES LIKE 'consultations'")->rowCount()) {
            $pastConsultStmt = $pdo->prepare("
                SELECT c.id, c.triage_result_id, c.consult_date, c.consult_time, c.created_at,
                       c.provider_id, c.consult_type,
                       COALESCE(NULLIF(TRIM(c.provider_name), ''),
                                TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')))) AS provider_display
                FROM consultations c
                LEFT JOIN users u ON u.id = c.provider_id
                WHERE c.patient_id = ?
                ORDER BY COALESCE(c.consult_date, DATE(c.created_at)) DESC, c.id DESC
                LIMIT 40
            ");
            $pastConsultStmt->execute([$patientId]);
            $pastConsultRows = $pastConsultStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    } catch (Throwable $e) {
        $pastConsultRows = [];
    }

    // Fetch any consultation-linked triage missing from the filtered list (shared /
    // cancelled edge cases still need accurate AI+doctor values).
    foreach ($pastConsultRows as $cRow) {
        $tid = (int) ($cRow['triage_result_id'] ?? 0);
        if ($tid <= 0 || isset($triageById[$tid])) {
            continue;
        }
        try {
            $fetchTr = $pdo->prepare("
                SELECT tr.id, tr.chief_complaint, tr.assessed_at, tr.triage_classification, tr.level, tr.urgency_label,
                       tr.triage_level, tr.assessment_payload, tr.outcome, tr.recommendation_status,
                       tr.recommendation_approved_by, tr.assigned_provider_id, tr.assessment_status,
                       TRIM(CONCAT(COALESCE(rev.first_name, ''), ' ', COALESCE(rev.last_name, ''))) AS reviewer_name,
                       TRIM(CONCAT(COALESCE(asn.first_name, ''), ' ', COALESCE(asn.last_name, ''))) AS assigned_name
                FROM triage_results tr
                LEFT JOIN users rev ON rev.id = tr.recommendation_approved_by
                LEFT JOIN users asn ON asn.id = tr.assigned_provider_id
                WHERE tr.id = ? AND tr.patient_id = ?
                LIMIT 1
            ");
            $fetchTr->execute([$tid, $patientId]);
            $extraTr = $fetchTr->fetch(PDO::FETCH_ASSOC) ?: null;
            if (is_array($extraTr)) {
                $triageById[$tid] = $phs_attach_consult($pdo, $patientId, $extraTr);
            }
        } catch (Throwable $e) {
            // non-fatal
        }
    }

    $historyByKey = [];

    // 1) Past consultations (including those sharing Latest's triage_result_id).
    foreach ($pastConsultRows as $cRow) {
        $cid = (int) ($cRow['id'] ?? 0);
        if ($cid <= 0 || ($latestConsultId > 0 && $cid === $latestConsultId)) {
            continue;
        }
        $tid = (int) ($cRow['triage_result_id'] ?? 0);
        $src = ($tid > 0 && isset($triageById[$tid])) ? $triageById[$tid] : null;
        $historyByKey['c:' . $cid] = $phs_history_from_consult($pdo, $patientId, $src, $cRow);
    }

    // 2) Other triage cases not already represented by a consultation card.
    foreach ($triageById as $tid => $trRow) {
        $tid = (int) $tid;
        if ($tid <= 0 || ($latestTriageId > 0 && $tid === $latestTriageId)) {
            continue;
        }
        $oc = (int) ($trRow['consultation_id'] ?? 0);
        if ($oc > 0 && isset($historyByKey['c:' . $oc])) {
            continue;
        }
        if ($oc > 0 && $latestConsultId > 0 && $oc === $latestConsultId) {
            // Linked only to the current consult — already shown as Current Assessment.
            continue;
        }
        $row = $trRow;
        $row['_history_kind'] = 'triage';
        $key = $oc > 0 ? ('c:' . $oc) : ('t:' . $tid);
        if (isset($historyByKey[$key])) {
            continue;
        }
        $historyByKey[$key] = $row;
    }

    $assessment_history = array_values($historyByKey);
    usort($assessment_history, static function (array $a, array $b) use ($phs_row_time): int {
        $ta = $phs_row_time($a);
        $tb = $phs_row_time($b);
        if ($ta === $tb) {
            $ca = (int) ($a['consultation_id'] ?? 0);
            $cb = (int) ($b['consultation_id'] ?? 0);
            if ($ca !== $cb) {
                return $cb <=> $ca;
            }
            return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
        }
        return $tb <=> $ta;
    });
    $assessment_history = array_slice($assessment_history, 0, 20);

    $triage_history = is_array($latest_triage)
        ? array_merge([$latest_triage], $assessment_history)
        : $assessment_history;
} catch (Throwable $e) {
    $latest_triage = null;
    $assessment_history = [];
    $triage_history = [];
}

$page_title = 'Health Summary';
$health_css_ver = (int) @filemtime(ASSETS_PATH . '/css/patient-health-summary.css');
$health_js_ver = (int) @filemtime(ASSETS_PATH . '/js/patient-health-summary.js');
$dash_css_ver = (int) @filemtime(ASSETS_PATH . '/css/patient-dashboard.css');
$patient_page_stylesheets = [
    ASSET_BASE . '/assets/css/patient-dashboard.css?v=' . $dash_css_ver,
];
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(language_preferences_html_lang(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<?php require_once VIEWS_PATH . '/patient/partials/layout_head.php'; ?>
</head>
<body class="patient-portal">

<?php require_once VIEWS_PATH . '/patient/partials/layout_shell_open.php'; ?>
<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/patient-health-summary.css?v=<?= $health_css_ver ?>"/>

<div class="patient-page pdash-page patient-health-summary-page"
     id="patientHealthSummaryRoot"
     data-csrf="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>"
     data-api="<?= htmlspecialchars(ASSET_BASE) ?>/app/api/patient">

  <?php require VIEWS_PATH . '/patient/partials/view_health_summary.php'; ?>

</div>

<?php require_once VIEWS_PATH . '/patient/partials/layout_shell_close.php'; ?>

<script>window.APP_BASE = <?= json_encode(ASSET_BASE) ?>;</script>
<script src="<?= ASSET_BASE ?>/assets/js/patient-health-summary.js?v=<?= $health_js_ver ?>"></script>
</body>
</html>
