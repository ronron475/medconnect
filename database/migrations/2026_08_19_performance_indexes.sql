-- MedConnect Performance Indexes
-- Created: 2026-08-19
-- Purpose: Add missing indexes for frequently-queried columns to reduce DB load

USE `medconnect`;

-- Notifications: polled every few seconds per user
CREATE INDEX IF NOT EXISTS `idx_notif_user_status_id`
  ON `notifications` (`user_id`, `status`, `id`);

CREATE INDEX IF NOT EXISTS `idx_notif_user_read`
  ON `notifications` (`user_id`, `is_read`, `id`);

-- Triage results: filtered by status, level, barangay on GIS + dashboards
CREATE INDEX IF NOT EXISTS `idx_triage_status`
  ON `triage_results` (`status`);

CREATE INDEX IF NOT EXISTS `idx_triage_level`
  ON `triage_results` (`level`);

CREATE INDEX IF NOT EXISTS `idx_triage_barangay`
  ON `triage_results` (`barangay_id`);

CREATE INDEX IF NOT EXISTS `idx_triage_patient_status`
  ON `triage_results` (`patient_id`, `status`);

-- Consultations: filtered by status on dashboards, queried by patient
CREATE INDEX IF NOT EXISTS `idx_consult_status_created`
  ON `consultations` (`status`, `created_at`);

CREATE INDEX IF NOT EXISTS `idx_consult_patient_status`
  ON `consultations` (`patient_id`, `status`);

CREATE INDEX IF NOT EXISTS `idx_consult_provider_status`
  ON `consultations` (`provider_id`, `status`);

-- Digital referrals: queried by patient and provider with status filter
CREATE INDEX IF NOT EXISTS `idx_referral_patient_status`
  ON `digital_referrals` (`patient_id`, `status`);

CREATE INDEX IF NOT EXISTS `idx_referral_provider_status`
  ON `digital_referrals` (`provider_id`, `status`);

-- Users: filtered by role on admin pages
CREATE INDEX IF NOT EXISTS `idx_users_role`
  ON `users` (`role`);

-- Appointment slots: queried by date range and status
CREATE INDEX IF NOT EXISTS `idx_slots_date_status`
  ON `appointment_slots` (`slot_date`, `status`);

-- Video sessions: looked up by consultation
CREATE INDEX IF NOT EXISTS `idx_video_consult_status`
  ON `video_sessions` (`consultation_id`, `status`);
