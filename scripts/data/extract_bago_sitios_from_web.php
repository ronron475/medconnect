<?php
/**
 * Extract Sitio/Purok names from City of Bago barangay profile pages (history text).
 * Usage: php scripts/data/extract_bago_sitios_from_web.php
 */
declare(strict_types=1);

$base = dirname(dirname(__DIR__));

$slugMap = [
    'Abuanan' => 'barangay-abuanan',
    'Alianza' => 'barangay-alianza',
    'Atipuluan' => 'barangay-atipuluan',
    'Bacong-Montilla' => 'barangay-bacong',
    'Bagroy' => 'barangay-bagroy',
    'Balingasag' => 'barangay-balingasag',
    'Binubuhan' => 'barangay-binubuhan',
    'Busay' => 'barangay-busay',
    'Calumangan' => 'barangay-calumangan',
    'Caridad' => 'barangay-caridad',
    'Don Jorge L. Araneta' => 'barangay-don-jorge-araneta',
    'Dulao' => 'barangay-dulao',
    'Ilijan' => 'barangay-ilijan',
    'Lag-Asan' => 'barangay-lag-asan',
    'Ma-ao' => 'barangay-ma-ao',
    'Mailum' => 'barangay-mailum',
    'Malingin' => 'barangay-malingin',
    'Napoles' => 'barangay-napoles',
    'Pacol' => 'barangay-pacol',
    'Poblacion' => 'barangay-poblacion',
    'Sagasa' => 'barangay-sagasa',
    'Sampinit' => 'barangay-sampinit',
    'Taba-ao' => 'barangay-taba-ao',
    'Tabunan' => 'barangay-tabunan',
    'Taloc' => 'barangay-taloc',
];

$ctx = stream_context_create([
    'http' => ['timeout' => 25, 'user_agent' => 'medConnect/1.0'],
    'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
]);

$manual = [
    'Binubuhan' => ['Purok Sunflower'],
    'Malingin' => ['Sitio Malingin Daku', 'Sitio Malingin Diutay'],
    'Sampinit' => ['Sitio Pinanuy-an'],
];

$barangays = [];
foreach ($slugMap as $name => $slug) {
    $url = 'https://bagocity.gov.ph/barangays/' . $slug . '/';
    $html = @file_get_contents($url, false, $ctx);
    $found = $manual[$name] ?? [];

    if (is_string($html) && $html !== '') {
        $text = html_entity_decode(strip_tags($html));
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        if (preg_match_all('/\b((?:Purok|Sitio)\s+[A-Z][A-Za-z0-9\-\'\s]{2,45})/u', $text, $m)) {
            foreach ($m[1] as $hit) {
                $hit = trim(preg_replace('/\s+/', ' ', $hit) ?? $hit);
                if (strlen($hit) > 8 && stripos($hit, 'No. of') === false) {
                    $found[$hit] = true;
                }
            }
        }
        if (preg_match_all('/known as (Malingin (?:Daku|Diutay))/i', $text, $mm)) {
            foreach ($mm[1] as $s) {
                $found['Sitio ' . trim($s)] = true;
            }
        }
    }

    $list = [];
    foreach ($found as $k => $v) {
        $list[] = is_int($k) ? $v : $k;
    }
    $list = array_values(array_unique($list));
    sort($list, SORT_NATURAL | SORT_FLAG_CASE);

    $barangays[$name] = [
        'named' => $list,
        'source_url' => $url,
    ];
    echo $name . ': ' . count($list) . ' — ' . implode('; ', array_slice($list, 0, 5)) . PHP_EOL;
    usleep(200000);
}

$out = [
    '_meta' => [
        'city' => 'Bago City',
        'province' => 'Negros Occidental',
        'sources' => [
            'https://bagocity.gov.ph/barangays/',
            'Patient registrations in medConnect (sync script)',
            'Verified news/LGU references in named entries',
        ],
        'note' => 'The City of Bago website does not publish a full purok master list (Fast Facts show placeholders). Named entries come from barangay profile narratives and verified references. Run sync_bago_puroks_from_patients.php to merge registration data.',
        'updated_at' => date('c'),
    ],
    'barangays' => $barangays,
    'aliases' => [
        'Bacong Montilla' => 'Bacong-Montilla',
        'Don Jorge Araneta' => 'Don Jorge L. Araneta',
        'Lag-asan' => 'Lag-Asan',
        'Maao' => 'Ma-ao',
        'Tabaao' => 'Taba-ao',
        'Bacong' => 'Bacong-Montilla',
        'Ma-ao Barrio' => 'Ma-ao',
        'Jorge L. Araneta' => 'Don Jorge L. Araneta',
    ],
];

$path = $base . '/data/geo/bago_city_puroks.json';
file_put_contents($path, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
echo "Wrote {$path}\n";
