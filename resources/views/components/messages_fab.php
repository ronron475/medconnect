<?php
/**
 * Floating Messages quick-access button — patient & provider portals.
 * Include from layout close partials on authenticated dashboard pages.
 */
$userRole = (string) ($_SESSION['user_role'] ?? '');
if (!in_array($userRole, ['patient', 'provider'], true) || empty($_SESSION['user_id'])) {
    return;
}

$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
if ($currentPage === 'view.php') {
    $routePath = (string) ($_GET['path'] ?? '');
    if ($routePath !== '') {
        $currentPage = basename(str_replace('\\', '/', $routePath));
    }
}

if ($currentPage === 'messages.php') {
    return;
}

require_once BASE_PATH . '/app/includes/message_deletion.php';

$fabUserId = (int) $_SESSION['user_id'];
$fabUnread = 0;
if (isset($pdo) && $pdo instanceof PDO) {
    consultation_messages_ensure_schema($pdo);
    $fabUnread = message_unread_count($pdo, $fabUserId);
}

$fabLabel = $fabUnread > 0 ? 'Messages (' . $fabUnread . ' unread)' : 'Messages';
$fabBadge = $fabUnread > 99 ? '99+' : (string) $fabUnread;
$fabCssVer = (int) @filemtime(ASSETS_PATH . '/css/messages-fab.css');
$unreadSvcVer = (int) @filemtime(ASSETS_PATH . '/js/messages-unread-service.js');
$dragFabVer = (int) @filemtime(ASSETS_PATH . '/js/draggable-fab.js');
$fabJsVer = (int) @filemtime(ASSETS_PATH . '/js/messages-fab.js');
?>
<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/messages-fab.css?v=<?= $fabCssVer ?>"/>
<script src="<?= ASSET_BASE ?>/assets/js/messages-unread-service.js?v=<?= $unreadSvcVer ?>" defer></script>
<script src="<?= ASSET_BASE ?>/assets/js/draggable-fab.js?v=<?= $dragFabVer ?>" defer></script>
<button
  type="button"
  class="mc-messages-fab<?= $fabUnread > 0 ? ' has-unread' : '' ?>"
  id="mcMessagesFab"
  data-messages-fab
  data-unread="<?= (int) $fabUnread ?>"
  draggable="false"
  aria-label="<?= htmlspecialchars($fabLabel, ENT_QUOTES) ?>"
  title="<?= htmlspecialchars($fabLabel, ENT_QUOTES) ?>"
>
  <svg class="mc-messages-fab__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>
  </svg>
  <span
    class="mc-messages-fab__badge"
    data-messages-fab-badge
    <?= $fabUnread <= 0 ? 'hidden' : '' ?>
  ><?= htmlspecialchars($fabBadge, ENT_QUOTES) ?></span>
</button>
<script src="<?= ASSET_BASE ?>/assets/js/messages-fab.js?v=<?= $fabJsVer ?>" defer></script>
