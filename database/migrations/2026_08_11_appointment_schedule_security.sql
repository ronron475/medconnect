-- Secure doctor schedule & availability: slot states, original booking, reschedule audit.
-- Note: MySQL versions without IF NOT EXISTS are applied via appointment_schedule_ensure_schema().

ALTER TABLE `appointment_slots`
    MODIFY COLUMN `status` ENUM(
        'available',
        'booked',
        'blocked',
        'completed',
        'cancelled',
        'expired'
    ) NOT NULL DEFAULT 'available';
