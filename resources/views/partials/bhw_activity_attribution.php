<?php
/**
 * Attribution footer for a community health activity entry.
 * Expects: $entry (array), $bhwAttrClass (string)
 */
$addedBy = trim((string) ($entry['added_by'] ?? $entry['bhw_name'] ?? ''));
$roleLabel = trim((string) ($entry['role_label'] ?? ''));
if ($roleLabel === '' && !empty($entry['role'])) {
    $roleLabel = match (strtolower((string) $entry['role'])) {
        'bhw' => 'Barangay Health Worker',
        'patient' => 'Patient',
        'provider' => 'Provider',
        default => '',
    };
}
if ($roleLabel === '' && $addedBy !== '' && $addedBy !== 'Unknown') {
    $roleLabel = 'Barangay Health Worker';
}
$dateLabel = trim((string) ($entry['date_label'] ?? ''));
$timeLabel = trim((string) ($entry['time_label'] ?? ''));
$barangayLabel = trim((string) ($entry['barangay_label'] ?? ''));
$attrClass = $bhwAttrClass ?? 'pmh-bhw__attr';
$combineDateTime = str_contains((string) $attrClass, 'pmh-event-meta');
$recordedLabel = trim($dateLabel);
if ($combineDateTime && $timeLabel !== '' && $timeLabel !== '—') {
    $recordedLabel = $recordedLabel !== '' && $recordedLabel !== '—'
        ? $recordedLabel . ' · ' . $timeLabel
        : $timeLabel;
}
?>
<dl class="<?= htmlspecialchars((string) $attrClass) ?>">
  <?php if ($addedBy !== '' && $addedBy !== 'Unknown'): ?>
  <div><dt>Added by</dt><dd><?= htmlspecialchars($addedBy) ?></dd></div>
  <?php endif; ?>
  <?php if ($roleLabel !== ''): ?>
  <div><dt>Role</dt><dd><?= htmlspecialchars($roleLabel) ?></dd></div>
  <?php endif; ?>
  <?php if ($barangayLabel !== '' && $barangayLabel !== '—'): ?>
  <div><dt>Barangay</dt><dd><?= htmlspecialchars($barangayLabel) ?></dd></div>
  <?php endif; ?>
  <?php if ($combineDateTime): ?>
    <?php if ($recordedLabel !== '' && $recordedLabel !== '—'): ?>
    <div><dt>Date recorded</dt><dd><?= htmlspecialchars($recordedLabel) ?></dd></div>
    <?php endif; ?>
  <?php else: ?>
    <?php if ($dateLabel !== '' && $dateLabel !== '—'): ?>
    <div><dt>Date</dt><dd><?= htmlspecialchars($dateLabel) ?></dd></div>
    <?php endif; ?>
    <?php if ($timeLabel !== '' && $timeLabel !== '—'): ?>
    <div><dt>Time</dt><dd><?= htmlspecialchars($timeLabel) ?></dd></div>
    <?php endif; ?>
  <?php endif; ?>
</dl>
