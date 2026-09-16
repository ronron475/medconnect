<?php
/**
 * My Health — Care timeline tab.
 * Expects: $care_timeline (unified), $history (legacy fallback),
 *          $rx_by_consult, $notes_by_consult, $outcomes_by_consult,
 *          $care_tips_history (optional), $bhw_activity (optional)
 */
$rx_by_consult = $rx_by_consult ?? [];
$notes_by_consult = $notes_by_consult ?? [];
$outcomes_by_consult = $outcomes_by_consult ?? [];
$care_timeline = $care_timeline ?? [];
$care_tips_history = $care_tips_history ?? [];
$bhwAttrClass = 'pmh-event-meta';

if ($care_timeline === [] && !empty($history)) {
    // Backward-compatible fallback if builder was not run.
    foreach ($history as $h) {
        $care_timeline[] = ['type' => 'consultation', 'sort_at' => '', 'data' => $h];
    }
}

$hasTimeline = $care_timeline !== [];
$hasCareTips = !empty($care_tips_history);

if (!function_exists('pmh_status_class')) {
function pmh_status_class(string $status): string {
    $s = strtolower(trim($status));
    if ($s === 'completed') return 'pmh-status--completed';
    if ($s === 'cancelled') return 'pmh-status--cancelled';
    if ($s === 'in_consultation') return 'pmh-status--live';
    if (in_array($s, ['scheduled', 'pending'], true)) return 'pmh-status--scheduled';
    return 'pmh-status--default';
}
}

if (!function_exists('pmh_note_text')) {
function pmh_note_text(?string $value, string $fallback = ''): string {
    $v = trim((string) $value);
    return $v !== '' ? $v : $fallback;
}
}

/**
 * Split chief complaint from other recorded fields for clearer hierarchy.
 *
 * @param list<array<string, mixed>> $fields
 * @return array{0: ?array<string, mixed>, 1: list<array<string, mixed>>}
 */
if (!function_exists('pmh_split_primary_field')) {
function pmh_split_primary_field(array $fields): array {
    $primary = null;
    $rest = [];
    foreach ($fields as $field) {
        $key = strtolower((string) ($field['key'] ?? ''));
        $label = strtolower((string) ($field['label'] ?? ''));
        $isPrimary = in_array($key, ['chief_complaint', 'reason', 'notes'], true)
            || in_array($label, ['chief complaint', 'reason', 'notes'], true);
        if ($isPrimary && $primary === null) {
            $primary = $field;
            continue;
        }
        $rest[] = $field;
    }
    return [$primary, $rest];
}
}

if (!function_exists('pmh_when_parts')) {
/**
 * @param array<string, mixed> $entry
 * @return list<string>
 */
function pmh_when_parts(array $entry, bool $includeCategory = false): array {
    $parts = [];
    if ($includeCategory) {
        $category = trim((string) ($entry['category'] ?? ''));
        if ($category !== '') {
            $parts[] = $category;
        }
    }
    if (!empty($entry['date_label']) && $entry['date_label'] !== '—') {
        $parts[] = (string) $entry['date_label'];
    }
    if (!empty($entry['time_label']) && $entry['time_label'] !== '—') {
        $parts[] = (string) $entry['time_label'];
    }
    return $parts;
}
}
?>
<?php if (!$hasTimeline): ?>
  <div class="pmh-empty pmh-empty--minimal mx-auto w-full max-w-3xl">
    <?php if ($hasCareTips): ?>
    <h3>No care visits on this timeline yet</h3>
    <p>
      Related activity is available under
      <a href="<?= ASSET_BASE ?>/views/patient/my_health.php?tab=care-tips">Care tips</a>
      and
      <a href="<?= ASSET_BASE ?>/views/patient/health_summary.php">Health Summary</a>.
    </p>
    <a href="<?= ASSET_BASE ?>/views/patient/triage.php" class="pmh-btn pmh-btn--primary">Book Consultation</a>
    <?php else: ?>
    <h3>No visit history yet</h3>
    <p>Your care timeline will show consultations and health measurements after your first recorded visit.</p>
    <a href="<?= ASSET_BASE ?>/views/patient/triage.php" class="pmh-btn pmh-btn--primary">Book Consultation</a>
    <?php endif; ?>
  </div>
<?php else: ?>
  <div class="pmh-feed pmh-feed--timeline mx-auto grid w-full max-w-3xl grid-cols-1 gap-3 lg:max-w-4xl lg:grid-cols-2 lg:gap-4">
    <?php foreach ($care_timeline as $item):
      $type = (string) ($item['type'] ?? '');
      $row = $item['data'] ?? [];
      if (!is_array($row)) {
          continue;
      }

      if ($type === 'consultation'):
      $h = $row;
      $cid = (int) ($h['id'] ?? 0);
      $p_list = $rx_by_consult[$cid] ?? [];
      $note = $notes_by_consult[$cid] ?? null;
      $outcome = $outcomes_by_consult[$cid] ?? null;
      $status = (string) ($h['status'] ?? 'pending');
      $statusLabel = ucwords(str_replace('_', ' ', $status));
      $isCompleted = strtolower($status) === 'completed';
      $hasFinalizedNote = $isCompleted && !empty($note);
      $chiefComplaint = trim((string) ($h['consult_type'] ?? ''));
      if ($chiefComplaint === '' || strcasecmp($chiefComplaint, 'General consultation') === 0) {
          $chiefComplaint = '';
      }
      $diagnosis = $hasFinalizedNote
          ? pmh_note_text($note['diagnosis'] ?? '', pmh_note_text($h['diagnosis'] ?? '', 'No diagnosis recorded.'))
          : ($isCompleted ? pmh_note_text($h['diagnosis'] ?? '', 'No diagnosis recorded.') : '');
      $recommendation = $hasFinalizedNote
          ? pmh_note_text($note['treatment_plan'] ?? '', pmh_note_text($note['plan'] ?? '', pmh_note_text($h['recommendation'] ?? '', 'No recommendations recorded.')))
          : '';
      $timeLabel = '';
      if (!empty($h['consult_time'])) {
          $tp = explode(':', (string) $h['consult_time']);
          $th = (int) ($tp[0] ?? 0);
          $tm = str_pad((string) ($tp[1] ?? '00'), 2, '0', STR_PAD_LEFT);
          $timeLabel = (($th + 11) % 12 + 1) . ':' . $tm . ' ' . ($th >= 12 ? 'PM' : 'AM');
      }
      $providerName = trim(($h['first_name'] ?? '') . ' ' . ($h['last_name'] ?? ''));
      $dateDisplay = !empty($h['consult_date']) ? date('M j, Y', strtotime($h['consult_date'])) : '—';
      $isPendingDoc = in_array(strtolower($status), ['in_consultation', 'scheduled', 'pending'], true);
    ?>
    <article class="pmh-visit pmh-visit--consultation">
      <div class="pmh-visit__rail" aria-hidden="true"><span class="pmh-visit__dot"></span></div>
      <div class="pmh-visit__body">
        <header class="pmh-visit__head">
          <div class="pmh-visit__identity">
            <p class="pmh-visit__eyebrow">Consultation</p>
            <h3 class="pmh-visit__title">Dr. <?= htmlspecialchars($providerName) ?></h3>
            <p class="pmh-visit__meta">
              <time datetime="<?= htmlspecialchars($h['consult_date'] ?? '') ?>"><?= htmlspecialchars($dateDisplay) ?></time>
              <?php if ($timeLabel): ?> · <?= htmlspecialchars($timeLabel) ?><?php endif; ?>
            </p>
          </div>
          <span class="pmh-status <?= pmh_status_class($status) ?>" data-consult-status="<?= (int) $cid ?>"><?= htmlspecialchars($statusLabel) ?></span>
        </header>

        <?php if (!empty($outcome['final_case_level'])): ?>
        <?php
          if (!function_exists('mc_render_consultation_outcome_stack')) {
              require_once VIEWS_PATH . '/patient/partials/triage_helpers.php';
          }
          mc_render_consultation_outcome_stack($outcome, $cid);
        ?>
        <?php endif; ?>

        <?php if ($hasFinalizedNote): ?>
        <div class="pmh-visit__main">
          <?php if ($chiefComplaint !== ''): ?>
          <section class="pmh-visit__highlight">
            <h4 class="pmh-visit__label">Chief complaint</h4>
            <p class="pmh-visit__highlight-value"><?= htmlspecialchars($chiefComplaint) ?></p>
          </section>
          <?php endif; ?>

          <div class="pmh-visit__grid">
            <section class="pmh-visit__block pmh-visit__block--full">
              <h4 class="pmh-visit__label">Subjective</h4>
              <p><?= nl2br(htmlspecialchars(pmh_note_text($note['subjective'] ?? '', '—'))) ?></p>
            </section>
            <section class="pmh-visit__block pmh-visit__block--full">
              <h4 class="pmh-visit__label">Objective</h4>
              <p><?= nl2br(htmlspecialchars(pmh_note_text($note['objective'] ?? '', '—'))) ?></p>
            </section>
            <section class="pmh-visit__block pmh-visit__block--full">
              <h4 class="pmh-visit__label">Assessment</h4>
              <p><?= nl2br(htmlspecialchars(pmh_note_text($note['assessment'] ?? '', '—'))) ?></p>
            </section>
            <section class="pmh-visit__block pmh-visit__block--full">
              <h4 class="pmh-visit__label">Plan</h4>
              <p><?= nl2br(htmlspecialchars(pmh_note_text($note['plan'] ?? '', $recommendation))) ?></p>
            </section>
            <?php
              $signedBy = function_exists('clinical_note_signed_by_label')
                ? clinical_note_signed_by_label($note, $providerName)
                : '';
              $signedAt = function_exists('clinical_note_signed_at_label')
                ? clinical_note_signed_at_label($note)
                : '';
            ?>
            <?php if ($signedBy !== '' || $signedAt !== ''): ?>
            <section class="pmh-visit__block pmh-visit__block--full">
              <p class="pmh-soap-signed">
                <?php if ($signedBy !== ''): ?><?= htmlspecialchars($signedBy) ?><?php endif; ?>
                <?php if ($signedAt !== ''): ?><?php if ($signedBy !== ''): ?><br><?php endif; ?><?= htmlspecialchars($signedAt) ?><?php endif; ?>
              </p>
            </section>
            <?php endif; ?>
            <?php if (pmh_note_text($note['diagnosis'] ?? '', '') !== '' || $diagnosis !== 'No diagnosis recorded.'): ?>
            <section class="pmh-visit__block">
              <h4 class="pmh-visit__label">Diagnosis</h4>
              <p><?= htmlspecialchars($diagnosis) ?></p>
            </section>
            <?php endif; ?>
          </div>
        </div>

        <?php if (!empty($p_list)): ?>
        <section class="pmh-visit__rx">
          <h4 class="pmh-visit__label">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m10.5 20.5 10-10a4.95 4.95 0 1 0-7-7l-10 10a4.95 4.95 0 1 0 7 7Z"/><line x1="8.5" y1="8.5" x2="15.5" y2="15.5"/></svg>
            Prescribed medications
          </h4>
          <ul class="pmh-rx-list">
            <?php foreach ($p_list as $med): ?>
            <li>
              <strong><?= htmlspecialchars($med['medication_name']) ?></strong>
              <span><?= htmlspecialchars($med['dosage']) ?> · <?= htmlspecialchars($med['frequency']) ?></span>
            </li>
            <?php endforeach; ?>
          </ul>
        </section>
        <?php endif; ?>

        <p class="pmh-visit__actions">
          <a href="<?= ASSET_BASE ?>/views/patient/my_health.php?tab=files#health-file-<?= (int) $cid ?>" class="pmh-btn pmh-btn--primary pmh-btn--sm">View Health File</a>
          <a href="<?= ASSET_BASE ?>/views/patient/consultation_detail.php?id=<?= (int) $cid ?>" class="pmh-btn pmh-btn--outline pmh-btn--sm">Consultation details</a>
        </p>
        <?php else: ?>
        <div class="pmh-visit__main">
          <?php if ($chiefComplaint !== ''): ?>
          <section class="pmh-visit__highlight">
            <h4 class="pmh-visit__label">Chief complaint</h4>
            <p class="pmh-visit__highlight-value"><?= htmlspecialchars($chiefComplaint) ?></p>
          </section>
          <?php endif; ?>

          <?php if ($isPendingDoc): ?>
          <section class="pmh-visit__status-block">
            <h4 class="pmh-visit__label">Consultation status</h4>
            <p>Your consultation is still being documented by your provider.</p>
          </section>
          <?php elseif ($isCompleted): ?>
          <section class="pmh-visit__status-block">
            <h4 class="pmh-visit__label">Consultation status</h4>
            <p>Your consultation is complete. Your medical record will appear here once your provider finalizes documentation.</p>
          </section>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    </article>

    <?php elseif ($type === 'health_entry'):
      $entry = $row;
      $who = trim((string) ($entry['added_by'] ?? ''));
      $roleKey = strtolower((string) ($entry['role'] ?? 'patient'));
      $eventTitle = $roleKey === 'bhw'
          ? ($who !== '' && $who !== 'Unknown' ? $who : 'Barangay Health Worker')
          : 'Patient health record';
      $statusLabel = trim((string) ($entry['status_label'] ?? 'On record'));
      $fields = $entry['fields'] ?? [];
      if (!is_array($fields)) {
          $fields = [];
      }
      [$primaryField, $otherFields] = pmh_split_primary_field($fields);
      $whenParts = pmh_when_parts($entry, true);
    ?>
    <article class="pmh-visit pmh-visit--record">
      <div class="pmh-visit__rail" aria-hidden="true"><span class="pmh-visit__dot"></span></div>
      <div class="pmh-visit__body">
        <header class="pmh-visit__head">
          <div class="pmh-visit__identity">
            <?php if ($roleKey === 'bhw'): ?>
            <p class="pmh-visit__eyebrow">Patient health record</p>
            <h3 class="pmh-visit__title"><?= htmlspecialchars($eventTitle) ?></h3>
            <?php else: ?>
            <h3 class="pmh-visit__title pmh-visit__title--event">Patient health record</h3>
            <?php endif; ?>
            <?php if ($whenParts !== []): ?>
            <p class="pmh-visit__meta"><?= htmlspecialchars(implode(' · ', $whenParts)) ?></p>
            <?php endif; ?>
          </div>
          <span class="pmh-status pmh-status--default"><?= htmlspecialchars($statusLabel) ?></span>
        </header>

        <div class="pmh-visit__main">
          <?php if ($primaryField !== null): ?>
          <section class="pmh-visit__highlight">
            <h4 class="pmh-visit__label"><?= htmlspecialchars((string) ($primaryField['label'] ?? 'Chief Complaint')) ?></h4>
            <p class="pmh-visit__highlight-value"><?= htmlspecialchars((string) ($primaryField['value'] ?? '')) ?></p>
          </section>
          <?php endif; ?>

          <?php if ($otherFields !== []): ?>
          <div class="pmh-visit__grid">
            <?php foreach ($otherFields as $field): ?>
            <section class="pmh-visit__block">
              <h4 class="pmh-visit__label"><?= htmlspecialchars((string) ($field['label'] ?? '')) ?></h4>
              <p><?= htmlspecialchars((string) ($field['value'] ?? '')) ?></p>
            </section>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>

        <section class="pmh-visit__details">
          <h4 class="pmh-visit__label">Record details</h4>
          <?php require VIEWS_PATH . '/partials/bhw_activity_attribution.php'; ?>
        </section>
      </div>
    </article>

    <?php elseif ($type === 'external_visit'):
      $entry = $row;
      $who = trim((string) ($entry['added_by'] ?? ''));
      $title = $who !== '' && $who !== 'Unknown' ? $who : 'External healthcare visit';
      $fields = $entry['fields'] ?? [];
      if (!is_array($fields)) {
          $fields = [];
      }
      [$primaryField, $otherFields] = pmh_split_primary_field($fields);
      $whenParts = pmh_when_parts($entry);
    ?>
    <article class="pmh-visit pmh-visit--record">
      <div class="pmh-visit__rail" aria-hidden="true"><span class="pmh-visit__dot"></span></div>
      <div class="pmh-visit__body">
        <header class="pmh-visit__head">
          <div class="pmh-visit__identity">
            <p class="pmh-visit__eyebrow">External healthcare visit</p>
            <h3 class="pmh-visit__title"><?= htmlspecialchars($title) ?></h3>
            <?php if ($whenParts !== []): ?>
            <p class="pmh-visit__meta"><?= htmlspecialchars(implode(' · ', $whenParts)) ?></p>
            <?php endif; ?>
          </div>
          <span class="pmh-status pmh-status--default">On record</span>
        </header>

        <div class="pmh-visit__main">
          <?php if ($primaryField !== null): ?>
          <section class="pmh-visit__highlight">
            <h4 class="pmh-visit__label"><?= htmlspecialchars((string) ($primaryField['label'] ?? 'Reason')) ?></h4>
            <p class="pmh-visit__highlight-value"><?= htmlspecialchars((string) ($primaryField['value'] ?? '')) ?></p>
          </section>
          <?php endif; ?>
          <?php if ($otherFields !== []): ?>
          <div class="pmh-visit__grid">
            <?php foreach ($otherFields as $field): ?>
            <section class="pmh-visit__block pmh-visit__block--full">
              <h4 class="pmh-visit__label"><?= htmlspecialchars((string) ($field['label'] ?? '')) ?></h4>
              <p><?= htmlspecialchars((string) ($field['value'] ?? '')) ?></p>
            </section>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>

        <section class="pmh-visit__details">
          <h4 class="pmh-visit__label">Record details</h4>
          <?php require VIEWS_PATH . '/partials/bhw_activity_attribution.php'; ?>
        </section>
      </div>
    </article>

    <?php elseif ($type === 'home_visit'):
      $entry = $row;
      $who = trim((string) ($entry['added_by'] ?? $entry['bhw_name'] ?? ''));
      $title = $who !== '' && $who !== 'Unknown' ? $who : 'Barangay Health Worker';
      $whenParts = [];
      if (!empty($entry['type_label'])) {
          $whenParts[] = (string) $entry['type_label'];
      }
      if (!empty($entry['date_label']) && $entry['date_label'] !== '—') {
          $whenParts[] = (string) $entry['date_label'];
      }
    ?>
    <article class="pmh-visit pmh-visit--record">
      <div class="pmh-visit__rail" aria-hidden="true"><span class="pmh-visit__dot"></span></div>
      <div class="pmh-visit__body">
        <header class="pmh-visit__head">
          <div class="pmh-visit__identity">
            <p class="pmh-visit__eyebrow">Home visit</p>
            <h3 class="pmh-visit__title"><?= htmlspecialchars($title) ?></h3>
            <?php if ($whenParts !== []): ?>
            <p class="pmh-visit__meta"><?= htmlspecialchars(implode(' · ', $whenParts)) ?></p>
            <?php endif; ?>
          </div>
          <span class="pmh-status pmh-status--default"><?= htmlspecialchars((string) ($entry['status'] ?? 'Logged')) ?></span>
        </header>
        <?php if (!empty($entry['notes'])): ?>
        <div class="pmh-visit__main">
          <section class="pmh-visit__highlight">
            <h4 class="pmh-visit__label">Notes</h4>
            <p class="pmh-visit__highlight-value"><?= htmlspecialchars((string) $entry['notes']) ?></p>
          </section>
        </div>
        <?php endif; ?>
        <section class="pmh-visit__details">
          <h4 class="pmh-visit__label">Record details</h4>
          <?php require VIEWS_PATH . '/partials/bhw_activity_attribution.php'; ?>
        </section>
      </div>
    </article>

    <?php elseif ($type === 'document'):
      $entry = $row;
      $who = trim((string) ($entry['added_by'] ?? $entry['bhw_name'] ?? ''));
      $title = trim((string) ($entry['title'] ?? 'Document'));
      $whenParts = [];
      if (!empty($entry['type'])) {
          $whenParts[] = (string) $entry['type'];
      }
      if (!empty($entry['date_label']) && $entry['date_label'] !== '—') {
          $whenParts[] = (string) $entry['date_label'];
      }
    ?>
    <article class="pmh-visit pmh-visit--record">
      <div class="pmh-visit__rail" aria-hidden="true"><span class="pmh-visit__dot"></span></div>
      <div class="pmh-visit__body">
        <header class="pmh-visit__head">
          <div class="pmh-visit__identity">
            <p class="pmh-visit__eyebrow">Document</p>
            <h3 class="pmh-visit__title"><?= htmlspecialchars($title) ?></h3>
            <?php if ($whenParts !== []): ?>
            <p class="pmh-visit__meta"><?= htmlspecialchars(implode(' · ', $whenParts)) ?></p>
            <?php endif; ?>
          </div>
          <span class="pmh-status pmh-status--default">On file</span>
        </header>
        <?php if (!empty($entry['description'])): ?>
        <div class="pmh-visit__main">
          <section class="pmh-visit__status-block">
            <p><?= htmlspecialchars((string) $entry['description']) ?></p>
          </section>
        </div>
        <?php endif; ?>
        <section class="pmh-visit__details">
          <h4 class="pmh-visit__label">Record details</h4>
          <?php require VIEWS_PATH . '/partials/bhw_activity_attribution.php'; ?>
        </section>
      </div>
    </article>

    <?php elseif ($type === 'referral'):
      $entry = $row;
      $who = trim((string) ($entry['added_by'] ?? $entry['bhw_name'] ?? ''));
      $title = trim((string) ($entry['type'] ?? 'Referral'));
    ?>
    <article class="pmh-visit pmh-visit--record">
      <div class="pmh-visit__rail" aria-hidden="true"><span class="pmh-visit__dot"></span></div>
      <div class="pmh-visit__body">
        <header class="pmh-visit__head">
          <div class="pmh-visit__identity">
            <p class="pmh-visit__eyebrow">Referral</p>
            <h3 class="pmh-visit__title"><?= htmlspecialchars($title) ?></h3>
            <?php if (!empty($entry['date_label']) && $entry['date_label'] !== '—'): ?>
            <p class="pmh-visit__meta"><?= htmlspecialchars((string) $entry['date_label']) ?></p>
            <?php endif; ?>
          </div>
        </header>
        <div class="pmh-visit__main">
          <div class="pmh-visit__grid">
            <?php if (!empty($entry['facility'])): ?>
            <section class="pmh-visit__block pmh-visit__block--full">
              <h4 class="pmh-visit__label">Facility / service</h4>
              <p><?= htmlspecialchars((string) $entry['facility']) ?></p>
            </section>
            <?php endif; ?>
            <?php if (!empty($entry['reason'])): ?>
            <section class="pmh-visit__block pmh-visit__block--full">
              <h4 class="pmh-visit__label">Reason for referral</h4>
              <p><?= htmlspecialchars((string) $entry['reason']) ?></p>
            </section>
            <?php endif; ?>
            <?php if (!empty($entry['notes'])): ?>
            <section class="pmh-visit__block pmh-visit__block--full">
              <h4 class="pmh-visit__label">Doctor’s notes</h4>
              <p><?= htmlspecialchars((string) $entry['notes']) ?></p>
            </section>
            <?php endif; ?>
            <?php if ($who !== ''): ?>
            <section class="pmh-visit__block pmh-visit__block--full">
              <h4 class="pmh-visit__label">Issued by</h4>
              <p><?= htmlspecialchars($who) ?></p>
            </section>
            <?php endif; ?>
          </div>
        </div>
        <section class="pmh-visit__details">
          <h4 class="pmh-visit__label">Record details</h4>
          <?php require VIEWS_PATH . '/partials/bhw_activity_attribution.php'; ?>
        </section>
      </div>
    </article>

    <?php elseif ($type === 'assessment'):
      $assess = $row;
      $complaint = trim((string) ($assess['chief_complaint'] ?? ''));
      $when = !empty($assess['assessed_at']) ? date('M j, Y · g:i A', strtotime((string) $assess['assessed_at'])) : '—';
    ?>
    <article class="pmh-visit pmh-visit--record pmh-visit--assessment">
      <div class="pmh-visit__rail" aria-hidden="true"><span class="pmh-visit__dot pmh-visit__dot--assess"></span></div>
      <div class="pmh-visit__body">
        <header class="pmh-visit__head">
          <div class="pmh-visit__identity">
            <p class="pmh-visit__eyebrow">Triage assessment</p>
            <h3 class="pmh-visit__title">Preliminary assessment</h3>
            <p class="pmh-visit__meta"><?= htmlspecialchars($when) ?></p>
          </div>
          <span class="pmh-status pmh-status--scheduled">Assessment</span>
        </header>
        <?php if ($complaint !== ''): ?>
        <div class="pmh-visit__main">
          <section class="pmh-visit__highlight">
            <h4 class="pmh-visit__label">Chief complaint</h4>
            <p class="pmh-visit__highlight-value"><?= htmlspecialchars($complaint) ?></p>
          </section>
        </div>
        <?php endif; ?>
        <div class="pmh-visit__note">
          <p>Full assessment details are in your Health Summary overview.</p>
          <a href="<?= ASSET_BASE ?>/views/patient/health_summary.php" class="pmh-visit__note-link">Open Health Summary →</a>
        </div>
      </div>
    </article>
    <?php endif; ?>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
