<?php
/**
 * Import NLP reference CSV datasets into MySQL.
 *
 * Usage (from project root):
 *   php scripts/data/import_nlp_datasets.php
 *   php scripts/data/import_nlp_datasets.php --conditions-only
 *   php scripts/data/import_nlp_datasets.php --allergies-only
 *   php scripts/data/import_nlp_datasets.php --symptoms-only
 *
 * Prerequisites:
 *   1. Run: python scripts/data/build_icd10_conditions.py
 *   2. Run: python scripts/data/build_allergies_official.py
 *   3. Apply schema: mysql -u root medconnect < database/schema_nlp_reference.sql
 */

declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/config/db.php';

$opts = getopt('', ['conditions-only', 'allergies-only', 'symptoms-only', 'truncate']);
$onlyOne = isset($opts['conditions-only']) || isset($opts['allergies-only']) || isset($opts['symptoms-only']);
$doConditions = !$onlyOne || isset($opts['conditions-only']);
$doAllergies = !$onlyOne || isset($opts['allergies-only']);
$doSymptoms = !$onlyOne || isset($opts['symptoms-only']);
$truncate = isset($opts['truncate']);

if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "Database connection not available. Check config/db.php\n");
    exit(1);
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/**
 * Bulk INSERT for large ICD-10 files (much faster than row-by-row execute).
 */
function importCsvBatch(PDO $pdo, string $table, array $columns, string $csvPath, int $batchSize = 1000): int
{
    if (!is_readable($csvPath)) {
        throw new RuntimeException("File not readable: {$csvPath}");
    }

    $handle = fopen($csvPath, 'r');
    if ($handle === false) {
        throw new RuntimeException("Cannot open: {$csvPath}");
    }

    $header = fgetcsv($handle);
    if (!is_array($header)) {
        fclose($handle);
        return 0;
    }

    $colIndex = [];
    foreach ($columns as $col) {
        $idx = array_search($col, $header, true);
        if ($idx === false) {
            fclose($handle);
            throw new RuntimeException("Column {$col} missing in {$csvPath}");
        }
        $colIndex[$col] = (int) $idx;
    }

    $rowPlaceholder = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
    $columnList = '`' . implode('`,`', $columns) . '`';

    $count = 0;
    $batchRows = [];
    $batchParams = [];

    $flush = static function () use (
        $pdo,
        $table,
        $columnList,
        $rowPlaceholder,
        &$batchRows,
        &$batchParams,
        &$count
    ): void {
        if ($batchRows === []) {
            return;
        }
        $sql = 'INSERT INTO `' . $table . '` (' . $columnList . ') VALUES '
            . implode(',', $batchRows);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($batchParams);
        $count += count($batchRows);
        $batchRows = [];
        $batchParams = [];
    };

    $pdo->exec('SET UNIQUE_CHECKS=0');

    while (($row = fgetcsv($handle)) !== false) {
        $batchRows[] = $rowPlaceholder;
        foreach ($columns as $col) {
            $batchParams[] = $row[$colIndex[$col]] ?? null;
        }
        if (count($batchRows) >= $batchSize) {
            $flush();
        }
    }

    $flush();
    fclose($handle);
    $pdo->exec('SET UNIQUE_CHECKS=1');

    return $count;
}

function globParts(string $dir, string $pattern): array
{
    $files = glob($dir . '/' . $pattern) ?: [];
    sort($files, SORT_NATURAL);

    return $files;
}

echo "medConnect NLP dataset import\n";

$pdo->exec('SET FOREIGN_KEY_CHECKS=0');

if ($truncate) {
    if ($doConditions) {
        $pdo->exec('TRUNCATE TABLE `nlp_medical_conditions`');
        echo "Truncated nlp_medical_conditions\n";
    }
    if ($doAllergies) {
        $pdo->exec('TRUNCATE TABLE `nlp_allergies`');
        echo "Truncated nlp_allergies\n";
    }
    if ($doSymptoms) {
        $pdo->exec('TRUNCATE TABLE `nlp_symptoms`');
        echo "Truncated nlp_symptoms\n";
    }
}

$totalConditions = 0;
if ($doConditions) {
    $icdDir = $projectRoot . '/data/nlp/icd10';
    $parts = globParts($icdDir, 'medical_conditions_part_*.csv');
    if ($parts === []) {
        $single = $projectRoot . '/data/nlp/medical_conditions.csv';
        if (is_readable($single)) {
            $parts = [$single];
        }
    }

    if ($parts === []) {
        echo "No condition CSV files found. Run: python scripts/data/build_icd10_conditions.py\n";
    } else {
        $cols = [
            'icd10_code', 'condition_name', 'icd10_category', 'chapter_code',
            'chapter_title', 'long_description', 'is_billable', 'search_name', 'source',
        ];
        foreach ($parts as $file) {
            $n = importCsvBatch($pdo, 'nlp_medical_conditions', $cols, $file);
            $totalConditions += $n;
            echo "Imported {$n} rows from " . basename($file) . "\n";
        }
        echo "Total conditions: {$totalConditions}\n";
    }
}

$totalAllergies = 0;
if ($doAllergies) {
    $allergyDir = $projectRoot . '/data/nlp/allergies';
    $parts = globParts($allergyDir, 'allergies_part_*.csv');
    if ($parts === []) {
        $single = $projectRoot . '/data/nlp/allergies.csv';
        if (is_readable($single)) {
            $parts = [$single];
        }
    }

    if ($parts === []) {
        echo "No allergy CSV files found. Run: python scripts/data/build_allergies_official.py\n";
    } else {
        $cols = ['allergy_name', 'category', 'search_name', 'source'];
        foreach ($parts as $file) {
            $n = importCsvBatch($pdo, 'nlp_allergies', $cols, $file);
            $totalAllergies += $n;
            echo "Imported {$n} rows from " . basename($file) . "\n";
        }
        echo "Total allergies: {$totalAllergies}\n";
    }
}

$totalSymptoms = 0;
if ($doSymptoms) {
    $symptomDir = $projectRoot . '/data/nlp/symptoms';
    $parts = globParts($symptomDir, 'symptoms_part_*.csv');
    if ($parts === []) {
        $single = $projectRoot . '/data/nlp/symptoms.csv';
        if (is_readable($single)) {
            $parts = [$single];
        }
    }

    if ($parts === []) {
        echo "No symptom CSV files found. Run: python scripts/data/build_comprehensive_symptoms.py\n";
    } else {
        $cols = ['symptom_name', 'category', 'description', 'related_body_system', 'search_name', 'source'];
        foreach ($parts as $file) {
            $n = importCsvBatch($pdo, 'nlp_symptoms', $cols, $file);
            $totalSymptoms += $n;
            echo "Imported {$n} rows from " . basename($file) . "\n";
        }
        echo "Total symptoms: {$totalSymptoms}\n";
    }
}

$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

echo "Done.\n";
