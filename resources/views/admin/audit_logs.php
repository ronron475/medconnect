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

if (!defined('MC_PORTAL_SHELL') || MC_PORTAL_SHELL !== 'superadmin') {
    require_once __DIR__ . '/_portal_access.php';
} else {
    require_once BASE_PATH . '/app/includes/auth_guard.php';
    auth_require_role(['admin', 'superadmin']);
}

require_once BASE_PATH . '/app/includes/platform_audit_logs.php';

$is_superadmin_portal = (defined('MC_PORTAL_SHELL') && MC_PORTAL_SHELL === 'superadmin');
$page_title = $is_superadmin_portal ? 'Platform Audit Logs' : 'Audit Logs';
$portal_eyebrow = $is_superadmin_portal ? 'Super Administration · Compliance & Security' : 'Administration · Compliance & Security';
$portal_heading = 'Platform Audit Logs';

$search = trim((string) ($_GET['q'] ?? ''));
$actionFilter = trim((string) ($_GET['action'] ?? 'all'));
$roleFilter = trim((string) ($_GET['role'] ?? 'all'));
$limit = max(10, min(500, (int) ($_GET['limit'] ?? 100)));

$result = platform_audit_fetch($pdo, [
    'search' => $search,
    'action' => $actionFilter,
    'role'   => $roleFilter,
    'limit'  => $limit,
    'offset' => 0,
]);

$logs = $result['logs'];
$total = (int) $result['total'];
$stats = $result['stats'];
$actionTypes = $result['action_types'];

$baseUrl = $is_superadmin_portal
    ? ASSET_BASE . '/views/superadmin/user_activity.php'
    : ASSET_BASE . '/views/admin/audit_logs.php';

require_once __DIR__ . '/partials/layout_open.php';
?>

<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/admin-staff-applications.css?v=1.1">
<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/admin-audit-logs.css?v=1.1">

<article class="audit-logs-page staff-apps-page">

<header class="staff-apps-hero">
    <div class="staff-apps-hero__content">
        <span class="staff-apps-hero__eyebrow"><?= htmlspecialchars($portal_eyebrow) ?></span>
        <h1 class="staff-apps-hero__title"><?= htmlspecialchars($portal_heading) ?></h1>
        <p class="staff-apps-hero__desc">
            Track critical platform actions across all user roles — logins, account changes, profile updates, and administrative operations for security monitoring and compliance.
        </p>
    </div>
</header>

<?php if ($is_superadmin_portal): ?>
<div class="audit-logs-banner">
    <span>Looking for failed login attempts and IP blocks?</span>
    <a href="<?= htmlspecialchars(ASSET_BASE . '/views/superadmin/security_logs.php') ?>" class="audit-logs-banner__link">Open Security Logs →</a>
</div>
<?php endif; ?>

<section class="audit-logs-stats" aria-label="Audit statistics">
    <div class="audit-logs-stat audit-logs-stat--today">
        <div class="audit-logs-stat__value"><?= number_format($stats['today']) ?></div>
        <div class="audit-logs-stat__label">Events Today</div>
    </div>
    <div class="audit-logs-stat">
        <div class="audit-logs-stat__value"><?= number_format($stats['week']) ?></div>
        <div class="audit-logs-stat__label">Last 7 Days</div>
    </div>
    <div class="audit-logs-stat">
        <div class="audit-logs-stat__value"><?= number_format($stats['unique_users_today']) ?></div>
        <div class="audit-logs-stat__label">Active Users Today</div>
    </div>
    <div class="audit-logs-stat">
        <div class="audit-logs-stat__value"><?= number_format($stats['total']) ?></div>
        <div class="audit-logs-stat__label">Total Recorded Events</div>
    </div>
</section>

<div class="audit-logs-card">
    <div class="audit-logs-toolbar">
        <form method="get" action="<?= htmlspecialchars($baseUrl) ?>" role="search">
            <div class="audit-logs-search">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
                <input
                    type="search"
                    name="q"
                    class="audit-logs-search__input"
                    placeholder="Search user, action, description, or IP…"
                    value="<?= htmlspecialchars($search) ?>"
                    autocomplete="off"
                >
            </div>

            <select name="action" class="audit-logs-filter" aria-label="Filter by action">
                <option value="all">All actions</option>
                <?php foreach ($actionTypes as $type): ?>
                <option value="<?= htmlspecialchars($type) ?>"<?= $actionFilter === $type ? ' selected' : '' ?>>
                    <?= htmlspecialchars(platform_audit_action_label($type)) ?>
                </option>
                <?php endforeach; ?>
            </select>

            <select name="role" class="audit-logs-filter" aria-label="Filter by role">
                <option value="all">All roles</option>
                <?php
                $roles = ['superadmin', 'admin', 'provider', 'bhw', 'patient'];
                foreach ($roles as $r):
                ?>
                <option value="<?= htmlspecialchars($r) ?>"<?= $roleFilter === $r ? ' selected' : '' ?>>
                    <?= htmlspecialchars(platform_audit_role_label($r)) ?>
                </option>
                <?php endforeach; ?>
            </select>

            <select name="limit" class="audit-logs-filter" aria-label="Results per page">
                <?php foreach ([50, 100, 200, 500] as $n): ?>
                <option value="<?= $n ?>"<?= $limit === $n ? ' selected' : '' ?>><?= $n ?> rows</option>
                <?php endforeach; ?>
            </select>

            <div class="audit-logs-toolbar__actions">
                <span class="audit-logs-count">
                    <?= number_format(count($logs)) ?> of <?= number_format($total) ?> events
                </span>
                <button type="submit" class="mc-btn mc-btn--primary">Apply Filters</button>
                <?php if ($search !== '' || $actionFilter !== 'all' || $roleFilter !== 'all' || $limit !== 100): ?>
                <a href="<?= htmlspecialchars($baseUrl) ?>" class="mc-btn mc-btn--outline">Reset</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <?php if (empty($logs)): ?>
    <div class="audit-logs-empty">
        <div class="audit-logs-empty__icon" aria-hidden="true">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
        </div>
        <h2 class="audit-logs-empty__title">No audit events found</h2>
        <p class="audit-logs-empty__text">
            <?php if ($search !== '' || $actionFilter !== 'all' || $roleFilter !== 'all'): ?>
                Try adjusting your search or filters to see more results.
            <?php else: ?>
                System activities will appear here as users interact with the platform.
            <?php endif; ?>
        </p>
    </div>
    <?php else: ?>
    <div class="audit-logs-table-wrap">
        <table class="audit-logs-table">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Action</th>
                    <th>Description</th>
                    <th>IP Address</th>
                    <th>Timestamp</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $i => $log):
                    $role = (string) ($log['role'] ?? '');
                    $roleClass = preg_match('/^[a-z]+$/', $role) ? ' audit-logs-role--' . $role : '';
                    $tone = (string) ($log['action_tone'] ?? 'default');
                    $ts = strtotime($log['created_at'] ?? '');
                ?>
                <tr>
                    <td class="audit-logs-td--user" data-label="User">
                        <div class="audit-logs-user">
                            <span class="audit-logs-avatar" aria-hidden="true"><?= htmlspecialchars($log['initials'] ?? '?') ?></span>
                            <div>
                                <div class="audit-logs-user__name"><?= htmlspecialchars($log['display_name'] ?: 'Unknown User') ?></div>
                                <?php if (!empty($log['email'])): ?>
                                <div class="audit-logs-user__email"><?= htmlspecialchars($log['email']) ?></div>
                                <?php endif; ?>
                                <span class="audit-logs-role<?= htmlspecialchars($roleClass) ?>"><?= htmlspecialchars(platform_audit_role_label($role)) ?></span>
                            </div>
                        </div>
                    </td>
                    <td data-label="Action">
                        <span class="audit-logs-action audit-logs-action--<?= htmlspecialchars($tone) ?>">
                            <?= htmlspecialchars($log['action_label'] ?? '') ?>
                        </span>
                    </td>
                    <td data-label="Description">
                        <div class="audit-logs-desc"><?= htmlspecialchars($log['description'] ?? '') ?></div>
                        <?php
                        $auditPayload = [
                            'action_label' => $log['action_label'] ?? '',
                            'action_tone'  => $tone,
                            'user_name'    => $log['display_name'] ?: 'Unknown User',
                            'user_email'   => $log['email'] ?? '',
                            'user_role'    => platform_audit_role_label($role),
                            'role_key'     => $role,
                            'initials'     => $log['initials'] ?? '?',
                            'description'  => $log['description'] ?? '',
                            'meta'         => $log['meta_pretty'] ?? null,
                            'ip_address'   => $log['ip_address'] ?? '—',
                            'user_agent'   => $log['user_agent'] ?? '',
                            'created_date' => $ts ? date('M j, Y', $ts) : '—',
                            'created_time' => $ts ? date('g:i:s A', $ts) : '',
                        ];
                        ?>
                        <button
                            type="button"
                            class="audit-logs-meta-toggle js-audit-detail-open"
                            data-audit="<?= htmlspecialchars(json_encode($auditPayload, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>"
                        >View details</button>
                    </td>
                    <td data-label="IP Address">
                        <span class="audit-logs-ip"><?= htmlspecialchars($log['ip_address'] ?? '—') ?></span>
                    </td>
                    <td data-label="Timestamp">
                        <div class="audit-logs-time__date"><?= $ts ? date('M j, Y', $ts) : '—' ?></div>
                        <div class="audit-logs-time__clock"><?= $ts ? date('g:i:s A', $ts) : '' ?></div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

</article>

<div id="auditLogDetailModal" class="audit-log-modal" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="auditLogDetailTitle">
    <div class="audit-log-modal__dialog">
        <div class="audit-log-modal__header">
            <div class="audit-log-modal__icon" aria-hidden="true">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
            </div>
            <div class="audit-log-modal__heading">
                <span class="audit-log-modal__eyebrow">Event Details</span>
                <h2 class="audit-log-modal__title" id="auditLogDetailTitle">Audit Log Entry</h2>
                <p class="audit-log-modal__sub" id="auditLogDetailTime"></p>
            </div>
            <button type="button" class="audit-log-modal__close js-audit-detail-close" aria-label="Close">&times;</button>
        </div>
        <div class="audit-log-modal__body">
            <div class="audit-log-modal__user" id="auditLogDetailUser"></div>
            <div class="audit-log-modal__section">
                <h3 class="audit-log-modal__label">Action</h3>
                <div id="auditLogDetailAction"></div>
            </div>
            <div class="audit-log-modal__section">
                <h3 class="audit-log-modal__label">Description</h3>
                <p class="audit-log-modal__desc" id="auditLogDetailDesc"></p>
            </div>
            <div class="audit-log-modal__section" id="auditLogDetailMetaWrap" hidden>
                <h3 class="audit-log-modal__label">Additional Metadata</h3>
                <pre class="audit-log-modal__meta" id="auditLogDetailMeta"></pre>
            </div>
            <div class="audit-log-modal__grid">
                <div class="audit-log-modal__section">
                    <h3 class="audit-log-modal__label">IP Address</h3>
                    <p class="audit-log-modal__mono" id="auditLogDetailIp"></p>
                </div>
                <div class="audit-log-modal__section" id="auditLogDetailAgentWrap" hidden>
                    <h3 class="audit-log-modal__label">User Agent</h3>
                    <p class="audit-log-modal__mono audit-log-modal__mono--wrap" id="auditLogDetailAgent"></p>
                </div>
            </div>
        </div>
        <div class="audit-log-modal__footer">
            <button type="button" class="mc-btn mc-btn--outline js-audit-detail-close">Close</button>
        </div>
    </div>
</div>

<script src="<?= ASSET_BASE ?>/assets/js/admin-audit-logs.js?v=1.1"></script>

<?php require_once __DIR__ . '/partials/layout_close.php'; ?>
