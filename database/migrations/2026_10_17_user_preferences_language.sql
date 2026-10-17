-- Account language preference for existing databases.
-- The application also adds this column when user_preferences is opened.
-- Run this statement once. Skip it if the column is already present.

ALTER TABLE user_preferences
    ADD COLUMN language VARCHAR(10) NOT NULL DEFAULT 'en' AFTER theme_preference;

-- Keep a provider's previously saved English or Filipino choice.
UPDATE user_preferences up
INNER JOIN provider_system_preferences psp ON psp.provider_id = up.user_id
SET up.language = psp.language
WHERE up.user_type = 'provider'
  AND psp.language IN ('en', 'fil', 'hil');
