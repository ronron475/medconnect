<?php
/**
 * Resolve BHW sector assignment from session or users table.
 */
function bhw_resolve_context(PDO $pdo): array
{
    if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'bhw') {
        return ['allowed' => false, 'reason' => 'unauthorized'];
    }

    $barangay_id = $_SESSION['user_barangay_id'] ?? null;
    $barangay_name = $_SESSION['user_barangay_name'] ?? null;

    if (!$barangay_id) {
        $user_cols = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('barangay_id', $user_cols, true)) {
            $stmt = $pdo->prepare('
                SELECT u.barangay_id, b.name AS barangay_name
                FROM users u
                LEFT JOIN barangays b ON b.id = u.barangay_id
                WHERE u.id = ? AND u.role = ?
                LIMIT 1
            ');
            $stmt->execute([(int) $_SESSION['user_id'], 'bhw']);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['barangay_id'])) {
                $barangay_id = (int) $row['barangay_id'];
                $barangay_name = $row['barangay_name'] ?: 'Assigned Sector';
                $_SESSION['user_barangay_id'] = $barangay_id;
                $_SESSION['user_barangay_name'] = $barangay_name;
            }
        }
    }

    if (!$barangay_id) {
        return ['allowed' => false, 'reason' => 'no_sector'];
    }

    return [
        'allowed' => true,
        'barangay_id' => (int) $barangay_id,
        'barangay_name' => $barangay_name ?: 'Assigned Sector',
    ];
}
