-- Reset Mitche Ann Yuma so she can file a new chief complaint.
-- Keeps the login, registration, and VERIFIED status.
-- Clears the open consultation, booked slot, triage rows, and saved complaints.
--
-- Local XAMPP phpMyAdmin: select database `medconnect` first.
-- Hostinger phpMyAdmin: select `u520834156_meDBConnect26`, keep Delimiter as ;
-- Do not run USE. The script uses whichever database is already selected.

SET @uid := (
  SELECT u.id
  FROM users u
  LEFT JOIN patient_registrations pr ON pr.user_id = u.id
  WHERE u.role = 'patient'
    AND (
      (u.first_name LIKE '%Mitche%' AND u.last_name LIKE '%Yuma%')
      OR pr.patient_code = 'MC-000028'
    )
  ORDER BY u.id DESC
  LIMIT 1
);

SELECT @uid AS user_id_to_reset, (
  SELECT CONCAT(first_name, ' ', last_name, ' <', email, '>')
  FROM users
  WHERE id = @uid
) AS confirm_name;

-- Drop the id unless the account is Mitche Yuma.
SET @uid := IF(
  @uid IS NOT NULL
  AND EXISTS (
    SELECT 1
    FROM users
    WHERE id = @uid
      AND first_name LIKE '%Mitche%'
      AND last_name LIKE '%Yuma%'
  ),
  @uid,
  NULL
);

SELECT IF(@uid IS NULL, 'STOP: Mitche Yuma was not found', 'OK to reset') AS safety_check;

-- Free the booked clinic slot before the consultation row is removed.
SET @mc_sql := IF(
  @uid IS NULL OR NOT EXISTS (
    SELECT 1 FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'appointment_slots'
  ),
  'SELECT "skip appointment_slots" AS skipped',
  CONCAT(
    'UPDATE appointment_slots SET status = ''available'', patient_id = NULL, consultation_id = NULL WHERE patient_id = ',
    @uid
  )
);
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Consultation children (delete before consultations).
SET @mc_tbl := 'video_sessions';
SET @mc_where := CONCAT('consultation_id IN (SELECT id FROM consultations WHERE patient_id = ', IFNULL(@uid, 0), ')');
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'consultation_messages';
SET @mc_where := CONCAT('consultation_id IN (SELECT id FROM consultations WHERE patient_id = ', IFNULL(@uid, 0), ')');
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'consultation_ai_live_chunks';
SET @mc_where := CONCAT('consultation_id IN (SELECT id FROM consultations WHERE patient_id = ', IFNULL(@uid, 0), ')');
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'consultation_ai_notes';
SET @mc_where := CONCAT('consultation_id IN (SELECT id FROM consultations WHERE patient_id = ', IFNULL(@uid, 0), ')');
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'consultation_recording_segments';
SET @mc_where := CONCAT('consultation_id IN (SELECT id FROM consultations WHERE patient_id = ', IFNULL(@uid, 0), ')');
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'consultation_recorded_data';
SET @mc_where := CONCAT('patient_id = ', IFNULL(@uid, 0), ' OR consultation_id IN (SELECT id FROM consultations WHERE patient_id = ', IFNULL(@uid, 0), ')');
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'consultation_thread_state';
SET @mc_where := CONCAT('consultation_id IN (SELECT id FROM consultations WHERE patient_id = ', IFNULL(@uid, 0), ')');
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'consultation_clinical_support';
SET @mc_where := CONCAT('patient_id = ', IFNULL(@uid, 0), ' OR consultation_id IN (SELECT id FROM consultations WHERE patient_id = ', IFNULL(@uid, 0), ')');
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'message_chat_events';
SET @mc_where := CONCAT('consultation_id IN (SELECT id FROM consultations WHERE patient_id = ', IFNULL(@uid, 0), ')');
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'clinical_notes';
SET @mc_where := CONCAT('patient_id = ', IFNULL(@uid, 0));
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'prescriptions';
SET @mc_where := CONCAT('patient_id = ', IFNULL(@uid, 0));
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'case_reports';
SET @mc_where := CONCAT('patient_id = ', IFNULL(@uid, 0));
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'digital_referrals';
SET @mc_where := CONCAT('patient_id = ', IFNULL(@uid, 0));
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'urgent_followup_cases';
SET @mc_where := CONCAT('patient_id = ', IFNULL(@uid, 0));
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'followups';
SET @mc_where := CONCAT('patient_id = ', IFNULL(@uid, 0));
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'referral_community_followups';
SET @mc_where := CONCAT('patient_id = ', IFNULL(@uid, 0));
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'bhw_home_visits';
SET @mc_where := CONCAT('patient_id = ', IFNULL(@uid, 0));
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'appointment_reschedule_log';
SET @mc_where := CONCAT('patient_id = ', IFNULL(@uid, 0));
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'patient_slot_waitlist';
SET @mc_where := CONCAT('patient_id = ', IFNULL(@uid, 0));
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'complaint_evidence';
SET @mc_where := CONCAT('patient_id = ', IFNULL(@uid, 0));
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'patient_chief_complaints';
SET @mc_where := CONCAT('patient_id = ', IFNULL(@uid, 0));
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'patient_medical_update_requests';
SET @mc_where := CONCAT('patient_id = ', IFNULL(@uid, 0));
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'consultations';
SET @mc_where := CONCAT('patient_id = ', IFNULL(@uid, 0));
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'triage_results';
SET @mc_where := CONCAT('patient_id = ', IFNULL(@uid, 0));
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'notifications';
SET @mc_where := CONCAT('user_id = ', IFNULL(@uid, 0), ' OR sender_id = ', IFNULL(@uid, 0));
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

UPDATE patient_registrations
SET workflow_status = 'awaiting_complaint',
    pending_chief_complaint = NULL,
    pending_nlp_json = NULL,
    registration_urgency = NULL
WHERE @uid IS NOT NULL
  AND user_id = @uid;

SELECT
  u.id,
  CONCAT(u.first_name, ' ', u.last_name) AS patient_name,
  pr.status AS registration_status,
  pr.workflow_status,
  (SELECT COUNT(*) FROM consultations c WHERE c.patient_id = u.id) AS consultations_left,
  (SELECT COUNT(*) FROM triage_results t WHERE t.patient_id = u.id) AS triage_left,
  (SELECT COUNT(*) FROM patient_chief_complaints p WHERE p.patient_id = u.id) AS complaints_left
FROM users u
LEFT JOIN patient_registrations pr ON pr.user_id = u.id
WHERE u.id = @uid;

SELECT 'OK — login kept. Open consultation and complaints cleared. Hard-refresh the patient dashboard and enter a new complaint.' AS result;
