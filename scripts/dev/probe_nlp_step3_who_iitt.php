<?php
require __DIR__ . '/nlp_cli_bootstrap.php';

function ok(bool $c, string $l, string $d = ''): void
{
    echo ($c ? 'PASS  ' : 'FAIL  ') . $l . ($d !== '' ? " — {$d}" : '') . "\n";
    if (!$c) {
        $GLOBALS['fails'] = ($GLOBALS['fails'] ?? 0) + 1;
    }
}
$GLOBALS['fails'] = 0;

function eng(string $text): array
{
    return ClinicalTriageEngine::assess($text, $text);
}

function who(?array $r): string
{
    $w = $r['assessment_factors']['who_iitt'] ?? null;
    if (!is_array($w)) {
        return '-';
    }

    return (string) ($w['rule_id'] ?? '') . '/' . (string) ($w['triage_level'] ?? '');
}

echo "=== WHO IITT triage alignment ===\n";
echo 'WHO rules loaded: ' . count(WhoIittTriageRulesLoader::rules()) . "\n\n";

// Multi-complaint several days — must NOT auto-URGENT without WHO yellow/red
$multi = eng('pila na ka adlaw kasakit ulo ko kag sakit akon tiyan');
ok(($multi['triage_display'] ?? '') === 'NON-URGENT', 'multi head+abdomen days → NON-URGENT', (string) (($multi['triage_display'] ?? '') . ' who=' . who($multi)));

// Mild headache 5/10 gradual
$mild = eng('kasakit ulo ko, pila na ka adlaw, 5/10, hinay-hinay, wala iban nga sintomas');
ok(($mild['triage_display'] ?? '') === 'NON-URGENT', 'mild headache 5/10 → NON-URGENT', (string) ($mild['triage_display'] ?? ''));

// Isolated chest → URGENT (WHO Y015), not EMERGENCY
$chest = eng('Masakit dughan ko');
ok(($chest['triage_display'] ?? '') === 'URGENT', 'isolated chest → URGENT', (string) (($chest['triage_display'] ?? '') . ' who=' . who($chest)));
ok(($chest['triage_display'] ?? '') !== 'EMERGENCY', 'isolated chest not EMERGENCY');

// Chest + dyspnea → EMERGENCY
$chestEr = eng('Masakit dughan ko kag budlay magginhawa');
ok(($chestEr['triage_display'] ?? '') === 'EMERGENCY', 'chest+dyspnea → EMERGENCY', (string) (($chestEr['triage_display'] ?? '') . ' who=' . who($chestEr)));

// Heavy bleeding → EMERGENCY
$bleed = eng('grabe gid nagadugo');
ok(($bleed['triage_display'] ?? '') === 'EMERGENCY', 'heavy bleeding → EMERGENCY', (string) (($bleed['triage_display'] ?? '') . ' who=' . who($bleed)));

// Seizure → EMERGENCY
$sz = eng('naguyam ko');
ok(($sz['triage_display'] ?? '') === 'EMERGENCY', 'seizure → EMERGENCY', (string) (($sz['triage_display'] ?? '') . ' who=' . who($sz)));

// Headache + fever = meningism pair → EMERGENCY
$men = eng('kasakit ulo ko kag may lagnat');
ok(($men['triage_display'] ?? '') === 'EMERGENCY', 'headache+fever meningism → EMERGENCY', (string) (($men['triage_display'] ?? '') . ' who=' . who($men)));

// Severe pain alone → URGENT (WHO YELLOW)
$sev = eng('grabe gid ang kasakit ulo ko 9/10');
ok(($sev['triage_display'] ?? '') === 'URGENT', 'severe pain → URGENT', (string) (($sev['triage_display'] ?? '') . ' who=' . who($sev)));

// Urinary retention → URGENT not EMERGENCY
$urine = eng('wala ko maka-ihi');
ok(($urine['triage_display'] ?? '') === 'URGENT', 'urinary retention → URGENT', (string) (($urine['triage_display'] ?? '') . ' who=' . who($urine)));

// Wheezing → URGENT
$wheeze = eng('nagahubak ang akon ginhawa');
ok(($wheeze['triage_display'] ?? '') === 'URGENT', 'wheezing → URGENT', (string) (($wheeze['triage_display'] ?? '') . ' who=' . who($wheeze)));

echo "\nFails: " . (int) ($GLOBALS['fails'] ?? 0) . "\n";
exit(($GLOBALS['fails'] ?? 0) > 0 ? 1 : 0);
