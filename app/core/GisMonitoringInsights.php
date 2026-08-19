<?php
/**
 * GIS population/spatial monitoring — not medical triage.
 *
 * Status and hotspot flags are derived only from mapped case counts,
 * optional real barangay population, and BHW assignment fields already
 * present on patient rows. Missing population is never invented.
 */
final class GisMonitoringInsights
{
    public const STATUS_STABLE = 'stable';
    public const STATUS_MONITORING = 'monitoring';
    public const STATUS_ELEVATED = 'elevated';
    public const STATUS_CRITICAL = 'critical';

    /**
     * @param list<array<string, mixed>> $patients
     * @param array<string, int> $populationByBarangay Positive census counts only.
     * @return array<string, mixed>
     */
    public static function fromPatients(array $patients, array $populationByBarangay = []): array
    {
        $today = date('Y-m-d');
        $weekAgo = date('Y-m-d', strtotime('-7 days') ?: time());
        $populationByBarangay = self::sanitizePopulationMap($populationByBarangay);
        $populationAvailable = $populationByBarangay !== [];

        $barangays = [];
        $newToday = 0;
        $unmapped = 0;
        $invalid = 0;
        $unassignedBhw = 0;
        $exact = 0;
        $geocoded = 0;
        $barangayLevel = 0;

        foreach ($patients as $row) {
            $barangay = trim((string) ($row['barangay'] ?? '')) ?: 'Unknown';
            if (!isset($barangays[$barangay])) {
                $barangays[$barangay] = [
                    'name' => $barangay,
                    'registered' => 0,
                    'active_consultations' => 0,
                    'non_urgent' => 0,
                    'urgent' => 0,
                    'emergency' => 0,
                    'total' => 0,
                    'recent_7d' => 0,
                    'unassigned_bhw' => 0,
                    'unmapped' => 0,
                    'mapped' => 0,
                    'gps' => 0,
                    'assigned_bhw_names' => [],
                    'lat_min' => null,
                    'lat_max' => null,
                    'lng_min' => null,
                    'lng_max' => null,
                ];
            }

            $barangays[$barangay]['registered']++;
            $barangays[$barangay]['total']++;
            $barangays[$barangay]['active_consultations'] += (int) ($row['active_consultations'] ?? 0);

            $level = strtolower((string) ($row['triage_level'] ?? 'non_urgent'));
            if ($level === 'emergency') {
                $barangays[$barangay]['emergency']++;
            } elseif ($level === 'urgent') {
                $barangays[$barangay]['urgent']++;
            } else {
                $barangays[$barangay]['non_urgent']++;
            }

            $created = self::rowDate($row);
            if ($created === $today) {
                $newToday++;
            }
            if ($created !== '' && $created >= $weekAgo) {
                $barangays[$barangay]['recent_7d']++;
            }

            $bhw = trim((string) ($row['assigned_bhw'] ?? ''));
            if ($bhw === '' || strcasecmp($bhw, 'Not assigned') === 0) {
                $unassignedBhw++;
                $barangays[$barangay]['unassigned_bhw']++;
            } else {
                $barangays[$barangay]['assigned_bhw_names'][$bhw] = true;
            }

            $quality = strtoupper((string) ($row['location_quality'] ?? ''));
            $accuracy = strtolower((string) ($row['location_accuracy'] ?? ''));
            $source = strtolower((string) ($row['location_source'] ?? ''));
            $hasMarker = !empty($row['has_map_marker']);

            if ($source === 'gps' || $source === 'manual' || $source === 'imported' || $accuracy === 'exact') {
                $barangays[$barangay]['gps']++;
            }

            if ($quality === 'INVALID_LOCATION' || $accuracy === 'invalid') {
                $invalid++;
            }
            if (!$hasMarker || $quality === 'MISSING_LOCATION' || $accuracy === 'unavailable') {
                $unmapped++;
                $barangays[$barangay]['unmapped']++;
            } else {
                $barangays[$barangay]['mapped']++;
                $lat = isset($row['latitude']) ? (float) $row['latitude'] : null;
                $lng = isset($row['longitude']) ? (float) $row['longitude'] : null;
                if ($lat !== null && $lng !== null && $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180 && !($lat == 0.0 && $lng == 0.0)) {
                    $stats = &$barangays[$barangay];
                    $stats['lat_min'] = $stats['lat_min'] === null ? $lat : min($stats['lat_min'], $lat);
                    $stats['lat_max'] = $stats['lat_max'] === null ? $lat : max($stats['lat_max'], $lat);
                    $stats['lng_min'] = $stats['lng_min'] === null ? $lng : min($stats['lng_min'], $lng);
                    $stats['lng_max'] = $stats['lng_max'] === null ? $lng : max($stats['lng_max'], $lng);
                    unset($stats);
                }
                if ($quality === 'EXACT_LOCATION' || $accuracy === 'exact') {
                    $exact++;
                } elseif ($quality === 'GEOCODED_LOCATION' || $accuracy === 'geocoded') {
                    $geocoded++;
                } else {
                    $barangayLevel++;
                }
            }
        }

        $rates = [];
        foreach ($barangays as $name => &$stats) {
            $pop = $populationByBarangay[$name] ?? null;
            $stats['population'] = $pop;
            $stats['population_label'] = $pop !== null
                ? number_format($pop)
                : 'Population data unavailable';
            $stats['rate_per_1000'] = ($pop !== null && $pop > 0)
                ? round(($stats['total'] / $pop) * 1000, 2)
                : null;
            if ($stats['rate_per_1000'] !== null) {
                $rates[] = $stats['rate_per_1000'];
            }
            $stats['status'] = self::statusForBarangay($stats);
            $stats['status_label'] = self::statusLabel($stats['status']);
            $stats['is_hotspot'] = false;
            $stats['assigned_bhw_count'] = count($stats['assigned_bhw_names'] ?? []);
            unset($stats['assigned_bhw_names']);
            if ($stats['lat_min'] !== null && $stats['lng_min'] !== null) {
                $stats['bounds'] = [
                    'south' => $stats['lat_min'],
                    'west'  => $stats['lng_min'],
                    'north' => $stats['lat_max'],
                    'east'  => $stats['lng_max'],
                ];
            } else {
                $stats['bounds'] = null;
            }
            unset($stats['lat_min'], $stats['lat_max'], $stats['lng_min'], $stats['lng_max']);
        }
        unset($stats);

        $rateMean = $rates !== [] ? array_sum($rates) / count($rates) : null;
        $rateStd = self::stddev($rates, $rateMean);

        foreach ($barangays as &$stats) {
            $stats['is_hotspot'] = self::isHotspot($stats, $rateMean, $rateStd);
        }
        unset($stats);

        $list = array_values($barangays);
        usort($list, static function (array $a, array $b): int {
            $rank = [
                self::STATUS_CRITICAL => 0,
                self::STATUS_ELEVATED => 1,
                self::STATUS_MONITORING => 2,
                self::STATUS_STABLE => 3,
            ];
            $cmp = ($rank[$a['status']] ?? 9) <=> ($rank[$b['status']] ?? 9);
            if ($cmp !== 0) {
                return $cmp;
            }
            if ($b['emergency'] !== $a['emergency']) {
                return $b['emergency'] <=> $a['emergency'];
            }

            return $b['total'] <=> $a['total'];
        });

        $hotspots = array_values(array_filter($list, static fn (array $row): bool => !empty($row['is_hotspot'])));

        return [
            'population_available' => $populationAvailable,
            'population_by_barangay' => $populationByBarangay,
            'new_cases_today' => $newToday,
            'unmapped_cases' => $unmapped,
            'invalid_coordinates' => $invalid,
            'unassigned_bhw' => $unassignedBhw,
            'location_quality' => [
                'exact' => $exact,
                'geocoded' => $geocoded,
                'barangay' => $barangayLevel,
                'missing' => $unmapped,
                'invalid' => $invalid,
            ],
            'highest_case_barangay' => self::topBy($list, 'total'),
            'highest_emergency_barangay' => self::topBy($list, 'emergency'),
            'barangays_with_cases' => count($list),
            'highest_rate_barangay' => $populationAvailable ? self::topByRate($list) : null,
            'hotspots' => $hotspots,
            'barangays' => $list,
            'note' => $populationAvailable
                ? 'Case rates use recorded barangay population. GIS status is spatial monitoring only and does not change medical triage.'
                : 'Population data unavailable — case rates per 1,000 are not calculated. GIS status uses emergency/urgent concentration only and does not change medical triage.',
        ];
    }

    /**
     * @param array<string, mixed> $stats
     */
    private static function statusForBarangay(array $stats): string
    {
        $emergency = (int) ($stats['emergency'] ?? 0);
        $urgent = (int) ($stats['urgent'] ?? 0);
        $recent = (int) ($stats['recent_7d'] ?? 0);
        $total = (int) ($stats['total'] ?? 0);

        if ($emergency >= 2 || ($emergency >= 1 && $urgent >= 2)) {
            return self::STATUS_CRITICAL;
        }
        if ($emergency >= 1 || $urgent >= 2) {
            return self::STATUS_ELEVATED;
        }
        if ($urgent >= 1 || $recent >= 2 || $total >= 3) {
            return self::STATUS_MONITORING;
        }

        return self::STATUS_STABLE;
    }

    /**
     * @param array<string, mixed> $stats
     */
    private static function isHotspot(array $stats, ?float $rateMean, ?float $rateStd): bool
    {
        $status = (string) ($stats['status'] ?? '');
        if ($status === self::STATUS_CRITICAL || $status === self::STATUS_ELEVATED) {
            return true;
        }

        $rate = $stats['rate_per_1000'] ?? null;
        if ($rate === null || $rateMean === null || $rateStd === null) {
            return false;
        }

        return (int) ($stats['total'] ?? 0) >= 2
            && (float) $rate >= ($rateMean + $rateStd)
            && (int) ($stats['urgent'] ?? 0) + (int) ($stats['emergency'] ?? 0) >= 1;
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_CRITICAL => 'Critical',
            self::STATUS_ELEVATED => 'Elevated',
            self::STATUS_MONITORING => 'Monitoring',
            default => 'Stable',
        };
    }

    /**
     * @param array<string, mixed> $map
     * @return array<string, int>
     */
    private static function sanitizePopulationMap(array $map): array
    {
        $clean = [];
        foreach ($map as $name => $value) {
            $label = trim((string) $name);
            $count = is_numeric($value) ? (int) $value : 0;
            if ($label === '' || $count <= 0) {
                continue;
            }
            $clean[$label] = $count;
        }

        return $clean;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{name:string,count:int,display:string}|null
     */
    private static function topBy(array $rows, string $field): ?array
    {
        $best = null;
        foreach ($rows as $row) {
            $count = (int) ($row[$field] ?? 0);
            if ($best === null || $count > $best['count']) {
                $best = [
                    'name' => (string) ($row['name'] ?? ''),
                    'count' => $count,
                    'display' => ($row['name'] ?? '') . ' (' . $count . ')',
                ];
            }
        }
        if ($best === null || $best['count'] <= 0 || $best['name'] === '') {
            return null;
        }

        return $best;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{name:string,rate:float,display:string}|null
     */
    private static function topByRate(array $rows): ?array
    {
        $best = null;
        foreach ($rows as $row) {
            if ($row['rate_per_1000'] === null) {
                continue;
            }
            $rate = (float) $row['rate_per_1000'];
            if ($best === null || $rate > $best['rate']) {
                $best = [
                    'name' => (string) ($row['name'] ?? ''),
                    'rate' => $rate,
                    'display' => ($row['name'] ?? '') . ' (' . $rate . ' / 1,000)',
                ];
            }
        }

        return $best;
    }

    /**
     * @param list<float> $values
     */
    private static function stddev(array $values, ?float $mean): ?float
    {
        if ($mean === null || count($values) < 2) {
            return null;
        }
        $sum = 0.0;
        foreach ($values as $value) {
            $sum += ($value - $mean) ** 2;
        }

        return sqrt($sum / count($values));
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function rowDate(array $row): string
    {
        $raw = (string) ($row['registration_date'] ?? '');
        if ($raw === '') {
            return '';
        }
        $ts = strtotime($raw);

        return $ts ? date('Y-m-d', $ts) : '';
    }
}
