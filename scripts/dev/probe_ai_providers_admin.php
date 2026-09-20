<?php
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once BASE_PATH . '/app/includes/ai_providers.php';
$s = ai_providers_live_snapshot(false);
foreach ($s['providers'] as $p) {
    echo $p['id']
        . ' | ' . ($p['state']['status'] ?? '?')
        . ' | enabled=' . (!empty($p['enabled']) ? '1' : '0')
        . ' | key=' . json_encode($p['api_key_set'])
        . ' | model=' . ($p['model'] ?? '-')
        . PHP_EOL;
}
echo 'triage_note_ok=' . (str_contains($s['triage_note'] ?? '', 'ClinicalTriageEngine') ? 'yes' : 'no') . PHP_EOL;
