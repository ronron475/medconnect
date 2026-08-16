<?php
/** @var array<int, array<string, mixed>> $slot_list */
/** @var bool $slot_actions_enabled */
$slot_actions_enabled = $slot_actions_enabled ?? false;
if (empty($slot_list)) {
    return;
}
?>
<div class="sched-slot-grid">
    <?php foreach ($slot_list as $sl):
        $slotId = (int) ($sl['id'] ?? 0);
        $status = strtolower((string) ($sl['status'] ?? 'available'));
        $is_booked = $status === 'booked' || $status === 'blocked';
        $is_past = false;
        if (!empty($slot_preview_date) && $status === 'available') {
            $nowSlot = function_exists('appointment_now') ? appointment_now() : null;
            $todayYmd = $nowSlot ? $nowSlot->format('Y-m-d') : date('Y-m-d');
            $nowHis = $nowSlot ? $nowSlot->format('H:i:s') : date('H:i:s');
            $is_past = $slot_preview_date === $todayYmd
                && substr((string) ($sl['start_time'] ?? ''), 0, 8) <= $nowHis;
        }
        if ($status === 'expired') {
            $is_past = true;
        }

        $displayStatus = appointment_slot_display_status($status, $is_past);
        $card_class = match (true) {
            $is_booked => 'is-booked',
            in_array($status, ['completed'], true) => 'is-completed',
            in_array($status, ['cancelled'], true) => 'is-cancelled',
            $is_past, $status === 'expired' => 'is-past',
            default => 'is-available',
        };
        $consultationId = (int) ($sl['consultation_id'] ?? 0);
        $pendingReschedule = (string) ($sl['reschedule_status'] ?? '') === 'pending_patient';
    ?>
    <div class="sched-slot-card <?= $card_class ?>" data-slot-id="<?= $slotId ?>">
        <div class="sched-slot-time">
            <?= date('g:i A', strtotime($sl['start_time'])) ?>
        </div>
        <div class="sched-slot-status" title="<?= htmlspecialchars($displayStatus) ?>">
            <?= htmlspecialchars($displayStatus) ?>
        </div>

        <?php if ($is_booked && !empty($sl['patient_name'])): ?>
        <div class="sched-slot-patient" title="<?= htmlspecialchars($sl['patient_name']) ?>">
            <?= htmlspecialchars($sl['patient_name']) ?>
        </div>
        <?php endif; ?>

        <?php if ($is_booked && $pendingReschedule): ?>
        <div class="sched-slot-pending">
            <p class="sched-slot-note sched-slot-note--pending">
                Reschedule pending — patient must confirm
            </p>
            <?php if (!empty($sl['reschedule_reason'])): ?>
            <p class="sched-slot-reason">
                <strong>Reason:</strong>
                <?= htmlspecialchars((string) $sl['reschedule_reason']) ?>
            </p>
            <?php endif; ?>
            <?php if (!empty($sl['reschedule_new_time'])):
                $proposedLabel = date('g:i A', strtotime((string) $sl['reschedule_new_time']));
                $wasLabel = !empty($sl['reschedule_old_time'])
                    ? date('g:i A', strtotime((string) $sl['reschedule_old_time']))
                    : '';
            ?>
            <p class="sched-slot-proposed">
                <strong>Proposed:</strong> <?= htmlspecialchars($proposedLabel) ?>
                <?php if ($wasLabel !== '' && $status === 'booked'): ?>
                <span class="sched-slot-proposed-was">(was <?= htmlspecialchars($wasLabel) ?>)</span>
                <?php endif; ?>
            </p>
            <?php endif; ?>
        </div>
        <?php elseif ($is_booked): ?>
        <p class="sched-slot-note sched-slot-note--locked">
            BOOKED — This time slot cannot be changed because a patient has an appointment.
        </p>
        <?php endif; ?>

        <?php if ($slot_actions_enabled): ?>
        <div class="sched-slot-actions">
            <?php if ($status === 'available' && !$is_past): ?>
            <button type="button" class="sched-slot-btn sched-slot-btn--danger" data-remove-slot="<?= $slotId ?>">
                Remove
            </button>
            <?php elseif ($is_booked && $consultationId > 0 && !$pendingReschedule): ?>
            <button type="button"
                    class="sched-slot-btn sched-slot-btn--primary"
                    data-reschedule-slot="<?= $slotId ?>"
                    data-consultation-id="<?= $consultationId ?>"
                    data-patient-name="<?= htmlspecialchars((string) ($sl['patient_name'] ?? 'Patient'), ENT_QUOTES) ?>"
                    data-slot-time="<?= htmlspecialchars(date('g:i A', strtotime($sl['start_time'])), ENT_QUOTES) ?>">
                Reschedule
            </button>
            <?php elseif (in_array($status, ['completed', 'cancelled', 'expired'], true) || $is_past): ?>
            <span class="sched-slot-view-only">View only</span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
