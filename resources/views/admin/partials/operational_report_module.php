<?php
/**
 * One operational report: title, description, CSV download, live table.
 *
 * @var array{type:string,title:string,description:string,headers:list<string>,rows:list<list<string>>,total:int,page:int,per_page:int,error:?string,demographics?:array} $reportModule
 * @var array<string, int> $reportPages
 * @var array<string, int|string> $reportExtraParams
 * @var string $reportBasePath
 * @var string $reportExportBase
 */
$reportType = (string) ($reportModule['type'] ?? '');
$reportHeaders = $reportModule['headers'] ?? [];
$reportRows = $reportModule['rows'] ?? [];
$reportTotal = (int) ($reportModule['total'] ?? 0);
$reportPage = (int) ($reportModule['page'] ?? 1);
$reportPerPage = max(1, (int) ($reportModule['per_page'] ?? 25));
$reportError = (string) ($reportModule['error'] ?? '');
$rangeStart = $reportTotal > 0 ? (($reportPage - 1) * $reportPerPage) + 1 : 0;
$rangeEnd = $reportTotal > 0 ? min($reportTotal, $rangeStart + count($reportRows) - 1) : 0;
$reportPages = $reportPages ?? [];
$reportExtraParams = $reportExtraParams ?? [];
$prevHref = $reportPage > 1
    ? admin_operational_report_page_href($reportBasePath, $reportType, $reportPage - 1, $reportPages, $reportExtraParams)
    : '';
$nextHref = ($rangeEnd < $reportTotal)
    ? admin_operational_report_page_href($reportBasePath, $reportType, $reportPage + 1, $reportPages, $reportExtraParams)
    : '';
$reportDemo = isset($reportModule['demographics']) && is_array($reportModule['demographics'])
    ? $reportModule['demographics']
    : null;
$reportConsult = isset($reportModule['consultation']) && is_array($reportModule['consultation'])
    ? $reportModule['consultation']
    : null;
$reportExportHref = $reportExportBase . '?export=csv&type=' . rawurlencode($reportType);
if ($reportDemo !== null && !empty($reportDemo['barangay']['id'])) {
    $reportExportHref .= '&barangay_id=' . (int) $reportDemo['barangay']['id'];
} elseif ($reportConsult !== null) {
    $exportParams = array_merge(
        ['export' => 'csv', 'type' => $reportType],
        admin_consultation_report_query_params($reportConsult['filters'] ?? [])
    );
    $reportExportHref = $reportExportBase . '?' . http_build_query($exportParams);
}
$reportResetParams = [];
foreach ($reportExtraParams as $extraKey => $extraValue) {
    if (!str_starts_with((string) $extraKey, 'appt_')) {
        $reportResetParams[$extraKey] = $extraValue;
    }
}
foreach ($reportPages as $pageKey => $pageValue) {
    if ($pageKey !== $reportType && (int) $pageValue > 1) {
        $reportResetParams[$pageKey . '_page'] = (int) $pageValue;
    }
}
$reportResetHref = $reportBasePath . ($reportResetParams !== [] ? '?' . http_build_query($reportResetParams) : '');
$reportResetHref .= '#report-' . rawurlencode($reportType);
?>
<section class="mc-card" id="report-<?= htmlspecialchars($reportType) ?>" style="padding: 14px 16px; margin-bottom: 10px;">
    <div style="font-weight: 800; color: var(--mc-navy-dark); margin-bottom: 6px;"><?= htmlspecialchars((string) ($reportModule['title'] ?? '')) ?></div>
    <p class="text-xs text-muted" style="margin-bottom: 10px;"><?= htmlspecialchars((string) ($reportModule['description'] ?? '')) ?></p>

    <?php if ($reportConsult !== null): ?>
        <?php
        $consultFilters = is_array($reportConsult['filters'] ?? null) ? $reportConsult['filters'] : [];
        $consultPresets = is_array($reportConsult['presets'] ?? null) ? $reportConsult['presets'] : [];
        $consultStatuses = is_array($reportConsult['statuses'] ?? null) ? $reportConsult['statuses'] : [];
        $consultProviders = is_array($reportConsult['providers'] ?? null) ? $reportConsult['providers'] : [];
        $consultBhws = $reportConsult['bhws'] ?? null;
        $consultSlots = $reportConsult['slot_statuses'] ?? null;
        ?>
        <style>
            .op-appt { display: flex; flex-direction: column; gap: 10px; margin-bottom: 10px; }
            .op-appt__grid { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 10px; }
            .op-appt__field { display: flex; flex-direction: column; gap: 4px; min-width: 150px; }
            .op-appt__field--search { min-width: 220px; flex: 1 1 220px; }
            .op-appt__label { font-size: var(--mc-fs-xs); font-weight: 700; color: var(--text-secondary, var(--mc-slate-muted)); text-transform: uppercase; letter-spacing: .04em; }
            .op-appt__control { height: 38px; padding: 0 10px; border-radius: var(--mc-radius-sm); border: 1px solid var(--border-color, var(--mc-border-thin)); background: var(--card-bg, var(--mc-white)); color: var(--text-primary, var(--mc-navy-dark)); font: inherit; font-size: var(--mc-fs-sm); width: 100%; }
            .op-appt__actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        </style>
        <form class="op-appt" method="get" action="<?= htmlspecialchars($reportBasePath . '#report-' . rawurlencode($reportType)) ?>">
            <?php foreach ($reportPages as $pageKey => $pageValue): ?>
                <?php if ($pageKey !== $reportType && (int) $pageValue > 1): ?>
                    <input type="hidden" name="<?= htmlspecialchars($pageKey . '_page') ?>" value="<?= (int) $pageValue ?>">
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if (!empty($reportExtraParams['users_barangay'])): ?>
                <input type="hidden" name="users_barangay" value="<?= (int) $reportExtraParams['users_barangay'] ?>">
            <?php endif; ?>
            <div class="op-appt__grid">
                <div class="op-appt__field">
                    <label class="op-appt__label" for="appt_period">Date</label>
                    <select class="op-appt__control" id="appt_period" name="appt_period">
                        <?php foreach ($consultPresets as $presetValue => $presetLabel): ?>
                            <option value="<?= htmlspecialchars((string) $presetValue) ?>"<?= (string) ($consultFilters['period'] ?? 'all') === (string) $presetValue ? ' selected' : '' ?>><?= htmlspecialchars((string) $presetLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="op-appt__field">
                    <label class="op-appt__label" for="appt_from">Custom from</label>
                    <input class="op-appt__control" type="date" id="appt_from" name="appt_from" value="<?= htmlspecialchars((string) ($consultFilters['from_input'] ?? '')) ?>" title="Inclusive start date. Used when Date is Custom range.">
                </div>
                <div class="op-appt__field">
                    <label class="op-appt__label" for="appt_to">Custom to</label>
                    <input class="op-appt__control" type="date" id="appt_to" name="appt_to" value="<?= htmlspecialchars((string) ($consultFilters['to_input'] ?? '')) ?>" title="Inclusive end date. Used when Date is Custom range.">
                </div>
                <div class="op-appt__field op-appt__field--search">
                    <label class="op-appt__label" for="appt_q">Patient</label>
                    <input class="op-appt__control" type="search" id="appt_q" name="appt_q" maxlength="80" value="<?= htmlspecialchars((string) ($consultFilters['q'] ?? '')) ?>" placeholder="Name, MC- code, or ID">
                </div>
                <div class="op-appt__field">
                    <label class="op-appt__label" for="appt_provider">Healthcare provider</label>
                    <select class="op-appt__control" id="appt_provider" name="appt_provider">
                        <option value="">All providers</option>
                        <?php foreach ($consultProviders as $person): ?>
                            <option value="<?= (int) $person['id'] ?>"<?= (int) ($consultFilters['provider_id'] ?? 0) === (int) $person['id'] ? ' selected' : '' ?>><?= htmlspecialchars((string) $person['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if (is_array($consultBhws)): ?>
                    <div class="op-appt__field">
                        <label class="op-appt__label" for="appt_bhw">Assigned BHW</label>
                        <select class="op-appt__control" id="appt_bhw" name="appt_bhw">
                            <option value="">All BHW</option>
                            <?php foreach ($consultBhws as $person): ?>
                                <option value="<?= (int) $person['id'] ?>"<?= (int) ($consultFilters['bhw_id'] ?? 0) === (int) $person['id'] ? ' selected' : '' ?>><?= htmlspecialchars((string) $person['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <div class="op-appt__field">
                    <label class="op-appt__label" for="appt_status">Consultation status</label>
                    <select class="op-appt__control" id="appt_status" name="appt_status">
                        <option value="">All statuses</option>
                        <?php foreach ($consultStatuses as $statusValue): ?>
                            <option value="<?= htmlspecialchars($statusValue) ?>"<?= (string) ($consultFilters['status'] ?? '') === $statusValue ? ' selected' : '' ?>><?= htmlspecialchars(ucwords(str_replace('_', ' ', $statusValue))) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="op-appt__field">
                    <label class="op-appt__label" for="appt_type">Consultation type</label>
                    <input class="op-appt__control" type="search" id="appt_type" name="appt_type" maxlength="80" value="<?= htmlspecialchars((string) ($consultFilters['type'] ?? '')) ?>" placeholder="General Consultation">
                </div>
                <?php if (is_array($consultSlots)): ?>
                    <div class="op-appt__field">
                        <label class="op-appt__label" for="appt_slot">Appointment status</label>
                        <select class="op-appt__control" id="appt_slot" name="appt_slot">
                            <option value="">Any slot</option>
                            <?php foreach ($consultSlots as $statusValue): ?>
                                <option value="<?= htmlspecialchars($statusValue) ?>"<?= (string) ($consultFilters['slot_status'] ?? '') === $statusValue ? ' selected' : '' ?>><?= htmlspecialchars(ucwords(str_replace('_', ' ', $statusValue))) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <?php if (!empty($reportConsult['supports_prescription'])): ?>
                    <div class="op-appt__field">
                        <label class="op-appt__label" for="appt_rx">Prescription</label>
                        <select class="op-appt__control" id="appt_rx" name="appt_rx">
                            <option value="">Any</option>
                            <option value="yes"<?= (string) ($consultFilters['prescription'] ?? '') === 'yes' ? ' selected' : '' ?>>Has a prescription</option>
                            <option value="no"<?= (string) ($consultFilters['prescription'] ?? '') === 'no' ? ' selected' : '' ?>>No prescription</option>
                        </select>
                    </div>
                <?php endif; ?>
                <?php if (!empty($reportConsult['supports_referral'])): ?>
                    <div class="op-appt__field">
                        <label class="op-appt__label" for="appt_ref">Referral</label>
                        <select class="op-appt__control" id="appt_ref" name="appt_ref">
                            <option value="">Any</option>
                            <option value="yes"<?= (string) ($consultFilters['referral'] ?? '') === 'yes' ? ' selected' : '' ?>>Has a referral</option>
                            <option value="no"<?= (string) ($consultFilters['referral'] ?? '') === 'no' ? ' selected' : '' ?>>No referral</option>
                        </select>
                    </div>
                <?php endif; ?>
            </div>
            <div class="op-appt__actions">
                <button type="submit" class="mc-btn mc-btn--primary mc-btn--sm">Apply filters</button>
                <a class="mc-btn mc-btn--outline mc-btn--sm" href="<?= htmlspecialchars($reportResetHref) ?>">Reset filters</a>
                <a class="mc-btn mc-btn--outline mc-btn--info" href="<?= htmlspecialchars($reportExportHref) ?>">Download CSV</a>
            </div>
        </form>
    <?php elseif ($reportDemo !== null): ?>
        <?php require __DIR__ . '/operational_report_demographics.php'; ?>
    <?php else: ?>
        <a class="mc-btn mc-btn--outline mc-btn--info" href="<?= htmlspecialchars($reportExportHref) ?>">Download CSV</a>
    <?php endif; ?>

    <?php if ($reportError !== ''): ?>
        <p class="text-sm" role="alert" style="margin: 10px 0 0;"><?= htmlspecialchars($reportError) ?></p>
    <?php elseif ($reportDemo === null || !empty($reportDemo['barangay'])): ?>
        <div class="mc-table-wrap" style="margin-top: 10px;">
            <table class="mc-table">
                <thead>
                    <tr>
                        <?php foreach ($reportHeaders as $header): ?>
                            <th scope="col"><?= htmlspecialchars((string) $header) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($reportRows === []): ?>
                        <tr>
                            <td colspan="<?= max(1, count($reportHeaders)) ?>" class="text-muted">No records found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($reportRows as $row): ?>
                            <tr>
                                <?php foreach ($row as $cell): ?>
                                    <td><?= htmlspecialchars((string) $cell) ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div style="display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-top: 10px; flex-wrap: wrap;">
            <span class="text-xs text-muted">
                <?php if ($reportTotal === 0): ?>
                    0 records
                <?php else: ?>
                    Showing <?= (int) $rangeStart ?>–<?= (int) $rangeEnd ?> of <?= (int) $reportTotal ?>
                <?php endif; ?>
            </span>
            <?php if ($reportTotal > $reportPerPage): ?>
                <div style="display: flex; gap: 8px;">
                    <?php if ($prevHref !== ''): ?>
                        <a class="mc-btn mc-btn--outline mc-btn--sm" href="<?= htmlspecialchars($prevHref) ?>">Previous</a>
                    <?php endif; ?>
                    <?php if ($nextHref !== ''): ?>
                        <a class="mc-btn mc-btn--outline mc-btn--sm" href="<?= htmlspecialchars($nextHref) ?>">Next</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>
