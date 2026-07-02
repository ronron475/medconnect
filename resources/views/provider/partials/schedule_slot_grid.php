<?php
/** @var array<int, array<string, mixed>> $slot_list */
if (empty($slot_list)) {
    return;
}
?>
<div class="sched-slot-grid">
    <?php foreach ($slot_list as $sl):
        $is_booked = ($sl['status'] ?? '') === 'booked';
        $is_past = false;
        if (!empty($slot_preview_date) && ($sl['status'] ?? '') === 'available') {
            $is_past = $slot_preview_date === date('Y-m-d')
                && substr((string) ($sl['start_time'] ?? ''), 0, 8) <= date('H:i:s');
        }
        $card_class = $is_booked ? 'is-booked' : ($is_past ? 'is-past' : 'is-available');
    ?>
    <div class="sched-slot-card <?= $card_class ?>">
        <div class="sched-slot-time">
            <?= date('g:i A', strtotime($sl['start_time'])) ?>
        </div>
        <div class="sched-slot-status">
            <?= $is_past ? 'Passed' : ucfirst((string) ($sl['status'] ?? 'available')) ?>
        </div>
        <?php if ($is_booked && !empty($sl['patient_name'])): ?>
        <div class="sched-slot-patient" title="<?= htmlspecialchars($sl['patient_name']) ?>">
            <?= htmlspecialchars($sl['patient_name']) ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
