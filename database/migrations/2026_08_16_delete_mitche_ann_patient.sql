-- Delete ALL database records for patient Mitche Ann Yuma (MC-000026).
-- Does NOT delete doctors, BHWs, admins, or other patients.
-- Missing tables are skipped (Hostinger has no `messages` table).
--
-- Hostinger phpMyAdmin:
--   1. Click database u520834156_meDBConnect26 in the left sidebar
--      (do NOT run USE medconnect;)
--   2. Keep Delimiter as ;
--   3. Run STEP 1, then STEP 2, then STEP 3
--
-- Safe to re-run if a previous attempt stopped with an error.

-- ============================================================
-- STEP 1 — Confirm the account
-- ============================================================

SELECT id, first_name, last_name, email, role
FROM users
WHERE role = 'patient'
  AND (
    id = 26
    OR (first_name LIKE '%Mitche%' AND last_name LIKE '%Yuma%')
  );

-- Stop here if that row is not Mitche Ann Yuma / MC-000026.


-- ============================================================
-- STEP 2 — Delete related records, then the user
-- ============================================================

SET @uid := (
  SELECT id
  FROM users
  WHERE role = 'patient'
    AND (
      id = 26
      OR (first_name LIKE '%Mitche%' AND last_name LIKE '%Yuma%')
    )
  LIMIT 1
);

SELECT IFNULL(@uid, 'already deleted') AS mitche_user_id_to_delete;

-- Free booked clinic slots
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

SET @mc_tbl := 'complaint_evidence'; SET @mc_where := CONCAT('patient_id = ', IFNULL(@uid, 0));
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'patient_chief_complaints'; SET @mc_where := CONCAT('patient_id = ', IFNULL(@uid, 0));
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'patient_locations'; SET @mc_where := CONCAT('patient_id = ', IFNULL(@uid, 0));
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'patient_medical_update_requests'; SET @mc_where := CONCAT('patient_id = ', IFNULL(@uid, 0));
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'patient_notification_preferences'; SET @mc_where := CONCAT('user_id = ', IFNULL(@uid, 0));
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'patient_privacy_preferences'; SET @mc_where := CONCAT('user_id = ', IFNULL(@uid, 0));
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'patient_audit_logs'; SET @mc_where := CONCAT('patient_id = ', IFNULL(@uid, 0));
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'prescriptions'; SET @mc_where := CONCAT('patient_id = ', IFNULL(@uid, 0));
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'clinical_notes'; SET @mc_where := CONCAT('patient_id = ', IFNULL(@uid, 0));
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'digital_referrals'; SET @mc_where := CONCAT('patient_id = ', IFNULL(@uid, 0));
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'case_reports'; SET @mc_where := CONCAT('patient_id = ', IFNULL(@uid, 0));
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'urgent_followup_cases'; SET @mc_where := CONCAT('patient_id = ', IFNULL(@uid, 0));
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'followups'; SET @mc_where := CONCAT('patient_id = ', IFNULL(@uid, 0));
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'residency_documents'; SET @mc_where := CONCAT('patient_id = ', IFNULL(@uid, 0));
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'bhw_home_visits'; SET @mc_where := CONCAT('patient_id = ', IFNULL(@uid, 0));
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'video_sessions'; SET @mc_where := CONCAT('consultation_id IN (SELECT id FROM consultations WHERE patient_id = ', IFNULL(@uid, 0), ')');
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'consultation_messages'; SET @mc_where := CONCAT('consultation_id IN (SELECT id FROM consultations WHERE patient_id = ', IFNULL(@uid, 0), ')');
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'consultation_clinical_support'; SET @mc_where := CONCAT('patient_id = ', IFNULL(@uid, 0), ' OR consultation_id IN (SELECT id FROM consultations WHERE patient_id = ', IFNULL(@uid, 0), ')');
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'consultation_ai_notes'; SET @mc_where := CONCAT('consultation_id IN (SELECT id FROM consultations WHERE patient_id = ', IFNULL(@uid, 0), ')');
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'message_chat_events'; SET @mc_where := CONCAT('consultation_id IN (SELECT id FROM consultations WHERE patient_id = ', IFNULL(@uid, 0), ')');
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'consultations'; SET @mc_where := CONCAT('patient_id = ', IFNULL(@uid, 0));
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'triage_results'; SET @mc_where := CONCAT('patient_id = ', IFNULL(@uid, 0));
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'notifications'; SET @mc_where := CONCAT('user_id = ', IFNULL(@uid, 0), ' OR sender_id = ', IFNULL(@uid, 0));
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

-- `messages` does not exist on Hostinger; this will skip it
SET @mc_tbl := 'messages'; SET @mc_where := CONCAT('sender_id = ', IFNULL(@uid, 0), ' OR receiver_id = ', IFNULL(@uid, 0));
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'remember_tokens'; SET @mc_where := CONCAT('user_id = ', IFNULL(@uid, 0));
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'active_sessions'; SET @mc_where := CONCAT('user_id = ', IFNULL(@uid, 0));
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'user_preferences'; SET @mc_where := CONCAT('user_id = ', IFNULL(@uid, 0));
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'password_history'; SET @mc_where := CONCAT('user_id = ', IFNULL(@uid, 0));
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'user_login_events'; SET @mc_where := CONCAT('user_id = ', IFNULL(@uid, 0));
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'user_devices'; SET @mc_where := CONCAT('user_id = ', IFNULL(@uid, 0));
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'failed_logins'; SET @mc_where := CONCAT('user_id = ', IFNULL(@uid, 0));
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'security_logs'; SET @mc_where := CONCAT('user_id = ', IFNULL(@uid, 0));
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'chatbot_messages'; SET @mc_where := CONCAT('conversation_id IN (SELECT id FROM chatbot_conversations WHERE user_id = ', IFNULL(@uid, 0), ')');
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'chatbot_conversations'; SET @mc_where := CONCAT('user_id = ', IFNULL(@uid, 0));
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'conversation_history'; SET @mc_where := CONCAT('conversation_id IN (SELECT id FROM chatbot_conversations WHERE user_id = ', IFNULL(@uid, 0), ')');
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'patient_registrations'; SET @mc_where := CONCAT('user_id = ', IFNULL(@uid, 0), ' OR email IN (SELECT email FROM users WHERE id = ', IFNULL(@uid, 0), ')');
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'users'; SET @mc_where := CONCAT('id = ', IFNULL(@uid, 0), ' AND role = ''patient''');
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;


-- ============================================================
-- STEP 3 — Confirm she is gone
-- ============================================================

SELECT id, first_name, last_name, email
FROM users
WHERE first_name LIKE '%Mitche%'
   OR last_name LIKE '%Yuma%'
   OR id = 26;

SELECT COUNT(*) AS remaining_triage
FROM triage_results
WHERE patient_id = 26;
