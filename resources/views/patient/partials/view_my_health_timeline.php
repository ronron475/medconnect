<?php
/**
 * My Health — Care timeline tab (vertical rail + paired cards).
 * Expects: $care_timeline, $history (legacy fallback),
 *          $rx_by_consult, $notes_by_consult, $outcomes_by_consult,
 *          $care_tips_history (optional), $pdo, $uid (optional for chief complaint)
 */
$rx_by_consult = $rx_by_consult ?? [];
$notes_by_consult = $notes_by_consult ?? [];
$outcomes_by_consult = $outcomes_by_consult ?? [];
$care_timeline = $care_timeline ?? [];
$care_tips_history = $care_tips_history ?? [];
$bhwAttrClass = 'pmh-event-meta';

if ($care_timeline === [] && !empty($history)) {
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

if (!function_exists('pmh_is_chief_complaint_field')) {
function pmh_is_chief_complaint_field(array $field): bool {
    $key = strtolower(trim((string) ($field['key'] ?? '')));
    $label = strtolower(trim((string) ($field['label'] ?? '')));
    return $key === 'chief_complaint' || $label === 'chief complaint';
}
}

if (!function_exists('pmh_split_primary_field')) {
function pmh_split_primary_field(array $fields): array {
    $primary = null;
    $rest = [];
    foreach ($fields as $field) {
        if ($primary === null && pmh_is_chief_complaint_field($field)) {
            $primary = $field;
            continue;
        }
        $rest[] = $field;
    }
    return [$primary, $rest];
}
}

if (!function_exists('pmh_when_parts')) {
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

if (!function_exists('pmh_tl_format_when')) {
/**
 * @return array{date: string, time: string, combined: string}
 */
function pmh_tl_format_when(?string $sortAt, ?string $dateOnly = null, ?string $timeOnly = null): array {
    $date = '—';
    $time = '';
    $ts = 0;
    if ($sortAt !== null && trim($sortAt) !== '') {
        $ts = strtotime(trim($sortAt)) ?: 0;
    }
    if ($ts <= 0 && $dateOnly) {
        $combo = trim($dateOnly) . ($timeOnly ? ' ' . trim($timeOnly) : '');
        $ts = strtotime($combo) ?: 0;
    }
    if ($ts > 0) {
        $date = date('M j, Y', $ts);
        $time = date('g:i A', $ts);
    } elseif ($dateOnly) {
        $parsed = strtotime((string) $dateOnly);
        $date = $parsed ? date('M j, Y', $parsed) : (string) $dateOnly;
        if ($timeOnly) {
            $tp = explode(':', (string) $timeOnly);
            $th = (int) ($tp[0] ?? 0);
            $tm = str_pad((string) ($tp[1] ?? '00'), 2, '0', STR_PAD_LEFT);
            $time = (($th + 11) % 12 + 1) . ':' . $tm . ' ' . ($th >= 12 ? 'PM' : 'AM');
        }
    }
    $combined = $date . ($time !== '' ? ' · ' . $time : '');
    return ['date' => $date, 'time' => $time, 'combined' => $combined];
}
}

if (!function_exists('pmh_tl_vitals_from_fields')) {
/**
 * @param list<array<string, mixed>> $fields
 * @return list<array{key: string, label: string, value: string}>
 */
function pmh_tl_vitals_from_fields(array $fields): array {
    $want = [
        'blood_pressure' => 'Blood Pressure',
        'pulse_bpm' => 'Heart Rate',
        'temperature_c' => 'Temperature',
    ];
    $byKey = [];
    foreach ($fields as $field) {
        $key = (string) ($field['key'] ?? '');
        if ($key !== '' && isset($want[$key])) {
            $byKey[$key] = [
                'key' => $key,
                'label' => $want[$key],
                'value' => (string) ($field['value'] ?? ''),
            ];
        }
    }
    $out = [];
    foreach ($want as $key => $_label) {
        if (isset($byKey[$key]) && trim($byKey[$key]['value']) !== '') {
            $out[] = $byKey[$key];
        }
    }
    return $out;
}
}
?>
<?php if (!$hasTimeline): ?>
  <div class="pmh-empty pmh-empty--minimal mx-auto w-full max-w-3xl">
    <?php if ($hasCareTips): ?>
    <h3>No care visits yet</h3>
    <p>
      Your timeline will list assessments, visits, and health records here.
      Related guidance is under
      <a href="<?= ASSET_BASE ?>/views/patient/my_health.php?tab=care-tips">Care Tips</a>
      or in your
      <a href="<?= ASSET_BASE ?>/views/patient/health_summary.php">Health Summary</a>.
    </p>
    <a href="<?= ASSET_BASE ?>/views/patient/triage.php" class="pmh-btn pmh-btn--primary">Book a consultation</a>
    <?php else: ?>
    <h3>No care history yet</h3>
    <p>After your first assessment or visit, events will appear here with the date and what happened.</p>
    <a href="<?= ASSET_BASE ?>/views/patient/triage.php" class="pmh-btn pmh-btn--primary">Book a consultation</a>
    <?php endif; ?>
  </div>
<?php else: ?>
  <div class="pmh-tl-wrap">
    <p class="pmh-section-lead">Your care history in order — what happened and when.</p>
    <div class="pmh-tl-toolbar">
      <label class="pmh-tl-filter">
        <span class="sr-only">Filter timeline events</span>
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
        <select id="pmh-tl-filter" data-pmh-tl-filter>
          <option value="all">All events</option>
          <option value="consultation">Consultations</option>
          <option value="assessment">Triage assessments</option>
          <option value="health_entry">Health records</option>
          <option value="referral">Referrals</option>
        </select>
      </label>
    </div>

    <ol class="pmh-tl" id="pmh-tl-list">
      <?php foreach ($care_timeline as $item):
        $type = (string) ($item['type'] ?? '');
        $row = $item['data'] ?? [];
        if (!is_array($row)) {
            continue;
        }
        $sortAt = (string) ($item['sort_at'] ?? '');
        $filterType = in_array($type, ['external_visit', 'home_visit', 'document'], true) ? 'health_entry' : $type;
      ?>

      <?php if ($type === 'consultation'):
        $h = $row;
        $cid = (int) ($h['id'] ?? 0);
        $note = $notes_by_consult[$cid] ?? null;
        $status = (string) ($h['status'] ?? 'pending');
        $statusLabel = match (strtolower($status)) {
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            'in_consultation' => 'In session',
            'scheduled' => 'Scheduled',
            'pending' => 'Pending',
            default => ucwords(str_replace('_', ' ', $status)),
        };
        $isCompleted = strtolower($status) === 'completed';
        $hasFinalizedNote = $isCompleted && !empty($note);
        $docStatus = $hasFinalizedNote ? 'Notes ready' : ($isCompleted ? 'Notes pending' : 'In progress');
        $docStatusClass = $hasFinalizedNote ? 'pmh-status--completed' : ($isCompleted ? 'pmh-status--scheduled' : 'pmh-status--default');
        $providerName = trim(($h['first_name'] ?? '') . ' ' . ($h['last_name'] ?? ''));
        $when = pmh_tl_format_when($sortAt, $h['consult_date'] ?? null, $h['consult_time'] ?? null);
        $specialty = trim((string) ($h['consult_type'] ?? ''));
        if ($specialty === '' || strcasecmp($specialty, 'General Consultation') === 0) {
            $specialty = 'General Practitioner';
        }
        $chiefComplaint = '';
        if (isset($pdo) && isset($uid) && function_exists('patient_session_chief_complaint')) {
            $chiefComplaint = patient_session_chief_complaint($pdo, (int) $uid, $h);
        }
        if ($chiefComplaint === '') {
            $ct = trim((string) ($h['consult_type'] ?? ''));
            if ($ct !== '' && strcasecmp($ct, 'General Consultation') !== 0 && strcasecmp($ct, 'General Practitioner') !== 0) {
                $chiefComplaint = $ct;
            }
        }
      ?>
      <li class="pmh-tl__item pmh-tl__item--consultation" data-tl-type="consultation">
        <div class="pmh-tl__when">
          <span class="pmh-tl__date"><?= htmlspecialchars($when['date']) ?></span>
          <?php if ($when['time'] !== ''): ?><span class="pmh-tl__time"><?= htmlspecialchars($when['time']) ?></span><?php endif; ?>
        </div>
        <div class="pmh-tl__rail" aria-hidden="true">
          <span class="pmh-tl__node">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4.8 2.3A.25.25 0 0 0 5 2.25h1.5a.25.25 0 0 1 .25.25v1.5a.25.25 0 0 0 .25.25h1.5a.25.25 0 0 1 .25.25V6"/><path d="M20 15.5V7a2 2 0 0 0-2-2h-3"/><path d="M14 3v4h4"/><circle cx="10" cy="16" r="6"/><path d="M10 13v6"/><path d="M7 16h6"/></svg>
          </span>
        </div>
        <div class="pmh-tl__cards">
          <article class="pmh-tl-card pmh-tl-card--summary">
            <div class="pmh-tl-card__top">
              <span class="pmh-tl-badge pmh-tl-badge--consult">Consultation</span>
              <span class="pmh-status <?= pmh_status_class($status) ?>"><?= htmlspecialchars($statusLabel) ?></span>
            </div>
            <h4 class="pmh-tl-card__title">Dr. <?= htmlspecialchars($providerName !== '' ? $providerName : 'Provider') ?></h4>
            <p class="pmh-tl-card__sub"><?= htmlspecialchars($specialty) ?></p>
          </article>
          <article class="pmh-tl-card pmh-tl-card--detail">
            <div class="pmh-tl-card__top">
              <h4 class="pmh-tl-card__heading">Visit details</h4>
              <span class="pmh-status <?= $docStatusClass ?>"><?= htmlspecialchars($docStatus) ?></span>
            </div>
            <?php if ($chiefComplaint !== ''): ?>
            <div class="pmh-tl-highlight">
              <span class="pmh-tl-highlight__icon" aria-hidden="true">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
              </span>
              <div>
                <span class="pmh-tl-highlight__label">Reason for visit</span>
                <p class="pmh-tl-highlight__value"><?= htmlspecialchars($chiefComplaint) ?></p>
              </div>
            </div>
            <?php elseif ($hasFinalizedNote): ?>
            <p class="pmh-tl-card__copy">Your provider has finalized notes for this visit. Open the health file for the full record.</p>
            <?php elseif ($isCompleted): ?>
            <p class="pmh-tl-card__copy">This visit is complete. Notes will appear once your provider finalizes them.</p>
            <?php else: ?>
            <p class="pmh-tl-card__copy">Your provider is still documenting this visit.</p>
            <?php endif; ?>
            <?php if ($cid > 0): ?>
            <p class="pmh-tl-card__actions">
              <?php if ($hasFinalizedNote): ?>
              <a href="<?= ASSET_BASE ?>/views/patient/my_health.php?tab=files#health-file-<?= (int) $cid ?>" class="pmh-tl-link">View health file →</a>
              <?php endif; ?>
              <a href="<?= ASSET_BASE ?>/views/patient/consultation_detail.php?id=<?= (int) $cid ?>" class="pmh-tl-link">View consultation details →</a>
            </p>
            <?php endif; ?>
          </article>
        </div>
      </li>

      <?php elseif ($type === 'assessment'):
        $assess = $row;
        $complaint = trim((string) ($assess['chief_complaint'] ?? ''));
        $when = pmh_tl_format_when($sortAt, null, null);
        if ($when['date'] === '—' && !empty($assess['assessed_at'])) {
            $when = pmh_tl_format_when((string) $assess['assessed_at']);
        }
      ?>
      <li class="pmh-tl__item pmh-tl__item--assessment" data-tl-type="assessment">
        <div class="pmh-tl__when">
          <span class="pmh-tl__date"><?= htmlspecialchars($when['date']) ?></span>
          <?php if ($when['time'] !== ''): ?><span class="pmh-tl__time"><?= htmlspecialchars($when['time']) ?></span><?php endif; ?>
        </div>
        <div class="pmh-tl__rail" aria-hidden="true">
          <span class="pmh-tl__node">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 12h6"/><path d="M9 16h6"/></svg>
          </span>
        </div>
        <div class="pmh-tl__cards">
          <article class="pmh-tl-card pmh-tl-card--summary">
            <div class="pmh-tl-card__top">
              <span class="pmh-tl-badge pmh-tl-badge--assess">Triage Assessment</span>
              <span class="pmh-status pmh-status--scheduled">Recorded</span>
            </div>
            <h4 class="pmh-tl-card__title"><?= htmlspecialchars($complaint !== '' ? $complaint : 'Symptom check') ?></h4>
            <p class="pmh-tl-card__sub">Preliminary triage before a consultation</p>
          </article>
          <article class="pmh-tl-card pmh-tl-card--detail">
            <div class="pmh-tl-card__top">
              <h4 class="pmh-tl-card__heading">Assessment summary</h4>
            </div>
            <p class="pmh-tl-card__copy">This was a triage check of your symptoms. Open Health Summary for the full overview and next steps.</p>
            <p class="pmh-tl-card__actions">
              <a href="<?= ASSET_BASE ?>/views/patient/health_summary.php" class="pmh-tl-link pmh-tl-link--accent">Open Health Summary →</a>
            </p>
          </article>
        </div>
      </li>

      <?php elseif ($type === 'health_entry'):
        $entry = $row;
        $who = trim((string) ($entry['added_by'] ?? ''));
        $roleKey = strtolower((string) ($entry['role'] ?? 'patient'));
        $eventTitle = $roleKey === 'bhw'
            ? ($who !== '' && $who !== 'Unknown' ? $who : 'Barangay Health Worker')
            : 'Health measurements';
        $statusLabel = trim((string) ($entry['status_label'] ?? 'On record'));
        if ($statusLabel === '' || strcasecmp($statusLabel, 'On record') === 0) {
            $statusLabel = 'On record';
        }
        $fields = is_array($entry['fields'] ?? null) ? $entry['fields'] : [];
        [$primaryField, $otherFields] = pmh_split_primary_field($fields);
        $vitals = pmh_tl_vitals_from_fields($fields);
        $when = pmh_tl_format_when($sortAt);
        if ($when['date'] === '—') {
            $whenParts = pmh_when_parts($entry);
            $when['combined'] = $whenParts !== [] ? implode(' · ', $whenParts) : '—';
            $when['date'] = (string) ($entry['date_label'] ?? '—');
            $when['time'] = (string) ($entry['time_label'] ?? '');
        }
        $recordedBy = $roleKey === 'bhw'
            ? 'Recorded during a community health visit'
            : ($who !== '' && $who !== 'Unknown' ? 'Recorded by ' . $who : '');
      ?>
      <li class="pmh-tl__item pmh-tl__item--record" data-tl-type="health_entry">
        <div class="pmh-tl__when">
          <span class="pmh-tl__date"><?= htmlspecialchars($when['date']) ?></span>
          <?php if ($when['time'] !== ''): ?><span class="pmh-tl__time"><?= htmlspecialchars($when['time']) ?></span><?php endif; ?>
        </div>
        <div class="pmh-tl__rail" aria-hidden="true">
          <span class="pmh-tl__node">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
          </span>
        </div>
        <div class="pmh-tl__cards">
          <article class="pmh-tl-card pmh-tl-card--summary">
            <div class="pmh-tl-card__top">
              <span class="pmh-tl-badge pmh-tl-badge--record">Health Record</span>
              <span class="pmh-status pmh-status--default"><?= htmlspecialchars($statusLabel) ?></span>
            </div>
            <h4 class="pmh-tl-card__title"><?= htmlspecialchars($eventTitle) ?></h4>
            <?php if ($recordedBy !== ''): ?><p class="pmh-tl-card__sub"><?= htmlspecialchars($recordedBy) ?></p><?php endif; ?>
          </article>
          <article class="pmh-tl-card pmh-tl-card--detail">
            <div class="pmh-tl-card__top">
              <h4 class="pmh-tl-card__heading">Recorded details</h4>
            </div>
            <?php if ($primaryField !== null): ?>
            <div class="pmh-tl-highlight">
              <span class="pmh-tl-highlight__icon" aria-hidden="true">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
              </span>
              <div>
                <span class="pmh-tl-highlight__label">Reason for visit</span>
                <p class="pmh-tl-highlight__value"><?= htmlspecialchars((string) ($primaryField['value'] ?? '')) ?></p>
              </div>
            </div>
            <?php endif; ?>
            <?php if ($vitals !== []): ?>
            <div class="pmh-tl-vitals" role="list">
              <?php foreach ($vitals as $vital): ?>
              <div class="pmh-tl-vital" role="listitem" data-vital="<?= htmlspecialchars($vital['key']) ?>">
                <span class="pmh-tl-vital__icon" aria-hidden="true">
                  <?php if ($vital['key'] === 'blood_pressure'): ?>
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg>
                  <?php elseif ($vital['key'] === 'pulse_bpm'): ?>
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                  <?php else: ?>
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 4v10.54a4 4 0 1 1-4 0V4a2 2 0 0 1 4 0Z"/></svg>
                  <?php endif; ?>
                </span>
                <?php
                  $vitalVal = (string) $vital['value'];
                  if ($vital['key'] === 'blood_pressure' && $vitalVal !== '' && stripos($vitalVal, 'mmhg') === false) {
                      $vitalVal .= ' mmHg';
                  }
                ?>
                <span class="pmh-tl-vital__text"><strong><?= htmlspecialchars($vital['label']) ?>:</strong> <?= htmlspecialchars($vitalVal) ?></span>
              </div>
              <?php endforeach; ?>
            </div>
            <?php elseif ($otherFields !== []): ?>
            <div class="pmh-tl-field-list">
              <?php foreach (array_slice($otherFields, 0, 4) as $field): ?>
              <div class="pmh-tl-field">
                <span class="pmh-tl-field__label"><?= htmlspecialchars((string) ($field['label'] ?? '')) ?></span>
                <span class="pmh-tl-field__value"><?= htmlspecialchars((string) ($field['value'] ?? '')) ?></span>
              </div>
              <?php endforeach; ?>
            </div>
            <?php elseif ($primaryField === null): ?>
            <p class="pmh-tl-card__copy">No additional measurements were listed for this entry.</p>
            <?php endif; ?>
          </article>
        </div>
      </li>

      <?php elseif ($type === 'referral'):
        $entry = $row;
        $who = trim((string) ($entry['added_by'] ?? $entry['bhw_name'] ?? ''));
        $title = trim((string) ($entry['type'] ?? 'Referral'));
        $when = pmh_tl_format_when($sortAt);
        if ($when['date'] === '—' && !empty($entry['date_label'])) {
            $when['date'] = (string) $entry['date_label'];
            $when['combined'] = $when['date'];
        }
      ?>
      <li class="pmh-tl__item pmh-tl__item--referral" data-tl-type="referral">
        <div class="pmh-tl__when">
          <span class="pmh-tl__date"><?= htmlspecialchars($when['date']) ?></span>
          <?php if ($when['time'] !== ''): ?><span class="pmh-tl__time"><?= htmlspecialchars($when['time']) ?></span><?php endif; ?>
        </div>
        <div class="pmh-tl__rail" aria-hidden="true">
          <span class="pmh-tl__node">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><path d="M15 3h6v6"/><path d="m10 14 11-11"/></svg>
          </span>
        </div>
        <div class="pmh-tl__cards">
          <article class="pmh-tl-card pmh-tl-card--summary">
            <div class="pmh-tl-card__top">
              <span class="pmh-tl-badge pmh-tl-badge--referral">Referral</span>
              <span class="pmh-status pmh-status--default">Sent</span>
            </div>
            <h4 class="pmh-tl-card__title"><?= htmlspecialchars($title !== '' ? $title : 'Referral') ?></h4>
            <?php if ($who !== ''): ?><p class="pmh-tl-card__sub">From <?= htmlspecialchars($who) ?></p><?php endif; ?>
          </article>
          <article class="pmh-tl-card pmh-tl-card--detail">
            <div class="pmh-tl-card__top">
              <h4 class="pmh-tl-card__heading">Where you were referred</h4>
            </div>
            <?php if (!empty($entry['facility'])): ?>
            <p class="pmh-tl-card__copy"><strong>Facility:</strong> <?= htmlspecialchars((string) $entry['facility']) ?></p>
            <?php endif; ?>
            <?php if (!empty($entry['reason'])): ?>
            <p class="pmh-tl-card__copy"><strong>Reason:</strong> <?= htmlspecialchars((string) $entry['reason']) ?></p>
            <?php endif; ?>
            <?php if (!empty($entry['notes'])): ?>
            <p class="pmh-tl-card__copy"><?= htmlspecialchars((string) $entry['notes']) ?></p>
            <?php endif; ?>
            <?php if (empty($entry['facility']) && empty($entry['reason']) && empty($entry['notes'])): ?>
            <p class="pmh-tl-card__copy">Referral details are on file. Check Health Files for related documents.</p>
            <?php endif; ?>
          </article>
        </div>
      </li>

      <?php elseif (in_array($type, ['external_visit', 'home_visit', 'document'], true)):
        $entry = $row;
        $when = pmh_tl_format_when($sortAt);
        if ($type === 'external_visit') {
            $who = trim((string) ($entry['added_by'] ?? ''));
            $title = $who !== '' && $who !== 'Unknown' ? $who : 'External healthcare visit';
            $eyebrow = 'External Visit';
            $detailHeading = 'Visit details';
            $fields = is_array($entry['fields'] ?? null) ? $entry['fields'] : [];
            [$primaryField, $otherFields] = pmh_split_primary_field($fields);
        } elseif ($type === 'home_visit') {
            $who = trim((string) ($entry['added_by'] ?? $entry['bhw_name'] ?? ''));
            $title = $who !== '' && $who !== 'Unknown' ? $who : 'Barangay Health Worker';
            $eyebrow = 'Home Visit';
            $detailHeading = 'Visit notes';
            $primaryField = null;
            $otherFields = !empty($entry['notes']) ? [['label' => 'Notes', 'value' => $entry['notes']]] : [];
        } else {
            $title = trim((string) ($entry['title'] ?? 'Document'));
            $eyebrow = 'Document';
            $detailHeading = 'Document details';
            $primaryField = null;
            $otherFields = !empty($entry['description']) ? [['label' => 'Details', 'value' => $entry['description']]] : [];
        }
        if ($when['date'] === '—' && !empty($entry['date_label'])) {
            $when['date'] = (string) $entry['date_label'];
            $when['combined'] = $when['date'];
        }
      ?>
      <li class="pmh-tl__item pmh-tl__item--record" data-tl-type="health_entry">
        <div class="pmh-tl__when">
          <span class="pmh-tl__date"><?= htmlspecialchars($when['date']) ?></span>
          <?php if ($when['time'] !== ''): ?><span class="pmh-tl__time"><?= htmlspecialchars($when['time']) ?></span><?php endif; ?>
        </div>
        <div class="pmh-tl__rail" aria-hidden="true">
          <span class="pmh-tl__node">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
          </span>
        </div>
        <div class="pmh-tl__cards">
          <article class="pmh-tl-card pmh-tl-card--summary">
            <div class="pmh-tl-card__top">
              <span class="pmh-tl-badge pmh-tl-badge--record"><?= htmlspecialchars($eyebrow) ?></span>
              <span class="pmh-status pmh-status--default">On record</span>
            </div>
            <h4 class="pmh-tl-card__title"><?= htmlspecialchars($title) ?></h4>
          </article>
          <article class="pmh-tl-card pmh-tl-card--detail">
            <div class="pmh-tl-card__top">
              <h4 class="pmh-tl-card__heading"><?= htmlspecialchars($detailHeading) ?></h4>
            </div>
            <?php if ($primaryField !== null): ?>
            <div class="pmh-tl-highlight">
              <span class="pmh-tl-highlight__icon" aria-hidden="true">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
              </span>
              <div>
                <span class="pmh-tl-highlight__label">Reason for visit</span>
                <p class="pmh-tl-highlight__value"><?= htmlspecialchars((string) ($primaryField['value'] ?? '')) ?></p>
              </div>
            </div>
            <?php endif; ?>
            <?php foreach (array_slice($otherFields, 0, 3) as $field): ?>
            <p class="pmh-tl-card__copy"><strong><?= htmlspecialchars((string) ($field['label'] ?? '')) ?>:</strong> <?= htmlspecialchars((string) ($field['value'] ?? '')) ?></p>
            <?php endforeach; ?>
            <?php if ($primaryField === null && $otherFields === []): ?>
            <p class="pmh-tl-card__copy">This activity is saved on your care timeline.</p>
            <?php endif; ?>
          </article>
        </div>
      </li>
      <?php endif; ?>
      <?php endforeach; ?>
    </ol>
  </div>
  <script>
  (function () {
    var sel = document.querySelector('[data-pmh-tl-filter]');
    if (!sel) return;
    sel.addEventListener('change', function () {
      var type = sel.value;
      document.querySelectorAll('#pmh-tl-list > .pmh-tl__item').forEach(function (item) {
        item.hidden = !(type === 'all' || item.getAttribute('data-tl-type') === type);
      });
    });
  })();
  </script>
<?php endif; ?>
