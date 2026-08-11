<?php
/**
 * Format triage symptoms for display (JSON array or plain text).
 */

if (!function_exists('patient_triage_row_booking_state')) {
    $bookingStatusPath = defined('BASE_PATH')
        ? BASE_PATH . '/app/includes/patient_booking_status.php'
        : dirname(__DIR__, 4) . '/app/includes/patient_booking_status.php';
    if (is_file($bookingStatusPath)) {
        require_once $bookingStatusPath;
    }
}
function mc_format_triage_symptoms(?string $raw): string
{
    if ($raw === null || $raw === '') {
        return '—';
    }
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        return htmlspecialchars(implode(', ', array_map('strval', $decoded)));
    }
    return htmlspecialchars($raw);
}

function mc_triage_risk_class(string $level): string
{
    if (in_array($level, ['1', 'high', 'emergency', 'urgent', 'EMERGENCY'], true)) {
        return 'badge-risk--high';
    }
    if (in_array($level, ['2', 'moderate', 'non-urgent', 'URGENT'], true)) {
        return 'badge-risk--moderate';
    }
    return 'badge-risk--low';
}

function mc_triage_level_label(string $level, ?string $urgency_label = null): string
{
    if ($urgency_label) {
        return htmlspecialchars($urgency_label);
    }
    $map = [
        '1' => 'Emergency',
        '2' => 'Urgent',
        '3' => 'Non-Urgent',
        'EMERGENCY' => 'Emergency',
        'URGENT' => 'Urgent',
        'NON_URGENT' => 'Non-Urgent',
    ];
    return htmlspecialchars($map[$level] ?? strtoupper($level));
}

/**
 * Patient-facing visit status (no NLP / confidence exposure).
 *
 * @param array<string, mixed> $row
 * @param PDO|null $pdo Optional — when provided, reflects actual booking state.
 * @param int $patientId
 */
function mc_patient_visit_status_label(array $row, ?PDO $pdo = null, int $patientId = 0): string
{
    $bookingState = (string) ($row['_booking_state'] ?? '');
    if ($bookingState === '' && $pdo instanceof PDO && $patientId > 0 && !empty($row['assessed_at'])) {
        $bookingState = patient_triage_row_booking_state(
            $pdo,
            $patientId,
            (string) $row['assessed_at'],
            (int) ($row['id'] ?? 0)
        );
    }

    if ($bookingState === 'booked') {
        return 'Visit booked';
    }
    if ($bookingState === 'completed') {
        return 'Visit completed';
    }

    $recStatus = strtolower((string) ($row['recommendation_status'] ?? ''));
    if ($recStatus === 'hidden') {
        return 'Visit completed';
    }
    if ($recStatus === 'pending_approval') {
        return 'Care tips in review';
    }
    if ($recStatus === 'approved') {
        return 'Approved — book a visit';
    }

    $level = strtoupper((string) ($row['level'] ?? ''));
    $label = strtolower((string) ($row['urgency_label'] ?? ''));
    $triageLevel = strtolower((string) ($row['triage_level'] ?? ''));

    if ($level === '1' || str_contains($label, 'emergency') || $triageLevel === 'emergency') {
        return 'Emergency — seek care promptly';
    }
    if ($level === '2' || str_contains($label, 'urgent') || $triageLevel === 'urgent') {
        return 'Urgent — book a time slot';
    }
    return 'Routine — book when ready';
}

function mc_patient_visit_status_class(array $row): string
{
    $bookingState = (string) ($row['_booking_state'] ?? '');
    if ($bookingState === 'booked') {
        return 'badge-risk--low';
    }
    if ($bookingState === 'completed') {
        return 'badge-risk--low';
    }

    $recStatus = strtolower((string) ($row['recommendation_status'] ?? ''));
    if ($recStatus === 'hidden') {
        return 'badge-risk--low';
    }
    if ($recStatus === 'pending_approval') {
        return 'badge-risk--moderate';
    }
    if ($recStatus === 'approved') {
        return 'badge-risk--moderate';
    }

    $level = strtoupper((string) ($row['level'] ?? ''));
    $label = strtolower((string) ($row['urgency_label'] ?? ''));
    $triageLevel = strtolower((string) ($row['triage_level'] ?? ''));

    if ($level === '1' || str_contains($label, 'emergency') || $triageLevel === 'emergency') {
        return 'badge-risk--high';
    }
    if ($level === '2' || str_contains($label, 'urgent') || $triageLevel === 'urgent') {
        return 'badge-risk--moderate';
    }
    return 'badge-risk--low';
}

/**
 * Patient-facing self-care / Care tips row (triage_results).
 *
 * @return array{label: string, class: string, show_tips: bool, active?: bool, kind: string}
 */
function mc_patient_care_tip_meta(array $row): array
{
    $status = (string) ($row['recommendation_status'] ?? '');
    $bookingState = (string) ($row['_booking_state'] ?? '');
    $historical = $status === 'hidden' || $bookingState === 'completed';
    if ($historical) {
        return [
            'label' => 'Visit completed',
            'class' => 'pmh-care-card__status--acked',
            'show_tips' => true,
            'active' => false,
            'kind' => 'historical',
        ];
    }

    $acked = !empty($row['recommendation_patient_ack_at']);
    $approvedAt = (string) ($row['recommendation_approved_at'] ?? '');
    $approvedTs = $approvedAt !== '' ? strtotime($approvedAt) : false;
    $isExpired = $approvedTs !== false && time() >= ($approvedTs + (24 * 3600));

    if ($status === 'pending_approval') {
        return [
            'label' => 'In review',
            'class' => 'pmh-care-card__status--pending',
            'show_tips' => false,
            'active' => true,
            'kind' => 'pending',
        ];
    }
    if ($status === 'rejected') {
        return [
            'label' => 'Not approved',
            'class' => 'pmh-care-card__status--rejected',
            'show_tips' => false,
            'kind' => 'rejected',
        ];
    }
    if ($status === 'approved' && $isExpired) {
        return [
            'label' => 'Expired',
            'class' => 'pmh-care-card__status--acked',
            'show_tips' => true,
            'kind' => 'expired',
        ];
    }
    if ($status === 'approved' && $acked) {
        return [
            'label' => 'Completed',
            'class' => 'pmh-care-card__status--acked',
            'show_tips' => true,
            'active' => true,
            'kind' => 'acked',
        ];
    }
    if ($status === 'approved') {
        return [
            'label' => 'Tips ready',
            'class' => 'pmh-care-card__status--ready',
            'show_tips' => true,
            'active' => true,
            'kind' => 'ready',
        ];
    }

    return [
        'label' => 'Recorded',
        'class' => 'pmh-care-card__status--default',
        'show_tips' => false,
        'kind' => 'default',
    ];
}
