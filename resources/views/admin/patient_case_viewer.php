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
require_once BASE_PATH . '/app/includes/auth_guard.php';

if (!defined('MC_PORTAL_SHELL') || MC_PORTAL_SHELL !== 'superadmin') {
    require_once __DIR__ . '/_portal_access.php';
}

$page_title = 'Patient Case Viewer';
$patient_id = (int) ($_GET['patient_id'] ?? 0);
$search = trim($_GET['q'] ?? '');

$patientsQuery = "
    SELECT u.id, u.first_name, u.last_name, u.email, u.is_active
    FROM users u
    WHERE u.role = 'patient'
";
$params = [];
if ($search !== '') {
    $patientsQuery .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)";
    $like = '%' . $search . '%';
    $params = [$like, $like, $like];
}
$patientsQuery .= ' ORDER BY u.last_name, u.first_name LIMIT 200';
$stmt = $pdo->prepare($patientsQuery);
$stmt->execute($params);
$patients = $stmt->fetchAll(PDO::FETCH_ASSOC);

$profile = null;
$consults = $triage = $referrals = $followups = $prescriptions = $location = [];

if ($patient_id > 0) {
    $s = $pdo->prepare("SELECT u.*, pr.* FROM users u LEFT JOIN patient_registrations pr ON pr.email = u.email WHERE u.id = ? AND u.role = 'patient'");
    $s->execute([$patient_id]);
    $profile = $s->fetch(PDO::FETCH_ASSOC);

    if ($profile) {
        $consults = $pdo->prepare('SELECT * FROM consultations WHERE patient_id = ? ORDER BY consult_date DESC');
        $consults->execute([$patient_id]);
        $consults = $consults->fetchAll(PDO::FETCH_ASSOC);

        $triage = $pdo->prepare('SELECT * FROM triage_results WHERE patient_id = ? ORDER BY assessed_at DESC');
        $triage->execute([$patient_id]);
        $triage = $triage->fetchAll(PDO::FETCH_ASSOC);

        $referrals = $pdo->prepare('SELECT * FROM digital_referrals WHERE patient_id = ? ORDER BY created_at DESC');
        $referrals->execute([$patient_id]);
        $referrals = $referrals->fetchAll(PDO::FETCH_ASSOC);

        $followups = $pdo->prepare('SELECT * FROM followups WHERE patient_id = ? ORDER BY followup_date DESC');
        $followups->execute([$patient_id]);
        $followups = $followups->fetchAll(PDO::FETCH_ASSOC);

        $prescriptions = $pdo->prepare('SELECT * FROM prescriptions WHERE patient_id = ? ORDER BY created_at DESC');
        $prescriptions->execute([$patient_id]);
        $prescriptions = $prescriptions->fetchAll(PDO::FETCH_ASSOC);

        try {
            $loc = $pdo->prepare('SELECT * FROM patient_locations WHERE patient_id = ?');
            $loc->execute([$patient_id]);
            $location = $loc->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            $location = [];
        }
    }
}

function case_viewer_initials(string $first, string $last): string
{
    $f = strtoupper(substr(trim($first), 0, 1));
    $l = strtoupper(substr(trim($last), 0, 1));
    return ($f . $l) ?: '?';
}

function case_viewer_query(array $extra = []): string
{
    $params = array_filter(array_merge(['q' => $_GET['q'] ?? null], $extra), static fn($v) => $v !== null && $v !== '');
    return $params ? ('?' . http_build_query($params)) : '';
}

require_once __DIR__ . '/partials/layout_open.php';
?>

<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/admin-staff-applications.css?v=1.1">
<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/admin-case-viewer.css?v=1.0">

<article class="case-viewer-page staff-apps-page">

<header class="staff-apps-hero">
    <div class="staff-apps-hero__content">
        <span class="staff-apps-hero__eyebrow">Administration · Clinical Records</span>
        <h1 class="staff-apps-hero__title">Patient Case Viewer</h1>
        <p class="staff-apps-hero__desc">Read-only administrative view of patient clinical data. No modifications are permitted from this module.</p>
    </div>
</header>

<div class="staff-apps-note" role="note">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
    <span><strong>Read-only access.</strong> This viewer is for monitoring and review purposes only. All patient data changes must be made through authorized clinical workflows.</span>
</div>

<div class="case-viewer-layout">
    <aside class="case-viewer-list-card" aria-label="Patient list">
        <div class="case-viewer-list-card__head">
            <p class="case-viewer-list-card__title">Patients (<?= count($patients) ?>)</p>
            <form method="GET" class="case-viewer-list-card__search">
                <?php if ($patient_id > 0): ?>
                <input type="hidden" name="patient_id" value="<?= $patient_id ?>">
                <?php endif; ?>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="search" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search patients…" aria-label="Search patients">
            </form>
        </div>
        <div class="case-viewer-list">
            <?php if (!$patients): ?>
            <div class="case-viewer-list-empty">No patients found.</div>
            <?php else: ?>
            <?php foreach ($patients as $p):
                $active = $patient_id === (int) $p['id'];
                $href = case_viewer_query(['patient_id' => (int) $p['id']]);
            ?>
            <a href="<?= htmlspecialchars($href) ?>" class="case-viewer-patient<?= $active ? ' is-active' : '' ?>">
                <span class="case-viewer-patient__avatar" aria-hidden="true"><?= htmlspecialchars(case_viewer_initials($p['first_name'], $p['last_name'])) ?></span>
                <span>
                    <div class="case-viewer-patient__name"><?= htmlspecialchars($p['last_name'] . ', ' . $p['first_name']) ?></div>
                    <div class="case-viewer-patient__email"><?= htmlspecialchars($p['email']) ?></div>
                </span>
                <span class="case-viewer-patient__badge<?= empty($p['is_active']) ? ' case-viewer-patient__badge--inactive' : '' ?>"><?= !empty($p['is_active']) ? 'Active' : 'Inactive' ?></span>
            </a>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </aside>

    <div class="case-viewer-detail">
        <?php if (!$profile): ?>
        <div class="case-viewer-empty">
            <div class="case-viewer-empty__icon" aria-hidden="true">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            </div>
            <p class="case-viewer-empty__title">Select a patient</p>
            <p class="case-viewer-empty__text">Choose a patient from the list to view their case file, consultations, triage records, and related clinical data.</p>
        </div>
        <?php else: ?>
        <div class="case-viewer-profile">
            <div class="case-viewer-profile__top">
                <span class="case-viewer-profile__avatar" aria-hidden="true"><?= htmlspecialchars(case_viewer_initials($profile['first_name'] ?? '', $profile['last_name'] ?? '')) ?></span>
                <div>
                    <h2 class="case-viewer-profile__name"><?= htmlspecialchars(trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? ''))) ?></h2>
                    <p class="case-viewer-profile__meta"><?= htmlspecialchars($profile['email'] ?? '') ?></p>
                </div>
                <span class="case-viewer-profile__status<?= empty($profile['is_active']) ? ' case-viewer-profile__status--inactive' : '' ?>"><?= !empty($profile['is_active']) ? 'Active Account' : 'Inactive Account' ?></span>
            </div>
            <dl class="case-viewer-profile__grid">
                <div class="case-viewer-profile__field"><dt>Barangay</dt><dd><?= htmlspecialchars($profile['barangay'] ?? '—') ?></dd></div>
                <div class="case-viewer-profile__field"><dt>Contact</dt><dd><?= htmlspecialchars($profile['contact_number'] ?? '—') ?></dd></div>
                <div class="case-viewer-profile__field"><dt>Date of Birth</dt><dd><?= htmlspecialchars($profile['birthdate'] ?? '—') ?></dd></div>
                <?php if ($location): ?>
                <div class="case-viewer-profile__field"><dt>GIS Coordinates</dt><dd><?= htmlspecialchars(($location['latitude'] ?? '—') . ', ' . ($location['longitude'] ?? '—')) ?></dd></div>
                <div class="case-viewer-profile__field"><dt>Location Barangay</dt><dd><?= htmlspecialchars($location['barangay'] ?? '—') ?></dd></div>
                <?php endif; ?>
            </dl>
            <div class="case-viewer-readonly">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                Read-only view — editing is disabled
            </div>
        </div>

        <?php
        $sections = [
            'Consultations' => [
                'rows' => $consults,
                'render' => static function (array $row): string {
                    $date = htmlspecialchars(($row['consult_date'] ?? '') . ' ' . ($row['consult_time'] ?? ''));
                    $title = htmlspecialchars($row['consult_type'] ?? 'Consultation');
                    $provider = htmlspecialchars($row['provider_name'] ?? 'Unassigned');
                    $status = htmlspecialchars(ucfirst(str_replace('_', ' ', $row['status'] ?? 'pending')));
                    $dx = trim((string) ($row['diagnosis'] ?? ''));
                    $meta = $date . ' · ' . $provider . ' · ' . $status;
                    if ($dx !== '') {
                        $meta .= ' · ' . htmlspecialchars($dx);
                    }
                    return '<p class="case-viewer-record__title">' . $title . '</p><p class="case-viewer-record__meta">' . $meta . '</p>';
                },
            ],
            'Triage Records' => [
                'rows' => $triage,
                'render' => static function (array $row): string {
                    $title = htmlspecialchars($row['urgency_label'] ?? $row['level'] ?? 'Triage');
                    $meta = htmlspecialchars(($row['assessed_at'] ?? '') . ' · ' . ($row['chief_complaint'] ?? $row['symptoms'] ?? ''));
                    return '<p class="case-viewer-record__title">' . $title . '</p><p class="case-viewer-record__meta">' . $meta . '</p>';
                },
            ],
            'Referrals' => [
                'rows' => $referrals,
                'render' => static function (array $row): string {
                    $title = htmlspecialchars($row['facility_name'] ?? $row['reason'] ?? 'Referral');
                    $meta = htmlspecialchars(($row['created_at'] ?? '') . ' · ' . ($row['status'] ?? ''));
                    return '<p class="case-viewer-record__title">' . $title . '</p><p class="case-viewer-record__meta">' . $meta . '</p>';
                },
            ],
            'Follow-Ups' => [
                'rows' => $followups,
                'render' => static function (array $row): string {
                    $title = htmlspecialchars($row['followup_date'] ?? 'Follow-up');
                    $meta = htmlspecialchars(($row['status'] ?? '') . ' · ' . ($row['message'] ?? $row['notes'] ?? ''));
                    return '<p class="case-viewer-record__title">' . $title . '</p><p class="case-viewer-record__meta">' . $meta . '</p>';
                },
            ],
            'Prescriptions' => [
                'rows' => $prescriptions,
                'render' => static function (array $row): string {
                    $title = htmlspecialchars($row['medication_name'] ?? $row['drug_name'] ?? 'Prescription');
                    $meta = htmlspecialchars(($row['created_at'] ?? '') . ' · ' . ($row['dosage'] ?? '') . ' ' . ($row['instructions'] ?? ''));
                    return '<p class="case-viewer-record__title">' . $title . '</p><p class="case-viewer-record__meta">' . $meta . '</p>';
                },
            ],
        ];

        foreach ($sections as $title => $section):
            $rows = $section['rows'];
            $render = $section['render'];
        ?>
        <section class="case-viewer-section">
            <div class="case-viewer-section__head">
                <h3 class="case-viewer-section__title"><?= htmlspecialchars($title) ?></h3>
                <span class="case-viewer-section__count"><?= count($rows) ?></span>
            </div>
            <div class="case-viewer-section__body">
                <?php if (!$rows): ?>
                <div class="case-viewer-section__empty">No records found.</div>
                <?php else: ?>
                <?php foreach (array_slice($rows, 0, 8) as $row): ?>
                <div class="case-viewer-record"><?= $render($row) ?></div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

</article>

<?php require_once __DIR__ . '/partials/layout_close.php'; ?>
