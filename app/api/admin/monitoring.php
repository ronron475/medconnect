<?php
/**
 * Live monitoring data for admin/superadmin dashboards.
 * GET ?type=live — active consultations / WebRTC sessions
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/api/admin/_auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/admin_queue_live.php';

$type = $_GET['type'] ?? 'live';

if ($type === 'live') {
    $rows = [];
    try {
        if ($pdo->query("SHOW TABLES LIKE 'consultations'")->rowCount()) {
            $consultCols = $pdo->query('SHOW COLUMNS FROM consultations')->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $hasPriority = in_array('consult_priority', $consultCols, true);
            $prioritySelect = $hasPriority ? 'c.consult_priority' : 'NULL AS consult_priority';

            $hasTriage = $pdo->query("SHOW TABLES LIKE 'triage_results'")->rowCount() > 0;
            $triageSelect = $hasTriage ? 'tr.urgency_label, tr.level AS triage_level' : 'NULL AS urgency_label, NULL AS triage_level';
            $triageJoin = '';
            if ($hasTriage) {
                $triageJoin = "
                LEFT JOIN (
                    SELECT t1.patient_id, t1.urgency_label, t1.level
                    FROM triage_results t1
                    INNER JOIN (
                        SELECT patient_id, MAX(assessed_at) AS latest_at
                        FROM triage_results
                        GROUP BY patient_id
                    ) t2 ON t2.patient_id = t1.patient_id AND t2.latest_at = t1.assessed_at
                ) tr ON tr.patient_id = c.patient_id
                ";
            }

            $hasVideo = $pdo->query("SHOW TABLES LIKE 'video_sessions'")->rowCount() > 0;
            $videoSelect = 'NULL AS started_at, NULL AS video_status';
            $videoJoin = '';
            $whereSql = "c.status = 'in_consultation'";
            $orderSql = "CONCAT(c.consult_date,' ',c.consult_time) DESC";
            if ($hasVideo) {
                $videoSelect = 'vs.started_at, vs.status AS video_status';
                $videoJoin = "
                LEFT JOIN video_sessions vs ON vs.id = (
                    SELECT vs2.id FROM video_sessions vs2
                    WHERE vs2.consultation_id = c.id
                    ORDER BY CASE WHEN vs2.status = 'active' THEN 0 ELSE 1 END, vs2.started_at DESC
                    LIMIT 1
                )
                ";
                $whereSql = "c.status = 'in_consultation' OR vs.status = 'active'";
                $orderSql = "COALESCE(vs.started_at, CONCAT(c.consult_date,' ',c.consult_time)) DESC";
            }

            $rows = $pdo->query("
                SELECT c.id, c.consult_date, c.consult_time, c.status,
                       {$prioritySelect},
                       {$triageSelect},
                       {$videoSelect},
                       CONCAT(p.first_name,' ',p.last_name) AS patient_name,
                       CONCAT(pr.first_name,' ',pr.last_name) AS provider_name
                FROM consultations c
                JOIN users p ON p.id = c.patient_id
                LEFT JOIN users pr ON pr.id = c.provider_id
                {$triageJoin}
                {$videoJoin}
                WHERE {$whereSql}
                ORDER BY {$orderSql}
                LIMIT 50
            ")->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'Could not load consultations.']);
        exit;
    }

    echo json_encode([
        'success'   => true,
        'timestamp' => date('c'),
        'count'     => count($rows),
        'rows'      => array_map(static function (array $r): array {
            $started = (string) ($r['started_at'] ?? '');
            if ($started === '') {
                $started = trim((string) ($r['consult_date'] ?? '') . ' ' . (string) ($r['consult_time'] ?? ''));
            }
            $videoStatus = strtolower(trim((string) ($r['video_status'] ?? '')));
            if ($videoStatus === 'active') {
                $connection = 'Connected';
            } elseif ($videoStatus === 'ended') {
                $connection = 'Ended';
            } else {
                $connection = 'No session';
            }

            return [
                'id'              => (int) ($r['id'] ?? 0),
                'provider_name'   => (string) ($r['provider_name'] ?? '') !== ''
                    ? (string) $r['provider_name']
                    : 'Unassigned',
                'patient_name'    => (string) ($r['patient_name'] ?? ''),
                'status'          => (string) ($r['status'] ?? ''),
                'started_label'   => $started !== '' ? date('M j, Y g:i A', strtotime($started)) : '—',
                'duration_label'  => admin_live_duration_label($started),
                'connection'      => $connection,
                'urgency_label'   => admin_queue_priority_label(
                    isset($r['consult_priority']) ? (string) $r['consult_priority'] : null,
                    isset($r['urgency_label']) ? (string) $r['urgency_label'] : null,
                    isset($r['triage_level']) ? (string) $r['triage_level'] : null
                ),
            ];
        }, $rows),
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown monitoring type.']);

function admin_live_duration_label(string $startedAt): string
{
    $startedAt = trim($startedAt);
    if ($startedAt === '') {
        return '—';
    }
    $ts = strtotime($startedAt);
    if ($ts === false) {
        return '—';
    }
    $minutes = (int) floor(max(0, time() - $ts) / 60);
    return admin_queue_minutes_phrase($minutes);
}
