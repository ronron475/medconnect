<?php
/**
 * Ensures extended triage_results columns for AI assessment persistence.
 */

function triage_assessment_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $tableCheck = $pdo->query("SHOW TABLES LIKE 'triage_results'");
    if (!$tableCheck || $tableCheck->rowCount() === 0) {
        return;
    }

    $columns = [
        'confidence_score'        => 'DECIMAL(5,2) NULL',
        'severity'                => 'VARCHAR(20) NULL',
        'triage_level'            => "VARCHAR(20) NULL COMMENT 'GIS severity: non_urgent|urgent|emergency'",
        'triage_classification' => 'VARCHAR(20) NULL',
        'english_complaint'       => 'TEXT NULL',
        'detected_symptoms_json'  => 'JSON NULL',
        'possible_conditions_json'=> 'JSON NULL',
        'recommendations'         => 'TEXT NULL',
        'assessment_payload'      => 'JSON NULL',
        'engine'                  => 'VARCHAR(50) NULL',
    ];

    $existing = [];
    $stmt = $pdo->query('SHOW COLUMNS FROM triage_results');
    if ($stmt) {
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $existing[(string) $row['Field']] = true;
        }
    }

    foreach ($columns as $name => $definition) {
        if (isset($existing[$name])) {
            continue;
        }
        $pdo->exec("ALTER TABLE triage_results ADD COLUMN `{$name}` {$definition}");
    }

    triage_assessment_backfill_triage_level($pdo);

    $done = true;
}

/**
 * Backfill triage_level for legacy rows (one-time data migration, not GIS logic).
 */
function triage_assessment_backfill_triage_level(PDO $pdo): void
{
    static $backfilled = false;
    if ($backfilled) {
        return;
    }

    $tableCheck = $pdo->query("SHOW TABLES LIKE 'triage_results'");
    if (!$tableCheck || $tableCheck->rowCount() === 0) {
        return;
    }

    $colCheck = $pdo->query("SHOW COLUMNS FROM triage_results LIKE 'triage_level'");
    if (!$colCheck || $colCheck->rowCount() === 0) {
        return;
    }

    require_once dirname(__DIR__) . '/core/TriageLevelService.php';

    $stmt = $pdo->query("
        SELECT id, triage_level, triage_classification, level
        FROM triage_results
        WHERE triage_level IS NULL OR TRIM(triage_level) = ''
        LIMIT 2000
    ");
    if (!$stmt) {
        $backfilled = true;
        return;
    }

    $update = $pdo->prepare('UPDATE triage_results SET triage_level = ? WHERE id = ?');
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $level = TriageLevelService::resolve([
            'triage_classification' => $row['triage_classification'] ?? '',
            'level'                 => $row['level'] ?? '',
        ]);
        $update->execute([$level, (int) $row['id']]);
    }

    $backfilled = true;
}

/**
 * True when triage was submitted on a calendar day before today (no longer acceptable).
 */
function triage_case_is_expired(?string $assessedAt): bool
{
    if ($assessedAt === null || trim($assessedAt) === '') {
        return true;
    }

    $tz = defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Asia/Manila';
    try {
        $submitted = new DateTimeImmutable($assessedAt, new DateTimeZone($tz));
        $today     = new DateTimeImmutable('today', new DateTimeZone($tz));

        return $submitted < $today;
    } catch (Exception $e) {
        return true;
    }
}

function triage_case_can_accept(?string $assessedAt, string $status = 'pending'): bool
{
    return $status === 'pending' && !triage_case_is_expired($assessedAt);
}
