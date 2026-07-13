<?php
/**
 * My Health — Care timeline tab.
 * Expects: $history, $rx_by_consult, $notes_by_consult (optional)
 */
$rx_by_consult = $rx_by_consult ?? [];
$notes_by_consult = $notes_by_consult ?? [];

function pmh_status_class(string $status): string {
    $s = strtolower(trim($status));
    if ($s === 'completed') return 'pmh-status--completed';
    if ($s === 'cancelled') return 'pmh-status--cancelled';
    if ($s === 'in_consultation') return 'pmh-status--live';
    if (in_array($s, ['scheduled', 'pending'], true)) return 'pmh-status--scheduled';
    return 'pmh-status--default';
}

function pmh_provider_initials(?string $first, ?string $last): string {
    $f = mb_substr(trim((string) $first), 0, 1);
    $l = mb_substr(trim((string) $last), 0, 1);
    return strtoupper($f . $l) ?: 'DR';
}

function pmh_note_text(?string $value, string $fallback = ''): string {
    $v = trim((string) $value);
    return $v !== '' ? $v : $fallback;
}
?>
<?php if (empty($history)): ?>
  <div class="pmh-empty">
    <div class="pmh-empty__icon" aria-hidden="true">
      <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
    </div>
    <h3>No visit history yet</h3>
    <p>After your first consultation, your care timeline will appear here with diagnosis notes and prescriptions.</p>
    <a href="<?= ASSET_BASE ?>/views/patient/triage.php" class="pmh-btn pmh-btn--primary">Book Consultation</a>
  </div>
<?php else: ?>
  <div class="pmh-timeline">
    <?php foreach ($history as $h):
      $cid = (int) ($h['id'] ?? 0);
      $p_list = $rx_by_consult[$cid] ?? [];
      $note = $notes_by_consult[$cid] ?? null;
      $status = (string) ($h['status'] ?? 'pending');
      $statusLabel = ucwords(str_replace('_', ' ', $status));
      $diagnosis = pmh_note_text($note['diagnosis'] ?? '', pmh_note_text($h['diagnosis'] ?? '', 'No diagnosis recorded.'));
      $recommendation = pmh_note_text($note['treatment_plan'] ?? '', pmh_note_text($note['plan'] ?? '', pmh_note_text($h['recommendation'] ?? '', 'No recommendations recorded.')));
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
          <span class="pmh-status <?= pmh_status_class($status) ?>"><?= htmlspecialchars($statusLabel) ?></span>
        </header>

        <div class="pmh-visit__grid">
          <section class="pmh-visit__block">
            <h4 class="pmh-visit__label">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
              Diagnosis
            </h4>
            <p><?= htmlspecialchars($diagnosis) ?></p>
          </section>
          <section class="pmh-visit__block">
            <h4 class="pmh-visit__label">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
              Plan / Recommendations
            </h4>
            <p><?= htmlspecialchars($recommendation) ?></p>
          </section>
        </div>

        <?php if ($note): ?>
        <section class="pmh-visit__soap">
          <h4 class="pmh-visit__label">Clinical note (SOAP)</h4>
          <dl class="pmh-soap-list">
            <?php if (pmh_note_text($note['subjective'] ?? '')): ?>
            <div><dt>Subjective</dt><dd><?= htmlspecialchars($note['subjective']) ?></dd></div>
            <?php endif; ?>
            <?php if (pmh_note_text($note['objective'] ?? '')): ?>
            <div><dt>Objective</dt><dd><?= htmlspecialchars($note['objective']) ?></dd></div>
            <?php endif; ?>
            <?php if (pmh_note_text($note['assessment'] ?? '')): ?>
            <div><dt>Assessment</dt><dd><?= htmlspecialchars($note['assessment']) ?></dd></div>
            <?php endif; ?>
            <?php if (pmh_note_text($note['plan'] ?? '')): ?>
            <div><dt>Plan</dt><dd><?= htmlspecialchars($note['plan']) ?></dd></div>
            <?php endif; ?>
          </dl>
        </section>
        <?php endif; ?>

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
      </div>
    </article>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
