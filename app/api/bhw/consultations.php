<?php
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/bhw_workflows.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/bhw_clinical.php';

try {
    $ctx = bhw_api_bootstrap($pdo);
    bhw_clinical_ensure_schema($pdo);

    $date = trim((string) ($_GET['date'] ?? date('Y-m-d')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        Api::error('Invalid date.', 400);
    }

    $statusFilter = isset($_GET['status']) ? trim((string) $_GET['status']) : '';
    $serverStatus = null;
    if ($statusFilter !== '' && $statusFilter !== 'active') {
        $serverStatus = $statusFilter;
    }

    $allRows = BhwWorkflows::listConsultations($pdo, $ctx, $date, null);
    $rows = $allRows;

    if ($statusFilter === 'active') {
        $rows = array_values(array_filter($allRows, static function (array $row): bool {
            $s = (string) ($row['status'] ?? '');
            return $s === 'scheduled' || $s === 'in_consultation';
        }));
    } elseif ($serverStatus !== null) {
        $rows = array_values(array_filter($allRows, static function (array $row) use ($serverStatus): bool {
            return (string) ($row['status'] ?? '') === $serverStatus;
        }));
    }

    $summary = [
        'total' => count($allRows),
        'scheduled' => 0,
        'in_consultation' => 0,
        'completed' => 0,
        'cancelled' => 0,
        'active' => 0,
        'with_consent' => 0,
    ];
    foreach ($allRows as $row) {
        $s = (string) ($row['status'] ?? '');
        if (isset($summary[$s])) {
            $summary[$s]++;
        }
        if ($s === 'scheduled' || $s === 'in_consultation') {
            $summary['active']++;
        }
        if (!empty($row['teleconsult_consent'])) {
            $summary['with_consent']++;
        }
    }

    Api::success([
        'consultations' => $rows,
        'date' => $date,
        'summary' => $summary,
        'barangay' => (string) ($ctx['barangay_name'] ?? ''),
    ]);
} catch (Throwable $e) {
    Api::error($e->getMessage(), 500);
}
