<?php
/**
 * Legacy logout URL (/logout.php).
 * Delegates to the canonical handler so audit log, remember-me, and JSON
 * logout stay the same as /app/api/logout.php.
 */
declare(strict_types=1);

require __DIR__ . '/app/api/logout.php';
