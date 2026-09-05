<?php
/**
 * Standalone consultation-completed screen (ended video room token).
 * Presentation only — URLs and values are prepared by video_room.php.
 */
$isPatientEnded = ($role === 'patient');
$providerLabel = $endedProvider !== '' ? $endedProvider : 'your doctor';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>Consultation Completed — medConnect</title>
  <link rel="icon" type="image/png" href="<?= htmlspecialchars($endedLogo) ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= htmlspecialchars(ASSET_BASE) ?>/assets/css/video-room-enhancements.css?v=<?= (int) $endedCssVer ?>">
  <link rel="stylesheet" href="<?= htmlspecialchars(ASSET_BASE) ?>/assets/css/responsive.css">
</head>
<body class="mc-ended-page is-ended-consultation<?= $isPatientEnded ? ' role-patient' : ' role-provider' ?>">
  <main class="mc-vc-postcall mc-ended-page__shell" role="main">
    <article class="mc-vc-postcall__card">
      <div class="mc-vc-postcall__brand">
        <img src="<?= htmlspecialchars($endedLogo) ?>" width="28" height="28" alt="">
        <span>medConnect</span>
      </div>

      <div class="mc-vc-postcall__hero">
        <div class="mc-vc-postcall__check" aria-hidden="true">
          <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
        </div>
        <h1 id="mcVcPostCallTitle">Consultation Completed</h1>
        <?php if ($isPatientEnded): ?>
        <p class="mc-vc-postcall__sub">Your video consultation with <strong><?= htmlspecialchars($providerLabel) ?></strong> has ended successfully.</p>
        <?php else: ?>
        <p class="mc-vc-postcall__sub">Your video consultation has ended. The live call cannot be restarted.</p>
        <?php endif; ?>
      </div>

      <?php if ($isPatientEnded): ?>
      <section class="mc-vc-postcall__summary" aria-label="Consultation summary">
        <h2>Consultation Summary</h2>
        <dl class="mc-vc-postcall__meta">
          <?php if ($endedDateLabel !== ''): ?>
          <div>
            <dt>
              <span class="mc-vc-postcall__ico" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg></span>
              Date
            </dt>
            <dd><?= htmlspecialchars($endedDateLabel) ?></dd>
          </div>
          <?php endif; ?>
          <div>
            <dt>
              <span class="mc-vc-postcall__ico" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></span>
              Provider
            </dt>
            <dd><?= htmlspecialchars($providerLabel) ?></dd>
          </div>
          <?php if ($endedStatusLabel !== ''): ?>
          <div>
            <dt>
              <span class="mc-vc-postcall__ico" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></span>
              Status
            </dt>
            <dd><?= htmlspecialchars($endedStatusLabel) ?></dd>
          </div>
          <?php endif; ?>
          <div>
            <dt>
              <span class="mc-vc-postcall__ico" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg></span>
              Duration
            </dt>
            <dd><?= htmlspecialchars($endedDuration !== '' ? $endedDuration : '—') ?></dd>
          </div>
        </dl>
      </section>

      <div class="mc-vc-postcall__confirm">
        <p class="mc-vc-postcall__confirm-title">Consultation saved successfully</p>
        <p class="mc-vc-postcall__confirm-copy">Your consultation record is now available in My Sessions.</p>
        <?php if ($endedRecordingUrl !== ''): ?>
        <p class="mc-vc-postcall__confirm-copy">A video recording is available in this session.</p>
        <?php endif; ?>
      </div>
      <?php else: ?>
      <div class="mc-vc-postcall__confirm">
        <p class="mc-vc-postcall__confirm-title">Consultation saved successfully</p>
      </div>
      <?php endif; ?>

      <div class="mc-vc-postcall__actions">
        <?php if ($isOwner): ?>
        <a class="mc-vc-postcall__btn mc-vc-postcall__btn--primary" href="<?= htmlspecialchars($historyUrl) ?>" target="_top">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/></svg>
          View Session
        </a>
        <?php endif; ?>
        <a class="mc-vc-postcall__btn" href="<?= htmlspecialchars($dashUrl) ?>" target="_top">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/></svg>
          Return to Dashboard
        </a>
      </div>
    </article>
  </main>
  <script>
  (function () {
    try {
      var p = localStorage.getItem('medconnect_theme');
      var dark = p === 'dark' || ((!p || p === 'system') && window.matchMedia('(prefers-color-scheme: dark)').matches);
      if (dark) document.documentElement.setAttribute('data-theme-resolved', 'dark');
      if (window.parent && window.parent !== window) {
        window.parent.postMessage({ type: 'medconnect:call-completed', ended: true, historical: true }, window.location.origin);
      }
    } catch (e) {}
  })();
  </script>
</body>
</html>
