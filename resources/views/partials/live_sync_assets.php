<?php
/**
 * Shared live-sync hub — load once per portal layout (not on video_room.php).
 */
if (!empty($live_sync_skip_js)) {
    return;
}
$liveSyncJsVer = (int) @filemtime(ASSETS_PATH . '/js/medconnect-live-sync.js');
?>
<script src="<?= ASSET_BASE ?>/assets/js/medconnect-live-sync.js?v=<?= $liveSyncJsVer ?>" defer></script>
