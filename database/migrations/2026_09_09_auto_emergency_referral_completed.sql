-- Automatic emergency triage referrals are issued immediately (nearest ER).
-- They should not remain pending as if awaiting acceptance.
-- Schemas use either facility_name or destination_facility — run the matching UPDATE.
-- Prefer: php scripts/dev/fix_auto_emergency_referral_status.php

UPDATE digital_referrals
SET status = 'completed'
WHERE status = 'pending'
  AND referral_type = 'Hospital'
  AND destination_facility = 'Nearest hospital / ER — emergency triage';

-- Alternate if your schema uses facility_name instead of destination_facility:
-- UPDATE digital_referrals
-- SET status = 'completed'
-- WHERE status = 'pending'
--   AND referral_type = 'Hospital'
--   AND facility_name = 'Nearest hospital / ER — emergency triage';
