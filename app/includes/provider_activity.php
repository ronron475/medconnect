<?php
/**
 * Provider dashboard activity feed — merges account logs and consultation events.
 */
declare(strict_types=1);

require_once __DIR__ . '/provider_password.php';

function provider_activity_time_label(string $datetime): string
{
    $ts = strtotime($datetime);
    if ($ts === false) {
        return '';
    }

    $diff = time() - $ts;
    if ($diff < 60) {
        return 'Just now';
    }
    if ($diff < 3600) {
        return (int) floor($diff / 60) . ' min ago';
    }
    if ($diff < 86400) {
        return (int) floor($diff / 3600) . ' hr ago';
    }

    return date('M j, g:i A', $ts);
}

/**
 * @return list<array{msg: string, time: string, icon: string}>
 */
function provider_load_recent_activity(PDO $pdo, int $providerId, int $limit = 8): array
{
    $items = [];

    try {
        provider_password_ensure_schema($pdo);
        $stmt = $pdo->prepare("
            SELECT action AS msg, created_at, 'lock' AS icon
            FROM provider_activity_logs
            WHERE provider_id = ?
            ORDER BY created_at DESC
            LIMIT 10
        ");
        $stmt->execute([$providerId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $createdAt = (string) ($row['created_at'] ?? '');
            $items[] = [
                'msg' => (string) ($row['msg'] ?? ''),
                'time' => provider_activity_time_label($createdAt),
                'icon' => 'lock',
                'sort_ts' => strtotime($createdAt) ?: 0,
            ];
        }
    } catch (Throwable $e) { /* non-fatal */ }

    try {
        $stmt = $pdo->prepare("
            SELECT
                CONCAT(
                    'Consultation with ',
                    TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, 'Patient'))),
                    ' — ',
                    REPLACE(c.status, '_', ' ')
                ) AS msg,
                c.created_at,
                CASE c.status
                    WHEN 'completed' THEN 'check'
                    WHEN 'in_consultation' THEN 'video'
                    WHEN 'cancelled' THEN 'x'
                    ELSE 'calendar'
                END AS icon
            FROM consultations c
            INNER JOIN users u ON u.id = c.patient_id
            WHERE c.provider_id = ?
            ORDER BY c.created_at DESC
            LIMIT 10
        ");
        $stmt->execute([$providerId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $createdAt = (string) ($row['created_at'] ?? '');
            $items[] = [
                'msg' => (string) ($row['msg'] ?? ''),
                'time' => provider_activity_time_label($createdAt),
                'icon' => (string) ($row['icon'] ?? 'calendar'),
                'sort_ts' => strtotime($createdAt) ?: 0,
            ];
        }
    } catch (Throwable $e) { /* non-fatal */ }

    usort($items, static fn(array $a, array $b): int => ($b['sort_ts'] ?? 0) <=> ($a['sort_ts'] ?? 0));
    $items = array_slice($items, 0, max(1, $limit));

    return array_map(static function (array $item): array {
        return [
            'msg' => (string) ($item['msg'] ?? ''),
            'time' => (string) ($item['time'] ?? ''),
            'icon' => (string) ($item['icon'] ?? 'activity'),
        ];
    }, $items);
}
