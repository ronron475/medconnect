<?php
/**
 * MedConnect — public front controller (landing page).
 * Point Apache/Nginx document root to this public/ directory in production.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap/app.php';
require_once CONFIG_PATH . '/db.php';
require_once BASE_PATH . '/app/includes/announcement_service.php';
require_once BASE_PATH . '/app/includes/landing_page_config.php';

AnnouncementService::ensureSchema($pdo);
$landing_announcements = AnnouncementService::listPublic($pdo, 6);
$landing_announcements_total = AnnouncementService::countPublic($pdo);
$landing_search_announcements = AnnouncementService::listPublic($pdo, 100);
$landing_hero = LandingPageConfig::hero($pdo);
$landing_sections = LandingPageConfig::sections($pdo);
$landing_maintenance = [
    'enabled' => LandingPageConfig::flag($pdo, 'LANDING_MAINTENANCE_BANNER'),
    'message' => LandingPageConfig::get($pdo, 'LANDING_MAINTENANCE_MESSAGE'),
];

require_once BASE_PATH . '/app/includes/auth_guard.php';
auth_redirect_if_logged_in();

require VIEWS_PATH . '/landing/home.php';
