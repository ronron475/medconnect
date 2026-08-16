<?php
/**
 * Read-only BHW activity lists.
 * Expects: $bhw_activity from community_bhw_activity_load()
 * Optional: $bhw_activity_variant = 'provider' | 'patient'
 */
$bhw_activity = $bhw_activity ?? ['documents' => [], 'visits' => [], 'referrals' => [], 'total' => 0];
$bhw_activity_variant = $bhw_activity_variant ?? 'provider';
$bhwDocs = $bhw_activity['documents'] ?? [];
$bhwVisits = $bhw_activity['visits'] ?? [];
$bhwRefs = $bhw_activity['referrals'] ?? [];
$bhwTotal = (int) ($bhw_activity['total'] ?? 0);
$isProvider = $bhw_activity_variant === 'provider';

if (!$isProvider && $bhwTotal === 0) {
    return;
}

$bhwEmpty = $isProvider
    ? 'No barangay health worker documents, home visits, or referrals on file for this patient.'
    : 'Your barangay health worker has not logged documents, home visits, or referrals yet.';
?>
<?php if ($isProvider): ?>
        <div class="session-card bhw-act-card">
            <div class="session-card-header">
                <div>
                    <p class="csp-eyebrow" style="margin:0 0 2px;">Barangay support</p>
                    <div class="session-card-title"><?= icon('pin') ?> BHW Activity</div>
                </div>
            </div>
            <div class="session-card-body">
                <p class="bhw-act-lead">Documents, home visits, and referrals logged by the barangay health worker. Read-only — this does not change SOAP or triage.</p>
<?php else: ?>
  <section class="pmh-bhw" aria-labelledby="pmhBhwTitle">
    <div class="pmh-bhw__head">
      <p class="pmh-bhw__eyebrow">Barangay support</p>
      <h3 class="pmh-bhw__title" id="pmhBhwTitle">Barangay health worker</h3>
      <p class="pmh-bhw__lead">Documents, home visits, and referrals your BHW saved for you.</p>
    </div>
<?php endif; ?>

                <?php if ($bhwTotal === 0): ?>
                <p class="<?= $isProvider ? 'hs-empty' : 'pmh-bhw__empty' ?>"><?= htmlspecialchars($bhwEmpty) ?></p>
                <?php else: ?>

                <section class="bhw-act-section">
                    <h4 class="<?= $isProvider ? 'hs-label' : 'pmh-bhw__label' ?>">Documents (<?= count($bhwDocs) ?>)</h4>
                    <?php if ($bhwDocs === []): ?>
                    <p class="<?= $isProvider ? 'hs-empty' : 'pmh-bhw__empty' ?>">No BHW-uploaded documents.</p>
                    <?php else: ?>
                    <ul class="bhw-act-list">
                        <?php foreach ($bhwDocs as $doc): ?>
                        <li class="<?= $isProvider ? 'hs-block' : 'pmh-bhw__item' ?>">
                            <div class="bhw-act-item__title"><?= htmlspecialchars((string) $doc['title']) ?></div>
                            <div class="bhw-act-item__meta">
                                <?= htmlspecialchars((string) $doc['type']) ?>
                                · <?= htmlspecialchars((string) $doc['date_label']) ?>
                                <?php if (!empty($doc['bhw_name'])): ?>
                                · <?= htmlspecialchars((string) $doc['bhw_name']) ?>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($doc['description'])): ?>
                            <p class="bhw-act-item__note"><?= htmlspecialchars((string) $doc['description']) ?></p>
                            <?php endif; ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </section>

                <section class="bhw-act-section">
                    <h4 class="<?= $isProvider ? 'hs-label' : 'pmh-bhw__label' ?>">Home visits (<?= count($bhwVisits) ?>)</h4>
                    <?php if ($bhwVisits === []): ?>
                    <p class="<?= $isProvider ? 'hs-empty' : 'pmh-bhw__empty' ?>">No home visits logged.</p>
                    <?php else: ?>
                    <ul class="bhw-act-list">
                        <?php foreach ($bhwVisits as $visit): ?>
                        <li class="<?= $isProvider ? 'hs-block' : 'pmh-bhw__item' ?>">
                            <div class="bhw-act-item__title"><?= htmlspecialchars((string) $visit['type_label']) ?> · <?= htmlspecialchars((string) $visit['date_label']) ?></div>
                            <div class="bhw-act-item__meta">
                                Status: <?= htmlspecialchars((string) $visit['status']) ?>
                                <?php if (!empty($visit['bhw_name'])): ?>
                                · <?= htmlspecialchars((string) $visit['bhw_name']) ?>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($visit['notes'])): ?>
                            <p class="bhw-act-item__note"><?= htmlspecialchars((string) $visit['notes']) ?></p>
                            <?php endif; ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </section>

                <section class="bhw-act-section">
                    <h4 class="<?= $isProvider ? 'hs-label' : 'pmh-bhw__label' ?>">Referrals (<?= count($bhwRefs) ?>)</h4>
                    <?php if ($bhwRefs === []): ?>
                    <p class="<?= $isProvider ? 'hs-empty' : 'pmh-bhw__empty' ?>">No BHW referrals on file.</p>
                    <?php else: ?>
                    <ul class="bhw-act-list">
                        <?php foreach ($bhwRefs as $ref): ?>
                        <li class="<?= $isProvider ? 'hs-block' : 'pmh-bhw__item' ?>">
                            <div class="bhw-act-item__title"><?= htmlspecialchars((string) $ref['type']) ?> · <?= htmlspecialchars((string) $ref['status']) ?></div>
                            <div class="bhw-act-item__meta">
                                <?= htmlspecialchars((string) $ref['date_label']) ?>
                                <?php if (!empty($ref['facility'])): ?>
                                · <?= htmlspecialchars((string) $ref['facility']) ?>
                                <?php endif; ?>
                                <?php if (!empty($ref['bhw_name'])): ?>
                                · <?= htmlspecialchars((string) $ref['bhw_name']) ?>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($ref['reason'])): ?>
                            <p class="bhw-act-item__note"><?= htmlspecialchars((string) $ref['reason']) ?></p>
                            <?php endif; ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </section>

                <?php endif; ?>

<?php if ($isProvider): ?>
            </div>
        </div>
<?php else: ?>
  </section>
<?php endif; ?>
