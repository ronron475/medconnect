<?php
/**
 * BHW barangay-scoped access control and SQL helpers.
 *
 * Core rule: logged-in BHW.barangay_id === patient.barangay_id → ALLOW
 * No assigned barangay on BHW → DENY (never city-wide access).
 */
require_once VIEWS_PATH . '/bhw/partials/bhw_context.php';
require_once __DIR__ . '/barangays_bago.php';

function bhw_api_bootstrap(PDO $pdo, bool $requirePost = false): array
{
    require_once __DIR__ . '/../core/Api.php';
    require_once __DIR__ . '/auth_guard.php';
    Api::startJson();
    Api::requireRole('bhw');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if (!auth_csrf_validate($_POST['csrf_token'] ?? '')) {
            Api::error('Invalid CSRF token.', 403);
        }
    }
    if ($requirePost) {
        Api::requirePost();
    }
    patient_registrations_ensure_barangay_id($pdo);
    $ctx = bhw_resolve_context($pdo);
    if (!$ctx['allowed'] || (int) ($ctx['barangay_id'] ?? 0) <= 0) {
        Api::error('BHW sector not assigned. Contact administrator.', 403);
    }
    require_once __DIR__ . '/patient_account_security.php';
    patient_security_ensure_schema($pdo);
    return $ctx;
}

function bhw_audit(PDO $pdo, int $subjectPatientId, string $action, string $description, array $meta = []): void
{
    require_once BASE_PATH . '/app/includes/audit_log.php';
    $bhwId = (int) ($_SESSION['user_id'] ?? 0);
    audit_log($pdo, [
        'patient_id'  => $subjectPatientId > 0 ? $subjectPatientId : $bhwId,
        'action_type' => $action,
        'description' => $description,
        'meta'        => array_merge(['bhw_id' => $bhwId, 'barangay' => $_SESSION['user_barangay_name'] ?? ''], $meta),
    ]);
}

function bhw_pr_columns_reset(): void
{
    bhw_pr_columns(null, true);
}

/**
 * @return list<string>
 */
function bhw_pr_columns(?PDO $pdo = null, bool $refresh = false): array
{
    static $cols = null;
    if ($refresh) {
        $cols = null;
        if ($pdo === null) {
            return [];
        }
    }
    if ($cols !== null) {
        return $cols;
    }
    if ($pdo === null) {
        return [];
    }
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM patient_registrations')->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        $cols = [];
    }
    return $cols;
}

/**
 * Server-side sector filter for patient_registrations.
 *
 * Prefers patient.barangay_id = BHW.barangay_id.
 * Falls back to Step-2 barangay name match only when barangay_id is NULL (legacy rows).
 *
 * @return array{0: string, 1: list<mixed>}
 */
function bhw_patient_sector_clause(PDO $pdo, array $ctx, string $prAlias = 'pr'): array
{
    patient_registrations_ensure_barangay_id($pdo);

    $barangayId = (int) ($ctx['barangay_id'] ?? 0);
    $barangayName = trim((string) ($ctx['barangay_name'] ?? ''));

    // NULL / missing BHW barangay must never mean "all patients".
    if ($barangayId <= 0) {
        return ['1 = 0', []];
    }

    $cols = bhw_pr_columns($pdo);
    $nameExpr = "LOWER(TRIM(CONVERT({$prAlias}.barangay USING utf8mb4))) COLLATE utf8mb4_unicode_ci";
    $nameParam = "LOWER(TRIM(CONVERT(? USING utf8mb4))) COLLATE utf8mb4_unicode_ci";

    if (in_array('barangay_id', $cols, true)) {
        if ($barangayName !== '') {
            return [
                "({$prAlias}.barangay_id = ? OR ({$prAlias}.barangay_id IS NULL AND {$nameExpr} = {$nameParam}))",
                [$barangayId, $barangayName],
            ];
        }
        return ["{$prAlias}.barangay_id = ?", [$barangayId]];
    }

    if ($barangayName === '') {
        return ['1 = 0', []];
    }

    return ["{$nameExpr} = {$nameParam}", [$barangayName]];
}

/**
 * BHW data scope — always the assigned barangay station (no city-wide filter).
 *
 * @return array{0: string, 1: list<mixed>}
 */
function bhw_patient_scope_clause(PDO $pdo, array $ctx, array $filters, string $prAlias = 'pr'): array
{
    unset($filters);

    return bhw_patient_sector_clause($pdo, $ctx, $prAlias);
}

function bhw_list_barangay_options(PDO $pdo): array
{
    return barangays_list_bago_city($pdo);
}

/**
 * Deny unless patient belongs to the logged-in BHW's assigned barangay.
 */
function bhw_assert_patient_in_sector(PDO $pdo, array $ctx, int $patientId): bool
{
    if ($patientId <= 0 || (int) ($ctx['barangay_id'] ?? 0) <= 0) {
        return false;
    }
    [$clause, $params] = bhw_patient_sector_clause($pdo, $ctx, 'pr');
    $sql = "
        SELECT u.id FROM users u
        INNER JOIN patient_registrations pr ON pr.email = u.email
        WHERE u.id = ? AND u.role = 'patient' AND {$clause}
        LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$patientId], $params));
    return (bool) $stmt->fetchColumn();
}

function bhw_patient_account_exists(PDO $pdo, int $patientId): bool
{
    if ($patientId <= 0) {
        return false;
    }
    $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'patient' LIMIT 1");
    $stmt->execute([$patientId]);
    return (bool) $stmt->fetchColumn();
}

/**
 * API gate: 403 when the patient is outside the BHW barangay (or BHW has no barangay).
 */
function bhw_api_require_patient_in_sector(PDO $pdo, array $ctx, int $patientId): void
{
    if ($patientId <= 0) {
        Api::error('Patient is required.', 400);
    }
    if ((int) ($ctx['barangay_id'] ?? 0) <= 0) {
        Api::error('NO PATIENT ACCESS', 403);
    }
    if (!bhw_patient_account_exists($pdo, $patientId)) {
        Api::error('Patient not found.', 404);
    }
    if (!bhw_assert_patient_in_sector($pdo, $ctx, $patientId)) {
        Api::error('ACCESS DENIED', 403);
    }
}

function bhw_notify(PDO $pdo, int $userId, string $type, string $title, string $message, ?string $link = null): void
{
    require_once __DIR__ . '/../core/NotificationManager.php';
    NotificationManager::notify($pdo, $userId, $type, $title, $message, $link);
}

function bhw_sync_gis(PDO $pdo, int $patientId, array $ctx, ?string $address = null): void
{
    require_once __DIR__ . '/../core/GisDashboardService.php';
    $gis = new GisDashboardService($pdo);
    $gis->savePatientLocation(
        $patientId,
        'Negros Occidental',
        'Bago City',
        $ctx['barangay_name'],
        $address ?? ('Brgy. ' . $ctx['barangay_name'] . ', Bago City'),
        null,
        null,
        'barangay_centroid'
    );
}
