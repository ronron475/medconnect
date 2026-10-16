<?php
/**
 * Follow-up SMS reminders through TextBee.
 * Mocked, accepted, and pending attempts block another automatic send.
 * A pending row is an unresolved claim, not proof of delivery.
 */
require_once __DIR__ . '/../core/TextBeeSmsClient.php';

function followup_sms_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS followup_sms_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            followup_id INT UNSIGNED NOT NULL,
            patient_id INT UNSIGNED NOT NULL,
            sent_by INT UNSIGNED NOT NULL,
            recipient VARCHAR(20) NOT NULL,
            message_body VARCHAR(500) NOT NULL,
            status ENUM('mocked', 'accepted', 'failed', 'duplicate', 'pending') NOT NULL,
            provider_ref VARCHAR(80) NULL,
            error_message VARCHAR(500) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_followup_sms_followup (followup_id, status),
            KEY idx_followup_sms_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $col = $pdo->query("SHOW COLUMNS FROM followup_sms_logs LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    $type = strtolower((string) ($col['Type'] ?? ''));
    if ($type !== '' && !str_contains($type, 'pending')) {
        $pdo->exec("
            ALTER TABLE followup_sms_logs
            MODIFY COLUMN status ENUM('mocked', 'accepted', 'failed', 'duplicate', 'pending') NOT NULL
        ");
    }

    $done = true;
}

/**
 * @return array{success:bool,status:string,message:string,recipient?:string,mocked?:bool}
 */
function followup_sms_send(PDO $pdo, int $followupId, int $actorId): array
{
    followup_sms_ensure_schema($pdo);
    require_once __DIR__ . '/contact_validation.php';
    require_once __DIR__ . '/consultation_followup.php';
    consultation_followup_ensure_schema($pdo);

    if ($followupId <= 0 || $actorId <= 0) {
        return ['success' => false, 'status' => 'failed', 'message' => 'Follow-up reminder could not be sent.'];
    }

    $ownTx = !$pdo->inTransaction();
    if ($ownTx) {
        $pdo->beginTransaction();
    }

    try {
        $lock = $pdo->prepare('SELECT id, patient_id, provider_id, followup_date, status, slot_id FROM followups WHERE id = ? LIMIT 1 FOR UPDATE');
        $lock->execute([$followupId]);
        $followup = $lock->fetch(PDO::FETCH_ASSOC);
        if (!$followup) {
            if ($ownTx) {
                $pdo->rollBack();
            }
            return ['success' => false, 'status' => 'failed', 'message' => 'Follow-up not found.'];
        }

        if (strtolower((string) $followup['status']) !== 'scheduled' || trim((string) $followup['followup_date']) === '') {
            if ($ownTx) {
                $pdo->rollBack();
            }
            return ['success' => false, 'status' => 'failed', 'message' => 'Only a scheduled follow-up with a date can be reminded by SMS.'];
        }

        $existing = $pdo->prepare("
            SELECT id, status, created_at
            FROM followup_sms_logs
            WHERE followup_id = ? AND status IN ('mocked', 'accepted', 'pending')
            ORDER BY id DESC
            LIMIT 1
        ");
        $existing->execute([$followupId]);
        $prior = $existing->fetch(PDO::FETCH_ASSOC);
        if ($prior) {
            followup_sms_log(
                $pdo,
                $followupId,
                (int) $followup['patient_id'],
                $actorId,
                '',
                '',
                'duplicate',
                null,
                'A reminder was already logged for this follow-up.'
            );
            if ($ownTx) {
                $pdo->commit();
            }
            return [
                'success' => false,
                'status' => 'duplicate',
                'message' => 'An SMS reminder was already recorded for this follow-up. It was not sent again.',
            ];
        }

        $patientId = (int) $followup['patient_id'];
        $patient = followup_sms_patient($pdo, $patientId);
        if (!$patient) {
            if ($ownTx) {
                $pdo->rollBack();
            }
            return ['success' => false, 'status' => 'failed', 'message' => 'Patient record was not found.'];
        }

        $mobile = followup_sms_mobile($patient, (string) ($followup['contact_number'] ?? ''));
        $phoneErr = $mobile === '' ? 'Patient has no registered mobile number.' : mc_phone_validation_error($mobile, true);
        if ($phoneErr) {
            followup_sms_log($pdo, $followupId, $patientId, $actorId, $mobile, '', 'failed', null, $phoneErr);
            if ($ownTx) {
                $pdo->commit();
            }
            return ['success' => false, 'status' => 'failed', 'message' => $phoneErr];
        }

        $canonical = mc_canonical_ph_mobile($mobile);
        $when = followup_sms_when($pdo, (string) $followup['followup_date'], (int) ($followup['slot_id'] ?? 0));
        $name = trim((string) $patient['first_name'] . ' ' . (string) $patient['last_name']);
        if ($name === '') {
            $name = 'Patient';
        }
        $body = followup_sms_body($name, $when, $followupId);
        $e164 = TextBeeSmsClient::toE164($canonical);

        if (!TextBeeSmsClient::sendEnabled()) {
            $sent = TextBeeSmsClient::send($e164, $body);
            followup_sms_log(
                $pdo,
                $followupId,
                $patientId,
                $actorId,
                $canonical,
                '',
                'mocked',
                isset($sent['provider_ref']) ? (string) $sent['provider_ref'] : null,
                null
            );
            if ($ownTx) {
                $pdo->commit();
            }
            return [
                'success' => true,
                'status' => 'mocked',
                'message' => (string) $sent['message'],
                'mocked' => true,
            ];
        }

        if (!TextBeeSmsClient::isConfigured() && !TextBeeSmsClient::hasTestTransport()) {
            followup_sms_log(
                $pdo,
                $followupId,
                $patientId,
                $actorId,
                $canonical,
                '',
                'failed',
                null,
                'TextBee is not configured.'
            );
            if ($ownTx) {
                $pdo->commit();
            }
            return [
                'success' => false,
                'status' => 'failed',
                'message' => 'SMS gateway is not configured.',
                'mocked' => false,
            ];
        }

        if (!$ownTx) {
            return [
                'success' => false,
                'status' => 'failed',
                'message' => 'SMS reminder could not be saved.',
                'mocked' => false,
            ];
        }

        $claimId = followup_sms_log(
            $pdo,
            $followupId,
            $patientId,
            $actorId,
            $canonical,
            '',
            'pending',
            null,
            'Gateway request has not been confirmed.'
        );
        $pdo->commit();
        $ownTx = false;
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('followup_sms_send: ' . $e->getMessage());
        return ['success' => false, 'status' => 'failed', 'message' => 'SMS reminder could not be saved.'];
    }

    return followup_sms_finish_live_claim($pdo, $claimId, $e164, $body);
}

/**
 * @return array{success:bool,status:string,message:string,mocked:bool}
 */
function followup_sms_finish_live_claim(PDO $pdo, int $claimId, string $e164, string $body): array
{
    $uncertain = [
        'success' => false,
        'status' => 'pending',
        'message' => 'The SMS outcome is uncertain. It will not be sent again automatically.',
        'mocked' => false,
    ];
    try {
        $sent = TextBeeSmsClient::send($e164, $body);
        $disposition = (string) ($sent['disposition'] ?? 'uncertain');
        if ($disposition === 'mocked' || !empty($sent['mocked'])) {
            $updated = $pdo->prepare("
                UPDATE followup_sms_logs
                SET status = 'mocked', provider_ref = ?, error_message = NULL
                WHERE id = ? AND status = 'pending'
            ");
            $updated->execute([
                isset($sent['provider_ref']) && is_string($sent['provider_ref']) ? mb_substr($sent['provider_ref'], 0, 80) : null,
                $claimId,
            ]);
            if ($updated->rowCount() !== 1) {
                return $uncertain;
            }
            return [
                'success' => true,
                'status' => 'mocked',
                'message' => 'SMS was not sent. TextBee live sending is disabled.',
                'mocked' => true,
            ];
        }
        if ($disposition === 'accepted') {
            if (followup_sms_test_fail_after_accept()) {
                throw new RuntimeException('simulated failure after gateway acceptance');
            }
            $updated = $pdo->prepare("
                UPDATE followup_sms_logs
                SET status = 'accepted', provider_ref = ?, error_message = NULL
                WHERE id = ? AND status = 'pending'
            ");
            $updated->execute([
                isset($sent['provider_ref']) && $sent['provider_ref'] !== null ? mb_substr((string) $sent['provider_ref'], 0, 80) : null,
                $claimId,
            ]);
            if ($updated->rowCount() !== 1) {
                return $uncertain;
            }
            return [
                'success' => true,
                'status' => 'accepted',
                'message' => 'TextBee accepted the SMS for delivery. Acceptance does not confirm the patient received it.',
                'mocked' => false,
            ];
        }
        if ($disposition === 'rejected') {
            $pdo->prepare("
                UPDATE followup_sms_logs
                SET status = 'failed', error_message = ?
                WHERE id = ? AND status = 'pending'
            ")->execute([followup_sms_sanitize_error((string) ($sent['message'] ?? 'TextBee rejected the SMS.')), $claimId]);
            return [
                'success' => false,
                'status' => 'failed',
                'message' => 'TextBee rejected the SMS. It was not accepted, so it can be tried again.',
                'mocked' => false,
            ];
        }
        $pdo->prepare("
            UPDATE followup_sms_logs
            SET error_message = ?
            WHERE id = ? AND status = 'pending'
        ")->execute([followup_sms_sanitize_error((string) ($sent['message'] ?? 'Gateway outcome was not confirmed.')), $claimId]);
        return $uncertain;
    } catch (Throwable $e) {
        error_log('followup_sms_send outcome: claim left pending');
        return $uncertain;
    }
}

function followup_sms_test_fail_after_accept(bool $enabled = false): bool
{
    static $flag = false;
    if (func_num_args() > 0) {
        $flag = $enabled;
    }
    return $flag;
}

function followup_sms_body(string $name, string $when, int $followupId): string
{
    $text = 'MedConnect: Hello ' . $name . '. Your follow-up consultation is scheduled for ' . $when
        . '. Please arrive on time or open the MedConnect patient portal. Reference #' . $followupId . '.';
    if (mb_strlen($text) > 480) {
        $text = mb_substr($text, 0, 480);
    }
    return $text;
}

/**
 * @param array<string, mixed> $patient
 */
function followup_sms_mobile(array $patient, string $followupContact): string
{
    foreach (['contact_number', 'phone'] as $key) {
        $value = trim((string) ($patient[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    return trim($followupContact);
}

function followup_sms_when(PDO $pdo, string $date, int $slotId): string
{
    $pretty = $date;
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if ($dt) {
        $pretty = $dt->format('M j, Y');
    }
    if ($slotId <= 0) {
        return $pretty;
    }
    try {
        $stmt = $pdo->prepare('SELECT start_time FROM appointment_slots WHERE id = ? LIMIT 1');
        $stmt->execute([$slotId]);
        $start = trim((string) $stmt->fetchColumn());
        if ($start !== '') {
            $time = DateTime::createFromFormat('H:i:s', $start) ?: DateTime::createFromFormat('H:i', $start);
            $pretty .= ' at ' . ($time ? $time->format('g:i A') : $start);
        }
    } catch (Throwable $e) {
        // Date alone is still enough for the reminder.
    }
    return $pretty;
}

/**
 * @return array<string, mixed>|null
 */
function followup_sms_patient(PDO $pdo, int $patientId): ?array
{
    $hasUserId = false;
    $phoneSelect = 'NULL AS phone';
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM patient_registrations')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $hasUserId = in_array('user_id', $cols, true);
    } catch (Throwable $e) {
        $hasUserId = false;
    }
    try {
        $userCols = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (in_array('phone', $userCols, true)) {
            $phoneSelect = 'u.phone';
        }
    } catch (Throwable $e) {
        $phoneSelect = 'NULL AS phone';
    }

    $join = $hasUserId
        ? '(pr.user_id = u.id OR (pr.user_id IS NULL AND pr.email = u.email))'
        : 'pr.email = u.email';
    $stmt = $pdo->prepare("
        SELECT u.first_name, u.last_name, {$phoneSelect}, pr.contact_number
        FROM users u
        LEFT JOIN patient_registrations pr ON {$join}
        WHERE u.id = ? AND u.role = 'patient'
        ORDER BY pr.id DESC
        LIMIT 1
    ");
    try {
        $stmt->execute([$patientId]);
    } catch (Throwable $e) {
        $stmt = $pdo->prepare('SELECT first_name, last_name, NULL AS phone, NULL AS contact_number FROM users WHERE id = ? AND role = ? LIMIT 1');
        $stmt->execute([$patientId, 'patient']);
    }
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Doctor SMS eligibility: owned by this provider, scheduled, and dated today or later.
 *
 * @return array{ok:bool,http:int,message:string}
 */
function followup_sms_provider_gate(PDO $pdo, int $followupId, int $providerId): array
{
    $stmt = $pdo->prepare('SELECT id, provider_id FROM followups WHERE id = ? LIMIT 1');
    $stmt->execute([$followupId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int) $row['provider_id'] !== $providerId) {
        return ['ok' => false, 'http' => 403, 'message' => 'Access denied.'];
    }

    $eligible = $pdo->prepare("
        SELECT id
        FROM followups
        WHERE id = ?
          AND provider_id = ?
          AND status = 'scheduled'
          AND followup_date >= CURDATE()
        LIMIT 1
    ");
    $eligible->execute([$followupId, $providerId]);
    if (!$eligible->fetchColumn()) {
        return [
            'ok' => false,
            'http' => 400,
            'message' => 'Only a scheduled follow-up dated today or later can be reminded by SMS.',
        ];
    }

    return ['ok' => true, 'http' => 200, 'message' => ''];
}

function followup_sms_redact_recipient(string $mobile): string
{
    $digits = preg_replace('/\D+/', '', $mobile) ?? '';
    if (strlen($digits) < 4) {
        return '';
    }
    return substr($digits, -4);
}

function followup_sms_sanitize_error(?string $error): ?string
{
    if ($error === null) {
        return null;
    }
    $text = trim($error);
    if ($text === '') {
        return null;
    }
    $text = preg_replace('/x-api-key\s*[:=]\s*\S+/i', 'x-api-key [redacted]', $text) ?? $text;
    $text = preg_replace('/(api[_-]?key|authorization|bearer)\s*[:=]\s*\S+/i', '$1 [redacted]', $text) ?? $text;
    $text = preg_replace('/\+?\d{7,}/', '[redacted]', $text) ?? $text;
    $text = preg_replace('/[A-Za-z0-9_\-]{24,}/', '[redacted]', $text) ?? $text;
    $text = trim($text);
    if ($text === '') {
        return null;
    }
    return mb_substr($text, 0, 180);
}

function followup_sms_log(
    PDO $pdo,
    int $followupId,
    int $patientId,
    int $actorId,
    string $recipient,
    string $body,
    string $status,
    ?string $providerRef,
    ?string $error
): int {
    unset($body);
    $allowed = ['mocked', 'accepted', 'failed', 'duplicate', 'pending'];
    if (!in_array($status, $allowed, true)) {
        $status = 'failed';
    }
    $pdo->prepare('
        INSERT INTO followup_sms_logs
            (followup_id, patient_id, sent_by, recipient, message_body, status, provider_ref, error_message)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ')->execute([
        $followupId,
        $patientId,
        $actorId,
        followup_sms_redact_recipient($recipient),
        '',
        $status,
        $providerRef !== null && $providerRef !== '' ? mb_substr($providerRef, 0, 80) : null,
        followup_sms_sanitize_error($error),
    ]);
    return (int) $pdo->lastInsertId();
}
