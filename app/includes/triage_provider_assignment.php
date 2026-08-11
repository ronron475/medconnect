<?php
/**
 * Provider assignment for non-urgent AI self-care review (workload balancing).
 */

declare(strict_types=1);

require_once __DIR__ . '/triage_assessment_schema.php';

/**
 * Patient-facing label for a provider account (avoids blank / placeholder names).
 */
function triage_provider_display_name(PDO $pdo, int $providerId): string
{
    if ($providerId <= 0) {
        return '';
    }

    $stmt = $pdo->prepare('
        SELECT first_name, last_name, email
        FROM users
        WHERE id = ? AND role = \'provider\'
        LIMIT 1
    ');
    $stmt->execute([$providerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return '';
    }

    $first = trim((string) ($row['first_name'] ?? ''));
    $last = trim((string) ($row['last_name'] ?? ''));
    $full = trim($first . ' ' . $last);

    $isGeneric = $full === ''
        || preg_match('/^(healthcare\s+provider|health\s+care\s+provider|provider|doctor)$/i', $full);

    if (!$isGeneric) {
        return triage_provider_format_doctor_name($full);
    }

    $email = trim((string) ($row['email'] ?? ''));
    if ($email !== '' && str_contains($email, '@')) {
        $local = (string) explode('@', $email, 2)[0];
        $local = trim(str_replace(['.', '_', '-'], ' ', $local));
        if (strlen($local) > 2) {
            return triage_provider_format_doctor_name(ucwords(strtolower($local)));
        }
    }

    return 'Assigned doctor (ID ' . $providerId . ')';
}

function triage_provider_format_doctor_name(string $name): string
{
    $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
    if ($name === '') {
        return '';
    }
    if (preg_match('/^dr\.?\s+/i', $name)) {
        return $name;
    }
    return 'Dr. ' . $name;
}

/**
 * @return array{provider_id:int,provider_name:string,locked:bool,triage_id:int}
 */
function triage_patient_review_booking_context(PDO $pdo, int $patientId): array
{
    triage_assessment_ensure_schema($pdo);
    require_once __DIR__ . '/patient_booking_status.php';

    $empty = ['provider_id' => 0, 'provider_name' => '', 'locked' => false, 'triage_id' => 0];
    if ($patientId <= 0) {
        return $empty;
    }

    $stmt = $pdo->prepare("
        SELECT tr.id, tr.assigned_provider_id,
               CONCAT(u.first_name, ' ', u.last_name) AS provider_name
        FROM triage_results tr
        LEFT JOIN users u ON u.id = tr.assigned_provider_id
        WHERE tr.patient_id = ?
          AND tr.assigned_provider_id IS NOT NULL
          AND tr.assigned_provider_id > 0
          AND TRIM(COALESCE(tr.chief_complaint, '')) <> ''
          AND tr.assessed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
          " . patient_triage_sql_active_only('tr') . "
        ORDER BY tr.assessed_at DESC
        LIMIT 1
    ");
    $stmt->execute([$patientId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return $empty;
    }

    $pid = (int) ($row['assigned_provider_id'] ?? 0);
    if ($pid <= 0) {
        return $empty;
    }

    $displayName = triage_provider_display_name($pdo, $pid);
    if ($displayName === '') {
        $displayName = trim((string) ($row['provider_name'] ?? ''));
    }

    return [
        'provider_id'   => $pid,
        'provider_name' => $displayName,
        'locked'      => true,
        'triage_id'   => (int) ($row['id'] ?? 0),
    ];
}

/**
 * Pick the active provider with the fewest open non-urgent AI review cases.
 */
function triage_assign_review_provider(PDO $pdo, int $patientId, int $preferProviderId = 0): int
{
    triage_assessment_ensure_schema($pdo);

    if ($preferProviderId > 0) {
        $chk = $pdo->prepare("
            SELECT id FROM users
            WHERE id = ? AND role = 'provider' AND is_active = 1
            LIMIT 1
        ");
        $chk->execute([$preferProviderId]);
        if ($chk->fetchColumn()) {
            return $preferProviderId;
        }
    }

    $sql = "
        SELECT u.id,
               (
                 SELECT COUNT(*)
                 FROM triage_results tr
                 WHERE tr.assigned_provider_id = u.id
                   AND tr.recommendation_status = 'pending_approval'
                   AND tr.assessed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
               ) AS open_reviews
        FROM users u
        WHERE u.role = 'provider' AND u.is_active = 1
        ORDER BY open_reviews ASC, u.id ASC
        LIMIT 1
    ";
    $id = (int) ($pdo->query($sql)->fetchColumn() ?: 0);
    if ($id > 0) {
        return $id;
    }

    return triage_fallback_provider_id($pdo, $patientId);
}

function triage_fallback_provider_id(PDO $pdo, int $patientId): int
{
    if (function_exists('patient_resolve_provider_id')) {
        $resolved = (int) patient_resolve_provider_id($pdo, $patientId);
        if ($resolved > 0) {
            return $resolved;
        }
    }

    return (int) ($pdo->query("
        SELECT id FROM users
        WHERE role = 'provider' AND is_active = 1
        ORDER BY id ASC
        LIMIT 1
    ")->fetchColumn() ?: 0);
}

function triage_bind_assigned_provider(PDO $pdo, int $triageId, int $providerId): void
{
    if ($triageId <= 0 || $providerId <= 0) {
        return;
    }
    triage_assessment_ensure_schema($pdo);
    $pdo->prepare("
        UPDATE triage_results
        SET assigned_provider_id = ?,
            assigned_at = COALESCE(assigned_at, NOW())
        WHERE id = ?
    ")->execute([$providerId, $triageId]);
}

/**
 * Count bookable (today, future time) slots for a provider.
 */
function triage_provider_bookable_slot_count_today(PDO $pdo, int $providerId): int
{
    if ($providerId <= 0) {
        return 0;
    }
    require_once __DIR__ . '/appointment_slots.php';
    appointment_slots_sync_today($pdo, $providerId);
    $bookable = appointment_slots_bookable_sql('s');
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM appointment_slots s
        WHERE s.provider_id = ?
          AND s.status = 'available'
          AND {$bookable}
    ");
    $stmt->execute([$providerId]);

    return (int) $stmt->fetchColumn();
}

/**
 * Active provider (excluding one) with the fewest open reviews who has bookable slots today.
 */
function triage_find_provider_with_slots_today(PDO $pdo, int $excludeProviderId = 0): int
{
    triage_assessment_ensure_schema($pdo);
    $ids = $pdo->query("
        SELECT id FROM users
        WHERE role = 'provider' AND is_active = 1
        ORDER BY id ASC
    ")->fetchAll(PDO::FETCH_COLUMN) ?: [];

    $withSlots = [];
    foreach ($ids as $rawId) {
        $id = (int) $rawId;
        if ($id <= 0 || $id === $excludeProviderId) {
            continue;
        }
        if (triage_provider_bookable_slot_count_today($pdo, $id) > 0) {
            $withSlots[] = $id;
        }
    }
    if ($withSlots === []) {
        return 0;
    }

    $bestId = 0;
    $bestLoad = PHP_INT_MAX;
    $loadStmt = $pdo->prepare("
        SELECT COUNT(*) FROM triage_results
        WHERE assigned_provider_id = ?
          AND recommendation_status = 'pending_approval'
          AND assessed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ");
    foreach ($withSlots as $id) {
        $loadStmt->execute([$id]);
        $load = (int) $loadStmt->fetchColumn();
        if ($load < $bestLoad) {
            $bestLoad = $load;
            $bestId = $id;
        }
    }

    return $bestId > 0 ? $bestId : (int) $withSlots[0];
}

/**
 * Booking context + today's slot availability for care-tips-locked patients.
 *
 * @return array{
 *   locked:bool,
 *   provider_id:int,
 *   provider_name:string,
 *   triage_id:int,
 *   assigned_has_slots_today:bool,
 *   alternate_available:bool
 * }
 */
function triage_patient_booking_slot_status(PDO $pdo, int $patientId): array
{
    $ctx = triage_patient_review_booking_context($pdo, $patientId);
    $providerId = (int) ($ctx['provider_id'] ?? 0);
    $out = [
        'locked' => !empty($ctx['locked']),
        'provider_id' => $providerId,
        'provider_name' => trim((string) ($ctx['provider_name'] ?? '')),
        'triage_id' => (int) ($ctx['triage_id'] ?? 0),
        'assigned_has_slots_today' => true,
        'alternate_available' => false,
    ];
    if (!$out['locked'] || $providerId <= 0) {
        return $out;
    }
    if ($out['provider_name'] === '') {
        $out['provider_name'] = triage_provider_display_name($pdo, $providerId);
    }
    $out['assigned_has_slots_today'] = triage_provider_bookable_slot_count_today($pdo, $providerId) > 0;
    if (!$out['assigned_has_slots_today']) {
        $out['alternate_available'] = triage_find_provider_with_slots_today($pdo, $providerId) > 0;
    }

    return $out;
}

/**
 * Patient-initiated reassignment when assigned doctor has no slots today.
 *
 * @return array{ok:bool,message:string,provider_id?:int,provider_name?:string}
 */
function triage_patient_request_alternate_booking_provider(PDO $pdo, int $patientId): array
{
    triage_assessment_ensure_schema($pdo);
    $status = triage_patient_booking_slot_status($pdo, $patientId);
    if (!$status['locked'] || $status['triage_id'] <= 0) {
        return ['ok' => false, 'message' => 'No active care tips assignment to update.'];
    }

    $triageId = (int) $status['triage_id'];
    $currentId = (int) $status['provider_id'];
    if ($status['assigned_has_slots_today']) {
        return [
            'ok' => false,
            'message' => 'Your assigned doctor still has open slots today. Refresh this page to book.',
        ];
    }

    $newId = triage_find_provider_with_slots_today($pdo, $currentId);
    if ($newId <= 0) {
        return [
            'ok' => false,
            'message' => 'No other doctor has open clinic slots today. Please contact the City Health Office or try again tomorrow.',
        ];
    }

    $prov = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'provider' AND is_active = 1 LIMIT 1");
    $prov->execute([$newId]);
    if (!$prov->fetchColumn()) {
        return ['ok' => false, 'message' => 'Selected doctor is no longer available.'];
    }

    triage_bind_assigned_provider($pdo, $triageId, $newId);

    require_once __DIR__ . '/audit_log.php';
    audit_log($pdo, [
        'patient_id' => $patientId,
        'action' => 'triage_patient_alternate_provider',
        'description' => "Patient #{$patientId} reassigned triage #{$triageId} from provider #{$currentId} to #{$newId} (no slots today).",
    ]);

    require_once __DIR__ . '/notification_events.php';
    $nameStmt = $pdo->prepare('SELECT CONCAT(first_name, " ", last_name) FROM users WHERE id = ? LIMIT 1');
    $nameStmt->execute([$patientId]);
    $patientName = trim((string) ($nameStmt->fetchColumn() ?: 'Patient'));
    NotificationEvents::aiSelfCareReviewRequired($pdo, $newId, $patientId, $patientName, $triageId, $patientId);

    $display = triage_provider_display_name($pdo, $newId);

    return [
        'ok' => true,
        'message' => 'You are now assigned to ' . $display . ' for today\'s booking. Care tips review will continue with this doctor.',
        'provider_id' => $newId,
        'provider_name' => $display,
    ];
}

/**
 * Enforce same-doctor booking after AI review assignment.
 */
function triage_assert_patient_may_book_provider(PDO $pdo, int $patientId, int $providerId): void
{
    $ctx = triage_patient_review_booking_context($pdo, $patientId);
    if (empty($ctx['locked']) || (int) $ctx['provider_id'] <= 0) {
        return;
    }
    if ((int) $ctx['provider_id'] === $providerId) {
        return;
    }

    $name = trim((string) ($ctx['provider_name'] ?? ''));
    if ($name === '') {
        $name = 'the doctor assigned to review your case';
    }

    throw new RuntimeException(
        'Please book your consultation with ' . $name
        . ', who reviewed your self-care guidance. Contact an administrator if you need a different provider.'
    );
}

/**
 * @return list<string>
 */
function triage_assessment_suggested_questions(?string $assessmentPayloadJson): array
{
    $payload = json_decode((string) $assessmentPayloadJson, true);
    if (!is_array($payload)) {
        return [];
    }

    $out = [];
    foreach (['suggested_questions', 'clarifying_questions', 'follow_up_questions'] as $key) {
        if (!empty($payload[$key]) && is_array($payload[$key])) {
            foreach ($payload[$key] as $q) {
                $text = is_string($q) ? trim($q) : trim((string) ($q['text'] ?? $q['question'] ?? ''));
                if ($text !== '') {
                    $out[] = $text;
                }
            }
        }
    }

    $ml = $payload['ml_layer'] ?? null;
    if (is_array($ml) && !empty($ml['precautions']) && is_array($ml['precautions'])) {
        foreach ($ml['precautions'] as $p) {
            $text = is_string($p) ? trim($p) : trim((string) ($p['text'] ?? ''));
            if ($text !== '') {
                $out[] = 'Clarify: ' . $text;
            }
        }
    }

    $complaint = trim((string) ($payload['chief_complaint'] ?? ''));
    if ($complaint !== '' && $out === []) {
        $out[] = 'How long have you had these symptoms?';
        $out[] = 'Have your symptoms worsened or improved since onset?';
        $out[] = 'Any fever, chest pain, difficulty breathing, or other red-flag symptoms?';
    }

    return array_values(array_unique($out));
}
