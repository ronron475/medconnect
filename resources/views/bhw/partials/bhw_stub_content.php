<?php
require_once __DIR__ . '/bhw_nav.php';

$stub_title = $page_title ?? 'Module';
$stub_file = $bhw_current_file ?? '';
$stub_group = $stub_file ? bhw_nav_find_group_for_file($stub_file) : null;
$section_title = $stub_group['label'] ?? null;
$section_description = $stub_group['description'] ?? null;
$stub_description = $page_description ?? $section_description ?? 'This module is currently under development.';
?>
<div class="bhw-card" style="max-width: 720px;">
    <?php if ($section_title): ?>
    <div class="text-muted" style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 8px;">
        <?= htmlspecialchars($section_title) ?>
    </div>
    <?php endif; ?>
    <h2 class="text-h2" style="color: var(--bhw-navy); font-weight: 800; margin-bottom: 8px;"><?= htmlspecialchars($stub_title) ?></h2>
    <?php if ($section_description): ?>
    <p style="color: var(--bhw-teal); font-size: 14px; font-weight: 600; line-height: 1.5; margin-bottom: 12px;">
        <?= htmlspecialchars($section_description) ?>
    </p>
    <?php endif; ?>
    <p class="text-muted" style="line-height: 1.6; margin-bottom: 0;"><?= htmlspecialchars($stub_description) ?></p>
    <p class="text-muted" style="font-size: 12px; margin-top: 16px; margin-bottom: 0;">
        Sector: <strong>Brgy. <?= htmlspecialchars($bhw_barangay_name ?? 'Assigned') ?></strong>
    </p>
</div>
