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
        <div class="download-app__device" id="download-app-device" aria-hidden="true">
          <div class="download-app__device-notch"></div>
          <div class="download-app__viewport" id="download-app-viewport">
            <p class="download-app__preview-note">App preview — sample layout only, not live patient data.</p>
            <div class="download-app__track" id="download-app-track">

              <article class="download-app__slide is-active" data-preview="dashboard">
                <div class="dap-dash">
                  <header class="dap-dash__hero">
                    <p class="dap-dash__eyebrow">Patient Care Portal</p>
                    <p class="dap-dash__title">Good morning, Patient</p>
                    <p class="dap-dash__sub">Appointments, visit history, and health records in one place.</p>
                    <div class="dap-dash__badges">
                      <span class="dap-dash__badge dap-dash__badge--verified">Verified Patient</span>
                      <span class="dap-dash__badge">Patient ID</span>
                    </div>
                    <div class="dap-dash__actions">
                      <span class="dap-dash__cta">Book Consultation</span>
                      <span class="dap-dash__cta dap-dash__cta--outline">My Sessions</span>
                    </div>
                  </header>
                  <div class="dap-dash__complaint">
                    <strong>Patient Complaint</strong>
                    <p>Share your current health concern to start triage.</p>
                    <span>Submit patient complaint</span>
                  </div>
                  <nav class="dap-dash__quick">
                    <span>Health Summary</span>
                    <span>My Health</span>
                    <span>Patient Complaint</span>
                    <span>Messages</span>
                  </nav>
                </div>
              </article>

              <article class="download-app__slide" data-preview="video">
                <div class="dap-vc">
                  <header class="dap-vc__header">
                    <div class="dap-vc__who">
                      <span class="dap-vc__avatar">HP</span>
                      <div>
                        <strong>Healthcare Provider</strong>
                        <em>Video consultation</em>
                      </div>
                    </div>
                    <span class="dap-vc__timer">00:00</span>
                  </header>
                  <div class="dap-vc__status">
                    <span class="dap-vc__dot"></span>
                    Connected
                  </div>
                  <div class="dap-vc__stage">
                    <span class="dap-vc__main-label">Provider</span>
                    <div class="dap-vc__pip">You</div>
                  </div>
                  <div class="dap-vc__bar">
                    <span class="dap-vc__btn" title="Microphone">
                      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v1a7 7 0 0 1-14 0v-1M12 18v4M8 22h8"/></svg>
                    </span>
                    <span class="dap-vc__btn" title="Camera">
                      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m23 7-7 5 7 5V7z"/><rect x="1" y="5" width="15" height="14" rx="2"/></svg>
                    </span>
                    <span class="dap-vc__btn" title="Fullscreen">
                      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"/></svg>
                    </span>
                    <span class="dap-vc__btn" title="More">
                      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="5" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="12" cy="19" r="1.5"/></svg>
                    </span>
                    <span class="dap-vc__leave">Leave</span>
                  </div>
                </div>
              </article>

              <article class="download-app__slide" data-preview="completed">
                <div class="dap-done">
                  <div class="dap-done__brand">
                    <img src="<?= $iconUrl ?>" alt="" width="22" height="22" />
                    <span>medConnect</span>
                  </div>
                  <div class="dap-done__check" aria-hidden="true">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M20 6 9 17l-5-5"/></svg>
                  </div>
                  <h3>Consultation Completed</h3>
                  <p class="dap-done__sub">Your video consultation with your healthcare provider has ended successfully.</p>
                  <dl class="dap-done__meta">
                    <div><dt>Provider</dt><dd>Healthcare Provider</dd></div>
                    <div><dt>Status</dt><dd>Completed</dd></div>
                    <div><dt>Duration</dt><dd>Session time</dd></div>
                  </dl>
                  <div class="dap-done__saved">
                    <strong>Consultation saved successfully</strong>
                    <span>Saved to My Sessions</span>
                  </div>
                  <span class="dap-done__cta">View Session</span>
                </div>
              </article>

              <article class="download-app__slide" data-preview="health">
                <div class="dap-health">
                  <header class="dap-health__hero">
                    <p>Health Summary</p>
                    <h3>Permanent medical profile</h3>
                    <span class="dap-health__chip">Read-only</span>
                  </header>
                  <div class="dap-health__card">
                    <em>Blood Type</em>
                    <span>From registration</span>
                  </div>
                  <div class="dap-health__card">
                    <em>Allergies</em>
                    <span>On file when recorded</span>
                  </div>
                  <div class="dap-health__card">
                    <em>Medical Conditions</em>
                    <span>Chronic profile details</span>
                  </div>
                  <div class="dap-health__card">
                    <em>Maintenance Meds</em>
                    <span>Regular medications</span>
                  </div>
                  <p class="dap-health__foot">My Health · prescriptions · consultation history</p>
                </div>
              </article>

              <article class="download-app__slide" data-preview="care">
                <div class="dap-care">
                  <header class="dap-care__hero">
                    <p>My Health</p>
                    <h3>Care Tips</h3>
                    <span>Doctor-approved self-care</span>
                  </header>
                  <article class="dap-care__card">
                    <div class="dap-care__card-head">
                      <strong>Health concern</strong>
                      <em>Pending review</em>
                    </div>
                    <p>Your provider is reviewing this concern. Approved tips will appear here and in the Care Assistant.</p>
                  </article>
                  <article class="dap-care__card dap-care__card--ready">
                    <div class="dap-care__card-head">
                      <strong>Approved guidance</strong>
                      <em>Ready</em>
                    </div>
                    <p>Home-care tips are shared only after your healthcare provider approves them. They do not replace a consultation.</p>
                  </article>
                </div>
              </article>

            </div>
          </div>
        </div>
        <div class="download-app__dots" id="download-app-dots" role="tablist" aria-label="App preview screens">
          <button type="button" class="is-active" role="tab" aria-selected="true" aria-label="Patient dashboard" data-slide="0"></button>
          <button type="button" role="tab" aria-selected="false" aria-label="Video consultation" data-slide="1"></button>
          <button type="button" role="tab" aria-selected="false" aria-label="Consultation completed" data-slide="2"></button>
          <button type="button" role="tab" aria-selected="false" aria-label="Health summary" data-slide="3"></button>
          <button type="button" role="tab" aria-selected="false" aria-label="Care tips" data-slide="4"></button>
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
