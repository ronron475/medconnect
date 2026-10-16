# PRC migration staging checklist

Use this checklist before applying `database/migrations/2026_10_17_textbee_prc_records.sql` on staging. The queries are in `scripts/dev/check_prc_migration_preflight.sql`.

This checklist has not been executed against a staging database. A completed document review does not mean the preflight passed.

Run the SQL file one section at a time. It does not stop itself when `provider_profiles` or a required column is missing. A client that submits the whole file can report an error and continue into later statements. If section 2 or 3 fails its expected result, do not run sections 4 or 5.

The repository ignore rule `*.sql` keeps SQL files out of git except `database/schema.sql` and `database/migrations/*.sql`. Keep a copy of the preflight file with this checklist if the working tree is not what will be deployed.

## What the migration changes

The migration adds `followup_sms_logs`, widens `followup_sms_logs.status` to include `pending`, widens `provider_profiles.verification_status` to include `needs_review`, and adds `prc_verification_events` and `prc_license_locks`. It then adds a unique index named `uq_provider_prc` on the full `prc_license_number` column when no qualifying unique index already covers that column.

A qualifying index is unique, contains exactly one column, that column is `prc_license_number`, and it is not a prefix index. Another index name is acceptable. An index named `uq_provider_prc` that is non-unique, composite, prefixed, or on a different column is not acceptable.

The migration does not delete, merge, or rewrite provider rows.

## DDL is not one transaction

MySQL and MariaDB commit each table and index change on its own. If the unique-index step fails, the SMS log table, the `verification_status` change, `prc_verification_events`, and `prc_license_locks` can already be in place. The helper procedure `medconnect_ensure_uq_provider_prc` can also remain until a later run removes it.

On any failure, stop. Inspect the current tables, columns, and indexes. Restore the verified staging backup, or follow a recovery plan that a reviewer has approved. Do not delete or merge provider rows to force the index through. After duplicate licenses are resolved outside the migration, the file can be run again. A later run skips index creation only when a unique index already covers the full license column.

## Steps

### 1. Back up staging and prove the dump can be restored

Take a full logical dump of the staging database before any migration statement.

Expected result: the dump file is readable and contains the `provider_profiles` table definition. Restore that dump into a separate empty database, or another restore target that is not the live staging schema, and confirm `provider_profiles` opens.

Stop if the dump is missing, unreadable, or cannot be restored.

### 2. Record the server version

Run section 1 of the preflight file:

```sql
SELECT VERSION() AS server_version;
```

Expected result: one row with the exact MySQL or MariaDB version string.

Stop if the client cannot run the query. Do not guess the version from project comments.

### 3. Review privileges locally

Run this on staging and read it on the administrator's machine:

```sql
SHOW GRANTS FOR CURRENT_USER();
```

Expected result: the account has `CREATE ROUTINE` on this database, or `ALL PRIVILEGES`. The migration creates and removes a stored procedure. `DELIMITER` also requires a client that understands it.

Do not paste the account name, host, or any credential into chat, tickets, or logs. Stop if routine creation is not granted.

### 4. Run the preflight sections and read each result

Run `scripts/dev/check_prc_migration_preflight.sql` section by section in the MySQL client or phpMyAdmin.

Expected results:

- Section 2 returns one `provider_profiles` row.
- Section 3 returns `prc_license_number` and `verification_status`, including the license column type, nullability, and collation.
- Section 4 returns no rows.
- Section 5 returns `blank_or_whitespace_rows` of 0 or 1.
- Section 6 lists every index with uniqueness, column order, and prefix length.
- Section 7 returns the qualifying full-column unique index, or no rows when that index is not there yet.
- Section 8 returns no rows.

Section 4 groups licenses with the column collation. Case and trailing spaces follow that collation. Repeated `NULL` licenses are not listed because a unique index allows them.

Stop before sections 4 and 5 when section 2 or 3 does not show the table and both columns.

### 5. Stop for data or index conflicts

Stop the migration when any of these are true:

- Section 4 returns any duplicate non-NULL license.
- Section 5 reports more than one blank or whitespace-only license.
- `provider_profiles`, `prc_license_number`, or `verification_status` is missing.
- Section 8 returns an index named `uq_provider_prc`.

A qualifying unique index under another name is not a conflict. The migration will leave it in place and will not add `uq_provider_prc`.

### 6. Investigate conflicts manually

Review the conflicting licenses and the index rows from sections 6 and 8 with the people who own the provider records.

Expected result: a written decision for each conflict. The migration stays unapplied until that decision is reviewed.

Do not delete, merge, normalize, or update provider rows from an automated script. This checklist does not include repair statements.

### 7. Pause application traffic and background work

Stop web requests and scheduled jobs that load the PHP application. Session restore, doctor login, doctor applications, doctor verification, staff creation, provider settings, the provider list, patient health summary, and account-status changes call `provider_verification_ensure_schema()`. That function can add `uq_provider_prc` while the migration is adding the same index. The migration does not ignore a duplicate index name, so the file can fail even when the resulting index is correct.

Expected result: no PHP request or background job can open a database connection as the application while the migration runs.

Stop if traffic or jobs cannot be paused.

### 8. Apply the migration with a compatible client

Run `database/migrations/2026_10_17_textbee_prc_records.sql` only in the MySQL command-line client or phpMyAdmin. Both understand `DELIMITER`.

Do not use `scripts/dev/apply_admin_modules_migration.php` or another PHP runner that splits SQL on semicolons. `DELIMITER` is a client instruction, and splitting the procedure will not execute it as one statement.

Expected result: the client finishes with no error.

Stop at the first error. If the error is a duplicate index name, rerun preflight sections 6 through 8 before treating it as duplicate license data. If the error is a duplicate license value, stop and return to step 6.

### 9. Verify the migrated objects

After a clean first run, confirm all of the following:

- `followup_sms_logs.status` allows `mocked`, `accepted`, `failed`, `duplicate`, and `pending`.
- `provider_profiles.verification_status` allows `pending`, `verified`, `rejected`, and `needs_review`.
- `prc_verification_events` and `prc_license_locks` exist.
- Preflight section 7 returns one qualifying full-column unique index on `prc_license_number`.
- Preflight section 8 still returns no rows.
- The procedure `medconnect_ensure_uq_provider_prc` is gone.

```sql
SELECT COLUMN_TYPE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND (
        (TABLE_NAME = 'followup_sms_logs' AND COLUMN_NAME = 'status')
        OR (TABLE_NAME = 'provider_profiles' AND COLUMN_NAME = 'verification_status')
        OR (TABLE_NAME = 'prc_verification_events' AND COLUMN_NAME = 'status')
        OR (TABLE_NAME = 'prc_license_locks' AND COLUMN_NAME = 'holder_type')
      );

SELECT TABLE_NAME
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('followup_sms_logs', 'prc_verification_events', 'prc_license_locks');

SELECT ROUTINE_NAME
FROM information_schema.ROUTINES
WHERE ROUTINE_SCHEMA = DATABASE()
  AND ROUTINE_NAME = 'medconnect_ensure_uq_provider_prc';
```

Expected result: both enum definitions contain the values listed above, all three tables are present, section 7 shows a qualifying index, and the procedure query returns no rows.

Stop if any object, enum value, or qualifying index is missing. Leave the application paused and use the backup or the reviewed recovery plan.

### 10. Run the migration a second time

Run the same migration file again only after step 9 passed.

Expected result: the second run finishes without error and does not add a second index. Section 7 still shows the same qualifying index.

Stop if the second run returns an error or tries to add `uq_provider_prc` again. Do not use a second run as the first response to a failed run.

### 11. Resume the application and perform limited checks

Restore PHP and background jobs only after step 10 passes.

Open the staging doctor verification screen and the staff account screen. Confirm both pages load. Direct doctor account creation should still show the Maker-Checker rejection. Do not approve a license, create an account, or submit a form that sends mail.

Expected result: both screens load, and no message, SMS, or email is sent.

Stop and pause the application again if a page errors while reading provider profiles.

### 12. Leave SMS disabled

Keep `TEXTBEE_SEND_ENABLED` unset or false. Do not turn sending on for this test. Do not send a real SMS or email from follow-up, verification, or account screens.

Expected result: staging can be exercised without an outbound SMS or email.

Stop if a test would require enabling sending or contacting a real recipient.
