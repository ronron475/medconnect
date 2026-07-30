<?php
/**
 * Fetch City of Bago barangay profile pages and extract purok counts/names.
 * Usage: php scripts/data/scrape_bago_barangay_puroks.php
 */
declare(strict_types=1);

$base = dirname(dirname(__DIR__));
require_once $base . '/bootstrap.php';

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

$out = [
    '_meta' => [
        'city' => 'Bago City',
        'province' => 'Negros Occidental',
        'source' => 'https://bagocity.gov.ph/barangays/',
        'scraped_at' => date('c'),
        'note' => 'numbered_through from barangay Fast Facts; named from page text matches.',
    ],
    'barangays' => [],
    'aliases' => [
        'Bacong Montilla' => 'Bacong-Montilla',
        'Don Jorge Araneta' => 'Don Jorge L. Araneta',
        'Lag-asan' => 'Lag-Asan',
        'Maao' => 'Ma-ao',
        'Tabaao' => 'Taba-ao',
        'Bacong' => 'Bacong-Montilla',
    ],
];

$ctx = stream_context_create([
    'http' => [
        'timeout' => 20,
        'user_agent' => 'medConnectDataBot/1.0 (+local research)',
    ],
    'ssl' => [
        'verify_peer' => true,
        'verify_peer_name' => true,
    ],
]);

foreach ($slugMap as $name => $slug) {
    $url = 'https://bagocity.gov.ph/barangays/' . $slug . '/';
    $html = @file_get_contents($url, false, $ctx);
    $numbered = null;
    $named = [];

    if (is_string($html) && $html !== '') {
        if (preg_match('/No\.\s*of\s*Puroks[\s\S]{0,600}?(\d+)\s*\(Projected\)/i', $html, $m)) {
            $numbered = (int) $m[1];
        } elseif (preg_match('/No\.\s*of\s*Puroks[\s\S]{0,300}?>\s*(\d+)\s*</i', $html, $m)) {
            $numbered = (int) $m[1];
        }

        if (preg_match_all('/\b(Purok\s+[A-Za-z][A-Za-z0-9\-\'\s]{0,35})/u', $html, $pm)) {
            foreach ($pm[1] as $p) {
                $p = trim(preg_replace('/\s+/', ' ', $p) ?? $p);
                if (strlen($p) > 6 && stripos($p, 'No. of') === false) {
                    $named[$p] = true;
                }
            }
        }
        if (preg_match_all('/\b(Sitio\s+[A-Za-z][A-Za-z0-9\-\'\s]{0,35})/u', $html, $sm)) {
            foreach ($sm[1] as $p) {
                $p = trim(preg_replace('/\s+/', ' ', $p) ?? $p);
                if (strlen($p) > 6) {
                    $named[$p] = true;
                }
            }
        }
    }

  // Verified external: Binubuhan Purok Sunflower (Negros Power electrification)
    if ($name === 'Binubuhan') {
        $named['Purok Sunflower'] = true;
    }

    $namedList = array_keys($named);
    sort($namedList, SORT_NATURAL | SORT_FLAG_CASE);

    $out['barangays'][$name] = [
        'named' => $namedList,
        'numbered_through' => $numbered > 0 ? $numbered : null,
        'source_url' => $url,
    ];

    echo $name . ': numbered=' . ($numbered ?? 'n/a') . ' named=' . count($namedList) . PHP_EOL;
    usleep(250000);
}

$path = $base . '/data/geo/bago_city_puroks.json';
file_put_contents($path, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
echo "Wrote {$path}\n";
