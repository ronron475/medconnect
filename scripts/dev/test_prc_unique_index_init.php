<?php
/**
 * Classifies provider_profiles index metadata without a database connection.
 * php scripts/dev/test_prc_unique_index_init.php
 */
require_once dirname(__DIR__, 2) . '/app/includes/provider_verification.php';

$failed = 0;

function check(bool $ok, string $label): void
{
    global $failed;
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $label . PHP_EOL;
    if (!$ok) {
        $failed++;
    }
}

function indexRow(string $name, int $nonUnique, string $column, int $sequence = 1, mixed $prefix = null): array
{
    return [
        'Key_name' => $name,
        'Non_unique' => $nonUnique,
        'Column_name' => $column,
        'Seq_in_index' => $sequence,
        'Sub_part' => $prefix,
    ];
}

check(
    provider_verification_index_rows_enforce_prc_unique([
        indexRow('other_name', 0, 'prc_license_number'),
    ]),
    'a unique index on the full license column qualifies under any name'
);
check(
    !provider_verification_index_rows_enforce_prc_unique([
        indexRow('uq_provider_prc', 1, 'prc_license_number'),
    ]),
    'the expected index name does not qualify when the index is not unique'
);
check(
    !provider_verification_index_rows_enforce_prc_unique([
        indexRow('uq_provider_prc', 0, 'user_id'),
    ]),
    'the expected index name does not qualify when it covers another column'
);
check(
    !provider_verification_index_rows_enforce_prc_unique([
        indexRow('uq_provider_prc', 0, 'prc_license_number', 1),
        indexRow('uq_provider_prc', 0, 'user_id', 2),
    ]),
    'a composite unique index does not enforce license uniqueness alone'
);
check(
    !provider_verification_index_rows_enforce_prc_unique([
        indexRow('prc_prefix', 0, 'prc_license_number', 1, 8),
    ]),
    'a prefix index does not cover the full license value'
);

$staff = (string) file_get_contents(dirname(__DIR__, 2) . '/app/api/admin/create_staff.php');
$profileInsert = strpos($staff, 'INSERT INTO provider_profiles');
$userInsert = strpos($staff, 'INSERT INTO users');
$constraintCheck = strpos($staff, 'provider_verification_prc_unique_index_exists');
$begin = strpos($staff, 'beginTransaction');
$commit = strpos($staff, 'commit()');
$rollback = strpos($staff, 'rollBack');
$makerChecker = strpos($staff, 'Doctor accounts require Maker-Checker approval');
check(
    $constraintCheck !== false && $profileInsert !== false && $constraintCheck < $profileInsert,
    'staff creation checks the PRC unique constraint before inserting a provider profile'
);
check(
    $begin !== false && $userInsert !== false && $constraintCheck < $begin && $begin < $userInsert && $userInsert < $profileInsert && $profileInsert < $commit,
    'provider user and profile inserts share one transaction'
);
check(
    $rollback !== false && $begin < $rollback && $rollback < $profileInsert,
    'a failed second PRC check rolls back before the profile insert'
);
check(
    !str_contains($staff, 'Database error: '),
    'staff creation does not return raw database exception text'
);
check(
    $makerChecker !== false && $makerChecker < $constraintCheck,
    'direct doctor creation remains rejected before the profile insert'
);
check(
    !str_contains($staff, 'DELETE FROM provider_profiles'),
    'a missing PRC constraint does not delete provider records'
);

$migration = (string) file_get_contents(dirname(__DIR__, 2) . '/database/migrations/2026_10_17_textbee_prc_records.sql');
check(
    !str_contains($migration, 'CREATE UNIQUE INDEX IF NOT EXISTS'),
    'the PRC migration does not use CREATE INDEX IF NOT EXISTS'
);
check(
    str_contains($migration, 'NON_UNIQUE = 0')
        && str_contains($migration, 'SUB_PART IS NULL')
        && str_contains($migration, "LOWER(COLUMN_NAME) = 'prc_license_number'")
        && str_contains($migration, 'CREATE UNIQUE INDEX uq_provider_prc'),
    'the PRC migration creates the index only when a full-column unique index is absent'
);
check(
    !str_contains($migration, 'DELETE FROM provider_profiles')
        && !str_contains($migration, 'DECLARE EXIT HANDLER')
        && !str_contains($migration, 'DECLARE CONTINUE HANDLER'),
    'the PRC migration does not delete provider rows or swallow index errors'
);

echo $failed === 0 ? "OK\n" : "FAILED {$failed}\n";
exit($failed === 0 ? 0 : 1);
