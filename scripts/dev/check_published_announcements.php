<?php
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/app/includes/announcement_service.php';

AnnouncementService::ensureSchema($pdo);

$published = (int) $pdo->query("SELECT COUNT(*) FROM announcements WHERE status = 'published' AND deleted_at IS NULL")->fetchColumn();
$drafts = (int) $pdo->query("SELECT COUNT(*) FROM announcements WHERE status = 'draft' AND deleted_at IS NULL")->fetchColumn();
$items = $pdo->query(
    "SELECT id, title, status, publish_at, is_featured
     FROM announcements
     WHERE status = 'published' AND deleted_at IS NULL
     ORDER BY COALESCE(publish_at, created_at) DESC
     LIMIT 6"
)->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'published_count' => $published,
    'draft_count'     => $drafts,
    'sample'          => $items,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
