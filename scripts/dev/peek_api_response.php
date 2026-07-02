<?php
$j = json_decode(file_get_contents(__DIR__ . '/api_response2.json'), true);
$d = $j['data'] ?? [];
echo 'engine=' . ($d['engine'] ?? '') . PHP_EOL;
echo 'service_used=' . (!empty($d['service_used']) ? 'true' : 'false') . PHP_EOL;
echo 'summary=' . ($d['summary'] ?? '') . PHP_EOL;
