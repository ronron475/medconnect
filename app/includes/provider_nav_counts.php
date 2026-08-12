<?php
/**
 * Lightweight provider sidebar badge counts (queue + active triage).
 */

require_once __DIR__ . '/provider_triage_cases.php';
require_once __DIR__ . '/urgent_followup_workflow.php';

/**
 * @return array{queue: int, triage: int, triage_urgent: int, referrals: int, followups: int, urgent_followups: int}
 */
function provider_nav_counts(PDO $pdo, int $providerId): array
{
    $queue = 0;
    $triage = 0;
    $triageUrgent = 0;
    $urgentFollowups = 0;

    if ($providerId <= 0) {
        return ['queue' => 0, 'triage' => 0, 'triage_urgent' => 0, 'referrals' => 0, 'followups' => 0, 'urgent_followups' => 0];
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

    try {
        $urgentFollowups = urgent_followup_open_count($pdo, $providerId);
    } catch (Throwable $e) {
        $urgentFollowups = 0;
    }

    return [
        'queue'             => max(0, $queue),
        'triage'            => max(0, $triage),
        'triage_urgent'     => max(0, $triageUrgent),
        'referrals'         => max(0, portal_nav_provider_referrals_count($pdo, $providerId)),
        'followups'         => max(0, portal_nav_provider_followups_count($pdo, $providerId)),
        'urgent_followups'  => max(0, $urgentFollowups),
    ];
}

function portal_nav_provider_referrals_count(PDO $pdo, int $providerId): int
{
    if ($providerId <= 0) {
        return 0;
    }
    try {
        if (!$pdo->query("SHOW TABLES LIKE 'digital_referrals'")->rowCount()) {
            return 0;
        }
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM digital_referrals WHERE provider_id = ? AND status = 'pending'");
        $stmt->execute([$providerId]);
        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function portal_nav_provider_followups_count(PDO $pdo, int $providerId): int
{
    if ($providerId <= 0) {
        return 0;
    }
    try {
        if (!$pdo->query("SHOW TABLES LIKE 'followups'")->rowCount()) {
            return 0;
        }
        // Match Follow-Up Management default "Upcoming" list:
        // scheduled, today or later, and only patients who still exist.
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM followups f
            INNER JOIN users u ON u.id = f.patient_id AND u.role = 'patient'
            WHERE f.provider_id = ?
              AND f.status = 'scheduled'
              AND f.followup_date >= CURDATE()
        ");
        $stmt->execute([$providerId]);
        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}
