<?php
$current_theme = $_SESSION['user_theme'] ?? 'system';
$theme_labels = [
    'system' => ['icon' => '⚙', 'label' => 'System Default'],
    'light' => ['icon' => '☀', 'label' => 'Light Mode'],
    'dark' => ['icon' => '🌙', 'label' => 'Dark Mode'],
];
$resolved = $current_theme === 'dark' ? 'dark' : ($current_theme === 'light' ? 'light' : 'system');
$icon = $current_theme === 'dark' ? '🌙' : ($current_theme === 'light' ? '☀' : '⚙');
?>
<div class="mc-theme-toggle" data-theme-toggle>
  <button
    type="button"
    class="mc-theme-toggle__btn"
    aria-label="Theme settings"
    aria-haspopup="true"
    aria-expanded="false"
  >
    <span class="mc-theme-toggle__icon" aria-hidden="true"><?= $icon ?></span>
    <span class="mc-theme-toggle__label">Theme</span>
  </button>
  <div class="mc-theme-toggle__menu" role="menu" aria-label="Theme options">
    <?php foreach ($theme_labels as $value => $meta): ?>
    <button
      type="button"
      class="mc-theme-toggle__option<?= $current_theme === $value ? ' is-active' : '' ?>"
      role="menuitemradio"
      data-theme-value="<?= htmlspecialchars($value) ?>"
      aria-checked="<?= $current_theme === $value ? 'true' : 'false' ?>"
    >
      <span aria-hidden="true"><?= $meta['icon'] ?></span>
      <span><?= htmlspecialchars($meta['label']) ?></span>
    </button>
    <?php endforeach; ?>
  </div>
</div>
