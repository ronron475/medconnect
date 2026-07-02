<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
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

$stmt = $pdo->prepare("
    SELECT
        u.id                                        AS user_id,
        u.first_name,
        u.last_name,
        u.email,
        u.profile_picture,
        COALESCE(p.contact_number, '')              AS contact_number,
        COALESCE(p.age, '')                         AS age,
        COALESCE(p.gender, '')                      AS gender,
        COALESCE(p.date_of_birth, '')               AS date_of_birth,
        COALESCE(p.blood_type, '')                  AS blood_type,
        COALESCE(p.philhealth_status, '')           AS philhealth_status,
        COALESCE(p.region, '')                      AS region,
        COALESCE(p.province, '')                    AS province,
        COALESCE(p.city_municipality, '')           AS city_municipality,
        COALESCE(p.barangay, '')                    AS barangay,
        COALESCE(p.status, 'pending')               AS reg_status,
        COALESCE(p.emergency_contact_name, '')      AS emergency_contact_name,
        COALESCE(p.emergency_contact_phone, '')     AS emergency_contact_phone,
        COALESCE(p.emergency_contact_relation, '')  AS emergency_contact_relation,
        CONCAT('MC-', LPAD(u.id, 6, '0'))           AS patient_number
    FROM users u
    LEFT JOIN patient_registrations p ON p.email = u.email
    WHERE u.id = ?
    LIMIT 1
");
$stmt->execute([$uid]);
$pt = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$patient_initials = profile_picture_initials($pt['first_name'] ?? '', $pt['last_name'] ?? '');
$patient_picture_url = profile_picture_public_url($pt['profile_picture'] ?? $_SESSION['profile_picture'] ?? null);
$pt['full_address'] = implode(', ', array_filter([
    $pt['barangay'] ?? '',
    $pt['city_municipality'] ?? '',
    $pt['province'] ?? '',
]));

$page_title = 'My Identity';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require_once VIEWS_PATH . '/patient/partials/layout_head.php'; ?>
</head>
<body class="patient-portal">

  <?php require_once VIEWS_PATH . '/patient/partials/layout_shell_open.php'; ?>

    <div class="patient-page">
      <?php require VIEWS_PATH . '/patient/partials/view_profile.php'; ?>
    </div>

  <?php require_once VIEWS_PATH . '/patient/partials/layout_shell_close.php'; ?>

  <script>window.APP_BASE = <?= json_encode(ASSET_BASE) ?>;</script>
  <script src="<?= ASSET_BASE ?>/assets/js/patient-portal.js?v=<?= $patient_portal_ver ?>"></script>
</body>
</html>
