-- Hostinger phpMyAdmin → database u520834156_meDBConnect26 → SQL tab
-- Paste ALL of this and click Go.
-- This deletes the remaining 1 patient_registrations row that still
-- causes: "An account with this National ID already exists."
-- Only deletes if that row is a patient (not doctor/BHW/admin).

SELECT
  pr.id,
  pr.user_id,
  pr.first_name,
  pr.last_name,
  pr.email,
  u.role
FROM patient_registrations pr
LEFT JOIN users u ON u.id = pr.user_id;

SET @prid := (SELECT id FROM patient_registrations ORDER BY id DESC LIMIT 1);
SET @uid  := (SELECT user_id FROM patient_registrations WHERE id = @prid LIMIT 1);
SET @role := (SELECT role FROM users WHERE id = @uid LIMIT 1);

SELECT @prid AS registration_id, @uid AS user_id, IFNULL(@role, 'no user') AS role;

-- Free slots
UPDATE appointment_slots
SET status = 'available', patient_id = NULL, consultation_id = NULL
WHERE patient_id = IFNULL(@uid, 0);

DELETE FROM complaint_evidence WHERE patient_id = IFNULL(@uid, 0);
DELETE FROM patient_chief_complaints WHERE patient_id = IFNULL(@uid, 0);
DELETE FROM patient_locations WHERE patient_id = IFNULL(@uid, 0);
DELETE FROM patient_audit_logs WHERE patient_id = IFNULL(@uid, 0);
DELETE FROM prescriptions WHERE patient_id = IFNULL(@uid, 0);
DELETE FROM clinical_notes WHERE patient_id = IFNULL(@uid, 0);
DELETE FROM digital_referrals WHERE patient_id = IFNULL(@uid, 0);
DELETE FROM triage_results WHERE patient_id = IFNULL(@uid, 0);
DELETE FROM notifications WHERE user_id = IFNULL(@uid, 0) OR sender_id = IFNULL(@uid, 0);
DELETE FROM consultation_messages WHERE consultation_id IN (SELECT id FROM consultations WHERE patient_id = IFNULL(@uid, 0));
DELETE FROM video_sessions WHERE consultation_id IN (SELECT id FROM consultations WHERE patient_id = IFNULL(@uid, 0));
DELETE FROM consultations WHERE patient_id = IFNULL(@uid, 0);
DELETE FROM remember_tokens WHERE user_id = IFNULL(@uid, 0);
DELETE FROM active_sessions WHERE user_id = IFNULL(@uid, 0);
DELETE FROM user_preferences WHERE user_id = IFNULL(@uid, 0);

DELETE FROM patient_registrations
WHERE id = @prid
   OR user_id = IFNULL(@uid, 0);

DELETE FROM users
WHERE id = IFNULL(@uid, 0)
  AND role = 'patient';

SELECT COUNT(*) AS remaining_registrations FROM patient_registrations;
