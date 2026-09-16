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

function pmh_referral_status_label(?string $status): string
{
    return match (strtolower(trim((string) $status))) {
        'pending' => 'Issued / Open',
        'accepted' => 'In progress',
        'completed' => 'Completed',
        'cancelled', 'rejected' => 'Cancelled',
        'expired' => 'Expired',
        default => trim((string) $status) !== '' ? ucfirst(trim((string) $status)) : 'Issued / Open',
    };
}

/**
 * @return array{0: string, 1: string} [label, svg]
 */
function pmh_soap_field_meta(string $key): array
{
    return match ($key) {
        'chief_complaint' => [
            'Chief Complaint',
            '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
        ],
        'subjective' => [
            'Subjective',
            '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/></svg>',
        ],
        'objective' => [
            'Objective',
            '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/><path d="M11 8v6"/><path d="M8 11h6"/></svg>',
        ],
        'assessment' => [
            'Assessment',
            '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 12h6"/><path d="M9 16h6"/></svg>',
        ],
        'plan' => [
            'Plan',
            '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>',
        ],
        default => [
            ucfirst($key),
            '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/></svg>',
        ],
    };
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
      <?= htmlspecialchars($meta['label']) ?> (<?= (int) $cnt ?>)
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

      <?php if ($type === 'Health File'): ?>
        <?php if (!empty($outcome['final_case_level']) || !empty($outcome['ai_case_level'])): ?>
          <?php
            if (!function_exists('mc_render_consultation_outcome_stack')) {
                require_once VIEWS_PATH . '/patient/partials/triage_helpers.php';
            }
            echo '<div class="pmh-file-card__assess">';
            mc_render_consultation_outcome_stack($outcome, $consultId);
            echo '</div>';
          ?>
        <?php endif; ?>

        <?php
          $soapFields = [
              'chief_complaint' => $r['chief_complaint'] ?? '',
              'subjective' => $r['subjective'] ?? '',
              'objective' => $r['objective'] ?? '',
              'assessment' => $r['assessment'] ?? '',
              'plan' => $r['plan'] ?? '',
          ];
        ?>
        <div class="pmh-soap-fields" role="list">
          <?php foreach ($soapFields as $fieldKey => $fieldVal):
            // Skip empty chief complaint; always show SOAP S/O/A/P rows.
            if ($fieldKey === 'chief_complaint' && !pmh_health_file_text((string) $fieldVal)) {
                continue;
            }
            [$label, $icon] = pmh_soap_field_meta($fieldKey);
            $display = pmh_health_file_text((string) $fieldVal) ? (string) $fieldVal : '—';
          ?>
          <div class="pmh-soap-field<?= $fieldKey === 'chief_complaint' ? ' pmh-soap-field--chief' : '' ?>" role="listitem">
            <span class="pmh-soap-field__icon" aria-hidden="true"><?= $icon ?></span>
            <div class="pmh-soap-field__body">
              <span class="pmh-soap-field__label"><?= htmlspecialchars($label) ?></span>
              <span class="pmh-soap-field__value"><?= nl2br(htmlspecialchars($display)) ?></span>
            </div>
          </div>
          <?php endforeach; ?>
        </div>

        <?php if (pmh_health_file_text($r['treatment_plan'] ?? '')): ?>
        <p class="pmh-file-card__soap"><strong>Care plan:</strong> <?= nl2br(htmlspecialchars($r['treatment_plan'])) ?></p>
        <?php endif; ?>

        <?php
          $signedBy = function_exists('clinical_note_signed_by_label')
            ? clinical_note_signed_by_label($r, (string) ($r['provider_name'] ?? ''))
            : '';
          $signedAt = function_exists('clinical_note_signed_at_label')
            ? clinical_note_signed_at_label($r)
            : '';
        ?>
        <?php if ($signedBy !== '' || $signedAt !== ''): ?>
        <div class="pmh-soap-signed pmh-soap-signed--box">
          <span class="pmh-soap-signed__icon" aria-hidden="true">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
          </span>
          <div class="pmh-soap-signed__text">
            <?php if ($signedBy !== ''): ?><span><?= htmlspecialchars($signedBy) ?></span><?php endif; ?>
            <?php if ($signedAt !== ''): ?><span class="pmh-soap-signed__at"><?= htmlspecialchars($signedAt) ?></span><?php endif; ?>
          </div>
        </div>
        <?php endif; ?>

        <?php if ($consultId > 0): ?>
        <p class="pmh-file-card__link">
          <a href="<?= htmlspecialchars(patient_consultation_detail_url($consultId)) ?>" class="pmh-btn pmh-btn--outline pmh-btn--block">Full consultation details</a>
        </p>
        <?php endif; ?>

      <?php elseif ($type === 'Prescription'): ?>
        <?php
          $med = trim((string) ($r['medication_name'] ?? ''));
          $dose = trim((string) ($r['dosage'] ?? ''));
          $freq = trim((string) ($r['frequency'] ?? ''));
          $dur = trim((string) ($r['duration'] ?? ''));
          $notes = trim((string) ($r['detail'] ?? ''));
          if ($med === '' && pmh_health_file_text($r['record_name'] ?? '')) {
              $med = (string) $r['record_name'];
          }
        ?>
        <div class="pmh-rx-grid" aria-label="Prescription details">
          <div class="pmh-rx-grid__cell">
            <span class="pmh-rx-grid__label">Medication</span>
            <span class="pmh-rx-grid__value"><?= htmlspecialchars($med !== '' ? $med : '—') ?></span>
          </div>
          <div class="pmh-rx-grid__cell">
            <span class="pmh-rx-grid__label">Dosage</span>
            <span class="pmh-rx-grid__value"><?= htmlspecialchars($dose !== '' ? $dose : 'As directed') ?></span>
          </div>
          <div class="pmh-rx-grid__cell">
            <span class="pmh-rx-grid__label">Frequency</span>
            <span class="pmh-rx-grid__value"><?= htmlspecialchars($freq !== '' ? $freq : 'As directed') ?></span>
          </div>
          <div class="pmh-rx-grid__cell">
            <span class="pmh-rx-grid__label">Duration</span>
            <span class="pmh-rx-grid__value"><?= htmlspecialchars($dur !== '' ? $dur : 'As directed') ?></span>
          </div>
        </div>
        <?php if ($notes !== ''): ?>
        <div class="pmh-rx-notes">
          <span class="pmh-rx-notes__label">Additional Notes</span>
          <p class="pmh-rx-notes__text"><?= nl2br(htmlspecialchars($notes)) ?></p>
        </div>
        <?php else: ?>
        <div class="pmh-rx-notes">
          <span class="pmh-rx-notes__label">Additional Notes</span>
          <p class="pmh-rx-notes__text">Take as prescribed. Follow up if symptoms persist.</p>
        </div>
        <?php endif; ?>

      <?php elseif ($type === 'Referral'): ?>
        <?php
          $refType = (string) ($r['referral_type'] ?? '');
          $refReason = (string) ($r['referral_reason'] ?? $r['frequency'] ?? '');
          $refFacility = (string) ($r['referral_facility'] ?? $r['duration'] ?? '');
          $refNotes = (string) ($r['referral_notes'] ?? '');
        ?>
        <div class="pmh-soap-fields" role="list">
          <div class="pmh-soap-field" role="listitem">
            <span class="pmh-soap-field__icon" aria-hidden="true">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><path d="M15 3h6v6"/><path d="m10 14 11-11"/></svg>
            </span>
            <div class="pmh-soap-field__body">
              <span class="pmh-soap-field__label">Referral type</span>
              <span class="pmh-soap-field__value"><?= htmlspecialchars($refType !== '' ? $refType : 'Referral') ?></span>
            </div>
          </div>
          <div class="pmh-soap-field" role="listitem">
            <span class="pmh-soap-field__icon" aria-hidden="true">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/></svg>
            </span>
            <div class="pmh-soap-field__body">
              <span class="pmh-soap-field__label">Facility / service</span>
              <span class="pmh-soap-field__value"><?= htmlspecialchars(pmh_health_file_text($refFacility) ? $refFacility : '—') ?></span>
            </div>
          </div>
          <div class="pmh-soap-field" role="listitem">
            <span class="pmh-soap-field__icon" aria-hidden="true">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            </span>
            <div class="pmh-soap-field__body">
              <span class="pmh-soap-field__label">Reason for referral</span>
              <span class="pmh-soap-field__value"><?= nl2br(htmlspecialchars(pmh_health_file_text($refReason) ? $refReason : '—')) ?></span>
            </div>
          </div>
          <?php if (pmh_health_file_text($refNotes)): ?>
          <div class="pmh-soap-field" role="listitem">
            <span class="pmh-soap-field__icon" aria-hidden="true">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
            </span>
            <div class="pmh-soap-field__body">
              <span class="pmh-soap-field__label">Doctor’s notes</span>
              <span class="pmh-soap-field__value"><?= nl2br(htmlspecialchars($refNotes)) ?></span>
            </div>
          </div>
          <?php endif; ?>
        </div>
        <p class="pmh-file-card__meta text-xs text-muted">Issued by your doctor · Read-only</p>
      <?php endif; ?>
    </article>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
