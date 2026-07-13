<?php
if (!defined('ASSET_BASE')) {
    require_once dirname(__DIR__, 2) . '/mc_load.php';
}
header('Location: ' . ASSET_BASE . '/views/provider/triage.php?tab=history', true, 301);
exit;
