<?php
/**
 * Bootstrap loader for portal view templates (any depth under resources/views/).
 */
declare(strict_types=1);

if (defined('BASE_PATH')) {
    return;
}

require_once dirname(__DIR__) . '/bootstrap.php';
