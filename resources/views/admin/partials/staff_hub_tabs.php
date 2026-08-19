<?php
/**
 * Tab navigation for Doctor / BHW Management hub pages.
 *
 * Expects: $hub_kind ('doctor'|'bhw'), $hub_tab (string), $hub_base (filename)
 */
declare(strict_types=1);

$hub_kind = $hub_kind ?? 'doctor';
$hub_tab = $hub_tab ?? 'all';
$hub_base = $hub_base ?? 'doctor_applications.php';

if ($hub_kind === 'doctor') {
    $hub_tabs = [
        'all'           => 'All',
        'applications'  => 'Applications',
        'pending'       => 'Pending Approval',
        'active'        => 'Active',
        'rejected'      => 'Rejected',
        'archived'      => 'Archived',
    ];
    if (!empty($hub_show_queue_tab)) {
        $hub_tabs['queue'] = 'Queue Monitoring';
    }
} else {
    $hub_tabs = [
        'all'      => 'All',
        'drafts'   => 'Drafts',
        'pending'  => 'Pending Approval',
        'active'   => 'Active',
        'rejected' => 'Rejected',
        'archived' => 'Archived',
    ];
}

if (!isset($hub_views_base)) {
    require_once BASE_PATH . '/app/includes/portal_paths.php';
    $hub_views_base = portal_views_base();
}
?>
<nav class="staff-mgmt-tabs" aria-label="<?= $hub_kind === 'doctor' ? 'Doctor' : 'BHW' ?> management views">
    <?php foreach ($hub_tabs as $tabKey => $tabLabel):
        $isTabActive = ($hub_tab === $tabKey);
        $href = $hub_views_base . '/' . $hub_base . ($tabKey === 'all' ? '' : '?tab=' . urlencode($tabKey));
    ?>
    <a href="<?= htmlspecialchars($href) ?>"
       class="staff-mgmt-tabs__item<?= $isTabActive ? ' is-active' : '' ?>"
       <?= $isTabActive ? 'aria-current="page"' : '' ?>>
        <?= htmlspecialchars($tabLabel) ?>
    </a>
    <?php endforeach; ?>
</nav>
