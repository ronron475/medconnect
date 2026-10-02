<?php
/**
 * User Demographics header: barangay picker, CSV, total patients, age groups, sex breakdown.
 *
 * @var array{barangays:list<array{id:int,name:string}>,barangay:?array{id:int,name:string},summary:array{total:int,age_groups:array<string,int>,gender:array<string,int>}} $reportDemo
 * @var array<string, int> $reportPages
 * @var string $reportType
 * @var string $reportBasePath
 * @var string $reportExportHref
 */
$demoBarangays = $reportDemo['barangays'] ?? [];
$demoSelected = $reportDemo['barangay'] ?? null;
$demoSummary = $reportDemo['summary'] ?? ['total' => 0, 'age_groups' => [], 'gender' => []];
$demoTotal = (int) ($demoSummary['total'] ?? 0);
$demoPct = static function (int $count) use ($demoTotal): int {
    return $demoTotal > 0 ? (int) round(($count / $demoTotal) * 100) : 0;
};
$demoGenderColors = ['Male' => 'var(--mc-info)', 'Female' => '#EC4899'];
?>
<style>
    .op-demo__toolbar { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 10px; }
    .op-demo__toolbar > .mc-btn { height: 38px; }
    .op-demo__field { display: flex; flex-direction: column; gap: 4px; min-width: 240px; }
    .op-demo__label { font-size: var(--mc-fs-xs); font-weight: 700; color: var(--text-secondary, var(--mc-slate-muted)); text-transform: uppercase; letter-spacing: .04em; }
    .op-demo__select { height: 38px; padding: 0 10px; border-radius: var(--mc-radius-sm); border: 1px solid var(--border-color, var(--mc-border-thin)); background: var(--card-bg, var(--mc-white)); color: var(--text-primary, var(--mc-navy-dark)); font: inherit; font-size: var(--mc-fs-sm); }
    .op-demo__grid { display: grid; grid-template-columns: minmax(160px, 0.8fr) minmax(220px, 1.2fr) minmax(220px, 1.2fr); gap: 10px; margin-top: 12px; }
    .op-demo__tile { border: 1px solid var(--border-color, var(--mc-border-thin)); border-radius: var(--mc-radius-sm); background: var(--bg-secondary, var(--mc-ice-blue)); padding: 12px 14px; }
    .op-demo__tile-title { font-size: var(--mc-fs-xs); font-weight: 700; color: var(--text-secondary, var(--mc-slate-muted)); text-transform: uppercase; letter-spacing: .04em; margin-bottom: 8px; }
    .op-demo__tile--total { display: flex; flex-direction: column; justify-content: center; }
    .op-demo__total { font-size: 2rem; font-weight: 800; line-height: 1.1; color: var(--text-primary, var(--mc-navy-dark)); }
    .op-demo__sub { font-size: var(--mc-fs-xs); color: var(--text-secondary, var(--mc-slate-muted)); margin-top: 4px; }
    .op-demo__row { display: grid; grid-template-columns: minmax(110px, auto) 1fr auto; align-items: center; gap: 8px; font-size: var(--mc-fs-sm); color: var(--text-primary, var(--mc-navy-dark)); }
    .op-demo__row + .op-demo__row { margin-top: 6px; }
    .op-demo__bar { height: 8px; border-radius: 999px; background: var(--border-color, var(--mc-border-thin)); overflow: hidden; }
    .op-demo__bar > span { display: block; height: 100%; border-radius: 999px; background: var(--mc-aqua); }
    .op-demo__count { font-variant-numeric: tabular-nums; font-weight: 700; white-space: nowrap; }
    .op-demo__count small { font-weight: 500; color: var(--text-secondary, var(--mc-slate-muted)); margin-left: 4px; }
    #report-users .mc-table th, #report-users .mc-table td { white-space: nowrap; }
    #report-users .mc-table td:nth-child(1) { font-variant-numeric: tabular-nums; }
    #report-users .mc-table th:nth-child(3), #report-users .mc-table td:nth-child(3) { text-align: center; }
    @media (max-width: 900px) { .op-demo__grid { grid-template-columns: 1fr; } }
</style>

<div class="op-demo">
    <div class="op-demo__toolbar">
        <?php if ($demoBarangays !== []): ?>
            <form class="op-demo__field" method="get" action="<?= htmlspecialchars($reportBasePath . '#report-' . rawurlencode($reportType)) ?>">
                <?php foreach ($reportPages as $pageKey => $pageValue): ?>
                    <?php if ($pageKey !== $reportType && (int) $pageValue > 1): ?>
                        <input type="hidden" name="<?= htmlspecialchars($pageKey . '_page') ?>" value="<?= (int) $pageValue ?>">
                    <?php endif; ?>
                <?php endforeach; ?>
                <label class="op-demo__label" for="opDemoBarangay">BHW-assigned barangay</label>
                <select class="op-demo__select" id="opDemoBarangay" name="users_barangay" onchange="this.form.submit()">
                    <?php foreach ($demoBarangays as $option): ?>
                        <option value="<?= (int) $option['id'] ?>"<?= $demoSelected !== null && (int) $demoSelected['id'] === (int) $option['id'] ? ' selected' : '' ?>><?= htmlspecialchars($option['name']) ?> (<?= number_format((int) $option['patients']) ?> <?= (int) $option['patients'] === 1 ? 'patient' : 'patients' ?>)</option>
                    <?php endforeach; ?>
                </select>
                <noscript><button type="submit" class="mc-btn mc-btn--outline mc-btn--sm" style="margin-top: 6px;">Show</button></noscript>
            </form>
        <?php endif; ?>
        <?php if ($demoSelected !== null): ?>
            <a class="mc-btn mc-btn--outline mc-btn--info" href="<?= htmlspecialchars($reportExportHref) ?>">Download CSV</a>
        <?php endif; ?>
    </div>

    <?php if ($demoSelected === null): ?>
        <p class="text-sm text-muted" style="margin: 10px 0 0;">No patients are registered yet in any BHW-assigned barangay.</p>
    <?php else: ?>
        <div class="op-demo__grid">
            <div class="op-demo__tile op-demo__tile--total">
                <div class="op-demo__tile-title">Total patients</div>
                <div class="op-demo__total"><?= number_format($demoTotal) ?></div>
                <div class="op-demo__sub">Registered in Brgy. <?= htmlspecialchars($demoSelected['name']) ?></div>
            </div>

            <div class="op-demo__tile">
                <div class="op-demo__tile-title">Age groups</div>
                <?php foreach (($demoSummary['age_groups'] ?? []) as $label => $count): ?>
                    <div class="op-demo__row">
                        <span><?= htmlspecialchars((string) $label) ?></span>
                        <span class="op-demo__bar" aria-hidden="true"><span style="width: <?= $demoPct((int) $count) ?>%;"></span></span>
                        <span class="op-demo__count"><?= (int) $count ?><small><?= $demoPct((int) $count) ?>%</small></span>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="op-demo__tile">
                <div class="op-demo__tile-title">Sex</div>
                <?php foreach (($demoSummary['gender'] ?? []) as $label => $count): ?>
                    <div class="op-demo__row">
                        <span><?= htmlspecialchars((string) $label) ?></span>
                        <span class="op-demo__bar" aria-hidden="true"><span style="width: <?= $demoPct((int) $count) ?>%; background: <?= $demoGenderColors[$label] ?? 'var(--mc-aqua)' ?>;"></span></span>
                        <span class="op-demo__count"><?= (int) $count ?><small><?= $demoPct((int) $count) ?>%</small></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</div>
