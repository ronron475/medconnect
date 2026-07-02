<?php
/**
 * Shared API auth for admin portal endpoints (Admin + Super Admin).
 */
require_once dirname(__DIR__, 2) . '/includes/portal_auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

portal_api_require_admin_portal();
