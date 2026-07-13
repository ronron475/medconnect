<?php
/**
 * Quick Messages Panel (drawer / mobile sheet)
 * Renders only for authenticated patient/provider.
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

$rolePath = $userRole === 'provider' ? 'provider' : 'patient';
$viewAllHref = ASSET_BASE . '/views/' . $rolePath . '/messages.php';

$panelCssVer = (int) @filemtime(ASSETS_PATH . '/css/messages-quick-panel.css');
$panelJsVer  = (int) @filemtime(ASSETS_PATH . '/js/messages-quick-panel.js');
?>
<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/messages-quick-panel.css?v=<?= $panelCssVer ?>"/>

<div class="mc-msgqp-overlay" data-msgqp-overlay hidden aria-hidden="true"></div>

<aside
  class="mc-msgqp"
  data-msgqp
  data-user-id="<?= (int) ($_SESSION['user_id'] ?? 0) ?>"
  data-role="<?= htmlspecialchars($userRole, ENT_QUOTES) ?>"
  role="dialog"
  aria-modal="true"
  aria-label="Quick Messages"
  hidden
>
  <header class="mc-msgqp__head">
    <div class="mc-msgqp__title">
      <span class="mc-msgqp__icon" aria-hidden="true">💬</span>
      <span>Messages</span>
    </div>

    <div class="mc-msgqp__meta">
      <span class="mc-msgqp__unread" data-msgqp-unread>0 Unread</span>
      <a class="mc-msgqp__viewall" href="<?= htmlspecialchars($viewAllHref, ENT_QUOTES) ?>">View All</a>
      <button type="button" class="mc-msgqp__close" data-msgqp-close aria-label="Close">
        <span aria-hidden="true">×</span>
      </button>
    </div>
  </header>

  <div class="mc-msgqp__body">
    <section class="mc-msgqp__list" data-msgqp-list aria-label="Recent conversations">
      <div class="mc-msgqp__filters" role="tablist" aria-label="Conversation filter">
        <button type="button" class="mc-msgqp__filter is-active" data-msgqp-filter="inbox" role="tab" aria-selected="true">Inbox</button>
        <button type="button" class="mc-msgqp__filter" data-msgqp-filter="archived" role="tab" aria-selected="false">Archived</button>
        <button type="button" class="mc-msgqp__filter" data-msgqp-filter="all" role="tab" aria-selected="false">All</button>
      </div>
      <div class="mc-msgqp__list-scroll">
        <div class="mc-msgqp__loading" data-msgqp-loading>Loading conversations…</div>
        <div class="mc-msgqp__empty" data-msgqp-empty hidden>No conversations yet.</div>
        <div class="mc-msgqp__items" data-msgqp-items></div>
      </div>
    </section>

    <section class="mc-msgqp__thread" data-msgqp-thread hidden aria-label="Conversation">
      <div class="mc-msgqp__threadhead">
        <button type="button" class="mc-msgqp__back" data-msgqp-back aria-label="Back to conversations">←</button>
        <div class="mc-msgqp__peer">
          <div class="mc-msgqp__peername" data-msgqp-peername></div>
          <div class="mc-msgqp__peerstatus" data-msgqp-peerstatus>—</div>
        </div>
      </div>

      <div class="mc-msgqp__messages" data-msgqp-messages></div>

      <form class="mc-msgqp__composer" data-msgqp-composer>
        <input type="text" class="mc-msgqp__input" data-msgqp-input placeholder="Write a message" aria-label="Write a message" autocomplete="off"/>
        <button type="submit" class="mc-msgqp__send" data-msgqp-send>Send</button>
      </form>
    </section>
  </div>
</aside>

<script src="<?= ASSET_BASE ?>/assets/js/messages-quick-panel.js?v=<?= $panelJsVer ?>" defer></script>

