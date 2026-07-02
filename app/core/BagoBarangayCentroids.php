<?php
/**
 * Approximate coordinates for Bago City barangays when GPS is unavailable.
 */
final class BagoBarangayCentroids
{
    private const CITY_CENTER = ['lat' => 10.5378, 'lng' => 122.8383];

    /** @var array<string, array{lat: float, lng: float}>|null */
    private static ?array $map = null;

    /** @var list<string>|null */
    private static ?array $names = null;

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

        self::$names = array_keys(self::rawCentroidRows());
        usort(self::$names, static fn(string $a, string $b): int => strcasecmp($a, $b));

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
     * @return array<string, array{0: float, 1: float}>
     */
    private static function rawCentroidRows(): array
    {
        return [
            'Poblacion' => [10.5378, 122.8383],
            'Abuanan' => [10.5521, 122.8512],
            'Alianza' => [10.5289, 122.8124],
            'Atipuluan' => [10.5612, 122.8245],
            'Bacong-Montilla' => [10.5198, 122.8456],
            'Bagroy' => [10.5445, 122.8156],
            'Balingasag' => [10.5312, 122.8289],
            'Binubuhan' => [10.5567, 122.8367],
            'Busay' => [10.5234, 122.8567],
            'Calumangan' => [10.5489, 122.8023],
            'Caridad' => [10.5156, 122.8312],
            'Dulao' => [10.5678, 122.8489],
            'Ilijan' => [10.5389, 122.8678],
            'Lag-Asan' => [10.5123, 122.8198],
            'Ma-ao' => [10.5545, 122.7934],
            'Mailum' => [10.5267, 122.8745],
            'Malingin' => [10.5412, 122.8056],
            'Napoles' => [10.5589, 122.8612],
            'Pacol' => [10.5178, 122.8423],
            'Sagasa' => [10.5498, 122.8289],
            'Sampinit' => [10.5334, 122.7989],
            'Tabunan' => [10.5623, 122.8178],
            'Taloc' => [10.5212, 122.8634],
            'Taba-ao' => [10.5456, 122.8523],
        ];
    }

    /**
     * @return array{lat: float, lng: float}
     */
    public static function resolve(string $barangay, string $city = 'Bago City'): array
    {
        $key = self::normalizeKey($barangay);
        if ($key !== '' && isset(self::centroidMap()[$key])) {
            return self::centroidMap()[$key];
        }

        if ($key !== '') {
            return self::hashJitter($key);
        }

        return self::CITY_CENTER;
    }

    /**
     * @return array<string, array{lat: float, lng: float}>
     */
    private static function centroidMap(): array
    {
        if (self::$map !== null) {
            return self::$map;
        }

        $rows = self::rawCentroidRows();

        self::$map = [];
        foreach ($rows as $name => [$lat, $lng]) {
            self::$map[self::normalizeKey($name)] = ['lat' => $lat, 'lng' => $lng];
        }

        return self::$map;
    }

    /**
     * @return array{lat: float, lng: float}
     */
    private static function hashJitter(string $normalizedBarangay): array
    {
        $hash = crc32($normalizedBarangay);
        $latOffset = (($hash & 0xff) - 128) / 8000.0;
        $lngOffset = ((($hash >> 8) & 0xff) - 128) / 8000.0;

        return [
            'lat' => round(self::CITY_CENTER['lat'] + $latOffset, 6),
            'lng' => round(self::CITY_CENTER['lng'] + $lngOffset, 6),
        ];
    }

    private static function normalizeKey(string $barangay): string
    {
        $value = strtolower(trim($barangay));
        $value = preg_replace('/\s*\(pob\.?\)\s*/i', '', $value) ?? $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return $value;
    }
}
