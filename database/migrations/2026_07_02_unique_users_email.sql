-- Enforce uniqueness at DB level (MySQL/MariaDB)
-- Run once in your database (phpMyAdmin / mysql client).
--
-- Note: If duplicates already exist, this will fail until you resolve them.

ALTER TABLE users
  ADD UNIQUE KEY unique_users_email (email);

