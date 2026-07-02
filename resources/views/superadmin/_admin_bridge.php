<?php
/**
 * Reuse admin page body inside Super Admin portal shell.
 *
 * @param string $adminRelative e.g. 'gis_dashboard.php' or path under views/admin/
 * @param array<string, mixed> $vars Variables to extract before including admin logic
 */
function superadmin_render_admin_page(string $adminRelative, array $vars = []): void
{
    extract($vars, EXTR_SKIP);
    $adminFile = VIEWS_PATH . '/admin/' . ltrim($adminRelative, '/');
    if (!is_readable($adminFile)) {
        echo '<div class="mc-card"><p class="text-muted">Module not found.</p></div>';
        return;
    }

  // Capture admin page output, strip duplicate layout if present
    ob_start();
    $savedTitle = $page_title ?? null;
    include $adminFile;
    $html = ob_get_clean();

    // If admin page rendered full document, extract body content only
    if (preg_match('/<body[^>]*>(.*)<\/body>/is', $html, $m)) {
        echo $m[1];
        return;
    }
    // If it included layout, we only want inner content — admin pages always use layout
    // So we re-include admin file logic without layout via dedicated content files when needed.
    echo $html;
}
