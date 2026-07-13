<?php
/**
 * Import NLP reference CSV datasets into MySQL.
 *
 * Usage (from project root):
 *   php scripts/data/import_nlp_datasets.php
 *   php scripts/data/import_nlp_datasets.php --truncate
 *   php scripts/data/import_nlp_datasets.php --conditions-only
 *   php scripts/data/import_nlp_datasets.php --allergies-only
 *   php scripts/data/import_nlp_datasets.php --symptoms-only
 *   php scripts/data/import_nlp_datasets.php --flags-only
 *   php scripts/data/import_nlp_datasets.php --pain-only
 *   php scripts/data/import_nlp_datasets.php --dictionary-only
 *
 * Prerequisites:
 *   1. Build CSVs (ICD-10 / allergies / symptoms / generators as needed)
 *   2. Apply schema: mysql -u root medconnect < database/schema_nlp_reference.sql
 *
 * Note: PHP NLP loaders primarily read CSV files under data/nlp/.
 * MySQL import is for autocomplete, reporting, and DB-backed tools.
 */

declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/config/db.php';

$opts = getopt('', [
    'conditions-only',
    'allergies-only',
    'symptoms-only',
    'flags-only',
    'pain-only',
    'dictionary-only',
    'truncate',
]);
$onlyOne = isset($opts['conditions-only'])
    || isset($opts['allergies-only'])
    || isset($opts['symptoms-only'])
    || isset($opts['flags-only'])
    || isset($opts['pain-only'])
    || isset($opts['dictionary-only']);
$doConditions = !$onlyOne || isset($opts['conditions-only']);
$doAllergies = !$onlyOne || isset($opts['allergies-only']);
$doSymptoms = !$onlyOne || isset($opts['symptoms-only']);
$doFlags = !$onlyOne || isset($opts['flags-only']);
$doPain = !$onlyOne || isset($opts['pain-only']);
$doDictionary = !$onlyOne || isset($opts['dictionary-only']);
$truncate = isset($opts['truncate']);

if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "Database connection not available. Check config/db.php\n");
    exit(1);
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/**
 * Bulk INSERT for large CSV files (much faster than row-by-row execute).
 *
 * @param list<string> $columns
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

function tableExists(PDO $pdo, string $table): bool
{
    // MariaDB/MySQL often reject placeholders in SHOW TABLES LIKE.
    $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $table) ?? '';
    if ($safe === '' || $safe !== $table) {
        return false;
    }
    $stmt = $pdo->query("SHOW TABLES LIKE '{$safe}'");
    return $stmt !== false && (bool) $stmt->fetchColumn();
}

echo "medConnect NLP dataset import\n";
echo "Note: runtime NLP still primarily loads CSV files; MySQL mirrors optional tables.\n";

$pdo->exec('SET FOREIGN_KEY_CHECKS=0');

if ($truncate) {
    $tables = [];
    if ($doConditions) {
        $tables[] = 'nlp_medical_conditions';
    }
    if ($doAllergies) {
        $tables[] = 'nlp_allergies';
    }
    if ($doSymptoms) {
        $tables[] = 'nlp_symptoms';
    }
    if ($doFlags) {
        $tables[] = 'nlp_emergency_flags';
    }
    if ($doPain) {
        $tables[] = 'nlp_body_part_pain_symptoms';
    }
    if ($doDictionary) {
        $tables[] = 'nlp_medical_dictionary';
    }
    foreach ($tables as $table) {
        if (tableExists($pdo, $table)) {
            $pdo->exec('TRUNCATE TABLE `' . $table . '`');
            echo "Truncated {$table}\n";
        }
    }
}

$totalConditions = 0;
if ($doConditions) {
    if (!tableExists($pdo, 'nlp_medical_conditions')) {
        echo "Missing table nlp_medical_conditions. Apply database/schema_nlp_reference.sql first.\n";
    } else {
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
            $handle0 = fopen($parts[0], 'r');
            $sampleHeader = $handle0 ? fgetcsv($handle0) : false;
            if ($handle0) {
                fclose($handle0);
            }
            $hasIcdCols = is_array($sampleHeader) && in_array('icd10_code', $sampleHeader, true);
            if (!$hasIcdCols) {
                echo "Condition CSV headers differ from ICD import shape; skipping SQL conditions for this file set.\n";
            } else {
                $cols = [
                    'icd10_code', 'condition_name', 'icd10_category', 'chapter_code',
                    'chapter_title', 'long_description', 'is_billable', 'search_name', 'source',
                ];
                foreach ($parts as $file) {
                    try {
                        $n = importCsvBatch($pdo, 'nlp_medical_conditions', $cols, $file);
                        $totalConditions += $n;
                        echo "Imported {$n} rows from " . basename($file) . "\n";
                    } catch (Throwable $e) {
                        echo "Skip " . basename($file) . ": " . $e->getMessage() . "\n";
                    }
                }
            }
            echo "Total conditions: {$totalConditions}\n";
        }
    }
}

$totalAllergies = 0;
if ($doAllergies) {
    if (!tableExists($pdo, 'nlp_allergies')) {
        echo "Missing table nlp_allergies. Apply database/schema_nlp_reference.sql first.\n";
    } else {
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
}

$totalSymptoms = 0;
if ($doSymptoms) {
    if (!tableExists($pdo, 'nlp_symptoms')) {
        echo "Missing table nlp_symptoms. Apply database/schema_nlp_reference.sql first.\n";
    } else {
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
            $handle0 = fopen($parts[0], 'r');
            $header0 = $handle0 ? fgetcsv($handle0) : false;
            if ($handle0) {
                fclose($handle0);
            }
            $cols = is_array($header0) && in_array('search_name', $header0, true)
                ? ['symptom_name', 'category', 'description', 'related_body_system', 'search_name', 'source']
                : ['symptom_name', 'category', 'description', 'related_body_system'];
            foreach ($parts as $file) {
                try {
                    $n = importCsvBatch($pdo, 'nlp_symptoms', $cols, $file);
                    $totalSymptoms += $n;
                    echo "Imported {$n} rows from " . basename($file) . "\n";
                } catch (Throwable $e) {
                    echo "Skip " . basename($file) . ": " . $e->getMessage() . "\n";
                }
            }
            echo "Total symptoms: {$totalSymptoms}\n";
        }
    }
}

$totalFlags = 0;
if ($doFlags) {
    if (!tableExists($pdo, 'nlp_emergency_flags')) {
        echo "Missing table nlp_emergency_flags. Apply database/schema_nlp_reference.sql first.\n";
    } else {
        $file = $projectRoot . '/data/nlp/emergency_flags.csv';
        if (!is_readable($file)) {
            echo "Missing emergency_flags.csv. Run: python scripts/dev/generate_clinical_triage_datasets.py\n";
        } else {
            $cols = [
                'flag_id', 'flag_name', 'hiligaynon_pattern', 'english_pattern',
                'body_system', 'category', 'auto_triage', 'severity', 'clinical_rationale', 'status',
            ];
            $totalFlags = importCsvBatch($pdo, 'nlp_emergency_flags', $cols, $file);
            echo "Imported {$totalFlags} emergency flags\n";
        }
    }
}

$totalPain = 0;
if ($doPain) {
    if (!tableExists($pdo, 'nlp_body_part_pain_symptoms')) {
        echo "Missing table nlp_body_part_pain_symptoms. Apply database/schema_nlp_reference.sql first.\n";
    } else {
        $file = $projectRoot . '/data/nlp/body_part_pain_symptoms.csv';
        if (!is_readable($file)) {
            echo "Missing body_part_pain_symptoms.csv. Run: python scripts/data/build_body_part_pain_symptoms.py\n";
        } else {
            $cols = ['english_alias', 'canonical_english', 'official_symptom', 'body_part', 'notes'];
            $totalPain = importCsvBatch($pdo, 'nlp_body_part_pain_symptoms', $cols, $file);
            echo "Imported {$totalPain} body-part pain mappings\n";
        }
    }
}

$totalDictionary = 0;
if ($doDictionary) {
    if (!tableExists($pdo, 'nlp_medical_dictionary')) {
        echo "Missing table nlp_medical_dictionary. Apply database/schema_nlp_reference.sql first.\n";
    } else {
        $file = $projectRoot . '/data/nlp/medical_dictionary.csv';
        if (!is_readable($file)) {
            echo "Missing medical_dictionary.csv\n";
        } else {
            $cols = ['dictionary_id', 'local_term', 'english_term', 'category'];
            $totalDictionary = importCsvBatch($pdo, 'nlp_medical_dictionary', $cols, $file, 2000);
            echo "Imported {$totalDictionary} dictionary terms\n";
        }
    }
}

$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

echo "Done.\n";
echo "Summary: conditions={$totalConditions} allergies={$totalAllergies} symptoms={$totalSymptoms} flags={$totalFlags} pain={$totalPain} dictionary={$totalDictionary}\n";
