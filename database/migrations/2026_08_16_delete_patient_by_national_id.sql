-- Delete the existing patient whose National ID is blocking registration:
--   "An account with this National ID already exists."
--
-- National IDs are stored as SHA-256 hashes in patient_registrations.national_id.
-- This script hashes the ID the same way PHP registration does.
--
-- Does NOT delete doctors, BHWs, admins, or other patients.
-- Missing tables are skipped (safe on Hostinger).
--
-- Hostinger phpMyAdmin:
--   1. Click database u520834156_meDBConnect26 in the left sidebar
--      (do NOT run USE medconnect;)
--   2. Keep Delimiter as ;       aaaaaaaaaaaaaaaaaaaaaaaaaaa
--   3. Run STEP 0 first (lists everyone + clears leftover rows with no user).
--      If Step 3 of registration still fails, run STEP 1–3 with the National ID.
--
-- Local XAMPP: select database `medconnect` first.

-- ============================================================
-- STEP 0 — See who exists, then remove leftover National IDs
--          that are NOT linked to a real user account
-- ============================================================

SELECT
  pr.id AS registration_id,
  pr.user_id,
  pr.first_name,
  pr.last_name,
  pr.email,
  pr.created_at,
  CASE
    WHEN pr.user_id IS NULL OR pr.user_id = 0 THEN 'ORPHAN — blocks National ID, no login'
    WHEN u.id IS NULL THEN 'ORPHAN — user row missing'
    ELSE CONCAT('ACTIVE ', COALESCE(u.role, 'patient'))
  END AS account_status
FROM patient_registrations pr
LEFT JOIN users u ON u.id = pr.user_id
ORDER BY pr.id DESC;

-- Clears incomplete registrations that still trigger
-- "An account with this National ID already exists."
DELETE pr
FROM patient_registrations pr
LEFT JOIN users u ON u.id = pr.user_id
WHERE pr.user_id IS NULL
   OR pr.user_id = 0
   OR u.id IS NULL;

SELECT ROW_COUNT() AS orphan_registrations_deleted;


-- ============================================================
-- PASTE THE NATIONAL ID FROM THE ID CARD (dashes/spaces OK)
-- Only needed if STEP 0 still leaves an ACTIVE account.
-- ============================================================

SET @nid_raw := 'PASTE_NATIONAL_ID_HERE';

SET @nid_norm := REPLACE(REPLACE(REPLACE(TRIM(@nid_raw), '-', ''), ' ', ''), CHAR(9), '');
SET @nid_hash := LOWER(SHA2(@nid_norm, 256));


-- ============================================================
-- STEP 1 — Confirm the matching account
-- Stop if this is not the person you intend to delete.
-- ============================================================

SELECT
  pr.id AS registration_id,
  pr.user_id,
  pr.first_name,
  pr.last_name,
  pr.email,
  pr.contact_number,
  LEFT(pr.national_id, 12) AS national_id_prefix,
  CHAR_LENGTH(pr.national_id) AS national_id_len,
  pr.created_at,
  u.id AS user_id_joined,
  u.role,
  u.is_active
FROM patient_registrations pr
LEFT JOIN users u ON u.id = pr.user_id
WHERE pr.national_id IN (@nid_hash, @nid_norm, TRIM(@nid_raw));

SELECT IF(
  @nid_raw = 'PASTE_NATIONAL_ID_HERE' OR @nid_norm = '',
  'STOP: paste the National ID into @nid_raw first',
  CONCAT('hash=', @nid_hash)
) AS lookup_status;


-- ============================================================
-- STEP 2 — Delete related records, then the user + registration
-- ============================================================

SET @prid := (
  SELECT id
  FROM patient_registrations
  WHERE national_id IN (@nid_hash, @nid_norm, TRIM(@nid_raw))
  LIMIT 1
);

SET @uid := (
  SELECT COALESCE(
    NULLIF((SELECT user_id FROM patient_registrations WHERE id = @prid LIMIT 1), 0),
    (
      SELECT u.id
      FROM users u
      INNER JOIN patient_registrations pr ON LOWER(pr.email) = LOWER(u.email)
      WHERE pr.id = @prid
        AND u.role = 'patient'
      LIMIT 1
    )
  )
);

SELECT @prid AS registration_id_to_delete, IFNULL(@uid, 'registration only (no user row)') AS user_id_to_delete;

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

SET @mc_tbl := 'registration_activity_logs'; SET @mc_where := CONCAT('national_id_hash = ''', REPLACE(@nid_hash, '''', ''), '''');
SET @mc_sql := IF(@nid_norm = '' OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Remove the registration row that blocks "National ID already exists"
SET @mc_tbl := 'patient_registrations';
SET @mc_where := CONCAT(
  'national_id IN (''', REPLACE(@nid_hash, '''', ''), ''', ''', REPLACE(@nid_norm, '''', ''), ''', ''', REPLACE(TRIM(@nid_raw), '''', ''), ''')',
  IF(@uid IS NULL, '', CONCAT(' OR user_id = ', @uid))
);
SET @mc_sql := IF(@nid_norm = '' OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @mc_tbl := 'users'; SET @mc_where := CONCAT('id = ', IFNULL(@uid, 0), ' AND role = ''patient''');
SET @mc_sql := IF(@uid IS NULL OR NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = @mc_tbl), CONCAT('SELECT "', @mc_tbl, '" AS skipped'), CONCAT('DELETE FROM `', @mc_tbl, '` WHERE ', @mc_where));
PREPARE s FROM @mc_sql; EXECUTE s; DEALLOCATE PREPARE s;


-- ============================================================
-- STEP 3 — Confirm the National ID is free
-- ============================================================

SELECT id, user_id, first_name, last_name, email
FROM patient_registrations
WHERE national_id IN (@nid_hash, @nid_norm, TRIM(@nid_raw));

SELECT IF(
  EXISTS (
    SELECT 1 FROM patient_registrations
    WHERE national_id IN (@nid_hash, @nid_norm, TRIM(@nid_raw))
  ),
  'STILL BLOCKED — matching registration remains',
  'OK — this National ID can be registered again'
) AS result;
