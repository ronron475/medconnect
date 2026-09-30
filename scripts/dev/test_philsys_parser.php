<?php
require dirname(__DIR__, 2) . '/app/core/PhilSysOcrParser.php';

$text = <<<'TXT'
PHILIPPINE IDENTIFICATION CARD
LAST NAME
ZAMORA
GIVEN NAMES
ANGEL
MIDDLE NAME
BRILLO
DATE OF BIRTH
APRIL 03, 2004
ADDRESS
PUROK KASANTOLAN, MAILUM, CITY OF BAGO, NEGROS OCCIDENTAL
TXT;

$bad = <<<'TXT'
LAST NAME
ZAMORA
GIVEN NAMES
Given Names
MIDDLE NAME
BRILLO
TXT;

$no_middle_label = <<<'TXT'
LAST NAME
ZAMORA
GIVEN NAMES
ANGEL
BRILLO
DATE OF BIRTH
APRIL 03, 2004
TXT;

$garbled = <<<'TXT'
REPUBLIKA NG PILIPINAS
AMBANSANG PAGKAKAKILANLAN
5039-8463-7417-2356
LAUREÑO
Me Pangle Gren Nomes
MA. PAULA
Gonang Apollido/Middly Nome
CARITATIVO
Petsa ng Kapangarukan/Duce of Birth
JANUARY 03, 2004
FIRK. 1. RECREO, PONTEVEDRA NEGROS OCCIDENTAL
TXT;

foreach (['good' => $text, 'bad_label_dup' => $bad, 'no_middle_label' => $no_middle_label, 'garbled_hologram' => $garbled] as $label => $raw) {
    $r = PhilSysOcrParser::extractAll($raw);
    echo "=== $label low=" . ($r['low_confidence'] ? 'yes' : 'no') . " ===\n";
    echo json_encode([
        'first' => $r['fields']['first_name']['value'],
        'middle' => $r['fields']['middle_name']['value'],
        'last' => $r['fields']['last_name']['value'],
        'dob' => $r['fields']['date_of_birth']['value'],
        'id' => $r['fields']['national_id']['value'],
    ], JSON_PRETTY_PRINT) . "\n";
}
