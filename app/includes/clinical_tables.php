<?php
/**
 * Ensure clinical tables used by video consult, notes, and e-prescriptions exist.
 */

function clinical_tables_ensure(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS video_sessions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                consultation_id INT(11) UNSIGNED NOT NULL,
                room_token VARCHAR(64) NOT NULL,
                status ENUM('active','ended') NOT NULL DEFAULT 'active',
                started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                ended_at DATETIME NULL,
                recording_path VARCHAR(500) NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_video_room_token (room_token),
                KEY idx_video_consultation (consultation_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $e) { /* non-fatal */ }

    try {
        $cols = $pdo->query('SHOW COLUMNS FROM video_sessions')->fetchAll(PDO::FETCH_COLUMN);
        if (is_array($cols) && !in_array('recording_path', $cols, true)) {
            $pdo->exec('ALTER TABLE video_sessions ADD COLUMN recording_path VARCHAR(500) NULL AFTER ended_at');
        }
    } catch (PDOException $e) { /* non-fatal */ }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS prescriptions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                consultation_id INT(11) UNSIGNED NULL,
                patient_id INT(11) UNSIGNED NOT NULL,
                provider_id INT(11) UNSIGNED NOT NULL,
                medication_name VARCHAR(255) NOT NULL,
                dosage VARCHAR(120) NOT NULL,
                frequency VARCHAR(120) NOT NULL,
                duration VARCHAR(120) NOT NULL,
                notes TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_rx_patient (patient_id),
                KEY idx_rx_provider (provider_id),
                KEY idx_rx_consultation (consultation_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $e) { /* non-fatal */ }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS clinical_notes (
                id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                consultation_id INT(11) UNSIGNED NOT NULL,
                patient_id INT(11) UNSIGNED NOT NULL,
                provider_id INT(11) UNSIGNED NOT NULL,
                subjective TEXT NULL,
                objective TEXT NULL,
                assessment TEXT NULL,
                plan TEXT NULL,
                diagnosis TEXT NULL,
                treatment_plan TEXT NULL,
                prescription TEXT NULL,
                signature_data TEXT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY consultation_id (consultation_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $e) { /* non-fatal */ }

    $done = true;
}
