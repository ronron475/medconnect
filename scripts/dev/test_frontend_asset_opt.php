<?php
/**
 * Smoke checks for frontend polling + asset gating changes.
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$fail = 0;
$ok = static function (string $label, bool $pass) use (&$fail): void {
    echo ($pass ? 'OK  ' : 'FAIL') . ' ' . $label . PHP_EOL;
    if (!$pass) {
        $fail++;
    }
};

$read = static fn(string $rel): string => (string) file_get_contents($root . '/' . $rel);

$ok('LiveSync POLL_MS=10000', str_contains($read('public/assets/js/medconnect-live-sync.js'), 'var POLL_MS = 10000'));
$ok('portal-nav-badges POLL_MS=10000', str_contains($read('public/assets/js/portal-nav-badges.js'), 'const POLL_MS = 10000'));
$ok('messages-unread POLL_MS=15000', str_contains($read('public/assets/js/messages-unread-service.js'), 'const POLL_MS = 15000'));
$ok('provider-nav-counts POLL_MS=15000', str_contains($read('public/assets/js/provider-nav-counts.js'), 'const POLL_MS = 15000'));
$ok('provider-queue-live POLL_MS=10000', str_contains($read('public/assets/js/provider-queue-live.js'), 'const POLL_MS = 10000'));
$ok('messages-fab POLL_MS=15000', str_contains($read('public/assets/js/messages-fab.js'), 'const POLL_MS = 15000'));
$ok('messages-quick-panel softened', str_contains($read('public/assets/js/messages-quick-panel.js'), 'POLL_CONV_MS = 8000')
    && str_contains($read('public/assets/js/messages-quick-panel.js'), 'POLL_THREAD_MS = 4000'));
$ok('video-mute-tts poll 5000', str_contains($read('public/assets/js/video-mute-tts.js'), 'pollMuteMessages, 5000'));

$session = $read('resources/views/provider/consultation_session.php');
$ok('session CSS extracted link present', str_contains($session, 'provider-consultation-session.css'));
$ok('session no giant inline style block', !preg_match('/<style>\s*\.session-page\s*\{/', $session));
$ok('session boot CSS var present', str_contains($session, '--mc-session-patient-suffix'));
$ok('session chat poll 5000', str_contains($session, 'setInterval(refreshSessionChat, 5000)'));
$ok('session realtime poller 5000', str_contains($session, 'sessionRealtimePoller.start(5000)'));

$css = $read('public/assets/css/provider-consultation-session.css');
$ok('extracted CSS file exists and large', strlen($css) > 10000);
$ok('extracted CSS uses CSS var for patient name', str_contains($css, '--mc-session-patient-suffix'));
$ok('extracted CSS has no PHP', !str_contains($css, '<?'));

$video = $read('resources/views/consultation/video_room.php');
$metaPos = strpos($video, 'window.__mcVideoRoomMeta');
$peerScriptPos = strpos($video, '<script src="https://unpkg.com/peerjs@1.5.2');
$ok('video peerjs script loads after head meta (not parser-blocking early)', $metaPos !== false && $peerScriptPos !== false && $peerScriptPos > $metaPos);
$ok('video libs appear before roomToken boot', (bool) preg_match('/<script src="https:\/\/unpkg.com\/peerjs@1\.5\.2[\s\S]{0,1200}const roomToken/', $video));
$ok('video patient status poll 5000', str_contains($video, 'pollPatientCallStatus, 5000'));
$ok('global-loader deferred in video head', (bool) preg_match('/global-loader\.js[^"\']*["\']\s+defer/i', $video));

$ok('admin charts CSS gated', str_contains($read('resources/views/admin/partials/layout_open.php'), 'load_admin_charts'));
$ok('admin dashboard enables charts', str_contains($read('resources/views/admin/dashboard.php'), '$load_admin_charts = true'));
$ok('superadmin charts CSS gated', str_contains($read('resources/views/superadmin/partials/layout_open.php'), 'load_admin_charts'));
$ok('superadmin dashboard enables charts', str_contains($read('resources/views/superadmin/dashboard.php'), '$load_admin_charts = true'));
$ok('provider mock CSS gated', str_contains($read('resources/views/provider/partials/layout_open.php'), 'load_provider_dashboard_mock_css'));
$ok('provider dashboard enables mock CSS', str_contains($read('resources/views/provider/dashboard_view.php'), '$load_provider_dashboard_mock_css = true'));

echo PHP_EOL . ($fail === 0 ? 'ALL PASSED' : "FAILED: {$fail}") . PHP_EOL;
exit($fail === 0 ? 0 : 1);
