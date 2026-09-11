<?php
/**
 * BHW community follow-up on doctor referrals.
 * Records contacted / acted-on / notes only — never mutates digital_referrals.
 */

function referral_community_followups_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS referral_community_followups (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            referral_id INT UNSIGNED NOT NULL,
            patient_id INT UNSIGNED NOT NULL,
            bhw_id INT UNSIGNED NOT NULL,
            patient_contacted TINYINT(1) NOT NULL DEFAULT 0,
            acted_on_referral TINYINT(1) NOT NULL DEFAULT 0,
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_rcf_referral (referral_id),
            INDEX idx_rcf_patient (patient_id),
            INDEX idx_rcf_bhw (bhw_id),
            INDEX idx_rcf_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $ready = true;
}

/**
 * @return list<array<string, mixed>>
 */
function referral_community_followups_for_referral(PDO $pdo, int $referralId): array
{
    if ($referralId <= 0) {
        return [];
    }
    referral_community_followups_ensure_schema($pdo);
    $stmt = $pdo->prepare("
        SELECT f.*,
               TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS bhw_name
        FROM referral_community_followups f
        LEFT JOIN users u ON u.id = f.bhw_id
        WHERE f.referral_id = ?
        ORDER BY f.created_at DESC, f.id DESC
    ");
    $stmt->execute([$referralId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Latest follow-up summary keyed by referral_id.
 *
 * @param list<int> $referralIds
 * @return array<int, array<string, mixed>>
 */
function referral_community_followups_latest_by_referral(PDO $pdo, array $referralIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $referralIds))));
    if ($ids === []) {
        return [];
    }
    referral_community_followups_ensure_schema($pdo);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("
        SELECT f.*,
               TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS bhw_name
        FROM referral_community_followups f
        LEFT JOIN users u ON u.id = f.bhw_id
        INNER JOIN (
            SELECT referral_id, MAX(id) AS max_id
            FROM referral_community_followups
            WHERE referral_id IN ({$placeholders})
            GROUP BY referral_id
        ) latest ON latest.max_id = f.id
    ");
    $stmt->execute($ids);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $out[(int) $row['referral_id']] = $row;
    }
    return $out;
}

function referral_community_followup_summary_label(array $row): string
{
    $contacted = !empty($row['patient_contacted']) ? 'Contacted' : 'Not contacted';
    $acted = !empty($row['acted_on_referral']) ? 'acted on referral' : 'not acted on';
    return $contacted . '; ' . $acted;
}
