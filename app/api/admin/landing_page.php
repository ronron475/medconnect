<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json');

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/landing_page_config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/audit_log.php';
require_once __DIR__ . '/_auth.php';

$action = $_GET['action'] ?? $_POST['action'] ?? 'stats';
$userId = (int) ($_SESSION['user_id'] ?? 0);

try {
    switch ($action) {
        case 'stats':
            echo json_encode([
                'success' => true,
                'config' => LandingPageConfig::all($pdo),
                'hero' => LandingPageConfig::hero($pdo),
                'sections' => LandingPageConfig::sections($pdo),
                'stats' => LandingPageConfig::dashboardStats($pdo),
                'recent' => LandingPageConfig::recentAnnouncements($pdo, 8),
            ]);
            break;

        case 'save_hero':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new RuntimeException('Method not allowed.');
            }
            $pairs = [
                'LANDING_HERO_ACCENT' => trim((string) ($_POST['hero_accent'] ?? '')),
                'LANDING_HERO_LINE1' => trim((string) ($_POST['hero_line1'] ?? '')),
                'LANDING_HERO_LINE2' => trim((string) ($_POST['hero_line2'] ?? '')),
                'LANDING_HERO_SUBHEADING' => trim((string) ($_POST['hero_subheading'] ?? '')),
                'LANDING_HERO_BG_IMAGE' => trim((string) ($_POST['hero_bg_image'] ?? '')),
                'LANDING_HERO_ANIMATION' => !empty($_POST['hero_animation']) ? '1' : '0',
            ];
            foreach (['LANDING_HERO_ACCENT', 'LANDING_HERO_LINE1', 'LANDING_HERO_LINE2', 'LANDING_HERO_SUBHEADING'] as $req) {
                if ($pairs[$req] === '') {
                    throw new RuntimeException('Hero heading fields cannot be empty.');
                }
            }
            if ($pairs['LANDING_HERO_BG_IMAGE'] === '') {
                throw new RuntimeException('Background image path is required.');
            }
            LandingPageConfig::save($pdo, $pairs, $userId);
            audit_log($pdo, [
                'patient_id' => $userId,
                'action_type' => 'landing_page_updated',
                'description' => 'Updated landing page hero section.',
            ]);
            echo json_encode(['success' => true, 'message' => 'Hero section saved.', 'hero' => LandingPageConfig::hero($pdo)]);
            break;

        case 'save_settings':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new RuntimeException('Method not allowed.');
            }
            $pairs = [
                'LANDING_SECTION_ANNOUNCEMENTS' => !empty($_POST['section_announcements']) ? '1' : '0',
                'LANDING_SECTION_SERVICES' => !empty($_POST['section_services']) ? '1' : '0',
                'LANDING_SECTION_HOW_IT_WORKS' => !empty($_POST['section_how_it_works']) ? '1' : '0',
                'LANDING_SECTION_CONTACT' => !empty($_POST['section_contact']) ? '1' : '0',
                'LANDING_MAINTENANCE_BANNER' => !empty($_POST['maintenance_banner']) ? '1' : '0',
                'LANDING_MAINTENANCE_MESSAGE' => trim((string) ($_POST['maintenance_message'] ?? '')),
            ];
            LandingPageConfig::save($pdo, $pairs, $userId);
            audit_log($pdo, [
                'patient_id' => $userId,
                'action_type' => 'landing_page_updated',
                'description' => 'Updated landing page visibility settings.',
            ]);
            echo json_encode(['success' => true, 'message' => 'Landing page settings saved.', 'sections' => LandingPageConfig::sections($pdo)]);
            break;

        default:
            throw new RuntimeException('Unknown action.');
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
