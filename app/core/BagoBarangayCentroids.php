<?php
/**
 * Bago City barangay geographic reference data.
 *
 * Canonical coordinates live in data/geo/bago_barangay_locations.json and are
 * synced into the barangays table at runtime. No random or per-patient offsets.
 */
final class BagoBarangayCentroids
{
    private const DATA_FILE = 'data/geo/bago_barangay_locations.json';

    /** @var array<string, mixed>|null */
    private static ?array $dataset = null;

    /** @var array<string, array{lat: float, lng: float}>|null */
    private static ?array $map = null;

    /** @var array<string, string>|null */
    private static ?array $aliases = null;

    /** @var list<string>|null */
    private static ?array $names = null;

    /**
     * @return array<string, mixed>
     */
    public static function dataset(): array
    {
        if (self::$dataset !== null) {
            return self::$dataset;
        }

        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        $path = $base . '/' . self::DATA_FILE;
        if (!is_file($path)) {
            self::$dataset = self::fallbackDataset();

            return self::$dataset;
        }

        $json = file_get_contents($path);
        $decoded = json_decode($json ?: '', true);
        self::$dataset = is_array($decoded) ? $decoded : self::fallbackDataset();

        return self::$dataset;
    }

    /**
     * @return array{lat: float, lng: float}
     */
    public static function cityCenter(): array
    {
        $center = self::dataset()['center'] ?? [];

        return [
            'lat' => (float) ($center['lat'] ?? 10.538797),
            'lng' => (float) ($center['lng'] ?? 122.838447),
        ];
    }

    /**
     * @return array{south: float, west: float, north: float, east: float}
     */
    public static function cityBounds(): array
    {
        $bounds = self::dataset()['bounds'] ?? [];

        return [
            'south' => (float) ($bounds['south'] ?? 10.478),
            'west'  => (float) ($bounds['west'] ?? 122.748),
            'north' => (float) ($bounds['north'] ?? 10.598),
            'east'  => (float) ($bounds['east'] ?? 122.898),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function mapConfig(): array
    {
        $dataset = self::dataset();

        return [
            'center'       => self::cityCenter(),
            'bounds'       => self::cityBounds(),
            'default_zoom' => (int) ($dataset['default_zoom'] ?? 12),
            'city'         => (string) ($dataset['city'] ?? 'Bago City'),
            'province'     => (string) ($dataset['province'] ?? 'Negros Occidental'),
        ];
    }

    /**
     * Official barangays of Bago City, Negros Occidental (24).
     *
     * @return list<string>
     */
    public static function barangayNames(): array
    {
        if (self::$names !== null) {
            return self::$names;
        }

        self::$names = array_keys(self::barangayMap());
        usort(self::$names, static fn (string $a, string $b): int => strcasecmp($a, $b));

        return self::$names;
    }

    /**
     * @return list<array{name: string, lat: float, lng: float}>
     */
    public static function barangayRecords(): array
    {
        $records = [];
        foreach (self::barangayNames() as $name) {
            $coords = self::resolve($name);
            $records[] = [
                'name' => $name,
                'lat'  => $coords['lat'],
                'lng'  => $coords['lng'],
            ];
        }

        return $records;
    }

    /**
     * Resolve canonical barangay name from free-text input.
     */
    public static function canonicalName(string $barangay): ?string
    {
        $key = self::normalizeKey($barangay);
        if ($key === '') {
            return null;
        }

        $aliases = self::aliasMap();
        if (isset($aliases[$key])) {
            return $aliases[$key];
        }

        foreach (self::barangayMap() as $name => $_coords) {
            if (self::normalizeKey($name) === $key) {
                return $name;
            }
        }

        return null;
    }

    public static function normalizeBarangayName(string $barangay): string
    {
        return self::canonicalName($barangay) ?? trim($barangay);
    }

    /**
     * Resolve verified barangay center only — returns null when barangay cannot be matched.
     *
     * @return array{lat: float, lng: float}|null
     */
    public static function resolveBarangayCenter(string $barangay): ?array
    {
        $canonical = self::canonicalName($barangay);
        if ($canonical !== null && isset(self::barangayMap()[$canonical])) {
            return self::barangayMap()[$canonical];
        }

        return null;
    }

    /**
     * @return array{lat: float, lng: float}
     */
    public static function resolve(string $barangay, string $city = 'Bago City'): array
    {
        unset($city);

        $center = self::resolveBarangayCenter($barangay);

        return $center ?? self::cityCenter();
    }

    /**
     * @return array<string, array{lat: float, lng: float}>
     */
    private static function barangayMap(): array
    {
        if (self::$map !== null) {
            return self::$map;
        }

        self::$map = [];
        $rows = self::dataset()['barangays'] ?? [];
        if (!is_array($rows)) {
            return self::$map;
        }

        foreach ($rows as $name => $coords) {
            if (!is_array($coords)) {
                continue;
            }
            self::$map[(string) $name] = [
                'lat' => round((float) ($coords['lat'] ?? 0), 6),
                'lng' => round((float) ($coords['lng'] ?? 0), 6),
            ];
        }

        return self::$map;
    }

    /**
     * @return array<string, string>
     */
    private static function aliasMap(): array
    {
        if (self::$aliases !== null) {
            return self::$aliases;
        }

        self::$aliases = [];
        $rows = self::dataset()['aliases'] ?? [];
        if (!is_array($rows)) {
            return self::$aliases;
        }

        foreach ($rows as $alias => $canonical) {
            $key = self::normalizeKey((string) $alias);
            if ($key !== '') {
                self::$aliases[$key] = (string) $canonical;
            }
        }

        return self::$aliases;
    }

    private static function normalizeKey(string $barangay): string
    {
        $value = strtolower(trim($barangay));
        $value = preg_replace('/\s*\(pob\.?\)\s*/i', '', $value) ?? $value;
        $value = preg_replace('/\s*brgy\.?\s*/i', '', $value) ?? $value;
        $value = preg_replace('/\s*barangay\s*/i', '', $value) ?? $value;
        $value = preg_replace('/[^a-z0-9\s\-]/', '', $value) ?? $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        $value = str_replace([' ', '-'], '', $value);

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private static function fallbackDataset(): array
    {
        return [
            'city' => 'Bago City',
            'province' => 'Negros Occidental',
            'center' => ['lat' => 10.538797, 'lng' => 122.838447],
            'bounds' => [
                'south' => 10.478,
                'west' => 122.748,
                'north' => 10.598,
                'east' => 122.898,
            ],
            'default_zoom' => 12,
            'barangays' => [
                'Poblacion' => ['lat' => 10.538797, 'lng' => 122.838447],
            ],
            'aliases' => [],
        ];
    }
}
