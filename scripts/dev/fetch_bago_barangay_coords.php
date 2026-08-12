<?php
/**
 * One-off: fetch barangay centroids from OSM Nominatim.
 * Usage: php scripts/dev/fetch_bago_barangay_coords.php
 */
$barangays = [
    'Poblacion', 'Abuanan', 'Alianza', 'Atipuluan', 'Bacong-Montilla', 'Bagroy',
    'Balingasag', 'Binubuhan', 'Busay', 'Calumangan', 'Caridad', 'Dulao', 'Ilijan',
    'Lag-Asan', 'Ma-ao', 'Mailum', 'Malingin', 'Napoles', 'Pacol', 'Sagasa',
    'Sampinit', 'Tabunan', 'Taloc', 'Taba-ao',
];

$out = [];
foreach ($barangays as $b) {
    $q = urlencode($b . ', Bago City, Negros Occidental, Philippines');
    $url = 'https://nominatim.openstreetmap.org/search?q=' . $q . '&format=json&limit=1';
    $ctx = stream_context_create([
        'http' => [
            'header' => "User-Agent: MedConnectGIS/1.0\r\n",
            'timeout' => 20,
        ],
    ]);
    $json = @file_get_contents($url, false, $ctx);
    $data = json_decode($json ?: '[]', true);
    if (!empty($data[0])) {
        $out[$b] = [
            'lat' => round((float) $data[0]['lat'], 6),
            'lng' => round((float) $data[0]['lon'], 6),
            'display_name' => (string) ($data[0]['display_name'] ?? ''),
        ];
        echo "{$b}: {$out[$b]['lat']}, {$out[$b]['lng']}\n";
    } else {
        echo "{$b}: MISSING\n";
    }
    usleep(1100000);
}

$path = dirname(__DIR__, 2) . '/data/geo/bago_barangay_locations.json';
if (!is_dir(dirname($path))) {
    mkdir(dirname($path), 0755, true);
}
file_put_contents($path, json_encode([
    'city' => 'Bago City',
    'province' => 'Negros Occidental',
    'country' => 'Philippines',
    'center' => ['lat' => 10.538797, 'lng' => 122.838447],
    'bounds' => [
        'south' => 10.48,
        'west' => 122.75,
        'north' => 10.60,
        'east' => 122.90,
    ],
    'barangays' => $out,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "\nWrote {$path}\n";
