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
$bhwAttrClass = 'pmh-bhw__attr';

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

if (!function_exists('pmh_provider_initials')) {
function pmh_provider_initials(?string $first, ?string $last): string {
    $f = mb_substr(trim((string) $first), 0, 1);
    $l = mb_substr(trim((string) $last), 0, 1);
    return strtoupper($f . $l) ?: 'DR';
}
}

if (!function_exists('pmh_note_text')) {
function pmh_note_text(?string $value, string $fallback = ''): string {
    $v = trim((string) $value);
    return $v !== '' ? $v : $fallback;
}
}

if (!function_exists('pmh_timeline_initials')) {
function pmh_timeline_initials(string $name, string $fallback = 'HX'): string {
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $letters = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $letters .= mb_substr($part, 0, 1);
    }
    return strtoupper($letters !== '' ? $letters : $fallback);
}
}
?>
<?php if (!$hasTimeline): ?>
  <div class="pmh-empty pmh-empty--minimal">
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
  <div class="pmh-feed pmh-feed--timeline">
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
    ?>
    <article class="pmh-visit">
      <div class="pmh-visit__rail" aria-hidden="true"><span class="pmh-visit__dot"></span></div>
      <div class="pmh-visit__body">
        <header class="pmh-visit__head">
          <div class="pmh-visit__provider">
            <span class="pmh-visit__avatar"><?= htmlspecialchars(pmh_provider_initials($h['first_name'] ?? '', $h['last_name'] ?? '')) ?></span>
            <div>
              <h3 class="pmh-visit__title">Dr. <?= htmlspecialchars(trim(($h['first_name'] ?? '') . ' ' . ($h['last_name'] ?? ''))) ?></h3>
              <p class="pmh-visit__meta">
                <time datetime="<?= htmlspecialchars($h['consult_date'] ?? '') ?>">
                  <?= !empty($h['consult_date']) ? date('M j, Y', strtotime($h['consult_date'])) : '—' ?>
                </time>
                <?php if ($timeLabel): ?> · <?= htmlspecialchars($timeLabel) ?><?php endif; ?>
                <?php if (!empty($h['consult_type'])): ?> · <?= htmlspecialchars($h['consult_type']) ?><?php endif; ?>
              </p>
            </div>
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
        <div class="pmh-visit__grid">
          <?php if ($chiefComplaint !== ''): ?>
          <section class="pmh-visit__block pmh-visit__block--full">
            <h4 class="pmh-visit__label">Patient complaint</h4>
            <p><?= htmlspecialchars($chiefComplaint) ?></p>
          </section>
          <?php endif; ?>
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
              ? clinical_note_signed_by_label($note, trim((string) (($h['first_name'] ?? '') . ' ' . ($h['last_name'] ?? ''))))
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

        <p class="pmh-visit__actions">
          <a href="<?= ASSET_BASE ?>/views/patient/my_health.php?tab=files#health-file-<?= (int) $cid ?>" class="pmh-btn pmh-btn--primary pmh-btn--sm">View Health File</a>
          <a href="<?= ASSET_BASE ?>/views/patient/consultation_detail.php?id=<?= (int) $cid ?>" class="pmh-btn pmh-btn--outline pmh-btn--sm">Consultation details</a>
        </p>
        <?php elseif (in_array(strtolower($status), ['in_consultation', 'scheduled', 'pending'], true)): ?>
        <div class="pmh-visit__pending">
          <p>Your consultation is still being documented by your provider.</p>
        </div>
        <?php elseif ($isCompleted): ?>
        <div class="pmh-visit__pending">
          <p>Your consultation is complete. Your medical record will appear here once your provider finalizes documentation.</p>
        </div>
        <?php endif; ?>

        <?php if ($hasFinalizedNote && !empty($p_list)): ?>
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
      </div>
    </article>

    <?php elseif ($type === 'health_entry'):
      $entry = $row;
      $who = trim((string) ($entry['added_by'] ?? ''));
      $roleKey = strtolower((string) ($entry['role'] ?? 'patient'));
      $title = $roleKey === 'bhw' ? ($who !== '' && $who !== 'Unknown' ? $who : 'Barangay Health Worker') : 'Patient health record';
      $avatar = $roleKey === 'bhw' ? pmh_timeline_initials($who, 'BH') : 'PT';
      $statusLabel = trim((string) ($entry['status_label'] ?? 'On record'));
    ?>
    <article class="pmh-visit pmh-visit--record">
      <div class="pmh-visit__rail" aria-hidden="true"><span class="pmh-visit__dot"></span></div>
      <div class="pmh-visit__body">
        <header class="pmh-visit__head">
          <div class="pmh-visit__provider">
            <span class="pmh-visit__avatar"><?= htmlspecialchars($avatar) ?></span>
            <div>
              <h3 class="pmh-visit__title"><?= htmlspecialchars($title) ?></h3>
              <p class="pmh-visit__meta">
                Health measurements
                <?php if (!empty($entry['date_label']) && $entry['date_label'] !== '—'): ?>
                  · <?= htmlspecialchars((string) $entry['date_label']) ?>
                <?php endif; ?>
                <?php if (!empty($entry['time_label']) && $entry['time_label'] !== '—'): ?>
                  · <?= htmlspecialchars((string) $entry['time_label']) ?>
                <?php endif; ?>
              </p>
            </div>
          </div>
          <span class="pmh-status pmh-status--default"><?= htmlspecialchars($statusLabel) ?></span>
        </header>
        <div class="pmh-visit__grid">
          <?php foreach (($entry['fields'] ?? []) as $field): ?>
          <section class="pmh-visit__block">
            <h4 class="pmh-visit__label"><?= htmlspecialchars((string) ($field['label'] ?? '')) ?></h4>
            <p><?= htmlspecialchars((string) ($field['value'] ?? '')) ?></p>
          </section>
          <?php endforeach; ?>
        </div>
        <?php require VIEWS_PATH . '/partials/bhw_activity_attribution.php'; ?>
      </div>
    </article>

    <?php elseif ($type === 'external_visit'):
      $entry = $row;
      $who = trim((string) ($entry['added_by'] ?? ''));
      $roleKey = strtolower((string) ($entry['role'] ?? 'bhw'));
      $title = $who !== '' && $who !== 'Unknown' ? $who : 'External healthcare visit';
    ?>
    <article class="pmh-visit pmh-visit--record">
      <div class="pmh-visit__rail" aria-hidden="true"><span class="pmh-visit__dot"></span></div>
      <div class="pmh-visit__body">
        <header class="pmh-visit__head">
          <div class="pmh-visit__provider">
            <span class="pmh-visit__avatar"><?= htmlspecialchars(pmh_timeline_initials($who, $roleKey === 'patient' ? 'PT' : 'BH')) ?></span>
            <div>
              <h3 class="pmh-visit__title"><?= htmlspecialchars($title) ?></h3>
              <p class="pmh-visit__meta">
                External healthcare visit
                <?php if (!empty($entry['date_label']) && $entry['date_label'] !== '—'): ?>
                  · <?= htmlspecialchars((string) $entry['date_label']) ?>
                <?php endif; ?>
                <?php if (!empty($entry['time_label']) && $entry['time_label'] !== '—'): ?>
                  · <?= htmlspecialchars((string) $entry['time_label']) ?>
                <?php endif; ?>
              </p>
            </div>
          </div>
          <span class="pmh-status pmh-status--default">On record</span>
        </header>
        <div class="pmh-visit__grid">
          <?php foreach (($entry['fields'] ?? []) as $field): ?>
          <section class="pmh-visit__block pmh-visit__block--full">
            <h4 class="pmh-visit__label"><?= htmlspecialchars((string) ($field['label'] ?? '')) ?></h4>
            <p><?= htmlspecialchars((string) ($field['value'] ?? '')) ?></p>
          </section>
          <?php endforeach; ?>
        </div>
        <?php require VIEWS_PATH . '/partials/bhw_activity_attribution.php'; ?>
      </div>
    </article>

    <?php elseif ($type === 'home_visit'):
      $entry = $row;
      $who = trim((string) ($entry['added_by'] ?? $entry['bhw_name'] ?? ''));
      $title = $who !== '' && $who !== 'Unknown' ? $who : 'Barangay Health Worker';
    ?>
    <article class="pmh-visit pmh-visit--record">
      <div class="pmh-visit__rail" aria-hidden="true"><span class="pmh-visit__dot"></span></div>
      <div class="pmh-visit__body">
        <header class="pmh-visit__head">
          <div class="pmh-visit__provider">
            <span class="pmh-visit__avatar"><?= htmlspecialchars(pmh_timeline_initials($who, 'BH')) ?></span>
            <div>
              <h3 class="pmh-visit__title"><?= htmlspecialchars($title) ?></h3>
              <p class="pmh-visit__meta">
                Home visit<?= !empty($entry['type_label']) ? ' · ' . htmlspecialchars((string) $entry['type_label']) : '' ?>
                <?php if (!empty($entry['date_label']) && $entry['date_label'] !== '—'): ?>
                  · <?= htmlspecialchars((string) $entry['date_label']) ?>
                <?php endif; ?>
              </p>
            </div>
          </div>
          <span class="pmh-status pmh-status--default"><?= htmlspecialchars((string) ($entry['status'] ?? 'Logged')) ?></span>
        </header>
        <?php if (!empty($entry['notes'])): ?>
        <div class="pmh-visit__grid">
          <section class="pmh-visit__block pmh-visit__block--full">
            <h4 class="pmh-visit__label">Notes</h4>
            <p><?= htmlspecialchars((string) $entry['notes']) ?></p>
          </section>
        </div>
        <?php endif; ?>
        <?php require VIEWS_PATH . '/partials/bhw_activity_attribution.php'; ?>
      </div>
    </article>

    <?php elseif ($type === 'document'):
      $entry = $row;
      $who = trim((string) ($entry['added_by'] ?? $entry['bhw_name'] ?? ''));
      $title = trim((string) ($entry['title'] ?? 'Document'));
    ?>
    <article class="pmh-visit pmh-visit--record">
      <div class="pmh-visit__rail" aria-hidden="true"><span class="pmh-visit__dot"></span></div>
      <div class="pmh-visit__body">
        <header class="pmh-visit__head">
          <div class="pmh-visit__provider">
            <span class="pmh-visit__avatar"><?= htmlspecialchars(pmh_timeline_initials($who, 'BH')) ?></span>
            <div>
              <h3 class="pmh-visit__title"><?= htmlspecialchars($title) ?></h3>
              <p class="pmh-visit__meta">
                Document<?= !empty($entry['type']) ? ' · ' . htmlspecialchars((string) $entry['type']) : '' ?>
                <?php if (!empty($entry['date_label']) && $entry['date_label'] !== '—'): ?>
                  · <?= htmlspecialchars((string) $entry['date_label']) ?>
                <?php endif; ?>
              </p>
            </div>
          </div>
          <span class="pmh-status pmh-status--default">On file</span>
        </header>
        <?php if (!empty($entry['description'])): ?>
        <div class="pmh-visit__pending"><p><?= htmlspecialchars((string) $entry['description']) ?></p></div>
        <?php endif; ?>
        <?php require VIEWS_PATH . '/partials/bhw_activity_attribution.php'; ?>
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
          <div class="pmh-visit__provider">
            <span class="pmh-visit__avatar"><?= htmlspecialchars(pmh_timeline_initials($who, 'BH')) ?></span>
            <div>
              <h3 class="pmh-visit__title"><?= htmlspecialchars($title) ?></h3>
              <p class="pmh-visit__meta">
                Referral
                <?php if (!empty($entry['date_label']) && $entry['date_label'] !== '—'): ?>
                  · <?= htmlspecialchars((string) $entry['date_label']) ?>
                <?php endif; ?>
              </p>
            </div>
          </div>
          <span class="pmh-status pmh-status--default"><?= htmlspecialchars((string) ($entry['status'] ?? 'Pending')) ?></span>
        </header>
        <div class="pmh-visit__grid">
          <?php if (!empty($entry['facility'])): ?>
          <section class="pmh-visit__block pmh-visit__block--full">
            <h4 class="pmh-visit__label">Facility</h4>
            <p><?= htmlspecialchars((string) $entry['facility']) ?></p>
          </section>
          <?php endif; ?>
          <?php if (!empty($entry['reason'])): ?>
          <section class="pmh-visit__block pmh-visit__block--full">
            <h4 class="pmh-visit__label">Reason</h4>
            <p><?= htmlspecialchars((string) $entry['reason']) ?></p>
          </section>
          <?php endif; ?>
        </div>
        <?php require VIEWS_PATH . '/partials/bhw_activity_attribution.php'; ?>
      </div>
    </article>

    <?php elseif ($type === 'assessment'):
      $assess = $row;
      $complaint = trim((string) ($assess['chief_complaint'] ?? ''));
      $when = !empty($assess['assessed_at']) ? date('M j, Y g:i A', strtotime((string) $assess['assessed_at'])) : '—';
    ?>
    <article class="pmh-visit pmh-visit--record">
      <div class="pmh-visit__rail" aria-hidden="true"><span class="pmh-visit__dot"></span></div>
      <div class="pmh-visit__body">
        <header class="pmh-visit__head">
          <div class="pmh-visit__provider">
            <span class="pmh-visit__avatar">TA</span>
            <div>
              <h3 class="pmh-visit__title">Triage assessment</h3>
              <p class="pmh-visit__meta">
                <?= htmlspecialchars($when) ?>
                <?php if ($complaint !== ''): ?> · <?= htmlspecialchars($complaint) ?><?php endif; ?>
              </p>
            </div>
          </div>
          <span class="pmh-status pmh-status--default">Assessment</span>
        </header>
        <div class="pmh-visit__pending">
          <p>Full assessment details are kept in your Health Summary overview.</p>
        </div>
        <p class="pmh-visit__actions">
          <a href="<?= ASSET_BASE ?>/views/patient/health_summary.php" class="pmh-btn pmh-btn--outline pmh-btn--sm">Open Health Summary</a>
        </p>
      </div>
    </article>
    <?php endif; ?>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
