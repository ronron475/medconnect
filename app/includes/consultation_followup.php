<?php
declare(strict_types=1);

/**
 * Post-consultation follow-up decision.
 *
 * Every ended consultation gets an explicit answer to "is a follow-up required?".
 * A follow-up may be saved as REQUIRED BUT UNSCHEDULED when the provider has no
 * future availability yet — the system must never invent a time slot to fill the
 * gap. A scheduled follow-up is only accepted against a real, still-available
 * `appointment_slots` row belonging to that provider.
 */

require_once __DIR__ . '/appointment_slots.php';

/** How far ahead a follow-up may be scheduled, matching the slot generator. */
const CONSULTATION_FOLLOWUP_HORIZON_DAYS = 28;

/**
 * The repo historically had no CREATE TABLE for `followups`, and older installs
 * have followup_date NOT NULL. Mirror the migration at runtime so the decision
 * endpoint works before anyone runs SQL by hand.
 */
function consultation_followup_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `followups` (
                `id`              INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `consultation_id` INT(11) UNSIGNED NULL,
                `patient_id`      INT(11) UNSIGNED NOT NULL,
                `provider_id`     INT(11) UNSIGNED NOT NULL,
                `followup_date`   DATE NULL DEFAULT NULL,
                `slot_id`         INT(11) UNSIGNED NULL DEFAULT NULL,
                `message`         TEXT NULL,
                `notes`           TEXT NULL,
                `contact_number`  VARCHAR(32) NULL,
                `status`          VARCHAR(20) NOT NULL DEFAULT 'scheduled',
                `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_followup_patient` (`patient_id`, `status`),
                KEY `idx_followup_provider_date` (`provider_id`, `followup_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) {
        // Table already exists in a shape we cannot create; patch it below instead.
    }

    try {
        $cols = $pdo->query('SHOW COLUMNS FROM followups')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $byName = [];
        foreach ($cols as $col) {
            $byName[(string) $col['Field']] = $col;
        }

        if (!isset($byName['notes'])) {
            $pdo->exec('ALTER TABLE followups ADD COLUMN notes TEXT NULL AFTER message');
        }
        if (!isset($byName['contact_number'])) {
            $pdo->exec('ALTER TABLE followups ADD COLUMN contact_number VARCHAR(32) NULL AFTER notes');
        }
        if (!isset($byName['slot_id'])) {
            $pdo->exec('ALTER TABLE followups ADD COLUMN slot_id INT(11) UNSIGNED NULL DEFAULT NULL AFTER followup_date');
        }
        // "Required but unscheduled" needs a nullable date...
        if (isset($byName['followup_date']) && strtoupper((string) $byName['followup_date']['Null']) === 'NO') {
            $pdo->exec('ALTER TABLE followups MODIFY COLUMN followup_date DATE NULL DEFAULT NULL');
        }
        // ...and a status the deployed ENUM does not yet contain.
        if (isset($byName['status'])) {
            $type = strtolower((string) $byName['status']['Type']);
            if (str_starts_with($type, 'enum') && !str_contains($type, "'unscheduled'")) {
                $pdo->exec("
                    ALTER TABLE followups
                    MODIFY COLUMN status
                        ENUM('unscheduled','scheduled','completed','missed','cancelled')
                        NOT NULL DEFAULT 'scheduled'
                ");
            }
        }
    } catch (Throwable $e) {
        // Non-fatal: the insert below degrades to whatever columns exist.
    }

    try {
        $cCols = $pdo->query('SHOW COLUMNS FROM consultations')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (!in_array('follow_up_required', $cCols, true)) {
            $pdo->exec('ALTER TABLE consultations ADD COLUMN follow_up_required TINYINT(1) NULL DEFAULT NULL');
        }
        if (!in_array('follow_up_decided_at', $cCols, true)) {
            $pdo->exec('ALTER TABLE consultations ADD COLUMN follow_up_decided_at DATETIME NULL DEFAULT NULL');
        }
        if (!in_array('follow_up_id', $cCols, true)) {
            $pdo->exec('ALTER TABLE consultations ADD COLUMN follow_up_id INT(11) UNSIGNED NULL DEFAULT NULL');
        }
    } catch (Throwable $e) {
        // Non-fatal.
    }
}

/**
 * Real bookable follow-up slots for this provider: future, still available, and
 * generated from the provider's own weekly schedule.
 *
 * @return list<array{id: int, slot_date: string, start_time: string, end_time: string, label: string}>
 */
function consultation_followup_available_slots(PDO $pdo, int $providerId, int $limit = 60): array
{
    if ($providerId <= 0) {
        return [];
    }

    try {
        appointment_slots_sync_provider($pdo, $providerId, CONSULTATION_FOLLOWUP_HORIZON_DAYS);
        appointment_slots_expire_passed($pdo, $providerId);
    } catch (Throwable $e) {
        // Fall through and read whatever is already generated.
    }

    try {
        $stmt = $pdo->prepare("
            SELECT id, slot_date, start_time, end_time
            FROM appointment_slots
            WHERE provider_id = ?
              AND status = 'available'
              AND (
                    slot_date > CURDATE()
                 OR (slot_date = CURDATE() AND start_time > CURTIME())
              )
              AND slot_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
            ORDER BY slot_date ASC, start_time ASC
            LIMIT " . max(1, min(200, $limit)) . "
        ");
        $stmt->execute([$providerId, CONSULTATION_FOLLOWUP_HORIZON_DAYS]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $slots = [];
    foreach ($rows as $row) {
        $date = (string) $row['slot_date'];
        $start = (string) $row['start_time'];
        $slots[] = [
            'id'         => (int) $row['id'],
            'slot_date'  => $date,
            'start_time' => $start,
            'end_time'   => (string) $row['end_time'],
            'label'      => date('D, M j, Y', strtotime($date)) . ' · ' . date('g:i A', strtotime($date . ' ' . $start)),
        ];
    }

    return $slots;
}

/**
 * Validate a chosen slot belongs to this provider, is still free, and is in the
 * future. Returns the slot row or null.
 *
 * @return array<string, mixed>|null
 */
function consultation_followup_validate_slot(PDO $pdo, int $providerId, int $slotId): ?array
{
    if ($providerId <= 0 || $slotId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT id, provider_id, slot_date, start_time, end_time, status
        FROM appointment_slots
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$slotId]);
    $slot = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$slot) {
        return null;
    }
    if ((int) $slot['provider_id'] !== $providerId) {
        return null;
    }
    if ((string) $slot['status'] !== 'available') {
        return null;
    }

    $start = appointment_slot_start_datetime((string) $slot['slot_date'], (string) $slot['start_time']);
    if ($start <= appointment_now()) {
        return null;
    }

    return $slot;
}

/**
 * Has this consultation already been decided? Makes a repeated submit a no-op
 * instead of a second follow-up row.
 *
 * @return array{decided: bool, follow_up_required: ?int, follow_up_id: ?int}
 */
function consultation_followup_existing_decision(PDO $pdo, int $consultationId): array
{
    $blank = ['decided' => false, 'follow_up_required' => null, 'follow_up_id' => null];
    if ($consultationId <= 0) {
        return $blank;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT follow_up_required, follow_up_decided_at, follow_up_id
            FROM consultations
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$consultationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return $blank;
    }

    if (!$row || $row['follow_up_decided_at'] === null) {
        return $blank;
    }

    return [
        'decided'            => true,
        'follow_up_required' => $row['follow_up_required'] === null ? null : (int) $row['follow_up_required'],
        'follow_up_id'       => $row['follow_up_id'] === null ? null : (int) $row['follow_up_id'],
    ];
}

/**
 * Record the provider's decision.
 *
 * $slotId > 0  → schedule against that real slot (validated, then claimed).
 * $slotId = 0  → "follow-up required, no slot available yet" (followup_date NULL).
 * $required=false → no follow-up; nothing is written to `followups`.
 *
 * @return array{success: bool, message: string, followup_id: int, scheduled: bool, already_decided?: bool}
 */
function consultation_followup_record_decision(
    PDO $pdo,
    int $providerId,
    int $consultationId,
    int $patientId,
    bool $required,
    int $slotId = 0,
    string $notes = ''
): array {
    consultation_followup_ensure_schema($pdo);

    $existing = consultation_followup_existing_decision($pdo, $consultationId);
    if ($existing['decided']) {
        $existingId = (int) ($existing['follow_up_id'] ?? 0);
        // A saved follow-up may still be unscheduled, so read the row rather
        // than inferring "scheduled" from the id existing.
        $wasScheduled = false;
        if ($existingId > 0) {
            $check = $pdo->prepare('SELECT slot_id FROM followups WHERE id = ? LIMIT 1');
            $check->execute([$existingId]);
            $wasScheduled = (int) ($check->fetchColumn() ?: 0) > 0;
        }

        return [
            'success'         => true,
            'already_decided' => true,
            'followup_id'     => $existingId,
            'scheduled'       => $wasScheduled,
            'message'         => 'Follow-up decision was already saved for this consultation.',
        ];
    }

    if (!$required) {
        consultation_followup_save_decision_flag($pdo, $consultationId, false, 0);

        return [
            'success'     => true,
            'followup_id' => 0,
            'scheduled'   => false,
            'message'     => 'Consultation completed. No follow-up required.',
        ];
    }

    $slot = null;
    if ($slotId > 0) {
        $slot = consultation_followup_validate_slot($pdo, $providerId, $slotId);
        if ($slot === null) {
            return [
                'success'     => false,
                'followup_id' => 0,
                'scheduled'   => false,
                'message'     => 'That follow-up slot is no longer available. Pick another time from your schedule.',
            ];
        }
    }

    $followupDate = $slot !== null ? (string) $slot['slot_date'] : null;
    $status = $slot !== null ? 'scheduled' : 'unscheduled';
    $message = $slot !== null
        ? 'Follow-up scheduled after video consultation.'
        : 'Follow-up required. No provider availability yet — to be scheduled once slots open.';

    $stmt = $pdo->prepare("
        INSERT INTO followups
            (consultation_id, patient_id, provider_id, followup_date, slot_id, message, notes, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $consultationId ?: null,
        $patientId,
        $providerId,
        $followupDate,
        $slot !== null ? (int) $slot['id'] : null,
        $message,
        $notes !== '' ? $notes : null,
        $status,
    ]);
    $followupId = (int) $pdo->lastInsertId();

    // Hold the slot so another patient cannot take the follow-up time.
    if ($slot !== null) {
        $claim = $pdo->prepare("
            UPDATE appointment_slots
            SET status = 'blocked',
                patient_id = ?
            WHERE id = ?
              AND provider_id = ?
              AND status = 'available'
        ");
        $claim->execute([$patientId, (int) $slot['id'], $providerId]);

        if ($claim->rowCount() === 0) {
            // Lost the race: keep the follow-up but downgrade it to unscheduled
            // rather than pointing at a slot someone else now owns.
            $pdo->prepare("
                UPDATE followups
                SET followup_date = NULL, slot_id = NULL, status = 'unscheduled'
                WHERE id = ?
            ")->execute([$followupId]);

            consultation_followup_save_decision_flag($pdo, $consultationId, true, $followupId);

            return [
                'success'     => true,
                'followup_id' => $followupId,
                'scheduled'   => false,
                'message'     => 'That time was taken while saving. Follow-up is flagged as required but not scheduled.',
            ];
        }
    }

    consultation_followup_save_decision_flag($pdo, $consultationId, true, $followupId);

    return [
        'success'     => true,
        'followup_id' => $followupId,
        'scheduled'   => $slot !== null,
        'message'     => $slot !== null
            ? 'Follow-up scheduled for ' . date('D, M j, Y', strtotime((string) $followupDate))
                . ' at ' . date('g:i A', strtotime((string) $slot['slot_date'] . ' ' . (string) $slot['start_time'])) . '.'
            : 'Follow-up marked as required. No available slots yet — schedule it once you add availability.',
    ];
}

/** Write the decision onto the consultation exactly once. */
function consultation_followup_save_decision_flag(
    PDO $pdo,
    int $consultationId,
    bool $required,
    int $followupId
): void {
    if ($consultationId <= 0) {
        return;
    }

    try {
        $pdo->prepare("
            UPDATE consultations
            SET follow_up_required = ?,
                follow_up_decided_at = COALESCE(follow_up_decided_at, NOW()),
                follow_up_id = ?
            WHERE id = ?
              AND follow_up_decided_at IS NULL
        ")->execute([
            $required ? 1 : 0,
            $followupId > 0 ? $followupId : null,
            $consultationId,
        ]);
    } catch (Throwable $e) {
        // Non-fatal: the followups row is the source of truth for the reminder.
    }
}
