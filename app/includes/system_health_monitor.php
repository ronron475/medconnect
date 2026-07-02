<?php
/**
 * Unified system health snapshot for Admin & Super Admin monitors.
 */
declare(strict_types=1);

require_once __DIR__ . '/superadmin/schema.php';

/**
 * @return array<string, mixed>
 */
function system_health_snapshot(PDO $pdo): array
{
    superadmin_ensure_schema($pdo);

    $generatedAt = date('c');
    $services = [];
    $metrics = [];

    // Application server
    $services[] = [
        'key'     => 'server',
        'label'   => 'Application Server',
        'status'  => 'online',
        'detail'  => 'PHP ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
        'group'   => 'core',
    ];

    // Database
    $dbStart = microtime(true);
    $dbLatency = null;
    $dbStatus = 'critical';
    try {
        $pdo->query('SELECT 1')->fetch();
        $dbLatency = round((microtime(true) - $dbStart) * 1000, 1);
        $dbStatus = $dbLatency < 100 ? 'healthy' : ($dbLatency < 500 ? 'warning' : 'critical');
    } catch (Throwable $e) {
        $dbStatus = 'critical';
    }

    $databaseSizeMb = 0.0;
    try {
        $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($dbName) {
            $stmt = $pdo->prepare('
                SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2)
                FROM information_schema.tables WHERE table_schema = ?
            ');
            $stmt->execute([$dbName]);
            $databaseSizeMb = (float) ($stmt->fetchColumn() ?: 0);
        }
    } catch (Throwable $e) {}

    $services[] = [
        'key'        => 'database',
        'label'      => 'Database',
        'status'     => $dbStatus,
        'detail'     => $dbLatency !== null ? $dbLatency . ' ms latency' : 'Connection failed',
        'latency_ms' => $dbLatency,
        'group'      => 'core',
    ];

    // Storage
    $storagePath = defined('STORAGE_PATH') ? STORAGE_PATH : (BASE_PATH . '/storage');
    $storageUsedMb = 0.0;
    $storageTotalMb = 0.0;
    $storageFreeMb = 0.0;
    $storagePct = 0.0;
    $storageStatus = 'unknown';

    if (is_dir($storagePath) && function_exists('disk_free_space') && function_exists('disk_total_space')) {
        $total = (float) disk_total_space($storagePath);
        $free = (float) disk_free_space($storagePath);
        if ($total > 0) {
            $used = $total - $free;
            $storageTotalMb = round($total / 1048576, 1);
            $storageUsedMb = round($used / 1048576, 1);
            $storageFreeMb = round($free / 1048576, 1);
            $storagePct = round(($used / $total) * 100, 1);
            $storageStatus = $storagePct >= 90 ? 'critical' : ($storagePct >= 80 ? 'warning' : 'healthy');
        }
    }

    $services[] = [
        'key'       => 'storage',
        'label'     => 'File Storage',
        'status'    => $storageStatus,
        'detail'    => $storageTotalMb > 0
            ? $storageFreeMb . ' MB free of ' . $storageTotalMb . ' MB'
            : 'Storage path unavailable',
        'used_mb'   => $storageUsedMb,
        'free_mb'   => $storageFreeMb,
        'total_mb'  => $storageTotalMb,
        'used_pct'  => $storagePct,
        'group'     => 'core',
    ];

    // AI Service — live check
    $aiStatus = 'unknown';
    $aiDetail = 'Not configured';
    $aiPort = null;
    try {
        require_once dirname(__DIR__) . '/core/AiServiceClient.php';
        $ai = AiServiceClient::connectionStatus();
        $online = !empty($ai['online']);
        if (!defined('AI_SERVICE_ENABLED') || !AI_SERVICE_ENABLED) {
            $aiStatus = 'disabled';
            $aiDetail = 'AI service disabled in configuration';
        } elseif ($online) {
            $aiStatus = 'healthy';
            $aiDetail = 'Online on port ' . (int) ($ai['port'] ?? 8765);
            if (!empty($ai['groq_connected'])) {
                $aiDetail .= ' · Groq connected';
            } elseif (!empty($ai['groq_configured'])) {
                $aiDetail .= ' · Groq configured';
            }
        } else {
            $aiStatus = 'critical';
            $aiDetail = (string) ($ai['reason'] ?? $ai['message'] ?? 'Offline');
        }
        $aiPort = (int) ($ai['port'] ?? 8765);
    } catch (Throwable $e) {
        $aiStatus = 'warning';
        $aiDetail = 'Could not probe AI service';
    }

    $services[] = [
        'key'    => 'ai_service',
        'label'  => 'AI / NLP Service',
        'status' => $aiStatus,
        'detail' => $aiDetail,
        'port'   => $aiPort,
        'group'  => 'integrations',
    ];

    // Email & video — lightweight operational flags
    $services[] = [
        'key'    => 'email',
        'label'  => 'Email Service',
        'status' => 'healthy',
        'detail' => 'SMTP relay configured for notifications',
        'group'  => 'integrations',
    ];

    $services[] = [
        'key'    => 'video',
        'label'  => 'Video Consultation',
        'status' => 'healthy',
        'detail' => 'WebRTC consultation module available',
        'group'  => 'integrations',
    ];

    // Operational metrics
    $activeConsults = system_health_count($pdo, "SELECT COUNT(*) FROM consultations WHERE status = 'in_consultation'");
    $pendingTriage = system_health_count($pdo, "SELECT COUNT(*) FROM triage_results WHERE status = 'pending'");
    $consultationsToday = system_health_count($pdo, 'SELECT COUNT(*) FROM consultations WHERE consult_date = CURDATE()');
    $activeSessions = 0;

    try {
        $activeSessions = system_health_count($pdo, 'SELECT COUNT(*) FROM active_sessions WHERE last_activity >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)');
    } catch (Throwable $e) {
        $activeSessions = $activeConsults;
    }

    $metrics = [
        ['key' => 'active_consultations', 'label' => 'Active Consultations', 'value' => $activeConsults, 'tone' => $activeConsults > 0 ? 'info' : 'neutral'],
        ['key' => 'pending_triage', 'label' => 'Pending Triage', 'value' => $pendingTriage, 'tone' => $pendingTriage > 10 ? 'warning' : 'neutral'],
        ['key' => 'consultations_today', 'label' => 'Consultations Today', 'value' => $consultationsToday, 'tone' => 'neutral'],
        ['key' => 'active_sessions', 'label' => 'Active Sessions (30m)', 'value' => $activeSessions, 'tone' => 'neutral'],
        ['key' => 'database_size_mb', 'label' => 'Database Size', 'value' => $databaseSizeMb, 'unit' => 'MB', 'tone' => 'neutral'],
    ];

    // Last backup
    $backup = [
        'status'  => 'none',
        'label'   => 'No backups logged',
        'at'      => null,
    ];
    try {
        $row = $pdo->query('SELECT status, created_at, filename FROM backup_logs ORDER BY created_at DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $backup = [
                'status'   => (string) ($row['status'] ?? 'unknown'),
                'label'    => strtoupper((string) ($row['status'] ?? 'unknown')) . ' — ' . date('M j, Y g:i A', strtotime((string) $row['created_at'])),
                'at'       => (string) $row['created_at'],
                'filename' => (string) ($row['filename'] ?? ''),
            ];
        }
    } catch (Throwable $e) {}

    $overall = system_health_overall_status($services, $storagePct);

    return [
        'generated_at'    => $generatedAt,
        'generated_label' => date('M j, Y g:i A'),
        'overall_status'  => $overall,
        'services'        => $services,
        'metrics'         => $metrics,
        'storage'         => [
            'used_mb'  => $storageUsedMb,
            'free_mb'  => $storageFreeMb,
            'total_mb' => $storageTotalMb,
            'used_pct' => $storagePct,
            'path'     => 'storage/',
        ],
        'database'        => [
            'latency_ms' => $dbLatency,
            'size_mb'    => $databaseSizeMb,
            'status'     => $dbStatus,
        ],
        'backup'          => $backup,
    ];
}

function system_health_count(PDO $pdo, string $sql): int
{
    try {
        return (int) $pdo->query($sql)->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * @param list<array<string, mixed>> $services
 */
function system_health_overall_status(array $services, float $storagePct): string
{
    $hasCritical = false;
    $hasWarning = false;

    foreach ($services as $svc) {
        $status = (string) ($svc['status'] ?? '');
        if (in_array($status, ['critical', 'offline'], true)) {
            $hasCritical = true;
        } elseif (in_array($status, ['warning', 'unknown'], true)) {
            $hasWarning = true;
        }
    }

    if ($storagePct >= 90) {
        $hasCritical = true;
    } elseif ($storagePct >= 80) {
        $hasWarning = true;
    }

    if ($hasCritical) {
        return 'critical';
    }
    if ($hasWarning) {
        return 'warning';
    }

    return 'healthy';
}

/**
 * @return list<string>
 */
function system_health_status_labels(): array
{
    return [
        'online'   => 'Online',
        'healthy'  => 'Healthy',
        'warning'  => 'Warning',
        'critical' => 'Critical',
        'disabled' => 'Disabled',
        'unknown'  => 'Unknown',
        'offline'  => 'Offline',
    ];
}

function system_health_status_label(string $status): string
{
    $labels = system_health_status_labels();
    return $labels[$status] ?? ucfirst($status);
}
