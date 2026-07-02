<?php
/**
 * Live monitoring data for admin/superadmin dashboards.
 * GET ?type=live — active/scheduled consultations
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/api/admin/_auth.php';

$type = $_GET['type'] ?? 'live';

if ($type === 'live') {
    $rows = [];
    try {
        if ($pdo->query("SHOW TABLES LIKE 'consultations'")->rowCount()) {
            $rows = $pdo->query("
                SELECT c.id, c.consult_date, c.consult_time, c.status,
                       CONCAT(p.first_name,' ',p.last_name) AS patient_name,
                       CONCAT(pr.first_name,' ',pr.last_name) AS provider_name,
                       COALESCE(vs.started_at, CONCAT(c.consult_date,' ',c.consult_time)) AS started_at
                FROM consultations c
                JOIN users p ON p.id = c.patient_id
                JOIN users pr ON pr.id = c.provider_id
                LEFT JOIN video_sessions vs ON vs.consultation_id = c.id
                WHERE c.status IN ('in_consultation','scheduled','waiting')
                ORDER BY c.consult_date DESC, c.consult_time DESC
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
            $started = $r['started_at'] ?? '';
            return [
                'id'             => (int) ($r['id'] ?? 0),
                'provider_name'  => (string) ($r['provider_name'] ?? ''),
                'patient_name'   => (string) ($r['patient_name'] ?? ''),
                'status'         => (string) ($r['status'] ?? ''),
                'started_label'  => $started !== '' ? date('M j, Y g:i A', strtotime($started)) : '—',
            ];
        }, $rows),
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown monitoring type.']);
