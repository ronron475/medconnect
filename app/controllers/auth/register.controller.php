<?php
// Controller for register page
require_once dirname(__DIR__, 3) . '/bootstrap/app.php';
require_once BASE_PATH . '/app/includes/landing_page_config.php';

$reg_hero = LandingPageConfig::hero($pdo);

require_once VIEWS_PATH . '/auth/register.view.php';
?>
