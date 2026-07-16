-- Fix utf8mb4 collation mismatch on Hostinger (error 1267).
-- Run once in phpMyAdmin on database u520834156_meDBConnect26.

ALTER DATABASE `u520834156_meDBConnect26`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

-- Core schedule tables (provider schedule save / slot generation)
ALTER TABLE `provider_schedules`
    CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE `appointment_slots`
    CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Frequently joined tables
ALTER TABLE `users`
    CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE `consultations`
    CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE `triage_results`
    CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
