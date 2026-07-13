<?php
/**
 * In-page tab navigation for merged BHW modules (records, follow-up, referrals).
 *
 * Expects: $bhw_subnav_items = [['file' => 'track.php', 'label' => 'Track'], ...]
 *          $bhw_subnav_active = 'track.php' (basename or relative path under module folder)
 */
if (empty($bhw_subnav_items) || !is_array($bhw_subnav_items)) {
    return;
}
$active = (string) ($bhw_subnav_active ?? '');
?>
<nav class="bhw-module-nav" aria-label="Module sections">
  <?php foreach ($bhw_subnav_items as $item):
    $file = (string) ($item['file'] ?? '');
    $label = (string) ($item['label'] ?? '');
    if ($file === '' || $label === '') {
        continue;
    }
    $is_active = $active === $file || str_ends_with($active, '/' . $file);
  ?>
  <a href="<?= htmlspecialchars($file) ?>"
     class="bhw-module-nav__item<?= $is_active ? ' is-active' : '' ?>"
     <?= $is_active ? 'aria-current="page"' : '' ?>>
    <?= htmlspecialchars($label) ?>
  </a>
  <?php endforeach; ?>
</nav>
