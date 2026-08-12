<?php
/**
 * My Health — Health files tab.
 * Expects: $all_records, $counts, $outcomes_by_consult (optional)
 */
$outcomes_by_consult = $outcomes_by_consult ?? [];
$filter_types = [
    'all'           => ['label' => 'All Records', 'icon' => 'folder', 'accent' => '#028090'],
    'Prescription'  => ['label' => 'Prescriptions', 'icon' => 'rx', 'accent' => '#2563eb'],
    'Health File'   => ['label' => 'Health Files', 'icon' => 'note', 'accent' => '#059669'],
    'Referral'      => ['label' => 'Referrals', 'icon' => 'referral', 'accent' => '#d97706'],
];

function pmh_health_file_text(?string $value): bool
{
    $t = trim((string) $value);
    return $t !== '' && $t !== '—';
}
?>
<div class="pmh-files">
  <nav class="pmh-files__filters pmh-toolbar" aria-label="Filter health files">
    <?php foreach ($filter_types as $key => $meta):
      $cnt = $key === 'all' ? ($counts['all'] ?? 0) : ($counts[$key] ?? 0);
    ?>
    <button type="button"
      class="pmh-files__filter"
      id="health-btn-<?= htmlspecialchars($key) ?>"
      data-health-filter="<?= htmlspecialchars($key) ?>"
      aria-pressed="<?= $key === 'all' ? 'true' : 'false' ?>">
      <span class="pmh-files__filter-count"><?= (int) $cnt ?></span>
      <?= htmlspecialchars($meta['label']) ?>
    </button>
    <?php endforeach; ?>
  </nav>

  <?php if (empty($all_records)): ?>
  <div class="pmh-empty">
    <div class="pmh-empty__icon" aria-hidden="true">
      <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
    </div>
    <h3>No health files yet</h3>
    <p>Your doctor&apos;s finalized consultation records will appear here after they complete and sign your visit.</p>
  </div>
  <?php else: ?>
  <div class="pmh-files__list pmh-feed pmh-feed--files" id="pmh-files-list">
    <?php foreach ($all_records as $r):
      $type = (string) ($r['record_type'] ?? '');
      $accent = $filter_types[$type]['accent'] ?? '#64748b';
      $typeSlug = strtolower(str_replace(' ', '-', $type));
      $consultId = (int) ($r['consultation_id'] ?? 0);
      $outcome = $consultId > 0 ? ($outcomes_by_consult[$consultId] ?? null) : null;
      $cardId = $type === 'Health File' && $consultId > 0 ? 'health-file-' . $consultId : '';
    ?>
    <article class="pmh-file-card"<?= $cardId !== '' ? ' id="' . htmlspecialchars($cardId) . '"' : '' ?> data-type="<?= htmlspecialchars($type) ?>" style="--pmh-accent: <?= htmlspecialchars($accent) ?>">
      <div class="pmh-file-card__type">
        <span class="pmh-file-card__badge pmh-file-card__badge--<?= htmlspecialchars($typeSlug) ?>"><?= htmlspecialchars($type) ?></span>
        <time datetime="<?= htmlspecialchars($r['record_date'] ?? '') ?>">
          <?= !empty($r['record_date']) ? date('M j, Y', strtotime($r['record_date'])) : '—' ?>
        </time>
      </div>
      <h3 class="pmh-file-card__title"><?= htmlspecialchars($r['record_name'] ?? '—') ?></h3>
      <p class="pmh-file-card__provider">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        Dr. <?= htmlspecialchars($r['provider_name'] ?? '—') ?>
      </p>
      <?php if (!empty($outcome['final_case_level'])): ?>
      <p class="pmh-visit__case-level">
        <span class="pmh-case-level <?= htmlspecialchars(patient_case_level_chip_class((string) ($outcome['final_case_bucket'] ?? ''))) ?>">
          Final: <?= htmlspecialchars((string) $outcome['final_case_level']) ?>
        </span>
      </p>
      <?php endif; ?>
      <?php if ($type === 'Health File'): ?>
        <dl class="pmh-soap-list pmh-file-card__soap-list">
          <div><dt>Subjective</dt><dd><?= nl2br(htmlspecialchars(pmh_health_file_text($r['subjective'] ?? '') ? $r['subjective'] : '—')) ?></dd></div>
          <div><dt>Objective</dt><dd><?= nl2br(htmlspecialchars(pmh_health_file_text($r['objective'] ?? '') ? $r['objective'] : '—')) ?></dd></div>
          <div><dt>Assessment</dt><dd><?= nl2br(htmlspecialchars(pmh_health_file_text($r['assessment'] ?? '') ? $r['assessment'] : '—')) ?></dd></div>
          <div><dt>Plan</dt><dd><?= nl2br(htmlspecialchars(pmh_health_file_text($r['plan'] ?? '') ? $r['plan'] : '—')) ?></dd></div>
        </dl>
        <?php if (pmh_health_file_text($r['treatment_plan'] ?? '')): ?>
        <p class="pmh-file-card__soap"><strong>Care plan:</strong> <?= nl2br(htmlspecialchars($r['treatment_plan'])) ?></p>
        <?php endif; ?>
        <?php if ($consultId > 0): ?>
        <p class="pmh-file-card__link">
          <a href="<?= htmlspecialchars(patient_consultation_detail_url($consultId)) ?>" class="pmh-btn pmh-btn--outline pmh-btn--sm">Full consultation details</a>
        </p>
        <?php endif; ?>
      <?php else: ?>
        <?php if (!empty($r['detail']) && $r['detail'] !== '—'): ?>
        <p class="pmh-file-card__detail"><?= htmlspecialchars($r['detail']) ?></p>
        <?php endif; ?>
        <?php if (!empty($r['frequency']) || !empty($r['duration'])): ?>
        <p class="pmh-file-card__meta text-xs text-muted">
          <?= htmlspecialchars(trim(($r['frequency'] ?? '') . ($r['duration'] ? ' · ' . $r['duration'] : ''))) ?>
        </p>
        <?php endif; ?>
      <?php endif; ?>
    </article>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
