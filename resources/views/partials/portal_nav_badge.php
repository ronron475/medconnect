<?php
declare(strict_types=1);

/**
 * Render a sidebar nav count badge.
 *
 * Expects: $portal_nav_badge_count (int), $portal_nav_badge_key (?string)
 */
$portal_nav_badge_count = max(0, (int) ($portal_nav_badge_count ?? 0));
$portal_nav_badge_key = isset($portal_nav_badge_key) ? (string) $portal_nav_badge_key : '';
if ($portal_nav_badge_key === '') {
    return;
}

$badgeClass = ($portal_nav_badge_key === 'messages')
    ? 'mc-nav-messages-badge mc-nav-count-badge'
    : 'mc-nav-count-badge';
$attrs = portal_nav_badge_data_attr($portal_nav_badge_key);
$hidden = $portal_nav_badge_count <= 0 ? ' hidden' : '';
$text = portal_nav_badge_format($portal_nav_badge_count);
?>
<span class="<?= htmlspecialchars($badgeClass) ?>" <?= $attrs ?><?= $hidden ?> aria-hidden="<?= $portal_nav_badge_count <= 0 ? 'true' : 'false' ?>"><?= htmlspecialchars($text) ?></span>
