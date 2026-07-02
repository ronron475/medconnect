<?php
declare(strict_types=1);

/**
 * Landing page public content — stored in system_settings (no schema migration).
 */
require_once __DIR__ . '/system_settings.php';

final class LandingPageConfig
{
    public const KEYS = [
        'LANDING_HERO_ACCENT',
        'LANDING_HERO_LINE1',
        'LANDING_HERO_LINE2',
        'LANDING_HERO_SUBHEADING',
        'LANDING_HERO_BG_IMAGE',
        'LANDING_HERO_ANIMATION',
        'LANDING_SECTION_ANNOUNCEMENTS',
        'LANDING_SECTION_SERVICES',
        'LANDING_SECTION_HOW_IT_WORKS',
        'LANDING_SECTION_CONTACT',
        'LANDING_MAINTENANCE_BANNER',
        'LANDING_MAINTENANCE_MESSAGE',
        'LANDING_UPDATED_AT',
        'LANDING_UPDATED_BY',
    ];

    /** @return array<string, string> */
    public static function defaults(): array
    {
        return [
            'LANDING_HERO_ACCENT' => 'Online Video Call',
            'LANDING_HERO_LINE1' => 'Consultation',
            'LANDING_HERO_LINE2' => 'and AI-Powered Triage System',
            'LANDING_HERO_SUBHEADING' => 'A secure, non-emergency hybrid healthcare portal connecting patients with licensed providers through AI-assisted triage, secure video consultation, and centralized records.',
            'LANDING_HERO_BG_IMAGE' => 'assets/img/cho-hero-bg.jpg',
            'LANDING_HERO_ANIMATION' => '1',
            'LANDING_SECTION_ANNOUNCEMENTS' => '1',
            'LANDING_SECTION_SERVICES' => '1',
            'LANDING_SECTION_HOW_IT_WORKS' => '1',
            'LANDING_SECTION_CONTACT' => '1',
            'LANDING_MAINTENANCE_BANNER' => '0',
            'LANDING_MAINTENANCE_MESSAGE' => 'Scheduled maintenance in progress. Some features may be temporarily unavailable.',
            'LANDING_UPDATED_AT' => '',
            'LANDING_UPDATED_BY' => '',
        ];
    }

  /** @return array<string, string> */
    public static function all(PDO $pdo): array
    {
        $stored = system_settings_get_all($pdo);
        return array_merge(self::defaults(), array_intersect_key($stored, array_flip(self::KEYS)));
    }

    public static function get(PDO $pdo, string $key, ?string $default = null): string
    {
        $defaults = self::defaults();
        $fallback = $default ?? ($defaults[$key] ?? '');
        $val = system_settings_get($pdo, $key, $fallback);
        return $val ?? $fallback;
    }

    public static function flag(PDO $pdo, string $key): bool
    {
        return self::get($pdo, $key, '0') === '1';
    }

    /** @return array<string, mixed> */
    public static function hero(PDO $pdo): array
    {
        $c = self::all($pdo);
        $bg = trim($c['LANDING_HERO_BG_IMAGE']);
        if ($bg !== '' && !preg_match('#^https?://#i', $bg)) {
            $bg = ASSET_BASE . '/' . ltrim($bg, '/');
        }

        return [
            'accent' => $c['LANDING_HERO_ACCENT'],
            'line1' => $c['LANDING_HERO_LINE1'],
            'line2' => $c['LANDING_HERO_LINE2'],
            'subheading' => $c['LANDING_HERO_SUBHEADING'],
            'bg_image' => $bg,
            'animation' => $c['LANDING_HERO_ANIMATION'] === '1',
        ];
    }

    /** @return array<string, bool> */
    public static function sections(PDO $pdo): array
    {
        return [
            'announcements' => self::flag($pdo, 'LANDING_SECTION_ANNOUNCEMENTS'),
            'services' => self::flag($pdo, 'LANDING_SECTION_SERVICES'),
            'how_it_works' => self::flag($pdo, 'LANDING_SECTION_HOW_IT_WORKS'),
            'contact' => self::flag($pdo, 'LANDING_SECTION_CONTACT'),
        ];
    }

    /** @return array<string, mixed> */
    public static function dashboardStats(PDO $pdo): array
    {
        require_once __DIR__ . '/announcement_service.php';
        AnnouncementService::ensureSchema($pdo);
        AnnouncementService::syncStatuses($pdo);

        $total = (int) $pdo->query('SELECT COUNT(*) FROM announcements WHERE deleted_at IS NULL')->fetchColumn();
        $published = (int) $pdo->query("SELECT COUNT(*) FROM announcements WHERE status='published' AND deleted_at IS NULL")->fetchColumn();
        $drafts = (int) $pdo->query("SELECT COUNT(*) FROM announcements WHERE status='draft' AND deleted_at IS NULL")->fetchColumn();
        $featured = (int) $pdo->query("SELECT COUNT(*) FROM announcements WHERE is_pinned=1 AND deleted_at IS NULL")->fetchColumn();

        $media = 0;
        try {
            $media = (int) $pdo->query('SELECT COUNT(*) FROM media_library')->fetchColumn();
        } catch (Throwable $e) {
            $media = 0;
        }

        $updatedByName = '';
        $updatedById = (int) self::get($pdo, 'LANDING_UPDATED_BY', '0');
        if ($updatedById > 0) {
            $stmt = $pdo->prepare('SELECT CONCAT(first_name, " ", last_name) FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$updatedById]);
            $updatedByName = (string) ($stmt->fetchColumn() ?: '');
        }

        return [
            'announcements_total' => $total,
            'announcements_published' => $published,
            'announcements_drafts' => $drafts,
            'announcements_featured' => $featured,
            'announcements_active' => AnnouncementService::countPublic($pdo),
            'media_count' => $media,
            'homepage_visits' => null,
            'last_updated' => self::get($pdo, 'LANDING_UPDATED_AT', ''),
            'last_updated_by' => $updatedByName,
        ];
    }

    /** @return array<int, array> */
    public static function recentAnnouncements(PDO $pdo, int $limit = 6): array
    {
        require_once __DIR__ . '/announcement_service.php';
        $result = AnnouncementService::listAdmin($pdo, ['limit' => $limit, 'offset' => 0]);
        return $result['items'];
    }

    /** @param array<string, string> $pairs */
    public static function save(PDO $pdo, array $pairs, int $userId): void
    {
        $allowed = array_flip(self::KEYS);
        $filtered = [];
        foreach ($pairs as $key => $value) {
            if (isset($allowed[$key])) {
                $filtered[$key] = $value;
            }
        }
        $filtered['LANDING_UPDATED_AT'] = date('Y-m-d H:i:s');
        $filtered['LANDING_UPDATED_BY'] = (string) $userId;
        system_settings_set_many($pdo, $filtered, $userId);
    }
}
