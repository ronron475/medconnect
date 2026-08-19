-- Reset Mitche Ann Yuma (MC-000028) clinical workflow.
-- Keeps the login / registration / VERIFIED status.
-- Hostinger phpMyAdmin: keep Delimiter as ;

USE `u520834156_meDBConnect26`;

SET @uid := (
  SELECT u.id
  FROM users u
  LEFT JOIN patient_registrations pr ON pr.user_id = u.id
  WHERE u.role = 'patient'
    AND (
      pr.patient_code = 'MC-000028'
      OR (u.first_name LIKE '%Mitche%' AND u.last_name LIKE '%Yuma%')
    )
  ORDER BY u.id DESC
  LIMIT 1
);

SELECT @uid AS user_id_to_reset, (
  SELECT CONCAT(first_name, ' ', last_name, ' <', email, '>')
  FROM users WHERE id = @uid
) AS confirm_name;

-- Stop if confirm_name is not Mitche Ann Yuma.

UPDATE appointment_slots
SET status = 'available', patient_id = NULL, consultation_id = NULL
WHERE @uid IS NOT NULL AND patient_id = @uid;

SET @mc_tbl := 'patient_slot_waitlist';
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), 'SELECT "skip waitlist" AS skipped', CONCAT('DELETE FROM patient_slot_waitlist WHERE patient_id = ', @uid));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

DELETE FROM complaint_evidence WHERE @uid IS NOT NULL AND patient_id = @uid;
DELETE FROM patient_chief_complaints WHERE @uid IS NOT NULL AND patient_id = @uid;
DELETE FROM patient_medical_update_requests WHERE @uid IS NOT NULL AND patient_id = @uid;
DELETE FROM digital_referrals WHERE @uid IS NOT NULL AND patient_id = @uid;
DELETE FROM urgent_followup_cases WHERE @uid IS NOT NULL AND patient_id = @uid;
DELETE FROM followups WHERE @uid IS NOT NULL AND patient_id = @uid;
DELETE FROM prescriptions WHERE @uid IS NOT NULL AND patient_id = @uid;
DELETE FROM clinical_notes WHERE @uid IS NOT NULL AND patient_id = @uid;
DELETE FROM case_reports WHERE @uid IS NOT NULL AND patient_id = @uid;

SET @mc_tbl := 'video_sessions';
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), 'SELECT "skip video_sessions" AS skipped', CONCAT('DELETE FROM video_sessions WHERE consultation_id IN (SELECT id FROM consultations WHERE patient_id = ', @uid, ')'));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

DELETE FROM consultations WHERE @uid IS NOT NULL AND patient_id = @uid;
DELETE FROM triage_results WHERE @uid IS NOT NULL AND patient_id = @uid;
DELETE FROM notifications WHERE @uid IS NOT NULL AND (user_id = @uid OR sender_id = @uid);

UPDATE patient_registrations
SET workflow_status = 'registered'
WHERE @uid IS NOT NULL AND user_id = @uid;

SELECT 'OK — login kept, clinical workflow cleared. Hard-refresh the dashboard.' AS result;
