<?php
/**
 * Resolve BHW sector assignment from the users table (authoritative), then cache in session.
 *
 * Core rule: BHW.users.barangay_id must match patient_registrations.barangay_id.
 * Session alone is never trusted for access decisions without a DB refresh.
 */
function bhw_resolve_context(PDO $pdo): array
{
    if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'bhw') {
        return ['allowed' => false, 'reason' => 'unauthorized'];
    }

    $bhwUserId = (int) $_SESSION['user_id'];
    $barangay_id = null;
    $barangay_name = null;

    try {
        $user_cols = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (in_array('barangay_id', $user_cols, true)) {
            $stmt = $pdo->prepare('
                SELECT u.barangay_id, b.name AS barangay_name
                FROM users u
                LEFT JOIN barangays b ON b.id = u.barangay_id
                WHERE u.id = ? AND u.role = ?
                LIMIT 1
            ');
            $stmt->execute([$bhwUserId, 'bhw']);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['barangay_id'])) {
                $barangay_id = (int) $row['barangay_id'];
                $barangay_name = trim((string) ($row['barangay_name'] ?? ''));
            }
        }
    } catch (Throwable $e) {
        error_log('bhw_resolve_context: ' . $e->getMessage());
    }

    if (!$barangay_id) {
        unset($_SESSION['user_barangay_id'], $_SESSION['user_barangay_name']);

        return ['allowed' => false, 'reason' => 'no_sector'];
    }

    if ($barangay_name === '') {
        $barangay_name = 'Assigned Sector';
    }

    $_SESSION['user_barangay_id'] = $barangay_id;
    $_SESSION['user_barangay_name'] = $barangay_name;

    return [
        'allowed'       => true,
        'barangay_id'   => $barangay_id,
        'barangay_name' => $barangay_name,
        'bhw_id'        => $bhwUserId,
    ];
}
