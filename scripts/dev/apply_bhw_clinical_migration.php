<?php
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/app/includes/bhw_clinical.php';

bhw_clinical_ensure_schema($pdo);
echo "BHW clinical schema ready.\n";
