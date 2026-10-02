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
$reportExportHref = $reportExportBase . '?type=' . rawurlencode($reportType);
if ($reportDemo !== null && !empty($reportDemo['barangay']['id'])) {
    $reportExportHref .= '&barangay_id=' . (int) $reportDemo['barangay']['id'];
}
?>
<section class="mc-card" id="report-<?= htmlspecialchars($reportType) ?>" style="padding: 14px 16px; margin-bottom: 10px;">
    <div style="font-weight: 800; color: var(--mc-navy-dark); margin-bottom: 6px;"><?= htmlspecialchars((string) ($reportModule['title'] ?? '')) ?></div>
    <p class="text-xs text-muted" style="margin-bottom: 10px;"><?= htmlspecialchars((string) ($reportModule['description'] ?? '')) ?></p>

    <?php if ($reportDemo !== null): ?>
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
