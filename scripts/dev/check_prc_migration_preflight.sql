-- Read-only preflight for database/migrations/2026_10_17_textbee_prc_records.sql.
-- Select one section at a time. This file does not stop itself when a table or
-- column is missing. A client that runs every statement can report an error and
-- still continue. If section 2 or section 3 says to stop, do not run sections 4 or 5.
--
-- These statements do not change schema or rows. Do not add statements that
-- change the database to this file.

-- ---------------------------------------------------------------------------
-- 1. Database version
-- Record the full result. This file does not decide whether that version is safe.
-- ---------------------------------------------------------------------------
SELECT VERSION() AS server_version;

-- ---------------------------------------------------------------------------
-- 2. provider_profiles
-- Expected: one row. If the result is empty, stop. Sections 4 and 5 read that
-- table and will fail if it is absent.
-- ---------------------------------------------------------------------------
SELECT TABLE_NAME, ENGINE, TABLE_COLLATION
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'provider_profiles';

-- ---------------------------------------------------------------------------
-- 3. Required columns, including license type, nullability, and collation
-- Expected: one row for prc_license_number and one row for verification_status.
-- If either name is missing, stop. Do not run sections 4 or 5.
-- Read COLLATION_NAME before judging duplicates. The duplicate query uses that
-- collation, so case and trailing-space comparisons follow the column rules.
-- ---------------------------------------------------------------------------
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLLATION_NAME, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'provider_profiles'
  AND COLUMN_NAME IN ('prc_license_number', 'verification_status');

-- ---------------------------------------------------------------------------
-- 4. Duplicate non-NULL license values
-- Run only after sections 2 and 3 show the table and prc_license_number.
-- GROUP BY uses the column collation. Repeated NULL values are omitted; a
-- unique index allows more than one NULL. Any returned row is a stop.
-- ---------------------------------------------------------------------------
SELECT prc_license_number, COUNT(*) AS row_count
FROM provider_profiles
WHERE prc_license_number IS NOT NULL
GROUP BY prc_license_number
HAVING COUNT(*) > 1;

-- ---------------------------------------------------------------------------
-- 5. Empty and whitespace-only license values
-- Run only after sections 2 and 3 show the table and prc_license_number.
-- One such row can remain. More than one can collide when the unique index is
-- added. Stop when blank_or_whitespace_rows is greater than 1.
-- ---------------------------------------------------------------------------
SELECT COUNT(*) AS blank_or_whitespace_rows
FROM provider_profiles
WHERE prc_license_number IS NOT NULL
  AND TRIM(prc_license_number) = '';

SELECT prc_license_number,
       CHAR_LENGTH(prc_license_number) AS stored_length,
       COUNT(*) AS row_count
FROM provider_profiles
WHERE prc_license_number IS NOT NULL
  AND TRIM(prc_license_number) = ''
GROUP BY prc_license_number, CHAR_LENGTH(prc_license_number);

-- ---------------------------------------------------------------------------
-- 6. Every index on provider_profiles
-- NON_UNIQUE 0 means unique. SEQ_IN_INDEX is the column order.
-- SUB_PART NULL means the full column; a number means a prefix.
-- An empty result means the table has no indexes or the table is absent.
-- Confirm section 2 before treating an empty result as no indexes.
-- ---------------------------------------------------------------------------
SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'provider_profiles'
ORDER BY INDEX_NAME, SEQ_IN_INDEX;

-- ---------------------------------------------------------------------------
-- 7. Full-column unique index on prc_license_number
-- A qualifying index is unique, has exactly one column, that column is
-- prc_license_number at sequence 1, and SUB_PART is NULL. The index name
-- does not matter. Zero rows means the migration will try to add
-- uq_provider_prc. One or more rows means that step will be skipped.
-- ---------------------------------------------------------------------------
SELECT INDEX_NAME
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'provider_profiles'
  AND NON_UNIQUE = 0
GROUP BY INDEX_NAME
HAVING COUNT(*) = 1
   AND SUM(
        CASE
            WHEN SEQ_IN_INDEX = 1
             AND LOWER(COLUMN_NAME) = 'prc_license_number'
             AND SUB_PART IS NULL
            THEN 1
            ELSE 0
        END
   ) = 1;

-- ---------------------------------------------------------------------------
-- 8. Index named uq_provider_prc that does not qualify
-- Expected: no rows. Any returned row means that name is already used by a
-- non-unique, composite, prefix, or wrong-column index. Stop. The migration
-- will try to add an index of the same name and will fail.
-- ---------------------------------------------------------------------------
SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'provider_profiles'
  AND INDEX_NAME = 'uq_provider_prc'
  AND NOT EXISTS (
        SELECT 1
        FROM (
            SELECT INDEX_NAME
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'provider_profiles'
              AND INDEX_NAME = 'uq_provider_prc'
              AND NON_UNIQUE = 0
            GROUP BY INDEX_NAME
            HAVING COUNT(*) = 1
               AND SUM(
                    CASE
                        WHEN SEQ_IN_INDEX = 1
                         AND LOWER(COLUMN_NAME) = 'prc_license_number'
                         AND SUB_PART IS NULL
                        THEN 1
                        ELSE 0
                    END
               ) = 1
        ) AS qualifying_named_index
  );
