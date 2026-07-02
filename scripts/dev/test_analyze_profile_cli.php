<?php
require_once dirname(__DIR__, 2) . '/bootstrap.php';

$_POST['existing_conditions'] = 'hypertension, fever';
$_POST['allergies'] = 'Penicillin';
$_SERVER['REQUEST_METHOD'] = 'POST';

ob_start();
include BASE_PATH . '/app/api/ai/analyze_medical_profile.php';
$output = ob_get_clean();
echo substr($output, 0, 300);
