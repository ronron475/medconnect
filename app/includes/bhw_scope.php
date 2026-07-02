<?php
/**
 * BHW barangay-scoped access control and SQL helpers.
 */
require_once VIEWS_PATH . '/bhw/partials/bhw_context.php';

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
    $ctx = bhw_resolve_context($pdo);
    if (!$ctx['allowed']) {
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

function bhw_pr_columns(PDO $pdo): array
{
    static $cols = null;
    if ($cols !== null) {
        return $cols;
    }
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM patient_registrations')->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        $cols = [];
    }
    return $cols;
}

function bhw_patient_sector_clause(PDO $pdo, array $ctx, string $prAlias = 'pr'): array
{
    $cols = bhw_pr_columns($pdo);
    if (in_array('barangay_id', $cols, true) && !empty($ctx['barangay_id'])) {
        return ["{$prAlias}.barangay_id = ?", [(int) $ctx['barangay_id']]];
    }
    return ["LOWER(TRIM({$prAlias}.barangay)) = LOWER(?)", [$ctx['barangay_name']]];
}

function bhw_assert_patient_in_sector(PDO $pdo, array $ctx, int $patientId): bool
{
    if ($patientId <= 0) {
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

function bhw_notify(PDO $pdo, int $userId, string $type, string $title, string $message, ?string $link = null): void
{
    require_once __DIR__ . '/../core/NotificationManager.php';
    NotificationManager::notify($pdo, $userId, $type, $title, $message, $link);
}

function bhw_sync_gis(PDO $pdo, int $patientId, array $ctx, ?string $address = null): void
{
    require_once __DIR__ . '/../core/GisDashboardService.php';
    require_once __DIR__ . '/../core/BagoBarangayCentroids.php';
    $gis = new GisDashboardService($pdo);
    $gis->ensureSchema();
    $centroid = BagoBarangayCentroids::resolve($ctx['barangay_name'] ?? '');
    $lat = $centroid['lat'] ?? null;
    $lng = $centroid['lng'] ?? null;
    $gis->savePatientLocation(
        $patientId,
        'Negros Occidental',
        'Bago City',
        $ctx['barangay_name'],
        $address ?? ('Brgy. ' . $ctx['barangay_name'] . ', Bago City'),
        $lat,
        $lng,
        ($lat !== null && $lng !== null) ? 'barangay_centroid' : 'manual'
    );
}
