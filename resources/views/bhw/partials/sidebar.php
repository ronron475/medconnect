<?php
/**
 * BHW portal sidebar — thin wrapper around the shared admin-style sidebar.
 * Navigation content remains BHW-specific; visuals match Admin / SuperAdmin.
 */
declare(strict_types=1);

$adm_sidebar_portal = 'bhw';
require_once VIEWS_PATH . '/partials/adm_portal_sidebar.php';
