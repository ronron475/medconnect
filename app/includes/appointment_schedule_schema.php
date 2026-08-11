<?php
/**
 * Runtime schema for secure appointment scheduling (slot states, reschedule audit).
 */

declare(strict_types=1);

function appointment_schedule_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $col = $pdo->query("SHOW COLUMNS FROM appointment_slots LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
        if ($col && isset($col['Type']) && stripos((string) $col['Type'], 'expired') === false) {
            $pdo->exec("
                ALTER TABLE appointment_slots
                MODIFY COLUMN status ENUM(
                    'available', 'booked', 'blocked', 'completed', 'cancelled', 'expired'
                ) NOT NULL DEFAULT 'available'
            ");
        }
    } catch (PDOException $e) {
        error_log('appointment_schedule_ensure_schema slots.status: ' . $e->getMessage());
    }

    $consultCols = $pdo->query('SHOW COLUMNS FROM consultations')->fetchAll(PDO::FETCH_COLUMN);
    $addConsult = static function (string $sql) use ($pdo): void {
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            error_log('appointment_schedule_ensure_schema consultations: ' . $e->getMessage());
        }
    };

    if (!in_array('original_consult_date', $consultCols, true)) {
        $addConsult('ALTER TABLE consultations ADD COLUMN original_consult_date DATE NULL AFTER consult_time');
    }
    if (!in_array('original_consult_time', $consultCols, true)) {
        $addConsult('ALTER TABLE consultations ADD COLUMN original_consult_time TIME NULL AFTER original_consult_date');
    }
    if (!in_array('reschedule_status', $consultCols, true)) {
        $addConsult("
            ALTER TABLE consultations
            ADD COLUMN reschedule_status ENUM('none', 'pending_patient') NOT NULL DEFAULT 'none'
            AFTER original_consult_time
        ");
    }

    try {
        $exists = $pdo->query("SHOW TABLES LIKE 'appointment_reschedule_log'")->rowCount();
        if ($exists === 0) {
            $pdo->exec("
                CREATE TABLE appointment_reschedule_log (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    consultation_id INT UNSIGNED NOT NULL,
                    provider_id INT UNSIGNED NOT NULL,
                    patient_id INT UNSIGNED NOT NULL,
                    old_slot_id INT UNSIGNED NULL,
                    new_slot_id INT UNSIGNED NULL,
                    old_date DATE NOT NULL,
                    old_time TIME NOT NULL,
                    new_date DATE NOT NULL,
                    new_time TIME NOT NULL,
                    reason TEXT NULL,
                    status ENUM('pending_patient', 'accepted', 'declined', 'cancelled') NOT NULL DEFAULT 'pending_patient',
                    requested_by INT UNSIGNED NOT NULL,
                    requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    responded_at TIMESTAMP NULL DEFAULT NULL,
                    responded_by INT UNSIGNED NULL,
                    patient_note TEXT NULL,
                    PRIMARY KEY (id),
                    KEY idx_reschedule_consultation (consultation_id),
                    KEY idx_reschedule_patient_status (patient_id, status),
                    KEY idx_reschedule_provider (provider_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    } catch (PDOException $e) {
        error_log('appointment_schedule_ensure_schema reschedule_log: ' . $e->getMessage());
    }
}
