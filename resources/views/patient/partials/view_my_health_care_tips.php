<?php
/**
 * My Health — Care tips (timeline layout matching Care Timeline).
 * Expects: $care_tips_history, $care_tips_active_count (optional)
 */
require_once __DIR__ . '/triage_helpers.php';
require_once BASE_PATH . '/app/includes/triage_assessment_schema.php';

$care_tips_history = $care_tips_history ?? [];
$care_tips_active = [];
$care_tips_past = [];
$pmh_care_tips_modal = [];

/**
 * @param array<string, mixed> $row
 */
function pmh_care_tip_provider_label(array $row): string
{
    $name = trim((string) ($row['reviewer_name'] ?? ''));
    if ($name === '') {
        $name = trim((string) ($row['assigned_name'] ?? ''));
    }
    if ($name === '') {
        return '';
    }
    if (stripos($name, 'dr.') !== 0 && stripos($name, 'dr ') !== 0) {
        return 'Dr. ' . $name;
    }

    return $name;
}

/**
 * @param array<string, mixed> $row
 * @param array<string, mixed> $meta
 * @param list<string> $tips
 * @return array<string, mixed>
 */
function pmh_care_tip_modal_entry(array $row, array $meta, array $tips): array
{
    $dateRaw = (string) ($row['recommendation_approved_at'] ?? '');
    if ($dateRaw === '') {
        $dateRaw = (string) ($row['assessed_at'] ?? '');
    }
    $dateLabel = $dateRaw !== '' ? date('M j, Y', strtotime($dateRaw)) : '—';
    $timeLabel = $dateRaw !== '' ? date('g:i A', strtotime($dateRaw)) : '';
    $datetimeLabel = $timeLabel !== '' ? $dateLabel . ' · ' . $timeLabel : $dateLabel;
    $complaint = trim((string) ($row['chief_complaint'] ?? ''));

    return [
        'triageId' => (int) ($row['id'] ?? 0),
        'complaint' => $complaint !== '' ? $complaint : 'Health concern',
        'datetimeIso' => $dateRaw,
        'datetimeLabel' => $datetimeLabel,
        'statusLabel' => (string) ($meta['label'] ?? 'Recorded'),
        'statusClass' => (string) ($meta['class'] ?? 'pmh-care-card__status--default'),
        'providerName' => pmh_care_tip_provider_label($row),
        'tips' => array_values($tips),
    ];
}

foreach ($care_tips_history as $row) {
    $meta = mc_patient_care_tip_meta($row);
    if (!empty($meta['active'])) {
        $care_tips_active[] = $row;
    } else {
        $care_tips_past[] = $row;
    }

    if (empty($meta['show_tips'])) {
        continue;
    }
    $tips = triage_recommendations_to_list((string) ($row['recommendations'] ?? ''));
    if ($tips === []) {
        continue;
    }
    $triageId = (int) ($row['id'] ?? 0);
    if ($triageId > 0) {
        $pmh_care_tips_modal[$triageId] = pmh_care_tip_modal_entry($row, $meta, $tips);
    }
}

/**
 * @param array<string, mixed> $row
 */
function pmh_care_tip_timeline_item(array $row): void
{
    $meta = mc_patient_care_tip_meta($row);
    $kind = (string) ($meta['kind'] ?? 'default');
    $complaint = trim((string) ($row['chief_complaint'] ?? ''));
    $tips = !empty($meta['show_tips'])
        ? triage_recommendations_to_list((string) ($row['recommendations'] ?? ''))
        : [];
    $dateRaw = (string) ($row['recommendation_approved_at'] ?? '');
    if ($dateRaw === '') {
        $dateRaw = (string) ($row['assessed_at'] ?? '');
    }
    $ts = $dateRaw !== '' ? (strtotime($dateRaw) ?: 0) : 0;
    $dateLabel = $ts > 0 ? date('M j, Y', $ts) : '—';
    $timeLabel = $ts > 0 ? date('g:i A', $ts) : '';
    $combined = $dateLabel . ($timeLabel !== '' ? ' · ' . $timeLabel : '');
    $status = (string) ($row['recommendation_status'] ?? '');
    $triageId = (int) ($row['id'] ?? 0);
    $provider = pmh_care_tip_provider_label($row);
    $tipCount = count($tips);
    $isActive = !empty($meta['active']);
    $filterType = $isActive ? 'active' : 'history';
    $nodeMod = match ($kind) {
        'pending' => 'pmh-tl__item--assessment',
        'ready' => 'pmh-tl__item--consultation',
        'rejected' => 'pmh-tl__item--referral',
        default => 'pmh-tl__item--record',
    };
    ?>
    <li class="pmh-tl__item <?= htmlspecialchars($nodeMod) ?> pmh-tl__item--care" data-tl-type="<?= htmlspecialchars($filterType) ?>" data-care-kind="<?= htmlspecialchars($kind) ?>">
      <div class="pmh-tl__when">
        <span class="pmh-tl__date"><?= htmlspecialchars($dateLabel) ?></span>
        <?php if ($timeLabel !== ''): ?><span class="pmh-tl__time"><?= htmlspecialchars($timeLabel) ?></span><?php endif; ?>
      </div>
      <div class="pmh-tl__rail" aria-hidden="true">
        <span class="pmh-tl__node">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18h6"/><path d="M10 22h4"/><path d="M12 2a7 7 0 0 0-4 12.7V17h8v-2.3A7 7 0 0 0 12 2z"/></svg>
        </span>
      </div>
      <div class="pmh-tl__cards">
        <article class="pmh-tl-card pmh-tl-card--summary">
          <div class="pmh-tl-card__top">
            <span class="pmh-tl-badge pmh-tl-badge--care">Care Tips</span>
            <span class="pmh-care-card__status <?= htmlspecialchars((string) $meta['class']) ?>"><?= htmlspecialchars((string) $meta['label']) ?></span>
          </div>
          <h4 class="pmh-tl-card__title"><?= htmlspecialchars($complaint !== '' ? $complaint : 'Health concern') ?></h4>
          <?php if ($provider !== ''): ?>
          <p class="pmh-tl-card__sub"><?= htmlspecialchars($provider) ?></p>
          <?php elseif ($isActive): ?>
          <p class="pmh-tl-card__sub">Needs your attention</p>
          <?php else: ?>
          <p class="pmh-tl-card__sub">Home-care guidance</p>
          <?php endif; ?>
        </article>
        <article class="pmh-tl-card pmh-tl-card--detail">
          <div class="pmh-tl-card__top">
            <h4 class="pmh-tl-card__heading">What to do at home</h4>
            <?php if ($tipCount > 0): ?>
            <span class="pmh-status pmh-status--default"><?= (int) $tipCount ?> tip<?= $tipCount === 1 ? '' : 's' ?></span>
            <?php endif; ?>
          </div>

          <?php if ($meta['show_tips'] && $tips !== []): ?>
          <p class="pmh-tl-card__copy">Provider-approved tips for this concern.</p>
          <p class="pmh-tl-card__actions">
            <button
              type="button"
              class="pmh-tl-link pmh-tl-link--accent"
              data-pmh-care-tips-open
              data-triage-id="<?= $triageId ?>"
              aria-haspopup="dialog"
              aria-controls="pmhCareTipsModal"
            >
              View <?= (int) $tipCount ?> care tip<?= $tipCount === 1 ? '' : 's' ?> →
            </button>
          </p>
          <?php elseif ($status === 'pending_approval'): ?>
          <p class="pmh-tl-card__copy">Your provider is reviewing this concern. Approved tips will appear here and in Care Assistant.</p>
          <?php elseif ($status === 'rejected'): ?>
          <p class="pmh-tl-card__copy">Home-care tips were not shared for this concern. Book a consultation if you need clinical advice.</p>
          <?php else: ?>
          <p class="pmh-tl-card__copy">No care tips are available for this entry yet.</p>
          <?php endif; ?>
        </article>
      </div>
    </li>
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
      When you share a non-urgent concern and your provider approves home-care guidance,
      the tips will appear here and in Care Assistant.
    </p>
    <a href="<?= ASSET_BASE ?>/views/patient/triage.php" class="pmh-btn pmh-btn--primary">Check symptoms or book</a>
  </div>
<?php else: ?>
  <div class="pmh-tl-wrap pmh-tl-wrap--care">
    <p class="pmh-section-lead">Home-care tips approved for your health concerns.</p>
    <div class="pmh-tl-toolbar">
      <label class="pmh-tl-filter">
        <span class="sr-only">Filter care tips</span>
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
        <select id="pmh-care-tl-filter" data-pmh-care-tl-filter>
          <option value="all">All tips</option>
          <?php if ($care_tips_active !== []): ?>
          <option value="active">Needs attention (<?= count($care_tips_active) ?>)</option>
          <?php endif; ?>
          <?php if ($care_tips_past !== []): ?>
          <option value="history">Past tips (<?= count($care_tips_past) ?>)</option>
          <?php endif; ?>
        </select>
      </label>
    </div>

    <ol class="pmh-tl" id="pmh-care-tl-list">
      <?php
        foreach ($care_tips_active as $row) {
            pmh_care_tip_timeline_item($row);
        }
        foreach ($care_tips_past as $row) {
            pmh_care_tip_timeline_item($row);
        }
      ?>
    </ol>
  </div>
  <script>
  (function () {
    var sel = document.querySelector('[data-pmh-care-tl-filter]');
    if (!sel) return;
    sel.addEventListener('change', function () {
      var type = sel.value;
      document.querySelectorAll('#pmh-care-tl-list > .pmh-tl__item').forEach(function (item) {
        item.hidden = !(type === 'all' || item.getAttribute('data-tl-type') === type);
      });
    });
  })();
  </script>
<?php endif; ?>

<?php if ($pmh_care_tips_modal !== []): ?>
  <?php require __DIR__ . '/patient_care_tips_modal.php'; ?>
  <script type="application/json" id="pmhCareTipsData"><?= json_encode($pmh_care_tips_modal, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
  <?php $pmhCareTipsJsVer = (int) @filemtime(ASSETS_PATH . '/js/patient-care-tips-modal.js'); ?>
  <script src="<?= ASSET_BASE ?>/assets/js/patient-care-tips-modal.js?v=<?= $pmhCareTipsJsVer ?>"></script>
<?php endif; ?>
