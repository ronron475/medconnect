<?php
/**
 * Global persistent video consultation shell (PiP across portal navigation).
 * Keeps a single iframe to video_room.php — WebRTC is never duplicated.
 */
if (!defined('ASSET_BASE')) {
    return;
}
$shellCssVer = (int) @filemtime(ASSETS_PATH . '/css/session-video-shell.css');
$shellJsVer  = (int) @filemtime(ASSETS_PATH . '/js/session-video-shell.js');
?>
<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/session-video-shell.css?v=<?= $shellCssVer ?>"/>
<div id="mcGlobalVideoShell" class="mc-session-float-shell" hidden aria-hidden="true" data-mode="hidden">
  <div class="mc-session-float-handle" id="mcGlobalVideoHandle" title="Drag to move">
    <span class="mc-session-float-dot" aria-hidden="true"></span>
    <span id="mcGlobalVideoHandleLabel">Video consultation</span>
    <div class="mc-session-float-handle__actions">
      <button type="button" class="mc-session-float-btn" id="mcGlobalVideoExpand" title="Expand" aria-label="Expand video">⤢</button>
      <button type="button" class="mc-session-float-btn" id="mcGlobalVideoMinimize" title="Minimize" aria-label="Minimize video">—</button>
    </div>
  </div>
  <div class="mc-session-float-body">
    <iframe
      id="mcGlobalVideoFrame"
      title="Video consultation"
      allow="camera; microphone; display-capture; autoplay"
      referrerpolicy="same-origin"
    ></iframe>
  </div>
  <div class="mc-session-float-toolbar" id="mcGlobalVideoToolbar" hidden>
    <button type="button" class="mc-session-float-tool" data-shell-action="mute" title="Mute">🎤</button>
    <button type="button" class="mc-session-float-tool" data-shell-action="camera" title="Camera">📷</button>
    <button type="button" class="mc-session-float-tool mc-session-float-tool--end" data-shell-action="end" title="End call">Leave</button>
  </div>
</div>
<script src="<?= ASSET_BASE ?>/assets/js/session-video-shell.js?v=<?= $shellJsVer ?>"></script>
