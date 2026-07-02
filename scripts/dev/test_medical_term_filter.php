<?php
require_once dirname(__DIR__, 2) . '/bootstrap.php';

$hiligaynon = NlpPreprocessor::preprocessField('May alta presyon ko kag sakit ulo', 'conditions');
echo "Hiligaynon filter:\n";
echo json_encode($hiligaynon['medical_term_filter'] ?? [], JSON_PRETTY_PRINT) . "\n\n";

$noise = NlpPreprocessor::preprocessField('I feel tired and random words today', 'conditions');
echo "Non-medical filter:\n";
echo json_encode($noise['medical_term_filter'] ?? [], JSON_PRETTY_PRINT) . "\n";
echo "Keywords: " . json_encode($noise['keywords'] ?? []) . "\n";

$junk = NlpPreprocessor::preprocessField('random words only nothing medical', 'conditions');
echo "\nJunk-only keywords: " . json_encode($junk['keywords'] ?? []) . "\n";
echo "Discarded: " . json_encode($junk['medical_term_filter']['discarded'] ?? []) . "\n";
