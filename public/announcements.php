<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/app/includes/announcement_service.php';

AnnouncementService::ensureSchema($pdo);

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$asset = ASSET_BASE;

if ($id > 0) {
    $detail = AnnouncementService::findPublicById($pdo, $id);
    if ($detail) {
        AnnouncementService::incrementViewCount($pdo, $id);
    }
} else {
    $detail = null;
}

$items = AnnouncementService::listPublic($pdo, 50, 0);
$total = AnnouncementService::countPublic($pdo);

$dateIcon = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="18" height="18" x="3" y="4" rx="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>';
$arrowIcon = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><path d="m13 6 6 6-6 6"/></svg>';
$backIcon = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>';
$emptyIcon = '<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $detail ? htmlspecialchars($detail['title']) . ' — ' : '' ?>Announcements — MEDCONNECT</title>
  <link rel="icon" type="image/png" href="<?= $asset ?>/assets/img/medcon_logo.png">
  <link rel="stylesheet" href="<?= $asset ?>/assets/css/style.css?v=20260701e">
  <link rel="stylesheet" href="<?= $asset ?>/assets/css/responsive.css">
  <link rel="stylesheet" href="<?= $asset ?>/assets/css/landing-nav.css?v=2.10">
  <link rel="stylesheet" href="<?= $asset ?>/assets/css/landing-announcements.css?v=20">
  <link rel="stylesheet" href="<?= $asset ?>/assets/css/announcements-list.css?v=4">
  <link rel="stylesheet" href="<?= $asset ?>/assets/css/announcement-modal.css">
</head>
<body class="landing-page ann-list-page-body">
  <?php
  $navVariant = 'ann-list';
  require dirname(__DIR__) . '/resources/views/landing/partials/landing_navbar.php';
  ?>

  <main class="ann-list-page">
    <div class="ann-list-page__inner">
      <?php if ($detail): ?>
        <article class="ann-list-detail">
          <a href="<?= $asset ?>/public/announcements.php" class="ann-list-detail__back">
            <?= $backIcon ?>
            All Announcements
          </a>
          <div class="ann-list-detail__card">
            <?php if ($detail['banner_url']): ?>
            <img class="ann-detail__banner" src="<?= htmlspecialchars($detail['banner_url']) ?>" alt="<?= htmlspecialchars($detail['title']) ?>">
            <?php endif; ?>
            <span class="ann-detail__badge"><?= htmlspecialchars($detail['category_label']) ?></span>
            <?php if (!empty($detail['is_pinned'])): ?>
            <span class="ann-list-card__featured" style="margin-left:8px;"><span aria-hidden="true">📌</span> Featured</span>
            <?php endif; ?>
            <h1 class="ann-detail__title"><?= htmlspecialchars($detail['title']) ?></h1>
            <?php if ($detail['subtitle']): ?>
            <p class="ann-detail__subtitle"><?= htmlspecialchars($detail['subtitle']) ?></p>
            <?php endif; ?>
            <time class="ann-detail__date"><?= date('F j, Y', strtotime($detail['publish_at'] ?? $detail['created_at'])) ?></time>
            <?php if ($detail['short_description']): ?>
            <p class="ann-detail__lead"><strong><?= htmlspecialchars($detail['short_description']) ?></strong></p>
            <?php endif; ?>
            <div class="ann-detail__body"><?= nl2br(htmlspecialchars($detail['content'])) ?></div>
            <?php if ($detail['attachment_url']): ?>
            <a class="ann-detail__attach" href="<?= htmlspecialchars($detail['attachment_url']) ?>" target="_blank" rel="noopener">Download attachment (PDF)</a>
            <?php endif; ?>
          </div>
        </article>
      <?php else: ?>
        <header class="ann-list-page__header">
          <p class="ann-list-page__kicker">City Health Office · Bago City</p>
          <h1 class="ann-list-page__title">All Announcements</h1>
          <p class="ann-list-page__count"><?= (int)$total ?> published announcement<?= $total === 1 ? '' : 's' ?></p>
        </header>

        <?php if (empty($items)): ?>
        <div class="ann-empty-state">
          <div class="ann-empty-state__icon" aria-hidden="true"><?= $emptyIcon ?></div>
          <h2 class="ann-empty-state__title">No announcements available</h2>
          <p class="ann-empty-state__desc">Health advisories and program updates from the City Health Office will be published here.</p>
        </div>
        <?php else: ?>
        <div class="ann-list-grid">
          <?php foreach ($items as $ann):
            $pubDate = date('M j, Y', strtotime($ann['publish_at'] ?? $ann['created_at']));
            $excerpt = $ann['short_description'] ?: mb_substr(strip_tags($ann['content']), 0, 180);
            if (mb_strlen(strip_tags($ann['content'])) > 180) $excerpt .= '…';
          ?>
          <article class="ann-list-card">
            <?php if (!empty($ann['banner_url'])): ?>
            <button type="button"
                    class="ann-list-card__media-btn"
                    data-image-url="<?= htmlspecialchars($ann['banner_url']) ?>"
                    aria-label="View full-size image for <?= htmlspecialchars($ann['title']) ?>">
              <img class="ann-list-card__img"
                   src="<?= htmlspecialchars($ann['banner_url']) ?>"
                   alt="<?= htmlspecialchars($ann['title']) ?>"
                   loading="lazy"
                   decoding="async"
                   width="1600"
                   height="900">
            </button>
            <?php endif; ?>
            <a href="?id=<?= (int)$ann['id'] ?>" class="ann-list-card__link">
              <div class="ann-list-card__meta">
                <span class="ann-list-card__badge"><?= htmlspecialchars($ann['category_label']) ?></span>
                <?php if (!empty($ann['is_pinned'])): ?>
                <span class="ann-list-card__featured"><span aria-hidden="true">📌</span> Featured</span>
                <?php endif; ?>
                <time class="ann-list-card__date" datetime="<?= htmlspecialchars($ann['publish_at'] ?? $ann['created_at']) ?>">
                  <?= $dateIcon ?>
                  <?= $pubDate ?>
                </time>
              </div>
              <h2 class="ann-list-card__title"><?= htmlspecialchars($ann['title']) ?></h2>
              <p class="ann-list-card__excerpt"><?= htmlspecialchars($excerpt) ?></p>
              <span class="ann-list-card__cta">Read announcement <?= $arrowIcon ?></span>
            </a>
          </article>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </main>

  <div id="ann-image-lightbox" class="ann-lightbox" hidden aria-modal="true" role="dialog" aria-label="Announcement image preview">
    <div class="ann-lightbox__backdrop" data-ann-lightbox-close aria-hidden="true"></div>
    <div class="ann-lightbox__dialog">
      <button type="button" class="ann-lightbox__close" data-ann-lightbox-close aria-label="Close image preview">&times;</button>
      <div class="ann-lightbox__toolbar" role="toolbar" aria-label="Image zoom controls">
        <button type="button" class="ann-lightbox__tool" data-ann-lightbox-zoom="out" aria-label="Zoom out">−</button>
        <button type="button" class="ann-lightbox__tool" data-ann-lightbox-zoom="reset" aria-label="Reset zoom">100%</button>
        <button type="button" class="ann-lightbox__tool" data-ann-lightbox-zoom="in" aria-label="Zoom in">+</button>
      </div>
      <div class="ann-lightbox__stage">
        <img class="ann-lightbox__img" src="" alt="">
      </div>
    </div>
  </div>

  <script>window.ASSET_BASE = <?= json_encode($asset) ?>;</script>
  <script src="<?= $asset ?>/assets/js/announcements-list.js?v=1"></script>
</body>
</html>
