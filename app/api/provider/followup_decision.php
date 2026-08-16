<?php
/**
 * API: Post-consultation follow-up decision (provider only).
 *
 * GET  ?consultation_id=N  → current decision + real available slots for the picker.
 * POST consultation_id, follow_up_required=0|1, slot_id (optional), notes (optional)
 *
 * The patient_id and provider_id are always read from the consultation row, never
 * from the request, so a client cannot attach a follow-up to someone else.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/auth_guard.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/consultation_followup.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'provider') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$providerId = (int) $_SESSION['user_id'];
$method     = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$consultationId = $method === 'POST'
    ? (int) ($_POST['consultation_id'] ?? 0)
    : (int) ($_GET['consultation_id'] ?? 0);

if ($consultationId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Consultation is required.']);
    exit;
}

try {
    // Authoritative record: the consultation must belong to this provider.
    $stmt = $pdo->prepare("
        SELECT id, patient_id, provider_id, status
        FROM consultations
        WHERE id = ? AND provider_id = ?
        LIMIT 1
    ");
    $stmt->execute([$consultationId, $providerId]);
    $consultation = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$consultation) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied.']);
        exit;
    }

    $patientId = (int) $consultation['patient_id'];

    if ($method === 'GET') {
        consultation_followup_ensure_schema($pdo);
        $slots = consultation_followup_available_slots($pdo, $providerId);
        $existing = consultation_followup_existing_decision($pdo, $consultationId);

        echo json_encode([
            'success'            => true,
            'consultation_id'    => $consultationId,
            'consultation_status' => (string) $consultation['status'],
            'already_decided'    => $existing['decided'],
            'follow_up_required' => $existing['follow_up_required'],
            'slots'              => $slots,
            'has_slots'          => $slots !== [],
        ]);
        exit;
    }

    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
        exit;
    }

    if (!auth_csrf_validate($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
        exit;
    }

    $requiredRaw = (string) ($_POST['follow_up_required'] ?? '');
    if ($requiredRaw === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Choose whether a follow-up is required.']);
        exit;
    }

    $required = in_array(strtolower($requiredRaw), ['1', 'true', 'yes', 'on'], true);
    $slotId   = (int) ($_POST['slot_id'] ?? 0);
    $notes    = trim((string) ($_POST['notes'] ?? ''));

    if (mb_strlen($notes) > 1000) {
        $notes = mb_substr($notes, 0, 1000);
    }

    $result = consultation_followup_record_decision(
        $pdo,
        $providerId,
        $consultationId,
        $patientId,
        $required,
        $required ? $slotId : 0,
        $notes
    );

    if (!$result['success']) {
        http_response_code(409);
        echo json_encode($result);
        exit;
    }

    // Only notify on a genuinely new, scheduled follow-up.
    if (empty($result['already_decided']) && !empty($result['scheduled'])) {
        try {
            require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/notification_events.php';
            $dateStmt = $pdo->prepare('SELECT followup_date FROM followups WHERE id = ? LIMIT 1');
            $dateStmt->execute([(int) $result['followup_id']]);
            $followupDate = (string) ($dateStmt->fetchColumn() ?: '');
            if ($followupDate !== '') {
                NotificationEvents::followUpScheduled($pdo, $patientId, $followupDate, $providerId, $providerId, true);
            }
        } catch (Throwable $e) {
            error_log('followup_decision notify: ' . $e->getMessage());
        }
    }

    if (empty($result['already_decided'])) {
        try {
            require_once BASE_PATH . '/app/includes/audit_log.php';
            audit_log($pdo, [
                'patient_id'  => $patientId,
                'action_type' => 'provider_followup_decision',
                'description' => $required
                    ? ($result['scheduled'] ? 'Follow-up scheduled after consultation.' : 'Follow-up required, awaiting availability.')
                    : 'No follow-up required after consultation.',
                'meta'        => [
                    'consultation_id'    => $consultationId,
                    'provider_id'        => $providerId,
                    'follow_up_required' => $required,
                    'followup_id'        => (int) $result['followup_id'],
                    'slot_id'            => $slotId,
                ],
            ]);
        } catch (Throwable $e) {
            error_log('followup_decision audit: ' . $e->getMessage());
        }
    }

    echo json_encode($result);
} catch (PDOException $e) {
    error_log('followup_decision: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not save the follow-up decision.']);
}
