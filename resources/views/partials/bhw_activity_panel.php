<?php
/**
 * Read-only BHW / community health activity lists.
 * Expects: $bhw_activity from community_bhw_activity_load()
 * Optional: $bhw_activity_variant = 'provider' | 'patient'
 */
$bhw_activity = $bhw_activity ?? [
    'documents' => [],
    'visits' => [],
    'referrals' => [],
    'health_entries' => [],
    'external_visits' => [],
    'total' => 0,
];
$bhw_activity_variant = $bhw_activity_variant ?? 'provider';
$bhwDocs = $bhw_activity['documents'] ?? [];
$bhwVisits = $bhw_activity['visits'] ?? [];
$bhwRefs = $bhw_activity['referrals'] ?? [];
$bhwHealth = $bhw_activity['health_entries'] ?? [];
$isProvider = $bhw_activity_variant === 'provider';
// Provider consultation already has a dedicated external-visits card — avoid duplicate list there.
$bhwExternal = $isProvider ? [] : ($bhw_activity['external_visits'] ?? []);
$bhwTotal = $isProvider
    ? (count($bhwDocs) + count($bhwVisits) + count($bhwRefs) + count($bhwHealth))
    : (int) ($bhw_activity['total'] ?? 0);

if (!$isProvider && $bhwTotal === 0) {
    return;
}

$bhwEmpty = $isProvider
    ? 'No barangay health worker health measurements, documents, home visits, or referrals on file for this patient.'
    : 'Your barangay health worker has not logged health measurements, documents, home visits, or referrals yet.';
$bhwAttrClass = $isProvider ? 'bhw-act-attr' : 'pmh-bhw__attr';
$bhwItemClass = $isProvider ? 'hs-block' : 'pmh-bhw__item';
$bhwLabelClass = $isProvider ? 'hs-label' : 'pmh-bhw__label';
$bhwEmptyClass = $isProvider ? 'hs-empty' : 'pmh-bhw__empty';

if ($isProvider) {
    ?>
        <div class="session-card bhw-act-card">
            <div class="session-card-header">
                <div>
                    <p class="csp-eyebrow" style="margin:0 0 2px;">Barangay support</p>
                    <div class="session-card-title"><?= icon('pin') ?> BHW Activity</div>
                </div>
            </div>
            <div class="session-card-body">
                <p class="bhw-act-lead">Health measurements, documents, home visits, and referrals from the patient’s record. Source (patient / BHW / provider) and recorded date/time are shown for each entry. Read-only — this does not change SOAP or triage.</p>
    <?php
} else {
    ?>
  <section class="pmh-bhw" aria-labelledby="pmhBhwTitle">
    <div class="pmh-bhw__head">
      <p class="pmh-bhw__eyebrow">Community health record</p>
      <h3 class="pmh-bhw__title" id="pmhBhwTitle">Health data on your record</h3>
      <p class="pmh-bhw__lead">Measurements and notes saved to your existing health record. Each entry shows who added it and when.</p>
    </div>
    <?php
}

if ($bhwTotal === 0) {
    echo '<p class="' . htmlspecialchars($bhwEmptyClass) . '">' . htmlspecialchars($bhwEmpty) . '</p>';
} else {
    ?>
                <section class="bhw-act-section">
                    <h4 class="<?= htmlspecialchars($bhwLabelClass) ?>">Health measurements (<?= count($bhwHealth) ?>)</h4>
                    <?php if ($bhwHealth === []): ?>
                    <p class="<?= htmlspecialchars($bhwEmptyClass) ?>">No vitals or visit-intake measurements on file.</p>
                    <?php else: ?>
                    <ul class="bhw-act-list">
                        <?php foreach ($bhwHealth as $entry): ?>
                        <li class="<?= htmlspecialchars($bhwItemClass) ?> bhw-act-entry">
                            <?php if (!empty($entry['status_label'])): ?>
                            <div class="bhw-act-item__meta"><?= htmlspecialchars((string) $entry['status_label']) ?></div>
                            <?php endif; ?>
                            <?php foreach (($entry['fields'] ?? []) as $field): ?>
                            <div class="bhw-act-field">
                                <div class="bhw-act-field__label"><?= htmlspecialchars((string) ($field['label'] ?? '')) ?></div>
                                <div class="bhw-act-field__value"><?= htmlspecialchars((string) ($field['value'] ?? '')) ?></div>
                            </div>
                            <?php endforeach; ?>
                            <?php require VIEWS_PATH . '/partials/bhw_activity_attribution.php'; ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </section>

                <?php if (!$isProvider): ?>
                <section class="bhw-act-section">
                    <h4 class="pmh-bhw__label">External healthcare visits (<?= count($bhwExternal) ?>)</h4>
                    <?php if ($bhwExternal === []): ?>
                    <p class="pmh-bhw__empty">No external facility visits on file.</p>
                    <?php else: ?>
                    <ul class="bhw-act-list">
                        <?php foreach ($bhwExternal as $entry): ?>
                        <li class="pmh-bhw__item bhw-act-entry">
                            <?php foreach (($entry['fields'] ?? []) as $field): ?>
                            <div class="bhw-act-field">
                                <div class="bhw-act-field__label"><?= htmlspecialchars((string) ($field['label'] ?? '')) ?></div>
                                <div class="bhw-act-field__value"><?= htmlspecialchars((string) ($field['value'] ?? '')) ?></div>
                            </div>
                            <?php endforeach; ?>
                            <?php require VIEWS_PATH . '/partials/bhw_activity_attribution.php'; ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </section>
                <?php endif; ?>

                <section class="bhw-act-section">
                    <h4 class="<?= htmlspecialchars($bhwLabelClass) ?>">Documents (<?= count($bhwDocs) ?>)</h4>
                    <?php if ($bhwDocs === []): ?>
                    <p class="<?= htmlspecialchars($bhwEmptyClass) ?>">No BHW-uploaded documents.</p>
                    <?php else: ?>
                    <ul class="bhw-act-list">
                        <?php foreach ($bhwDocs as $doc): ?>
                        <?php $entry = $doc; ?>
                        <li class="<?= htmlspecialchars($bhwItemClass) ?>">
                            <div class="bhw-act-item__title"><?= htmlspecialchars((string) $doc['title']) ?></div>
                            <div class="bhw-act-item__meta"><?= htmlspecialchars((string) $doc['type']) ?></div>
                            <?php if (!empty($doc['description'])): ?>
                            <p class="bhw-act-item__note"><?= htmlspecialchars((string) $doc['description']) ?></p>
                            <?php endif; ?>
                            <?php require VIEWS_PATH . '/partials/bhw_activity_attribution.php'; ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </section>

                <section class="bhw-act-section">
                    <h4 class="<?= htmlspecialchars($bhwLabelClass) ?>">Home visits (<?= count($bhwVisits) ?>)</h4>
                    <?php if ($bhwVisits === []): ?>
                    <p class="<?= htmlspecialchars($bhwEmptyClass) ?>">No home visits logged.</p>
                    <?php else: ?>
                    <ul class="bhw-act-list">
                        <?php foreach ($bhwVisits as $visit): ?>
                        <?php $entry = $visit; ?>
                        <li class="<?= htmlspecialchars($bhwItemClass) ?>">
                            <div class="bhw-act-item__title"><?= htmlspecialchars((string) $visit['type_label']) ?></div>
                            <div class="bhw-act-item__meta">Status: <?= htmlspecialchars((string) $visit['status']) ?></div>
                            <?php if (!empty($visit['notes'])): ?>
                            <p class="bhw-act-item__note"><?= htmlspecialchars((string) $visit['notes']) ?></p>
                            <?php endif; ?>
                            <?php require VIEWS_PATH . '/partials/bhw_activity_attribution.php'; ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </section>

                <section class="bhw-act-section">
                    <h4 class="<?= htmlspecialchars($bhwLabelClass) ?>">Referrals (<?= count($bhwRefs) ?>)</h4>
                    <?php if ($bhwRefs === []): ?>
                    <p class="<?= htmlspecialchars($bhwEmptyClass) ?>">No BHW referrals on file.</p>
                    <?php else: ?>
                    <ul class="bhw-act-list">
                        <?php foreach ($bhwRefs as $ref): ?>
                        <?php $entry = $ref; ?>
                        <li class="<?= htmlspecialchars($bhwItemClass) ?>">
                            <div class="bhw-act-item__title"><?= htmlspecialchars((string) $ref['type']) ?> · <?= htmlspecialchars((string) $ref['status']) ?></div>
                            <?php if (!empty($ref['facility'])): ?>
                            <div class="bhw-act-item__meta"><?= htmlspecialchars((string) $ref['facility']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($ref['reason'])): ?>
                            <p class="bhw-act-item__note"><?= htmlspecialchars((string) $ref['reason']) ?></p>
                            <?php endif; ?>
                            <?php require VIEWS_PATH . '/partials/bhw_activity_attribution.php'; ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </section>
    <?php
}

if ($isProvider) {
    ?>
            </div>
        </div>
    <?php
} else {
    ?>
  </section>
    <?php
}
