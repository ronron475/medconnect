<?php
/**
 * API: List active providers with generated appointment slots.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/provider_verification.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/appointment_slots.php';

Api::startJson();

if (empty($_SESSION['user_id'])) {
    Api::error('Unauthorized.', 403);
}

try {
    provider_verification_ensure_schema($pdo);
    $bookable = appointment_slots_bookable_sql('s');
    $stmt = $pdo->query("
        SELECT
            u.id,
            CONCAT(u.first_name, ' ', u.last_name) AS name,
            (
                SELECT COUNT(*)
                FROM appointment_slots s
                WHERE s.provider_id = u.id
                  AND s.slot_date >= CURDATE()
                  AND s.status = 'available'
                  AND {$bookable}
            ) AS available_slots
        FROM users u
        INNER JOIN provider_profiles pp ON pp.user_id = u.id AND pp.verification_status = 'verified'
        WHERE u.role = 'provider'
          AND u.is_active = 1
        ORDER BY u.first_name ASC, u.last_name ASC
    ");
    $providers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    Api::success(['providers' => $providers]);
} catch (PDOException $e) {
    Api::error('Could not load providers: ' . $e->getMessage(), 500);
}
