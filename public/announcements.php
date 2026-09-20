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
  <link rel="stylesheet" href="<?= $asset ?>/assets/css/landing-nav.css?v=3.2">
  <link rel="stylesheet" href="<?= $asset ?>/assets/css/landing-announcements.css?v=33">
  <link rel="stylesheet" href="<?= $asset ?>/assets/css/announcements-list.css?v=6">
  <link rel="stylesheet" href="<?= $asset ?>/assets/css/landing-responsive.css?v=5">
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
            <span class="ann-detail__badge"><?= htmlspecialchars($detail['category_label']) ?></span>
            <?php if (!empty($detail['is_pinned'])): ?>
            <span class="ann-list-card__featured" style="margin-left:8px;"><span aria-hidden="true">📌</span> Featured</span>
            <?php endif; ?>
            <h1 class="ann-detail__title"><?= htmlspecialchars($detail['title']) ?></h1>
            <time class="ann-detail__date"><?= date('F j, Y', strtotime($detail['publish_at'] ?? $detail['created_at'])) ?></time>
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
          ?>
          <article class="ann-list-card ann-list-card--plain">
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
              <span class="ann-list-card__cta">Read announcement <?= $arrowIcon ?></span>
            </a>
          </article>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </main>

  <script>window.ASSET_BASE = <?= json_encode($asset) ?>;</script>
</body>
</html>
