<?php
/** @var array<int, array> $landing_announcements */
/** @var int $landing_announcements_total */
$items = $landing_announcements ?? [];
$total = $landing_announcements_total ?? count($items);
$asset = ASSET_BASE;

$annIconSvgs = [
  'general'           => '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>',
  'health_advisory'   => '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>',
  'medical_mission'   => '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 11v6"/><path d="M19 14h6"/></svg>',
  'vaccination'       => '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m18 2 4 4"/><path d="m17 7 3-3"/><path d="M19 9 8.7 19.3c-1 1-2.5 1-3.4 0l-.6-.6c-1-1-1-2.5 0-3.4L15 5"/><path d="m9 11 4 4"/><path d="m5 19-3 3"/></svg>',
  'emergency'         => '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" x2="12" y1="9" y2="13"/><line x1="12" x2="12.01" y1="17" y2="17"/></svg>',
  'maintenance'       => '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>',
  'provider_schedule' => '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="18" height="18" x="3" y="4" rx="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>',
  'barangay_program'  => '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 9 12 2l9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
  'cho_advisory'      => '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
  'other'             => '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>',
];

$annJson = static function (array $ann): string {
  return htmlspecialchars(json_encode([
    'id' => (int)$ann['id'],
    'title' => $ann['title'],
    'subtitle' => $ann['subtitle'] ?? '',
    'category' => $ann['category'] ?? '',
    'category_label' => $ann['category_label'] ?? '',
    'short_description' => $ann['short_description'] ?? '',
    'content' => $ann['content'] ?? '',
    'banner_url' => $ann['banner_url'] ?? '',
    'attachment_url' => $ann['attachment_url'] ?? '',
    'publish_at' => $ann['publish_at'] ?? $ann['created_at'],
    'created_at' => $ann['created_at'],
  ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8');
};
?>
<section id="announcements-section" class="announcements-section" aria-labelledby="ann-section-heading">
  <div class="ann-section-inner">
    <div class="ann-slideshow" id="ann-slideshow">
      <svg class="ann-slideshow__curve ann-slideshow__curve--top" viewBox="0 0 1440 72" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
        <defs>
          <linearGradient id="ann-wave-back-top" x1="0" y1="0" x2="0" y2="72" gradientUnits="userSpaceOnUse">
            <stop offset="0" stop-color="#012A4A" stop-opacity="0"/>
            <stop offset="0.45" stop-color="#012A4A" stop-opacity="0.08"/>
            <stop offset="1" stop-color="#012A4A" stop-opacity="0.16"/>
          </linearGradient>
          <linearGradient id="ann-wave-front-top" x1="0" y1="0" x2="0" y2="72" gradientUnits="userSpaceOnUse">
            <stop offset="0" stop-color="#069396" stop-opacity="0"/>
            <stop offset="0.35" stop-color="#ffffff" stop-opacity="0.03"/>
            <stop offset="1" stop-color="#012A4A" stop-opacity="0.22"/>
          </linearGradient>
        </defs>
        <path class="ann-slideshow__curve-layer ann-slideshow__curve-layer--back" d="M0,44 C200,72 360,16 540,36 C720,56 900,20 1080,40 C1260,60 1380,28 1440,48 L1440,72 L0,72 Z" fill="url(#ann-wave-back-top)"/>
        <path class="ann-slideshow__curve-layer ann-slideshow__curve-layer--front" d="M0,36 C180,64 320,8 480,28 C640,48 800,12 960,32 C1120,52 1260,16 1440,40 L1440,72 L0,72 Z" fill="url(#ann-wave-front-top)"/>
      </svg>

      <header class="ann-slideshow__header">
        <p class="ann-slideshow__kicker">City Health Office · Bago City</p>
        <h2 id="ann-section-heading" class="ann-slideshow__title">Latest Announcements</h2>
      </header>

      <div class="ann-slideshow__panel ann-feature-reveal">
        <div class="ann-slideshow__content">
      <?php if (empty($items)): ?>
      <div class="ann-carousel ann-carousel--empty" id="ann-carousel">
        <div class="ann-carousel__viewport">
          <div class="ann-carousel__track">
            <article class="ann-carousel__slide ann-slide ann-slide--empty">
              <div class="ann-empty-state">
                <div class="ann-empty-state__icon" aria-hidden="true">
                  <?= $annIconSvgs['health_advisory'] ?>
                </div>
                <h3 class="ann-empty-state__title">No announcements available</h3>
                <p class="ann-empty-state__desc">Health advisories, vaccination schedules, and program updates from the City Health Office will appear here.</p>
              </div>
            </article>
          </div>
        </div>
      </div>
      <?php else: ?>
      <div class="ann-carousel" id="ann-carousel" data-interval="5000" aria-roledescription="carousel" aria-label="Latest announcements">
        <div class="ann-carousel__viewport">
          <div class="ann-carousel__track" id="ann-carousel-track">
            <?php foreach ($items as $i => $ann):
              $cat = $ann['category'] ?? 'general';
              $pubDate = !empty($ann['publish_at']) ? date('M j, Y', strtotime($ann['publish_at'])) : date('M j, Y', strtotime($ann['created_at']));
              $short = $ann['short_description'] ?: mb_substr(strip_tags($ann['content']), 0, 180);
              if (mb_strlen(strip_tags($ann['content'])) > 180) $short .= '…';
              $imgLoading = $i === 0 ? 'eager' : 'lazy';
            ?>
            <article class="ann-carousel__slide ann-slide ann-feature-card ann-card ann-card--clickable<?= $i === 0 ? ' is-active' : '' ?><?= !empty($ann['banner_url']) ? ' ann-slide--has-thumb' : ' ann-slide--text-only' ?>"
                     data-ann-id="<?= (int)$ann['id'] ?>"
                     data-ann-json="<?= $annJson($ann) ?>"
                     role="group"
                     aria-roledescription="slide"
                     aria-label="Announcement <?= $i + 1 ?> of <?= count($items) ?>"
                     tabindex="<?= $i === 0 ? '0' : '-1' ?>">
              <div class="ann-feature-card__shell">
                <div class="ann-feature-card__media">
                  <?php if (!empty($ann['banner_url'])): ?>
                  <button type="button"
                          class="ann-feature-card__media-btn"
                          data-image-url="<?= htmlspecialchars($ann['banner_url']) ?>"
                          aria-label="View full-size image for <?= htmlspecialchars($ann['title']) ?>">
                    <img class="ann-feature-card__img"
                         src="<?= htmlspecialchars($ann['banner_url']) ?>"
                         alt="<?= htmlspecialchars($ann['title']) ?>"
                         loading="<?= $imgLoading ?>"
                         decoding="async"
                         width="1600"
                         height="900">
                  </button>
                  <?php else: ?>
                  <div class="ann-feature-card__placeholder" aria-hidden="true"></div>
                  <?php endif; ?>
                  <div class="ann-feature-card__scrim" aria-hidden="true"></div>
                  <div class="ann-slide__caption ann-feature-card__caption">
                    <div class="ann-slide__caption-head">
                      <div class="ann-slide__badges">
                        <span class="ann-slide__badge"><?= htmlspecialchars($ann['category_label']) ?></span>
                        <?php if (!empty($ann['is_pinned'])): ?>
                        <span class="ann-slide__featured"><span class="ann-slide__featured-icon" aria-hidden="true">📌</span> Featured</span>
                        <?php endif; ?>
                      </div>
                      <time class="ann-slide__date" datetime="<?= htmlspecialchars($ann['publish_at'] ?? $ann['created_at']) ?>"><?= $pubDate ?></time>
                    </div>
                    <h3 class="ann-slide__title"><?= htmlspecialchars($ann['title']) ?></h3>
                    <?php if (!empty($ann['subtitle'])): ?>
                    <p class="ann-slide__subtitle"><?= htmlspecialchars($ann['subtitle']) ?></p>
                    <?php endif; ?>
                    <p class="ann-slide__desc"><?= htmlspecialchars($short) ?></p>
                    <button type="button" class="ann-slide__read" data-read-ann="<?= (int)$ann['id'] ?>">
                      <span>Read More</span>
                      <svg class="ann-slide__read-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><path d="m13 6 6 6-6 6"/></svg>
                    </button>
                  </div>
                </div>
              </div>
            </article>
            <?php endforeach; ?>
          </div>
        </div>

        <?php if (count($items) > 1): ?>
        <button type="button" class="ann-carousel__control ann-carousel__control--prev" id="ann-carousel-prev" aria-label="Previous announcement">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
        </button>
        <button type="button" class="ann-carousel__control ann-carousel__control--next" id="ann-carousel-next" aria-label="Next announcement">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
        </button>
        <div class="ann-carousel__dots" id="ann-carousel-dots" role="tablist" aria-label="Announcement slides">
          <?php foreach ($items as $i => $ann): ?>
          <button type="button"
                  class="ann-carousel__dot<?= $i === 0 ? ' is-active' : '' ?>"
                  data-slide="<?= $i ?>"
                  role="tab"
                  aria-label="Go to announcement <?= $i + 1 ?>"
                  aria-selected="<?= $i === 0 ? 'true' : 'false' ?>"></button>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php if ($total > 0): ?>
      <footer class="ann-slideshow__footer">
        <a href="<?= $asset ?>/public/announcements.php" class="ann-view-all-btn">
          <span>View All Announcements</span>
          <svg class="ann-view-all-btn__arrow" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><path d="m13 6 6 6-6 6"/></svg>
        </a>
      </footer>
      <?php endif; ?>
        </div>
      </div>

      <div class="ann-slideshow__bottom-transition" aria-hidden="true">
      <svg class="ann-slideshow__curve ann-slideshow__curve--bottom" viewBox="0 0 1440 72" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
        <path class="ann-slideshow__curve-layer ann-slideshow__curve-layer--bottom" d="M0,36 C180,64 320,8 480,28 C640,48 800,12 960,32 C1120,52 1260,16 1440,40 L1440,72 L0,72 Z" fill="#012A4A"/>
      </svg>
      </div>
    </div>
  </div>
</section>

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
