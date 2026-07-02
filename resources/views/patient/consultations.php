<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!defined('BASE_PATH')) {
    $d = __DIR__;
    while ($d !== dirname($d)) {
        if (is_file($d . '/mc_load.php')) {
            require_once $d . '/mc_load.php';
            break;
        }
        $d = dirname($d);
    }
}
require_once BASE_PATH . '/app/includes/patient_portal_bootstrap.php';

$all_consults = [];
if ($pdo->query("SHOW TABLES LIKE 'consultations'")->rowCount()) {
    $s = $pdo->prepare("
        SELECT c.id, c.consult_date, c.consult_time, c.provider_name, c.consult_type, c.status, c.diagnosis, c.recommendation,
               vs.room_token
        FROM consultations c
        LEFT JOIN video_sessions vs ON c.id = vs.consultation_id AND vs.status = 'active'
        WHERE c.patient_id = ?
        ORDER BY c.consult_date DESC, c.consult_time DESC
    ");
    $s->execute([$uid]);
    $all_consults = $s->fetchAll(PDO::FETCH_ASSOC);
}

$page_title = 'My Sessions';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require_once VIEWS_PATH . '/patient/partials/layout_head.php'; ?>
</head>
<body class="patient-portal">

  <?php require_once VIEWS_PATH . '/patient/partials/layout_shell_open.php'; ?>

    <div class="patient-page">
      <?php require VIEWS_PATH . '/patient/partials/view_consultations.php'; ?>
    </div>

  <?php require_once VIEWS_PATH . '/patient/partials/layout_shell_close.php'; ?>

  <script>window.APP_BASE = <?= json_encode(ASSET_BASE) ?>;</script>
  <script>window.consultations = <?= json_encode($all_consults, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;</script>
  <script src="<?= ASSET_BASE ?>/assets/js/patient-portal.js?v=<?= $patient_portal_ver ?>"></script>
  <script>
  document.addEventListener('DOMContentLoaded', function () {
    if (typeof window.filterSessions === 'function') {
      window.filterSessions('upcoming');
    }
  });
  (function () {
    const tokensKey = () => (window.consultations || []).map((c) => c.room_token || '').join('|');
    let lastTokens = tokensKey();
    setInterval(async () => {
      try {
        const res = await fetch(window.location.href, { cache: 'no-store' });
        const html = await res.text();
        const m = html.match(/window\.consultations\s*=\s*(\[[\s\S]*?\]);/);
        if (!m) return;
        const fresh = JSON.parse(m[1]);
        const freshKey = fresh.map((c) => c.room_token || '').join('|');
        if (freshKey === lastTokens) return;
        lastTokens = freshKey;
        window.consultations.length = 0;
        window.consultations.push(...fresh);
        const tab = document.querySelector('.tab-btn.active');
        const type = tab && tab.innerText.toLowerCase().includes('past') ? 'past' : 'upcoming';
        if (typeof window.filterSessions === 'function') window.filterSessions(type);
      } catch (_) {}
    }, 15000);
  })();
  </script>
</body>
</html>
