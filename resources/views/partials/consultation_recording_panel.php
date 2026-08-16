<?php
/**
 * Provider/patient recording status + playback links for one consultation.
 *
 * Expects: $consultation_id (int), $video_history (array), optional $recording_btn_class
 */
$recordingConsultId = (int) ($consultation_id ?? 0);
$recordingHistory = is_array($video_history ?? null) ? $video_history : [];
$recordingBtnClass = (string) ($recording_btn_class ?? 'session-btn primary');
$recordingViewUrl = $recordingConsultId > 0 ? consultation_video_recording_view_url($recordingConsultId) : '';
$recordingSegments = is_array($recordingHistory['recording_segments'] ?? null)
    ? $recordingHistory['recording_segments']
    : [];
$recordingPlayable = [];
foreach ($recordingSegments as $recordingSegment) {
    if (!empty($recordingSegment['playable'])) {
        $recordingPlayable[] = $recordingSegment;
    }
}
$recordingCount = (int) ($recordingHistory['recording_segment_count'] ?? count($recordingPlayable));
if ($recordingCount <= 0 && $recordingViewUrl !== '') {
    $recordingCount = 1;
}
$recordingLabel = (string) ($recordingHistory['recording_label'] ?? '');
if ($recordingLabel === '') {
    $recordingLabel = $recordingViewUrl === ''
        ? 'Not available'
        : ($recordingCount > 1 ? ($recordingCount . ' recording segments') : 'Available');
}
?>
<?php if ($recordingViewUrl === ''): ?>
<div class="info-row" style="margin-top:10px;"><span class="info-key">Video recording</span><span class="info-val">Not available</span></div>
<?php else: ?>
<div class="info-row" style="margin-top:10px;"><span class="info-key">Video recording</span><span class="info-val"><?= htmlspecialchars($recordingLabel) ?></span></div>
<?php if (count($recordingPlayable) > 1): ?>
<ul style="margin:8px 0 0;padding-left:18px;font-size:12px;color:#334155;line-height:1.7;">
    <?php foreach ($recordingPlayable as $recordingSegment):
        $segId = (int) ($recordingSegment['id'] ?? 0);
        $segIdx = (int) ($recordingSegment['segment_index'] ?? 0);
        $segUrl = consultation_video_recording_segment_url($recordingConsultId, $segId);
        $timeBits = trim((string) ($recordingSegment['started_label'] ?? ''));
        if ((string) ($recordingSegment['ended_label'] ?? '') !== '') {
            $timeBits .= ($timeBits !== '' ? '–' : '') . (string) $recordingSegment['ended_label'];
        }
        $segTitle = 'Segment ' . ($segIdx > 0 ? $segIdx : '1');
        if ($timeBits !== '') {
            $segTitle .= ' (' . $timeBits . ')';
        }
    ?>
    <li>
        <?= htmlspecialchars($segTitle) ?>
        <?php if ($segUrl !== ''): ?>
        — <a href="<?= htmlspecialchars($segUrl) ?>" target="_blank" rel="noopener">Play</a>
        <?php endif; ?>
    </li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>
<p style="margin:12px 0 0;">
    <a class="<?= htmlspecialchars($recordingBtnClass) ?>" href="<?= htmlspecialchars($recordingViewUrl) ?>" target="_blank" rel="noopener">View Recording</a>
</p>
<?php endif; ?>
