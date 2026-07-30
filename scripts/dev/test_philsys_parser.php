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

foreach (['good' => $text, 'bad_label_dup' => $bad, 'no_middle_label' => $no_middle_label] as $label => $raw) {
    $r = PhilSysOcrParser::extractAll($raw);
    echo "=== $label ===\n";
    echo json_encode([
        'first' => $r['fields']['first_name']['value'],
        'middle' => $r['fields']['middle_name']['value'],
        'last' => $r['fields']['last_name']['value'],
    ], JSON_PRETTY_PRINT) . "\n";
}
