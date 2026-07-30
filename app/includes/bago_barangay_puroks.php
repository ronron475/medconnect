<?php
/**
 * Official / reference purok names for Bago City barangays.
 */
require_once __DIR__ . '/barangays_bago.php';

final class BagoBarangayPuroks
{
    /** @var array<string, mixed>|null */
    private static ?array $catalog = null;

    public static function ensureSchema(PDO $pdo): void
    {
        barangays_ensure_bago_city($pdo);
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS barangay_puroks (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                barangay_id INT UNSIGNED NOT NULL,
                purok_name VARCHAR(120) NOT NULL,
                source VARCHAR(32) NOT NULL DEFAULT 'reference',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_brgy_purok (barangay_id, purok_name),
                KEY idx_brgy_purok_brgy (barangay_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        self::seedFromCatalog($pdo);
    }

    public static function seedFromCatalog(PDO $pdo): void
    {
        self::loadCatalog();
        $insert = $pdo->prepare("
            INSERT IGNORE INTO barangay_puroks (barangay_id, purok_name, source)
            VALUES (?, ?, 'reference')
        ");
        foreach (barangays_list_bago_city($pdo) as $brgy) {
            $id = (int) ($brgy['id'] ?? 0);
            $name = (string) ($brgy['name'] ?? '');
            if ($id <= 0 || $name === '') {
                continue;
            }
            foreach (self::referenceLabelsForName($name) as $label) {
                $insert->execute([$id, $label]);
            }
        }
    }

    /**
     * @return list<string>
     */
    public static function labelsForBarangay(PDO $pdo, ?int $barangayId, ?string $barangayName = null): array
    {
        self::ensureSchema($pdo);
        $labels = [];

        if ($barangayId !== null && $barangayId > 0) {
            $stmt = $pdo->prepare('
                SELECT purok_name FROM barangay_puroks
                WHERE barangay_id = ?
                ORDER BY purok_name ASC
            ');
            $stmt->execute([$barangayId]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $row) {
                $p = trim((string) $row);
                if ($p !== '') {
                    $labels[$p] = true;
                }
            }
            if ($barangayName === null || $barangayName === '') {
                $n = $pdo->prepare('SELECT name FROM barangays WHERE id = ? LIMIT 1');
                $n->execute([$barangayId]);
                $barangayName = (string) ($n->fetchColumn() ?: '');
            }
        }

        if ($barangayName !== null && trim($barangayName) !== '') {
            foreach (self::referenceLabelsForName(trim($barangayName)) as $ref) {
                $labels[$ref] = true;
            }
        }

        $sorted = array_keys($labels);
        usort($sorted, static fn (string $a, string $b): int => strnatcasecmp($a, $b));

        return $sorted;
    }

    public static function upsertPatientPurok(PDO $pdo, int $barangayId, string $purokName): void
    {
        if ($barangayId <= 0) {
            return;
        }
        $purokName = trim($purokName);
        if ($purokName === '') {
            return;
        }
        self::ensureSchema($pdo);
        $pdo->prepare('
            INSERT IGNORE INTO barangay_puroks (barangay_id, purok_name, source)
            VALUES (?, ?, \'patient\')
        ')->execute([$barangayId, $purokName]);
    }

    /**
     * @return list<string>
     */
    private static function referenceLabelsForName(string $barangayName): array
    {
        self::loadCatalog();
        $key = self::resolveCatalogKey($barangayName);
        if ($key === null) {
            return [];
        }
        $entry = self::$catalog['barangays'][$key] ?? [];
        $labels = [];
        foreach ($entry['named'] ?? [] as $named) {
            $n = trim((string) $named);
            if ($n !== '') {
                $labels[$n] = true;
            }
        }
        // Do not auto-generate "Purok 1..N" — City of Bago site does not publish verified lists.
        $out = array_keys($labels);
        usort($out, static fn (string $a, string $b): int => strnatcasecmp($a, $b));

        return $out;
    }

    private static function resolveCatalogKey(string $barangayName): ?string
    {
        $name = trim($barangayName);
        if ($name === '') {
            return null;
        }
        $barangays = self::$catalog['barangays'] ?? [];
        if (isset($barangays[$name])) {
            return $name;
        }
        $aliases = self::$catalog['aliases'] ?? [];
        if (isset($aliases[$name])) {
            return $aliases[$name];
        }
        foreach (array_keys($barangays) as $key) {
            if (strcasecmp($key, $name) === 0) {
                return $key;
            }
        }

        return null;
    }

    private static function loadCatalog(): void
    {
        if (self::$catalog !== null) {
            return;
        }
        $path = defined('BASE_PATH')
            ? BASE_PATH . '/data/geo/bago_city_puroks.json'
            : dirname(dirname(__DIR__)) . '/data/geo/bago_city_puroks.json';
        if (!is_readable($path)) {
            self::$catalog = ['barangays' => [], 'aliases' => []];

            return;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        self::$catalog = is_array($decoded) ? $decoded : ['barangays' => [], 'aliases' => []];
    }
}
