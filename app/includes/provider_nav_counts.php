<?php
/**
 * Lightweight provider sidebar badge counts (queue + active triage).
 */

require_once __DIR__ . '/provider_triage_cases.php';

/**
 * @return array{queue: int, triage: int, triage_urgent: int}
 */
function provider_nav_counts(PDO $pdo, int $providerId): array
{
    $queue = 0;
    $triage = 0;
    $triageUrgent = 0;

    if ($providerId <= 0) {
        return ['queue' => 0, 'triage' => 0, 'triage_urgent' => 0];
    }

    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM consultations
            WHERE provider_id = ?
              AND consult_date = CURDATE()
              AND status IN ('pending', 'scheduled', 'waiting', 'in_consultation')
        ");
        $stmt->execute([$providerId]);
        $queue = (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        $queue = 0;
    }

    try {
        $cases = provider_triage_cases_load($pdo, $providerId);
        $active = array_values(array_filter($cases, 'provider_triage_case_is_active'));
        $stats = provider_triage_cases_stats($active);
        $triage = (int) ($stats['total'] ?? count($active));
        $triageUrgent = (int) ($stats['urgent'] ?? 0);
    } catch (Throwable $e) {
        $triage = 0;
        $triageUrgent = 0;
    }

    return [
        'queue'         => max(0, $queue),
        'triage'        => max(0, $triage),
        'triage_urgent' => max(0, $triageUrgent),
    ];
}
