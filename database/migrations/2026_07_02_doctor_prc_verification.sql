-- Doctor PRC manual verification fields (admin create-doctor workflow)

ALTER TABLE provider_profiles
    ADD COLUMN IF NOT EXISTS middle_name VARCHAR(100) NULL DEFAULT NULL AFTER prc_license_number,
    ADD COLUMN IF NOT EXISTS birthdate DATE NULL DEFAULT NULL AFTER middle_name,
    ADD COLUMN IF NOT EXISTS specialty VARCHAR(120) NULL DEFAULT NULL AFTER birthdate,
    ADD COLUMN IF NOT EXISTS facility VARCHAR(200) NULL DEFAULT NULL AFTER specialty,
    ADD COLUMN IF NOT EXISTS created_by INT(11) UNSIGNED NULL DEFAULT NULL AFTER facility;
