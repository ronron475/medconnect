<?php
$page_title = $page_title ?? 'System Overview';
$admin_name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
if (!$admin_name) $admin_name = $_SESSION['user_name'] ?? 'Admin';
$admin_initials = strtoupper(
  substr($_SESSION['first_name'] ?? 'A', 0, 1) .
  substr($_SESSION['last_name']  ?? '',  0, 1)
);

// Server-side seed — JS live clock takes over immediately
$today = date('F j, Y');
$now   = date('h:i A');
?>
<header class="topbar">

  <!-- Left: breadcrumb + title -->
  <div class="topbar-left">
    <div class="topbar-eyebrow">
      <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
      </svg>
      Administration
    </div>
    <h1 class="topbar-title"><?= htmlspecialchars($page_title) ?></h1>
  </div>

  <!-- Right: datetime · bell · avatar · logout -->
  <div class="topbar-right">

    <!-- Date & live time -->
    <div class="topbar-datetime" aria-label="Current date and time">
      <span class="topbar-date" id="adm-date"><?= $today ?></span>
      <span class="topbar-sep">|</span>
      <span class="topbar-time" id="adm-time"><?= $now ?></span>
    </div>

    <!-- Divider -->
    <div class="topbar-divider" aria-hidden="true"></div>

    <!-- Notification bell -->
    <button class="topbar-icon-btn" aria-label="Notifications" title="Notifications">
      <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
        <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
      </svg>
      <span class="notif-dot"></span>
    </button>

    <!-- Avatar circle -->
    <div class="topbar-avatar" title="<?= htmlspecialchars($admin_name) ?>" aria-label="Avatar initials">
      <?= $admin_initials ?>
    </div>

    <!-- Logout -->
    <a href="<?= BASE_URL ?>/app/api/logout.php" class="topbar-logout" title="Sign out" aria-label="Sign out">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
        <polyline points="16 17 21 12 16 7"/>
        <line x1="21" y1="12" x2="9" y2="12"/>
      </svg>
    </a>

  </div>
</header>

<script>
(function(){
  var de = document.getElementById('adm-date');
  var te = document.getElementById('adm-time');
  if (!te) return;
  var M = ['January','February','March','April','May','June','July','August','September','October','November','December'];
  function p(n){ return n < 10 ? '0'+n : String(n); }
  function tick(){
    var d = new Date(), h = d.getHours(), ampm = h>=12?'PM':'AM';
    h = h%12||12;
    if(de) de.textContent = M[d.getMonth()]+' '+d.getDate()+', '+d.getFullYear();
    te.textContent = p(h)+':'+p(d.getMinutes())+' '+ampm;
  }
  tick(); setInterval(tick, 1000);
})();
</script>
