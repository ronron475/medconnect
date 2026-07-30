<?php
/**
 * My Health — Care tips history (provider-approved self-care from triage).
 * Expects: $care_tips_history, $care_tips_active_count (optional)
 */
require_once __DIR__ . '/triage_helpers.php';
require_once BASE_PATH . '/app/includes/triage_assessment_schema.php';

$care_tips_history = $care_tips_history ?? [];
$care_tips_active = [];
$care_tips_past = [];

foreach ($care_tips_history as $row) {
    $meta = mc_patient_care_tip_meta($row);
    if (!empty($meta['active'])) {
        $care_tips_active[] = $row;
    } else {
        $care_tips_past[] = $row;
    }
}

/**
 * @param array<string, mixed> $row
 */
function pmh_care_tip_card(array $row, bool $expandTipsDefault): void
{
    $meta = mc_patient_care_tip_meta($row);
    $kind = (string) ($meta['kind'] ?? 'default');
    $complaint = trim((string) ($row['chief_complaint'] ?? ''));
    $tips = $meta['show_tips']
        ? triage_recommendations_to_list((string) ($row['recommendations'] ?? ''))
        : [];
    $dateRaw = (string) ($row['recommendation_approved_at'] ?? '');
    if ($dateRaw === '') {
        $dateRaw = (string) ($row['assessed_at'] ?? '');
    }
    $dateLabel = $dateRaw !== '' ? date('M j, Y', strtotime($dateRaw)) : '—';
    $timeLabel = $dateRaw !== '' ? date('g:i A', strtotime($dateRaw)) : '';
    $status = (string) ($row['recommendation_status'] ?? '');
    ?>
    <article class="pmh-care-card pmh-care-card--<?= htmlspecialchars($kind) ?>">
      <div class="pmh-care-card__timeline" aria-hidden="true">
        <span class="pmh-care-card__dot"></span>
      </div>
      <div class="pmh-care-card__body">
        <header class="pmh-care-card__head">
          <div class="pmh-care-card__titles">
            <h3 class="pmh-care-card__title"><?= htmlspecialchars($complaint !== '' ? $complaint : 'Health concern') ?></h3>
            <p class="pmh-care-card__meta">
              <time datetime="<?= htmlspecialchars($dateRaw) ?>"><?= htmlspecialchars($dateLabel) ?></time>
              <?php if ($timeLabel !== ''): ?>
                <span class="pmh-care-card__meta-sep">·</span>
                <span><?= htmlspecialchars($timeLabel) ?></span>
              <?php endif; ?>
            </p>
          </div>
          <span class="pmh-care-card__status <?= htmlspecialchars($meta['class']) ?>">
            <?= htmlspecialchars($meta['label']) ?>
          </span>
        </header>

        <?php if ($meta['show_tips'] && $tips !== []): ?>
          <details class="pmh-care-card__tips"<?= $expandTipsDefault ? ' open' : '' ?>>
            <summary class="pmh-care-card__tips-summary">
              <span><?= count($tips) ?> self-care tip<?= count($tips) === 1 ? '' : 's' ?></span>
              <svg class="pmh-care-card__chev" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
            </summary>
            <ol class="pmh-care-card__tips-list">
              <?php foreach ($tips as $tip): ?>
                <li><?= htmlspecialchars($tip) ?></li>
              <?php endforeach; ?>
            </ol>
          </details>
        <?php elseif ($status === 'pending_approval'): ?>
          <div class="pmh-care-card__message pmh-care-card__message--pending">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            <p>Your provider is reviewing this concern. Approved tips will appear here and in the Care Assistant.</p>
          </div>
        <?php elseif ($status === 'rejected'): ?>
          <div class="pmh-care-card__message pmh-care-card__message--muted">
            <p>Self-care tips were not shared for this concern. Book a consultation if you need clinical advice.</p>
          </div>
        <?php endif; ?>
      </div>
    </article>
    <?php
}
?>

<?php if (empty($care_tips_history)): ?>
  <div class="pmh-empty pmh-empty--care">
    <div class="pmh-empty__icon" aria-hidden="true">
      <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
      </svg>
    </div>
    <h3>No care tips yet</h3>
    <p>
      When you share a non-urgent concern and your provider approves home care guidance,
      it will appear here and in the Care Assistant chat.
    </p>
    <a href="<?= ASSET_BASE ?>/views/patient/triage.php" class="pmh-btn pmh-btn--primary">Check symptoms / book</a>
  </div>
<?php else: ?>
  <div class="pmh-care-layout">
    <?php if ($care_tips_active !== []): ?>
      <section class="pmh-care-section" aria-labelledby="pmh-care-active-heading">
        <div class="pmh-care-section__head">
          <h2 id="pmh-care-active-heading" class="pmh-care-section__title">
            Needs attention
            <span class="pmh-care-section__count"><?= count($care_tips_active) ?></span>
          </h2>
          <p class="pmh-care-section__hint">Open the Care Assistant for the latest messages on these concerns.</p>
        </div>
        <div class="pmh-care-feed">
          <?php foreach ($care_tips_active as $row) {
              pmh_care_tip_card($row, true);
          } ?>
        </div>
      </section>
    <?php endif; ?>

    <?php if ($care_tips_past !== []): ?>
      <section class="pmh-care-section pmh-care-section--past" aria-labelledby="pmh-care-past-heading">
        <div class="pmh-care-section__head">
          <h2 id="pmh-care-past-heading" class="pmh-care-section__title">
            History
            <span class="pmh-care-section__count pmh-care-section__count--muted"><?= count($care_tips_past) ?></span>
          </h2>
        </div>
        <div class="pmh-care-feed pmh-care-feed--compact">
          <?php foreach ($care_tips_past as $row) {
              pmh_care_tip_card($row, false);
          } ?>
        </div>
      </section>
    <?php endif; ?>
  </div>
<?php endif; ?>
