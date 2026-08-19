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
$triageSchemaPath = defined('BASE_PATH')
    ? BASE_PATH . '/app/includes/triage_assessment_schema.php'
    : dirname(__DIR__, 4) . '/app/includes/triage_assessment_schema.php';
if (is_file($triageSchemaPath)) {
    require_once $triageSchemaPath;
}

/**
 * @param array<string, mixed> $row
 */
if (!function_exists('mc_render_triage_assessment_stack')) {
    function mc_render_triage_assessment_stack(array $row, bool $showTitle = false): void
    {
        $ai = triage_ai_preliminary_label($row);
        $doctor = triage_doctor_final_label($row);
        $final = triage_final_decision_label($row);
        $finalKey = triage_doctor_final_key($row);
        $chip = $finalKey === 'emergency'
            ? 'pt-assess-chip--emergency'
            : ($finalKey === 'urgent' ? 'pt-assess-chip--urgent' : 'pt-assess-chip--routine');
        $isEmergency = $finalKey === 'emergency';
        ?>
    <div class="pt-assess-stack<?= $isEmergency ? ' pt-assess-stack--emergency' : '' ?>">
      <?php if ($showTitle): ?>
      <div class="pt-assess-stack__title">Latest Triage Assessment</div>
      <?php endif; ?>
      <div class="pt-assess-stack__row pt-assess-stack__row--ai">
        <span class="pt-assess-stack__label">Preliminary AI Assessment</span>
        <span class="pt-assess-chip pt-assess-chip--ai"><?= htmlspecialchars($ai) ?></span>
      </div>
      <div class="pt-assess-stack__row pt-assess-stack__row--doctor">
        <span class="pt-assess-stack__label">Final Doctor Assessment</span>
        <span class="pt-assess-chip <?= htmlspecialchars($chip) ?>"><?= htmlspecialchars($doctor) ?></span>
      </div>
      <div class="pt-assess-stack__row pt-assess-stack__row--final">
        <span class="pt-assess-stack__label">Final Triage Result</span>
        <span class="pt-assess-chip <?= htmlspecialchars($chip) ?>"><?= htmlspecialchars($final) ?></span>
      </div>
      <?php if ($doctor !== $ai || $final !== $ai): ?>
      <div class="pt-assess-stack__row">
        <span class="pt-assess-stack__label">Finalized By</span>
        <span class="pt-assess-chip">Doctor</span>
      </div>
      <?php endif; ?>
      <?php if ($isEmergency): ?>
      <p class="pt-assess-emergency-note">
        Your doctor classified this case as an EMERGENCY. Seek immediate in-person medical attention.
        You may continue the live consultation while arranging transfer.
      </p>
      <?php endif; ?>
    </div>
        <?php
    }
}

/**
 * Same AI vs Final stack for a consultation outcome (My Sessions, My Health, details).
 *
 * @param array<string, mixed>|null $outcome
 */
if (!function_exists('mc_render_consultation_outcome_stack')) {
    function mc_render_consultation_outcome_stack(?array $outcome, int $consultationId = 0): void
    {
        if (!is_array($outcome)) {
            return;
        }
        $final = trim((string) ($outcome['final_case_level'] ?? ''));
        if ($final === '') {
            return;
        }
        $ai = trim((string) ($outcome['ai_case_level'] ?? ''));
        $bucket = (string) ($outcome['final_case_bucket'] ?? '');
        $chip = function_exists('patient_case_level_chip_class')
            ? patient_case_level_chip_class($bucket)
            : 'pt-assess-chip--routine';
        $byDoctor = !empty($outcome['is_doctor_override'])
            || strcasecmp((string) ($outcome['finalized_by'] ?? ''), 'Doctor') === 0;
        $cid = $consultationId > 0 ? $consultationId : (int) ($outcome['consultation_id'] ?? 0);
        ?>
    <div class="pt-assess-stack"<?= $cid > 0 ? ' data-consult-id="' . (int) $cid . '"' : '' ?>>
      <?php if ($ai !== ''): ?>
      <div class="pt-assess-stack__row pt-assess-stack__row--ai">
        <span class="pt-assess-stack__label">Preliminary AI Assessment</span>
        <span class="pt-assess-chip pt-assess-chip--ai js-consult-ai"><?= htmlspecialchars($ai) ?></span>
      </div>
      <?php endif; ?>
      <div class="pt-assess-stack__row pt-assess-stack__row--final">
        <span class="pt-assess-stack__label">Final Triage Result</span>
        <span class="pt-assess-chip <?= htmlspecialchars($chip) ?> js-consult-final"><?= htmlspecialchars($final) ?></span>
      </div>
      <?php if ($byDoctor): ?>
      <div class="pt-assess-stack__row">
        <span class="pt-assess-stack__label">Finalized By</span>
        <span class="pt-assess-chip js-consult-finalized">Doctor</span>
      </div>
      <?php endif; ?>
    </div>
        <?php
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

    $finalKey = function_exists('triage_doctor_final_key') ? triage_doctor_final_key($row) : '';
    if ($finalKey === 'emergency') {
        return 'Emergency — seek care promptly';
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

    if ($finalKey === 'urgent') {
        return 'Urgent — book a time slot';
    }
    return 'Routine — book when ready';
}

/**
 * Stable filter key for Visit History (not shown as a label).
 *
 * @param array<string, mixed> $row
 */
if (!function_exists('mc_patient_visit_status_filter_key')) {
    function mc_patient_visit_status_filter_key(array $row): string
    {
        $bookingState = (string) ($row['_booking_state'] ?? '');
        $finalKey = function_exists('triage_doctor_final_key') ? triage_doctor_final_key($row) : '';
        if ($finalKey === 'emergency') {
            return 'emergency';
        }
        if ($bookingState === 'booked') {
            return 'booked';
        }
        if ($bookingState === 'completed') {
            return 'completed';
        }

        $recStatus = strtolower((string) ($row['recommendation_status'] ?? ''));
        if ($recStatus === 'hidden') {
            return 'completed';
        }
        if ($recStatus === 'pending_approval') {
            return 'review';
        }
        if ($recStatus === 'approved') {
            return 'approved';
        }
        if ($finalKey === 'urgent') {
            return 'urgent';
        }

        return 'routine';
    }
}

function mc_patient_visit_status_class(array $row): string
{
    $bookingState = (string) ($row['_booking_state'] ?? '');
    $finalKey = function_exists('triage_doctor_final_key') ? triage_doctor_final_key($row) : '';
    if ($finalKey === 'emergency') {
        return 'badge-risk--high';
    }
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

    if ($finalKey === 'urgent') {
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
