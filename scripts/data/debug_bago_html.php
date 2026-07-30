<?php
$url = $argv[1] ?? 'https://bagocity.gov.ph/barangays/barangay-taloc/';
$html = file_get_contents($url);
if (!$html) {
    echo "fail\n";
    exit(1);
}
if (preg_match('/No\.\s*of\s*Puroks.*?(\d+)/is', $html, $m)) {
    echo "match1: " . $m[1] . "\n";
}
if (preg_match('/Puroks<\/[^>]+>[\s\S]{0,800}/i', $html, $m)) {
    echo strip_tags($m[0]) . "\n";
}
echo "len=" . strlen($html) . "\n";
