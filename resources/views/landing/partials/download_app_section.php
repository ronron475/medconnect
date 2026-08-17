<?php
declare(strict_types=1);

require_once BASE_PATH . '/app/includes/mobile_app.php';

$mobileApp = medconnect_mobile_app();
$apkReady = !empty($mobileApp['available']);
$downloadUrl = htmlspecialchars((string) $mobileApp['download_url'], ENT_QUOTES, 'UTF-8');
$filename = htmlspecialchars((string) $mobileApp['filename'], ENT_QUOTES, 'UTF-8');
$iconUrl = htmlspecialchars((string) $mobileApp['icon_url'], ENT_QUOTES, 'UTF-8');
$version = htmlspecialchars((string) $mobileApp['version'], ENT_QUOTES, 'UTF-8');
$platform = htmlspecialchars((string) $mobileApp['platform'], ENT_QUOTES, 'UTF-8');
$sizeLabel = htmlspecialchars((string) $mobileApp['size_label'], ENT_QUOTES, 'UTF-8');
?>
<section id="download-app" class="download-app-section" data-apk-ready="<?= $apkReady ? '1' : '0' ?>">
  <div class="download-app__glow" aria-hidden="true"></div>
  <div class="download-app__container">
    <div class="services-header download-app__header" data-lsa="fade-up">
      <p class="download-app__kicker">Android app</p>
      <h2 class="services-title">Download medConnect Mobile App</h2>
      <p class="services-desc">
        Take <span class="services-brand">medConnect</span> with you — triage, video consultation, and records on your Android phone.
      </p>
    </div>

    <div class="download-app__grid">
      <div class="download-app__phone-col" data-lsa="fade-up" data-lsa-delay="80">
        <div class="download-app__device" aria-hidden="true">
          <div class="download-app__device-notch"></div>
          <div class="download-app__viewport" id="download-app-viewport">
            <div class="download-app__track" id="download-app-track">

              <article class="download-app__slide download-app__slide--welcome">
                <img src="<?= $iconUrl ?>" alt="" width="56" height="56" />
                <p class="download-app__slide-brand">med<span>Connect</span></p>
                <h3>Welcome to medConnect</h3>
                <p>City Health Office · Bago City</p>
                <span class="download-app__chip">Secure video care</span>
              </article>

              <article class="download-app__slide download-app__slide--dash">
                <header class="download-app__appbar">
                  <span>Good morning</span>
                  <strong>Alex R.</strong>
                </header>
                <div class="download-app__mini-card">
                  <em>Health Summary</em>
                  <p>All records up to date</p>
                </div>
                <div class="download-app__mini-card download-app__mini-card--accent">
                  <em>Upcoming Consultation</em>
                  <p>Thu · 10:30 AM</p>
                </div>
                <div class="download-app__mini-row">
                  <span>Complaint</span>
                  <span>Messages</span>
                </div>
              </article>

              <article class="download-app__slide download-app__slide--book">
                <header class="download-app__appbar">
                  <span>Book Consultation</span>
                  <strong>Dr. Elena Cruz</strong>
                </header>
                <div class="download-app__mini-card">
                  <em>Family Medicine</em>
                  <p>Available this week</p>
                </div>
                <div class="download-app__slots">
                  <span>Wed 9:00</span>
                  <span class="is-on">Thu 10:30</span>
                  <span>Fri 2:00</span>
                </div>
                <div class="download-app__fake-cta">Continue</div>
              </article>

              <article class="download-app__slide download-app__slide--video">
                <div class="download-app__video-stage">
                  <span class="download-app__live">Live</span>
                  <p>Dr. Elena Cruz</p>
                </div>
                <div class="download-app__video-self">You</div>
                <div class="download-app__video-bar">
                  <span></span><span></span><span></span>
                </div>
              </article>

              <article class="download-app__slide download-app__slide--records">
                <header class="download-app__appbar">
                  <span>Health Records</span>
                  <strong>History</strong>
                </header>
                <div class="download-app__mini-card">
                  <em>Consultation</em>
                  <p>Follow-up notes on file</p>
                </div>
                <div class="download-app__mini-card">
                  <em>Prescription</em>
                  <p>Ready for pharmacy</p>
                </div>
                <div class="download-app__mini-card">
                  <em>Medical record</em>
                  <p>Encrypted &amp; stored</p>
                </div>
              </article>

            </div>
          </div>
        </div>
        <div class="download-app__dots" id="download-app-dots" role="tablist" aria-label="App preview screens">
          <button type="button" class="is-active" role="tab" aria-selected="true" aria-label="Welcome screen" data-slide="0"></button>
          <button type="button" role="tab" aria-selected="false" aria-label="Patient dashboard" data-slide="1"></button>
          <button type="button" role="tab" aria-selected="false" aria-label="Book consultation" data-slide="2"></button>
          <button type="button" role="tab" aria-selected="false" aria-label="Video consultation" data-slide="3"></button>
          <button type="button" role="tab" aria-selected="false" aria-label="Health records" data-slide="4"></button>
        </div>
      </div>

      <div class="download-app__panel" data-lsa="fade-up" data-lsa-delay="140">
        <p class="download-app__panel-kicker">Download the medConnect app</p>
        <?php if ($apkReady): ?>
        <h3 class="download-app__status" id="download-app-status">Take medConnect with you wherever you go.</h3>
        <p class="download-app__lead">Access your consultations, health records, appointments, and patient services from your Android phone.</p>
        <?php else: ?>
        <h3 class="download-app__status" id="download-app-status">App download is temporarily unavailable. Please try again later.</h3>
        <p class="download-app__lead">You can still use medConnect in the browser while the Android package is being restored.</p>
        <?php endif; ?>

        <ul class="download-app__meta-list">
          <li>Version <?= $version ?></li>
          <li><?= $platform ?></li>
          <?php if ($sizeLabel !== ''): ?>
          <li><?= $sizeLabel ?></li>
          <?php endif; ?>
          <li>Official release</li>
        </ul>

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
            <li>
              <span>01</span>
              <p>Tap <strong>Download medConnect App</strong> and wait for <?= $filename ?> to finish.</p>
            </li>
            <li>
              <span>02</span>
              <p>Allow your browser to install unknown apps if Android asks (Settings → Apps → Chrome or Files → Install unknown apps → Allow).</p>
            </li>
            <li>
              <span>03</span>
              <p>Open <strong><?= $filename ?></strong> from your Downloads folder.</p>
            </li>
            <li>
              <span>04</span>
              <p>If Play Protect warns you, tap More details, then Install anyway. Tap Install, then open medConnect.</p>
            </li>
          </ol>
        </div>
      </div>
    </div>
  </div>
</section>
