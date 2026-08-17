<?php
declare(strict_types=1);

require_once BASE_PATH . '/app/includes/mobile_app.php';

$mobileApp = medconnect_mobile_app();
$apkReady = !empty($mobileApp['available']);
$apkMeta = $apkReady
    ? ('Version ' . $mobileApp['version'] . ' · Android · ' . $mobileApp['size_label'] . ' · Official release')
    : ('Version ' . $mobileApp['version'] . ' · Android');
$downloadUrl = htmlspecialchars((string) $mobileApp['download_url'], ENT_QUOTES, 'UTF-8');
$filename = htmlspecialchars((string) $mobileApp['filename'], ENT_QUOTES, 'UTF-8');
?>
<section id="download-app" class="download-app-section" data-apk-ready="<?= $apkReady ? '1' : '0' ?>">
  <div class="download-app__container">
    <div class="services-header download-app__header" data-lsa="fade-up">
      <p class="download-app__kicker">Android app</p>
      <h2 class="services-title">Download medConnect Mobile App</h2>
      <p class="services-desc">
        Install the official <span class="services-brand">medConnect</span> Android app on your phone for faster access to triage, video consultation, and records.
      </p>
    </div>

    <div class="download-app__grid">
      <div class="download-app__phone" aria-hidden="true" data-lsa="fade-up" data-lsa-delay="80">
        <div class="download-app__device">
          <div class="download-app__device-notch"></div>
          <div class="download-app__device-screen">
            <img src="<?= htmlspecialchars($mobileApp['icon_url']) ?>" alt="" width="72" height="72" />
            <strong>medConnect</strong>
            <span>City Health Office · Bago City</span>
          </div>
        </div>
      </div>

      <div class="download-app__panel" data-lsa="fade-up" data-lsa-delay="140">
        <?php if ($apkReady): ?>
        <p class="download-app__status" id="download-app-status">Download the official medConnect Android app, then follow the steps below to install it.</p>
        <?php else: ?>
        <p class="download-app__status" id="download-app-status">App download is temporarily unavailable. Please try again later.</p>
        <?php endif; ?>

        <p class="download-app__meta"><?= htmlspecialchars($apkMeta) ?></p>

        <div class="download-app__actions">
          <a
            class="download-app__btn download-app__btn--primary"
            id="download-apk-btn"
            href="<?= $downloadUrl ?>"
            download="<?= $filename ?>"
            data-apk-ready="<?= $apkReady ? '1' : '0' ?>"
          >
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
            <span>Download medConnect App</span>
          </a>

          <button type="button" class="download-app__btn download-app__btn--ghost" id="install-pwa-btn" hidden>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="2" width="14" height="20" rx="2"/><path d="M12 18h.01"/></svg>
            <span>Install on this phone</span>
          </button>
        </div>

        <p class="download-app__done" id="download-app-done" hidden>
          If the file does not appear, check your browser downloads and open <?= $filename ?>.
        </p>

        <div class="download-app__steps">
          <h3>How to install on Android</h3>
          <ol>
            <li>Tap <strong>Download medConnect App</strong> and wait for <?= $filename ?> to finish.</li>
            <li>Allow your browser to install unknown apps if Android asks (Settings → Apps → Chrome or Files → Install unknown apps → Allow).</li>
            <li>Open <strong><?= $filename ?></strong> from your Downloads folder.</li>
            <li>If Play Protect warns you, tap More details, then Install anyway. Tap Install, then open medConnect.</li>
          </ol>
        </div>
      </div>
    </div>
  </div>
</section>
